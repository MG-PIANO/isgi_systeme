import React, { useRef, useState, useEffect, useCallback } from 'react';
import { Camera, RefreshCw, Upload, X, Zap, ZapOff, Check, AlertTriangle } from 'lucide-react';
import type { Student } from '../types/student';

interface CameraStudioProps {
  student: Student;
  onPhotoCaptured: (dataUrl: string) => void;
  onClose: () => void;
}

export const CameraStudio: React.FC<CameraStudioProps> = ({
  student,
  onPhotoCaptured,
  onClose
}) => {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [hasTorch, setHasTorch] = useState<boolean>(false);
  const [torchOn, setTorchOn] = useState<boolean>(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [isStartingCamera, setIsStartingCamera] = useState<boolean>(true);
  const [isFlashing, setIsFlashing] = useState<boolean>(false);

  // Start Camera
  const startCamera = useCallback(async () => {
    setIsStartingCamera(true);
    setCameraError(null);

    // Stop existing stream if any
    if (streamRef.current) {
      streamRef.current.getTracks().forEach(track => track.stop());
      streamRef.current = null;
    }

    try {
      const constraints: MediaStreamConstraints = {
        audio: false,
        video: {
          facingMode: { ideal: facingMode },
          width: { ideal: 1920, min: 1280 },
          height: { ideal: 1080, min: 720 },
        }
      };

      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      streamRef.current = stream;

      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }

      // Check torch support
      const videoTrack = stream.getVideoTracks()[0];
      const capabilities = (videoTrack?.getCapabilities ? videoTrack.getCapabilities() : {}) as any;
      setHasTorch(Boolean(capabilities?.torch));

    } catch (err: any) {
      console.error('Erreur accès caméra:', err);
      let msg = 'Impossible d\'accéder à la caméra.';
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        msg = 'Veuillez autoriser l\'accès à l\'appareil photo dans votre navigateur.';
      } else if (err.name === 'NotFoundError') {
        msg = 'Aucun appareil photo détecté sur cet appareil.';
      }
      setCameraError(msg);
    } finally {
      setIsStartingCamera(false);
    }
  }, [facingMode]);

  useEffect(() => {
    startCamera();
    return () => {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
      }
    };
  }, [startCamera]);

  // Toggle Torch / Flashlight
  const toggleTorch = async () => {
    if (!streamRef.current) return;
    const track = streamRef.current.getVideoTracks()[0];
    if (track && hasTorch) {
      try {
        const nextState = !torchOn;
        await (track as any).applyConstraints({
          advanced: [{ torch: nextState }]
        });
        setTorchOn(nextState);
      } catch (e) {
        console.warn('Erreur activation torche:', e);
      }
    }
  };

  // Toggle Front / Back Camera
  const toggleFacingMode = () => {
    setFacingMode(prev => prev === 'environment' ? 'user' : 'environment');
  };

  // Snap Photo
  const takeSnapshot = () => {
    if (!videoRef.current) return;
    const video = videoRef.current;

    // Visual flash
    setIsFlashing(true);
    setTimeout(() => setIsFlashing(false), 300);

    // Tactile feedback
    if (navigator.vibrate) {
      navigator.vibrate(60);
    }

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 1280;
    canvas.height = video.videoHeight || 720;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // If front camera, mirror image for natural selfie orientation
    if (facingMode === 'user') {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.95);

    // Stop camera and pass photo
    if (streamRef.current) {
      streamRef.current.getTracks().forEach(t => t.stop());
    }
    onPhotoCaptured(dataUrl);
  };

  // Handle File Input Fallback
  const handleFileSelected = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        if (streamRef.current) {
          streamRef.current.getTracks().forEach(t => t.stop());
        }
        onPhotoCaptured(reader.result);
      }
    };
    reader.readAsDataURL(file);
  };

  return (
    <div className="fixed inset-0 z-50 bg-black flex flex-col justify-between select-none">
      {/* Top Overlay Bar */}
      <div className="absolute top-0 inset-x-0 z-30 p-4 bg-gradient-to-b from-black/80 via-black/40 to-transparent flex items-center justify-between">
        <button
          onClick={onClose}
          className="p-2.5 rounded-full bg-slate-900/60 backdrop-blur-md text-white border border-white/20 active:scale-95 transition-all"
          aria-label="Fermer"
        >
          <X className="w-5 h-5" />
        </button>

        {/* Student banner */}
        <div className="text-center px-3 py-1 rounded-full bg-slate-900/70 backdrop-blur-md border border-white/20 max-w-[200px] truncate">
          <p className="text-xs font-bold text-white truncate">
            {student.nom.toUpperCase()} {student.prenom}
          </p>
          <p className="text-[10px] text-amber-400 font-mono">
            {student.matricule}
          </p>
        </div>

        {/* Camera options */}
        <div className="flex items-center gap-2">
          {hasTorch && (
            <button
              onClick={toggleTorch}
              className={`p-2.5 rounded-full backdrop-blur-md border border-white/20 active:scale-95 transition-all ${
                torchOn ? 'bg-amber-500 text-slate-950' : 'bg-slate-900/60 text-white'
              }`}
              title="Lampe torche"
            >
              {torchOn ? <Zap className="w-5 h-5 fill-current" /> : <ZapOff className="w-5 h-5" />}
            </button>
          )}

          <button
            onClick={toggleFacingMode}
            className="p-2.5 rounded-full bg-slate-900/60 backdrop-blur-md text-white border border-white/20 active:scale-95 transition-all"
            title="Changer de caméra"
          >
            <RefreshCw className="w-5 h-5" />
          </button>
        </div>
      </div>

      {/* Main Video Viewport */}
      <div className="relative flex-1 flex items-center justify-center overflow-hidden bg-slate-950">
        <video
          ref={videoRef}
          playsInline
          autoPlay
          muted
          className={`w-full h-full object-cover ${facingMode === 'user' ? 'scale-x-[-1]' : ''}`}
        />

        {/* Shutter flash animation */}
        {isFlashing && (
          <div className="absolute inset-0 bg-white camera-flash z-40 pointer-events-none" />
        )}

        {/* Camera Error Message */}
        {cameraError && (
          <div className="absolute inset-0 z-20 flex flex-col items-center justify-center bg-slate-950/90 p-6 text-center text-slate-300">
            <div className="w-14 h-14 rounded-full bg-rose-500/20 text-rose-400 flex items-center justify-center mb-3">
              <AlertTriangle className="w-7 h-7" />
            </div>
            <p className="text-base font-semibold text-white mb-2">Accès Caméra Restreint</p>
            <p className="text-sm text-slate-400 max-w-sm mb-6">{cameraError}</p>
            <div className="flex flex-col sm:flex-row gap-3 w-full max-w-xs">
              <button
                onClick={startCamera}
                className="w-full py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-medium text-sm flex items-center justify-center gap-2"
              >
                <RefreshCw className="w-4 h-4" />
                Réessayer
              </button>
              <button
                onClick={() => fileInputRef.current?.click()}
                className="w-full py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-sm flex items-center justify-center gap-2 border border-slate-700"
              >
                <Upload className="w-4 h-4" />
                Choisir un fichier
              </button>
            </div>
          </div>
        )}

        {/* Portrait Silhouette Guide Overlay */}
        {!cameraError && (
          <div className="absolute inset-0 pointer-events-none flex flex-col items-center justify-center">
            {/* Oval Head Guide */}
            <div className="relative w-64 h-80 rounded-[50%/60%] border-2 border-dashed border-cyan-400/80 shadow-[0_0_30px_rgba(34,211,238,0.25)] guide-pulse flex flex-col items-center justify-end pb-4">
              <div className="absolute top-1/2 left-0 right-0 border-t border-cyan-400/30" />
              <div className="absolute left-1/2 top-0 bottom-0 border-l border-cyan-400/30" />
            </div>

            {/* Shoulder Arc */}
            <div className="w-80 h-16 border-t-2 border-dashed border-cyan-400/60 rounded-t-[100%] mt-2 opacity-80" />

            {/* Guiding Helper Text */}
            <div className="mt-4 px-4 py-1.5 rounded-full bg-black/60 backdrop-blur-md border border-white/20 text-xs font-medium text-cyan-300">
              Cadrez le visage dans l'ovale
            </div>
          </div>
        )}
      </div>

      {/* Bottom Shutter Controls */}
      <div className="relative z-30 p-6 bg-gradient-to-t from-black via-black/80 to-transparent flex items-center justify-around">
        {/* Gallery / File Picker */}
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*"
          className="hidden"
          onChange={handleFileSelected}
        />
        <button
          onClick={() => fileInputRef.current?.click()}
          className="flex flex-col items-center gap-1.5 p-3 rounded-2xl text-slate-300 hover:text-white active:scale-95 transition-all"
        >
          <div className="w-12 h-12 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center justify-center">
            <Upload className="w-5 h-5" />
          </div>
          <span className="text-[11px] font-medium">Galerie</span>
        </button>

        {/* Main Big Shutter Button */}
        <button
          onClick={takeSnapshot}
          disabled={Boolean(cameraError) || isStartingCamera}
          className="group relative flex items-center justify-center w-20 h-20 rounded-full bg-white/20 border-4 border-white active:scale-90 transition-all disabled:opacity-40 disabled:pointer-events-none"
          aria-label="Prendre la photo"
        >
          <div className="w-16 h-16 rounded-full bg-white group-active:scale-95 transition-transform shadow-lg" />
        </button>

        {/* Cancel / Back */}
        <button
          onClick={onClose}
          className="flex flex-col items-center gap-1.5 p-3 rounded-2xl text-slate-300 hover:text-white active:scale-95 transition-all"
        >
          <div className="w-12 h-12 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center justify-center">
            <X className="w-5 h-5" />
          </div>
          <span className="text-[11px] font-medium">Annuler</span>
        </button>
      </div>
    </div>
  );
};
