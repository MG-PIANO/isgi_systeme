-- ═══════════════════════════════════════════════════════════════════
--  ISGI Système — Vidéothèque avec Contrôle d'Accès par CLASSE
--  Exécuter dans Supabase SQL Editor
--  Si le portail étudiant est installé, réexécuter ensuite
--  supabase_portal_accounts.sql pour restaurer ses politiques RLS strictes.
-- ═══════════════════════════════════════════════════════════════════

-- ─── 0. Assurer les colonnes dans public.utilisateurs ─────────────
-- OBLIGATOIRE EN TOUT PREMIER : les politiques RLS plus bas en dépendent
ALTER TABLE IF EXISTS public.utilisateurs
  ADD COLUMN IF NOT EXISTS classe_id  TEXT,
  ADD COLUMN IF NOT EXISTS classe_nom TEXT,
  ADD COLUMN IF NOT EXISTS classe     TEXT,
  ADD COLUMN IF NOT EXISTS filiere    TEXT,
  ADD COLUMN IF NOT EXISTS niveau     TEXT;

-- Optionnel : table etudiants si elle existe
ALTER TABLE IF EXISTS public.etudiants
  ADD COLUMN IF NOT EXISTS classe_id  TEXT,
  ADD COLUMN IF NOT EXISTS classe_nom TEXT;

-- ─── 1. Table principale des vidéos ──────────────────────────────
CREATE TABLE IF NOT EXISTS public.videos (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  titre           TEXT NOT NULL,
  description     TEXT,
  categorie       TEXT NOT NULL DEFAULT 'cours',  -- cours | tutoriel | presentation | autre

  -- ── Contrôle d'accès ──────────────────────────────────────────
  -- visibilite = 'publique' → tout le monde connecté voit
  -- visibilite = 'classe'   → uniquement la classe ciblée (par classe_id / classe_nom)
  visibilite      TEXT NOT NULL DEFAULT 'publique'
                  CHECK (visibilite IN ('publique', 'classe')),

  -- Classe cible (obligatoire si visibilite = 'classe', NULL si publique)
  classe_id       TEXT,   -- ex: "L1_IG", UUID ou ID unique de la classe
  classe_nom      TEXT,   -- ex: "Licence 1 — Informatique de Gestion (L1-IG)"
  classe_filiere  TEXT,   -- ex: "Informatique de Gestion"
  classe_niveau   TEXT,   -- ex: "Licence 1"
  -- La classe est l'entité structurante : chaque vidéo ciblée appartient à une classe précise

  -- ── Fichier vidéo ─────────────────────────────────────────────
  url_video       TEXT,       -- URL Supabase Storage
  url_miniature   TEXT,       -- URL miniature/thumbnail
  duree_secondes  INTEGER,    -- Durée en secondes
  taille_octets   BIGINT,     -- Taille fichier

  -- ── Métadonnées ───────────────────────────────────────────────
  publie_par      UUID REFERENCES auth.users(id) ON DELETE SET NULL,
  publie_par_nom  TEXT,       -- Nom lisible du publieur (informaticien)
  statut          TEXT NOT NULL DEFAULT 'publie'
                  CHECK (statut IN ('brouillon', 'publie', 'archive')),
  vues            INTEGER NOT NULL DEFAULT 0,

  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Si la table existait déjà, ajouter les nouvelles colonnes de classe
ALTER TABLE public.videos
  ADD COLUMN IF NOT EXISTS classe_id  TEXT,
  ADD COLUMN IF NOT EXISTS classe_nom TEXT;

-- ─── 2. Table historique des vues ─────────────────────────────────
CREATE TABLE IF NOT EXISTS public.video_vues (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id   UUID NOT NULL REFERENCES public.videos(id) ON DELETE CASCADE,
  user_id    UUID REFERENCES auth.users(id) ON DELETE SET NULL,
  watched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  duree_vue  INTEGER  -- secondes regardées
);

-- ─── 3. Index ──────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_videos_visibilite    ON public.videos(visibilite);
CREATE INDEX IF NOT EXISTS idx_videos_classe_id     ON public.videos(classe_id);
CREATE INDEX IF NOT EXISTS idx_videos_classe_nom    ON public.videos(classe_nom);
CREATE INDEX IF NOT EXISTS idx_videos_classe        ON public.videos(classe_filiere, classe_niveau);
CREATE INDEX IF NOT EXISTS idx_videos_statut        ON public.videos(statut);
CREATE INDEX IF NOT EXISTS idx_videos_categorie     ON public.videos(categorie);
CREATE INDEX IF NOT EXISTS idx_videos_created       ON public.videos(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_video_vues_video     ON public.video_vues(video_id);

-- ─── 4. Trigger updated_at ─────────────────────────────────────────
CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS videos_updated_at ON public.videos;
CREATE TRIGGER videos_updated_at
  BEFORE UPDATE ON public.videos
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ─── 5. Trigger compteur de vues ───────────────────────────────────
CREATE OR REPLACE FUNCTION public.increment_video_vues()
RETURNS TRIGGER AS $$
BEGIN
  UPDATE public.videos SET vues = vues + 1 WHERE id = NEW.video_id;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS increment_vues_trigger ON public.video_vues;
CREATE TRIGGER increment_vues_trigger
  AFTER INSERT ON public.video_vues
  FOR EACH ROW EXECUTE FUNCTION public.increment_video_vues();

-- ─── 6. Enable Realtime ────────────────────────────────────────────
ALTER TABLE public.videos     REPLICA IDENTITY FULL;
ALTER TABLE public.video_vues REPLICA IDENTITY FULL;

-- ─── 7. RLS (Row Level Security) ───────────────────────────────────
ALTER TABLE public.videos     ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.video_vues ENABLE ROW LEVEL SECURITY;

-- Supprimer anciennes policies
DROP POLICY IF EXISTS "videos_select_publiques"    ON public.videos;
DROP POLICY IF EXISTS "videos_select_classe"       ON public.videos;
DROP POLICY IF EXISTS "videos_select_admin"        ON public.videos;
DROP POLICY IF EXISTS "videos_insert_informaticien" ON public.videos;
DROP POLICY IF EXISTS "videos_update_informaticien" ON public.videos;
DROP POLICY IF EXISTS "videos_delete_admin"        ON public.videos;
DROP POLICY IF EXISTS "video_vues_insert"          ON public.video_vues;
DROP POLICY IF EXISTS "video_vues_select"          ON public.video_vues;

-- ══════════════════════════════════════════════════════════════════
-- RÈGLES D'ACCÈS PAR CLASSE :
--
--  VIDÉOS PUBLIQUES → Tous les utilisateurs connectés
--
--  VIDÉOS DE CLASSE → Uniquement :
--    - Étudiant inscrit dans cette classe (par classe_id, classe_nom ou filiere+niveau)
--    - Professeur enseignant dans cette classe
--    - Tous les comptes de direction/staff/administration :
--      (Secrétariat, DAC, Secretaire DAC, Comptable, Gestionnaire, Surveillant, Informaticien, Admin)
-- ══════════════════════════════════════════════════════════════════

-- ── 7a. SELECT vidéos publiques : tous utilisateurs connectés ────
CREATE POLICY "videos_select_publiques"
ON public.videos FOR SELECT
USING (
  statut = 'publie'
  AND visibilite = 'publique'
  AND auth.uid() IS NOT NULL
);

-- ── 7b. SELECT vidéos de classe : étudiant/prof de cette classe + tout le staff ──
CREATE POLICY "videos_select_classe"
ON public.videos FOR SELECT
USING (
  statut = 'publie'
  AND visibilite = 'classe'
  AND (
    -- ── 1. Étudiant : inscrit dans cette classe précise ──
    EXISTS (
      SELECT 1 FROM public.utilisateurs u
      WHERE u.id = auth.uid()
      AND u.role IN ('etudiant', 'etudiants')
      AND (
        -- Par classe_id
        (videos.classe_id IS NOT NULL AND u.classe_id = videos.classe_id)
        OR
        -- Par nom complet de classe
        (videos.classe_nom IS NOT NULL AND (u.classe = videos.classe_nom OR u.classe_nom = videos.classe_nom))
        OR
        -- Par couple filière + niveau
        (u.filiere = videos.classe_filiere AND u.niveau = videos.classe_niveau)
      )
    )
    OR
    -- Vérification via table d'affectation classe_etudiants si existante
    EXISTS (
      SELECT 1 FROM public.classe_etudiants ce
      WHERE ce.etudiant_id::text = auth.uid()::text
      AND (
        ce.classe_id = videos.classe_id
        OR ce.classe_id = videos.classe_nom
      )
    )
    OR
    -- ── 2. Professeur : enseignant dans cette classe ──
    EXISTS (
      SELECT 1 FROM public.utilisateurs u
      WHERE u.id = auth.uid()
      AND u.role = 'professeur'
      AND (
        (videos.classe_id IS NOT NULL AND u.classe_id = videos.classe_id)
        OR (videos.classe_nom IS NOT NULL AND (u.classe = videos.classe_nom OR u.classe_nom = videos.classe_nom))
        OR (u.filiere = videos.classe_filiere)
        OR videos.classe_id IS NULL
      )
    )
    OR
    EXISTS (
      SELECT 1 FROM public.classe_matieres cm
      WHERE cm.enseignant_id::text = auth.uid()::text
      AND cm.classe_id = videos.classe_id
    )
    OR
    -- ── 3. Comptes Administratifs & Staff : accès complet ──
    -- Secrétariat, DAC, Secretaire DAC, Comptable, Gestionnaire, Surveillant, Informaticien, Admin
    EXISTS (
      SELECT 1 FROM public.utilisateurs u
      WHERE u.id = auth.uid()
      AND u.role IN (
        'admin_principal', 'admin',
        'dac', 'direction_dac',
        'secretariat', 'secretaire', 'secretaire_dac', 'secretaire du dac',
        'gestionnaire',
        'comptable',
        'surveillant',
        'informaticien', 'informaticiens',
        'tuteur'
      )
    )
  )
);

-- ── 7c. SELECT staff universel (brouillons & archives) ─────────────
CREATE POLICY "videos_select_admin"
ON public.videos FOR SELECT
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN (
      'admin_principal', 'admin', 'informaticien', 'informaticiens',
      'dac', 'direction_dac', 'secretariat', 'secretaire', 'secretaire_dac',
      'gestionnaire', 'comptable', 'surveillant'
    )
  )
);

-- ── 7d. INSERT : informaticiens et admins publient ───────────────
CREATE POLICY "videos_insert_informaticien"
ON public.videos FOR INSERT
WITH CHECK (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN ('admin_principal', 'admin', 'informaticien', 'informaticiens')
  )
);

-- ── 7e. UPDATE : informaticiens et admins ────────────────────────
CREATE POLICY "videos_update_informaticien"
ON public.videos FOR UPDATE
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN ('admin_principal', 'admin', 'informaticien', 'informaticiens')
  )
);

-- ── 7f. DELETE : admins principaux uniquement ────────────────────
CREATE POLICY "videos_delete_admin"
ON public.videos FOR DELETE
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN ('admin_principal', 'admin')
  )
);

-- ── 7g. Vues : enregistrement et lecture ─────────────────────────
CREATE POLICY "video_vues_insert"
ON public.video_vues FOR INSERT
WITH CHECK (auth.uid() IS NOT NULL);

CREATE POLICY "video_vues_select"
ON public.video_vues FOR SELECT
USING (
  user_id = auth.uid()
  OR EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN ('admin_principal', 'admin', 'informaticien', 'informaticiens', 'dac', 'secretariat')
  )
);

-- ─── 8. Storage bucket vidéos ─────────────────────────────────────
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'videos-isgi',
  'videos-isgi',
  false,
  5368709120,  -- 5 GB max
  ARRAY[
    'video/mp4', 'video/webm', 'video/x-matroska',
    'video/avi', 'video/quicktime', 'video/ogg',
    'image/jpeg', 'image/png', 'image/webp'
  ]
)
ON CONFLICT (id) DO NOTHING;

-- Storage policies
DROP POLICY IF EXISTS "videos_storage_insert" ON storage.objects;
DROP POLICY IF EXISTS "videos_storage_select" ON storage.objects;

-- Upload : informaticiens et admins seulement
CREATE POLICY "videos_storage_insert"
ON storage.objects FOR INSERT
WITH CHECK (
  bucket_id = 'videos-isgi'
  AND EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
    AND u.role IN ('admin_principal', 'admin', 'informaticien', 'informaticiens')
  )
);

-- Lecture : selon les règles de la vidéo (publique vs classe)
CREATE POLICY "videos_storage_select"
ON storage.objects FOR SELECT
USING (
  bucket_id = 'videos-isgi'
  AND (
    -- Admins et tout le staff (Secrétariat, DAC, Comptable, Surveillant, Informaticien...) : accès total
    EXISTS (
      SELECT 1 FROM public.utilisateurs u
      WHERE u.id = auth.uid()
      AND u.role IN (
        'admin_principal', 'admin', 'informaticien', 'informaticiens',
        'dac', 'direction_dac', 'secretariat', 'secretaire', 'secretaire_dac',
        'gestionnaire', 'comptable', 'surveillant'
      )
    )
    OR
    -- Vidéo publique : tout utilisateur connecté
    EXISTS (
      SELECT 1 FROM public.videos v
      WHERE v.url_video LIKE '%' || storage.objects.name
      AND v.visibilite = 'publique' AND v.statut = 'publie'
    )
    OR
    -- Vidéo de classe : étudiant/prof de la même classe
    EXISTS (
      SELECT 1 FROM public.videos v
      JOIN public.utilisateurs u ON u.id = auth.uid()
      WHERE v.url_video LIKE '%' || storage.objects.name
      AND v.visibilite = 'classe' AND v.statut = 'publie'
      AND (
        (
          u.role IN ('etudiant','etudiants')
          AND (
            (v.classe_id IS NOT NULL AND u.classe_id = v.classe_id)
            OR (v.classe_nom IS NOT NULL AND (u.classe = v.classe_nom OR u.classe_nom = v.classe_nom))
            OR (u.filiere = v.classe_filiere AND u.niveau = v.classe_niveau)
          )
        )
        OR u.role = 'professeur'
      )
    )
  )
);

-- ─── 9. Vue statistiques pour le staff (DAC, Secrétariat, Admin) ───
CREATE OR REPLACE VIEW public.v_videos_stats AS
SELECT
  v.id,
  v.titre,
  v.categorie,
  v.visibilite,
  -- Classe cible formatée
  CASE
    WHEN v.visibilite = 'publique' THEN '🌐 Tout le monde (Publique)'
    ELSE '🎓 ' || COALESCE(v.classe_nom, COALESCE(v.classe_filiere, '?') || ' — ' || COALESCE(v.classe_niveau, '?'))
  END AS classe_cible,
  v.classe_id,
  v.classe_nom,
  v.classe_filiere,
  v.classe_niveau,
  v.statut,
  v.vues,
  v.publie_par_nom,
  v.created_at,
  COUNT(DISTINCT vv.user_id) AS viewers_uniques
FROM public.videos v
LEFT JOIN public.video_vues vv ON vv.video_id = v.id
GROUP BY v.id;

-- ─── 10. Données de démonstration ─────────────────────────────────
INSERT INTO public.videos
  (titre, description, categorie, visibilite, classe_id, classe_nom, classe_filiere, classe_niveau, publie_par_nom, statut)
VALUES
  (
    'Présentation de l''ISGI 2026-2027',
    'Vidéo de bienvenue pour tous les nouveaux étudiants. Découvrez notre campus et nos services.',
    'presentation', 'publique', NULL, NULL, NULL, NULL,
    'Service Informatique', 'publie'
  ),
  (
    'Règlement intérieur de l''école',
    'Présentation complète du règlement intérieur et des procédures administratives.',
    'presentation', 'publique', NULL, NULL, NULL, NULL,
    'Service Informatique', 'publie'
  ),
  (
    'Introduction à l''Algorithmique',
    'Cours d''introduction aux algorithmes et à la pensée computationnelle.',
    'cours', 'classe', 'L1_IG', 'Licence 1 — Informatique de Gestion (L1-IG)', 'Informatique de Gestion', 'Licence 1',
    'Prof. MOUKALA', 'publie'
  ),
  (
    'Bases de Données Relationnelles — TP',
    'Travaux pratiques : modélisation entité-association et SQL.',
    'cours', 'classe', 'L2_IG', 'Licence 2 — Informatique de Gestion (L2-IG)', 'Informatique de Gestion', 'Licence 2',
    'Prof. NZITA', 'publie'
  ),
  (
    'Comptabilité Générale — Module 1',
    'Introduction aux principes comptables fondamentaux.',
    'cours', 'classe', 'BTS1_FC', 'BTS 1 — Finance et Comptabilité (BTS1-FC)', 'Finance et Comptabilité', 'BTS 1',
    'Prof. BAKALA', 'publie'
  ),
  (
    'Marketing Digital & Stratégie',
    'Stratégies marketing à l''ère du numérique.',
    'cours', 'classe', 'L1_MC', 'Licence 1 — Marketing et Commerce (L1-MC)', 'Marketing et Commerce', 'Licence 1',
    'Prof. MBEMBA', 'publie'
  )
ON CONFLICT DO NOTHING;

-- ══════════════════════════════════════════════════════════════════
-- RÉSUMÉ FINAL DES ACCÈS PAR CLASSE :
--
-- 1. VIDÉO PUBLIQUE :
--   ✓ Tous les utilisateurs connectés (étudiant, prof, dac, secretariat, etc.)
--
-- 2. VIDÉO DE CLASSE (ex: "Licence 1 — Informatique de Gestion (L1-IG)") :
--   ✓ Étudiant inscrit dans CETTE CLASSE précise :
--     - Par classe_id (ex: L1_IG)
--     - Par classe_nom (ex: Licence 1 — Informatique de Gestion)
--     - Par filière + niveau
--     - Ou via la table d'affectation classe_etudiants
--   ✓ Professeurs assignés à cette classe (ou enseignant cette matière)
--   ✓ TOUT LE STAFF ADMINISTRATIF :
--     - Secrétariat / Secrétaire DAC
--     - Direction DAC
--     - Gestionnaire
--     - Comptable
--     - Surveillant
--     - Informaticien (publieur)
--     - Administrateur Principal
--   ✗ Les étudiants d'une AUTRE classe ne peuvent PAS voir cette vidéo
--
-- 3. GESTION (Upload / Édition / Suppression) :
--   ✓ Informaticien & Administrateur Principal
--
-- 4. CONSULTATION & VIDÉOTHÈQUE STAFF (Secrétariat, DAC, Gestionnaire...) :
--   ✓ Onglet Vidéothèque dédié dans leurs applications respectives
--   ✓ Filtres par classe structurée, par filière, par catégorie et recherche
-- ══════════════════════════════════════════════════════════════════
