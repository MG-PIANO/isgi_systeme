import React, { useRef, useEffect, useState, useCallback } from 'react';
import QRCode from 'qrcode';
import confetti from 'canvas-confetti';
import { Download, Save, RefreshCw, CheckCircle2, ArrowRight, Eye, Edit3, Camera } from 'lucide-react';
import type { Student, PhotoTransform, BadgeSide } from '../types/student';
import { uploadAndSaveStudentPhoto } from '../lib/supabase';

interface BadgeRendererProps {
  student: Student;
  photoUrl: string;
  transform: PhotoTransform;
  onEditPhoto: () => void;
  onRetakePhoto: () => void;
  onNextStudent: () => void;
}

export const BadgeRenderer: React.FC<BadgeRendererProps> = ({
  student,
  photoUrl,
  transform,
  onEditPhoto,
  onRetakePhoto,
  onNextStudent
}) => {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const [badgeSide, setBadgeSide] = useState<BadgeSide>('recto');
  const [isSaving, setIsSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [renderedImageUrl, setRenderedImageUrl] = useState<string | null>(null);

  // Constants matching the official ISGI template
  const BADGE_WIDTH = 1063;
  const BADGE_HEIGHT = 1535;
  const PHOTO_FRAME = { x: 322, y: 400, width: 426, height: 452, radius: 28 };

  // Render the official badge onto Canvas
  const drawOfficialBadge = useCallback(async () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    canvas.width = BADGE_WIDTH;
    canvas.height = BADGE_HEIGHT;
    ctx.clearRect(0, 0, BADGE_WIDTH, BADGE_HEIGHT);

    if (badgeSide === 'verso') {
      // Draw Verso Template
      const versoImg = new Image();
      versoImg.crossOrigin = 'anonymous';
      versoImg.src = '/badge_verso.png';
      await new Promise(res => {
        versoImg.onload = res;
        versoImg.onerror = res;
      });
      ctx.drawImage(versoImg, 0, 0, BADGE_WIDTH, BADGE_HEIGHT);
      setRenderedImageUrl(canvas.toDataURL('image/png'));
      return;
    }

    // 1. Draw Recto Template Background
    const rectoImg = new Image();
    rectoImg.crossOrigin = 'anonymous';
    rectoImg.src = '/badge_recto.png';
    await new Promise(res => {
      rectoImg.onload = res;
      rectoImg.onerror = res;
    });
    ctx.drawImage(rectoImg, 0, 0, BADGE_WIDTH, BADGE_HEIGHT);

    // 2. Draw Cropped Student Photo inside frame
    if (photoUrl) {
      await new Promise<void>((resolve) => {
        const pImg = new Image();
        pImg.crossOrigin = 'anonymous';
        pImg.onload = () => {
          ctx.save();

          // Rounded rectangle clip
          ctx.beginPath();
          if (ctx.roundRect) {
            ctx.roundRect(PHOTO_FRAME.x, PHOTO_FRAME.y, PHOTO_FRAME.width, PHOTO_FRAME.height, PHOTO_FRAME.radius);
          } else {
            const r = PHOTO_FRAME.radius;
            ctx.moveTo(PHOTO_FRAME.x + r, PHOTO_FRAME.y);
            ctx.arcTo(PHOTO_FRAME.x + PHOTO_FRAME.width, PHOTO_FRAME.y, PHOTO_FRAME.x + PHOTO_FRAME.width, PHOTO_FRAME.y + PHOTO_FRAME.height, r);
            ctx.arcTo(PHOTO_FRAME.x + PHOTO_FRAME.width, PHOTO_FRAME.y + PHOTO_FRAME.height, PHOTO_FRAME.x, PHOTO_FRAME.y + PHOTO_FRAME.height, r);
            ctx.arcTo(PHOTO_FRAME.x, PHOTO_FRAME.y + PHOTO_FRAME.height, PHOTO_FRAME.x, PHOTO_FRAME.y, r);
            ctx.arcTo(PHOTO_FRAME.x, PHOTO_FRAME.y, PHOTO_FRAME.x + PHOTO_FRAME.width, PHOTO_FRAME.y, r);
            ctx.closePath();
          }
          ctx.clip();

          // Apply brightness/contrast filter
          ctx.filter = `brightness(${transform.brightness}%) contrast(${transform.contrast}%)`;

          // Calculate scaling and centering
          const frameAspect = PHOTO_FRAME.width / PHOTO_FRAME.height;
          const imgAspect = pImg.width / pImg.height;

          let baseW = PHOTO_FRAME.width;
          let baseH = PHOTO_FRAME.height;
          if (imgAspect > frameAspect) {
            baseW = PHOTO_FRAME.height * imgAspect;
          } else {
            baseH = PHOTO_FRAME.width / imgAspect;
          }

          const drawW = baseW * transform.zoom;
          const drawH = baseH * transform.zoom;
          const centerX = PHOTO_FRAME.x + PHOTO_FRAME.width / 2 + transform.offsetX;
          const centerY = PHOTO_FRAME.y + PHOTO_FRAME.height / 2 + transform.offsetY;

          ctx.translate(centerX, centerY);
          if (transform.rotation) {
            ctx.rotate((transform.rotation * Math.PI) / 180);
          }
          ctx.drawImage(pImg, -drawW / 2, -drawH / 2, drawW, drawH);

          ctx.restore();
          resolve();
        };
        pImg.onerror = () => resolve();
        pImg.src = photoUrl;
      });
    }

    // 3. Draw Student Details (Exact ISGI Typography & Positions)
    ctx.textBaseline = 'middle';

    const drawField = (text: string, x: number, y: number, fontSize: number, align: CanvasTextAlign = 'left', maxW: number = 600) => {
      ctx.font = `bold ${fontSize}px Arial, sans-serif`;
      ctx.fillStyle = '#ffffff';
      ctx.textAlign = align;

      let truncated = text;
      while (truncated && ctx.measureText(truncated).width > maxW && truncated.length > 3) {
        truncated = `${truncated.slice(0, -2)}…`;
      }
      ctx.fillText(truncated, x, y);
    };

    drawField(student.nom.toUpperCase(), 360, 1077, 26, 'left', 600);
    drawField(student.prenom, 360, 1135, 26, 'left', 600);
    drawField(student.lieu_naissance || '', 360, 1195, 24, 'left', 600);
    drawField(student.filiere || '', 360, 1253, 24, 'left', 600);
    drawField(student.niveau || '', 360, 1314, 24, 'left', 380);

    // Orange Matricule Banner
    ctx.fillStyle = '#FF6B00';
    ctx.fillRect(330, 1364, 420, 90);
    const matriculeText = student.matricule ? `N°ISGI : ${student.matricule}` : 'N°ISGI : EN COURS';
    drawField(matriculeText, 535, 1417, 30, 'center', 400);

    // 4. Draw Official ISGI QR Code
    const qrPayload = JSON.stringify({
      type: 'ISGI_STUDENT',
      version: 1,
      matricule: (student.matricule || '').toUpperCase(),
      nom: (student.nom || '').toUpperCase(),
      prenom: student.prenom
    });

    try {
      const qrDataUrl = await QRCode.toDataURL(qrPayload, {
        width: 191,
        margin: 1,
        color: { dark: '#003087', light: '#ffffff' },
        errorCorrectionLevel: 'M'
      });

      await new Promise<void>((res) => {
        const qrImg = new Image();
        qrImg.onload = () => {
          ctx.drawImage(qrImg, 772, 1297, 191, 191);
          res();
        };
        qrImg.onerror = () => res();
        qrImg.src = qrDataUrl;
      });
    } catch (e) {
      console.warn('Erreur génération QR Code:', e);
    }

    setRenderedImageUrl(canvas.toDataURL('image/png'));
  }, [student, photoUrl, transform, badgeSide]);

  useEffect(() => {
    drawOfficialBadge();
  }, [drawOfficialBadge]);

  // Extract optimized photo from current canvas crop for storage
  const extractOptimizedPhotoBlob = async (): Promise<{ blob: Blob; dataUrl: string }> => {
    const offCanvas = document.createElement('canvas');
    offCanvas.width = PHOTO_FRAME.width * 2; // High resolution 2x
    offCanvas.height = PHOTO_FRAME.height * 2;
    const offCtx = offCanvas.getContext('2d');
    if (!offCtx) throw new Error('Canvas non disponible');

    const pImg = new Image();
    pImg.crossOrigin = 'anonymous';
    await new Promise((res, rej) => {
      pImg.onload = res;
      pImg.onerror = rej;
      pImg.src = photoUrl;
    });

    offCtx.save();
    offCtx.filter = `brightness(${transform.brightness}%) contrast(${transform.contrast}%)`;

    const frameAspect = offCanvas.width / offCanvas.height;
    const imgAspect = pImg.width / pImg.height;

    let baseW = offCanvas.width;
    let baseH = offCanvas.height;
    if (imgAspect > frameAspect) {
      baseW = offCanvas.height * imgAspect;
    } else {
      baseH = offCanvas.width / imgAspect;
    }

    const drawW = baseW * transform.zoom;
    const drawH = baseH * transform.zoom;
    const centerX = offCanvas.width / 2 + transform.offsetX * 2;
    const centerY = offCanvas.height / 2 + transform.offsetY * 2;

    offCtx.translate(centerX, centerY);
    if (transform.rotation) {
      offCtx.rotate((transform.rotation * Math.PI) / 180);
    }
    offCtx.drawImage(pImg, -drawW / 2, -drawH / 2, drawW, drawH);
    offCtx.restore();

    const dataUrl = offCanvas.toDataURL('image/jpeg', 0.90);
    const blob = await new Promise<Blob>((res, rej) => {
      offCanvas.toBlob((b) => b ? res(b) : rej(new Error('Conversion échouée')), 'image/jpeg', 0.90);
    });

    return { blob, dataUrl };
  };

  // Save to Supabase
  const handleSaveToDatabase = async () => {
    setIsSaving(true);
    setErrorMessage(null);

    try {
      const { blob, dataUrl } = await extractOptimizedPhotoBlob();
      await uploadAndSaveStudentPhoto(student, blob, dataUrl);

      // Trigger Confetti
      confetti({
        particleCount: 80,
        spread: 70,
        origin: { y: 0.6 }
      });

      // Tactile feedback
      if (navigator.vibrate) {
        navigator.vibrate([100, 50, 100]);
      }

      setSaveSuccess(true);
    } catch (err: any) {
      console.error('Erreur enregistrement:', err);
      setErrorMessage(err.message || 'Impossible d\'enregistrer dans la base.');
    } finally {
      setIsSaving(false);
    }
  };

  // Download Badge PNG HD
  const handleDownloadPNG = () => {
    if (!canvasRef.current) return;
    const link = document.createElement('a');
    link.download = `Badge_ISGI_${student.nom}_${student.prenom}_${student.matricule || 'Etudiant'}.png`;
    link.href = canvasRef.current.toDataURL('image/png', 1.0);
    link.click();
  };

  return (
    <div className="flex flex-col h-full space-y-4">
      {/* Step Header */}
      <div className="flex items-center justify-between bg-slate-900/80 p-3 rounded-2xl border border-slate-800">
        <div>
          <span className="text-[11px] font-semibold text-emerald-400 uppercase tracking-wider">
            Étape 3 / 3 : Badge Officiel ISGI
          </span>
          <h2 className="text-base font-bold text-white">
            {student.nom.toUpperCase()} {student.prenom}
          </h2>
          <span className="text-xs text-amber-400 font-mono">
            {student.filiere} • {student.matricule}
          </span>
        </div>

        {/* Recto / Verso Tabs */}
        <div className="flex p-1 bg-slate-800/80 rounded-xl border border-slate-700/60 text-xs">
          <button
            onClick={() => setBadgeSide('recto')}
            className={`px-3 py-1 rounded-lg font-medium transition-all ${
              badgeSide === 'recto' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Recto
          </button>
          <button
            onClick={() => setBadgeSide('verso')}
            className={`px-3 py-1 rounded-lg font-medium transition-all ${
              badgeSide === 'verso' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Verso
          </button>
        </div>
      </div>

      {/* Hidden Native 1063 x 1535 Canvas */}
      <canvas ref={canvasRef} className="hidden" />

      {/* Real-time Badge Preview Card */}
      <div className="relative mx-auto flex items-center justify-center p-2">
        <div className="relative w-full max-w-[310px] aspect-[1063/1535] rounded-2xl overflow-hidden shadow-2xl border border-slate-700/80 bg-slate-900 group">
          {renderedImageUrl ? (
            <img
              src={renderedImageUrl}
              alt="Aperçu Badge Officiel ISGI"
              className="w-full h-full object-contain select-none"
            />
          ) : (
            <div className="flex flex-col items-center justify-center h-full text-slate-400">
              <RefreshCw className="w-8 h-8 animate-spin text-blue-500 mb-2" />
              <span className="text-xs">Génération du badge HD...</span>
            </div>
          )}

          {/* Quick Edit Overlay button */}
          <button
            onClick={onEditPhoto}
            className="absolute top-3 right-3 p-2 rounded-xl bg-black/60 backdrop-blur-md text-white border border-white/20 active:scale-95 transition-all opacity-80 hover:opacity-100"
            title="Ajuster la photo"
          >
            <Edit3 className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Error Banner */}
      {errorMessage && (
        <div className="p-3 rounded-xl bg-rose-950/60 border border-rose-800 text-rose-300 text-xs flex items-center gap-2">
          <span>⚠️ {errorMessage}</span>
        </div>
      )}

      {/* Success Modal / Banner */}
      {saveSuccess ? (
        <div className="p-4 rounded-2xl bg-emerald-950/70 border border-emerald-600/60 text-white space-y-3 animate-fade-in shadow-xl">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-500 text-slate-950 flex items-center justify-center shrink-0">
              <CheckCircle2 className="w-6 h-6" />
            </div>
            <div>
              <h4 className="font-bold text-sm text-emerald-200">Enregistré dans la base !</h4>
              <p className="text-xs text-slate-300">
                La photo et le badge sont synchronisés avec Supabase. L'ordinateur de l'informaticien affiche désormais la photo instantanément.
              </p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2 pt-1">
            <button
              onClick={handleDownloadPNG}
              className="py-2.5 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-white flex items-center justify-center gap-1.5 border border-slate-700 active:scale-95"
            >
              <Download className="w-4 h-4 text-cyan-400" />
              <span>Télécharger PNG</span>
            </button>

            <button
              onClick={onNextStudent}
              className="py-2.5 px-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-xs font-bold text-white flex items-center justify-center gap-1.5 shadow-md shadow-emerald-700/30 active:scale-95"
            >
              <span>Suivant sans photo</span>
              <ArrowRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      ) : (
        /* Action Buttons */
        <div className="space-y-2 pt-1">
          {/* Main Save to Supabase Button */}
          <button
            onClick={handleSaveToDatabase}
            disabled={isSaving}
            className="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 hover:from-blue-500 hover:to-indigo-500 text-white font-bold text-sm shadow-xl shadow-blue-900/40 active:scale-[0.98] transition-all flex items-center justify-center gap-2 disabled:opacity-50"
          >
            {isSaving ? (
              <>
                <RefreshCw className="w-4 h-4 animate-spin" />
                <span>Enregistrement dans la base...</span>
              </>
            ) : (
              <>
                <Save className="w-4 h-4" />
                <span>Enregistrer & Synchroniser sur PC</span>
              </>
            )}
          </button>

          {/* Secondary Options */}
          <div className="grid grid-cols-3 gap-2">
            <button
              onClick={onEditPhoto}
              className="py-2.5 px-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white text-xs font-medium border border-slate-700 flex items-center justify-center gap-1.5 active:scale-95"
            >
              <Edit3 className="w-3.5 h-3.5" />
              <span>Ajuster</span>
            </button>

            <button
              onClick={onRetakePhoto}
              className="py-2.5 px-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white text-xs font-medium border border-slate-700 flex items-center justify-center gap-1.5 active:scale-95"
            >
              <Camera className="w-3.5 h-3.5" />
              <span>Reprendre</span>
            </button>

            <button
              onClick={handleDownloadPNG}
              className="py-2.5 px-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white text-xs font-medium border border-slate-700 flex items-center justify-center gap-1.5 active:scale-95"
            >
              <Download className="w-3.5 h-3.5 text-cyan-400" />
              <span>PNG HD</span>
            </button>
          </div>
        </div>
      )}
    </div>
  );
};
