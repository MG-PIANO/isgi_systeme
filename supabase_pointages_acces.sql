-- ==============================================================================
-- SCHEMA SUPABASE : TABLE POINTAGES_ACCES (SURVEILLANCE ENTRÉES / SORTIES)
-- ==============================================================================
-- Règle métier :
-- 1er scan du jour    = Heure d'arrivée (Ponctualité : À l'heure ou Retard)
-- Scans intermédiaires = Nombre de tours / sorties temporaires
-- Dernier scan du jour = Heure de départ définitive
-- ==============================================================================

CREATE TABLE IF NOT EXISTS public.pointages_acces (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    etudiant_id UUID,
    matricule TEXT NOT NULL,
    nom TEXT NOT NULL,
    prenom TEXT NOT NULL,
    classe_nom TEXT,
    filiere TEXT,
    photo_url TEXT,
    date_jour DATE NOT NULL DEFAULT CURRENT_DATE,
    heure_arrivee TIME NOT NULL,
    statut_arrivee TEXT NOT NULL DEFAULT 'a_l_heure' CHECK (statut_arrivee IN ('a_l_heure', 'en_retard')),
    nombre_tours INTEGER NOT NULL DEFAULT 0,
    heure_depart TIME,
    statut_actuel TEXT NOT NULL DEFAULT 'sur_site' CHECK (statut_actuel IN ('sur_site', 'sorti')),
    surveillant_id UUID,
    surveillant_nom TEXT,
    appareil_dernier_scan TEXT DEFAULT 'mobile' CHECK (appareil_dernier_scan IN ('mobile', 'pc')),
    historique_scans JSONB DEFAULT '[]'::jsonb,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    CONSTRAINT unique_pointage_etudiant_jour UNIQUE (matricule, date_jour)
);

-- Index pour optimiser les requêtes du tableau de bord journalier
CREATE INDEX IF NOT EXISTS idx_pointages_date_jour ON public.pointages_acces(date_jour);
CREATE INDEX IF NOT EXISTS idx_pointages_matricule ON public.pointages_acces(matricule);
CREATE INDEX IF NOT EXISTS idx_pointages_statut_actuel ON public.pointages_acces(statut_actuel);

-- Politiques RLS (Row Level Security)
ALTER TABLE public.pointages_acces ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Lecture publique pointages_acces" ON public.pointages_acces;
CREATE POLICY "Lecture publique pointages_acces" 
ON public.pointages_acces FOR SELECT USING (true);

DROP POLICY IF EXISTS "Insertion pointages_acces" ON public.pointages_acces;
CREATE POLICY "Insertion pointages_acces" 
ON public.pointages_acces FOR INSERT WITH CHECK (true);

DROP POLICY IF EXISTS "Mise a jour pointages_acces" ON public.pointages_acces;
CREATE POLICY "Mise a jour pointages_acces" 
ON public.pointages_acces FOR UPDATE USING (true) WITH CHECK (true);

-- Enable Realtime pour la synchronisation instantanée entre Mobile et Bureau
ALTER PUBLICATION supabase_realtime ADD TABLE public.pointages_acces;
