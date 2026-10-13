import React, { useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { useNavigate } from 'react-router-dom';
import { 
  LogIn, 
  Loader2, 
  AlertCircle, 
  UserPlus, 
  ShieldCheck, 
  Eye, 
  EyeOff, 
  Lock, 
  Mail, 
  Building2
} from 'lucide-react';
import { RegisterModal } from './RegisterModal';
import { supabase } from '../../db/supabaseClient';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [isRegisterOpen, setIsRegisterOpen] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const { data, error: authError } = await supabase.auth.signInWithPassword({
        email: email.trim(),
        password,
      });

      if (authError) {
        setError(authError.message === 'Invalid login credentials' ? 'Identifiants incorrects ou mot de passe invalide.' : authError.message);
        setLoading(false);
        return;
      } 
      
      if (data.user && data.session) {
        // Récupérer le profil dans la table 'utilisateurs'
        const { data: profile, error: profileError } = await supabase
          .from('utilisateurs')
          .select('*')
          .eq('id', data.user.id)
          .single();

        if (profileError || !profile) {
          setError("Ce compte n'est pas encore enregistré dans le registre des utilisateurs ISGI. Veuillez contacter l'administration.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        if (profile.statut === 'bloque') {
          setError("Ce compte a été suspendu ou bloqué par la direction.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        // Rôles autorisés pour la Direction Générale : strictement réservé à l'Admin Principal
        const allowedRoles = ['admin_principal', 'admin', 'dg', 'directeur_general', '1'];
        if (!allowedRoles.includes(profile.role)) {
          setError("Accès refusé : Ce portail de Direction Générale est strictement réservé à l'Admin Principal.");
          await supabase.auth.signOut();
          setLoading(false);
          return;
        }

        // Mettre à jour la date de dernière connexion
        try {
          await supabase
            .from('utilisateurs')
            .update({ derniere_connexion: new Date().toISOString() })
            .eq('id', data.user.id);
        } catch {
          // Non bloquant
        }

        // Enregistrer dans le journal d'activités
        try {
          await supabase
            .from('journal_activites')
            .insert([{
              utilisateur_id: data.user.id,
              utilisateur_nom: profile.nom_complet,
              type_action: 'CONNEXION',
              description: `Connexion Direction Générale réussie (Admin Principal)`
            }]);
        } catch {
          // Non bloquant
        }

        // Enregistrer la session utilisateur
        const userObj = {
          id: profile.id,
          name: profile.nom_complet || 'Admin Principal',
          email: profile.email,
          role: profile.role === 'admin' ? 'admin_principal' : profile.role,
          location: data.user.user_metadata?.location || 'Direction Générale'
        };
        
        login(userObj, data.session.access_token);
        navigate('/');
      }
    } catch (err) {
      console.error('Erreur de connexion:', err);
      setError('Impossible de se connecter au serveur Supabase. Vérifiez votre connexion internet.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-surface-container-lowest flex items-center justify-center p-4 selection:bg-primary/20">
      <div className="max-w-md w-full bg-surface-container-low rounded-3xl shadow-2xl overflow-hidden border border-outline-variant/70">
        
        {/* Header Institutionnel Direction Générale */}
        <div className="bg-gradient-to-br from-primary via-primary/95 to-primary-container p-8 text-center text-on-primary relative overflow-hidden">
          <div className="absolute -top-12 -right-12 w-36 h-36 bg-surface-container-lowest/10 rounded-full blur-2xl pointer-events-none" />
          <div className="absolute -bottom-12 -left-12 w-36 h-36 bg-surface-container-lowest/10 rounded-full blur-2xl pointer-events-none" />
          
          <div className="relative z-10">
            <div className="w-20 h-20 bg-surface-container-lowest rounded-2xl mx-auto mb-4 flex items-center justify-center p-2 shadow-lg ring-4 ring-on-primary/10">
              <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain rounded-xl" />
            </div>

            <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-lowest/20 backdrop-blur-sm text-[11px] font-black uppercase tracking-wider mb-2 border border-on-primary/20">
              <ShieldCheck className="w-3.5 h-3.5" />
              <span>Admin Principal • Direction Générale</span>
            </div>

            <h1 className="text-2xl font-black tracking-tight">ISGI System</h1>
            <p className="text-on-primary/85 text-xs font-medium mt-1">
              Portail de Gouvernance & Décision Stratégique
            </p>
          </div>
        </div>

        {/* Corps du formulaire */}
        <form onSubmit={handleSubmit} className="p-6 sm:p-8 space-y-5">
          {error && (
            <div className="p-3.5 bg-error-container text-on-error-container rounded-2xl flex items-start gap-3 text-xs sm:text-sm font-medium border border-error/20 animate-in fade-in slide-in-from-top-2">
              <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5 text-error" />
              <span className="leading-snug">{error}</span>
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-1.5">
                Adresse Email (Admin Principal)
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 text-outline absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full pl-10 pr-4 py-2.5 bg-surface-container-lowest border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all placeholder:text-outline"
                  placeholder="admin@gmail.com ou dg@isgi.ga"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-1.5">
                Mot de Passe
              </label>
              <div className="relative">
                <Lock className="w-4 h-4 text-outline absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full pl-10 pr-10 py-2.5 bg-surface-container-lowest border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all placeholder:text-outline"
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-on-surface transition-colors cursor-pointer"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>
          </div>

          {/* Bouton de connexion */}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-primary text-on-primary py-3 rounded-xl font-bold hover:bg-primary/90 transition-all flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed shadow-md cursor-pointer"
          >
            {loading ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                <span>Vérification des droits...</span>
              </>
            ) : (
              <>
                <LogIn className="w-4 h-4" />
                <span>Se connecter en tant qu'Admin Principal</span>
              </>
            )}
          </button>

          {/* Création de compte */}
          <div className="pt-2 border-t border-outline-variant/60 text-center">
            <p className="text-xs text-on-surface-variant mb-2.5">
              Nouvel administrateur ou cadre dirigeant ?
            </p>
            <button
              type="button"
              onClick={() => setIsRegisterOpen(true)}
              className="w-full py-2 px-4 bg-surface-container-lowest hover:bg-surface-container-high text-on-surface font-semibold rounded-xl border border-outline-variant transition-all flex items-center justify-center gap-2 text-xs cursor-pointer"
            >
              <UserPlus className="w-4 h-4 text-primary" />
              <span>Créer un compte Admin Principal</span>
            </button>
          </div>
        </form>

        {/* Footer légal */}
        <div className="py-3 px-6 bg-surface-container text-center border-t border-outline-variant/50">
          <p className="text-[11px] text-outline font-medium flex items-center justify-center gap-1.5">
            <Building2 className="w-3.5 h-3.5" />
            Institut Supérieur de Gestion et d'Ingénierie • Brazzaville
          </p>
        </div>
      </div>

      {/* Modal d'enregistrement préconfiguré pour rôle admin_principal */}
      <RegisterModal
        isOpen={isRegisterOpen}
        onClose={() => setIsRegisterOpen(false)}
        defaultRole="admin_principal"
        onRegisteredSuccess={(createdEmail) => {
          setEmail(createdEmail);
          setError('');
        }}
      />
    </div>
  );
}
