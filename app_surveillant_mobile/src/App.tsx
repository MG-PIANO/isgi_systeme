import React, { useState, useEffect } from 'react';
import { MobileNavbar } from './components/MobileNavbar';
import { MobileScanner } from './components/MobileScanner';
import { ResultOverlay } from './components/ResultOverlay';
import { QuickKeypad } from './components/QuickKeypad';
import { TodayFlowList } from './components/TodayFlowList';
import { 
  initialiserBaseSurveillant, 
  db, 
  getPointagesParDate, 
  sauvegarderPointage,
  synchroniserAvecSupabase, 
  synchroniserPointagesEnAttente,
  getNombrePointagesEnAttente 
} from './db/db';
import { getDateAujourdhui, extraireMatriculeDepuisQR, traiterScanEtudiant } from './services/scanEngine';
import type { Etudiant, PointageAcces, ScanProcessResult } from './types';

export const App: React.FC = () => {
  const [torchOn, setTorchOn] = useState(false);
  const [soundOn, setSoundOn] = useState(true);
  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [heureStr, setHeureStr] = useState('');
  const [appPrete, setAppPrete] = useState(false);
  const [erreurInit, setErreurInit] = useState<string | null>(null);

  const [resultatScan, setResultatScan] = useState<ScanProcessResult | null>(null);
  const [isKeypadOpen, setIsKeypadOpen] = useState(false);
  const [isListOpen, setIsListOpen] = useState(false);

  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [pointagesJour, setPointagesJour] = useState<PointageAcces[]>([]);
  const [pendingSyncCount, setPendingSyncCount] = useState(0);
  const [isSyncing, setIsSyncing] = useState(false);

  const majCompteurEnAttente = async () => {
    try {
      const count = await getNombrePointagesEnAttente();
      setPendingSyncCount(count);
    } catch {
      // Ignorer
    }
  };

  const chargerDonnees = async () => {
    try {
      const etuds = await db.etudiants.where('statut').equals('actif').toArray();
      setEtudiants(etuds);
      const points = await getPointagesParDate(getDateAujourdhui());
      setPointagesJour(points);
      await majCompteurEnAttente();
    } catch (err) {
      console.warn('Erreur chargement données:', err);
    }
  };

  const handleForcerSync = async () => {
    setIsSyncing(true);
    try {
      await synchroniserAvecSupabase();
      await chargerDonnees();
    } catch (err) {
      console.warn('Erreur synchronisation manuelle:', err);
    } finally {
      setIsSyncing(false);
    }
  };

  useEffect(() => {
    // Initialisation base locale Dexie et synchro Supabase (avec tolérance hors-ligne)
    initialiserBaseSurveillant()
      .then(() => {
        chargerDonnees();
        setAppPrete(true);
      })
      .catch((err) => {
        console.error('Erreur initialisation:', err);
        setErreurInit('Connexion hors-ligne. Données locales utilisées.');
        setAppPrete(true);
      });

    // Horloge temps réel
    const now = new Date();
    setHeureStr(now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));

    const clock = setInterval(() => {
      const n = new Date();
      setHeureStr(n.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));
    }, 1000);

    // Détection automatique du retour d'internet : synchronise immédiatement les scans en attente
    const onOnline = () => {
      setIsOnline(true);
      synchroniserPointagesEnAttente().then(() => {
        chargerDonnees();
      });
    };

    const onOffline = () => {
      setIsOnline(false);
    };

    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);

    // Sync périodique en arrière-plan toutes les 25 secondes si en ligne
    const periodicSync = setInterval(() => {
      if (navigator.onLine) {
        synchroniserPointagesEnAttente().then(() => {
          majCompteurEnAttente();
        });
      }
    }, 25000);

    return () => {
      clearInterval(clock);
      clearInterval(periodicSync);
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  const handleValiderMatriculeKeypad = async (code: string) => {
    setIsKeypadOpen(false);
    const matricule = extraireMatriculeDepuisQR(code);
    try {
      const etudiant = await db.etudiants.where('matricule').equalsIgnoreCase(matricule).first();
      if (etudiant) {
        const pointageExistant = pointagesJour.find(p => p.matricule.toLowerCase() === etudiant.matricule.toLowerCase());
        const res = traiterScanEtudiant(etudiant, pointageExistant, { appareil: 'mobile' });
        await sauvegarderPointage(res.pointage);
        setResultatScan(res);
        await chargerDonnees();
      }
    } catch (err) {
      console.warn('Erreur validation matricule:', err);
    }
  };

  return (
    <div className="h-screen w-screen flex flex-col bg-black text-white overflow-hidden select-none">
      {/* Barre Supérieure Mobile avec indicateurs offline queue et synchro */}
      <MobileNavbar
        torchOn={torchOn}
        toggleTorch={() => setTorchOn(!torchOn)}
        hasTorch={true}
        soundOn={soundOn}
        toggleSound={() => setSoundOn(!soundOn)}
        switchCamera={() => setFacingMode(prev => prev === 'environment' ? 'user' : 'environment')}
        isOnline={isOnline}
        heureStr={heureStr}
        pendingSyncCount={pendingSyncCount}
        onSyncClick={handleForcerSync}
        isSyncing={isSyncing}
      />

      {/* Écran Caméra Plein Écran avec décodeur universel jsQR */}
      <MobileScanner
        onScanResultat={(res) => {
          setResultatScan(res);
          chargerDonnees();
        }}
        onOuvrirClavier={() => setIsKeypadOpen(true)}
        onOuvrirListe={() => setIsListOpen(true)}
        soundOn={soundOn}
        facingMode={facingMode}
        torchOn={torchOn}
      />

      {/* Overlay Résultat Scan Immédiat */}
      {resultatScan && (
        <ResultOverlay
          resultat={resultatScan}
          onFermer={() => setResultatScan(null)}
        />
      )}

      {/* Modal Pavé Tactile Matricule */}
      {isKeypadOpen && (
        <QuickKeypad
          etudiants={etudiants}
          onValiderMatricule={handleValiderMatriculeKeypad}
          onFermer={() => setIsKeypadOpen(false)}
        />
      )}

      {/* Modal Liste des Flux du Jour */}
      {isListOpen && (
        <TodayFlowList
          pointages={pointagesJour}
          onFermer={() => setIsListOpen(false)}
          onRafraichir={handleForcerSync}
        />
      )}
    </div>
  );
};

export default App;
