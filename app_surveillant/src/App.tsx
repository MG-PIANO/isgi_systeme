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
import { initialiserBaseSurveillant, db } from './db/db';
import { getDateAujourdhui } from './services/scanEngine';

export const App: React.FC = () => {
  const [activeTab, setActiveTab] = useState<TabType>('dashboard');
  const [countSurSite, setCountSurSite] = useState(0);

  useEffect(() => {
    // Initialiser la base locale au démarrage
    initialiserBaseSurveillant().then(() => {
      mettreAJourCompteurSurSite();
    });

    const interval = setInterval(mettreAJourCompteurSurSite, 4000);
    return () => clearInterval(interval);
  }, []);

  const mettreAJourCompteurSurSite = async () => {
    try {
      const dateJour = getDateAujourdhui();
      const count = await db.pointages
        .where('date_jour')
        .equals(dateJour)
        .filter(p => p.statut_actuel === 'sur_site')
        .count();
      setCountSurSite(count);
    } catch {
      // Ignorer
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-blue-600 selection:text-white">
      {/* Barre supérieure */}
      <Navbar />

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
          {activeTab === 'scanner' && <ScannerPage />}
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
