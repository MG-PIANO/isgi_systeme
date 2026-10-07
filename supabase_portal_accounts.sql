-- ISGI student and parent portal account tables.
-- This setup does not change access policies on existing academic tables.
-- Those policies remain a known privacy gap; do not expose the portal publicly
-- until the deferred RLS hardening has been reviewed and deployed.

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

CREATE TABLE IF NOT EXISTS public.portal_signup_rate_limits (
  key_hash TEXT PRIMARY KEY CHECK (key_hash ~ '^[0-9a-f]{64}$'),
  window_started_at TIMESTAMPTZ NOT NULL,
  attempt_count INTEGER NOT NULL CHECK (attempt_count > 0)
);

ALTER TABLE public.portal_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_account_students ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_signup_rate_limits ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON public.portal_accounts, public.portal_account_students, public.portal_signup_rate_limits
  FROM anon, authenticated;
GRANT ALL ON public.portal_accounts, public.portal_account_students, public.portal_signup_rate_limits
  TO service_role;

CREATE OR REPLACE FUNCTION public.portal_consume_rate_limit(
  p_key_hash TEXT,
  p_max_attempts INTEGER,
  p_window_seconds INTEGER
)
RETURNS BOOLEAN
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
DECLARE
  current_attempts INTEGER;
BEGIN
  IF p_key_hash !~ '^[0-9a-f]{64}$'
     OR p_max_attempts < 1 OR p_max_attempts > 100
     OR p_window_seconds < 1 OR p_window_seconds > 86400 THEN
    RAISE EXCEPTION 'Rate limit arguments are invalid';
  END IF;

  DELETE FROM public.portal_signup_rate_limits
  WHERE window_started_at < now() - INTERVAL '24 hours';

  INSERT INTO public.portal_signup_rate_limits (key_hash, window_started_at, attempt_count)
  VALUES (p_key_hash, now(), 1)
  ON CONFLICT (key_hash) DO UPDATE
  SET window_started_at = CASE
        WHEN public.portal_signup_rate_limits.window_started_at
             <= now() - (p_window_seconds * INTERVAL '1 second')
        THEN now()
        ELSE public.portal_signup_rate_limits.window_started_at
      END,
      attempt_count = CASE
        WHEN public.portal_signup_rate_limits.window_started_at
             <= now() - (p_window_seconds * INTERVAL '1 second')
        THEN 1
        ELSE public.portal_signup_rate_limits.attempt_count + 1
      END
  RETURNING attempt_count INTO current_attempts;

  RETURN current_attempts <= p_max_attempts;
END;
$$;

REVOKE ALL ON FUNCTION public.portal_consume_rate_limit(TEXT, INTEGER, INTEGER)
  FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.portal_consume_rate_limit(TEXT, INTEGER, INTEGER)
  TO service_role;
