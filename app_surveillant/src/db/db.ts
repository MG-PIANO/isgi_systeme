import Dexie, { type EntityTable } from 'dexie';
import { supabase } from './supabaseClient';
import type { 
  Etudiant, 
  PointageAcces, 
  Salle, 
  EmploiDuTempsItem, 
  StatsJour 
} from '../types';
import { getDateAujourdhui, calculerStatsJour } from '../services/scanEngine';

export interface ParametreItem {
  cle: string;
  valeur: string;
}

export const db = new Dexie('ISGI_SURVEILLANT_DB') as Dexie & {
  etudiants: EntityTable<Etudiant, 'id'>;
  pointages: EntityTable<PointageAcces, 'id'>;
  salles: EntityTable<Salle, 'id'>;
  emplois_du_temps: EntityTable<EmploiDuTempsItem, 'id'>;
  parametres: EntityTable<ParametreItem, 'cle'>;
};

// Schéma IndexedDB local
db.version(1).stores({
  etudiants: 'id, matricule, nom, prenom, filiere, niveau, classe_nom, statut',
  pointages: 'id, etudiant_id, matricule, date_jour, statut_arrivee, statut_actuel, heure_arrivee, heure_depart',
  salles: 'id, code, nom, statut, capacite, type',
  emplois_du_temps: 'id, classe_nom, matiere_nom, jour_semaine, salle_nom',
  parametres: 'cle'
});

/**
 * Enregistre ou met à jour un pointage dans Dexie (local) et synchronise avec Supabase (remote)
 */
export async function sauvegarderPointage(pointage: PointageAcces): Promise<PointageAcces> {
  // 1. Sauvegarde locale immédiate (instantanée)
  await db.pointages.put(pointage);

  // 2. Synchronisation en arrière-plan vers Supabase
  try {
    const payload = {
      id: pointage.id,
      etudiant_id: pointage.etudiant_id.includes('-') && pointage.etudiant_id.length === 36 ? pointage.etudiant_id : null,
      matricule: pointage.matricule,
      nom: pointage.nom,
      prenom: pointage.prenom,
      classe_nom: pointage.classe_nom,
      filiere: pointage.filiere,
      photo_url: pointage.photo_url || null,
      date_jour: pointage.date_jour,
      heure_arrivee: pointage.heure_arrivee,
      statut_arrivee: pointage.statut_arrivee,
      nombre_tours: pointage.nombre_tours,
      heure_depart: pointage.heure_depart || null,
      statut_actuel: pointage.statut_actuel,
      surveillant_id: pointage.surveillant_id && pointage.surveillant_id.length === 36 ? pointage.surveillant_id : null,
      surveillant_nom: pointage.surveillant_nom || 'Surveillant Général',
      appareil_dernier_scan: pointage.appareil_dernier_scan || 'pc',
      historique_scans: pointage.historique_scans,
      updated_at: new Date().toISOString()
    };

    await supabase.from('pointages_acces').upsert(payload, { onConflict: 'matricule,date_jour' });
  } catch (err) {
    console.warn('Sync pointage vers Supabase temporairement différée:', err);
  }

  return pointage;
}

/**
 * Récupère le pointage du jour pour un matricule donné
 */
export async function getPointageEtudiantJour(matricule: string, dateJour: string = getDateAujourdhui()): Promise<PointageAcces | undefined> {
  return await db.pointages
    .where('matricule')
    .equals(matricule)
    .filter(p => p.date_jour === dateJour)
    .first();
}

/**
 * Récupère tous les pointages pour une date donnée
 */
export async function getPointagesParDate(dateJour: string = getDateAujourdhui()): Promise<PointageAcces[]> {
  return await db.pointages
    .where('date_jour')
    .equals(dateJour)
    .reverse()
    .sortBy('updated_at');
}

/**
 * Calcule les statistiques en direct pour aujourd'hui
 */
export async function getStatsAujourdhui(): Promise<StatsJour> {
  const dateJour = getDateAujourdhui();
  const [totalInscrits, pointages] = await Promise.all([
    db.etudiants.where('statut').equals('actif').count(),
    db.pointages.where('date_jour').equals(dateJour).toArray()
  ]);

  return calculerStatsJour(totalInscrits, pointages);
}

/**
 * Synchronisation bidirectionnelle avec Supabase
/**
 * Synchronisation bidirectionnelle avec Supabase (données réelles)
 */
export async function synchroniserAvecSupabase(): Promise<{ countEtudiants: number; countPointages: number }> {
  let countEtudiants = 0;
  let countPointages = 0;

  try {
    // 1. Récupération de TOUS les étudiants réels depuis Supabase
    const { data: remoteEtudiants, error: errEtud } = await supabase
      .from('etudiants')
      .select('*')
      .order('nom', { ascending: true });

    if (!errEtud && remoteEtudiants && remoteEtudiants.length > 0) {
      countEtudiants = remoteEtudiants.length;
      
      const etudiantsMappes: Etudiant[] = remoteEtudiants.map(e => {
        const filiere = e.filiere || 'Informatique et Technologie';
        const niveau = e.niveau || 'Licence 1';
        const classe = e.classe_nom || `${filiere} ${niveau}`;

        return {
          id: String(e.id),
          matricule: (e.matricule || '').trim(),
          nom: (e.nom || '').trim(),
          prenom: (e.prenom || '').trim(),
          filiere,
          niveau,
          classe_nom: classe,
          statut: 'actif',
          photo_url: e.photo_url || '',
          telephone: e.telephone || '',
          email: e.email || '',
          nom_tuteur: e.nom_tuteur || '',
          telephone_tuteur: e.telephone_tuteur || '',
          qr_code_data: e.qr_code_data || `ETUDIANT:${e.matricule}|NOM:${e.nom}|PRENOM:${e.prenom}`
        };
      });

      // Remplacer proprement le cache local
      await db.etudiants.clear();
      await db.etudiants.bulkPut(etudiantsMappes);
      console.log(`[Surveillant] ${countEtudiants} vrais étudiants synchronisés depuis Supabase.`);
    }

    // 2. Récupération des pointages récents (des 60 derniers jours) pour le registre
    const dateIlYaDeuxMois = new Date();
    dateIlYaDeuxMois.setDate(dateIlYaDeuxMois.getDate() - 60);
    const dateDebutSync = dateIlYaDeuxMois.toISOString().split('T')[0];

    const { data: remotePointages, error: errPointages } = await supabase
      .from('pointages_acces')
      .select('*')
      .gte('date_jour', dateDebutSync)
      .order('updated_at', { ascending: false });

    if (!errPointages && remotePointages && remotePointages.length > 0) {
      countPointages = remotePointages.length;
      await db.pointages.bulkPut(remotePointages);
    }
  } catch (err) {
    console.warn('Synchronisation Supabase en mode hors-ligne:', err);
  }

  return { countEtudiants, countPointages };
}

/**
 * Recherche d'étudiants par matricule, nom ou prénom (dans Dexie local avec fallback Supabase)
 */
export async function rechercherEtudiants(terme: string): Promise<Etudiant[]> {
  const query = (terme || '').trim().toLowerCase();
  if (!query) {
    return await db.etudiants.toArray();
  }

  // 1. Recherche locale instantanée dans Dexie
  const tous = await db.etudiants.toArray();
  const locaux = tous.filter((e) => {
    const matricule = (e.matricule || '').toLowerCase();
    const nom = (e.nom || '').toLowerCase();
    const prenom = (e.prenom || '').toLowerCase();
    const nomComplet1 = `${nom} ${prenom}`;
    const nomComplet2 = `${prenom} ${nom}`;

    return (
      matricule.includes(query) ||
      nom.includes(query) ||
      prenom.includes(query) ||
      nomComplet1.includes(query) ||
      nomComplet2.includes(query)
    );
  });

  if (locaux.length > 0) {
    return locaux;
  }

  // 2. Si non trouvé localement, fallback direct Supabase en ligne
  try {
    const { data: remote, error } = await supabase
      .from('etudiants')
      .select('*')
      .or(`matricule.ilike.%${query}%,nom.ilike.%${query}%,prenom.ilike.%${query}%`)
      .limit(15);

    if (!error && remote && remote.length > 0) {
      const formates: Etudiant[] = remote.map((e) => ({
        id: String(e.id),
        matricule: (e.matricule || '').trim(),
        nom: (e.nom || '').trim(),
        prenom: (e.prenom || '').trim(),
        filiere: e.filiere || 'Informatique',
        niveau: e.niveau || 'Licence 1',
        classe_nom: e.classe_nom || `${e.filiere || 'Informatique'} ${e.niveau || 'L1'}`,
        statut: 'actif',
        photo_url: e.photo_url || '',
        telephone: e.telephone || '',
        email: e.email || '',
        nom_tuteur: e.nom_tuteur || '',
        telephone_tuteur: e.telephone_tuteur || '',
        qr_code_data: e.qr_code_data || `ETUDIANT:${e.matricule}|NOM:${e.nom}|PRENOM:${e.prenom}`
      }));

      // Mettre en cache dans Dexie
      await db.etudiants.bulkPut(formates);
      return formates;
    }
  } catch (err) {
    console.warn('Erreur recherche fallback Supabase:', err);
  }

  return [];
}

/**
 * Récupère les pointages d'une période donnée (entre dateDebut et dateFin incluses)
 */
export async function getPointagesParPeriode(dateDebut: string, dateFin: string): Promise<PointageAcces[]> {
  try {
    // 1. Tenter la synchronisation Supabase pour cette période
    const { data: remote, error } = await supabase
      .from('pointages_acces')
      .select('*')
      .gte('date_jour', dateDebut)
      .lte('date_jour', dateFin)
      .order('date_jour', { ascending: false });

    if (!error && remote && remote.length > 0) {
      await db.pointages.bulkPut(remote);
    }
  } catch (err) {
    console.warn('Mode hors-ligne pour la période:', err);
  }

  // 2. Lecture complète depuis Dexie
  const pointages = await db.pointages
    .filter((p) => p.date_jour >= dateDebut && p.date_jour <= dateFin)
    .toArray();

  // Tri par date décroissante puis heure d'arrivée décroissante
  return pointages.sort((a, b) => {
    if (a.date_jour !== b.date_jour) {
      return b.date_jour.localeCompare(a.date_jour);
    }
    return (b.heure_arrivee || '').localeCompare(a.heure_arrivee || '');
  });
}

/**
 * Initialisation des données par défaut si la base locale est vide
 */
export async function initialiserBaseSurveillant(): Promise<void> {
  // 1. Initialiser Paramètres par défaut
  const paramHeure = await db.parametres.get('heure_limite_arrivee');
  if (!paramHeure) {
    await db.parametres.put({ cle: 'heure_limite_arrivee', valeur: '08:15' });
  }

  // 2. Synchroniser TOUJOURS les données réelles depuis Supabase
  await synchroniserAvecSupabase();

  // 3. Initialiser Salles si vide
  const sallesCount = await db.salles.count();
  if (sallesCount === 0) {
    const defaultSalles: Salle[] = [
      { id: 'sal_amphi_a', nom: 'Amphi A - Campus 1', code: 'AMP-A', capacite: 150, type: 'amphi', statut: 'occupee', description: 'Grand amphithéâtre principal' },
      { id: 'sal_amphi_b', nom: 'Amphi B - Campus 1', code: 'AMP-B', capacite: 100, type: 'amphi', statut: 'disponible', description: 'Deuxième amphi' },
      { id: 'sal_lab_01', nom: 'Labo Info 1 (Dév)', code: 'LAB-01', capacite: 40, type: 'tp', statut: 'occupee', description: 'Laboratoire de développement' },
      { id: 'sal_lab_02', nom: 'Labo Info 2 (BDD)', code: 'LAB-02', capacite: 35, type: 'tp', statut: 'disponible', description: 'Laboratoire bases de données' },
      { id: 'sal_lab_res', nom: 'Labo Réseaux & Télécoms', code: 'LAB-RES', capacite: 30, type: 'tp', statut: 'disponible', description: 'Laboratoire réseaux Cisco' },
      { id: 'sal_101', nom: 'Salle 101 (Pédagogique)', code: 'S-101', capacite: 50, type: 'td', statut: 'disponible', description: 'Salle de cours standard' },
      { id: 'sal_102', nom: 'Salle 102 (Pédagogique)', code: 'S-102', capacite: 45, type: 'td', statut: 'occupee', description: 'Salle de travaux dirigés' },
      { id: 'sal_103', nom: 'Salle 103 (Pédagogique)', code: 'S-103', capacite: 45, type: 'td', statut: 'maintenance', description: 'Réparation climatisation' }
    ];
    await db.salles.bulkPut(defaultSalles);
  }

  // 4. Initialiser Emplois du Temps si vide
  const edtCount = await db.emplois_du_temps.count();
  if (edtCount === 0) {
    const defaultEdt: EmploiDuTempsItem[] = [
      { id: 'edt_1', classe_nom: 'L1 Tronc Commun Techno', matiere_nom: 'Algorithmique & Python', enseignant_nom: 'Dr. Mamadou DIALLO', salle_nom: 'Amphi A - Campus 1', jour_semaine: 'Lundi', heure_debut: '08:00', heure_fin: '10:00' },
      { id: 'edt_2', classe_nom: 'L2 Génie Logiciel', matiere_nom: 'Bases de Données Relationnelles', enseignant_nom: 'M. Ibrahima BAH', salle_nom: 'Labo Info 1 (Dév)', jour_semaine: 'Lundi', heure_debut: '10:15', heure_fin: '12:15' },
      { id: 'edt_3', classe_nom: 'L3 Réseaux & Télécoms', matiere_nom: 'Réseaux Mobiles 4G/5G', enseignant_nom: 'M. Alpha SOW', salle_nom: 'Labo Réseaux & Télécoms', jour_semaine: 'Lundi', heure_debut: '14:00', heure_fin: '16:00' },
      { id: 'edt_4', classe_nom: 'L2 Gestion & Finance', matiere_nom: 'Comptabilité Analytique', enseignant_nom: 'Mme. Fatou CAMARA', salle_nom: 'Salle 102 (Pédagogique)', jour_semaine: 'Mardi', heure_debut: '08:00', heure_fin: '10:00' }
    ];
    await db.emplois_du_temps.bulkPut(defaultEdt);
  }
}
