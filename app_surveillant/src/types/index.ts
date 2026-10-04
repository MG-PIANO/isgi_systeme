// Types pour l'application Surveillant Général (ISGI System)

export interface Etudiant {
  id: string;
  matricule: string;
  nom: string;
  prenom: string;
  sexe?: 'M' | 'F' | string;
  date_naissance?: string;
  telephone?: string;
  email?: string;
  filiere: string;
  niveau: string;
  classe_nom?: string;
  photo_url?: string;
  statut: 'actif' | 'inactif' | 'suspendu';
  qr_code_data?: string;
  nom_tuteur?: string;
  telephone_tuteur?: string;
}

export type ScanActionType = 'arrivee' | 'sortie_tour' | 'retour' | 'depart';

export interface ScanLogItem {
  id: string;
  timestamp: string; // Heure HH:mm:ss
  date_heure_iso: string;
  type: ScanActionType;
  tour_numero?: number;
  appareil: 'mobile' | 'pc';
  surveillant_id?: string;
  surveillant_nom?: string;
  notes?: string;
}

export interface PointageAcces {
  id: string;
  etudiant_id: string;
  matricule: string;
  nom: string;
  prenom: string;
  filiere: string;
  niveau: string;
  classe_nom: string;
  photo_url?: string;
  date_jour: string; // Format YYYY-MM-DD
  heure_arrivee: string; // Format HH:mm:ss (1er scan)
  statut_arrivee: 'a_l_heure' | 'en_retard';
  nombre_tours: number; // 0, 1, 2, ...
  heure_depart?: string; // Dernier scan
  statut_actuel: 'sur_site' | 'sorti';
  appareil_dernier_scan: 'mobile' | 'pc';
  surveillant_id?: string;
  surveillant_nom?: string;
  historique_scans: ScanLogItem[];
  created_at: string;
  updated_at: string;
}

export interface ScanProcessResult {
  success: boolean;
  message: string;
  action: ScanActionType;
  etudiant: Etudiant;
  pointage: PointageAcces;
  isFirstScanToday: boolean;
  estEnRetard: boolean;
  tourNumero?: number;
  timestamp: string;
}

export interface Salle {
  id: string;
  nom: string;
  code: string;
  capacite: number;
  type: 'amphi' | 'tp' | 'td' | 'autre';
  statut: 'disponible' | 'occupee' | 'reservee' | 'maintenance';
  site_id?: number;
  description?: string;
}

export interface EmploiDuTempsItem {
  id: string;
  classe_nom: string;
  matiere_nom: string;
  enseignant_nom: string;
  salle_nom: string;
  jour_semaine: 'Lundi' | 'Mardi' | 'Mercredi' | 'Jeudi' | 'Vendredi' | 'Samedi';
  heure_debut: string; // 08:00
  heure_fin: string;   // 10:00
}

export interface StatsJour {
  totalInscrits: number;
  totalArrives: number;
  actuellementSurSite: number;
  actuellementSortis: number;
  aLHeure: number;
  enRetard: number;
  totalToursSorties: number;
  tauxPresence: number; // Pourcentage
}

export interface SurveillantUser {
  id: string;
  email: string;
  nom_complet: string;
  role: 'surveillant' | 'admin_principal';
  site_id?: number;
}
