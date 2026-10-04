import React, { useEffect } from 'react';
import { 
  CheckCircle2, 
  AlertTriangle, 
  LogOut, 
  Building2, 
  Clock, 
  X, 
  ChevronRight,
  ArrowRightLeft
} from 'lucide-react';
import type { ScanProcessResult } from '../types';
import { formaterHeure } from '../lib/utils';

interface ResultOverlayProps {
  resultat: ScanProcessResult;
  onFermer: () => void;
}

export const ResultOverlay: React.FC<ResultOverlayProps> = ({ resultat, onFermer }) => {
  // Fermeture automatique après 5 secondes si pas de clic
  useEffect(() => {
    const timer = setTimeout(onFermer, 4500);
    return () => clearTimeout(timer);
  }, [onFermer]);

  const isArrivee = resultat.action === 'arrivee';
  const isSortie = resultat.action === 'sortie_tour';
  const isRetour = resultat.action === 'retour';

  const themeColor = isArrivee 
    ? resultat.estEnRetard 
      ? 'from-rose-600 to-rose-800' 
      : 'from-emerald-600 to-teal-800'
    : isSortie 
    ? 'from-amber-600 to-orange-800' 
    : 'from-blue-600 to-indigo-800';

  return (
    <div className="fixed inset-0 z-50 bg-black/85 backdrop-blur-md flex flex-col justify-between p-4 animate-fadeIn pb-safe pt-safe">
      {/* Bouton Fermer Haut */}
      <div className="flex justify-end">
        <button
          onClick={onFermer}
          className="p-2.5 rounded-full bg-slate-800/80 text-slate-300 hover:text-white border border-slate-700 active:scale-95 transition-all"
        >
          <X className="w-6 h-6" />
        </button>
      </div>

      {/* Contenu Central Mobile */}
      <div className="my-auto space-y-5 text-center">
        {/* Grand Bandeau d'Action */}
        <div className={`p-4 rounded-2xl bg-gradient-to-r ${themeColor} text-white shadow-2xl flex items-center justify-center gap-3 border border-white/20`}>
          {isArrivee ? (
            resultat.estEnRetard ? <AlertTriangle className="w-7 h-7 text-white shrink-0" /> : <CheckCircle2 className="w-7 h-7 text-white shrink-0" />
          ) : isSortie ? (
            <LogOut className="w-7 h-7 text-white shrink-0" />
          ) : (
            <Building2 className="w-7 h-7 text-white shrink-0" />
          )}
          <span className="text-base font-extrabold font-heading tracking-wide uppercase">
            {resultat.message}
          </span>
        </div>

        {/* Photo Étudiant Grand Format */}
        <div className="relative inline-block mx-auto">
          {resultat.etudiant.photo_url ? (
            <img
              src={resultat.etudiant.photo_url}
              alt={resultat.etudiant.nom}
              className="w-32 h-36 rounded-2xl object-cover border-4 border-white/30 shadow-2xl mx-auto"
            />
          ) : (
            <div className="w-32 h-36 rounded-2xl bg-slate-800 border-4 border-slate-700 flex items-center justify-center text-white font-extrabold text-3xl mx-auto shadow-2xl">
              {resultat.etudiant.nom.charAt(0)}{resultat.etudiant.prenom.charAt(0)}
            </div>
          )}

          {/* Badge position */}
          <div className="absolute -bottom-2.5 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-slate-900 border border-slate-700 shadow-lg">
            {resultat.pointage.statut_actuel === 'sur_site' ? (
              <span className="text-emerald-400 flex items-center gap-1.5">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                Sur Site
              </span>
            ) : (
              <span className="text-amber-400 flex items-center gap-1.5">
                <span className="w-2 h-2 rounded-full bg-amber-500"></span>
                Sorti
              </span>
            )}
          </div>
        </div>

        {/* Identité Étudiant */}
        <div className="space-y-1 pt-1">
          <div className="inline-block px-3 py-0.5 rounded-full text-xs font-mono font-bold bg-blue-500/20 text-blue-300 border border-blue-500/30">
            {resultat.etudiant.matricule}
          </div>
          <h2 className="text-2xl font-black text-white font-heading uppercase">
            {resultat.etudiant.nom}
          </h2>
          <div className="text-lg font-bold text-blue-300">
            {resultat.etudiant.prenom}
          </div>
          <div className="text-xs text-slate-300 font-medium">
            {resultat.etudiant.classe_nom || resultat.etudiant.filiere}
          </div>
        </div>

        {/* Détails Pointage du jour */}
        <div className="grid grid-cols-3 gap-2.5 p-3 rounded-2xl bg-slate-900/90 border border-slate-800 text-center text-xs">
          <div>
            <div className="text-slate-400 text-[10px] font-semibold uppercase">1ère Arrivée</div>
            <div className="text-sm font-bold text-white font-mono mt-0.5">
              {formaterHeure(resultat.pointage.heure_arrivee)}
            </div>
            <div className="text-[10px] mt-0.5">
              {resultat.pointage.statut_arrivee === 'a_l_heure' ? (
                <span className="text-emerald-400 font-bold">À l'heure</span>
              ) : (
                <span className="text-rose-400 font-bold">Retard</span>
              )}
            </div>
          </div>

          <div>
            <div className="text-slate-400 text-[10px] font-semibold uppercase">Tours Sorties</div>
            <div className="text-sm font-bold text-amber-400 font-mono mt-0.5">
              {resultat.pointage.nombre_tours}
            </div>
            <div className="text-[10px] text-slate-400 mt-0.5">sorties</div>
          </div>

          <div>
            <div className="text-slate-400 text-[10px] font-semibold uppercase">Heure Départ</div>
            <div className="text-sm font-bold text-blue-400 font-mono mt-0.5">
              {resultat.pointage.heure_depart ? formaterHeure(resultat.pointage.heure_depart) : '—'}
            </div>
            <div className="text-[10px] text-slate-400 mt-0.5">dernier scan</div>
          </div>
        </div>
      </div>

      {/* Bouton d'action tactile géant */}
      <button
        onClick={onFermer}
        className="w-full py-4 rounded-2xl bg-blue-600 active:bg-blue-700 text-white font-black text-base shadow-xl shadow-blue-600/30 flex items-center justify-center gap-2 active:scale-95 transition-all uppercase tracking-wide"
      >
        <span>Scan Suivant</span>
        <ChevronRight className="w-5 h-5" />
      </button>
    </div>
  );
};
