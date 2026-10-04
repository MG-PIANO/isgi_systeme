import React, { useState } from 'react';
import { 
  UserPlus, 
  X, 
  Mail, 
  Lock, 
  User, 
  Shield, 
  CheckCircle2, 
  AlertCircle, 
  Loader2, 
  Eye, 
  EyeOff,
  Sparkles
} from 'lucide-react';
import { supabase } from '../../db/supabaseClient';

interface RegisterModalProps {
  isOpen: boolean;
  onClose: () => void;
  onRegisteredSuccess?: (createdEmail: string) => void;
  defaultRole?: string;
}

export function RegisterModal({ isOpen, onClose, onRegisteredSuccess, defaultRole = 'dac' }: RegisterModalProps) {
  const [nomComplet, setNomComplet] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState(defaultRole);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    // Validations locales
    if (!nomComplet.trim()) {
      setError('Veuillez saisir votre nom complet.');
      return;
    }

    if (!email.trim() || !email.includes('@')) {
      setError('Veuillez saisir une adresse email valide.');
      return;
    }

    if (password.length < 6) {
      setError('Le mot de passe doit comporter au moins 6 caractères.');
      return;
    }

    if (password !== confirmPassword) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }

    setLoading(true);

    try {
      const cleanEmail = email.trim().toLowerCase();
      const cleanNom = nomComplet.trim();

      // 1. Inscription dans Supabase Auth
      const { data: authData, error: authError } = await supabase.auth.signUp({
        email: cleanEmail,
        password: password,
        options: {
          data: {
            nom_complet: cleanNom,
            role: role
          }
        }
      });

      if (authError) {
        if (authError.message.includes('already registered') || authError.message.includes('already exists')) {
          setError('Cette adresse email est déjà enregistrée. Veuillez vous connecter.');
        } else if (authError.message.includes('rate limit')) {
          setError("Limite temporaire d'envoi d'e-mails atteinte par Supabase. Veuillez désactiver l'option 'Confirm email' dans Supabase > Authentication > Providers > Email pour des inscriptions directes sans restriction.");
        } else {
          setError(authError.message);
        }
        setLoading(false);
        return;
      }

      const userId = authData.user?.id;

      if (userId) {
        // 2. Synchronisation directe dans la table 'utilisateurs'
        const { error: userTableError } = await supabase
          .from('utilisateurs')
          .upsert([{
            id: userId,
            email: cleanEmail,
            nom_complet: cleanNom,
            role: role,
            statut: 'actif',
            date_creation: new Date().toISOString(),
            derniere_connexion: new Date().toISOString()
          }]);

        if (userTableError) {
          console.warn('Avertissement insertion utilisateurs:', userTableError);
        }

        // 3. Synchronisation dans 'user_presences' pour le module Messenger
        const roleLibelles: Record<string, string> = {
          dac: 'Directeur des Affaires Académiques',
          secretariat: 'Secrétariat & Scolarité',
          comptable: 'Service Comptabilité',
          admin: 'Administrateur Général'
        };

        await supabase
          .from('user_presences')
          .upsert([{
            user_id: userId,
            nom_complet: cleanNom,
            role: roleLibelles[role] || role,
            en_ligne: true,
            derniere_connexion: new Date().toISOString(),
            statut_perso: 'Compte actif'
          }]).then(() => {}, () => {});

        // 4. Log dans le journal d'activités
        await supabase
          .from('journal_activites')
          .insert([{
            utilisateur_id: userId,
            utilisateur_nom: cleanNom,
            type_action: 'INSCRIPTION',
            description: `Création de compte (${role}) synchronisée avec Auth et Utilisateurs`
          }]).then(() => {}, () => {});

        setSuccess(true);
      } else {
        setError("L'inscription n'a pas pu renvoyer d'identifiant utilisateur.");
      }
    } catch (err: any) {
      console.error('Erreur inscription:', err);
      setError(err?.message || "Erreur de communication avec le serveur Supabase.");
    } finally {
      setLoading(false);
    }
  };

  const handleFinish = () => {
    onClose();
    if (onRegisteredSuccess) {
      onRegisteredSuccess(email.trim().toLowerCase());
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fade-in">
      <div className="relative w-full max-w-lg bg-surface-container-lowest rounded-3xl shadow-2xl border border-outline-variant overflow-hidden">
        
        {/* Header */}
        <div className="bg-gradient-to-r from-primary to-primary-container p-6 text-on-primary">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2.5 bg-surface-container-lowest/20 backdrop-blur-md rounded-2xl">
                <UserPlus className="w-6 h-6 text-on-primary" />
              </div>
              <div>
                <h2 className="text-xl font-bold tracking-tight">Nouveau Compte Utilisateur</h2>
                <p className="text-xs text-on-primary/80 mt-0.5">Synchronisation automatique avec Supabase Auth & Base de données</p>
              </div>
            </div>
            <button
              onClick={onClose}
              disabled={loading}
              className="p-2 text-on-primary/80 hover:text-on-primary hover:bg-surface-container-lowest/20 rounded-xl transition-all"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="p-6 sm:p-8">
          {success ? (
            <div className="text-center py-6 space-y-4">
              <div className="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 rounded-full flex items-center justify-center mx-auto shadow-inner">
                <CheckCircle2 className="w-10 h-10" />
              </div>
              <div className="space-y-2">
                <h3 className="text-xl font-bold text-on-surface">Compte créé avec succès !</h3>
                <p className="text-sm text-on-surface-variant max-w-sm mx-auto">
                  Le compte pour <strong>{nomComplet}</strong> a été enregistré et synchronisé dans la table <code className="bg-surface-container-high px-1.5 py-0.5 rounded text-xs">utilisateurs</code> et l'authentification Supabase.
                </p>
              </div>

              <div className="pt-4">
                <button
                  type="button"
                  onClick={handleFinish}
                  className="w-full py-3.5 bg-primary text-on-primary font-semibold rounded-xl hover:bg-primary/90 transition-all shadow-md flex items-center justify-center gap-2"
                >
                  <Sparkles className="w-5 h-5" />
                  <span>Se connecter maintenant</span>
                </button>
              </div>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              {error && (
                <div className="p-3.5 bg-error-container text-on-error-container rounded-xl flex items-start gap-2.5 text-xs sm:text-sm">
                  <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5" />
                  <div className="flex-1 font-medium">{error}</div>
                </div>
              )}

              {/* Nom complet */}
              <div>
                <label className="block text-xs font-semibold text-on-surface-variant mb-1.5 uppercase tracking-wider">
                  Nom et Prénom(s)
                </label>
                <div className="relative">
                  <User className="w-5 h-5 text-outline absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    required
                    value={nomComplet}
                    onChange={(e) => setNomComplet(e.target.value)}
                    placeholder="ex: Prof. M. DIALLO"
                    className="w-full pl-11 pr-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                  />
                </div>
              </div>

              {/* Email */}
              <div>
                <label className="block text-xs font-semibold text-on-surface-variant mb-1.5 uppercase tracking-wider">
                  Adresse Email Professionnelle
                </label>
                <div className="relative">
                  <Mail className="w-5 h-5 text-outline absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="dac@isgi-edu.org"
                    className="w-full pl-11 pr-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                  />
                </div>
              </div>

              {/* Rôle */}
              <div>
                <label className="block text-xs font-semibold text-on-surface-variant mb-1.5 uppercase tracking-wider">
                  Rôle / Fonction dans l'Établissement
                </label>
                <div className="relative">
                  <Shield className="w-5 h-5 text-outline absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <select
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                    className="w-full pl-11 pr-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all cursor-pointer font-medium"
                  >
                    <option value="dac">Directeur des Affaires Académiques (DAC)</option>
                    <option value="secretariat">Secrétariat & Scolarité</option>
                    <option value="comptable">Comptabilité & Finances</option>
                    <option value="admin">Administrateur Général (Direction)</option>
                  </select>
                </div>
              </div>

              {/* Mot de passe */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-on-surface-variant mb-1.5 uppercase tracking-wider">
                    Mot de passe
                  </label>
                  <div className="relative">
                    <Lock className="w-5 h-5 text-outline absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                      type={showPassword ? 'text' : 'password'}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="Min. 6 car."
                      className="w-full pl-11 pr-10 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-on-surface"
                    >
                      {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface-variant mb-1.5 uppercase tracking-wider">
                    Confirmation
                  </label>
                  <div className="relative">
                    <Lock className="w-5 h-5 text-outline absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                      type={showPassword ? 'text' : 'password'}
                      required
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      placeholder="Confirmer"
                      className="w-full pl-11 pr-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all"
                    />
                  </div>
                </div>
              </div>

              {/* Submit */}
              <div className="pt-3">
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full py-3 bg-primary text-on-primary font-medium rounded-xl hover:bg-primary/90 transition-all shadow-md flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
                >
                  {loading ? (
                    <>
                      <Loader2 className="w-5 h-5 animate-spin" />
                      <span>Synchronisation Supabase...</span>
                    </>
                  ) : (
                    <>
                      <UserPlus className="w-5 h-5" />
                      <span>Créer et synchroniser le compte</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
