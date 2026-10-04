-- ==============================================================================
-- SCHÉMA COMPLET SUPABASE — ISGI (Institut Supérieur de Gestion et d'Ingénierie)
-- DIRECTION DES AFFAIRES ACADÉMIQUES (DAC)
-- ==============================================================================
-- Instructions :
-- 1. Connectez-vous sur votre tableau de bord Supabase (https://supabase.com/dashboard)
-- 2. Allez dans le menu "SQL Editor" (icône terminal / code à gauche)
-- 3. Cliquez sur "New query", collez l'intégralité de ce script et cliquez sur "Run" (Exécuter)
-- ==============================================================================

-- 1. Table des Classes
CREATE TABLE IF NOT EXISTS public.classes (
    id TEXT PRIMARY KEY,
    nom TEXT NOT NULL,
    code TEXT NOT NULL,
    niveau TEXT NOT NULL,
    filiere TEXT NOT NULL,
    annee_academique TEXT DEFAULT '2025-2026',
    capacite_max INTEGER DEFAULT 40,
    salle_principale TEXT,
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. Table du Catalogue des Matières
CREATE TABLE IF NOT EXISTS public.matieres (
    id TEXT PRIMARY KEY,
    code TEXT NOT NULL,
    nom TEXT NOT NULL,
    credits NUMERIC(4,1) DEFAULT 3.0,
    coefficient NUMERIC(4,2) DEFAULT 2.0,
    filiere_cible TEXT,
    niveau_cible TEXT,
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 3. Table des Affectations d'Étudiants dans les Classes
CREATE TABLE IF NOT EXISTS public.classe_etudiants (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    etudiant_id TEXT NOT NULL, -- Compatible avec id UUID ou matricule de l'étudiant
    annee_academique TEXT DEFAULT '2025-2026',
    date_affectation TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    statut TEXT DEFAULT 'actif',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 4. Table des Matières et Enseignants associés par Classe
CREATE TABLE IF NOT EXISTS public.classe_matieres (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    enseignant_id TEXT, -- Compatible avec id UUID ou matricule de l'enseignant
    semestre TEXT DEFAULT 'S1',
    credits NUMERIC(4,1) DEFAULT 3.0,
    coefficient NUMERIC(4,2) DEFAULT 2.0,
    volume_horaire INTEGER,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 5. Table des Notes et Évaluations
CREATE TABLE IF NOT EXISTS public.notes (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    etudiant_id TEXT NOT NULL, -- Compatible avec id UUID ou matricule de l'étudiant
    note_cc NUMERIC(4,2),
    note_examen NUMERIC(4,2),
    note_rattrapage NUMERIC(4,2),
    note_finale NUMERIC(4,2) NOT NULL,
    semestre TEXT DEFAULT 'S1',
    annee_academique TEXT DEFAULT '2025-2026',
    saisi_par TEXT DEFAULT 'DAC',
    publie BOOLEAN NOT NULL DEFAULT false,
    observations TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 6. Table du Suivi des Présences
CREATE TABLE IF NOT EXISTS public.presences_etudiants (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE SET NULL,
    etudiant_id TEXT NOT NULL, -- Compatible avec id UUID ou matricule de l'étudiant
    date_seance DATE NOT NULL,
    statut TEXT NOT NULL DEFAULT 'present',
    motif TEXT,
    enregistre_par TEXT DEFAULT 'DAC',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 7. Table des Salles (Amphis, Labos, Salles de cours)
CREATE TABLE IF NOT EXISTS public.salles (
    id TEXT PRIMARY KEY,
    nom TEXT NOT NULL,
    code TEXT NOT NULL,
    capacite INTEGER NOT NULL DEFAULT 40,
    type_salle TEXT NOT NULL DEFAULT 'classe',
    batiment TEXT,
    etage TEXT,
    equipements JSONB DEFAULT '[]'::jsonb,
    statut TEXT NOT NULL DEFAULT 'disponible',
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 8. Table du Calendrier Académique
CREATE TABLE IF NOT EXISTS public.calendriers_academiques (
    id TEXT PRIMARY KEY,
    annee_academique TEXT NOT NULL DEFAULT '2025-2026',
    semestre TEXT NOT NULL DEFAULT 'S1',
    type_rentree TEXT DEFAULT 'Octobre (Principale)',
    date_debut_cours DATE,
    date_fin_cours DATE,
    date_debut_dst DATE,
    date_fin_dst DATE,
    date_debut_recherche DATE,
    date_fin_recherche DATE,
    date_debut_conge_etude DATE,
    date_fin_conge_etude DATE,
    date_debut_examens DATE,
    date_fin_examens DATE,
    date_reprise_cours DATE,
    date_debut_stage DATE,
    date_fin_stage DATE,
    date_debut_rattrapage DATE,
    date_fin_rattrapage DATE,
    statut TEXT NOT NULL DEFAULT 'planifie',
    publie BOOLEAN NOT NULL DEFAULT true,
    observations TEXT,
    evenements_speciaux JSONB DEFAULT '[]'::jsonb,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 9. Table des Emplois du Temps (Planning Hebdomadaire)
CREATE TABLE IF NOT EXISTS public.emplois_du_temps (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    enseignant_id TEXT, -- Compatible id UUID ou matricule personnel
    jour_semaine TEXT NOT NULL,
    heure_debut TEXT NOT NULL,
    heure_fin TEXT NOT NULL,
    salle TEXT NOT NULL,
    type_cours TEXT NOT NULL DEFAULT 'CM',
    semestre TEXT NOT NULL DEFAULT 'S1',
    annee_academique TEXT NOT NULL DEFAULT '2025-2026',
    statut TEXT NOT NULL DEFAULT 'actif',
    publie BOOLEAN NOT NULL DEFAULT false,
    couleur TEXT DEFAULT '#0284c7',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 10. Table des Publications Académiques officielles du DAC (Emplois du temps & Notes)
CREATE TABLE IF NOT EXISTS public.publications_academiques (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL, -- 'emploi_du_temps', 'notes_moyennes', 'calendrier'
    classe_id TEXT,
    semestre TEXT,
    annee_academique TEXT DEFAULT '2025-2026',
    publie BOOLEAN NOT NULL DEFAULT false,
    publie_par TEXT DEFAULT 'DAC',
    date_publication TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ==============================================================================
-- ACTIVATION DES POLITIQUES ROW LEVEL SECURITY (RLS)
-- ==============================================================================
ALTER TABLE public.classes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.matieres ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.classe_etudiants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.classe_matieres ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.presences_etudiants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.salles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.calendriers_academiques ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.emplois_du_temps ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.publications_academiques ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow all publications_academiques" ON public.publications_academiques;
CREATE POLICY "Allow all publications_academiques" ON public.publications_academiques FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all classes" ON public.classes;
CREATE POLICY "Allow all classes" ON public.classes FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all matieres" ON public.matieres;
CREATE POLICY "Allow all matieres" ON public.matieres FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all classe_etudiants" ON public.classe_etudiants;
CREATE POLICY "Allow all classe_etudiants" ON public.classe_etudiants FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all classe_matieres" ON public.classe_matieres;
CREATE POLICY "Allow all classe_matieres" ON public.classe_matieres FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all notes" ON public.notes;
CREATE POLICY "Allow all notes" ON public.notes FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all presences_etudiants" ON public.presences_etudiants;
CREATE POLICY "Allow all presences_etudiants" ON public.presences_etudiants FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all salles" ON public.salles;
CREATE POLICY "Allow all salles" ON public.salles FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all calendriers_academiques" ON public.calendriers_academiques;
CREATE POLICY "Allow all calendriers_academiques" ON public.calendriers_academiques FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all emplois_du_temps" ON public.emplois_du_temps;
CREATE POLICY "Allow all emplois_du_temps" ON public.emplois_du_temps FOR ALL USING (true) WITH CHECK (true);

-- ==============================================================================
-- DONNÉES INITIALES : SALLES DE L'ISGI
-- ==============================================================================
INSERT INTO public.salles (id, nom, code, capacite, type_salle, batiment, etage, equipements, statut, description)
VALUES 
('sal_amphi_a', 'Amphi A - Campus 1', 'AMP-A', 150, 'amphi', 'Campus 1 - Bâtiment Principal', 'RDC', '["Sonorisation complète", "Vidéoprojecteur HD", "Climatisation", "Wi-Fi haut débit", "Microphones sans fil"]'::jsonb, 'disponible', 'Grand amphithéâtre pour cours magistraux, conférences et soutenances'),
('sal_amphi_b', 'Amphi B - Campus 1', 'AMP-B', 100, 'amphi', 'Campus 1 - Bâtiment Principal', '1er Étage', '["Vidéoprojecteur", "Climatisation", "Wi-Fi", "Tableau blanc"]'::jsonb, 'disponible', 'Deuxième amphithéâtre pour promotions intermédiaires'),
('sal_labo_info1', 'Labo Info 1 (Développement)', 'LAB-01', 40, 'labo_info', 'Bâtiment Informatique & Technologies', '1er Étage', '["40 Postes PC Core i7", "Connexion Fibre Gigabit", "Vidéoprojecteur", "Climatisation", "Serveurs de dev"]'::jsonb, 'disponible', 'Laboratoire dédié au développement web, logiciel et algorithmique'),
('sal_labo_info2', 'Labo Info 2 (Bases de données)', 'LAB-02', 35, 'labo_info', 'Bâtiment Informatique & Technologies', '1er Étage', '["35 Postes PC", "SGBD MySQL & PostgreSQL configurés", "Climatisation", "Vidéoprojecteur"]'::jsonb, 'disponible', 'Laboratoire pour modélisation, requêtage et administration de bases de données'),
('sal_labo_reseaux', 'Labo Réseaux & Télécoms', 'LAB-RES', 30, 'labo_reseau', 'Bâtiment Informatique & Technologies', 'RDC', '["Racks d''interconnexion", "Routeurs & Switchs Cisco", "Bancs de câblage Ethernet/Fibre", "Climatisation"]'::jsonb, 'disponible', 'Laboratoire spécialisé pour TP réseau, architecture et sécurité'),
('sal_101', 'Salle 101 (Cours Magistraux)', 'S-101', 50, 'classe', 'Bâtiment Pédagogique A', '1er Étage', '["Tableau blanc magnétique", "Vidéoprojecteur", "Climatisation"]'::jsonb, 'disponible', 'Grande salle de cours magistraux et tronc commun'),
('sal_102', 'Salle 102 (Travaux Dirigés)', 'S-102', 45, 'classe', 'Bâtiment Pédagogique A', '1er Étage', '["Tableau blanc", "Climatisation"]'::jsonb, 'disponible', 'Salle modulable pour travaux dirigés et cours de langues'),
('sal_103', 'Salle 103 (Gestion & Économie)', 'S-103', 45, 'classe', 'Bâtiment Pédagogique A', '1er Étage', '["Tableau blanc", "Climatisation", "Wi-Fi"]'::jsonb, 'disponible', 'Salle de cours pour filières de gestion et comptabilité'),
('sal_201', 'Salle 201 (Langues & Séminaires)', 'S-201', 35, 'classe', 'Bâtiment Pédagogique B', '2ème Étage', '["Écran tactile interactif", "Casques d''écoute", "Climatisation"]'::jsonb, 'disponible', 'Salle multimédia pour anglais technique et ateliers')
ON CONFLICT (id) DO NOTHING;

-- ==============================================================================
-- DONNÉES INITIALES : CALENDRIER ACADÉMIQUE ISGI
-- ==============================================================================
INSERT INTO public.calendriers_academiques (
    id, annee_academique, semestre, type_rentree,
    date_debut_cours, date_fin_cours,
    date_debut_dst, date_fin_dst,
    date_debut_recherche, date_fin_recherche,
    date_debut_conge_etude, date_fin_conge_etude,
    date_debut_examens, date_fin_examens,
    date_reprise_cours, date_debut_stage, date_fin_stage,
    date_debut_rattrapage, date_fin_rattrapage,
    statut, publie, observations, evenements_speciaux
)
VALUES
(
    'cal_2025_2026_s1', '2025-2026', 'S1', 'Octobre (Principale)',
    '2025-10-06', '2026-01-30',
    '2025-11-17', '2025-11-22',
    '2025-12-08', '2025-12-13',
    '2026-02-02', '2026-02-07',
    '2026-02-09', '2026-02-14',
    '2026-03-02', '2026-07-01', '2026-08-31',
    '2026-02-23', '2026-02-28',
    'en_cours', true,
    'Calendrier officiel validé par la Direction des Affaires Académiques pour le Semestre 1',
    '[{"id":"ev_1","titre":"Journée d''Intégration des Nouveaux","date":"2025-10-18","type":"culturel","description":"Accueil officiel des étudiants de L1"},{"id":"ev_2","titre":"Congés de Noël & Nouvel An","date":"2025-12-22","type":"ferie","description":"Suspension administrative et pédagogique"}]'::jsonb
),
(
    'cal_2025_2026_s2', '2025-2026', 'S2', 'Mars (Second semestre)',
    '2026-03-02', '2026-06-19',
    '2026-04-13', '2026-04-18',
    '2026-05-04', '2026-05-09',
    '2026-06-22', '2026-06-27',
    '2026-06-29', '2026-07-04',
    '2026-10-05', '2026-07-06', '2026-09-15',
    '2026-07-13', '2026-07-18',
    'planifie', true,
    'Calendrier prévisionnel du second semestre et soutenances des mémoires',
    '[{"id":"ev_3","titre":"Hackathon Annuel ISGI","date":"2026-05-20","type":"pedagogique","description":"Compétition inter-filières de développement et gestion"}]'::jsonb
)
ON CONFLICT (id) DO NOTHING;

-- ==============================================================================
-- TABLES NOTIFICATIONS & SUJETS D'EXAMENS / PROJETS
-- ==============================================================================
CREATE TABLE IF NOT EXISTS public.notifications (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    type TEXT NOT NULL,
    titre TEXT NOT NULL,
    description TEXT NOT NULL,
    auteur_nom TEXT,
    auteur_role TEXT,
    lien_tab TEXT,
    lien_id TEXT,
    lu BOOLEAN NOT NULL DEFAULT false,
    donnees_extra JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.sujets_evaluations (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    classe_nom TEXT NOT NULL,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    matiere_nom TEXT NOT NULL,
    enseignant_id TEXT, -- Compatible id UUID ou matricule personnel
    enseignant_nom TEXT NOT NULL,
    type_evaluation TEXT NOT NULL DEFAULT 'Examen Final',
    titre TEXT NOT NULL,
    description TEXT,
    date_limite DATE NOT NULL,
    modele_nom TEXT,
    modele_url TEXT,
    modele_taille BIGINT,
    sujet_nom TEXT,
    sujet_url TEXT,
    sujet_taille BIGINT,
    date_depot TIMESTAMP WITH TIME ZONE,
    statut TEXT NOT NULL DEFAULT 'en_attente_depot',
    commentaires_dac TEXT,
    created_by TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sujets_evaluations ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow all notifications" ON public.notifications;
CREATE POLICY "Allow all notifications" ON public.notifications FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all sujets_evaluations" ON public.sujets_evaluations;
CREATE POLICY "Allow all sujets_evaluations" ON public.sujets_evaluations FOR ALL USING (true) WITH CHECK (true);

-- ==============================================================================
-- TABLES TRANSMISSION & VALIDATION DES NOTES (ENSEIGNANTS <-> DAC)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS public.soumissions_notes (
    id TEXT PRIMARY KEY,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    classe_nom TEXT NOT NULL,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    matiere_nom TEXT NOT NULL,
    matiere_code TEXT,
    enseignant_id TEXT,
    enseignant_nom TEXT NOT NULL,
    semestre TEXT NOT NULL,
    annee_academique TEXT NOT NULL,
    type_epreuve TEXT NOT NULL DEFAULT 'TOUTES', -- 'TOUTES', 'DST', 'DEVOIR_RECHERCHE', 'SESSION', 'RATTRAPAGE'
    statut TEXT NOT NULL DEFAULT 'EN_ATTENTE', -- 'EN_ATTENTE', 'VALIDE', 'REJETE'
    nombre_etudiants INTEGER NOT NULL DEFAULT 0,
    moyenne_classe NUMERIC(4,2),
    commentaires_enseignant TEXT,
    remarques_dac TEXT,
    notes JSONB DEFAULT '[]'::jsonb,
    date_soumission TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    date_validation TIMESTAMP WITH TIME ZONE,
    valide_par TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.notes_provisoires (
    id TEXT PRIMARY KEY,
    soumission_id TEXT REFERENCES public.soumissions_notes(id) ON DELETE CASCADE,
    classe_id TEXT REFERENCES public.classes(id) ON DELETE CASCADE,
    matiere_id TEXT REFERENCES public.matieres(id) ON DELETE CASCADE,
    etudiant_id TEXT,
    matricule TEXT,
    nom TEXT,
    prenom TEXT,
    note_cc NUMERIC(4,2),
    note_examen NUMERIC(4,2),
    note_finale NUMERIC(4,2),
    note_rattrapage NUMERIC(4,2),
    mg_estimee NUMERIC(4,2),
    semestre TEXT NOT NULL,
    annee_academique TEXT,
    observations TEXT
);

ALTER TABLE public.soumissions_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notes_provisoires ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow all soumissions_notes" ON public.soumissions_notes;
CREATE POLICY "Allow all soumissions_notes" ON public.soumissions_notes FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all notes_provisoires" ON public.notes_provisoires;
CREATE POLICY "Allow all notes_provisoires" ON public.notes_provisoires FOR ALL USING (true) WITH CHECK (true);


