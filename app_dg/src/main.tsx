import React from 'react';
import ReactDOM from 'react-dom/client';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from './components/layout/Layout';
import ExecutiveDashboardPage from './components/dashboard/ExecutiveDashboardPage';
import { PrevisionsRoiPage } from './components/previsions/PrevisionsRoiPage';
import PaiementsPage from './components/paiements/PaiementsPage';
import { DepensesPage } from './components/depenses/DepensesPage';
import InscriptionsPage from './components/inscriptions/InscriptionsPage';
import { PersonnelPage } from './components/personnel/PersonnelPage';
import { CirculairesAndLocks } from './components/system/CirculairesAndLocks';
import { ExecutiveReports } from './components/rapports/ExecutiveReports';
import { LoginPage } from './components/auth/LoginPage';
import { AuthProvider, useAuth } from './context/AuthContext';
import { autoSyncInit } from './db/sync';
import { seedDatabaseIfEmpty } from './db/db';
import './index.css';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

// Initialiser Dexie et la synchronisation cloud
seedDatabaseIfEmpty();
autoSyncInit();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <HashRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<ProtectedRoute><Layout /></ProtectedRoute>}>
            <Route index element={<ExecutiveDashboardPage />} />
            <Route path="previsions-roi" element={<PrevisionsRoiPage />} />
            <Route path="paiements" element={<PaiementsPage />} />
            <Route path="depenses" element={<DepensesPage />} />
            <Route path="inscriptions" element={<InscriptionsPage />} />
            <Route path="personnel" element={<PersonnelPage />} />
            <Route path="systeme" element={<CirculairesAndLocks />} />
            <Route path="rapports" element={<ExecutiveReports />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </AuthProvider>
    </HashRouter>
  </React.StrictMode>
);
