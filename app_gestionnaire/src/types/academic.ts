// Types académiques et Messenger pour app_gestionnaire

export interface Classe {
  id: string;
  nom: string;
  code: string;
  niveau: string;
  filiere: string;
  annee_academique: string;
  capacite_max?: number;
  salle_principale?: string;
  description?: string;
  created_at?: string;
  updated_at?: string;
}

export interface Matiere {
  id: string;
  code: string;
  nom: string;
  credits: number;
  coefficient: number;
  filiere_cible?: string;
  niveau_cible?: string;
  description?: string;
  created_at?: string;
}

export interface Personnel {
  id: string;
  matricule: string;
  nom: string;
  prenom: string;
  sexe?: string;
  telephone?: string;
  email?: string;
  type_personnel: 'Enseignant' | 'Administratif' | 'Support' | string;
  statut_contrat?: 'Permanent' | 'Vacataire' | 'Contractuel' | string;
  fonction?: string;
  departement?: string;
  specialite?: string;
  created_at?: string;
}

export interface Salle {
  id: string;
  nom: string;
  code?: string;
  capacite: number;
  type_salle: 'amphi' | 'classe' | 'labo_info' | 'labo_reseau' | 'salle_reunion' | 'autre';
  batiment?: string;
  etage?: string;
  equipements?: string[];
  statut: 'disponible' | 'occupee' | 'maintenance' | 'reservee';
  description?: string;
  created_at?: string;
  updated_at?: string;
}

export interface EvenementSpecial {
  id: string;
  titre: string;
  date_debut: string;
  date_fin?: string;
  type: 'pedagogique' | 'ferie' | 'reunion' | 'culturel' | 'autre';
  description?: string;
  impact_cours: boolean;
}

export interface CalendrierAcademique {
  id: string;
  annee_academique: string;
  semestre: 'S1' | 'S2' | 'S3' | 'S4' | 'S5' | 'S6' | 'S7' | 'S8' | 'S9' | 'S10' | 'Annuel' | string;
  type_rentree: string;
  date_debut_cours?: string;
  date_fin_cours?: string;
  date_debut_dst?: string;
  date_fin_dst?: string;
  date_debut_recherche?: string;
  date_fin_recherche?: string;
  date_debut_conge_etude?: string;
  date_fin_conge_etude?: string;
  date_debut_examens?: string;
  date_fin_examens?: string;
  date_reprise_cours?: string;
  date_debut_stage?: string;
  date_fin_stage?: string;
  date_debut_rattrapage?: string;
  date_fin_rattrapage?: string;
  statut: 'planifie' | 'en_cours' | 'termine' | 'annule';
  publie: boolean;
  observations?: string;
  evenements_speciaux?: EvenementSpecial[];
  created_at?: string;
  updated_at?: string;
}

export interface EmploiDuTempsItem {
  id: string;
  classe_id: string;
  matiere_id: string;
  enseignant_id?: string;
  jour_semaine: 'Lundi' | 'Mardi' | 'Mercredi' | 'Jeudi' | 'Vendredi' | 'Samedi';
  heure_debut: string;
  heure_fin: string;
  salle: string;
  type_cours: 'CM' | 'TD' | 'TP' | 'Séminaire' | 'Évaluation';
  semestre: 'S1' | 'S2' | 'S3' | 'S4' | 'S5' | 'S6' | 'S7' | 'S8' | 'S9' | 'S10' | 'Annuel' | string;
  annee_academique: string;
  statut: 'actif' | 'reporte' | 'annule';
  publie?: boolean;
  couleur?: string;
  created_at?: string;
  updated_at?: string;
}

export interface PublicationAcademique {
  id: string;
  type: 'emploi_du_temps' | 'notes_moyennes' | 'calendrier';
  classe_id?: string;
  semestre?: string;
  annee_academique?: string;
  titre?: string;
  publie: boolean;
  publie_par?: string;
  date_publication?: string;
  created_at?: string;
  updated_at?: string;
}

export interface Conversation {
  id: string;
  type: 'direct' | 'group';
  titre?: string;
  description?: string;
  avatar_url?: string;
  couleur?: string;
  created_by: string;
  dernier_message_texte?: string;
  dernier_message_date?: string;
  dernier_message_sender?: string;
  created_at?: string;
  updated_at?: string;
}

export interface ConversationParticipant {
  id: string;
  conversation_id: string;
  user_id: string;
  user_nom: string;
  user_role?: string;
  user_avatar?: string;
  statut_role: 'admin' | 'membre';
  statut_invitation?: 'direct' | 'en_attente' | 'accepte' | 'refuse';
  invite_par?: string;
  invite_par_nom?: string;
  joined_at?: string;
  last_read_at?: string;
  notifications_actives?: boolean;
}

export interface MessageChat {
  id: string;
  conversation_id: string;
  sender_id: string;
  sender_nom: string;
  sender_role?: string;
  sender_avatar?: string;
  type_message: 'text' | 'image' | 'video' | 'audio' | 'document';
  contenu?: string;
  fichier_url?: string;
  fichier_nom?: string;
  fichier_taille?: number;
  fichier_type?: string;
  lu_par?: string[];
  statut?: 'envoye' | 'distribue' | 'lu';
  created_at: string;
}

export interface UserPresence {
  user_id: string;
  nom_complet: string;
  role?: string;
  en_ligne: boolean;
  derniere_connexion: string;
  statut_perso?: string;
}

export interface NotificationItem {
  id: string;
  user_id: string;
  type: 'system' | 'message' | 'invitation' | 'note' | 'calendrier' | 'evaluation';
  titre: string;
  description?: string;
  conversation_id?: string;
  auteur_nom?: string;
  auteur_role?: string;
  lien_tab?: string;
  lu: boolean;
  created_at: string;
}
