import { createClient, type SupabaseClient } from 'https://esm.sh/@supabase/supabase-js@2';

export const corsHeaders = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
};

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders, 'Content-Type': 'application/json' },
  });
}

export function clientsForRequest(request: Request) {
  const url = Deno.env.get('SUPABASE_URL');
  const anonKey = Deno.env.get('SUPABASE_ANON_KEY');
  const serviceRoleKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY');
  if (!url || !anonKey || !serviceRoleKey) {
    throw new Error('Configuration Supabase incomplète sur le serveur.');
  }

  const authorization = request.headers.get('Authorization') || '';
  const userClient = createClient(url, anonKey, {
    global: { headers: { Authorization: authorization } },
    auth: { persistSession: false, autoRefreshToken: false },
  });
  const adminClient = createClient(url, serviceRoleKey, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
  return { userClient, adminClient };
}

export async function requireUser(
  request: Request,
  userClient: SupabaseClient,
): Promise<{ id: string }> {
  const token = request.headers.get('Authorization')?.replace(/^Bearer\s+/i, '');
  if (!token) throw new Error('Connexion requise.');
  const { data, error } = await userClient.auth.getUser(token);
  if (error || !data.user) throw new Error('Session invalide ou expirée.');
  return { id: data.user.id };
}

export function requireStaff(userId: string): void {
  const configuredIds = Deno.env.get('PORTAL_REVIEWER_USER_IDS') || '';
  const allowedIds = new Set(configuredIds.split(',').map((id) => id.trim()).filter(Boolean));
  if (!allowedIds.has(userId)) throw new Error('Accès réservé au personnel autorisé.');
}

export function normalizeUsername(value: unknown): string {
  if (typeof value !== 'string') throw new Error('Nom d’utilisateur invalide.');
  const username = value.trim().toLowerCase();
  if (!/^[a-z0-9][a-z0-9._-]{2,29}$/.test(username)) {
    throw new Error('Le nom d’utilisateur doit contenir 3 à 30 caractères (lettres, chiffres, point, tiret ou tiret bas).');
  }
  return username;
}

export async function hashCode(code: string): Promise<string> {
  const bytes = new TextEncoder().encode(code.trim().toUpperCase());
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function activationCode(): string {
  const alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
  const bytes = crypto.getRandomValues(new Uint8Array(12));
  return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Une erreur inattendue est survenue.';
}

export function assertMethod(request: Request): void {
  if (request.method !== 'POST') throw new Error('Méthode non autorisée.');
}
