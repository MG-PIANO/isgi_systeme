import React from 'react';
import { 
  ShieldCheck, 
  Flashlight, 
  FlashlightOff, 
  RotateCcw, 
  Wifi, 
  WifiOff, 
  Volume2, 
  VolumeX,
  Clock
} from 'lucide-react';

interface MobileNavbarProps {
  torchOn: boolean;
  toggleTorch: () => void;
  hasTorch: boolean;
  soundOn: boolean;
  toggleSound: () => void;
  switchCamera: () => void;
  isOnline: boolean;
  heureStr: string;
}

export const MobileNavbar: React.FC<MobileNavbarProps> = ({
  torchOn,
  toggleTorch,
  hasTorch,
  soundOn,
  toggleSound,
  switchCamera,
  isOnline,
  heureStr
}) => {
  return (
    <header className="h-14 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-4 flex items-center justify-between sticky top-0 z-40 pt-safe">
      <div className="flex items-center gap-2.5">
        <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-md">
          <ShieldCheck className="w-4 h-4" />
        </div>
        <div>
          <div className="flex items-center gap-1.5">
            <span className="font-extrabold text-sm text-white tracking-wide">ISGI SCAN</span>
            <span className="text-[10px] font-bold px-1.5 py-0.2 bg-blue-500/20 text-blue-400 rounded border border-blue-500/30 uppercase">
              Portail
            </span>
          </div>
        </div>
      </div>

      {/* Heure en direct */}
      <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-800 border border-slate-700 font-mono text-xs font-bold text-blue-400">
        <Clock className="w-3 h-3 text-blue-400 animate-pulse" />
        <span>{heureStr || '--:--'}</span>
      </div>

      {/* Contrôles Flash, Caméra, Son */}
      <div className="flex items-center gap-1.5">
        <button
          onClick={toggleSound}
          className={`p-2 rounded-lg border text-xs transition-all ${
            soundOn ? 'bg-blue-600/20 text-blue-400 border-blue-500/30' : 'bg-slate-800 text-slate-500 border-slate-700'
          }`}
          title="Son"
        >
          {soundOn ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
        </button>

        {hasTorch && (
          <button
            onClick={toggleTorch}
            className={`p-2 rounded-lg border text-xs transition-all ${
              torchOn ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'bg-slate-800 text-slate-400 border-slate-700'
            }`}
            title="Lampe Torche"
          >
            {torchOn ? <Flashlight className="w-4 h-4" /> : <FlashlightOff className="w-4 h-4" />}
          </button>
        )}

        <button
          onClick={switchCamera}
          className="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs transition-all"
          title="Changer de caméra"
        >
          <RotateCcw className="w-4 h-4" />
        </button>
      </div>
    </header>
  );
};
