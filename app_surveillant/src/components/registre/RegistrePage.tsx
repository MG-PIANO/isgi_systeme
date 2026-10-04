import React, { useState, useEffect, useMemo } from 'react';
import { 
  Search, 
  Filter, 
  Download, 
  FileSpreadsheet, 
  Calendar, 
  Clock, 
  CheckCircle2, 
  AlertTriangle, 
  User, 
  Eye, 
  X,
  Building2,
  RefreshCw,
  LogOut
} from 'lucide-react';
import { db } from '../../db/db';
import type { PointageAcces } from '../../types';
import { getDateAujourdhui } from '../../services/scanEngine';
import { formaterHeure, formaterDateFr } from '../../lib/utils';
import * as XLSX from 'xlsx';

export const RegistrePage: React.FC = () => {
  const [dateSelectionnee, setDateSelectionnee] = useState(getDateAujourdhui());
  const [pointages, setPointages] = useState<PointageAcces[]>([]);
  const [loading, setLoading] = useState(true);
  const [recherche, setRecherche] = useState('');
  const [filtreStatut, setFiltreStatut] = useState<string>('tous');
  const [filtreClasse, setFiltreClasse] = useState<string>('tous');
  const [pointageDetail, setPointageDetail] = useState<PointageAcces | null>(null);

  const chargerPointages = async (date: string) => {
    setLoading(true);
    try {
      const data = await db.pointages
        .where('date_jour')
        .equals(date)
        .reverse()
        .sortBy('updated_at');
      setPointages(data);
    } catch (e) {
      console.error('Erreur chargement pointages:', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    chargerPointages(dateSelectionnee);
  }, [dateSelectionnee]);

  // Liste des classes disponibles
  const classesList = useMemo(() => {
    const set = new Set(pointages.map(p => p.classe_nom).filter(Boolean));
    return Array.from(set);
  }, [pointages]);

  // Filtrage des résultats
  const pointagesFiltres = useMemo(() => {
    return pointages.filter((p) => {
      const matchRecherche = 
        p.nom.toLowerCase().includes(recherche.toLowerCase()) ||
        p.prenom.toLowerCase().includes(recherche.toLowerCase()) ||
        p.matricule.toLowerCase().includes(recherche.toLowerCase());

      const matchClasse = filtreClasse === 'tous' || p.classe_nom === filtreClasse;

      let matchStatut = true;
      if (filtreStatut === 'sur_site') matchStatut = p.statut_actuel === 'sur_site';
      if (filtreStatut === 'sorti') matchStatut = p.statut_actuel === 'sorti';
      if (filtreStatut === 'a_l_heure') matchStatut = p.statut_arrivee === 'a_l_heure';
      if (filtreStatut === 'en_retard') matchStatut = p.statut_arrivee === 'en_retard';

      return matchRecherche && matchClasse && matchStatut;
    });
  }, [pointages, recherche, filtreClasse, filtreStatut]);

  // Export Excel
  const exporterExcel = () => {
    const lignes = pointagesFiltres.map((p) => ({
      'Matricule': p.matricule,
      'Nom': p.nom,
      'Prénom': p.prenom,
      'Classe': p.classe_nom,
      'Date': p.date_jour,
      '1ère Arrivée': p.heure_arrivee,
      'Ponctualité': p.statut_arrivee === 'a_l_heure' ? 'À l\'heure' : 'En retard',
      'Tours de sorties': p.nombre_tours,
      'Dernier Départ': p.heure_depart || '—',
      'Statut Actuel': p.statut_actuel === 'sur_site' ? 'Sur Site' : 'Sorti',
      'Dernier Appareil': p.appareil_dernier_scan || 'pc'
    }));

    const ws = XLSX.utils.json_to_sheet(lignes);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Passages');
    XLSX.writeFile(wb, `Registre_Passages_${dateSelectionnee}.xlsx`);
  };

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-white font-heading">
            Registre des Passages & Pointages Journaliers
          </h2>
          <p className="text-sm text-slate-400 mt-1">
            Historique complet des heures d'arrivée, des tours de sorties et des départs.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={exporterExcel}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm shadow-lg shadow-emerald-600/20 transition-all"
          >
            <FileSpreadsheet className="w-4 h-4" />
            <span>Exporter Excel (.xlsx)</span>
          </button>
        </div>
      </div>

      {/* Barre de Filtres & Recherche */}
      <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Sélection Date */}
          <div>
            <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">
              Date du jour
            </label>
            <div className="relative">
              <input
                type="date"
                value={dateSelectionnee}
                onChange={(e) => setDateSelectionnee(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
              />
              <Calendar className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>
          </div>

          {/* Recherche */}
          <div>
            <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">
              Rechercher Étudiant
            </label>
            <div className="relative">
              <input
                type="text"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="Nom, prénom, matricule..."
                className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500 placeholder-slate-500"
              />
              <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>
          </div>

          {/* Filtre Classe */}
          <div>
            <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">
              Classe / Promotion
            </label>
            <select
              value={filtreClasse}
              onChange={(e) => setFiltreClasse(e.target.value)}
              className="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
            >
              <option value="tous">Toutes les classes</option>
              {classesList.map((c) => (
                <option key={c} value={c}>{c}</option>
              ))}
            </select>
          </div>

          {/* Filtre Statut */}
          <div>
            <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">
              Statut du passage
            </label>
            <select
              value={filtreStatut}
              onChange={(e) => setFiltreStatut(e.target.value)}
              className="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
            >
              <option value="tous">Tous les statuts</option>
              <option value="sur_site">Actuellement sur site</option>
              <option value="sorti">Actuellement sortis</option>
              <option value="a_l_heure">Arrivés à l'heure</option>
              <option value="en_retard">Arrivés en retard</option>
            </select>
          </div>
        </div>
      </div>

      {/* Tableau des Enregistrements */}
      <div className="rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl overflow-hidden">
        <div className="p-4 border-b border-slate-700/80 flex items-center justify-between text-xs text-slate-400">
          <span>{pointagesFiltres.length} étudiant(s) enregistré(s) le {formaterDateFr(dateSelectionnee)}</span>
          <button onClick={() => chargerPointages(dateSelectionnee)} className="flex items-center gap-1 hover:text-white transition-colors">
            <RefreshCw className="w-3.5 h-3.5" />
            <span>Actualiser</span>
          </button>
        </div>

        {loading ? (
          <div className="py-16 text-center text-slate-400">
            <RefreshCw className="w-8 h-8 animate-spin mx-auto text-blue-500 mb-2" />
            <span>Chargement des pointages...</span>
          </div>
        ) : pointagesFiltres.length === 0 ? (
          <div className="py-16 text-center text-slate-400">
            <p className="text-sm">Aucun enregistrement ne correspond aux filtres pour cette date.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-slate-300">
              <thead className="bg-slate-900/80 text-xs font-semibold uppercase tracking-wider text-slate-400 border-b border-slate-700">
                <tr>
                  <th className="py-3.5 px-4">Étudiant</th>
                  <th className="py-3.5 px-4">Classe</th>
                  <th className="py-3.5 px-4">1ère Arrivée</th>
                  <th className="py-3.5 px-4">Ponctualité</th>
                  <th className="py-3.5 px-4 text-center">Tours de Sorties</th>
                  <th className="py-3.5 px-4">Dernier Scan / Départ</th>
                  <th className="py-3.5 px-4">Position</th>
                  <th className="py-3.5 px-4 text-right">Détails</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-700/40">
                {pointagesFiltres.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-700/20 transition-colors">
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-3">
                        {p.photo_url ? (
                          <img src={p.photo_url} alt={p.nom} className="w-9 h-9 rounded-full object-cover border border-slate-600" />
                        ) : (
                          <div className="w-9 h-9 rounded-full bg-slate-700 flex items-center justify-center font-bold text-xs text-slate-300 border border-slate-600">
                            {p.nom.charAt(0)}{p.prenom.charAt(0)}
                          </div>
                        )}
                        <div>
                          <div className="font-semibold text-white">{p.nom} {p.prenom}</div>
                          <div className="text-xs text-slate-400 font-mono">{p.matricule}</div>
                        </div>
                      </div>
                    </td>
                    <td className="py-3.5 px-4 font-medium text-slate-300">{p.classe_nom}</td>
                    <td className="py-3.5 px-4 font-mono font-semibold text-slate-200">
                      {formaterHeure(p.heure_arrivee)}
                    </td>
                    <td className="py-3.5 px-4">
                      {p.statut_arrivee === 'a_l_heure' ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                          <CheckCircle2 className="w-3.5 h-3.5" /> À l'heure
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-400 border border-rose-500/30">
                          <AlertTriangle className="w-3.5 h-3.5" /> En Retard
                        </span>
                      )}
                    </td>
                    <td className="py-3.5 px-4 text-center">
                      <span className={`inline-block px-2.5 py-0.5 rounded-full text-xs font-bold ${
                        p.nombre_tours > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-slate-700/50 text-slate-400'
                      }`}>
                        {p.nombre_tours} {p.nombre_tours > 1 ? 'tours' : 'tour'}
                      </span>
                    </td>
                    <td className="py-3.5 px-4 font-mono text-slate-300">
                      {p.heure_depart ? formaterHeure(p.heure_depart) : '—'}
                    </td>
                    <td className="py-3.5 px-4">
                      {p.statut_actuel === 'sur_site' ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                          Sur Site
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                          Sorti
                        </span>
                      )}
                    </td>
                    <td className="py-3.5 px-4 text-right">
                      <button
                        onClick={() => setPointageDetail(p)}
                        className="p-1.5 rounded-lg bg-slate-700/60 hover:bg-slate-600 text-slate-300 hover:text-white transition-colors"
                        title="Voir la timeline complète"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal Détails & Timeline Chronologique */}
      {pointageDetail && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-5 shadow-2xl relative">
            <button
              onClick={() => setPointageDetail(null)}
              className="absolute right-4 top-4 text-slate-400 hover:text-white p-1 rounded-lg"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-4">
              {pointageDetail.photo_url ? (
                <img src={pointageDetail.photo_url} alt={pointageDetail.nom} className="w-14 h-14 rounded-xl object-cover border border-slate-700" />
              ) : (
                <div className="w-14 h-14 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-slate-300">
                  {pointageDetail.nom.charAt(0)}{pointageDetail.prenom.charAt(0)}
                </div>
              )}
              <div>
                <h3 className="text-lg font-bold text-white font-heading">
                  {pointageDetail.nom} {pointageDetail.prenom}
                </h3>
                <div className="text-xs text-slate-400 font-mono">{pointageDetail.matricule} • {pointageDetail.classe_nom}</div>
              </div>
            </div>

            {/* Récapitulatif */}
            <div className="grid grid-cols-3 gap-2.5 p-3 rounded-xl bg-slate-800/60 border border-slate-700/60 text-center text-xs">
              <div>
                <div className="text-slate-400 text-[10px] uppercase font-semibold">Arrivée</div>
                <div className="font-bold text-white font-mono mt-0.5">{formaterHeure(pointageDetail.heure_arrivee)}</div>
              </div>
              <div>
                <div className="text-slate-400 text-[10px] uppercase font-semibold">Tours Sorties</div>
                <div className="font-bold text-amber-400 font-mono mt-0.5">{pointageDetail.nombre_tours}</div>
              </div>
              <div>
                <div className="text-slate-400 text-[10px] uppercase font-semibold">Dernier Départ</div>
                <div className="font-bold text-blue-400 font-mono mt-0.5">{pointageDetail.heure_depart ? formaterHeure(pointageDetail.heure_depart) : 'Présent'}</div>
              </div>
            </div>

            {/* Timeline */}
            <div className="space-y-3">
              <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                Journal Chronologique des Scans
              </div>
              <div className="space-y-2 max-h-56 overflow-y-auto pr-1">
                {pointageDetail.historique_scans.map((log, i) => (
                  <div key={log.id} className="p-3 rounded-xl bg-slate-800/40 border border-slate-700/50 flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2.5">
                      <span className="w-5 h-5 rounded-full bg-slate-700 flex items-center justify-center font-bold text-[10px] text-slate-300">
                        {i + 1}
                      </span>
                      <div>
                        <div className="font-semibold text-white capitalize">
                          {log.type === 'arrivee' ? '1ère Arrivée à l\'école' : log.type === 'sortie_tour' ? `Sortie école (Tour ${log.tour_numero})` : 'Retour sur site'}
                        </div>
                        <div className="text-[10px] text-slate-400">Appareil : {log.appareil}</div>
                      </div>
                    </div>
                    <div className="font-mono text-blue-400 font-bold">
                      {formaterHeure(log.timestamp)}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
