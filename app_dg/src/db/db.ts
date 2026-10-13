import Dexie, { type EntityTable } from 'dexie';
import { supabase } from './supabaseClient';
import type {
  Classe,
  Matiere,
  Personnel,
  Salle,
  CalendrierAcademique,
  EmploiDuTempsItem,
  PublicationAcademique,
  Conversation,
  ConversationParticipant,
  MessageChat,
  UserPresence,
  NotificationItem
} from '../types/academic';

export interface Etudiant {
  id?: string;
  matricule: string;
  nom: string;
  prenom: string;
  sexe: string;
  date_naissance: string;
  lieu_naissance: string;
  nationalite: string;
  adresse: string;
  ville: string;
  pays: string;
  telephone: string;
  email: string;
  numero_cni: string;
  profession: string;
  situation_matrimoniale: string;
  nom_pere: string;
  profession_pere: string;
  nom_mere: string;
  profession_mere: string;
  nom_tuteur: string;
  profession_tuteur: string;
  telephone_tuteur: string;
  lieu_service_tuteur: string;
  type_etudiant: string;
  annee_obtention_bac?: number;
  serie_bac: string;
  option?: string;
  filiere: string;
  niveau: string;
  cycle_formation: string;
  rentree: string;
  annee_academique?: string;
  site_formation: string;
  vague?: string; // 'Jour' | 'Soir'
  is_synced: number; // 0 = local only (needs push), 1 = synced
  last_modified_at: string;
  created_at?: string;
  updated_at?: string;
  documents_physiques?: string;
}

export interface Paiement {
  id?: string;
  etudiant_id: string;
  type_paiement: string;
  montant: number;
  mode_paiement: 'Espèces' | 'Airtel Money' | 'MTN Mobile Money' | 'Virement';
  reference_transaction?: string;
  statut: 'Réussi' | 'Échoué' | 'En attente';
  gestionnaire_nom?: string;
  is_synced: number;
  last_modified_at: string;
  created_at?: string;
  updated_at?: string;
}

export interface Depense {
  id?: string;
  beneficiaire?: string;
  libelle?: string;
  motif?: string;
  categorie?: string;
  montant: number;
  date_depense?: string;
  created_at?: string;
  statut?: string;
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

export interface AuditLog {
  id?: string;
  date_action: string;
  acteur_nom: string;
  acteur_role: string;
  action: string;
  module: string;
  details: string;
  niveau: string;
}

const db = new Dexie('ISGIDatabase') as Dexie & {
  etudiants: EntityTable<Etudiant, 'id'>;
  paiements: EntityTable<Paiement, 'id'>;
  classes: EntityTable<Classe, 'id'>;
  matieres: EntityTable<Matiere, 'id'>;
  personnel: EntityTable<Personnel, 'id'>;
  salles: EntityTable<Salle, 'id'>;
  calendriers_academiques: EntityTable<CalendrierAcademique, 'id'>;
  emplois_du_temps: EntityTable<EmploiDuTempsItem, 'id'>;
  publications_academiques: EntityTable<PublicationAcademique, 'id'>;
  conversations: EntityTable<Conversation, 'id'>;
  conversation_participants: EntityTable<ConversationParticipant, 'id'>;
  messages: EntityTable<MessageChat, 'id'>;
  user_presences: EntityTable<UserPresence, 'user_id'>;
  notifications: EntityTable<NotificationItem, 'id'>;
  depenses: EntityTable<Depense, 'id'>;
  audit_logs: EntityTable<AuditLog, 'id'>;
  circulaires: EntityTable<Circulaire, 'id'>;
  system_locks: EntityTable<SystemLock, 'id'>;
};

export const seedDatabaseIfEmpty = async () => {
  try {
    const count = await db.etudiants.count();
    if (count === 0) {
      const seed = await import('./seed.json');
      if (seed.default && seed.default.length > 0) {
        await db.etudiants.bulkAdd(seed.default as any);
        console.log("Database seeded with", seed.default.length, "students.");
      }
    }
  } catch (e) {
    console.error("Failed to seed database:", e);
  }
};

// Schema declaration
db.version(1).stores({
  etudiants: 'id, matricule, is_synced, last_modified_at',
  paiements: 'id, etudiant_id, is_synced, last_modified_at'
});

db.version(2).stores({
  classes: 'id, nom, code, niveau, filiere, annee_academique',
  matieres: 'id, code, nom, credits, coefficient',
  personnel: 'id, matricule, nom, prenom, type_personnel',
  salles: 'id, nom, code, capacite, statut',
  calendriers_academiques: 'id, annee_academique, semestre, statut, publie',
  emplois_du_temps: 'id, classe_id, matiere_id, enseignant_id, jour_semaine, salle, semestre, annee_academique, statut, publie',
  publications_academiques: 'id, type, classe_id, semestre, publie',
  conversations: 'id, type, titre, created_by, dernier_message_date',
  conversation_participants: 'id, conversation_id, user_id, statut_role, statut_invitation',
  messages: 'id, conversation_id, sender_id, type_message, created_at',
  user_presences: 'user_id, nom_complet, en_ligne, derniere_connexion',
  notifications: 'id, user_id, type, lu, created_at',
  circulaires: 'id, date_publication, priorite, cible, actif',
  system_locks: 'id, cle, est_verrouille',
  depenses: 'id, date_depense, statut',
  audit_logs: 'id, date_action, module, niveau'
});

export { db };
