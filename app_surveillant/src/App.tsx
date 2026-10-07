import React, { useState, useEffect } from 'react';
import { Navbar } from './components/layout/Navbar';
import { Sidebar, type TabType } from './components/layout/Sidebar';
import { DashboardPage } from './components/dashboard/DashboardPage';
import { ScannerPage } from './components/scanner/ScannerPage';
import { RegistrePage } from './components/registre/RegistrePage';
import { BadgesPage } from './components/badges/BadgesPage';
import { SallesPage } from './components/salles/SallesPage';
import { EmploiDuTempsPage } from './components/emploi_du_temps/EmploiDuTempsPage';
import { ParametresPage } from './components/parametres/ParametresPage';
import { LoginPage } from './components/auth/LoginPage';
import { initialiserBaseSurveillant, db } from './db/db';
import { supabase } from './db/supabaseClient';
import { getDateAujourdhui } from './services/scanEngine';

interface SurveillantUser {
  id: string;
  nom: string;
  role: string;
}

export const App: React.FC = () => {
  const [activeTab, setActiveTab] = useState<TabType>('dashboard');
  const [countSurSite, setCountSurSite] = useState(0);
  const [currentUser, setCurrentUser] = useState<SurveillantUser | null>(null);
  const [initializing, setInitializing] = useState(true);
  const [authError, setAuthError] = useState('');

  useEffect(() => {
    let mounted = true;
    const restoreSession = async () => {
      try {
        const { data, error: sessionError } = await supabase.auth.getSession();
        if (sessionError) throw sessionError;
        if (!data.session) return;

        const { data: profile, error: profileError } = await supabase
          .from('utilisateurs')
          .select('id, nom_complet, role, statut')
          .eq('id', data.session.user.id)
          .maybeSingle();

        if (profileError) throw profileError;
        if (!profile || profile.statut !== 'actif' || !['surveillant', 'admin', 'admin_principal'].includes(profile.role)) {
          const { error: signOutError } = await supabase.auth.signOut();
          if (signOutError) console.error('Déconnexion du compte non autorisé impossible:', signOutError);
          if (mounted) setAuthError("Accès refusé. Connectez-vous avec un compte Surveillant actif.");
          return;
        }

        if (mounted) {
          setCurrentUser({
            id: profile.id,
            nom: profile.nom_complet || 'Surveillant Général',
            role: profile.role,
          });
        }
      } catch (error) {
        console.error('Restauration de la session Surveillant impossible:', error);
        if (mounted) setAuthError('Impossible de vérifier la session. Réessayez de vous connecter.');
      } finally {
        if (mounted) setInitializing(false);
      }
    };

    const { data: authListener } = supabase.auth.onAuthStateChange((event) => {
      if (event === 'SIGNED_OUT' && mounted) setCurrentUser(null);
    });
    void restoreSession();

    return () => {
      mounted = false;
      authListener.subscription.unsubscribe();
    };
  }, []);

  useEffect(() => {
    if (!currentUser) return;
    let mounted = true;

    const refreshOnSiteCount = async () => {
      try {
        const dateJour = getDateAujourdhui();
        const count = await db.pointages
          .where('date_jour')
          .equals(dateJour)
          .filter((pointage) => pointage.statut_actuel === 'sur_site')
          .count();
        if (mounted) setCountSurSite(count);
      } catch (error) {
        console.error('Mise à jour du compteur des étudiants sur site impossible:', error);
      }
    };

    void initialiserBaseSurveillant().then(refreshOnSiteCount).catch((error: unknown) => {
      console.error('Initialisation des données du Surveillant impossible:', error);
      if (mounted) {
        setAuthError('Impossible de préparer les données locales du poste de surveillance.');
        setCurrentUser(null);
      }
    });
    const interval = setInterval(() => { void refreshOnSiteCount(); }, 4000);

    return () => {
      mounted = false;
      clearInterval(interval);
    };
  }, [currentUser]);

  const handleLoginSuccess = (user: SurveillantUser) => {
    setAuthError('');
    setCurrentUser(user);
  };

  const handleLogout = async () => {
    const { error } = await supabase.auth.signOut();
    if (error) {
      console.error('Déconnexion Supabase impossible:', error);
      setAuthError('La déconnexion a échoué. Réessayez.');
      return;
    }
    setCurrentUser(null);
    setActiveTab('dashboard');
  };

  if (initializing) {
    return <div className="min-h-screen grid place-items-center bg-slate-950 text-slate-300">Vérification de la session…</div>;
  }

  if (!currentUser) {
    return <LoginPage initialError={authError} onLoginSuccess={handleLoginSuccess} />;
  }

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-blue-600 selection:text-white">
      {/* Barre supérieure */}
      <Navbar surveillantNom={currentUser.nom} onLogout={handleLogout} />

      {/* Conteneur principal (Sidebar + Contenu) */}
      <div className="flex-1 flex overflow-hidden">
        {/* Barre latérale */}
        <Sidebar 
          activeTab={activeTab} 
          setActiveTab={setActiveTab} 
          countSurSite={countSurSite}
        />

        {/* Zone de contenu principale */}
        <main className="flex-1 overflow-y-auto bg-slate-950/60 pb-12">
          {activeTab === 'dashboard' && (
            <DashboardPage 
              onOuvrirScanner={() => setActiveTab('scanner')} 
              onVoirRegistre={() => setActiveTab('registre')} 
            />
          )}
          {activeTab === 'scanner' && <ScannerPage surveillantId={currentUser.id} surveillantNom={currentUser.nom} />}
          {activeTab === 'registre' && <RegistrePage />}
          {activeTab === 'badges' && <BadgesPage />}
          {activeTab === 'salles' && <SallesPage />}
          {activeTab === 'edt' && <EmploiDuTempsPage />}
          {activeTab === 'parametres' && <ParametresPage />}
        </main>
      </div>
    </div>
  );
};

export default App;
