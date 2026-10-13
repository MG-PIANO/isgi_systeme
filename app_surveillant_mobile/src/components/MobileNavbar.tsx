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
  Clock,
  RefreshCw,
  CloudCheck
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
  pendingSyncCount?: number;
  onSyncClick?: () => void;
  isSyncing?: boolean;
}

export const MobileNavbar: React.FC<MobileNavbarProps> = ({
  torchOn,
  toggleTorch,
  hasTorch,
  soundOn,
  toggleSound,
  switchCamera,
  isOnline,
  heureStr,
  pendingSyncCount = 0,
  onSyncClick,
  isSyncing = false
}) => {
  return (
    <header className="h-14 bg-slate-900/95 backdrop-blur-md border-b border-slate-800 px-3 flex items-center justify-between sticky top-0 z-40 pt-safe">
      {/* Logo & Titre */}
      <div className="flex items-center gap-2">
        <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-md">
          <ShieldCheck className="w-4 h-4" />
        </div>
        <div>
          <div className="flex items-center gap-1.5">
            <span className="font-extrabold text-sm text-white tracking-wide">ISGI SCAN</span>
            <span className="text-[9px] font-black px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded border border-blue-500/30 uppercase">
              Web
            </span>
          </div>
        </div>
      </div>

      {/* Statut Connexion & Synchro Offline Queue */}
      <div className="flex items-center gap-1.5">
        {/* Bouton de Synchro Cloud */}
        {onSyncClick && (
          <button
            onClick={onSyncClick}
            disabled={isSyncing || (!isOnline && pendingSyncCount === 0)}
            className={`flex items-center gap-1 px-2 py-1 rounded-full text-[11px] font-bold border transition-all cursor-pointer ${
              pendingSyncCount > 0
                ? 'bg-amber-500/20 text-amber-300 border-amber-500/40 animate-pulse'
                : isOnline
                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                : 'bg-slate-800 text-slate-400 border-slate-700'
            }`}
            title="Synchroniser avec le serveur Supabase"
          >
            <RefreshCw className={`w-3 h-3 ${isSyncing ? 'animate-spin text-blue-400' : ''}`} />
            <span>
              {isSyncing 
                ? 'Synchro...' 
                : pendingSyncCount > 0 
                ? `${pendingSyncCount} en attente` 
                : isOnline 
                ? 'Synchro OK' 
                : 'Hors-ligne'}
            </span>
          </button>
        )}

        {/* Indicateur Wifi */}
        <div 
          className={`flex items-center p-1 rounded-full ${
            isOnline ? 'text-emerald-400' : 'text-rose-400'
          }`}
          title={isOnline ? 'Connecté à Internet' : 'Mode Hors-ligne (Stockage local)'}
        >
          {isOnline ? <Wifi className="w-3.5 h-3.5" /> : <WifiOff className="w-3.5 h-3.5 animate-bounce" />}
        </div>

        {/* Heure */}
        <div className="hidden sm:flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-800 border border-slate-700 font-mono text-xs font-bold text-blue-400">
          <Clock className="w-3 h-3 text-blue-400" />
          <span>{heureStr || '--:--'}</span>
        </div>
      </div>

      {/* Contrôles Flash, Caméra, Son */}
      <div className="flex items-center gap-1.5">
        <button
          onClick={toggleSound}
          className={`p-2 rounded-lg border text-xs transition-all cursor-pointer ${
            soundOn ? 'bg-blue-600/20 text-blue-400 border-blue-500/30' : 'bg-slate-800 text-slate-500 border-slate-700'
          }`}
          title="Son"
        >
          {soundOn ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
        </button>

        {hasTorch && (
          <button
            onClick={toggleTorch}
            className={`p-2 rounded-lg border text-xs transition-all cursor-pointer ${
              torchOn ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'bg-slate-800 text-slate-400 border-slate-700'
            }`}
            title="Lampe Torche"
          >
            {torchOn ? <Flashlight className="w-4 h-4" /> : <FlashlightOff className="w-4 h-4" />}
          </button>
        )}

        <button
          onClick={switchCamera}
          className="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs transition-all cursor-pointer"
          title="Changer de caméra"
        >
          <RotateCcw className="w-4 h-4" />
        </button>
      </div>
    </header>
  );
};
