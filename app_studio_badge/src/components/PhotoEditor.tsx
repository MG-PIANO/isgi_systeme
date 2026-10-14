import React from 'react';
import { ZoomIn, ZoomOut, RotateCw, Sun, Sliders, ArrowUp, ArrowDown, ArrowLeft, ArrowRight, RotateCcw, Check, Camera } from 'lucide-react';
import type { PhotoTransform, Student } from '../types/student';

interface PhotoEditorProps {
  student: Student;
  photoUrl: string;
  transform: PhotoTransform;
  onChangeTransform: (newTransform: PhotoTransform) => void;
  onConfirmBadge: () => void;
  onRetakePhoto: () => void;
}

export const PhotoEditor: React.FC<PhotoEditorProps> = ({
  student,
  photoUrl,
  transform,
  onChangeTransform,
  onConfirmBadge,
  onRetakePhoto
}) => {
  const update = (partial: Partial<PhotoTransform>) => {
    onChangeTransform({ ...transform, ...partial });
  };

  const handleZoomChange = (delta: number) => {
    const nextZoom = Math.min(3.0, Math.max(1.0, Number((transform.zoom + delta).toFixed(2))));
    update({ zoom: nextZoom });
  };

  const handleMove = (dx: number, dy: number) => {
    update({
      offsetX: transform.offsetX + dx,
      offsetY: transform.offsetY + dy
    });
  };

  const handleRotate = () => {
    const nextRotation = (transform.rotation + 90) % 360;
    update({ rotation: nextRotation });
  };

  const resetAdjustments = () => {
    onChangeTransform({
      zoom: 1,
      offsetX: 0,
      offsetY: 0,
      rotation: 0,
      brightness: 100,
      contrast: 100
    });
  };

  return (
    <div className="flex flex-col h-full space-y-4">
      {/* Student Header */}
      <div className="flex items-center justify-between bg-slate-900/80 p-3 rounded-2xl border border-slate-800">
        <div>
          <span className="text-[11px] font-semibold text-blue-400 uppercase tracking-wider">
            Étape 2 / 3 : Ajustement Photo
          </span>
          <h2 className="text-base font-bold text-white">
            {student.nom.toUpperCase()} {student.prenom}
          </h2>
          <span className="text-xs text-amber-400 font-mono">
            Matricule : {student.matricule || 'N/A'}
          </span>
        </div>

        <button
          onClick={resetAdjustments}
          className="flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs border border-slate-700"
          title="Réinitialiser les réglages"
        >
          <RotateCcw className="w-3.5 h-3.5" />
          <span>Reset</span>
        </button>
      </div>

      {/* Photo Preview Container (Cropped inside badge-like rounded box 3:4 aspect) */}
      <div className="relative mx-auto w-64 h-80 rounded-2xl overflow-hidden border-2 border-blue-500/80 shadow-2xl bg-black flex items-center justify-center">
        {/* Photo with transform styles */}
        <img
          src={photoUrl}
          alt="Aperçu recadrage"
          className="w-full h-full object-cover transition-transform duration-75 select-none"
          style={{
            transform: `scale(${transform.zoom}) translate(${transform.offsetX / transform.zoom}px, ${transform.offsetY / transform.zoom}px) rotate(${transform.rotation}deg)`,
            filter: `brightness(${transform.brightness}%) contrast(${transform.contrast}%)`
          }}
        />

        {/* Visual Badge Frame Overlay (Dashed oval guide for face centering) */}
        <div className="absolute inset-0 pointer-events-none border-2 border-dashed border-cyan-400/40 rounded-2xl flex items-center justify-center">
          <div className="w-44 h-56 rounded-[50%/60%] border border-cyan-400/30" />
        </div>
      </div>

      {/* Control Buttons Bar */}
      <div className="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-4">
        {/* Zoom & Rotation Controls */}
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2 flex-1">
            <button
              onClick={() => handleZoomChange(-0.1)}
              className="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white active:scale-95 border border-slate-700"
              title="Dézoomer"
            >
              <ZoomOut className="w-4 h-4" />
            </button>
            <div className="flex-1 text-center">
              <span className="text-xs text-slate-400">Zoom : </span>
              <span className="text-xs font-bold text-white">{(transform.zoom * 100).toFixed(0)}%</span>
            </div>
            <button
              onClick={() => handleZoomChange(0.1)}
              className="p-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white active:scale-95 border border-slate-700"
              title="Zoomer"
            >
              <ZoomIn className="w-4 h-4" />
            </button>
          </div>

          <div className="h-6 w-px bg-slate-750" />

          {/* 90° Rotate */}
          <button
            onClick={handleRotate}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold active:scale-95 border border-slate-700"
            title="Pivoter de 90°"
          >
            <RotateCw className="w-4 h-4" />
            <span>Pivoter {transform.rotation > 0 ? `(${transform.rotation}°)` : ''}</span>
          </button>
        </div>

        {/* Pan Directional D-Pad */}
        <div className="flex items-center justify-between pt-1 border-t border-slate-800/80">
          <span className="text-xs text-slate-400">Positionner :</span>
          <div className="grid grid-cols-3 gap-1 w-28">
            <div />
            <button
              onClick={() => handleMove(0, -15)}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center active:scale-95 border border-slate-700"
              title="Haut"
            >
              <ArrowUp className="w-3.5 h-3.5" />
            </button>
            <div />
            <button
              onClick={() => handleMove(-15, 0)}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center active:scale-95 border border-slate-700"
              title="Gauche"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
            </button>
            <div className="flex items-center justify-center text-[10px] text-slate-500 font-bold">
              PAN
            </div>
            <button
              onClick={() => handleMove(15, 0)}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center active:scale-95 border border-slate-700"
              title="Droite"
            >
              <ArrowRight className="w-3.5 h-3.5" />
            </button>
            <div />
            <button
              onClick={() => handleMove(0, 15)}
              className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center active:scale-95 border border-slate-700"
              title="Bas"
            >
              <ArrowDown className="w-3.5 h-3.5" />
            </button>
            <div />
          </div>
        </div>

        {/* Brightness slider */}
        <div className="flex items-center gap-3 pt-1 border-t border-slate-800/80">
          <Sun className="w-4 h-4 text-amber-400 shrink-0" />
          <span className="text-xs text-slate-400 w-16">Lumière</span>
          <input
            type="range"
            min="70"
            max="140"
            value={transform.brightness}
            onChange={(e) => update({ brightness: Number(e.target.value) })}
            className="flex-1 accent-blue-500 cursor-pointer"
          />
          <span className="text-xs font-mono text-slate-300 w-10 text-right">{transform.brightness}%</span>
        </div>
      </div>

      {/* Action Footer Buttons */}
      <div className="grid grid-cols-2 gap-3 pt-2">
        <button
          onClick={onRetakePhoto}
          className="flex items-center justify-center gap-2 py-3 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-sm border border-slate-700 active:scale-95 transition-all"
        >
          <Camera className="w-4 h-4" />
          <span>Reprendre</span>
        </button>

        <button
          onClick={onConfirmBadge}
          className="flex items-center justify-center gap-2 py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-600/30 active:scale-95 transition-all"
        >
          <Check className="w-4 h-4" />
          <span>Voir le Badge</span>
        </button>
      </div>
    </div>
  );
};
