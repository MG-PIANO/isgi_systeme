import React, { useState, useEffect, useMemo } from 'react';
import { 
  DoorOpen, 
  Search, 
  Users, 
  CheckCircle2, 
  AlertCircle, 
  Wrench, 
  Clock, 
  Filter,
  Building2,
  RefreshCw
} from 'lucide-react';
import { db } from '../../db/db';
import type { Salle } from '../../types';

export const SallesPage: React.FC = () => {
  const [salles, setSalles] = useState<Salle[]>([]);
  const [recherche, setRecherche] = useState('');
  const [filtreStatut, setFiltreStatut] = useState<string>('tous');
  const [filtreType, setFiltreType] = useState<string>('tous');

  const chargerSalles = async () => {
    const data = await db.salles.toArray();
    setSalles(data);
  };

  useEffect(() => {
    chargerSalles();
  }, []);

  const basculerStatut = async (salle: Salle, nouveauStatut: Salle['statut']) => {
    const updated = { ...salle, statut: nouveauStatut };
    await db.salles.put(updated);
    setSalles(prev => prev.map(s => s.id === salle.id ? updated : s));
  };

  const sallesFiltrees = useMemo(() => {
    return salles.filter((s) => {
      const matchRech = 
        s.nom.toLowerCase().includes(recherche.toLowerCase()) ||
        s.code.toLowerCase().includes(recherche.toLowerCase());

      const matchStatut = filtreStatut === 'tous' || s.statut === filtreStatut;
      const matchType = filtreType === 'tous' || s.type === filtreType;

      return matchRech && matchStatut && matchType;
    });
  }, [salles, recherche, filtreStatut, filtreType]);

  const stats = useMemo(() => {
    const total = salles.length;
    const disponibles = salles.filter(s => s.statut === 'disponible').length;
    const occupees = salles.filter(s => s.statut === 'occupee').length;
    const maintenance = salles.filter(s => s.statut === 'maintenance').length;
    return { total, disponibles, occupees, maintenance };
  }, [salles]);

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-white font-heading">
            Surveillance & État des Salles
          </h2>
          <p className="text-sm text-slate-400 mt-1">
            Supervision de la disponibilité des amphithéâtres, laboratoires et salles de cours.
          </p>
        </div>

        <button
          onClick={chargerSalles}
          className="flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs transition-all"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          <span>Actualiser</span>
        </button>
      </div>

      {/* Cartes Compteurs */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="p-4 rounded-xl bg-slate-800/60 border border-slate-700/60 text-center">
          <div className="text-xs text-slate-400 uppercase font-semibold">Total Salles</div>
          <div className="text-2xl font-extrabold text-white mt-1 font-heading">{stats.total}</div>
        </div>
        <div className="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-center">
          <div className="text-xs text-emerald-400 uppercase font-semibold">Disponibles</div>
          <div className="text-2xl font-extrabold text-emerald-400 mt-1 font-heading">{stats.disponibles}</div>
        </div>
        <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-center">
          <div className="text-xs text-rose-400 uppercase font-semibold">Occupées</div>
          <div className="text-2xl font-extrabold text-rose-400 mt-1 font-heading">{stats.occupees}</div>
        </div>
        <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-center">
          <div className="text-xs text-amber-400 uppercase font-semibold">Maintenance</div>
          <div className="text-2xl font-extrabold text-amber-400 mt-1 font-heading">{stats.maintenance}</div>
        </div>
      </div>

      {/* Barre de Recherche et Filtres */}
      <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg flex flex-col sm:flex-row gap-4">
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
            placeholder="Rechercher par nom ou code de salle..."
            className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500 placeholder-slate-500"
          />
        </div>

        <select
          value={filtreStatut}
          onChange={(e) => setFiltreStatut(e.target.value)}
          className="px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:outline-none focus:border-blue-500"
        >
          <option value="tous">Tous les statuts</option>
          <option value="disponible">Disponible</option>
          <option value="occupee">Occupée</option>
          <option value="maintenance">Maintenance</option>
        </select>

        <select
          value={filtreType}
          onChange={(e) => setFiltreType(e.target.value)}
          className="px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:outline-none focus:border-blue-500"
        >
          <option value="tous">Tous les types</option>
          <option value="amphi">Amphithéâtre</option>
          <option value="tp">Laboratoire TP</option>
          <option value="td">Salle TD</option>
        </select>
      </div>

      {/* Grille des Salles */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        {sallesFiltrees.map((salle) => (
          <div
            key={salle.id}
            className={`p-5 rounded-2xl bg-slate-800/60 border shadow-lg space-y-4 transition-all hover:scale-[1.01] ${
              salle.statut === 'disponible' 
                ? 'border-emerald-500/30 hover:border-emerald-500/50' 
                : salle.statut === 'occupee'
                ? 'border-rose-500/30 hover:border-rose-500/50'
                : 'border-amber-500/30 hover:border-amber-500/50'
            }`}
          >
            <div className="flex items-start justify-between">
              <div>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold bg-slate-700 text-slate-300">
                  {salle.code}
                </span>
                <h3 className="text-base font-bold text-white font-heading mt-1.5">{salle.nom}</h3>
                <div className="text-xs text-slate-400 capitalize">{salle.type}</div>
              </div>

              <span className={`px-2.5 py-1 rounded-full text-xs font-bold ${
                salle.statut === 'disponible'
                  ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                  : salle.statut === 'occupee'
                  ? 'bg-rose-500/20 text-rose-400 border border-rose-500/30'
                  : 'bg-amber-500/20 text-amber-400 border border-amber-500/30'
              }`}>
                {salle.statut === 'disponible' ? 'Disponible' : salle.statut === 'occupee' ? 'Occupée' : 'Maintenance'}
              </span>
            </div>

            <p className="text-xs text-slate-400">{salle.description || 'Aucune description disponible.'}</p>

            <div className="flex items-center justify-between border-t border-slate-700/60 pt-3 text-xs">
              <span className="flex items-center gap-1.5 text-slate-400">
                <Users className="w-3.5 h-3.5 text-blue-400" />
                <span>Capacité : <strong className="text-slate-200">{salle.capacite} places</strong></span>
              </span>

              {/* Sélecteur de statut rapide */}
              <select
                value={salle.statut}
                onChange={(e) => basculerStatut(salle, e.target.value as Salle['statut'])}
                className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs text-slate-300 focus:outline-none focus:border-blue-500"
              >
                <option value="disponible">Disponible</option>
                <option value="occupee">Occupée</option>
                <option value="maintenance">Maintenance</option>
              </select>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};
