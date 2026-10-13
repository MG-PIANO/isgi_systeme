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

// Schéma IndexedDB local avec gestion offline queue
db.version(1).stores({
  etudiants: 'id, matricule, nom, prenom, filiere, niveau, classe_nom, statut',
  pointages: 'id, etudiant_id, matricule, date_jour, statut_arrivee, statut_actuel, heure_arrivee, heure_depart',
  salles: 'id, code, nom, statut, capacite, type',
  emplois_du_temps: 'id, classe_nom, matiere_nom, jour_semaine, salle_nom',
  parametres: 'cle'
});

db.version(2).stores({
  pointages: 'id, etudiant_id, matricule, date_jour, statut_arrivee, statut_actuel, heure_arrivee, heure_depart, synced'
});

/**
 * Enregistre ou met à jour un pointage dans Dexie (local) et synchronise avec Supabase si connecté
 */
export async function sauvegarderPointage(pointage: PointageAcces): Promise<PointageAcces> {
  const pointageLocal: PointageAcces = {
    ...pointage,
    synced: pointage.synced ?? 0
  };

  // 1. Sauvegarde locale immédiate (100% hors-ligne dans Dexie)
  await db.pointages.put(pointageLocal);

  // 2. Synchronisation en arrière-plan vers Supabase si internet disponible
  if (navigator.onLine) {
    try {
      const payload = {
        id: pointageLocal.id,
        etudiant_id: pointageLocal.etudiant_id.includes('-') && pointageLocal.etudiant_id.length === 36 ? pointageLocal.etudiant_id : null,
        matricule: pointageLocal.matricule,
        nom: pointageLocal.nom,
        prenom: pointageLocal.prenom,
        classe_nom: pointageLocal.classe_nom,
        filiere: pointageLocal.filiere,
        photo_url: pointageLocal.photo_url || null,
        date_jour: pointageLocal.date_jour,
        heure_arrivee: pointageLocal.heure_arrivee,
        statut_arrivee: pointageLocal.statut_arrivee,
        nombre_tours: pointageLocal.nombre_tours,
        heure_depart: pointageLocal.heure_depart || null,
        statut_actuel: pointageLocal.statut_actuel,
        surveillant_id: pointageLocal.surveillant_id && pointageLocal.surveillant_id.length === 36 ? pointageLocal.surveillant_id : null,
        surveillant_nom: pointageLocal.surveillant_nom || 'Surveillant Mobile',
        appareil_dernier_scan: 'mobile',
        historique_scans: pointageLocal.historique_scans,
        updated_at: new Date().toISOString()
      };

      const { error } = await supabase.from('pointages_acces').upsert(payload, { onConflict: 'matricule,date_jour' });
      if (!error) {
        pointageLocal.synced = 1;
        await db.pointages.update(pointageLocal.id, { synced: 1 });
      }
    } catch (err) {
      console.warn('Sync pointage vers Supabase temporairement différé (hors-ligne):', err);
    }
  }

  return pointageLocal;
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
 * Synchronise tous les pointages locaux non envoyés vers Supabase
 */
export async function synchroniserPointagesEnAttente(): Promise<{ reussis: number; total: number }> {
  if (!navigator.onLine) {
    return { reussis: 0, total: 0 };
  }

  const tous = await db.pointages.toArray();
  const nonSynchronises = tous.filter(p => !p.synced || p.synced === 0);

  if (nonSynchronises.length === 0) {
    return { reussis: 0, total: 0 };
  }

  let reussis = 0;
  for (const p of nonSynchronises) {
    try {
      const payload = {
        id: p.id,
        etudiant_id: p.etudiant_id.includes('-') && p.etudiant_id.length === 36 ? p.etudiant_id : null,
        matricule: p.matricule,
        nom: p.nom,
        prenom: p.prenom,
        classe_nom: p.classe_nom,
        filiere: p.filiere,
        photo_url: p.photo_url || null,
        date_jour: p.date_jour,
        heure_arrivee: p.heure_arrivee,
        statut_arrivee: p.statut_arrivee,
        nombre_tours: p.nombre_tours,
        heure_depart: p.heure_depart || null,
        statut_actuel: p.statut_actuel,
        surveillant_id: p.surveillant_id && p.surveillant_id.length === 36 ? p.surveillant_id : null,
        surveillant_nom: p.surveillant_nom || 'Surveillant Mobile',
        appareil_dernier_scan: 'mobile',
        historique_scans: p.historique_scans,
        updated_at: p.updated_at || new Date().toISOString()
      };

      const { error } = await supabase.from('pointages_acces').upsert(payload, { onConflict: 'matricule,date_jour' });
      if (!error) {
        await db.pointages.update(p.id, { synced: 1 });
        reussis++;
      }
    } catch (e) {
      console.warn(`Échec de synchronisation pour le pointage ${p.matricule}:`, e);
    }
  }

  console.log(`[Sync Offline -> Supabase] ${reussis}/${nonSynchronises.length} pointages synchronisés avec succès.`);
  return { reussis, total: nonSynchronises.length };
}

/**
 * Nombre de pointages en attente de synchronisation
 */
export async function getNombrePointagesEnAttente(): Promise<number> {
  const tous = await db.pointages.toArray();
  return tous.filter(p => !p.synced || p.synced === 0).length;
}

/**
 * Synchronisation bidirectionnelle avec Supabase (données réelles)
 */
export async function synchroniserAvecSupabase(): Promise<{ countEtudiants: number; countPointages: number; pendingSynced: number }> {
  let countEtudiants = 0;
  let countPointages = 0;
  let pendingSynced = 0;

  try {
    // 0. Envoyer d'abord tous les scans hors-ligne en attente
    const syncRes = await synchroniserPointagesEnAttente();
    pendingSynced = syncRes.reussis;

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
      console.log(`[Surveillant Mobile] ${countEtudiants} vrais étudiants synchronisés depuis Supabase.`);
    }

    // 2. Récupération des pointages récents
    const dateJour = getDateAujourdhui();
    const { data: remotePointages, error: errPointages } = await supabase
      .from('pointages_acces')
      .select('*')
      .eq('date_jour', dateJour);

    if (!errPointages && remotePointages && remotePointages.length > 0) {
      countPointages = remotePointages.length;
      await db.pointages.bulkPut(remotePointages);
    }
  } catch (err) {
    console.warn('Synchronisation Supabase en mode hors-ligne:', err);
  }

  return { countEtudiants, countPointages, pendingSynced };
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

  // 2. Fallback Supabase si non trouvé en local
  try {
    const { data } = await supabase
      .from('etudiants')
      .select('*')
      .or(`matricule.ilike.%${query}%,nom.ilike.%${query}%,prenom.ilike.%${query}%`)
      .limit(10);

    if (data && data.length > 0) {
      const mappes: Etudiant[] = data.map(e => ({
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
        qr_code_data: e.qr_code_data || `ETUDIANT:${e.matricule}|NOM:${e.nom}|PRENOM:${e.prenom}`
      }));
      await db.etudiants.bulkPut(mappes);
      return mappes;
    }
  } catch (err) {
    console.warn('Recherche distante Supabase échouée:', err);
  }

  return [];
}

/**
 * Initialisation de la base mobile : synchronisation directe des vraies données
 */
export async function initialiserBaseSurveillant(): Promise<void> {
  // 1. Paramètre heure limite d'arrivée
  const paramHeure = await db.parametres.get('heure_limite_arrivee');
  if (!paramHeure) {
    await db.parametres.put({ cle: 'heure_limite_arrivee', valeur: '08:15' });
  }

  // 2. Nettoyage des anciennes données fictives si présentes (ex: id 'etud_001')
  const premier = await db.etudiants.get('etud_001');
  if (premier) {
    console.log('[Surveillant Mobile] Nettoyage des données fictives...');
    await db.etudiants.clear();
  }

  // 3. Synchronisation immédiate avec les vraies données de Supabase
  await synchroniserAvecSupabase();
}
