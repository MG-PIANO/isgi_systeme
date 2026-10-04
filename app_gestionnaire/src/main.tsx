import React from 'react'
import ReactDOM from 'react-dom/client'
import { HashRouter, Routes, Route } from 'react-router-dom'
import { Layout } from './components/layout/Layout'
import DashboardPage from './components/dashboard/DashboardPage'
import InscriptionsPage from './components/inscriptions/InscriptionsPage'
import PaiementsPage from './components/paiements/PaiementsPage'
import HistoriquePaiementsPage from './components/paiements/HistoriquePaiementsPage'
import SuiviMensuelPage from './components/paiements/SuiviMensuelPage'
import { SuiviEtudiantsPage } from './components/suivi/SuiviEtudiantsPage'
import { FinancesPage } from './components/finances/FinancesPage'
import { MessengerPage } from './components/messenger/MessengerPage'
import { EmploiDuTempsConsultationPage } from './components/emploi_du_temps/EmploiDuTempsConsultationPage'
import { CalendrierConsultationPage } from './components/calendrier/CalendrierConsultationPage'
import { AuthProvider, useAuth } from './context/AuthContext'
import { LoginPage } from './components/auth/LoginPage'
import { Navigate } from 'react-router-dom'
import { autoSyncInit } from './db/sync'
import './index.css'

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

autoSyncInit();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <HashRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<ProtectedRoute><Layout /></ProtectedRoute>}>
            <Route index element={<DashboardPage />} />
            <Route path="messenger" element={<MessengerPage />} />
            <Route path="emploi-du-temps" element={<EmploiDuTempsConsultationPage />} />
            <Route path="calendrier" element={<CalendrierConsultationPage />} />
            <Route path="inscriptions" element={<InscriptionsPage />} />
            <Route path="paiements" element={<PaiementsPage />} />
            <Route path="historique-paiements" element={<HistoriquePaiementsPage />} />
            <Route path="suivi-mensuel" element={<SuiviMensuelPage />} />
            <Route path="suivi-etudiants" element={<SuiviEtudiantsPage />} />
            <Route path="finances" element={<FinancesPage />} />
          </Route>
        </Routes>
      </AuthProvider>
    </HashRouter>
  </React.StrictMode>,
)
