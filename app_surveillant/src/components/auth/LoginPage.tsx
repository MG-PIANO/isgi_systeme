import React, { useState } from 'react';
import { AlertCircle, Loader2, LogIn, ShieldCheck } from 'lucide-react';
import { supabase } from '../../db/supabaseClient';

interface LoginPageProps {
  initialError?: string;
  onLoginSuccess: (user: { id: string; nom: string; role: string }) => void;
}

export const LoginPage: React.FC<LoginPageProps> = ({ initialError = '', onLoginSuccess }) => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError('');
    setLoading(true);

    try {
      const { data, error: authError } = await supabase.auth.signInWithPassword({
        email: email.trim(),
        password,
      });
      if (authError || !data.user) {
        setError('Adresse e-mail ou mot de passe incorrect.');
        return;
      }

      const { data: profile, error: profileError } = await supabase
        .from('utilisateurs')
        .select('id, nom_complet, role, statut')
        .eq('id', data.user.id)
        .maybeSingle();

      if (profileError) throw profileError;
      if (!profile || profile.statut !== 'actif' || !['surveillant', 'admin', 'admin_principal'].includes(profile.role)) {
        const { error: signOutError } = await supabase.auth.signOut();
        if (signOutError) console.error('Déconnexion du compte non autorisé impossible:', signOutError);
        setError("Accès refusé. Cette application est réservée au Surveillant Général.");
        return;
      }

      onLoginSuccess({
        id: profile.id,
        nom: profile.nom_complet || 'Surveillant Général',
        role: profile.role,
      });
    } catch (reason) {
      console.error('Erreur de connexion du Surveillant:', reason);
      setError('Impossible de se connecter au serveur. Vérifiez votre connexion et vos identifiants.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="min-h-screen flex items-center justify-center bg-slate-950 px-4 py-8 font-sans">
      <section className="w-full max-w-md overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
        <header className="bg-blue-700 px-6 py-7 text-center text-white">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-white p-1">
            <img src="./logo.jpg" alt="ISGI" className="h-full w-full object-contain" />
          </div>
          <h1 className="text-2xl font-bold">ISGI System</h1>
          <p className="mt-1 text-sm text-blue-100">Espace Surveillant Général</p>
        </header>

        <form onSubmit={handleSubmit} className="space-y-4 p-6">
          <div className="mb-5">
            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-300">Accès sécurisé</p>
            <h2 className="mt-2 text-xl font-bold text-white">Connexion au poste de surveillance</h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-400">
              Connectez-vous avec le compte Surveillant créé par l’administration.
            </p>
          </div>

          {(error || initialError) && (
            <div role="alert" className="flex items-start gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{error || initialError}</span>
            </div>
          )}

          <label className="block text-sm font-medium text-slate-200">
            Adresse e-mail
            <input
              type="email"
              required
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="surveillant@isgi.ga"
              className="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-800 px-4 py-3 text-sm text-white outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20"
            />
          </label>

          <label className="block text-sm font-medium text-slate-200">
            Mot de passe
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-800 px-4 py-3 text-sm text-white outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20"
            />
          </label>

          <button
            type="submit"
            disabled={loading}
            className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60"
          >
            {loading ? <Loader2 className="h-5 w-5 animate-spin" /> : <LogIn className="h-5 w-5" />}
            {loading ? 'Connexion en cours…' : 'Se connecter'}
          </button>

          <p className="flex items-center justify-center gap-1.5 pt-2 text-center text-xs text-slate-400">
            <ShieldCheck className="h-4 w-4 text-emerald-400" />
            Les comptes sont fournis par l’administration ISGI.
          </p>
        </form>
      </section>
    </main>
  );
};
