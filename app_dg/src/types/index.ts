export interface Etudiant {
  id?: string;
  matricule: string;
  nom: string;
  prenom: string;
  sexe: string;
  telephone: string;
  email: string;
  filiere: string;
  niveau: string;
  cycle_formation: string;
  rentree?: string;
  vague?: string; // 'Jour' | 'Soir'
  statut?: string;
  created_at?: string;
  updated_at?: string;
}

export interface Paiement {
  id?: string;
  etudiant_id: string;
  type_paiement: string;
  montant: number;
  mode_paiement: string;
  reference_transaction?: string;
  statut: 'Réussi' | 'Échoué' | 'En attente';
  gestionnaire_nom?: string;
  last_modified_at: string;
  created_at?: string;
}

export interface Depense {
  id?: string;
  beneficiaire: string;
  motif: string;
  categorie: 'Salaires' | 'Loyer & Charges' | 'Fournitures' | 'Maintenance' | 'Pédagogie' | 'Autre';
  montant: number;
  statut: 'Approuvée' | 'En attente validation DG' | 'Rejetée';
  date_depense: string;
  cree_par: string;
  approuve_par?: string;
  commentaire_dg?: string;
}

export interface Personnel {
  id?: string;
  matricule?: string;
  nom: string;
  prenom: string;
  telephone: string;
  email: string;
  role: 'DG' | 'DAC' | 'Comptable' | 'Surveillant' | 'Secrétaire' | 'Informaticien' | 'Enseignant';
  statut_compte: 'Actif' | 'Suspendu' | 'Bloqué';
  departement?: string;
  dernier_acces?: string;
}

export interface AuditLog {
  id?: string;
  date_action: string;
  acteur_nom: string;
  acteur_role: string;
  action: string;
  module: 'Finances' | 'Académique' | 'Sécurité' | 'Inscriptions' | 'Système';
  details: string;
  niveau: 'INFO' | 'ATTENTION' | 'CRITIQUE';
  ip_address?: string;
}

export interface Circulaire {
  id?: string;
  titre: string;
  contenu: string;
  priorite: 'Normale' | 'Importante' | 'Urgente';
  cible: 'Tous' | 'Étudiants & Tuteurs' | 'Personnel Enseignant' | 'Administration';
  date_publication: string;
  publie_par: string;
  actif: boolean;
}

export interface SystemLock {
  id?: string;
  cle: string;
  nom: string;
  description: string;
  est_verrouille: boolean;
  verrouille_par?: string;
  date_modification?: string;
}
