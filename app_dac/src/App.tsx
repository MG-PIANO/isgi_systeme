import React, { useState, useEffect } from 'react';
import { Layout } from './components/layout/Layout';
import { DashboardPage } from './components/dashboard/DashboardPage';
import { EtudiantsPage } from './components/etudiants/EtudiantsPage';
import InscriptionsPage from './components/inscriptions/InscriptionsPage';
import { CartesPage } from './components/cartes/CartesPage';
import { ClassesPage } from './components/classes/ClassesPage';
import { MatieresPage } from './components/matieres/MatieresPage';
import { PresencesPage } from './components/presences/PresencesPage';
import { NotesPage } from './components/notes/NotesPage';
import { ValidationNotesPage } from './components/notes/ValidationNotesPage';
import { BulletinsPage } from './components/bulletins/BulletinsPage';
import { ParametresPage } from './components/parametres/ParametresPage';
import { CalendrierPage } from './components/calendrier/CalendrierPage';
import { EmploiDuTempsPage } from './components/emploi_du_temps/EmploiDuTempsPage';
import { SallesPage } from './components/salles/SallesPage';
import { MessengerPage } from './components/messenger/MessengerPage';
import { SujetsProjetsPage } from './components/sujets/SujetsProjetsPage';
import { VideothequePage } from './components/videotheque/VideothequePage';
import { LoginPage } from './components/auth/LoginPage';
import { syncFromSupabase } from './db/db';
import { supabase } from './db/supabaseClient';
import { AccountRequestsPage } from './components/accounts/AccountRequestsPage';

export function App() {
  const [currentUser, setCurrentUser] = useState<any>(null);
  const [currentTab, setCurrentTab] = useState<string>('dashboard');
  const [initializing, setInitializing] = useState<boolean>(true);

  useEffect(() => {
    let mounted = true;
    const restoreSession = async () => {
      try {
        const { data: { session }, error: sessionError } = await supabase.auth.getSession();
        if (sessionError) throw sessionError;
        if (!session) {
          localStorage.removeItem('dac_user');
          return;
        }
        const { data: profile, error: profileError } = await supabase
          .from('utilisateurs')
          .select('id, nom_complet, role, statut')
          .eq('id', session.user.id)
          .maybeSingle();
        if (profileError) throw profileError;
        if (!profile || profile.statut === 'bloque' || !['dac', 'direction_dac', 'admin', 'admin_principal'].includes(profile.role)) {
          await supabase.auth.signOut();
          localStorage.removeItem('dac_user');
          return;
        }
        const user = {
          id: profile.id,
          nom: profile.nom_complet || 'Direction académique',
          role: profile.role,
        };
        localStorage.setItem('dac_user', JSON.stringify(user));
        await syncFromSupabase();
        if (mounted) setCurrentUser(user);
      } catch (error) {
        console.error('Restauration de la session DAC impossible:', error);
        localStorage.removeItem('dac_user');
      } finally {
        if (mounted) setInitializing(false);
      }
    };
    void restoreSession();
    return () => { mounted = false; };
  }, []);

  const handleLogout = async () => {
    const { error } = await supabase.auth.signOut();
    if (error) console.error('Déconnexion Supabase impossible:', error);
    localStorage.removeItem('dac_user');
    setCurrentUser(null);
  };

  if (initializing) {
    return <div className="min-h-screen grid place-items-center text-on-surface-variant">Vérification de la session…</div>;
  }

  if (!currentUser) {
    return <LoginPage onLoginSuccess={(user) => setCurrentUser(user)} />;
  }

  const renderContent = () => {
    switch (currentTab) {
      case 'dashboard':
        return <DashboardPage onNavigate={(tab) => setCurrentTab(tab)} />;
      case 'messenger':
        return <MessengerPage />;
      case 'sujets':
        return <SujetsProjetsPage />;
      case 'inscriptions':
        return <InscriptionsPage />;
      case 'etudiants':
        return <EtudiantsPage />;
      case 'cartes':
        return <CartesPage />;
      case 'calendrier':
        return <CalendrierPage />;
      case 'emploi_du_temps':
        return <EmploiDuTempsPage />;
      case 'salles':
        return <SallesPage />;
      case 'classes':
        return <ClassesPage />;
      case 'videotheque':
        return <VideothequePage />;
      case 'matieres':
        return <MatieresPage />;
      case 'presences':
        return <PresencesPage />;
      case 'notes':
        return <NotesPage onNavigate={(tab) => setCurrentTab(tab)} />;
      case 'validation_notes':
        return <ValidationNotesPage />;
      case 'bulletins':
        return <BulletinsPage />;
      case 'parametres':
        return <ParametresPage />;
      case 'demandes_comptes':
        return <AccountRequestsPage />;
      default:
        return <DashboardPage onNavigate={(tab) => setCurrentTab(tab)} />;
    }
  };


  return (
    <Layout
      currentTab={currentTab}
      onSelectTab={setCurrentTab}
      onLogout={handleLogout}
    >
      {renderContent()}
    </Layout>
  );
}

export default App;
