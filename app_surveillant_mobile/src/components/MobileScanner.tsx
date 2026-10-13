import React, { useState, useEffect, useRef } from 'react';
import jsQR from 'jsqr';
import { 
  QrCode, 
  Keyboard, 
  ListFilter, 
  Zap, 
  Camera, 
  AlertCircle,
  Building2,
  UserCheck
} from 'lucide-react';
import { db, sauvegarderPointage, getPointageEtudiantJour } from '../db/db';
import { extraireMatriculeDepuisQR, traiterScanEtudiant } from '../services/scanEngine';
import type { Etudiant, PointageAcces, ScanProcessResult } from '../types';

interface MobileScannerProps {
  onScanResultat: (resultat: ScanProcessResult) => void;
  onOuvrirClavier: () => void;
  onOuvrirListe: () => void;
  soundOn: boolean;
  facingMode: 'environment' | 'user';
  torchOn: boolean;
}

export const MobileScanner: React.FC<MobileScannerProps> = ({
  onScanResultat,
  onOuvrirClavier,
  onOuvrirListe,
  soundOn,
  facingMode,
  torchOn
}) => {
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [messageErreur, setMessageErreur] = useState<string | null>(null);
  const [cameraPrete, setCameraPrete] = useState(false);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const animFrameRef = useRef<number | null>(null);

  useEffect(() => {
    const charger = async () => {
      const data = await db.etudiants.where('statut').equals('actif').toArray();
      setEtudiants(data);
    };
    charger();
  }, []);

  // Bip & Vibration mobile
  const vibrerEtBiper = (type: 'succes' | 'erreur') => {
    // Vibration haptique Android
    if (navigator.vibrate) {
      if (type === 'succes') navigator.vibrate([80, 50, 80]);
      else navigator.vibrate([200]);
    }

    if (!soundOn) return;
    try {
      const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      if (type === 'succes') {
        osc.frequency.setValueAtTime(659.25, audioCtx.currentTime); // E5
        osc.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.08); // A5
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.25);
      } else {
        osc.frequency.setValueAtTime(220, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.2);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.2);
      }
    } catch {}
  };

  const scanCooldownRef = useRef<{ code: string; timestamp: number }>({ code: '', timestamp: 0 });

  // Traitement d'un matricule
  const traiterMatricule = async (codeBrut: string) => {
    const matricule = extraireMatriculeDepuisQR(codeBrut);
    if (!matricule) {
      return;
    }

    // Protection anti-rebond (ignore si même matricule scanné il y a moins de 3 secondes)
    const now = Date.now();
    if (scanCooldownRef.current.code === matricule && (now - scanCooldownRef.current.timestamp) < 3000) {
      return;
    }
    scanCooldownRef.current = { code: matricule, timestamp: now };

    setMessageErreur(null);

    const etudiant = await db.etudiants
      .where('matricule')
      .equalsIgnoreCase(matricule)
      .first();

    if (!etudiant) {
      setMessageErreur(`Étudiant introuvable (${matricule})`);
      vibrerEtBiper('erreur');
      return;
    }

    const pointageExistant = await getPointageEtudiantJour(etudiant.matricule);
    const paramHeure = await db.parametres.get('heure_limite_arrivee');
    const heureLimite = paramHeure ? paramHeure.valeur : '08:15';

    const resultat = traiterScanEtudiant(etudiant, pointageExistant, {
      appareil: 'mobile',
      surveillantNom: 'Surveillant Mobile',
      heureLimiteArrivee: heureLimite
    });

    await sauvegarderPointage(resultat.pointage);
    vibrerEtBiper('succes');
    onScanResultat(resultat);
  };

  // Initialisation de la Caméra et Détection QR Code
  useEffect(() => {
    let actif = true;
    let scanInterval: any = null;

    const demarrerCamera = async () => {
      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          console.warn('API Camera non disponible dans cet environnement');
          setCameraPrete(false);
          return;
        }

        if (streamRef.current) {
          streamRef.current.getTracks().forEach(t => t.stop());
        }

        const stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode,
            width: { ideal: 1280 },
            height: { ideal: 720 }
          }
        });

        if (!actif) {
          stream.getTracks().forEach(t => t.stop());
          return;
        }

        streamRef.current = stream;

        // Gestion de la torche
        const track = stream.getVideoTracks()[0];
        if (track && torchOn) {
          try {
            await (track as any).applyConstraints({ advanced: [{ torch: true }] });
          } catch {}
        }

        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
          setCameraPrete(true);

          // Détection automatique universelle continue (jsQR + accélération native si disponible)
          let lastScanTime = 0;
          let detector: any = null;

          if (typeof (window as any).BarcodeDetector !== 'undefined') {
            try {
              detector = new (window as any).BarcodeDetector({ formats: ['qr_code', 'code_128', 'ean_13'] });
            } catch {}
          }

          const scanFrame = async () => {
            if (!actif) return;

            const now = performance.now();
            if (now - lastScanTime > 120) { // ~8 scans/sec: réactivité instantanée et économie batterie
              lastScanTime = now;
              const video = videoRef.current;

              if (video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
                let codeTrouve: string | null = null;

                // 1. Essai avec BarcodeDetector natif si supporté
                if (detector) {
                  try {
                    const codes = await detector.detect(video);
                    if (codes && codes.length > 0 && codes[0]?.rawValue) {
                      codeTrouve = codes[0].rawValue;
                    }
                  } catch {}
                }

                // 2. Décodeur universel jsQR (fonctionne à 100% sur TOUS les navigateurs mobiles et desktop, même sans BarcodeDetector)
                if (!codeTrouve) {
                  try {
                    if (!canvasRef.current) {
                      canvasRef.current = document.createElement('canvas');
                    }
                    const canvas = canvasRef.current;
                    // Résolution adaptée pour détection en temps réel rapide
                    const w = Math.min(video.videoWidth, 640);
                    const h = Math.round((w / video.videoWidth) * video.videoHeight);
                    canvas.width = w;
                    canvas.height = h;

                    const ctx = canvas.getContext('2d', { willReadFrequently: true });
                    if (ctx) {
                      ctx.drawImage(video, 0, 0, w, h);
                      const imageData = ctx.getImageData(0, 0, w, h);
                      const qrResult = jsQR(imageData.data, w, h, { inversionAttempts: 'dontInvert' });
                      if (qrResult && qrResult.data) {
                        codeTrouve = qrResult.data;
                      }
                    }
                  } catch (scanErr) {
                    // Erreur frame ponctuelle ignorée
                  }
                }

                if (codeTrouve) {
                  traiterMatricule(codeTrouve);
                }
              }
            }

            if (actif) {
              animFrameRef.current = requestAnimationFrame(scanFrame);
            }
          };

          animFrameRef.current = requestAnimationFrame(scanFrame);
        }
      } catch (e) {
        console.warn('Accès caméra refusé ou non supporté:', e);
        setCameraPrete(false);
      }
    };

    demarrerCamera();

    return () => {
      actif = false;
      if (animFrameRef.current) {
        cancelAnimationFrame(animFrameRef.current);
      }
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(t => t.stop());
      }
    };
  }, [facingMode, torchOn]);

  return (
    <div className="relative flex-1 flex flex-col justify-between overflow-hidden bg-black select-none">
      {/* Flux Vidéo de la Caméra en Plein Écran */}
      <div className="absolute inset-0 z-0">
        <video
          ref={videoRef}
          autoPlay
          playsInline
          muted
          className="w-full h-full object-cover"
        />
        {/* Voile sombre transparent avec trou de viseur */}
        <div className="absolute inset-0 bg-slate-950/40"></div>
      </div>

      {/* Viseur au Centre avec Animation Laser */}
      <div className="relative z-10 my-auto flex flex-col items-center justify-center p-6 pointer-events-none">
        <div className="relative w-64 h-64 border-2 border-blue-400/70 rounded-3xl overflow-hidden shadow-[0_0_35px_rgba(59,130,246,0.4)]">
          {/* Coins stylisés */}
          <div className="absolute top-2 left-2 w-5 h-5 border-t-4 border-l-4 border-white rounded-tl-lg"></div>
          <div className="absolute top-2 right-2 w-5 h-5 border-t-4 border-r-4 border-white rounded-tr-lg"></div>
          <div className="absolute bottom-2 left-2 w-5 h-5 border-b-4 border-l-4 border-white rounded-bl-lg"></div>
          <div className="absolute bottom-2 right-2 w-5 h-5 border-b-4 border-r-4 border-white rounded-br-lg"></div>

          {/* Ligne Laser Mobile Animée */}
          <div className="absolute left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-red-500 to-transparent shadow-[0_0_12px_#ef4444] animate-scan-laser"></div>
        </div>

        <p className="mt-4 text-xs font-bold text-white bg-slate-900/80 px-4 py-1.5 rounded-full border border-slate-700/80 backdrop-blur-md shadow-lg flex items-center gap-1.5">
          <QrCode className="w-3.5 h-3.5 text-blue-400" />
          <span>Cadrez le QR Code du badge</span>
        </p>
      </div>

      {/* Erreur éventuelle */}
      {messageErreur && (
        <div className="relative z-20 mx-4 mb-2 p-3 rounded-xl bg-rose-600/90 text-white text-xs font-bold flex items-center justify-between shadow-xl">
          <span>{messageErreur}</span>
          <button onClick={() => setMessageErreur(null)} className="underline text-[10px]">OK</button>
        </div>
      )}

      {/* Barre d'actions Inférieure (Grosses touches adaptées aux doigts) */}
      <div className="relative z-20 p-4 space-y-3 bg-gradient-to-t from-slate-950 via-slate-950/90 to-transparent pb-safe">
        {/* Raccourcis Tests Rapides (1-Tap) */}
        <div className="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar">
          <span className="text-[10px] font-bold text-slate-400 shrink-0 flex items-center gap-1">
            <Zap className="w-3 h-3 text-amber-400" /> Test :
          </span>
          {etudiants.slice(0, 4).map((e) => (
            <button
              key={e.id}
              onClick={() => traiterMatricule(e.matricule)}
              className="px-2.5 py-1 rounded-lg bg-slate-900/90 border border-slate-700/80 active:bg-blue-600 active:text-white text-[11px] font-mono text-blue-300 shrink-0 shadow"
            >
              {e.nom}
            </button>
          ))}
        </div>

        {/* 2 Gros Boutons d'Action Principale */}
        <div className="grid grid-cols-2 gap-3">
          <button
            onClick={onOuvrirClavier}
            className="py-3.5 px-4 rounded-2xl bg-slate-900/90 border border-slate-700/90 active:bg-slate-800 text-white font-bold text-xs flex items-center justify-center gap-2 shadow-xl backdrop-blur-md active:scale-95 transition-all"
          >
            <Keyboard className="w-4 h-4 text-blue-400" />
            <span>Taper Matricule</span>
          </button>

          <button
            onClick={onOuvrirListe}
            className="py-3.5 px-4 rounded-2xl bg-blue-600 active:bg-blue-700 text-white font-bold text-xs flex items-center justify-center gap-2 shadow-xl shadow-blue-600/30 active:scale-95 transition-all"
          >
            <ListFilter className="w-4 h-4 text-white" />
            <span>Flux du Jour</span>
          </button>
        </div>
      </div>
    </div>
  );
};
