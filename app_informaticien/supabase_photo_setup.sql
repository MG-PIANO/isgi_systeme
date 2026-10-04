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
     OR p_photo_path IS NULL
     OR p_photo_path !~ '^[a-zA-Z0-9_-]+/[0-9]+\.jpg$'
     OR split_part(p_photo_path, '/', 1) <> p_student_id THEN
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
