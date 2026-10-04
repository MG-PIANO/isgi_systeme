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

export function App() {
  const [currentUser, setCurrentUser] = useState<any>(null);
  const [currentTab, setCurrentTab] = useState<string>('dashboard');
  const [initializing, setInitializing] = useState<boolean>(true);

  useEffect(() => {
    // Vérifier session utilisateur
    try {
      const savedUser = localStorage.getItem('dac_user');
      if (savedUser) {
        setCurrentUser(JSON.parse(savedUser));
      }
    } catch {}

    // Synchronisation en tâche de fond avec Supabase
    syncFromSupabase().finally(() => {
      setInitializing(false);
    });
  }, []);

  const handleLogout = () => {
    localStorage.removeItem('dac_user');
    setCurrentUser(null);
  };

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
