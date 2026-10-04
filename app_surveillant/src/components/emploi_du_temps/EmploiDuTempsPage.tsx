import React, { useState, useEffect, useMemo } from 'react';
import { 
  CalendarDays, 
  Clock, 
  Building2, 
  UserCheck, 
  Search, 
  Filter,
  GraduationCap
} from 'lucide-react';
import { db } from '../../db/db';
import type { EmploiDuTempsItem } from '../../types';

export const EmploiDuTempsPage: React.FC = () => {
  const [items, setItems] = useState<EmploiDuTempsItem[]>([]);
  const [jourSelectionne, setJourSelectionne] = useState<string>('Lundi');
  const [filtreClasse, setFiltreClasse] = useState<string>('tous');
  const [recherche, setRecherche] = useState('');

  const jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

  useEffect(() => {
    const charger = async () => {
      const data = await db.emplois_du_temps.toArray();
      setItems(data);
    };
    charger();
  }, []);

  const classesList = useMemo(() => {
    const set = new Set(items.map(i => i.classe_nom).filter(Boolean));
    return Array.from(set);
  }, [items]);

  const itemsFiltres = useMemo(() => {
    return items.filter((item) => {
      const matchJour = item.jour_semaine === jourSelectionne;
      const matchClasse = filtreClasse === 'tous' || item.classe_nom === filtreClasse;
      const matchRech = 
        item.matiere_nom.toLowerCase().includes(recherche.toLowerCase()) ||
        item.salle_nom.toLowerCase().includes(recherche.toLowerCase()) ||
        item.enseignant_nom.toLowerCase().includes(recherche.toLowerCase());

      return matchJour && matchClasse && matchRech;
    });
  }, [items, jourSelectionne, filtreClasse, recherche]);

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-white font-heading">
            Emplois du Temps (Consultation des Salles)
          </h2>
          <p className="text-sm text-slate-400 mt-1">
            Repérage en direct des cours, des salles occupées et des enseignants responsables.
          </p>
        </div>
      </div>

      {/* Onglets des Jours de la Semaine */}
      <div className="flex overflow-x-auto gap-2 p-1.5 rounded-2xl bg-slate-800/60 border border-slate-700/60">
        {jours.map((jour) => (
          <button
            key={jour}
            onClick={() => setJourSelectionne(jour)}
            className={`flex-1 min-w-[100px] py-2.5 px-4 rounded-xl font-semibold text-xs transition-all ${
              jourSelectionne === jour
                ? 'bg-blue-600 text-white shadow-md'
                : 'text-slate-400 hover:text-white hover:bg-slate-700/40'
            }`}
          >
            {jour}
          </button>
        ))}
      </div>

      {/* Filtres */}
      <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg flex flex-col sm:flex-row gap-4">
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
            placeholder="Rechercher matière, salle, enseignant..."
            className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500 placeholder-slate-500"
          />
        </div>

        <select
          value={filtreClasse}
          onChange={(e) => setFiltreClasse(e.target.value)}
          className="px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:outline-none focus:border-blue-500"
        >
          <option value="tous">Toutes les classes</option>
          {classesList.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>

      {/* Liste des Créneaux du Jour */}
      {itemsFiltres.length === 0 ? (
        <div className="p-12 text-center text-slate-400 border border-dashed border-slate-700/80 rounded-2xl space-y-2">
          <CalendarDays className="w-10 h-10 mx-auto text-slate-500" />
          <p className="text-sm">Aucun cours programmé le {jourSelectionne} avec ces filtres.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {itemsFiltres.map((item) => (
            <div key={item.id} className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg space-y-3 hover:border-blue-500/40 transition-all">
              <div className="flex items-start justify-between">
                <div>
                  <span className="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">
                    {item.classe_nom}
                  </span>
                  <h3 className="text-base font-bold text-white font-heading mt-2">
                    {item.matiere_nom}
                  </h3>
                </div>

                <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-900 border border-slate-700 font-mono text-xs font-bold text-blue-400">
                  <Clock className="w-3.5 h-3.5" />
                  <span>{item.heure_debut} - {item.heure_fin}</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-2 pt-2 border-t border-slate-700/50 text-xs">
                <div className="flex items-center gap-2 text-slate-300">
                  <Building2 className="w-4 h-4 text-emerald-400 shrink-0" />
                  <span className="truncate">{item.salle_nom}</span>
                </div>
                <div className="flex items-center gap-2 text-slate-300">
                  <UserCheck className="w-4 h-4 text-indigo-400 shrink-0" />
                  <span className="truncate">{item.enseignant_nom}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
