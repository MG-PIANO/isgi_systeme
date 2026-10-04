import React, { useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { useNavigate } from 'react-router-dom';
import { LogIn, Loader2, AlertCircle, UserPlus } from 'lucide-react';
import { RegisterModal } from './RegisterModal';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
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
      const { supabase } = await import('../../db/supabaseClient');
      const { data, error: authError } = await supabase.auth.signInWithPassword({
        email,
        password,
      });

      if (authError) {
        setError(authError.message === 'Invalid login credentials' ? 'Identifiants incorrects' : authError.message);
        setLoading(false);
        return;
      } 
      
      if (data.user && data.session) {
        // Fetch user profile from utilisateurs table
        const { data: profile, error: profileError } = await supabase
          .from('utilisateurs')
          .select('*')
          .eq('id', data.user.id)
          .single();

        if (profileError || !profile) {
          setError("Ce compte n'est pas enregistré dans le système. Veuillez contacter l'administrateur.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        if (profile.statut === 'bloque') {
          setError("Ce compte a été bloqué.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        // Define allowed roles for this specific app (Comptabilité & Finances App)
        const allowedRoles = [
          'comptable', 
          'gestionnaire_principal', 
          'gestionnaire_secondaire', 
          'secretariat', 
          'admin', 
          'admin_site', 
          'admin_principal',
          '1', '2', '3', '4'
        ];
        if (!allowedRoles.includes(profile.role)) {
          setError("Accès refusé. Cette application est réservée à la comptabilité et à la gestion financière.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        // Update last login
        await supabase
          .from('utilisateurs')
          .update({ derniere_connexion: new Date().toISOString() })
          .eq('id', data.user.id);

        // Log the activity
        await supabase
          .from('journal_activites')
          .insert([{
            utilisateur_id: data.user.id,
            utilisateur_nom: profile.nom_complet,
            type_action: 'CONNEXION',
            description: `Connexion réussie depuis l'application Comptabilité`
          }]);

        // Create a user object matching our AuthContext User interface
        const user = {
          id: profile.id, // Using the UUID from Supabase
          name: profile.nom_complet,
          email: profile.email,
          role: profile.role,
          location: data.user.user_metadata?.location || 'Brazzaville'
        };
        
        login(user, data.session.access_token);
        navigate('/');
      }
    } catch (err) {
      console.error('Erreur de connexion:', err);
      setError('Impossible de se connecter au serveur. Vérifiez votre connexion internet.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-surface flex items-center justify-center p-4">
      <div className="max-w-md w-full bg-surface-container-lowest rounded-2xl shadow-xl overflow-hidden border border-outline-variant">
        <div className="bg-primary p-6 text-center text-on-primary">
          <div className="w-16 h-16 bg-surface-container-lowest rounded-full mx-auto mb-4 flex items-center justify-center overflow-hidden">
            <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain" />
          </div>
          <h1 className="text-2xl font-bold">ISGI System</h1>
          <p className="text-primary-container/80 mt-1">Espace Comptabilité</p>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          {error && (
            <div className="mb-4 p-3 bg-error-container text-on-error-container rounded-lg flex items-center gap-2 text-sm">
              <AlertCircle className="w-5 h-5 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">
                Adresse Email
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-2 bg-surface-container-low border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                placeholder="comptable@isgi.edu"
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
                className="w-full px-4 py-2 bg-surface-container-low border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                placeholder="••••••••"
              />
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full mt-6 bg-primary text-on-primary py-3 rounded-xl font-medium hover:bg-primary/90 transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
          >
            <span className="flex items-center justify-center gap-2">
              {loading ? (
                <Loader2 className="w-5 h-5 animate-spin" />
              ) : (
                <LogIn className="w-5 h-5" />
              )}
              <span>{loading ? 'Connexion en cours...' : 'Se connecter'}</span>
            </span>
          </button>

          <div className="mt-6 pt-4 border-t border-outline-variant/60 text-center">
            <p className="text-xs text-on-surface-variant mb-2.5">
              Nouvel agent ou collaborateur ISGI ?
            </p>
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
        defaultRole="comptable"
        onRegisteredSuccess={(createdEmail) => {
          setEmail(createdEmail);
          setError('');
        }}
      />
    </div>
  );
}
