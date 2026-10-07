-- DEFERRED: strict portal RLS hardening.
-- DO NOT RUN until all legacy clients of these tables use compatible,
-- authenticated access and have passed regression testing.
-- ISGI student and parent portal.
-- Run this migration after the existing DAC and video schemas.
-- Staff accounts must be explicitly allow-listed in portal_staff before
-- they can review registrations or access protected academic records.

CREATE TABLE IF NOT EXISTS public.portal_accounts (
  user_id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  username TEXT NOT NULL UNIQUE,
  account_type TEXT NOT NULL CHECK (account_type IN ('etudiant', 'tuteur')),
  full_name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'approved', 'active', 'rejected')),
  activation_code_hash TEXT,
  requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  reviewed_at TIMESTAMPTZ,
  reviewed_by UUID REFERENCES auth.users(id) ON DELETE SET NULL,
  activated_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS public.portal_account_students (
  user_id UUID NOT NULL REFERENCES public.portal_accounts(user_id) ON DELETE CASCADE,
  student_id TEXT NOT NULL,
  matricule TEXT NOT NULL,
  PRIMARY KEY (user_id, matricule)
);

-- This is deliberately not populated from public.utilisateurs: that table's
-- role is self-selectable during staff signup in existing clients.
CREATE TABLE IF NOT EXISTS public.portal_staff (
  user_id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (
    role IN (
      'secretariat', 'comptable', 'dac', 'secretaire_dac', 'admin',
      'informaticien', 'surveillant', 'gestionnaire', 'professeur'
    )
  ),
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE public.portal_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_account_students ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_staff ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_accounts, public.portal_account_students, public.portal_staff
  FROM anon, authenticated;
GRANT SELECT ON public.portal_staff TO authenticated;
GRANT ALL ON public.portal_accounts, public.portal_account_students, public.portal_staff
  TO service_role;
DROP POLICY IF EXISTS portal_staff_self_select ON public.portal_staff;
CREATE POLICY portal_staff_self_select ON public.portal_staff FOR SELECT TO authenticated
  USING (user_id = (SELECT auth.uid()));

CREATE OR REPLACE FUNCTION public.portal_is_active_account()
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.portal_accounts pa
    WHERE pa.user_id = (SELECT auth.uid()) AND pa.status = 'active'
  );
$$;

CREATE OR REPLACE FUNCTION public.portal_is_staff()
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.portal_staff ps
    WHERE ps.user_id = (SELECT auth.uid()) AND ps.enabled
  );
$$;

CREATE OR REPLACE FUNCTION public.portal_is_video_staff()
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.portal_staff ps
    WHERE ps.user_id = (SELECT auth.uid())
      AND ps.enabled
      AND ps.role IN ('admin', 'informaticien')
  );
$$;

CREATE OR REPLACE FUNCTION public.portal_can_view_student(p_student_ref TEXT)
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT p_student_ref IS NOT NULL AND (
    public.portal_is_staff()
    OR (
      public.portal_is_active_account()
      AND EXISTS (
        SELECT 1
        FROM public.portal_account_students pas
        WHERE pas.user_id = (SELECT auth.uid())
          AND (pas.student_id = p_student_ref OR pas.matricule = p_student_ref)
      )
    )
  );
$$;

CREATE OR REPLACE FUNCTION public.portal_can_view_class(
  p_class_id TEXT,
  p_class_name TEXT DEFAULT NULL
)
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT public.portal_is_staff()
    OR (
      public.portal_is_active_account()
      AND EXISTS (
        SELECT 1
        FROM public.portal_account_students pas
        JOIN public.etudiants e
          ON e.id::TEXT = pas.student_id OR e.matricule = pas.matricule
        LEFT JOIN public.classe_etudiants ce
          ON ce.etudiant_id::TEXT IN (pas.student_id, pas.matricule)
        WHERE pas.user_id = (SELECT auth.uid())
          AND (
            ce.classe_id::TEXT = p_class_id
            OR (p_class_name IS NOT NULL AND (
              ce.classe_id::TEXT = p_class_name
              OR e.classe_id::TEXT = p_class_id
              OR e.classe_nom = p_class_name
            ))
          )
      )
    );
$$;

CREATE OR REPLACE FUNCTION public.portal_can_view_video_object(p_object_name TEXT)
RETURNS BOOLEAN
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = ''
AS $$
  SELECT public.portal_is_staff() OR EXISTS (
    SELECT 1
    FROM public.videos v
    WHERE (
        v.url_video = p_object_name
        OR regexp_replace(
          split_part(v.url_video, '?', 1),
          '^.*/object/(public|sign)/videos-isgi/',
          ''
        ) = p_object_name
      )
      AND v.statut = 'publie'
      AND public.portal_is_active_account()
      AND (
        v.visibilite = 'publique'
        OR (
          v.visibilite = 'classe'
          AND public.portal_can_view_class(v.classe_id, v.classe_nom)
        )
      )
  );
$$;

REVOKE ALL ON FUNCTION public.portal_is_active_account() FROM PUBLIC, anon;
REVOKE ALL ON FUNCTION public.portal_is_staff() FROM PUBLIC, anon;
REVOKE ALL ON FUNCTION public.portal_is_video_staff() FROM PUBLIC, anon;
REVOKE ALL ON FUNCTION public.portal_can_view_student(TEXT) FROM PUBLIC, anon;
REVOKE ALL ON FUNCTION public.portal_can_view_class(TEXT, TEXT) FROM PUBLIC, anon;
REVOKE ALL ON FUNCTION public.portal_can_view_video_object(TEXT) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.portal_is_active_account() TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.portal_is_staff() TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.portal_is_video_staff() TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.portal_can_view_student(TEXT) TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.portal_can_view_class(TEXT, TEXT) TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.portal_can_view_video_object(TEXT) TO authenticated, service_role;

-- Existing DAC schemas create permissive "Allow all" policies. Remove every
-- policy from the portal-protected tables before installing least-privilege
-- rules; otherwise PostgreSQL combines permissive policies with OR.
DO $$
DECLARE
  target_table TEXT;
  existing_policy RECORD;
BEGIN
  FOREACH target_table IN ARRAY ARRAY[
    'utilisateurs', 'etudiants', 'classes', 'matieres', 'classe_etudiants',
    'notes', 'presences_etudiants', 'emplois_du_temps',
    'publications_academiques', 'videos', 'notifications',
    'soumissions_notes', 'notes_provisoires', 'paiements'
  ] LOOP
    IF to_regclass('public.' || target_table) IS NOT NULL THEN
      EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', target_table);
      FOR existing_policy IN
        SELECT policyname
        FROM pg_policies
        WHERE schemaname = 'public' AND tablename = target_table
      LOOP
        EXECUTE format('DROP POLICY %I ON public.%I', existing_policy.policyname, target_table);
      END LOOP;
    END IF;
  END LOOP;
END;
$$;

DO $$
BEGIN
  IF to_regclass('public.utilisateurs') IS NOT NULL THEN
    CREATE POLICY portal_users_select ON public.utilisateurs FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR id::TEXT = auth.uid()::TEXT);
    CREATE POLICY portal_users_staff_write ON public.utilisateurs FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.etudiants') IS NOT NULL THEN
    CREATE POLICY portal_students_select ON public.etudiants FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_can_view_student(id::TEXT)
        OR public.portal_can_view_student(matricule));
    CREATE POLICY portal_students_staff_write ON public.etudiants FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.classes') IS NOT NULL THEN
    CREATE POLICY portal_classes_select ON public.classes FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_is_active_account());
    CREATE POLICY portal_classes_staff_write ON public.classes FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.matieres') IS NOT NULL THEN
    CREATE POLICY portal_matieres_select ON public.matieres FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_is_active_account());
    CREATE POLICY portal_matieres_staff_write ON public.matieres FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.classe_etudiants') IS NOT NULL THEN
    CREATE POLICY portal_memberships_select ON public.classe_etudiants FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_can_view_student(etudiant_id::TEXT));
    CREATE POLICY portal_memberships_staff_write ON public.classe_etudiants FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.notes') IS NOT NULL THEN
    CREATE POLICY portal_notes_select ON public.notes FOR SELECT TO authenticated
      USING (
        public.portal_is_staff()
        OR (COALESCE(publie, false) AND public.portal_can_view_student(etudiant_id::TEXT))
      );
    CREATE POLICY portal_notes_staff_write ON public.notes FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.presences_etudiants') IS NOT NULL THEN
    CREATE POLICY portal_attendance_select ON public.presences_etudiants FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_can_view_student(etudiant_id::TEXT));
    CREATE POLICY portal_attendance_staff_write ON public.presences_etudiants FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.emplois_du_temps') IS NOT NULL THEN
    CREATE POLICY portal_timetable_select ON public.emplois_du_temps FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR (
        COALESCE(publie, false) AND public.portal_can_view_class(classe_id::TEXT)
      ));
    CREATE POLICY portal_timetable_staff_write ON public.emplois_du_temps FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.publications_academiques') IS NOT NULL THEN
    CREATE POLICY portal_publications_select ON public.publications_academiques FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR (
        publie AND public.portal_is_active_account()
        AND (classe_id IS NULL OR public.portal_can_view_class(classe_id::TEXT))
      ));
    CREATE POLICY portal_publications_staff_write ON public.publications_academiques FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.videos') IS NOT NULL THEN
    CREATE POLICY portal_videos_select ON public.videos FOR SELECT TO authenticated
      USING (
        public.portal_is_staff()
        OR (
          statut = 'publie'
          AND public.portal_is_active_account()
          AND (
            visibilite = 'publique'
            OR (visibilite = 'classe' AND public.portal_can_view_class(classe_id, classe_nom))
          )
        )
      );
    CREATE POLICY portal_videos_staff_write ON public.videos FOR ALL TO authenticated
      USING (public.portal_is_video_staff()) WITH CHECK (public.portal_is_video_staff());
  END IF;
  IF to_regclass('public.notifications') IS NOT NULL THEN
    CREATE POLICY portal_notifications_select ON public.notifications FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR user_id = auth.uid()::TEXT);
    CREATE POLICY portal_notifications_staff_write ON public.notifications FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.soumissions_notes') IS NOT NULL THEN
    CREATE POLICY portal_note_submissions_staff ON public.soumissions_notes FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.notes_provisoires') IS NOT NULL THEN
    CREATE POLICY portal_provisional_grades_staff ON public.notes_provisoires FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
  IF to_regclass('public.paiements') IS NOT NULL THEN
    CREATE POLICY portal_payments_select ON public.paiements FOR SELECT TO authenticated
      USING (public.portal_is_staff() OR public.portal_can_view_student(etudiant_id::TEXT));
    CREATE POLICY portal_payments_staff_write ON public.paiements FOR ALL TO authenticated
      USING (public.portal_is_staff()) WITH CHECK (public.portal_is_staff());
  END IF;
END;
$$;

-- Ensure the private video bucket cannot be read around the class checks.
DROP POLICY IF EXISTS "videos_storage_select" ON storage.objects;
DROP POLICY IF EXISTS "videos_storage_insert" ON storage.objects;
CREATE POLICY portal_video_storage_select ON storage.objects FOR SELECT TO authenticated
  USING (bucket_id = 'videos-isgi' AND public.portal_can_view_video_object(name));
CREATE POLICY portal_video_storage_staff_write ON storage.objects FOR ALL TO authenticated
  USING (bucket_id = 'videos-isgi' AND public.portal_is_video_staff())
  WITH CHECK (bucket_id = 'videos-isgi' AND public.portal_is_video_staff());

-- Add verified staff UUIDs manually after checking each existing Auth account.
-- Example:
-- INSERT INTO public.portal_staff (user_id, role)
-- SELECT id, 'dac' FROM auth.users WHERE email = 'dac@isgi.cg';
