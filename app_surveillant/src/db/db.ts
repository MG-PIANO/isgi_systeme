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
 */
export async function synchroniserAvecSupabase(): Promise<void> {
  try {
    // 1. Récupération des étudiants depuis Supabase
    const { data: remoteEtudiants, error: errEtud } = await supabase
      .from('etudiants')
      .select('*');

    if (!errEtud && remoteEtudiants && remoteEtudiants.length > 0) {
      await db.etudiants.bulkPut(remoteEtudiants.map(e => ({
        id: String(e.id),
        matricule: e.matricule,
        nom: e.nom,
        prenom: e.prenom,
        filiere: e.filiere || 'Informatique',
        niveau: e.niveau || 'Licence 1',
        classe_nom: e.classe_nom || `${e.filiere || 'Informatique'} ${e.niveau || 'L1'}`,
        statut: e.statut || 'actif',
        photo_url: e.photo_url || '',
        telephone: e.telephone || '',
        email: e.email || '',
        qr_code_data: e.qr_code_data || `ETUDIANT:${e.matricule}|NOM:${e.nom}|PRENOM:${e.prenom}`
      })));
    }

    // 2. Récupération des pointages du jour depuis Supabase
    const dateJour = getDateAujourdhui();
    const { data: remotePointages, error: errPointages } = await supabase
      .from('pointages_acces')
      .select('*')
      .eq('date_jour', dateJour);

    if (!errPointages && remotePointages && remotePointages.length > 0) {
      await db.pointages.bulkPut(remotePointages);
    }
  } catch (err) {
    console.warn('Synchronisation Supabase en mode hors-ligne:', err);
  }
}

/**
 * Initialisation des données par défaut si la base locale est vide
 */
export async function initialiserBaseSurveillant(): Promise<void> {
  // 1. Initialiser Paramètres
  const paramHeure = await db.parametres.get('heure_limite_arrivee');
  if (!paramHeure) {
    await db.parametres.put({ cle: 'heure_limite_arrivee', valeur: '08:15' });
  }

  // 2. Initialiser Étudiants si vide
  const etudiantsCount = await db.etudiants.count();
  if (etudiantsCount === 0) {
    const defaultStudents: Etudiant[] = [
      {
        id: 'etud_001',
        matricule: 'ISGI-2025-00019',
        nom: 'DIALLO',
        prenom: 'Aminata',
        filiere: 'Génie Logiciel',
        niveau: 'Licence 2',
        classe_nom: 'L2 Génie Logiciel',
        statut: 'actif',
        photo_url: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
        telephone: '+224 622 10 20 30',
        email: 'aminata.diallo@isgi-edu.com',
        nom_tuteur: 'M. Ibrahima DIALLO',
        telephone_tuteur: '+224 620 55 44 33',
        qr_code_data: 'ETUDIANT:ISGI-2025-00019|NOM:DIALLO|PRENOM:Aminata|SITE:1'
      },
      {
        id: 'etud_002',
        matricule: 'ISGI-2025-00042',
        nom: 'CAMARA',
        prenom: 'Mohamed Lamine',
        filiere: 'Réseaux & Télécoms',
        niveau: 'Licence 3',
        classe_nom: 'L3 Réseaux & Télécoms',
        statut: 'actif',
        photo_url: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150',
        telephone: '+224 621 44 55 66',
        email: 'mohamed.camara@isgi-edu.com',
        nom_tuteur: 'Mme. Fatoumata CAMARA',
        telephone_tuteur: '+224 622 77 88 99',
        qr_code_data: 'ETUDIANT:ISGI-2025-00042|NOM:CAMARA|PRENOM:Mohamed Lamine|SITE:1'
      },
      {
        id: 'etud_003',
        matricule: 'ISGI-2025-00088',
        nom: 'BARRY',
        prenom: 'Mamadou Oury',
        filiere: 'Tronc Commun Technologie',
        niveau: 'Licence 1',
        classe_nom: 'L1 Tronc Commun Techno',
        statut: 'actif',
        photo_url: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=150',
        telephone: '+224 628 11 22 33',
        email: 'mamadou.barry@isgi-edu.com',
        nom_tuteur: 'M. Boubacar BARRY',
        telephone_tuteur: '+224 628 99 88 77',
        qr_code_data: 'ETUDIANT:ISGI-2025-00088|NOM:BARRY|PRENOM:Mamadou Oury|SITE:1'
      },
      {
        id: 'etud_004',
        matricule: 'ISGI-2025-00105',
        nom: 'SOUMAH',
        prenom: 'Mariama',
        filiere: 'Gestion & Comptabilité',
        niveau: 'Licence 2',
        classe_nom: 'L2 Gestion & Finance',
        statut: 'actif',
        photo_url: 'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=150',
        telephone: '+224 623 99 00 11',
        email: 'mariama.soumah@isgi-edu.com',
        nom_tuteur: 'M. Lansana SOUMAH',
        telephone_tuteur: '+224 623 44 33 22',
        qr_code_data: 'ETUDIANT:ISGI-2025-00105|NOM:SOUMAH|PRENOM:Mariama|SITE:1'
      },
      {
        id: 'etud_005',
        matricule: 'ISGI-2025-00120',
        nom: 'KOUROUMA',
        prenom: 'Sékou',
        filiere: 'Génie Logiciel',
        niveau: 'Licence 3',
        classe_nom: 'L3 Génie Logiciel',
        statut: 'actif',
        photo_url: 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=150',
        telephone: '+224 625 33 22 11',
        email: 'sekou.kourouma@isgi-edu.com',
        nom_tuteur: 'M. Moriba KOUROUMA',
        telephone_tuteur: '+224 625 88 77 66',
        qr_code_data: 'ETUDIANT:ISGI-2025-00120|NOM:KOUROUMA|PRENOM:Sékou|SITE:1'
      }
    ];
    await db.etudiants.bulkPut(defaultStudents);
  }

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

  // 4. Initialiser Emplois du Temps de consultation si vide
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
