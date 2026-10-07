export interface Student {
  id: string
  matricule: string
  nom: string
  prenom: string
  filiere?: string
  niveau?: string
  annee_academique?: string
  statut?: string
  photo_url?: string | null
  classe?: string | null
  class_ids?: string[]
  qr_code_data?: string
}

export interface Grade {
  id: string
  etudiant_id: string
  matiere_id: string
  note_finale: number
  semestre: string
  annee_academique: string
  observations?: string
}

export interface Attendance {
  id: string
  etudiant_id: string
  date_seance: string
  statut: string
  motif?: string
  matiere_id?: string
}

export interface Payment {
  id: string
  etudiant_id: string
  type_paiement: string
  montant: number
  mode_paiement?: string
  reference_transaction?: string
  statut: string
  created_at?: string
}

export interface TimetableItem {
  id: string
  classe_id: string
  matiere_id: string
  jour_semaine: string
  heure_debut: string
  heure_fin: string
  salle: string
  type_cours?: string
}

export interface PortalVideo {
  id: string
  titre: string
  description?: string
  visibilite: 'publique' | 'public' | 'classe'
  classe_nom?: string
  url_video?: string
  url_miniature?: string
  duree_secondes?: number
}

export interface PortalDashboard {
  account: { username: string; account_type: 'etudiant' | 'tuteur'; full_name: string }
  students: Student[]
  grades: Grade[]
  attendance: Attendance[]
  payments: Payment[]
  timetable: TimetableItem[]
  subjects: Array<{ id: string; nom: string; code?: string }>
  classes: Array<{ id: string; nom: string }>
  videos: PortalVideo[]
}
