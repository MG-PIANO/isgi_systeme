import Dexie, { type EntityTable } from 'dexie';
import { supabase } from './supabaseClient';
import type {
  Etudiant,
  Personnel,
  Classe,
  Matiere,
  ClasseEtudiant,
  ClasseMatiere,
  Note,
  Presence,
  JournalActivite,
  CalendrierAcademique,
  EmploiDuTempsItem,
  Salle,
  Conversation,
  ConversationParticipant,
  MessageChat,
  UserPresence,
  NotificationItem,
  SujetEvaluation,
  SoumissionNotes,
  NoteProvisoire,
  PublicationAcademique
} from '../types';

export const db = new Dexie('ISGI_DAC_DB') as Dexie & {
  etudiants: EntityTable<Etudiant, 'id'>;
  personnel: EntityTable<Personnel, 'id'>;
  classes: EntityTable<Classe, 'id'>;
  matieres: EntityTable<Matiere, 'id'>;
  classe_etudiants: EntityTable<ClasseEtudiant, 'id'>;
  classe_matieres: EntityTable<ClasseMatiere, 'id'>;
  notes: EntityTable<Note, 'id'>;
  presences: EntityTable<Presence, 'id'>;
  journal_activites: EntityTable<JournalActivite, 'id'>;
  calendriers_academiques: EntityTable<CalendrierAcademique, 'id'>;
  emplois_du_temps: EntityTable<EmploiDuTempsItem, 'id'>;
  salles: EntityTable<Salle, 'id'>;
  conversations: EntityTable<Conversation, 'id'>;
  conversation_participants: EntityTable<ConversationParticipant, 'id'>;
  messages: EntityTable<MessageChat, 'id'>;
  user_presences: EntityTable<UserPresence, 'user_id'>;
  notifications: EntityTable<NotificationItem, 'id'>;
  sujets_evaluations: EntityTable<SujetEvaluation, 'id'>;
  soumissions_notes: EntityTable<SoumissionNotes, 'id'>;
  notes_provisoires: EntityTable<NoteProvisoire, 'id'>;
  publications_academiques: EntityTable<PublicationAcademique, 'id'>;
};

// Schéma IndexedDB
db.version(1).stores({
  etudiants: 'id, matricule, nom, prenom, filiere, niveau, annee_academique, statut',
  personnel: 'id, matricule, nom, prenom, type_personnel, departement',
  classes: 'id, nom, code, niveau, filiere, annee_academique',
  matieres: 'id, code, nom, credits, coefficient',
  classe_etudiants: 'id, classe_id, etudiant_id, annee_academique, statut',
  classe_matieres: 'id, classe_id, matiere_id, enseignant_id, semestre',
  notes: 'id, classe_id, matiere_id, etudiant_id, semestre, annee_academique',
  presences: 'id, classe_id, matiere_id, etudiant_id, date_seance, statut',
  journal_activites: 'id, utilisateur_nom, module, date_action'
});

db.version(2).stores({
  calendriers_academiques: 'id, annee_academique, semestre, statut, publie',
  emplois_du_temps: 'id, classe_id, matiere_id, enseignant_id, jour_semaine, salle, semestre, annee_academique, statut'
});

db.version(3).stores({
  salles: 'id, nom, code, capacite, type_salle, statut'
});

db.version(4).stores({
  conversations: 'id, type, titre, created_by, dernier_message_date',
  conversation_participants: 'id, conversation_id, user_id, statut_role',
  messages: 'id, conversation_id, sender_id, type_message, created_at',
  user_presences: 'user_id, nom_complet, en_ligne, derniere_connexion'
});

db.version(5).stores({
  conversation_participants: 'id, conversation_id, user_id, statut_role, statut_invitation',
  notifications: 'id, user_id, type, lu, created_at',
  sujets_evaluations: 'id, classe_id, matiere_id, enseignant_id, type_evaluation, statut, date_limite, created_at'
});

db.version(6).stores({
  soumissions_notes: 'id, classe_id, matiere_id, enseignant_id, semestre, annee_academique, type_epreuve, statut, date_soumission',
  notes_provisoires: 'id, soumission_id, classe_id, matiere_id, etudiant_id, semestre'
});

db.version(7).stores({
  publications_academiques: 'id, type, classe_id, semestre, publie',
  emplois_du_temps: 'id, classe_id, matiere_id, enseignant_id, jour_semaine, salle, semestre, annee_academique, statut, publie',
  notes: 'id, classe_id, matiere_id, etudiant_id, semestre, annee_academique, publie'
});

/** Helper pour vérifier si un élément académique est officiellement publié par le DAC */
export async function estPublieDAC(
  type: 'emploi_du_temps' | 'notes_moyennes' | 'calendrier',
  classeId?: string,
  semestre?: string
): Promise<boolean> {
  try {
    if (type === 'calendrier') {
      const cals = await db.calendriers_academiques.toArray();
      return cals.some(c => c.publie);
    }
    const pubKey = `pub_${type}_${classeId || 'all'}_${semestre || 'all'}`;
    const pub = await db.publications_academiques.get(pubKey);
    return pub ? Boolean(pub.publie) : false;
  } catch {
    return false;
  }
}

/** Helper pour basculer la publication officielle d'un élément académique par le DAC */
export async function setPublicationDAC(
  type: 'emploi_du_temps' | 'notes_moyennes' | 'calendrier',
  publie: boolean,
  classeId?: string,
  semestre?: string,
  anneeAcademique: string = '2025-2026',
  titre?: string
): Promise<void> {
  const pubKey = `pub_${type}_${classeId || 'all'}_${semestre || 'all'}`;
  const user = JSON.parse(localStorage.getItem('dac_user') || '{"nom":"DAC ISGI"}');
  const record: PublicationAcademique = {
    id: pubKey,
    type,
    classe_id: classeId,
    semestre,
    annee_academique: anneeAcademique,
    titre,
    publie,
    publie_par: user.nom || 'DAC',
    date_publication: publie ? new Date().toISOString() : undefined,
    updated_at: new Date().toISOString()
  };

  await db.publications_academiques.put(record);
  await pushItemToSupabase('publications_academiques', record);

  // Si c'est un emploi du temps, propager publie sur tous les créneaux concernés
  if (type === 'emploi_du_temps' && classeId && classeId !== 'all') {
    const cours = await db.emplois_du_temps
      .where('classe_id')
      .equals(classeId)
      .toArray();
    
    const filtered = semestre && semestre !== 'all' 
      ? cours.filter(c => c.semestre === semestre)
      : cours;

    const updatedCours = filtered.map(c => ({ ...c, publie }));
    if (updatedCours.length > 0) {
      await db.emplois_du_temps.bulkPut(updatedCours);
      supabase.from('emplois_du_temps').upsert(updatedCours).then(() => {}, () => {});
    }
  }

  // Si ce sont les notes/moyennes, propager publie sur toutes les notes de la classe et du semestre
  if (type === 'notes_moyennes' && classeId && classeId !== 'all') {
    const notes = await db.notes
      .where('classe_id')
      .equals(classeId)
      .toArray();
    
    const filteredNotes = semestre && semestre !== 'all'
      ? notes.filter(n => n.semestre === semestre)
      : notes;

    const updatedNotes = filteredNotes.map(n => ({ ...n, publie }));
    if (updatedNotes.length > 0) {
      await db.notes.bulkPut(updatedNotes);
      supabase.from('notes').upsert(updatedNotes).then(() => {}, () => {});
    }
  }

  await logAction(
    publie ? 'Publication Académique Officielle' : 'Retrait de Publication (Confidentiel)',
    type.toUpperCase(),
    `${type} pour Classe: ${classeId || 'Toutes'}, Semestre: ${semestre || 'Tous'} — Statut: ${publie ? 'PUBLIÉ (Visible par tous)' : 'CONFIDENTIEL (Brouillon DAC)'}`
  );
}
export async function logAction(action: string, module: string, details?: string) {
  const user = JSON.parse(localStorage.getItem('dac_user') || '{"nom":"Directeur des Affaires Académiques"}');
  const entry: JournalActivite = {
    id: 'log_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
    utilisateur_nom: user.nom || 'DAC ISGI',
    action,
    module,
    details: details || '',
    date_action: new Date().toISOString()
  };
  try {
    await db.journal_activites.put(entry);
    // Colonnes conformes à la table Supabase existante
    await supabase.from('journal_activites').insert([{
      utilisateur_nom: entry.utilisateur_nom,
      type_action: action,
      description: `[${module}] ${details || ''}`,
      date_action: entry.date_action
    }]).then(() => {}, () => {});
  } catch (e) {
    // Silencieux
  }
}

// Fonction de synchronisation sécurisée bidirectionnelle avec Supabase
export async function syncFromSupabase() {
  try {
    // 1. Synchroniser Étudiants
    try {
      const { data: remoteEtudiants, error: errEtud } = await supabase.from('etudiants').select('*');
      if (!errEtud && remoteEtudiants && remoteEtudiants.length > 0) {
        await db.etudiants.bulkPut(remoteEtudiants);
      }
    } catch {}

    // 2. Synchroniser Personnel
    try {
      const { data: remotePersonnel, error: errPers } = await supabase.from('personnel').select('*');
      if (!errPers && remotePersonnel && remotePersonnel.length > 0) {
        await db.personnel.bulkPut(remotePersonnel);
      }
    } catch {}

    // 3. Synchroniser Salles
    try {
      const { data: remoteSalles, error: errSalles } = await supabase.from('salles').select('*');
      if (!errSalles && remoteSalles && remoteSalles.length > 0) {
        await db.salles.bulkPut(remoteSalles);
      } else if (!errSalles) {
        const localSalles = await db.salles.toArray();
        if (localSalles.length > 0) {
          await supabase.from('salles').upsert(localSalles).then(() => {}, () => {});
        }
      }
    } catch {}

    // 4. Synchroniser Calendriers Académiques
    try {
      const { data: remoteCal, error: errCal } = await supabase.from('calendriers_academiques').select('*');
      if (!errCal && remoteCal && remoteCal.length > 0) {
        await db.calendriers_academiques.bulkPut(remoteCal);
      } else if (!errCal) {
        const localCal = await db.calendriers_academiques.toArray();
        if (localCal.length > 0) {
          await supabase.from('calendriers_academiques').upsert(localCal).then(() => {}, () => {});
        }
      }
    } catch {}

    // 5. Synchroniser Emplois du Temps
    try {
      const { data: remoteEdt, error: errEdt } = await supabase.from('emplois_du_temps').select('*');
      if (!errEdt && remoteEdt && remoteEdt.length > 0) {
        await db.emplois_du_temps.bulkPut(remoteEdt);
      } else if (!errEdt) {
        const localEdt = await db.emplois_du_temps.toArray();
        if (localEdt.length > 0) {
          await supabase.from('emplois_du_temps').upsert(localEdt).then(() => {}, () => {});
        }
      }
    } catch {}

    // 6. Synchroniser Messenger (Conversations, Participants, Messages, Présences)
    try {
      const { data: remoteConv, error: errConv } = await supabase.from('conversations').select('*');
      if (!errConv && remoteConv && remoteConv.length > 0) {
        await db.conversations.bulkPut(remoteConv);
      }
    } catch {}

    try {
      const { data: remoteParts, error: errParts } = await supabase.from('conversation_participants').select('*');
      if (!errParts && remoteParts && remoteParts.length > 0) {
        await db.conversation_participants.bulkPut(remoteParts);
      }
    } catch {}

    try {
      const { data: remoteMsg, error: errMsg } = await supabase.from('messages').select('*');
      if (!errMsg && remoteMsg && remoteMsg.length > 0) {
        await db.messages.bulkPut(remoteMsg);
      }
    } catch {}

    try {
      const { data: remotePres, error: errPres } = await supabase.from('user_presences').select('*');
      if (!errPres && remotePres && remotePres.length > 0) {
        await db.user_presences.bulkPut(remotePres);
      }
    } catch {}

    try {
      const { data: remoteNotifs, error: errNotifs } = await supabase.from('notifications').select('*');
      if (!errNotifs && remoteNotifs && remoteNotifs.length > 0) {
        await db.notifications.bulkPut(remoteNotifs);
      }
    } catch {}

    try {
      const { data: remoteSujets, error: errSujets } = await supabase.from('sujets_evaluations').select('*');
      if (!errSujets && remoteSujets && remoteSujets.length > 0) {
        await db.sujets_evaluations.bulkPut(remoteSujets);
      }
    } catch {}

    try {
      const { data: remoteSoumissions, error: errSoum } = await supabase.from('soumissions_notes').select('*');
      if (!errSoum && remoteSoumissions && remoteSoumissions.length > 0) {
        await db.soumissions_notes.bulkPut(remoteSoumissions);
      }
    } catch {}

    try {
      const { data: remoteNotesProv, error: errNotesProv } = await supabase.from('notes_provisoires').select('*');
      if (!errNotesProv && remoteNotesProv && remoteNotesProv.length > 0) {
        await db.notes_provisoires.bulkPut(remoteNotesProv);
      }
    } catch {}

    try {
      const { data: remotePubs, error: errPubs } = await supabase.from('publications_academiques').select('*');
      if (!errPubs && remotePubs && remotePubs.length > 0) {
        await db.publications_academiques.bulkPut(remotePubs);
      }
    } catch {}

    // Amorcer les données académiques et messenger si nécessaire
    const classesCount = await db.classes.count();
    const matieresCount = await db.matieres.count();
    const calCount = await db.calendriers_academiques.count();
    const edtCount = await db.emplois_du_temps.count();
    const sallesCount = await db.salles.count();

    if (classesCount === 0 || matieresCount === 0 || calCount === 0 || edtCount === 0 || sallesCount === 0) {
      await seedInitialAcademicData();
    }
  } catch (error) {
    console.warn('Sync notice:', error);
  }
}

// Helpers d'envoi unitaire vers Supabase
export async function pushItemToSupabase(tableName: string, data: any) {
  try {
    await supabase.from(tableName).upsert(data).then(() => {}, () => {});
  } catch {}
}

export async function deleteItemFromSupabase(tableName: string, id: string) {
  try {
    await supabase.from(tableName).delete().eq('id', id).then(() => {}, () => {});
  } catch {}
}

// Données académiques initiales ISGI
async function seedInitialAcademicData() {
  const matieresCount = await db.matieres.count();
  if (matieresCount === 0) {
    const initialMatieres: Matiere[] = [
      {
        id: 'mat_algo',
        code: 'INF101',
        nom: 'Algorithmique et Programmation I',
        credits: 4,
        coefficient: 3.0,
        description: 'Bases de l’algorithmique, pseudo-code et langage C/Python'
      },
      {
        id: 'mat_bdd',
        code: 'INF102',
        nom: 'Systèmes de Gestion de Bases de Données (SGBD)',
        credits: 4,
        coefficient: 2.5,
        description: 'Modélisation relationnelle Merise, SQL et administration'
      },
      {
        id: 'mat_reseaux',
        code: 'TEL101',
        nom: 'Architecture des Réseaux Informatiques',
        credits: 3,
        coefficient: 2.0,
        description: 'Modèle OSI/TCP-IP, adressage IPv4/IPv6, routage'
      },
      {
        id: 'mat_maths',
        code: 'MAT101',
        nom: 'Mathématiques Appliquées & Algèbre',
        credits: 3,
        coefficient: 2.0,
        description: 'Calcul matriciel, probabilités et statistiques'
      },
      {
        id: 'mat_anglais',
        code: 'LAN101',
        nom: 'Anglais Technique & Communication',
        credits: 2,
        coefficient: 1.5,
        description: 'Expression professionnelle et vocabulaire technique'
      },
      {
        id: 'mat_web',
        code: 'INF201',
        nom: 'Développement Web Moderne',
        credits: 4,
        coefficient: 3.0,
        description: 'Applications web interactives, React et API REST'
      },
      {
        id: 'mat_compta',
        code: 'GES101',
        nom: 'Comptabilité Générale & Gestion',
        credits: 2,
        coefficient: 1.5,
        description: 'Principes comptables OHADA et bilan'
      }
    ];
    await db.matieres.bulkPut(initialMatieres);
  }

  const classesCount = await db.classes.count();
  if (classesCount === 0) {
    const initialClasses: Classe[] = [
      {
        id: 'cls_l1_techno',
        nom: 'L1 Tronc Commun Technologie',
        code: 'L1-TCT',
        niveau: 'Licence 1',
        filiere: 'Informatique',
        annee_academique: '2025-2026',
        capacite_max: 45,
        salle_principale: 'Amphi A - Campus 1',
        description: 'Première année d’ingénierie et technologie'
      },
      {
        id: 'cls_l2_gl',
        nom: 'L2 Génie Logiciel & SI',
        code: 'L2-GL',
        niveau: 'Licence 2',
        filiere: 'Informatique',
        annee_academique: '2025-2026',
        capacite_max: 40,
        salle_principale: 'Salle Info 2',
        description: 'Deuxième année spécialisation développement'
      },
      {
        id: 'cls_l3_rt',
        nom: 'L3 Réseaux & Télécoms',
        code: 'L3-RT',
        niveau: 'Licence 3',
        filiere: 'Informatique',
        annee_academique: '2025-2026',
        capacite_max: 35,
        salle_principale: 'Labo Réseaux',
        description: 'Licence 3 Administration réseaux'
      }
    ];
    await db.classes.bulkPut(initialClasses);
  }

  // Calendriers académiques
  const calCount = await db.calendriers_academiques.count();
  if (calCount === 0) {
    const initialCalendriers: CalendrierAcademique[] = [
      {
        id: 'cal_2025_s1',
        annee_academique: '2025-2026',
        semestre: 'S1',
        type_rentree: 'Octobre (Principale)',
        date_debut_cours: '2025-10-15',
        date_fin_cours: '2026-02-14',
        date_debut_dst: '2025-11-24',
        date_fin_dst: '2025-11-29',
        date_debut_recherche: '2025-12-08',
        date_fin_recherche: '2025-12-20',
        date_debut_conge_etude: '2026-02-16',
        date_fin_conge_etude: '2026-02-21',
        date_debut_examens: '2026-02-23',
        date_fin_examens: '2026-03-07',
        date_reprise_cours: '2026-03-16',
        date_debut_rattrapage: '2026-03-23',
        date_fin_rattrapage: '2026-03-28',
        date_debut_stage: '2026-07-01',
        date_fin_stage: '2026-09-30',
        statut: 'termine',
        publie: true,
        observations: 'Semestre 1 clôturé avec procès-verbal de délibération des notes validé.',
        evenements_speciaux: [
          { titre: 'Rentrée Solennelle & Accueil des Nouveaux', date: '2025-10-15', type: 'ceremonie', description: 'Amphithéâtre A avec la direction générale et le corps enseignant' },
          { titre: 'Congés de Noël & Nouvel An', date: '2025-12-20', date_fin: '2026-01-04', type: 'fete', description: 'Fermeture administrative et congés des étudiants' }
        ],
        created_at: new Date().toISOString()
      },
      {
        id: 'cal_2025_s2',
        annee_academique: '2025-2026',
        semestre: 'S2',
        type_rentree: 'Octobre (Principale)',
        date_debut_cours: '2026-03-16',
        date_fin_cours: '2026-06-20',
        date_debut_dst: '2026-04-20',
        date_fin_dst: '2026-04-25',
        date_debut_recherche: '2026-05-04',
        date_fin_recherche: '2026-05-16',
        date_debut_conge_etude: '2026-06-22',
        date_fin_conge_etude: '2026-06-27',
        date_debut_examens: '2026-06-29',
        date_fin_examens: '2026-07-11',
        date_reprise_cours: '2026-07-20',
        date_debut_rattrapage: '2026-07-27',
        date_fin_rattrapage: '2026-08-01',
        date_debut_stage: '2026-08-03',
        date_fin_stage: '2026-10-15',
        statut: 'en_cours',
        publie: true,
        observations: 'Semestre 2 actuellement en progression académique.',
        evenements_speciaux: [
          { titre: 'Hackathon & Forum Entreprises ISGI', date: '2026-05-08', date_fin: '2026-05-09', type: 'pedagogique', description: 'Compétition inter-filières et rencontre avec les recruteurs' },
          { titre: 'Gala Annuel & Cérémonie d\'Excellence', date: '2026-07-18', type: 'ceremonie', description: 'Remise des prix aux meilleurs étudiants et célébration' }
        ],
        created_at: new Date().toISOString()
      },
      {
        id: 'cal_2026_s1',
        annee_academique: '2026-2027',
        semestre: 'S1',
        type_rentree: 'Octobre (Principale)',
        date_debut_cours: '2026-10-12',
        date_fin_cours: '2027-02-13',
        date_debut_dst: '2026-11-23',
        date_fin_dst: '2026-11-28',
        date_debut_recherche: '2026-12-07',
        date_fin_recherche: '2026-12-19',
        date_debut_conge_etude: '2027-02-15',
        date_fin_conge_etude: '2027-02-20',
        date_debut_examens: '2027-02-22',
        date_fin_examens: '2027-03-06',
        date_reprise_cours: '2027-03-15',
        date_debut_rattrapage: '2027-03-22',
        date_fin_rattrapage: '2027-03-27',
        date_debut_stage: '2027-07-01',
        date_fin_stage: '2027-09-30',
        statut: 'planifie',
        publie: true,
        observations: 'Calendrier officiel prévisionnel validé par le Conseil Académique.',
        evenements_speciaux: [
          { titre: 'Rentrée Solennelle 2026-2027', date: '2026-10-12', type: 'ceremonie', description: 'Cérémonie d\'ouverture de l\'année académique' }
        ],
        created_at: new Date().toISOString()
      }
    ];
    await db.calendriers_academiques.bulkPut(initialCalendriers);
  }

  // Emplois du temps
  const edtCount = await db.emplois_du_temps.count();
  if (edtCount === 0) {
    const initialEdt: EmploiDuTempsItem[] = [
      // L1 Tronc Commun Technologie
      {
        id: 'edt_l1_1',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_algo',
        jour_semaine: 'Lundi',
        heure_debut: '08:00',
        heure_fin: '10:00',
        salle: 'Amphi A - Campus 1',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#0284c7'
      },
      {
        id: 'edt_l1_2',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_algo',
        jour_semaine: 'Lundi',
        heure_debut: '10:15',
        heure_fin: '12:15',
        salle: 'Labo Info 1',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#0284c7'
      },
      {
        id: 'edt_l1_3',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_anglais',
        jour_semaine: 'Lundi',
        heure_debut: '14:00',
        heure_fin: '16:00',
        salle: 'Salle 102',
        type_cours: 'TD',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#10b981'
      },
      {
        id: 'edt_l1_4',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_bdd',
        jour_semaine: 'Mardi',
        heure_debut: '08:00',
        heure_fin: '10:00',
        salle: 'Amphi A - Campus 1',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#8b5cf6'
      },
      {
        id: 'edt_l1_5',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_bdd',
        jour_semaine: 'Mardi',
        heure_debut: '10:15',
        heure_fin: '12:15',
        salle: 'Labo Info 2',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#8b5cf6'
      },
      {
        id: 'edt_l1_6',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_maths',
        jour_semaine: 'Mercredi',
        heure_debut: '08:00',
        heure_fin: '10:00',
        salle: 'Salle 101',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#f59e0b'
      },
      {
        id: 'edt_l1_7',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_maths',
        jour_semaine: 'Mercredi',
        heure_debut: '10:15',
        heure_fin: '12:15',
        salle: 'Salle 101',
        type_cours: 'TD',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#f59e0b'
      },
      {
        id: 'edt_l1_8',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_reseaux',
        jour_semaine: 'Jeudi',
        heure_debut: '08:30',
        heure_fin: '11:30',
        salle: 'Labo Réseaux',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#ec4899'
      },
      {
        id: 'edt_l1_9',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_web',
        jour_semaine: 'Vendredi',
        heure_debut: '08:00',
        heure_fin: '11:00',
        salle: 'Labo Info 1',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#06b6d4'
      },
      {
        id: 'edt_l1_10',
        classe_id: 'cls_l1_techno',
        matiere_id: 'mat_compta',
        jour_semaine: 'Vendredi',
        heure_debut: '14:00',
        heure_fin: '16:00',
        salle: 'Salle 103',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#64748b'
      },
      // L2 Génie Logiciel
      {
        id: 'edt_l2_1',
        classe_id: 'cls_l2_gl',
        matiere_id: 'mat_web',
        jour_semaine: 'Lundi',
        heure_debut: '10:15',
        heure_fin: '12:15',
        salle: 'Labo Info 2',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#06b6d4'
      },
      {
        id: 'edt_l2_2',
        classe_id: 'cls_l2_gl',
        matiere_id: 'mat_web',
        jour_semaine: 'Lundi',
        heure_debut: '14:00',
        heure_fin: '17:00',
        salle: 'Labo Info 2',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#06b6d4'
      },
      {
        id: 'edt_l2_3',
        classe_id: 'cls_l2_gl',
        matiere_id: 'mat_bdd',
        jour_semaine: 'Mardi',
        heure_debut: '14:00',
        heure_fin: '17:00',
        salle: 'Labo Info 1',
        type_cours: 'TP',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#8b5cf6'
      },
      {
        id: 'edt_l2_4',
        classe_id: 'cls_l2_gl',
        matiere_id: 'mat_reseaux',
        jour_semaine: 'Mercredi',
        heure_debut: '10:15',
        heure_fin: '12:15',
        salle: 'Labo Réseaux',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#ec4899'
      },
      {
        id: 'edt_l2_5',
        classe_id: 'cls_l2_gl',
        matiere_id: 'mat_algo',
        jour_semaine: 'Jeudi',
        heure_debut: '08:00',
        heure_fin: '11:00',
        salle: 'Salle Info 2',
        type_cours: 'CM',
        semestre: 'S1',
        annee_academique: '2025-2026',
        statut: 'actif',
        couleur: '#0284c7'
      }
    ];
    await db.emplois_du_temps.bulkPut(initialEdt);
  }

  // Salles académiques
  const sallesCount = await db.salles.count();
  if (sallesCount === 0) {
    const initialSalles: Salle[] = [
      {
        id: 'sal_amphi_a',
        nom: 'Amphi A - Campus 1',
        code: 'AMP-A',
        capacite: 150,
        type_salle: 'amphi',
        batiment: 'Campus 1 - Bâtiment Principal',
        etage: 'RDC',
        equipements: ['Sonorisation complète', 'Vidéoprojecteur HD', 'Climatisation', 'Wi-Fi haut débit', 'Microphones sans fil'],
        statut: 'disponible',
        description: 'Grand amphithéâtre pour cours magistraux, conférences et soutenances'
      },
      {
        id: 'sal_amphi_b',
        nom: 'Amphi B - Campus 1',
        code: 'AMP-B',
        capacite: 100,
        type_salle: 'amphi',
        batiment: 'Campus 1 - Bâtiment Principal',
        etage: '1er Étage',
        equipements: ['Vidéoprojecteur', 'Climatisation', 'Wi-Fi', 'Tableau blanc'],
        statut: 'disponible',
        description: 'Deuxième amphithéâtre pour promotions intermédiaires'
      },
      {
        id: 'sal_labo_info1',
        nom: 'Labo Info 1 (Développement)',
        code: 'LAB-01',
        capacite: 40,
        type_salle: 'labo_info',
        batiment: 'Bâtiment Informatique & Technologies',
        etage: '1er Étage',
        equipements: ['40 Postes PC Core i7', 'Connexion Fibre Gigabit', 'Vidéoprojecteur', 'Climatisation', 'Serveurs de dev'],
        statut: 'disponible',
        description: 'Laboratoire dédié au développement web, logiciel et algorithmique'
      },
      {
        id: 'sal_labo_info2',
        nom: 'Labo Info 2 (Bases de données)',
        code: 'LAB-02',
        capacite: 35,
        type_salle: 'labo_info',
        batiment: 'Bâtiment Informatique & Technologies',
        etage: '1er Étage',
        equipements: ['35 Postes PC', 'SGBD MySQL & PostgreSQL configurés', 'Climatisation', 'Vidéoprojecteur'],
        statut: 'disponible',
        description: 'Laboratoire pour modélisation, requêtage et administration de bases de données'
      },
      {
        id: 'sal_labo_reseaux',
        nom: 'Labo Réseaux & Télécoms',
        code: 'LAB-RES',
        capacite: 30,
        type_salle: 'labo_reseau',
        batiment: 'Bâtiment Informatique & Technologies',
        etage: 'RDC',
        equipements: ['Racks d\'interconnexion', 'Routeurs & Switchs Cisco', 'Bancs de câblage Ethernet/Fibre', 'Climatisation'],
        statut: 'disponible',
        description: 'Laboratoire spécialisé pour TP réseau, architecture et sécurité'
      },
      {
        id: 'sal_101',
        nom: 'Salle 101 (Cours Magistraux)',
        code: 'S-101',
        capacite: 50,
        type_salle: 'classe',
        batiment: 'Bâtiment Pédagogique A',
        etage: '1er Étage',
        equipements: ['Tableau blanc magnétique', 'Vidéoprojecteur', 'Climatisation'],
        statut: 'disponible',
        description: 'Grande salle de cours magistraux et tronc commun'
      },
      {
        id: 'sal_102',
        nom: 'Salle 102 (Travaux Dirigés)',
        code: 'S-102',
        capacite: 45,
        type_salle: 'classe',
        batiment: 'Bâtiment Pédagogique A',
        etage: '1er Étage',
        equipements: ['Tableau blanc', 'Climatisation'],
        statut: 'disponible',
        description: 'Salle modulable pour travaux dirigés et cours de langues'
      },
      {
        id: 'sal_103',
        nom: 'Salle 103 (Gestion & Économie)',
        code: 'S-103',
        capacite: 45,
        type_salle: 'classe',
        batiment: 'Bâtiment Pédagogique A',
        etage: '1er Étage',
        equipements: ['Tableau blanc', 'Climatisation', 'Wi-Fi'],
        statut: 'disponible',
        description: 'Salle de cours pour filières de gestion et comptabilité'
      },
      {
        id: 'sal_201',
        nom: 'Salle 201 (Langues & Séminaires)',
        code: 'S-201',
        capacite: 35,
        type_salle: 'classe',
        batiment: 'Bâtiment Pédagogique B',
        etage: '2ème Étage',
        equipements: ['Écran tactile interactif', 'Casques d\'écoute', 'Climatisation'],
        statut: 'disponible',
        description: 'Salle multimédia pour anglais technique et ateliers'
      }
    ];
    await db.salles.bulkPut(initialSalles);
  }

  // Initialiser les présences du personnel administratif si nécessaire
  const presCount = await db.user_presences.count();
  if (presCount === 0) {
    await seedInitialMessengerData();
  }

  // Initialiser les Sujets d'évaluation et les Notifications
  await seedInitialSujetsAndNotifications();
}

async function seedInitialMessengerData() {
  const presences: UserPresence[] = [
    {
      user_id: 'dac_default',
      nom_complet: 'Prof. M. DIALLO (DAC)',
      role: 'Directeur des Affaires Académiques',
      en_ligne: true,
      derniere_connexion: new Date().toISOString(),
      statut_perso: 'Direction Académique'
    },
    {
      user_id: 'user_sg',
      nom_complet: 'Dr. A. KOUAME',
      role: 'Secrétaire Général',
      en_ligne: false,
      derniere_connexion: new Date().toISOString(),
      statut_perso: 'Secrétariat Général'
    },
    {
      user_id: 'user_dg',
      nom_complet: 'Dr. B. SANOGO',
      role: 'Directeur Général',
      en_ligne: false,
      derniere_connexion: new Date().toISOString(),
      statut_perso: 'Direction Générale'
    },
    {
      user_id: 'user_compta',
      nom_complet: 'Mme. F. SYLLA',
      role: 'Chef Service Comptabilité',
      en_ligne: false,
      derniere_connexion: new Date().toISOString(),
      statut_perso: 'Comptabilité'
    }
  ];
  await db.user_presences.bulkPut(presences);
}

async function seedInitialSujetsAndNotifications() {
  // Prêt pour la production : Aucune notification ni sujet fictif injecté
}

