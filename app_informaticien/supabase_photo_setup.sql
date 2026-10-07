-- Configuration Supabase de l'application Informaticien.
-- À exécuter dans le SQL Editor du projet Supabase avant d'utiliser le studio mobile.

ALTER TABLE public.etudiants
  ADD COLUMN IF NOT EXISTS photo_url TEXT;

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'student-photos',
  'student-photos',
  false,
  10485760,
  ARRAY['image/jpeg', 'image/png', 'image/webp']
)
ON CONFLICT (id) DO UPDATE SET
  public = false,
  file_size_limit = 10485760,
  allowed_mime_types = ARRAY['image/jpeg', 'image/png', 'image/webp'];

DROP POLICY IF EXISTS "informaticien_photo_select" ON storage.objects;
CREATE POLICY "informaticien_photo_select"
ON storage.objects FOR SELECT TO authenticated
USING (
  bucket_id = 'student-photos'
  AND EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

DROP POLICY IF EXISTS "informaticien_photo_insert" ON storage.objects;
CREATE POLICY "informaticien_photo_insert"
ON storage.objects FOR INSERT TO authenticated
WITH CHECK (
  bucket_id = 'student-photos'
  AND EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

DROP POLICY IF EXISTS "informaticien_photo_delete" ON storage.objects;
CREATE POLICY "informaticien_photo_delete"
ON storage.objects FOR DELETE TO authenticated
USING (
  bucket_id = 'student-photos'
  AND EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

CREATE OR REPLACE FUNCTION public.update_student_photo(p_student_id TEXT, p_photo_path TEXT)
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, auth
AS $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  ) THEN
    RAISE EXCEPTION 'Accès réservé au service informatique';
  END IF;

  IF p_student_id IS NULL
     OR p_student_id !~ '^[a-zA-Z0-9_-]+$'
     OR (p_photo_path IS NOT NULL AND (
       p_photo_path !~ '^[a-zA-Z0-9_-]+/[0-9]+\.jpg$'
       OR split_part(p_photo_path, '/', 1) <> p_student_id
     )) THEN
    RAISE EXCEPTION 'Chemin de photo étudiant invalide';
  END IF;

  UPDATE public.etudiants
  SET photo_url = p_photo_path
  WHERE id::TEXT = p_student_id;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'Étudiant introuvable';
  END IF;
END;
$$;

REVOKE ALL ON FUNCTION public.update_student_photo(TEXT, TEXT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.update_student_photo(TEXT, TEXT) TO authenticated;

CREATE TABLE IF NOT EXISTS public.informaticien_badges (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  etudiant_id TEXT,
  nom TEXT NOT NULL,
  prenom TEXT,
  matricule TEXT NOT NULL,
  lieu_naissance TEXT,
  filiere TEXT,
  niveau TEXT,
  annee_academique TEXT,
  date_generation TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE public.informaticien_badges ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, DELETE ON public.informaticien_badges TO authenticated;

DROP POLICY IF EXISTS "informaticien_badges_select" ON public.informaticien_badges;
CREATE POLICY "informaticien_badges_select"
ON public.informaticien_badges FOR SELECT TO authenticated
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

DROP POLICY IF EXISTS "informaticien_badges_insert" ON public.informaticien_badges;
CREATE POLICY "informaticien_badges_insert"
ON public.informaticien_badges FOR INSERT TO authenticated
WITH CHECK (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

DROP POLICY IF EXISTS "informaticien_badges_delete" ON public.informaticien_badges;
CREATE POLICY "informaticien_badges_delete"
ON public.informaticien_badges FOR DELETE TO authenticated
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

CREATE TABLE IF NOT EXISTS public.informaticien_parametres (
  id TEXT PRIMARY KEY DEFAULT 'global' CHECK (id = 'global'),
  annee_defaut TEXT,
  directeur TEXT
);

ALTER TABLE public.informaticien_parametres ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, UPDATE ON public.informaticien_parametres TO authenticated;

DROP POLICY IF EXISTS "informaticien_parametres_access" ON public.informaticien_parametres;
CREATE POLICY "informaticien_parametres_access"
ON public.informaticien_parametres FOR ALL TO authenticated
USING (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
  )
);

DROP POLICY IF EXISTS "informaticien_videos_delete_own" ON public.videos;
CREATE POLICY "informaticien_videos_delete_own"
ON public.videos FOR DELETE TO authenticated
USING (
  EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
      AND (
        videos.publie_par = auth.uid()
        OR u.role IN ('admin', 'admin_principal')
      )
  )
);

DROP POLICY IF EXISTS "informaticien_videos_storage_delete_own" ON storage.objects;
CREATE POLICY "informaticien_videos_storage_delete_own"
ON storage.objects FOR DELETE TO authenticated
USING (
  bucket_id = 'videos-isgi'
  AND EXISTS (
    SELECT 1
    FROM public.utilisateurs u
    WHERE u.id = auth.uid()
      AND u.statut = 'actif'
      AND u.role IN ('informaticien', 'informaticiens', 'admin', 'admin_principal')
      AND (
        split_part(storage.objects.name, '/', 1) = auth.uid()::TEXT
        OR u.role IN ('admin', 'admin_principal')
      )
  )
);

NOTIFY pgrst, 'reload schema';
