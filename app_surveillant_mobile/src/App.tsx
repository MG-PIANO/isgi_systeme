import React, { useState, useEffect } from 'react';
import { MobileNavbar } from './components/MobileNavbar';
import { MobileScanner } from './components/MobileScanner';
import { ResultOverlay } from './components/ResultOverlay';
import { QuickKeypad } from './components/QuickKeypad';
import { TodayFlowList } from './components/TodayFlowList';
import { initialiserBaseSurveillant, db, getPointagesParDate, synchroniserAvecSupabase } from './db/db';
import { getDateAujourdhui, extraireMatriculeDepuisQR, traiterScanEtudiant } from './services/scanEngine';
import type { Etudiant, PointageAcces, ScanProcessResult } from './types';

export const App: React.FC = () => {
  const [torchOn, setTorchOn] = useState(false);
  const [soundOn, setSoundOn] = useState(true);
  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [heureStr, setHeureStr] = useState('');

  const [resultatScan, setResultatScan] = useState<ScanProcessResult | null>(null);
  const [isKeypadOpen, setIsKeypadOpen] = useState(false);
  const [isListOpen, setIsListOpen] = useState(false);

  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [pointagesJour, setPointagesJour] = useState<PointageAcces[]>([]);

  useEffect(() => {
    // Initialisation base et synchro
    initialiserBaseSurveillant().then(() => {
      chargerDonnees();
    });

    const clock = setInterval(() => {
      const now = new Date();
      setHeureStr(now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));
    }, 1000);

    const onOnline = () => setIsOnline(true);
    const onOffline = () => setIsOnline(false);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);

    return () => {
      clearInterval(clock);
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  const chargerDonnees = async () => {
    try {
      const etuds = await db.etudiants.where('statut').equals('actif').toArray();
      setEtudiants(etuds);
      const points = await getPointagesParDate(getDateAujourdhui());
      setPointagesJour(points);
    } catch {}
  };

  const handleValiderMatriculeKeypad = async (code: string) => {
    setIsKeypadOpen(false);
    const matricule = extraireMatriculeDepuisQR(code);
    const etudiant = await db.etudiants.where('matricule').equalsIgnoreCase(matricule).first();
    if (etudiant) {
      const pointageExistant = pointagesJour.find(p => p.matricule.toLowerCase() === etudiant.matricule.toLowerCase());
      const res = traiterScanEtudiant(etudiant, pointageExistant, { appareil: 'mobile' });
      await db.pointages.put(res.pointage);
      setResultatScan(res);
      chargerDonnees();
    }
  };

  return (
    <div className="h-screen w-screen flex flex-col bg-black text-white overflow-hidden">
      {/* Barre Supérieure Mobile */}
      <MobileNavbar
        torchOn={torchOn}
        toggleTorch={() => setTorchOn(!torchOn)}
        hasTorch={true}
        soundOn={soundOn}
        toggleSound={() => setSoundOn(!soundOn)}
        switchCamera={() => setFacingMode(prev => prev === 'environment' ? 'user' : 'environment')}
        isOnline={isOnline}
        heureStr={heureStr}
      />

      {/* Écran Caméra Plein Écran */}
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
          onRafraichir={() => {
            synchroniserAvecSupabase().then(chargerDonnees);
          }}
        />
      )}
    </div>
  );
};

export default App;
