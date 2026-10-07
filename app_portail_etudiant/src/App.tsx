import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import {
  Award,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  CreditCard,
  Clock3,
  GraduationCap,
  LogIn,
  LogOut,
  Menu,
  Moon,
  ShieldCheck,
  Sun,
  UserRound,
  UsersRound,
  Video,
  X,
} from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { portalRequest, supabase } from './supabase'
import type { Attendance, Grade, Payment, PortalDashboard, Student, TimetableItem } from './types'

type Screen = 'login' | 'register' | 'pending' | 'activation' | 'dashboard'
type AccountStatus = 'pending' | 'approved' | 'active' | 'rejected'
type Section = 'overview' | 'grades' | 'attendance' | 'payments' | 'timetable' | 'badge' | 'videos'

const syntheticEmail = (username: string) => `${username.trim().toLowerCase()}@accounts.isgi.cg`

function errorText(error: unknown): string {
  return error instanceof Error ? error.message : 'Une erreur inattendue est survenue.'
}

export function App() {
  const [screen, setScreen] = useState<Screen>('login')
  const [section, setSection] = useState<Section>('overview')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [accountType, setAccountType] = useState<'etudiant' | 'tuteur'>('etudiant')
  const [fullName, setFullName] = useState('')
  const [matriculesText, setMatriculesText] = useState('')
  const [activationCode, setActivationCode] = useState('')
  const [dashboard, setDashboard] = useState<PortalDashboard | null>(null)
  const [selectedStudentId, setSelectedStudentId] = useState('')
  const [busy, setBusy] = useState(false)
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const saved = localStorage.getItem('theme')
    if (saved === 'light' || saved === 'dark') return saved
    return 'light'
  })
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    localStorage.setItem('theme', theme)
    document.documentElement.classList.toggle('dark', theme === 'dark')
  }, [theme])

  const acceptCurrentSession = useCallback(async () => {
    const status = await portalRequest<{ status: AccountStatus }>('account-status')
    if (status.status === 'pending') setScreen('pending')
    else if (status.status === 'approved') setScreen('activation')
    else if (status.status === 'active') {
      const data = await portalRequest<PortalDashboard>('dashboard')
      setDashboard(data)
      setSelectedStudentId((current) => current || data.students[0]?.id || '')
      setScreen('dashboard')
    } else {
      await supabase.auth.signOut()
      setScreen('login')
      setError('Cette demande de compte a été refusée. Contactez le secrétariat.')
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    supabase.auth.getSession().then(async ({ data, error: sessionError }) => {
      if (cancelled || sessionError || !data.session) return
      try {
        await acceptCurrentSession()
      } catch (reason) {
        if (!cancelled) {
          setError(errorText(reason))
          await supabase.auth.signOut()
        }
      }
    })
    return () => { cancelled = true }
  }, [acceptCurrentSession])

  const selectedStudent = dashboard?.students.find((student) => student.id === selectedStudentId)
    || dashboard?.students[0]
    || null
  const studentReferences = selectedStudent
    ? new Set([selectedStudent.id, selectedStudent.matricule])
    : new Set<string>()

  const grades = useMemo(
    () => (dashboard?.grades || []).filter((grade) => studentReferences.has(grade.etudiant_id)),
    [dashboard, selectedStudent],
  )
  const attendance = useMemo(
    () => (dashboard?.attendance || []).filter((row) => studentReferences.has(row.etudiant_id)),
    [dashboard, selectedStudent],
  )
  const payments = useMemo(
    () => (dashboard?.payments || []).filter((payment) => studentReferences.has(payment.etudiant_id)),
    [dashboard, selectedStudent],
  )
  const classIds = useMemo(
    () => new Set(selectedStudent?.class_ids || []),
    [selectedStudent],
  )
  const timetable = useMemo(
    () => (dashboard?.timetable || []).filter((item) => classIds.has(item.classe_id)),
    [dashboard, classIds],
  )

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const cleanUsername = username.trim().toLowerCase()
      const { error: authError } = await supabase.auth.signInWithPassword({
        email: syntheticEmail(cleanUsername),
        password,
      })
      if (authError) throw new Error('Nom d’utilisateur ou mot de passe incorrect.')
      await acceptCurrentSession()
    } catch (reason) {
      setError(errorText(reason))
    } finally {
      setBusy(false)
    }
  }

  async function handleRegistration(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const matricules = matriculesText.split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean)
      const result = await portalRequest<{ message: string }>('register', {
        username,
        password,
        accountType,
        fullName,
        matricules,
      })
      setNotice(result.message)
      setScreen('pending')
      setPassword('')
    } catch (reason) {
      setError(errorText(reason))
    } finally {
      setBusy(false)
    }
  }

  async function handleActivation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await portalRequest('redeem-code', { code: activationCode })
      await acceptCurrentSession()
    } catch (reason) {
      setError(errorText(reason))
    } finally {
      setBusy(false)
    }
  }

  async function logout() {
    await supabase.auth.signOut()
    setDashboard(null)
    setPassword('')
    setActivationCode('')
    setScreen('login')
    setSection('overview')
    setError('')
    setNotice('')
  }

  if (screen !== 'dashboard' || !dashboard) {
    return (
      <main className="auth-shell">
        <section className="auth-card">
          <div className="auth-card-header">
            <div className="auth-logo"><img src="./logo.jpg" alt="ISGI" /></div>
            <h1>ISGI System</h1>
            <p>Portail étudiant et tuteur</p>
          </div>
          <div className="auth-card-content">
            <p className="eyebrow">ESPACE PERSONNEL SÉCURISÉ</p>
            <h2>{screen === 'register' ? 'Créer mon espace' : screen === 'activation' ? 'Activer mon compte' : screen === 'pending' ? 'Demande en vérification' : 'Bienvenue sur votre portail'}</h2>
            <p className="muted">
              {screen === 'pending'
                ? 'Le secrétariat vérifie votre identité et les matricules associés avant d’ouvrir l’accès.'
                : screen === 'activation'
                  ? 'Après vérification en personne, saisissez le code remis par l’administration.'
                  : 'Consultez vos résultats, vos présences, votre emploi du temps et les vidéos pédagogiques.'}
            </p>
            {error && <div className="alert error" role="alert">{error}</div>}
            {notice && <div className="alert success" role="status">{notice}</div>}

            {screen === 'login' && (
              <form className="form" onSubmit={handleLogin}>
                <label>Nom d’utilisateur<input autoComplete="username" required value={username} onChange={(event) => setUsername(event.target.value)} minLength={3} maxLength={30} /></label>
                <label>Mot de passe<input type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Connexion…' : <><LogIn size={17} /> Se connecter</>}</button>
                <p className="muted tiny">Mot de passe oublié ? Contactez le secrétariat pour vérifier votre identité et demander une réinitialisation.</p>
                <button className="text-button" type="button" onClick={() => { setScreen('register'); setError(''); setNotice('') }}>Créer un compte étudiant ou tuteur</button>
              </form>
            )}

            {screen === 'register' && (
              <form className="form" onSubmit={handleRegistration}>
                <label>Type de compte
                  <select value={accountType} onChange={(event) => setAccountType(event.target.value as 'etudiant' | 'tuteur')}>
                    <option value="etudiant">Étudiant</option>
                    <option value="tuteur">Tuteur / Parent</option>
                  </select>
                </label>
                <label>Nom complet<input required value={fullName} onChange={(event) => setFullName(event.target.value)} maxLength={160} /></label>
                <label>Nom d’utilisateur<input required autoComplete="username" value={username} onChange={(event) => setUsername(event.target.value)} minLength={3} maxLength={30} pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,29}" /></label>
                <label>Matricule{accountType === 'tuteur' ? 's des enfants (un par ligne)' : ''}<textarea required value={matriculesText} onChange={(event) => setMatriculesText(event.target.value)} rows={accountType === 'tuteur' ? 4 : 1} placeholder={accountType === 'tuteur' ? 'ISGI-2026-00001\nISGI-2026-00002' : 'Votre matricule'} /></label>
                <label>Mot de passe (10 caractères minimum)<input type="password" required autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} minLength={10} maxLength={72} /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Envoi…' : 'Envoyer la demande'}</button>
                <p className="muted tiny">Ne saisissez que les matricules des étudiants concernés. Un membre de l’administration vérifiera les liens avant validation.</p>
                <button className="text-button" type="button" onClick={() => { setScreen('login'); setError('') }}>Retour à la connexion</button>
              </form>
            )}

            {screen === 'activation' && (
              <form className="form" onSubmit={handleActivation}>
                <label>Code unique d’activation<input required value={activationCode} onChange={(event) => setActivationCode(event.target.value.toUpperCase())} minLength={12} maxLength={12} autoComplete="one-time-code" /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Vérification…' : <><ShieldCheck size={17} /> Activer mon compte</>}</button>
                <button className="text-button" type="button" onClick={logout}>Déconnexion</button>
              </form>
            )}

            {screen === 'pending' && (
              <div className="pending-box">
                <span className="pending-icon"><Clock3 size={22} /></span>
                <strong>Accès bloqué jusqu’à validation</strong>
                <p className="muted">Le code sera remis en personne après vérification. Il ne peut être utilisé qu’une seule fois.</p>
                <button className="secondary-button" onClick={logout}>Fermer la session</button>
              </div>
            )}
          </div>
        </section>
      </main>
    )
  }

  const tabs: Array<{ id: Section; label: string; icon: typeof Award }> = [
    { id: 'overview', label: 'Vue d’ensemble', icon: GraduationCap },
    { id: 'grades', label: 'Mes moyennes', icon: Award },
    { id: 'attendance', label: 'Mes présences', icon: CheckCircle2 },
    { id: 'payments', label: 'Mes paiements', icon: CreditCard },
    { id: 'timetable', label: 'Emploi du temps', icon: CalendarDays },
    { id: 'badge', label: 'Mon badge', icon: ShieldCheck },
    { id: 'videos', label: 'Vidéothèque', icon: Video },
  ]

  return (
    <div className="portal-shell">
      {sidebarOpen && <button className="sidebar-overlay" aria-label="Fermer le menu" onClick={() => setSidebarOpen(false)} />}
      <aside className={`sidebar${sidebarOpen ? ' sidebar-open' : ''}`}>
        <div className="side-brand"><span><img src="./logo.jpg" alt="" /></span><div><strong>ISGI System</strong><small>PORTAIL ÉTUDIANT</small></div><button className="close-menu" aria-label="Fermer le menu" onClick={() => setSidebarOpen(false)}><X size={20} /></button></div>
        <div className="side-person"><div className="avatar"><UserRound /></div><div><strong>{dashboard.account.full_name}</strong><small>{dashboard.account.account_type === 'tuteur' ? 'Espace tuteur' : 'Espace étudiant'}</small></div></div>
        <nav className="side-nav">
          {tabs.map((tab) => <button key={tab.id} className={section === tab.id ? 'active' : ''} onClick={() => { setSection(tab.id); setSidebarOpen(false) }}><tab.icon size={18} />{tab.label}</button>)}
        </nav>
        <button className="logout-button" onClick={logout}><LogOut size={18} />Déconnexion</button>
      </aside>

      <main className="portal-main">
        <header className="topbar">
          <div className="topbar-title"><button className="menu-button" aria-label="Ouvrir le menu" onClick={() => setSidebarOpen(true)}><Menu size={22} /></button><div><span className="eyebrow">ESPACE PERSONNEL</span><h1>{tabs.find((tab) => tab.id === section)?.label}</h1></div></div>
          <div className="topbar-actions">
            {dashboard.students.length > 1 && <label className="student-picker"><UsersRound size={17} /><select value={selectedStudent?.id || ''} onChange={(event) => setSelectedStudentId(event.target.value)}>{dashboard.students.map((student) => <option key={student.id} value={student.id}>{student.prenom} {student.nom}</option>)}</select></label>}
            <button className="theme-button" aria-label={theme === 'dark' ? 'Activer le thème clair' : 'Activer le thème sombre'} title="Changer le thème" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? <Sun size={19} /> : <Moon size={19} />}</button>
            <div className="topbar-user"><span>{dashboard.account.full_name.charAt(0).toUpperCase()}</span><div><strong>{dashboard.account.full_name}</strong><small>{dashboard.account.account_type === 'tuteur' ? 'Tuteur / Parent' : 'Étudiant'}</small></div></div>
          </div>
        </header>
        <div className="portal-content">
          {error && <div className="alert error" role="alert">{error}</div>}
          {selectedStudent ? renderSection(section, selectedStudent, grades, attendance, payments, timetable, dashboard) : <div className="empty-state">Aucun dossier étudiant n’est associé à ce compte.</div>}
        </div>
      </main>
    </div>
  )
}

function renderSection(
  section: Section,
  student: Student,
  grades: Grade[],
  attendance: Attendance[],
  payments: Payment[],
  timetable: TimetableItem[],
  dashboard: PortalDashboard,
) {
  const average = grades.length
    ? grades.reduce((total, item) => total + Number(item.note_finale || 0), 0) / grades.length
    : null
  const present = attendance.filter((item) => /present|présent/i.test(item.statut)).length
  const paidTotal = payments
    .filter((payment) => /réussi|reussi/i.test(payment.statut))
    .reduce((total, payment) => total + Number(payment.montant || 0), 0)
  const subjectName = (id: string) => dashboard.subjects.find((item) => item.id === id)?.nom || 'Matière'

  if (section === 'grades') return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">RÉSULTATS PUBLIÉS</span><h2>Notes et moyennes</h2></div><strong className="average">{average === null ? '—' : `${average.toFixed(2)}/20`}</strong></div><div className="data-list">{grades.map((grade) => <article className="data-row" key={grade.id}><div><strong>{subjectName(grade.matiere_id)}</strong><small>{grade.semestre} · {grade.annee_academique}</small></div><b>{Number(grade.note_finale).toFixed(2)}/20</b></article>)}{!grades.length && <Empty>Les résultats publiés apparaîtront ici.</Empty>}</div></section>

  if (section === 'attendance') return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">SUIVI SCOLAIRE</span><h2>Présences</h2></div><strong className="average">{attendance.length ? `${Math.round((present / attendance.length) * 100)}%` : '—'}</strong></div><div className="data-list">{attendance.map((item) => <article className="data-row" key={item.id}><div><strong>{new Date(item.date_seance).toLocaleDateString('fr-FR')}</strong><small>{subjectName(item.matiere_id || '')}</small></div><span className={`status ${/present|présent/i.test(item.statut) ? 'good' : 'warning'}`}>{item.statut}</span></article>)}{!attendance.length && <Empty>Aucun pointage n’est enregistré pour le moment.</Empty>}</div></section>

  if (section === 'payments') return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">HISTORIQUE DE SCOLARITÉ</span><h2>Paiements</h2></div><strong className="average">{paidTotal.toLocaleString('fr-FR')} FCFA</strong></div><div className="data-list">{payments.map((payment) => <article className="data-row" key={payment.id}><div><strong>{payment.type_paiement}</strong><small>{payment.created_at ? new Date(payment.created_at).toLocaleDateString('fr-FR') : 'Date non renseignée'} · {payment.mode_paiement || 'Mode non renseigné'}</small></div><div className="text-right"><b>{Number(payment.montant || 0).toLocaleString('fr-FR')} FCFA</b><small>{payment.statut}</small></div></article>)}{!payments.length && <Empty>Aucun paiement n’est enregistré pour ce dossier.</Empty>}</div></section>

  if (section === 'timetable') return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">COURS PUBLIÉS</span><h2>Emploi du temps</h2></div></div><div className="data-list">{timetable.map((item) => <article className="data-row" key={item.id}><div><strong>{item.jour_semaine} · {subjectName(item.matiere_id)}</strong><small>{item.heure_debut} – {item.heure_fin}</small></div><span className="room">{item.salle}</span></article>)}{!timetable.length && <Empty>L’emploi du temps publié de la classe apparaîtra ici.</Empty>}</div></section>

  if (section === 'badge') {
    const qrData = String((student as Student & { qr_code_data?: string }).qr_code_data || `ETUDIANT:${student.matricule}|NOM:${student.nom}|PRENOM:${student.prenom}`)
    return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">CARTE ÉTUDIANT</span><h2>Mon badge ISGI</h2></div><button className="secondary-button no-print" onClick={() => window.print()}>Imprimer le badge</button></div><article className="id-card"><div className="card-header"><GraduationCap /><span>INSTITUT SUPÉRIEUR ISGI</span></div><div className="card-body"><div className="student-photo">{student.photo_url ? <img src={student.photo_url} alt={`Photo de ${student.prenom} ${student.nom}`} /> : <UserRound size={45} />}</div><div className="card-details"><span>CARTE D’ÉTUDIANT</span><h3>{student.prenom} {student.nom}</h3><p>{student.filiere || 'Filière à confirmer'} · {student.niveau || ''}</p><b>{student.matricule}</b></div><QRCodeSVG value={qrData} size={92} level="M" /></div><div className="card-footer">{student.annee_academique || 'Année académique'} <span>{student.classe || 'Étudiant ISGI'}</span></div></article></section>
  }

  if (section === 'videos') return <section className="content-section"><div className="section-heading"><div><span className="eyebrow">CONTENUS PÉDAGOGIQUES</span><h2>Vidéothèque</h2></div></div><div className="video-grid">{dashboard.videos.map((video) => <article className="video-card" key={video.id}>{video.url_miniature && <img className="video-thumb" src={video.url_miniature} alt="" />}<div className="video-content"><span className="video-tag">{video.visibilite === 'classe' ? video.classe_nom || 'Ma classe' : 'Publique'}</span><h3>{video.titre}</h3>{video.description && <p>{video.description}</p>}{video.url_video && <video controls preload="none" src={video.url_video} />}</div></article>)}{!dashboard.videos.length && <Empty>Il n’y a pas encore de vidéo publiée pour votre compte.</Empty>}</div></section>

  return (
    <section className="content-section dashboard-section">
      <div className="dashboard-heading">
        <div>
          <span className="eyebrow">{dashboard.account.account_type === 'tuteur' ? 'ESPACE FAMILLE' : 'ESPACE ÉTUDIANT'}</span>
          <h2>Bonjour {student.prenom},</h2>
          <p>{student.matricule} · {student.filiere || 'Filière'} {student.niveau || ''}</p>
        </div>
        <div className="welcome-icon"><GraduationCap size={28} /></div>
      </div>

      <div className="student-summary">
        <article className="metric-card">
          <span><Award size={18} /> Moyenne publiée</span>
          <strong>{average === null ? '—' : `${average.toFixed(2)}/20`}</strong>
          <small>{grades.length} note{grades.length === 1 ? '' : 's'} publiée{grades.length === 1 ? '' : 's'}</small>
        </article>
        <article className="metric-card">
          <span><CheckCircle2 size={18} /> Présences</span>
          <strong>{attendance.length ? `${Math.round((present / attendance.length) * 100)}%` : '—'}</strong>
          <small>{present} présence{present === 1 ? '' : 's'} sur {attendance.length} pointage{attendance.length === 1 ? '' : 's'}</small>
        </article>
        <article className="metric-card">
          <span><CreditCard size={18} /> Paiements réussis</span>
          <strong>{paidTotal.toLocaleString('fr-FR')} FCFA</strong>
          <small>{payments.filter((item) => /réussi|reussi/i.test(item.statut)).length} transaction(s) confirmée(s)</small>
        </article>
        <article className="metric-card">
          <span><BookOpen size={18} /> Vidéos accessibles</span>
          <strong>{dashboard.videos.length}</strong>
          <small>Publiques et de votre classe</small>
        </article>
      </div>

      <div className="overview-grid">
        <article className="panel">
          <div className="panel-title"><h3>Dernières notes</h3><Award size={18} /></div>
          {grades.slice(0, 4).map((grade) => <div className="compact-row" key={grade.id}><span>{subjectName(grade.matiere_id)}</span><strong>{Number(grade.note_finale).toFixed(2)}/20</strong></div>)}
          {!grades.length && <Empty>Aucune note publiée.</Empty>}
        </article>
        <article className="panel">
          <div className="panel-title"><h3>Prochains cours</h3><CalendarDays size={18} /></div>
          {timetable.slice(0, 4).map((item) => <div className="compact-row" key={item.id}><span>{item.jour_semaine} · {subjectName(item.matiere_id)}</span><strong>{item.heure_debut}</strong></div>)}
          {!timetable.length && <Empty>Aucun cours publié.</Empty>}
        </article>
      </div>
    </section>
  )
}

function Empty({ children }: { children: string }) {
  return <div className="empty-state">{children}</div>
}
