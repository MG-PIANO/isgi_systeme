-- ═══════════════════════════════════════════════════════════════════════
--  ISGI — Supabase Storage : Bucket student-photos + Politiques RLS
--  À exécuter dans le SQL Editor de Supabase (https://supabase.com/dashboard)
-- ═══════════════════════════════════════════════════════════════════════

-- 1. Créer le bucket student-photos (public = true pour un accès direct et pérenne aux photos des cartes)
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'student-photos',
  'student-photos',
  true,
  5242880, -- 5 Mo max
  ARRAY['image/jpeg', 'image/png', 'image/webp']
)
ON CONFLICT (id) DO UPDATE SET
  public = true,
  file_size_limit = 5242880,
  allowed_mime_types = ARRAY['image/jpeg', 'image/png', 'image/webp'];

-- 2. Supprimer les anciennes politiques RLS si existantes
DROP POLICY IF EXISTS "informaticien_photos_select" ON storage.objects;
DROP POLICY IF EXISTS "informaticien_photos_insert" ON storage.objects;
DROP POLICY IF EXISTS "informaticien_photos_update" ON storage.objects;
DROP POLICY IF EXISTS "informaticien_photos_delete" ON storage.objects;
DROP POLICY IF EXISTS "public_photos_select" ON storage.objects;
DROP POLICY IF EXISTS "authenticated_photos_insert" ON storage.objects;
DROP POLICY IF EXISTS "authenticated_photos_update" ON storage.objects;
DROP POLICY IF EXISTS "authenticated_photos_delete" ON storage.objects;

-- 3. Lecture publique (permet d'afficher les photos sur les badges, les aperçus et après redémarrage sans token expiré)
CREATE POLICY "public_photos_select"
ON storage.objects FOR SELECT
TO public
USING (bucket_id = 'student-photos');

-- 4. Téléversement pour les utilisateurs connectés (Informaticien, Admin)
CREATE POLICY "authenticated_photos_insert"
ON storage.objects FOR INSERT
TO authenticated
WITH CHECK (bucket_id = 'student-photos');

-- 5. Remplacement / Mise à jour des photos
CREATE POLICY "authenticated_photos_update"
ON storage.objects FOR UPDATE
TO authenticated
USING (bucket_id = 'student-photos')
WITH CHECK (bucket_id = 'student-photos');

-- 6. Suppression des anciennes photos
CREATE POLICY "authenticated_photos_delete"
ON storage.objects FOR DELETE
TO authenticated
USING (bucket_id = 'student-photos');

-- 7. Vérifier que la table etudiants permet la mise à jour de photo_url par les utilisateurs connectés
ALTER TABLE IF EXISTS public.etudiants ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "authenticated_update_etudiants_photo" ON public.etudiants;
CREATE POLICY "authenticated_update_etudiants_photo"
ON public.etudiants FOR UPDATE
TO authenticated
USING (true)
WITH CHECK (true);
