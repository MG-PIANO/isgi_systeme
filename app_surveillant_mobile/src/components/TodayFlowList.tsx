import React, { useState } from 'react';
import { 
  Users, 
  Search, 
  X, 
  Clock, 
  CheckCircle2, 
  AlertTriangle,
  Building2,
  LogOut,
  RotateCw
} from 'lucide-react';
import type { PointageAcces } from '../types';
import { formaterHeure } from '../lib/utils';

interface TodayFlowListProps {
  pointages: PointageAcces[];
  onFermer: () => void;
  onRafraichir: () => void;
}

export const TodayFlowList: React.FC<TodayFlowListProps> = ({ pointages, onFermer, onRafraichir }) => {
  const [filtre, setFiltre] = useState<'tous' | 'sur_site' | 'sortis'>('tous');
  const [recherche, setRecherche] = useState('');

  const pointagesFiltres = pointages.filter((p) => {
    const matchRech = 
      p.nom.toLowerCase().includes(recherche.toLowerCase()) ||
      p.prenom.toLowerCase().includes(recherche.toLowerCase()) ||
      p.matricule.toLowerCase().includes(recherche.toLowerCase());

    if (filtre === 'sur_site') return matchRech && p.statut_actuel === 'sur_site';
    if (filtre === 'sortis') return matchRech && p.statut_actuel === 'sorti';
    return matchRech;
  });

  const countSurSite = pointages.filter(p => p.statut_actuel === 'sur_site').length;
  const countSortis = pointages.filter(p => p.statut_actuel === 'sorti').length;

  return (
    <div className="fixed inset-0 z-50 bg-slate-950/95 backdrop-blur-md flex flex-col justify-between p-4 pb-safe pt-safe animate-fadeIn">
      {/* En-tête */}
      <div className="flex items-center justify-between pb-3 border-b border-slate-800">
        <div>
          <h3 className="text-base font-bold text-white font-heading">
            Flux du Jour ({pointages.length})
          </h3>
          <p className="text-xs text-slate-400">Pointages en direct au portail</p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={onRafraichir}
            className="p-2 rounded-full bg-slate-800 text-slate-400 active:text-white"
            title="Actualiser"
          >
            <RotateCw className="w-4 h-4" />
          </button>
          <button
            onClick={onFermer}
            className="p-2 rounded-full bg-slate-800 text-slate-400 active:text-white"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
      </div>

      {/* Barre de Recherche */}
      <div className="my-3">
        <input
          type="text"
          value={recherche}
          onChange={(e) => setRecherche(e.target.value)}
          placeholder="Rechercher par nom ou matricule..."
          className="w-full px-4 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs placeholder-slate-500 focus:outline-none focus:border-blue-500"
        />
      </div>

      {/* Onglets Filtres */}
      <div className="flex gap-2 mb-3">
        <button
          onClick={() => setFiltre('tous')}
          className={`flex-1 py-2 rounded-xl text-xs font-bold transition-all ${
            filtre === 'tous' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400'
          }`}
        >
          Tous ({pointages.length})
        </button>
        <button
          onClick={() => setFiltre('sur_site')}
          className={`flex-1 py-2 rounded-xl text-xs font-bold transition-all ${
            filtre === 'sur_site' ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-400'
          }`}
        >
          Sur Site ({countSurSite})
        </button>
        <button
          onClick={() => setFiltre('sortis')}
          className={`flex-1 py-2 rounded-xl text-xs font-bold transition-all ${
            filtre === 'sortis' ? 'bg-amber-600 text-white' : 'bg-slate-900 text-slate-400'
          }`}
        >
          Sortis ({countSortis})
        </button>
      </div>

      {/* Liste des étudiants */}
      <div className="flex-1 overflow-y-auto space-y-2 pr-1">
        {pointagesFiltres.length === 0 ? (
          <div className="py-12 text-center text-slate-500 text-xs">
            Aucun pointage trouvé.
          </div>
        ) : (
          pointagesFiltres.map((p) => (
            <div
              key={p.id}
              className="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between"
            >
              <div className="flex items-center gap-3">
                {p.photo_url ? (
                  <img src={p.photo_url} alt={p.nom} className="w-11 h-11 rounded-full object-cover border border-slate-700" />
                ) : (
                  <div className="w-11 h-11 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-white">
                    {p.nom.charAt(0)}{p.prenom.charAt(0)}
                  </div>
                )}
                <div>
                  <div className="font-bold text-sm text-white">{p.nom} {p.prenom}</div>
                  <div className="text-[11px] text-slate-400 font-mono">{p.matricule} • {p.classe_nom}</div>
                  <div className="flex items-center gap-2 mt-1">
                    <span className="text-[10px] text-slate-400">
                      Arr : <strong>{formaterHeure(p.heure_arrivee)}</strong>
                    </span>
                    <span className="text-[10px] text-amber-400">
                      • {p.nombre_tours} {p.nombre_tours > 1 ? 'tours' : 'tour'}
                    </span>
                  </div>
                </div>
              </div>

              <div>
                {p.statut_actuel === 'sur_site' ? (
                  <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                    <Building2 className="w-3 h-3" /> Sur Site
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                    <LogOut className="w-3 h-3" /> Sorti
                  </span>
                )}
              </div>
            </div>
          ))
        )}
      </div>

      {/* Bouton Fermer */}
      <button
        onClick={onFermer}
        className="w-full mt-3 py-3.5 rounded-xl bg-slate-800 active:bg-slate-700 text-white font-bold text-xs uppercase tracking-wider"
      >
        Retour au Scanner
      </button>
    </div>
  );
};
