export interface Etudiant {
  id: string;
  matricule: string;
  nom: string;
  prenom: string;
  sexe: 'M' | 'F' | string;
  date_naissance: string;
  lieu_naissance?: string;
  nationalite?: string;
  adresse?: string;
  ville?: string;
  pays?: string;
  telephone: string;
  email?: string;
  numero_cni?: string;
  profession?: string;
  situation_matrimoniale?: string;
  nom_pere?: string;
  profession_pere?: string;
  nom_mere?: string;
  profession_mere?: string;
  nom_tuteur?: string;
  profession_tuteur?: string;
  telephone_tuteur?: string;
  lieu_service_tuteur?: string;
  type_etudiant?: string;
  annee_obtention_bac?: number;
  serie_bac?: string;
  option?: string;
  filiere: string;
  niveau: string;
  cycle_formation?: string;
  rentree?: string;
  annee_academique?: string;
  site_formation?: string;
  vague?: 'Jour' | 'Soir' | string;
  statut?: 'Inscrit' | 'En attente' | 'Suspendu' | 'Diplômé';
  photo_url?: string;
  documents_physiques?: string;
  is_synced?: number;
  last_modified_at?: string;
  created_at?: string;
  updated_at?: string;
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
  nom: string; // Ex: "Amphi A - Campus 1"
  code?: string; // Ex: "AMP-A"
  capacite: number; // Ex: 120
  type_salle: 'amphi' | 'classe' | 'labo_info' | 'labo_reseau' | 'salle_reunion' | 'autre';
  batiment?: string; // Ex: "Campus 1 - Bâtiment Principal"
  etage?: string; // Ex: "RDC", "1er Étage"
  equipements?: string[]; // Ex: ["Vidéoprojecteur", "Climatisation", "Wi-Fi"]
  statut: 'disponible' | 'occupee' | 'maintenance' | 'reservee';
  description?: string;
  created_at?: string;
  updated_at?: string;
}


export interface Classe {
  id: string;
  nom: string; // Ex: "L1 Tronc Commun Technologie"
  code: string; // Ex: "L1-TCT"
  niveau: string; // "L1", "L2", "L3", "M1", "M2"
  filiere: string; // Ex: "Technologie", "Génie Logiciel"
  annee_academique: string; // Ex: "2025-2026"
  capacite_max?: number;
  salle_principale?: string;
  description?: string;
  created_at?: string;
  updated_at?: string;
}

export interface Matiere {
  id: string;
  code: string; // Ex: "INF101"
  nom: string; // Ex: "Algorithmique et Programmation"
  credits: number; // Ex: 4
  coefficient: number; // Ex: 2.0
  filiere_cible?: string;
  niveau_cible?: string;
  description?: string;
  created_at?: string;
}

export interface ClasseEtudiant {
  id: string;
  classe_id: string;
  etudiant_id: string;
  annee_academique: string;
  date_affectation: string;
  statut: 'actif' | 'transfere' | 'abandon';
  created_at?: string;
}

export interface ClasseMatiere {
  id: string;
  classe_id: string;
  matiere_id: string;
  enseignant_id?: string;
  semestre: 'S1' | 'S2' | 'S3' | 'S4' | 'S5' | 'S6' | 'S7' | 'S8' | 'S9' | 'S10' | 'Annuel' | string;
  credits: number;
  coefficient: number;
  volume_horaire?: number;
  created_at?: string;
}

export interface Note {
  id: string;
  classe_id: string;
  matiere_id: string;
  etudiant_id: string;
  note_cc?: number; // Contrôle continu sur 20
  note_examen?: number; // Examen sur 20
  note_rattrapage?: number; // Rattrapage sur 20
  note_finale: number; // Moyenne calculée sur 20
  semestre: 'S1' | 'S2' | 'S3' | 'S4' | 'S5' | 'S6' | 'S7' | 'S8' | 'S9' | 'S10' | 'Annuel' | string;
  annee_academique: string;
  saisi_par?: string;
  publie?: boolean;
  observations?: string;
  created_at?: string;
  updated_at?: string;
}

export interface Presence {
  id: string;
  classe_id: string;
  matiere_id?: string;
  etudiant_id: string;
  date_seance: string;
  heure_debut?: string;
  statut: 'present' | 'absent' | 'retard' | 'justifie';
  motif?: string;
  enregistre_par?: string;
  created_at?: string;
}

export interface JournalActivite {
  id: string;
  utilisateur_nom: string;
  action: string;
  module: string;
  details?: string;
  date_action: string;
}

export interface UserSession {
  id: string;
  nom_complet: string;
  email: string;
  role: 'dac' | 'admin' | string;
  token?: string;
}

export interface EvenementSpecial {
  id?: string;
  titre: string;
  date: string;
  date_fin?: string;
  type: 'fete' | 'reunion' | 'pedagogique' | 'ceremonie' | 'autre';
  description?: string;
}

export interface CalendrierAcademique {
  id: string;
  annee_academique: string;
  semestre: 'S1' | 'S2' | 'S3' | 'S4' | 'S5' | 'S6' | 'S7' | 'S8' | 'S9' | 'S10' | 'Annuel' | string;
  type_rentree: 'Octobre (Principale)' | 'Janvier (Décalée)' | 'Mars' | string;
  date_debut_cours: string;
  date_fin_cours: string;
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
  heure_debut: string; // "HH:mm" ex: "08:00"
  heure_fin: string; // "HH:mm" ex: "10:00"
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

// ==============================================================================
// TYPES MESSENGER & MESSAGERIE INSTANTANÉE
// ==============================================================================
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
  lu_par?: string[]; // user_ids
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
  avatar_url?: string;
}

// ==============================================================================
// NOTIFICATIONS SYSTÈME (STYLE FACEBOOK)
// ==============================================================================
export interface NotificationItem {
  id: string;
  user_id: string; // destinataire
  type: 'message' | 'invitation_groupe' | 'sujet_depose' | 'sujet_valide' | 'sujet_rejet' | 'demande_sujet' | 'notes_soumises' | 'notes_validees' | 'notes_rejetees' | 'system';
  titre: string;
  description: string;
  auteur_nom?: string;
  auteur_role?: string;
  lien_tab?: string;
  lien_id?: string;
  lu: boolean;
  donnees_extra?: any;
  created_at: string;
}

// ==============================================================================
// GESTION DES SUJETS D'EXAMENS & PROJETS (DAC <-> PROFESSEURS)
// ==============================================================================
export interface SujetEvaluation {
  id: string;
  classe_id: string;
  classe_nom: string;
  matiere_id: string;
  matiere_nom: string;
  enseignant_id: string;
  enseignant_nom: string;
  type_evaluation: 'Examen Final' | 'Projet' | 'DST' | 'Rattrapage' | string;
  semestre?: string;
  titre: string;
  description?: string;
  date_limite: string;
  modele_url?: string; // Modèle Word ou PDF envoyé par le DAC
  modele_nom?: string;
  modele_taille?: number;
  sujet_url?: string; // Sujet rempli déposé par le professeur
  sujet_nom?: string;
  sujet_taille?: number;
  date_depot?: string;
  statut: 'en_attente_depot' | 'depose' | 'valide' | 'a_corriger';
  commentaires_dac?: string;
  created_by: string;
  created_at: string;
  updated_at: string;
}

// ==============================================================================
// TRANSMISSION & VALIDATION DES NOTES (ENSEIGNANTS <-> DAC)
// ==============================================================================
export type TypeEpreuveSoumission = 'TOUTES' | 'DST' | 'DEVOIR_RECHERCHE' | 'SESSION' | 'RATTRAPAGE';

export interface NoteProvisoire {
  id: string;
  soumission_id: string;
  classe_id: string;
  matiere_id: string;
  etudiant_id: string;
  matricule: string;
  nom: string;
  prenom: string;
  note_cc?: number;         // DST /20
  note_examen?: number;     // Devoir de Recherche /20
  note_finale?: number;     // Session /20
  note_rattrapage?: number; // Rattrapage /20
  mg_estimee?: number;      // MG estimée selon composantes renseignées
  semestre: string;
  annee_academique: string;
  observations?: string;
}

export interface SoumissionNotes {
  id: string;
  classe_id: string;
  classe_nom: string;
  matiere_id: string;
  matiere_nom: string;
  matiere_code: string;
  enseignant_id: string;
  enseignant_nom: string;
  semestre: string;
  annee_academique: string;
  type_epreuve: TypeEpreuveSoumission;
  statut: 'EN_ATTENTE' | 'VALIDE' | 'REJETE';
  nombre_etudiants: number;
  moyenne_classe?: number;
  commentaires_enseignant?: string;
  remarques_dac?: string;
  notes: NoteProvisoire[];
  date_soumission: string;
  date_validation?: string;
  valide_par?: string;
  created_at: string;
  updated_at: string;
}


