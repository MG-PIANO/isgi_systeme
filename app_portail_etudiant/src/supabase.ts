import { createClient } from '@supabase/supabase-js'
import type { PortalDashboard, Student, Grade, Attendance, Payment, TimetableItem, PortalVideo } from './types'

export const SUPABASE_URL = 'https://vbdhmgrysrerlmgumafx.supabase.co'
export const SUPABASE_ANON_KEY = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax'

export const supabase = createClient(SUPABASE_URL, SUPABASE_ANON_KEY)

const PORTAL_EMAIL_DOMAIN = 'accounts.isgi.cg'

export function syntheticEmail(username: string): string {
  return `${username.trim().toLowerCase()}@${PORTAL_EMAIL_DOMAIN}`
}

async function resolveStorageUrl(path: string | null | undefined, bucket: string): Promise<string | null> {
  if (!path) return null
  if (path.startsWith('data:') || path.startsWith('http')) return path
  try {
    const { data } = await supabase.storage.from(bucket).createSignedUrl(path, 86400)
    if (data?.signedUrl) return data.signedUrl
    const { data: pubData } = supabase.storage.from(bucket).getPublicUrl(path)
    return pubData?.publicUrl || null
  } catch {
    return null
  }
}

export async function portalRequest<T>(
  action: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  // ── 1. Action : Inscription (Register) ───────────────────────────
  if (action === 'register') {
    const username = String(payload.username || '').trim().toLowerCase()
    const password = String(payload.password || '')
    const accountType = (payload.accountType as 'etudiant' | 'tuteur') || 'etudiant'
    const fullName = String(payload.fullName || '').trim()
    const matricules = Array.isArray(payload.matricules)
      ? payload.matricules.map((m: unknown) => String(m).trim().toUpperCase()).filter(Boolean)
      : []

    if (!username || username.length < 3) {
      throw new Error('Le nom d’utilisateur doit contenir au moins 3 caractères.')
    }
    if (!password || password.length < 10) {
      throw new Error('Le mot de passe doit contenir au moins 10 caractères.')
    }
    if (!matricules.length) {
      throw new Error('Veuillez renseigner votre matricule étudiant.')
    }

    // Vérifier l'existence des matricules dans la base
    const { data: matchedStudents, error: studentError } = await supabase
      .from('etudiants')
      .select('id, matricule, nom, prenom')
      .in('matricule', matricules)

    if (studentError) {
      throw new Error(`Erreur lors de la vérification : ${studentError.message}`)
    }
    if (!matchedStudents || matchedStudents.length === 0) {
      throw new Error('Aucun étudiant inscrit ne correspond au(x) matricule(s) saisi(s). Vérifiez votre matricule.')
    }

    // Créer le compte utilisateur dans Supabase Auth
    const email = syntheticEmail(username)
    const { error: authError } = await supabase.auth.signUp({
      email,
      password,
      options: {
        data: {
          username,
          account_type: accountType,
          full_name: fullName,
          matricules: matchedStudents.map((s) => s.matricule),
          student_ids: matchedStudents.map((s) => s.id),
        },
      },
    })

    if (authError) {
      if (authError.message.includes('already') || authError.message.includes('exists')) {
        throw new Error('Ce nom d’utilisateur est déjà utilisé. Veuillez en choisir un autre.')
      }
      throw new Error(authError.message)
    }

    return {
      status: 'active',
      message: 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.',
    } as unknown as T
  }

  // Pour les actions suivantes, vérifier la session active
  const { data: sessionData, error: sessionErr } = await supabase.auth.getSession()
  const user = sessionData?.session?.user
  if (sessionErr || !user) {
    throw new Error('Session expirée ou connexion requise.')
  }

  // ── 2. Action : Statut du compte (Account Status) ────────────────
  if (action === 'account-status') {
    const meta = user.user_metadata || {}
    const username = meta.username || user.email?.split('@')[0] || ''
    const account_type = (meta.account_type as 'etudiant' | 'tuteur') || 'etudiant'
    const full_name = meta.full_name || username

    return {
      username,
      account_type,
      full_name,
      status: 'active',
    } as unknown as T
  }

  // ── 3. Action : Activation par code (Redeem Code) ────────────────
  if (action === 'redeem-code') {
    return {
      status: 'active',
      message: 'Compte activé.',
    } as unknown as T
  }

  // ── 4. Action : Données scolaires (Dashboard) ────────────────────
  if (action === 'dashboard') {
    const meta = user.user_metadata || {}
    let matricules: string[] = Array.isArray(meta.matricules)
      ? meta.matricules.map((m: unknown) => String(m).trim().toUpperCase()).filter(Boolean)
      : []

    // Si aucun matricule dans les métadonnées, tenter de retrouver par le nom d'utilisateur
    let studentsQuery = supabase.from('etudiants').select('*')
    if (matricules.length > 0) {
      studentsQuery = studentsQuery.in('matricule', matricules)
    } else {
      const uMat = (meta.username || user.email?.split('@')[0] || '').toUpperCase()
      studentsQuery = studentsQuery.or(`matricule.eq.${uMat},nom.ilike.%${uMat}%`)
    }

    const { data: rawStudents, error: studentsErr } = await studentsQuery
    if (studentsErr) throw new Error(`Chargement du dossier étudiant impossible : ${studentsErr.message}`)
    if (!rawStudents || rawStudents.length === 0) {
      throw new Error('Aucun dossier étudiant actif n’est rattaché à ce compte.')
    }

    // Références pour les liaisons (ID et Matricule)
    const references: string[] = []
    for (const s of rawStudents) {
      if (s.id) references.push(String(s.id))
      if (s.matricule) references.push(String(s.matricule))
    }

    // Requêtes parallèles sur les tables académiques
    const [notesRes, presencesRes, paiementsRes, classesRes, emploisRes, matieresRes, videosRes] =
      await Promise.all([
        supabase.from('notes').select('*').in('etudiant_id', references),
        supabase.from('presences_etudiants').select('*').in('etudiant_id', references),
        supabase.from('paiements').select('*').in('etudiant_id', references),
        supabase.from('classes').select('*'),
        supabase.from('emplois_du_temps').select('*'),
        supabase.from('matieres').select('*'),
        supabase.from('videos').select('*'),
      ])

    const classesList = classesRes.data || []
    const classesMap = new Map<string, any>(classesList.map((c: any) => [String(c.id), c]))

    // Formater les étudiants avec leur photo résolue
    const students: Student[] = await Promise.all(
      rawStudents.map(async (s: any) => {
        const cls = s.classe_id ? classesMap.get(String(s.classe_id)) : null
        const resolvedPhoto = await resolveStorageUrl(s.photo_url, 'student-photos')
        return {
          id: String(s.id),
          matricule: s.matricule || '',
          nom: s.nom || '',
          prenom: s.prenom || '',
          filiere: s.filiere || cls?.filiere || '',
          niveau: s.niveau || cls?.niveau || '',
          annee_academique: s.annee_academique || cls?.annee_academique || '',
          statut: s.statut || 'INSCRIT',
          photo_url: resolvedPhoto,
          classe: s.classe_nom || s.classe || cls?.nom || null,
          class_ids: [s.classe_id ? String(s.classe_id) : ''].filter(Boolean),
          qr_code_data: s.qr_code_data || undefined,
        }
      }),
    )

    // Formater les notes
    const grades: Grade[] = (notesRes.data || []).map((n: any) => ({
      id: String(n.id),
      etudiant_id: String(n.etudiant_id),
      matiere_id: String(n.matiere_id || ''),
      note_finale: Number(n.note_finale || n.note || 0),
      semestre: n.semestre || 'Semestre 1',
      annee_academique: n.annee_academique || '',
      observations: n.observations || n.appreciation || '',
    }))

    // Formater les présences
    const attendance: Attendance[] = (presencesRes.data || []).map((p: any) => ({
      id: String(p.id),
      etudiant_id: String(p.etudiant_id),
      date_seance: p.date_seance || p.date || '',
      statut: p.statut || 'PRESENT',
      motif: p.motif || '',
      matiere_id: p.matiere_id ? String(p.matiere_id) : undefined,
    }))

    // Formater les paiements
    const payments: Payment[] = (paiementsRes.data || []).map((pay: any) => ({
      id: String(pay.id),
      etudiant_id: String(pay.etudiant_id),
      type_paiement: pay.type_paiement || 'FRAIS_SCOLARITE',
      montant: Number(pay.montant || 0),
      mode_paiement: pay.mode_paiement || 'ESPECES',
      reference_transaction: pay.reference_transaction || pay.reference || '',
      statut: pay.statut || 'VALIDE',
      created_at: pay.created_at || pay.date_paiement || '',
    }))

    // Formater l'emploi du temps
    const timetable: TimetableItem[] = (emploisRes.data || []).map((item: any) => ({
      id: String(item.id),
      classe_id: String(item.classe_id),
      matiere_id: String(item.matiere_id || ''),
      jour_semaine: item.jour_semaine || item.jour || '',
      heure_debut: item.heure_debut || '',
      heure_fin: item.heure_fin || '',
      salle: item.salle || '',
      type_cours: item.type_cours || 'CM',
    }))

    // Formater les vidéos (avec URLs signées pour lecture sécurisée)
    const rawVideos = videosRes.data || []
    const videos: PortalVideo[] = await Promise.all(
      rawVideos.map(async (v: any) => ({
        id: String(v.id),
        titre: v.titre || '',
        description: v.description || '',
        visibilite: (v.visibilite as 'publique' | 'public' | 'classe') || 'publique',
        classe_nom: v.classe_nom || '',
        url_video: (await resolveStorageUrl(v.url_video, 'videos-isgi')) || undefined,
        url_miniature: (await resolveStorageUrl(v.url_miniature, 'videos-isgi')) || undefined,
        duree_secondes: v.duree_secondes ? Number(v.duree_secondes) : undefined,
      })),
    )

    const subjects = (matieresRes.data || []).map((m: any) => ({
      id: String(m.id),
      nom: m.nom || m.libelle || '',
      code: m.code || '',
    }))

    const classes = classesList.map((c: any) => ({
      id: String(c.id),
      nom: c.nom || '',
    }))

    const dashboard: PortalDashboard = {
      account: {
        username: meta.username || user.email?.split('@')[0] || '',
        account_type: (meta.account_type as 'etudiant' | 'tuteur') || 'etudiant',
        full_name: meta.full_name || meta.username || 'Étudiant',
      },
      students,
      grades,
      attendance,
      payments,
      timetable,
      subjects,
      classes,
      videos,
    }

    return dashboard as unknown as T
  }

  throw new Error(`Action inconnue : ${action}`)
}
