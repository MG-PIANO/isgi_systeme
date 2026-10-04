import React, { useState, useEffect } from 'react';
import { 
  Users, 
  UserCheck, 
  UserMinus, 
  Clock, 
  ArrowUpRight, 
  ArrowDownRight, 
  QrCode, 
  CheckCircle2, 
  AlertTriangle,
  RotateCw,
  Search,
  Sparkles,
  Building2,
  Calendar
} from 'lucide-react';
import { db } from '../../db/db';
import type { PointageAcces, StatsJour, Etudiant } from '../../types';
import { formaterHeure, formaterDateFr } from '../../lib/utils';
import { getDateAujourdhui } from '../../services/scanEngine';
import { getStatsAujourdhui } from '../../db/db';

interface DashboardPageProps {
  onOuvrirScanner: () => void;
  onVoirRegistre: () => void;
}

export const DashboardPage: React.FC<DashboardPageProps> = ({ onOuvrirScanner, onVoirRegistre }) => {
  const [stats, setStats] = useState<StatsJour>({
    totalInscrits: 0,
    totalArrives: 0,
    actuellementSurSite: 0,
    actuellementSortis: 0,
    aLHeure: 0,
    enRetard: 0,
    totalToursSorties: 0,
    tauxPresence: 0
  });

  const [derniersPointages, setDerniersPointages] = useState<PointageAcces[]>([]);
  const [loading, setLoading] = useState(true);

  const chargerDonnees = async () => {
    try {
      const dateJour = getDateAujourdhui();
      const statsCalculees = await getStatsAujourdhui();
      setStats(statsCalculees);

      const pointages = await db.pointages
        .where('date_jour')
        .equals(dateJour)
        .reverse()
        .sortBy('updated_at');

      setDerniersPointages(pointages.slice(0, 10));
    } catch (e) {
      console.error('Erreur chargement dashboard:', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    chargerDonnees();
    const interval = setInterval(chargerDonnees, 5000); // Rafraîchissement automatique toutes les 5s
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="p-8 space-y-8 max-w-7xl mx-auto">
      {/* Bannière de Bienvenue */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl bg-gradient-to-r from-blue-900/60 via-slate-800/80 to-slate-900/90 border border-blue-500/20 shadow-xl relative overflow-hidden">
        <div className="absolute right-0 top-0 bottom-0 w-1/3 bg-gradient-to-l from-blue-500/10 to-transparent pointer-events-none"></div>
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
              Contrôle d'accès & Présence Journalière
            </span>
            <span className="text-xs text-slate-400">• Aujourd'hui : {getDateAujourdhui()}</span>
          </div>
          <h2 className="text-2xl font-extrabold text-white font-heading">
            Tableau de Bord du Surveillant Général
          </h2>
          <p className="text-sm text-slate-300 mt-1 max-w-2xl">
            Suivi en temps réel des flux d'entrées, des tours de sorties et des départs des étudiants.
          </p>
        </div>

        <div className="flex items-center gap-3 shrink-0">
          <button
            onClick={onOuvrirScanner}
            className="flex items-center gap-2.5 px-5 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-semibold text-sm shadow-lg shadow-blue-600/30 transition-all hover:scale-105 active:scale-95"
          >
            <QrCode className="w-5 h-5" />
            <span>Ouvrir le Scanner Direct</span>
          </button>
        </div>
      </div>

      {/* 4 Cartes KPIs Principales */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        {/* KPI 1 : Total Arrivés */}
        <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg relative overflow-hidden group hover:border-blue-500/40 transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Arrivés</span>
            <div className="w-10 h-10 rounded-xl bg-blue-500/20 border border-blue-500/30 flex items-center justify-center text-blue-400">
              <UserCheck className="w-5 h-5" />
            </div>
          </div>
          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-white font-heading">{stats.totalArrives}</span>
            <span className="text-xs text-slate-400">/ {stats.totalInscrits} inscrits</span>
          </div>
          <div className="mt-3 flex items-center justify-between text-xs text-slate-400 border-t border-slate-700/50 pt-2.5">
            <span>Taux de présence</span>
            <span className="font-bold text-blue-400">{stats.tauxPresence}%</span>
          </div>
        </div>

        {/* KPI 2 : Actuellement Sur Site */}
        <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg relative overflow-hidden group hover:border-emerald-500/40 transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Sur Site Actuellement</span>
            <div className="w-10 h-10 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
              <Building2 className="w-5 h-5" />
            </div>
          </div>
          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-emerald-400 font-heading">{stats.actuellementSurSite}</span>
            <span className="text-xs text-emerald-500/80 font-medium">dans l'école</span>
          </div>
          <div className="mt-3 flex items-center justify-between text-xs text-slate-400 border-t border-slate-700/50 pt-2.5">
            <span>Indicateur sécurité</span>
            <span className="flex items-center gap-1 text-emerald-400 font-medium">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Présents
            </span>
          </div>
        </div>

        {/* KPI 3 : Actuellement Sortis */}
        <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg relative overflow-hidden group hover:border-amber-500/40 transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Sortis / En Pause</span>
            <div className="w-10 h-10 rounded-xl bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-400">
              <UserMinus className="w-5 h-5" />
            </div>
          </div>
          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-amber-400 font-heading">{stats.actuellementSortis}</span>
            <span className="text-xs text-amber-500/80 font-medium">hors établissement</span>
          </div>
          <div className="mt-3 flex items-center justify-between text-xs text-slate-400 border-t border-slate-700/50 pt-2.5">
            <span>Total tours du jour</span>
            <span className="font-bold text-amber-400">{stats.totalToursSorties} sorties temporaires</span>
          </div>
        </div>

        {/* KPI 4 : Ponctualité & Retards */}
        <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg relative overflow-hidden group hover:border-rose-500/40 transition-all">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Ponctualité</span>
            <div className="w-10 h-10 rounded-xl bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
              <Clock className="w-5 h-5" />
            </div>
          </div>
          <div className="flex items-center justify-between">
            <div>
              <div className="text-2xl font-bold text-emerald-400">{stats.aLHeure}</div>
              <div className="text-[11px] text-slate-400">À l'heure</div>
            </div>
            <div className="h-8 w-px bg-slate-700"></div>
            <div>
              <div className="text-2xl font-bold text-rose-400">{stats.enRetard}</div>
              <div className="text-[11px] text-slate-400">En Retard</div>
            </div>
          </div>
          <div className="mt-3 flex items-center justify-between text-xs text-slate-400 border-t border-slate-700/50 pt-2.5">
            <span>Seuil limite</span>
            <span className="font-medium text-slate-300">08:15 max</span>
          </div>
        </div>
      </div>

      {/* Derniers Passages en direct */}
      <div className="p-6 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-blue-500/20 flex items-center justify-center text-blue-400">
              <RotateCw className="w-4 h-4 animate-spin text-blue-400" />
            </div>
            <div>
              <h3 className="text-base font-bold text-white font-heading">
                Flux des Passages en Direct (Aujourd'hui)
              </h3>
              <p className="text-xs text-slate-400">
                Mise à jour en temps réel des scans au portail (Mobile & PC)
              </p>
            </div>
          </div>

          <button
            onClick={onVoirRegistre}
            className="text-xs font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1 transition-colors"
          >
            <span>Voir le registre complet</span>
            <ArrowUpRight className="w-4 h-4" />
          </button>
        </div>

        {/* Tableau des récents */}
        {derniersPointages.length === 0 ? (
          <div className="text-center py-12 border border-dashed border-slate-700/80 rounded-xl text-slate-400 space-y-2">
            <QrCode className="w-10 h-10 mx-auto text-slate-500" />
            <p className="text-sm">Aucun scan enregistré pour le moment aujourd'hui.</p>
            <p className="text-xs text-slate-500">Ouvrez le scanner ou lancez l'application mobile pour commencer.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-slate-300">
              <thead className="bg-slate-900/60 text-xs font-semibold uppercase tracking-wider text-slate-400 border-b border-slate-700">
                <tr>
                  <th className="py-3 px-4">Étudiant</th>
                  <th className="py-3 px-4">Classe / Filière</th>
                  <th className="py-3 px-4">1ère Arrivée</th>
                  <th className="py-3 px-4">Ponctualité</th>
                  <th className="py-3 px-4 text-center">Tours / Sorties</th>
                  <th className="py-3 px-4">Dernier Scan / Départ</th>
                  <th className="py-3 px-4 text-right">Statut Actuel</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-700/40">
                {derniersPointages.map((p) => (
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
                    <td className="py-3.5 px-4 text-right">
                      {p.statut_actuel === 'sur_site' ? (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                          Sur Site
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                          <span className="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                          Sorti
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};
