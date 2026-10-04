-- ==============================================================================
-- SCRIPT DE MIGRATION : CONTRÔLE DE PUBLICATION PAR LE DAC
-- Emplois du temps, Résultats de notes & moyennes, Calendriers académiques
-- ==============================================================================

-- 1. Ajout de la colonne 'publie' dans la table 'emplois_du_temps'
ALTER TABLE public.emplois_du_temps 
ADD COLUMN IF NOT EXISTS publie BOOLEAN NOT NULL DEFAULT false;

-- Index pour accélérer le filtrage des cours publiés
CREATE INDEX IF NOT EXISTS idx_emplois_du_temps_publie ON public.emplois_du_temps(publie);
CREATE INDEX IF NOT EXISTS idx_emplois_du_temps_classe_semestre ON public.emplois_du_temps(classe_id, semestre);

-- 2. Ajout de la colonne 'publie' dans la table 'notes'
ALTER TABLE public.notes 
ADD COLUMN IF NOT EXISTS publie BOOLEAN NOT NULL DEFAULT false;

-- Index pour filtrer rapidement les notes publiées par classe et semestre
CREATE INDEX IF NOT EXISTS idx_notes_publie ON public.notes(publie);
CREATE INDEX IF NOT EXISTS idx_notes_classe_semestre ON public.notes(classe_id, semestre);

-- 3. S'assurer que 'publie' existe sur 'calendriers_academiques'
ALTER TABLE public.calendriers_academiques 
ADD COLUMN IF NOT EXISTS publie BOOLEAN NOT NULL DEFAULT false;

CREATE INDEX IF NOT EXISTS idx_calendriers_publie ON public.calendriers_academiques(publie);

-- 4. Table centrale des Publications Académiques officielles du DAC
-- Permet de tracer et verrouiller la publication par classe, semestre et type
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

-- Index pour vérification instantanée de publication
CREATE INDEX IF NOT EXISTS idx_publications_academiques_lookup 
ON public.publications_academiques(type, classe_id, semestre, publie);

-- Activer RLS sur la table des publications
ALTER TABLE public.publications_academiques ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS "Allow all publications_academiques" ON public.publications_academiques;
CREATE POLICY "Allow all publications_academiques" ON public.publications_academiques FOR ALL USING (true) WITH CHECK (true);
