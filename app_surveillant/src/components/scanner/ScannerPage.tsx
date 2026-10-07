import React, { useState, useEffect, useRef } from 'react';
import { 
  QrCode, 
  Search, 
  Camera, 
  CameraOff, 
  CheckCircle2, 
  AlertTriangle, 
  Volume2, 
  VolumeX, 
  User, 
  ArrowRightLeft, 
  Clock, 
  Sparkles,
  RefreshCw,
  Zap,
  Building2,
  LogOut
} from 'lucide-react';
import { db, sauvegarderPointage, getPointageEtudiantJour } from '../../db/db';
import { extraireMatriculeDepuisQR, traiterScanEtudiant } from '../../services/scanEngine';
import type { Etudiant, PointageAcces, ScanProcessResult } from '../../types';
import { formaterHeure } from '../../lib/utils';

interface ScannerPageProps {
  surveillantId: string;
  surveillantNom: string;
}

export const ScannerPage: React.FC<ScannerPageProps> = ({ surveillantId, surveillantNom }) => {
  const [saisieMatricule, setSaisieMatricule] = useState('');
  const [etudiantsList, setEtudiantsList] = useState<Etudiant[]>([]);
  const [resultatDernierScan, setResultatDernierScan] = useState<ScanProcessResult | null>(null);
  const [cameraActive, setCameraActive] = useState(false);
  const [sonActif, setSonActif] = useState(true);
  const [messageErreur, setMessageErreur] = useState<string | null>(null);
  const [heureLimite, setHeureLimite] = useState('08:15');

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);

  // Charger les étudiants et paramètres
  useEffect(() => {
    const charger = async () => {
      const etuds = await db.etudiants.where('statut').equals('actif').toArray();
      setEtudiantsList(etuds);

      const paramHeure = await db.parametres.get('heure_limite_arrivee');
      if (paramHeure) setHeureLimite(paramHeure.valeur);
    };
    charger();

    // Mettre l'autofocus sur le champ de scan
    inputRef.current?.focus();
  }, []);

  // Émission d'un son synthétique agréable (Web Audio API)
  const jouerBip = (type: 'arrivee' | 'retard' | 'tour' | 'erreur') => {
    if (!sonActif) return;
    try {
      const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      if (type === 'arrivee') {
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        osc.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.08); // A5
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.25);
      } else if (type === 'retard') {
        osc.frequency.setValueAtTime(440, audioCtx.currentTime);
        osc.frequency.setValueAtTime(349.23, audioCtx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.3);
      } else if (type === 'tour') {
        osc.frequency.setValueAtTime(659.25, audioCtx.currentTime); // E5
        osc.frequency.setValueAtTime(523.25, audioCtx.currentTime + 0.08); // C5
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.22);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.22);
      } else {
        osc.frequency.setValueAtTime(220, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.2);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.2);
      }
    } catch {
      // Ignorer si audio restreint
    }
  };

  // Traiter un scan (qu'il vienne d'une douchette, QR code ou saisie manuelle)
  const executerScan = async (codeBrut: string) => {
    setMessageErreur(null);
    const matricule = extraireMatriculeDepuisQR(codeBrut);
    if (!matricule) {
      setMessageErreur('Code non reconnu ou matricule introuvable.');
      jouerBip('erreur');
      return;
    }

    // Recherche de l'étudiant
    const etudiant = await db.etudiants
      .where('matricule')
      .equalsIgnoreCase(matricule)
      .first();

    if (!etudiant) {
      setMessageErreur(`Aucun étudiant trouvé avec le matricule : ${matricule}`);
      jouerBip('erreur');
      return;
    }

    // Récupérer son pointage existant pour aujourd'hui
    const pointageExistant = await getPointageEtudiantJour(etudiant.matricule);

    // Appliquer le moteur logique
    const resultat = traiterScanEtudiant(etudiant, pointageExistant, {
      appareil: 'pc',
      surveillantId,
      surveillantNom,
      heureLimiteArrivee: heureLimite
    });

    // Sauvegarder dans Dexie et synchroniser avec Supabase
    await sauvegarderPointage(resultat.pointage);

    // Mettre à jour l'affichage
    setResultatDernierScan(resultat);
    setSaisieMatricule('');

    // Son de retour
    if (resultat.action === 'arrivee') {
      jouerBip(resultat.estEnRetard ? 'retard' : 'arrivee');
    } else {
      jouerBip('tour');
    }

    // Refocus automatique pour le prochain scan
    inputRef.current?.focus();
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (saisieMatricule.trim()) {
      executerScan(saisieMatricule.trim());
    }
  };

  // Gestion Caméra Webcam
  const toggleCamera = async () => {
    if (cameraActive) {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
        streamRef.current = null;
      }
      setCameraActive(false);
    } else {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        streamRef.current = stream;
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          videoRef.current.play();
        }
        setCameraActive(true);
      } catch (err) {
        setMessageErreur('Impossible d\'accéder à la webcam ou caméra.');
      }
    }
  };

  // Nettoyage caméra au démontage
  useEffect(() => {
    return () => {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
      }
    };
  }, []);

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-2xl font-extrabold text-white font-heading">
              Poste de Scan & Contrôle d'Accès
            </h2>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Prêt pour scan
            </span>
          </div>
          <p className="text-sm text-slate-400 mt-1">
            Compatible douchette code-barres USB, caméra webcam et saisie manuelle rapide.
          </p>
        </div>

        {/* Contrôles Son & Caméra */}
        <div className="flex items-center gap-3">
          <button
            onClick={() => setSonActif(!sonActif)}
            className={`p-2.5 rounded-xl border transition-all ${
              sonActif 
                ? 'bg-blue-600/20 text-blue-400 border-blue-500/30 hover:bg-blue-600/30' 
                : 'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700'
            }`}
            title={sonActif ? 'Bip sonore actif' : 'Bip sonore coupé'}
          >
            {sonActif ? <Volume2 className="w-5 h-5" /> : <VolumeX className="w-5 h-5" />}
          </button>

          <button
            onClick={toggleCamera}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm border transition-all ${
              cameraActive
                ? 'bg-rose-500/20 text-rose-400 border-rose-500/30 hover:bg-rose-500/30'
                : 'bg-slate-800 text-slate-300 border-slate-700 hover:bg-slate-700 hover:text-white'
            }`}
          >
            {cameraActive ? (
              <>
                <CameraOff className="w-4 h-4" />
                <span>Couper Webcam</span>
              </>
            ) : (
              <>
                <Camera className="w-4 h-4" />
                <span>Activer Webcam</span>
              </>
            )}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne GAUCHE : Zone de Scan & Caméra */}
        <div className="lg:col-span-6 space-y-6">
          {/* Formulaire de Saisie / Douchette */}
          <div className="p-6 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-4">
            <div className="flex items-center justify-between">
              <label htmlFor="scan-input" className="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                <QrCode className="w-4 h-4 text-blue-400" />
                <span>Lecture Douchette ou Saisie Matricule</span>
              </label>
              <span className="text-[11px] text-slate-400">Touche [Entrée] pour valider</span>
            </div>

            <form onSubmit={handleSubmit} className="flex gap-2">
              <div className="relative flex-1">
                <Search className="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2" />
                <input
                  id="scan-input"
                  ref={inputRef}
                  type="text"
                  value={saisieMatricule}
                  onChange={(e) => setSaisieMatricule(e.target.value)}
                  placeholder="Scannez ou tapez le matricule (ex: ISGI-2025-00019)..."
                  className="w-full pl-12 pr-4 py-3.5 bg-slate-900 border-2 border-slate-700 focus:border-blue-500 rounded-xl text-white placeholder-slate-500 text-sm font-mono tracking-wide focus:outline-none focus:ring-4 focus:ring-blue-500/20 transition-all shadow-inner"
                  autoFocus
                />
              </div>

              <button
                type="submit"
                disabled={!saisieMatricule.trim()}
                className="px-6 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-50 disabled:pointer-events-none text-white font-bold text-sm shadow-lg shadow-blue-600/30 transition-all shrink-0"
              >
                Valider Scan
              </button>
            </form>

            {messageErreur && (
              <div className="p-3.5 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2.5 animate-shake">
                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-400" />
                <span>{messageErreur}</span>
              </div>
            )}

            {/* Test Rapide / Démo */}
            <div className="pt-2 border-t border-slate-700/50">
              <div className="text-[11px] font-semibold text-slate-400 mb-2 flex items-center gap-1.5">
                <Zap className="w-3.5 h-3.5 text-amber-400" />
                <span>Scans de test rapide (1-Clic) :</span>
              </div>
              <div className="flex flex-wrap gap-2">
                {etudiantsList.slice(0, 4).map((etud) => (
                  <button
                    key={etud.id}
                    onClick={() => executerScan(etud.matricule)}
                    className="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-700 border border-slate-700 hover:border-blue-500/50 text-xs text-slate-300 transition-all flex items-center gap-1.5"
                  >
                    <span className="font-mono text-blue-400">{etud.matricule}</span>
                    <span className="text-slate-400">({etud.nom})</span>
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* Flux Vidéo Webcam (si activée) */}
          {cameraActive && (
            <div className="p-4 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-3">
              <div className="flex items-center justify-between text-xs text-slate-300">
                <span className="font-semibold flex items-center gap-1.5">
                  <Camera className="w-4 h-4 text-emerald-400" />
                  Visée Caméra active
                </span>
                <span className="text-slate-400">Présentez le badge devant l'objectif</span>
              </div>
              <div className="relative aspect-video rounded-xl overflow-hidden bg-black border border-slate-700 flex items-center justify-center">
                <video ref={videoRef} autoPlay playsInline muted className="w-full h-full object-cover" />
                {/* Cadre de visée */}
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                  <div className="w-48 h-48 border-2 border-emerald-400/80 rounded-2xl shadow-[0_0_20px_rgba(52,211,153,0.3)] animate-pulse"></div>
                </div>
              </div>
            </div>
          )}

          {/* Rappel Visuel de la Règle Métier */}
          <div className="p-5 rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/90 border border-slate-700/60 shadow-lg space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-indigo-400" />
              <span>Règle officielle des scans journaliers</span>
            </h4>
            <div className="grid grid-cols-3 gap-2.5 text-center text-xs">
              <div className="p-3 rounded-xl bg-slate-900/80 border border-emerald-500/30">
                <div className="font-bold text-emerald-400 mb-1">1er Scan</div>
                <div className="text-[11px] text-slate-300">Heure d'arrivée</div>
                <div className="text-[10px] text-slate-500 mt-1">À l'heure / Retard</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-900/80 border border-amber-500/30">
                <div className="font-bold text-amber-400 mb-1">Scans Suivants</div>
                <div className="text-[11px] text-slate-300">Tours & Sorties</div>
                <div className="text-[10px] text-slate-500 mt-1">Incrémentation</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-900/80 border border-blue-500/30">
                <div className="font-bold text-blue-400 mb-1">Dernier Scan</div>
                <div className="text-[11px] text-slate-300">Heure de départ</div>
                <div className="text-[10px] text-slate-500 mt-1">Définitif</div>
              </div>
            </div>
          </div>
        </div>

        {/* Colonne DROITE : Grand Écran de Validation Immédiat */}
        <div className="lg:col-span-6">
          {resultatDernierScan ? (
            <div className="p-6 rounded-2xl bg-slate-800/80 border border-slate-700/80 shadow-2xl space-y-6 animate-fadeIn">
              {/* Badge d'action grand format */}
              <div className={`p-4 rounded-xl text-center font-bold text-base flex items-center justify-center gap-2.5 shadow-lg ${
                resultatDernierScan.action === 'arrivee'
                  ? resultatDernierScan.estEnRetard
                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40'
                    : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                  : resultatDernierScan.action === 'sortie_tour'
                  ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
                  : 'bg-blue-500/20 text-blue-300 border border-blue-500/40'
              }`}>
                {resultatDernierScan.action === 'arrivee' ? (
                  resultatDernierScan.estEnRetard ? <AlertTriangle className="w-5 h-5 text-rose-400" /> : <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                ) : resultatDernierScan.action === 'sortie_tour' ? (
                  <LogOut className="w-5 h-5 text-amber-400" />
                ) : (
                  <Building2 className="w-5 h-5 text-blue-400" />
                )}
                <span>{resultatDernierScan.message}</span>
              </div>

              {/* Fiche Étudiant avec Photo */}
              <div className="flex flex-col sm:flex-row items-center gap-5 p-5 rounded-xl bg-slate-900/80 border border-slate-700/60">
                {resultatDernierScan.etudiant.photo_url ? (
                  <img
                    src={resultatDernierScan.etudiant.photo_url}
                    alt={resultatDernierScan.etudiant.nom}
                    className="w-24 h-24 rounded-2xl object-cover border-2 border-blue-500/40 shadow-md"
                  />
                ) : (
                  <div className="w-24 h-24 rounded-2xl bg-slate-800 border-2 border-slate-700 flex items-center justify-center text-slate-400 font-bold text-xl">
                    {resultatDernierScan.etudiant.nom.charAt(0)}{resultatDernierScan.etudiant.prenom.charAt(0)}
                  </div>
                )}

                <div className="text-center sm:text-left space-y-1">
                  <div className="inline-block px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">
                    {resultatDernierScan.etudiant.matricule}
                  </div>
                  <h3 className="text-xl font-extrabold text-white font-heading">
                    {resultatDernierScan.etudiant.nom} {resultatDernierScan.etudiant.prenom}
                  </h3>
                  <div className="text-sm text-slate-300 font-medium">
                    {resultatDernierScan.etudiant.classe_nom || resultatDernierScan.etudiant.filiere}
                  </div>
                  {resultatDernierScan.etudiant.telephone && (
                    <div className="text-xs text-slate-400">
                      Tél : {resultatDernierScan.etudiant.telephone}
                    </div>
                  )}
                </div>
              </div>

              {/* Statuts & Compteurs du jour */}
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">1ère Arrivée</div>
                  <div className="text-base font-bold text-white font-mono mt-0.5">
                    {formaterHeure(resultatDernierScan.pointage.heure_arrivee)}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">
                    {resultatDernierScan.pointage.statut_arrivee === 'a_l_heure' ? (
                      <span className="text-emerald-400">À l'heure</span>
                    ) : (
                      <span className="text-rose-400">En retard</span>
                    )}
                  </div>
                </div>

                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">Tours de Sorties</div>
                  <div className="text-base font-bold text-amber-400 font-mono mt-0.5">
                    {resultatDernierScan.pointage.nombre_tours}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">sorties temporaires</div>
                </div>

                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">Position Actuelle</div>
                  <div className="text-base font-bold font-mono mt-0.5">
                    {resultatDernierScan.pointage.statut_actuel === 'sur_site' ? (
                      <span className="text-emerald-400">Sur Site</span>
                    ) : (
                      <span className="text-amber-400">Sorti</span>
                    )}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">
                    {resultatDernierScan.pointage.heure_depart ? `Départ: ${formaterHeure(resultatDernierScan.pointage.heure_depart)}` : 'Présent'}
                  </div>
                </div>
              </div>

              {/* Timeline chronologique des scans du jour */}
              <div className="space-y-2 pt-2 border-t border-slate-700/50">
                <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                  Historique des scans d'aujourd'hui
                </div>
                <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
                  {resultatDernierScan.pointage.historique_scans.map((log, index) => (
                    <div key={log.id} className="flex items-center justify-between p-2.5 rounded-lg bg-slate-900/50 border border-slate-700/40 text-xs">
                      <div className="flex items-center gap-2">
                        <span className="w-5 h-5 rounded-full bg-slate-800 text-slate-400 font-bold flex items-center justify-center text-[10px]">
                          {index + 1}
                        </span>
                        <span className="font-semibold text-white capitalize">
                          {log.type === 'arrivee' ? '1ère Arrivée' : log.type === 'sortie_tour' ? `Sortie (Tour N° ${log.tour_numero})` : 'Retour sur site'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2 font-mono text-slate-300">
                        <Clock className="w-3.5 h-3.5 text-blue-400" />
                        <span>{formaterHeure(log.timestamp)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          ) : (
            /* État d'attente / Placeholder */
            <div className="h-full min-h-[420px] rounded-2xl bg-slate-800/40 border border-dashed border-slate-700 flex flex-col items-center justify-center p-8 text-center text-slate-400 space-y-3">
              <div className="w-16 h-16 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center justify-center text-slate-500">
                <User className="w-8 h-8" />
              </div>
              <h3 className="text-lg font-bold text-white font-heading">
                En attente d'un scan...
              </h3>
              <p className="text-xs text-slate-400 max-w-sm">
                Scannez un QR code étudiant ou tapez un matricule pour afficher instantanément la fiche et enregistrer l'entrée/sortie.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
