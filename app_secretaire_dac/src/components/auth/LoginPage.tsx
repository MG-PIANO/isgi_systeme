import React, { useState } from 'react';
import { LogIn, Loader2, AlertCircle, UserPlus } from 'lucide-react';
import { supabase } from '../../db/supabaseClient';
import { logAction, syncFromSupabase } from '../../db/db';
import { RegisterModal } from './RegisterModal';

interface LoginPageProps {
  onLoginSuccess: (user: any) => void;
}

export function LoginPage({ onLoginSuccess }: LoginPageProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [isRegisterOpen, setIsRegisterOpen] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const { data: authData, error: authError } = await supabase.auth.signInWithPassword({
        email: email.trim(),
        password,
      });
      if (authError || !authData.user) {
        setError('Identifiants incorrects.');
        return;
      }

      const { data: profile, error: profileError } = await supabase
        .from('utilisateurs')
        .select('id, nom_complet, role, statut')
        .eq('id', authData.user.id)
        .maybeSingle();
      if (profileError) throw profileError;
      if (!profile || profile.statut === 'bloque' || !['secretaire_dac', 'secretariat', 'admin', 'admin_principal'].includes(profile.role)) {
        await supabase.auth.signOut();
        setError("Accès refusé. Cette application est réservée au Secrétariat du DAC.");
        return;
      }

      const userObj = {
        id: profile.id,
        nom: profile.nom_complet || 'Secrétaire DAC',
        role: profile.role
      };
      localStorage.setItem('secretaire_dac_user', JSON.stringify(userObj));
      await logAction('Connexion Secrétaire DAC', 'Authentification', `Connexion de ${userObj.nom}`);
      await syncFromSupabase();
      onLoginSuccess(userObj);
    } catch (err: unknown) {
      console.error('Erreur de connexion:', err);
      setError('Impossible de se connecter au serveur. Vérifiez vos identifiants.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-surface flex items-center justify-center p-4 font-sans">
      <div className="max-w-md w-full bg-surface-container-lowest rounded-2xl shadow-xl overflow-hidden border border-outline-variant">
        <div className="bg-primary p-6 text-center text-on-primary">
          <div className="w-16 h-16 bg-surface-container-lowest rounded-full mx-auto mb-4 flex items-center justify-center overflow-hidden border border-outline-variant">
            <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain" />
          </div>
          <h1 className="text-2xl font-bold">ISGI System</h1>
          <p className="text-primary-container/80 mt-1 text-sm">Secrétariat de la Direction Académique (Secrétaire DAC)</p>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          {error && (
            <div className="mb-4 p-3 bg-error-container text-on-error-container rounded-xl flex items-center gap-2 text-xs">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">
                Adresse Email ou Identifiant
              </label>
              <input
                type="text"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all text-sm text-on-surface"
                placeholder="secretaire_dac@isgi.ga"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">
                Mot de Passe
              </label>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all text-sm text-on-surface"
                placeholder="••••••••"
              />
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full mt-6 bg-primary text-on-primary py-3 rounded-xl font-medium hover:bg-primary/90 transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed text-sm"
          >
            {loading ? (
              <Loader2 className="w-5 h-5 animate-spin" />
            ) : (
              <LogIn className="w-5 h-5" />
            )}
            <span>{loading ? 'Connexion en cours...' : 'Se connecter'}</span>
          </button>

          <div className="mt-6 pt-4 border-t border-outline-variant text-center space-y-3">
            <button
              type="button"
              onClick={() => setIsRegisterOpen(true)}
              className="w-full py-2.5 px-4 bg-surface-container-high hover:bg-surface-container-highest text-primary font-medium rounded-xl border border-outline-variant transition-all flex items-center justify-center gap-2 text-sm shadow-sm"
            >
              <UserPlus className="w-4 h-4" />
              <span>Créer un compte utilisateur</span>
            </button>

          </div>
        </form>
      </div>

      <RegisterModal
        isOpen={isRegisterOpen}
        onClose={() => setIsRegisterOpen(false)}
        defaultRole="secretaire_dac"
        onRegisteredSuccess={(createdEmail) => {
          setEmail(createdEmail);
          setError('');
        }}
      />
    </div>
  );
}
