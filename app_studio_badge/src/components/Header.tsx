import React from 'react';
import { Camera, RefreshCw, Wifi, WifiOff, ShieldCheck, Sparkles } from 'lucide-react';

interface HeaderProps {
  isOnline: boolean;
  studentsCount: number;
  noPhotoCount: number;
  onRefresh: () => void;
  isLoading: boolean;
}

export const Header: React.FC<HeaderProps> = ({
  isOnline,
  studentsCount,
  noPhotoCount,
  onRefresh,
  isLoading
}) => {
  return (
    <header className="sticky top-0 z-40 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-4 py-3">
      <div className="max-w-5xl mx-auto flex items-center justify-between gap-3">
        {/* Logo & Title */}
        <div className="flex items-center gap-3">
          <div className="relative">
            <img
              src="/logo_isgi.jpg"
              alt="Logo ISGI"
              className="w-10 h-10 rounded-xl object-contain bg-white p-0.5 shadow-md border border-slate-700"
            />
            <span className="absolute -bottom-1 -right-1 flex h-3.5 w-3.5">
              <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${isOnline ? 'bg-emerald-400' : 'bg-rose-400'}`}></span>
              <span className={`relative inline-flex rounded-full h-3.5 w-3.5 border-2 border-slate-900 ${isOnline ? 'bg-emerald-500' : 'bg-rose-500'}`}></span>
            </span>
          </div>

          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-bold text-base sm:text-lg text-white leading-tight tracking-tight">
                ISGI Studio Badge
              </h1>
              <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold tracking-wide bg-blue-500/20 text-blue-400 border border-blue-500/30">
                INFORMATICIEN
              </span>
            </div>
            <p className="text-xs text-slate-400 flex items-center gap-1.5">
              <span>{studentsCount} étudiants</span>
              <span>•</span>
              <span className={noPhotoCount > 0 ? "text-amber-400 font-medium" : "text-emerald-400"}>
                {noPhotoCount} sans photo
              </span>
            </p>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2">
          <button
            onClick={onRefresh}
            disabled={isLoading}
            className={`p-2 rounded-xl bg-slate-800/80 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700/60 active:scale-95 transition-all ${isLoading ? 'animate-spin text-blue-400' : ''}`}
            title="Rafraîchir les données de Supabase"
            aria-label="Rafraîchir"
          >
            <RefreshCw className="w-4 h-4" />
          </button>

          <div
            className={`flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-medium border ${
              isOnline
                ? 'bg-emerald-950/40 text-emerald-400 border-emerald-800/50'
                : 'bg-rose-950/40 text-rose-400 border-rose-800/50'
            }`}
          >
            {isOnline ? (
              <>
                <Wifi className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">En ligne</span>
              </>
            ) : (
              <>
                <WifiOff className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">Hors ligne</span>
              </>
            )}
          </div>
        </div>
      </div>
    </header>
  );
};
