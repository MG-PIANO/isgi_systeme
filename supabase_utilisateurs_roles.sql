-- ==============================================================================
-- CONFIGURATION SUPABASE : TABLE UTILISATEURS AVEC LES 10 RÔLES OFFICIELS
-- ==============================================================================
-- 1. admin_principal   (Admin Principal)
-- 2. dac               (Directeur des Affaires Académiques)
-- 3. comptable         (Comptable / Gestionnaire Principal)
-- 4. secretariat       (Secrétariat / Scolarité)
-- 5. secretaire_dac    (Secrétaire du DAC)
-- 6. surveillant       (Surveillant Général)
-- 7. informaticien     (Informaticiens / Support SI)
-- 8. etudiant          (Étudiants)
-- 9. professeur        (Professeurs / Enseignants)
-- 10. tuteur           (Tuteurs / Parents d'élèves)
-- ==============================================================================

-- 1. Création de la table utilisateurs si elle n'existe pas encore
CREATE TABLE IF NOT EXISTS public.utilisateurs (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    email TEXT UNIQUE NOT NULL,
    nom_complet TEXT NOT NULL,
    role TEXT NOT NULL,
    statut TEXT DEFAULT 'actif' CHECK (statut IN ('actif', 'bloque', 'en_attente')),
    date_creation TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    derniere_connexion TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. Règle de validation (CHECK constraint) sur les 10 rôles exacts
ALTER TABLE public.utilisateurs 
DROP CONSTRAINT IF EXISTS check_utilisateurs_roles;

ALTER TABLE public.utilisateurs 
ADD CONSTRAINT check_utilisateurs_roles 
CHECK (role IN (
    'admin_principal',
    'admin',              -- alias rétro-compatible
    'dac',
    'comptable',
    'secretariat',
    'secretaire_dac',
    'secretaire du dac',  -- alias rétro-compatible
    'surveillant',
    'informaticien',
    'informaticiens',     -- alias pluriel
    'etudiant',
    'etudiants',          -- alias pluriel
    'professeur',
    'tuteur'
));

-- 3. Politiques de Sécurité RLS (Row Level Security)
ALTER TABLE public.utilisateurs ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Lecture publique pour les utilisateurs connectes" ON public.utilisateurs;
CREATE POLICY "Lecture publique pour les utilisateurs connectes" 
ON public.utilisateurs FOR SELECT USING (true);

DROP POLICY IF EXISTS "Insertion de compte utilisateur" ON public.utilisateurs;
CREATE POLICY "Insertion de compte utilisateur" 
ON public.utilisateurs FOR INSERT WITH CHECK (true);

DROP POLICY IF EXISTS "Mise a jour utilisateur" ON public.utilisateurs;
CREATE POLICY "Mise a jour utilisateur" 
ON public.utilisateurs FOR UPDATE USING (true) WITH CHECK (true);

-- 4. Index de performance
CREATE INDEX IF NOT EXISTS idx_utilisateurs_email ON public.utilisateurs(email);
CREATE INDEX IF NOT EXISTS idx_utilisateurs_role ON public.utilisateurs(role);

-- ==============================================================================
-- FIN DU SCRIPT
-- ==============================================================================
