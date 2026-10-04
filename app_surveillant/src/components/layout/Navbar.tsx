import React, { useState, useEffect } from 'react';
import { 
  ShieldCheck, 
  Clock, 
  Wifi, 
  WifiOff, 
  RefreshCw, 
  Bell, 
  UserCircle2,
  Calendar
} from 'lucide-react';
import { synchroniserAvecSupabase } from '../../db/db';

interface NavbarProps {
  surveillantNom?: string;
}

export const Navbar: React.FC<NavbarProps> = ({ surveillantNom = 'M. KOUAME - Surveillant Général' }) => {
  const [heureActuelle, setHeureActuelle] = useState('');
  const [dateActuelle, setDateActuelle] = useState('');
  const [isSyncing, setIsSyncing] = useState(false);
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  useEffect(() => {
    const mettreAJourTemps = () => {
      const now = new Date();
      setHeureActuelle(now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
      setDateActuelle(now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
    };

    mettreAJourTemps();
    const timer = setInterval(mettreAJourTemps, 1000);

    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      clearInterval(timer);
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const handleSync = async () => {
    setIsSyncing(true);
    try {
      await synchroniserAvecSupabase();
    } finally {
      setTimeout(() => setIsSyncing(false), 800);
    }
  };

  return (
    <header className="h-16 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-6 flex items-center justify-between sticky top-0 z-30">
      {/* Titre & Établissement */}
      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-500 flex items-center justify-center shadow-lg shadow-blue-500/20 text-white font-bold text-lg">
          <ShieldCheck className="w-5 h-5 text-white" />
        </div>
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-base font-bold text-white tracking-wide">ISGI SYSTEM</h1>
            <span className="px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30">
              Surveillant Général
            </span>
          </div>
          <p className="text-xs text-slate-400 flex items-center gap-1.5">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            Contrôle d'accès & Discipline de l'établissement
          </p>
        </div>
      </div>

      {/* Horloge dynamique & Date */}
      <div className="hidden md:flex items-center gap-4 bg-slate-800/60 border border-slate-700/60 rounded-xl px-4 py-2 text-sm shadow-inner">
        <div className="flex items-center gap-2 text-slate-300 font-medium">
          <Calendar className="w-4 h-4 text-blue-400" />
          <span className="capitalize">{dateActuelle}</span>
        </div>
        <div className="h-4 w-px bg-slate-700"></div>
        <div className="flex items-center gap-2 font-mono font-bold text-blue-400 text-base">
          <Clock className="w-4 h-4 text-blue-400 animate-pulse" />
          <span>{heureActuelle || '--:--:--'}</span>
        </div>
      </div>

      {/* Actions & Profil */}
      <div className="flex items-center gap-3">
        {/* Statut Sync */}
        <button
          onClick={handleSync}
          disabled={isSyncing}
          title="Synchroniser avec Supabase"
          className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs text-slate-300 transition-all hover:border-blue-500/50"
        >
          <RefreshCw className={`w-3.5 h-3.5 text-blue-400 ${isSyncing ? 'animate-spin' : ''}`} />
          <span className="hidden sm:inline">Sync Cloud</span>
        </button>

        {/* Indicateur Connexion */}
        <div className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-slate-800/80 border border-slate-700/60 text-xs">
          {isOnline ? (
            <>
              <Wifi className="w-3.5 h-3.5 text-emerald-400" />
              <span className="text-emerald-400 hidden sm:inline">En Ligne</span>
            </>
          ) : (
            <>
              <WifiOff className="w-3.5 h-3.5 text-amber-400" />
              <span className="text-amber-400 hidden sm:inline">Hors Ligne (Local)</span>
            </>
          )}
        </div>

        {/* Profil */}
        <div className="flex items-center gap-2 pl-2 border-l border-slate-800">
          <div className="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold">
            SG
          </div>
          <div className="hidden lg:block text-left">
            <div className="text-xs font-semibold text-white leading-tight">{surveillantNom}</div>
            <div className="text-[10px] text-slate-400">Poste de Commandement</div>
          </div>
        </div>
      </div>
    </header>
  );
};
