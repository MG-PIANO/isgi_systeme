import React, { useState, useEffect, useMemo, useRef } from 'react';
import { 
  Search, 
  Calendar, 
  Clock, 
  CheckCircle2, 
  AlertTriangle, 
  Eye, 
  X, 
  RefreshCw,
  FileSpreadsheet,
  Printer,
  CalendarDays,
  CalendarRange,
  ChevronLeft,
  ChevronRight,
  UserCheck,
  Building2,
  Users
} from 'lucide-react';
import { db, getPointagesParPeriode } from '../../db/db';
import type { PointageAcces } from '../../types';
import { getDateAujourdhui } from '../../services/scanEngine';
import { formaterHeure, formaterDateFr } from '../../lib/utils';
import * as XLSX from 'xlsx';

type ModePeriode = 'jour' | 'semaine' | 'mois';

export const RegistrePage: React.FC = () => {
  const [modePeriode, setModePeriode] = useState<ModePeriode>('jour');
  const [dateJour, setDateJour] = useState(getDateAujourdhui());
  const [dateSemaineRef, setDateSemaineRef] = useState(getDateAujourdhui());
  const [moisSelectionne, setMoisSelectionne] = useState(() => {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
  });

  const [pointages, setPointages] = useState<PointageAcces[]>([]);
  const [loading, setLoading] = useState(true);
  const [recherche, setRecherche] = useState('');
  const [filtreStatut, setFiltreStatut] = useState<string>('tous');
  const [filtreClasse, setFiltreClasse] = useState<string>('tous');
  const [pointageDetail, setPointageDetail] = useState<PointageAcces | null>(null);
  const [apercuImpression, setApercuImpression] = useState(false);

  // Calcul des bornes de dates selon le mode
  const { dateDebut, dateFin, libellePeriode } = useMemo(() => {
    if (modePeriode === 'jour') {
      return {
        dateDebut: dateJour,
        dateFin: dateJour,
        libellePeriode: `Journée du ${formaterDateFr(dateJour)}`
      };
    }

    if (modePeriode === 'semaine') {
      // Trouver le lundi et le dimanche de la semaine
      const ref = new Date(dateSemaineRef);
      const day = ref.getDay(); // 0: Dimanche, 1: Lundi, ...
      const diffToMonday = day === 0 ? -6 : 1 - day;
      
      const lundi = new Date(ref);
      lundi.setDate(ref.getDate() + diffToMonday);

      const dimanche = new Date(lundi);
      dimanche.setDate(lundi.getDate() + 6);

      const debutStr = lundi.toISOString().split('T')[0];
      const finStr = dimanche.toISOString().split('T')[0];

      return {
        dateDebut: debutStr,
        dateFin: finStr,
        libellePeriode: `Semaine du ${formaterDateFr(debutStr)} au ${formaterDateFr(finStr)}`
      };
    }

    // Mode Mois
    const [anneeStr, moisStr] = moisSelectionne.split('-');
    const annee = parseInt(anneeStr, 10);
    const mois = parseInt(moisStr, 10);

    const premierJour = `${anneeStr}-${moisStr.padStart(2, '0')}-01`;
    const dernierJourDate = new Date(annee, mois, 0); // Dernier jour du mois
    const finStr = `${anneeStr}-${moisStr.padStart(2, '0')}-${String(dernierJourDate.getDate()).padStart(2, '0')}`;

    const nomMois = dernierJourDate.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    const nomMoisMaj = nomMois.charAt(0).toUpperCase() + nomMois.slice(1);

    return {
      dateDebut: premierJour,
      dateFin: finStr,
      libellePeriode: `Mois de ${nomMoisMaj}`
    };
  }, [modePeriode, dateJour, dateSemaineRef, moisSelectionne]);

  // Chargement des pointages
  const chargerDonnees = async () => {
    setLoading(true);
    try {
      const data = await getPointagesParPeriode(dateDebut, dateFin);
      setPointages(data);
    } catch (e) {
      console.error('Erreur chargement pointages:', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    chargerDonnees();
  }, [dateDebut, dateFin]);

  // Liste des classes
  const classesList = useMemo(() => {
    const set = new Set(pointages.map(p => p.classe_nom).filter(Boolean));
    return Array.from(set).sort();
  }, [pointages]);

  // Filtrage des résultats
  const pointagesFiltres = useMemo(() => {
    const q = recherche.trim().toLowerCase();
    return pointages.filter((p) => {
      const matchRecherche = !q ||
        (p.nom || '').toLowerCase().includes(q) ||
        (p.prenom || '').toLowerCase().includes(q) ||
        (p.matricule || '').toLowerCase().includes(q) ||
        `${(p.nom || '').toLowerCase()} ${(p.prenom || '').toLowerCase()}`.includes(q);

      const matchClasse = filtreClasse === 'tous' || p.classe_nom === filtreClasse;

      let matchStatut = true;
      if (filtreStatut === 'sur_site') matchStatut = p.statut_actuel === 'sur_site';
      if (filtreStatut === 'sorti') matchStatut = p.statut_actuel === 'sorti';
      if (filtreStatut === 'a_l_heure') matchStatut = p.statut_arrivee === 'a_l_heure';
      if (filtreStatut === 'en_retard') matchStatut = p.statut_arrivee === 'en_retard';

      return matchRecherche && matchClasse && matchStatut;
    });
  }, [pointages, recherche, filtreClasse, filtreStatut]);

  // Statistiques calculées sur la période
  const statsPeriode = useMemo(() => {
    const totalScans = pointagesFiltres.length;
    const etudiantsUniques = new Set(pointagesFiltres.map(p => p.matricule)).size;
    const aLHeure = pointagesFiltres.filter(p => p.statut_arrivee === 'a_l_heure').length;
    const enRetard = pointagesFiltres.filter(p => p.statut_arrivee === 'en_retard').length;
    const totalTours = pointagesFiltres.reduce((acc, p) => acc + (p.nombre_tours || 0), 0);
    const tauxPonctualite = totalScans > 0 ? Math.round((aLHeure / totalScans) * 100) : 100;

    return { totalScans, etudiantsUniques, aLHeure, enRetard, totalTours, tauxPonctualite };
  }, [pointagesFiltres]);

  // Déplacement dans le temps selon le mode
  const naviguerTemps = (delta: number) => {
    if (modePeriode === 'jour') {
      const d = new Date(dateJour);
      d.setDate(d.getDate() + delta);
      setDateJour(d.toISOString().split('T')[0]);
    } else if (modePeriode === 'semaine') {
      const d = new Date(dateSemaineRef);
      d.setDate(d.getDate() + delta * 7);
      setDateSemaineRef(d.toISOString().split('T')[0]);
    } else {
      const [a, m] = moisSelectionne.split('-').map(Number);
      const d = new Date(a, m - 1 + delta, 1);
      setMoisSelectionne(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    }
  };

  const reinitialiserAujourdhui = () => {
    const today = getDateAujourdhui();
    setDateJour(today);
    setDateSemaineRef(today);
    const t = new Date();
    setMoisSelectionne(`${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}`);
  };

  // Export Excel
  const exporterExcel = () => {
    const lignes = pointagesFiltres.map((p, idx) => ({
      'N°': idx + 1,
      'Matricule': p.matricule,
      'Nom': p.nom,
      'Prénom': p.prenom,
      'Classe': p.classe_nom,
      'Date': p.date_jour,
      '1ère Arrivée': p.heure_arrivee,
      'Ponctualité': p.statut_arrivee === 'a_l_heure' ? 'À l\'heure' : 'En retard',
      'Tours de sorties': p.nombre_tours,
      'Dernier Départ': p.heure_depart || '—',
      'Position': p.statut_actuel === 'sur_site' ? 'Sur Site' : 'Sorti',
      'Appareil': p.appareil_dernier_scan || 'pc'
    }));

    const ws = XLSX.utils.json_to_sheet(lignes);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Présences');
    XLSX.writeFile(wb, `ISGI_Presences_${modePeriode}_${dateDebut}_au_${dateFin}.xlsx`);
  };

  // Déclencher l'impression du navigateur
  const lancerImpression = () => {
    window.print();
  };

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* SECTION HORS IMPRESSION (Interface Principale) */}
      <div className="print:hidden space-y-6">
        {/* En-tête */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 className="text-2xl font-extrabold text-white font-heading">
              Registre des Présences & Contrôle d'Accès
            </h2>
            <p className="text-sm text-slate-400 mt-1">
              Consultez, filtrez et imprimez les présences par jour, par semaine ou par mois.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <button
              onClick={exporterExcel}
              className="flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 font-semibold text-xs transition-all shadow"
            >
              <FileSpreadsheet className="w-4 h-4 text-emerald-400" />
              <span>Exporter Excel</span>
            </button>

            <button
              onClick={lancerImpression}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs shadow-lg shadow-blue-600/30 transition-all"
            >
              <Printer className="w-4 h-4" />
              <span>Imprimer la Liste ({modePeriode})</span>
            </button>
          </div>
        </div>

        {/* Barre de Commande : Sélection de la Période (Jour, Semaine, Mois) */}
        <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg space-y-4">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-700/60">
            {/* Onglets de mode de période */}
            <div className="inline-flex p-1 rounded-xl bg-slate-900 border border-slate-800">
              <button
                onClick={() => setModePeriode('jour')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all ${
                  modePeriode === 'jour'
                    ? 'bg-blue-600 text-white shadow-md'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                <Calendar className="w-4 h-4" />
                <span>Par Jour</span>
              </button>
              <button
                onClick={() => setModePeriode('semaine')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all ${
                  modePeriode === 'semaine'
                    ? 'bg-blue-600 text-white shadow-md'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                <CalendarRange className="w-4 h-4" />
                <span>Par Semaine</span>
              </button>
              <button
                onClick={() => setModePeriode('mois')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all ${
                  modePeriode === 'mois'
                    ? 'bg-blue-600 text-white shadow-md'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                <CalendarDays className="w-4 h-4" />
                <span>Par Mois</span>
              </button>
            </div>

            {/* Navigation temporelle Rapide (Précédent / Actuel / Suivant) */}
            <div className="flex items-center gap-2">
              <button
                onClick={() => naviguerTemps(-1)}
                className="p-2 rounded-xl bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300"
                title="Période précédente"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <button
                onClick={reinitialiserAujourdhui}
                className="px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-700 border border-slate-700 text-xs font-bold text-slate-300"
              >
                Aujourd'hui
              </button>
              <button
                onClick={() => naviguerTemps(1)}
                className="p-2 rounded-xl bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300"
                title="Période suivante"
              >
                <ChevronRight className="w-4 h-4" />
              </button>

              <div className="px-3.5 py-2 rounded-xl bg-blue-600/10 border border-blue-500/30 text-blue-300 text-xs font-bold">
                {libellePeriode}
              </div>
            </div>
          </div>

          {/* Filtres Détaillés (Date picker, Recherche, Classe, Statut) */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Sélecteur de date selon mode */}
            <div>
              <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">
                {modePeriode === 'jour' ? 'Date du jour' : modePeriode === 'semaine' ? 'Semaine de référence' : 'Mois sélectionné'}
              </label>
              {modePeriode === 'jour' && (
                <input
                  type="date"
                  value={dateJour}
                  onChange={(e) => setDateJour(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
                />
              )}
              {modePeriode === 'semaine' && (
                <input
                  type="date"
                  value={dateSemaineRef}
                  onChange={(e) => setDateSemaineRef(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
                />
              )}
              {modePeriode === 'mois' && (
                <input
                  type="month"
                  value={moisSelectionne}
                  onChange={(e) => setMoisSelectionne(e.target.value)}
                  className="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
                />
              )}
            </div>

            {/* Recherche Étudiant */}
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
                  className="w-full pl-9 pr-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500 placeholder-slate-500"
                />
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
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

        {/* Cartes de Synthèse de la Période */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="p-4 rounded-xl bg-slate-900/80 border border-slate-800">
            <div className="flex items-center justify-between text-slate-400 text-xs mb-1">
              <span>Total Passages</span>
              <Building2 className="w-4 h-4 text-blue-400" />
            </div>
            <div className="text-2xl font-extrabold text-white font-mono">{statsPeriode.totalScans}</div>
            <div className="text-[10px] text-slate-500 mt-1">sur la période sélectionnée</div>
          </div>

          <div className="p-4 rounded-xl bg-slate-900/80 border border-slate-800">
            <div className="flex items-center justify-between text-slate-400 text-xs mb-1">
              <span>Étudiants Distincts</span>
              <Users className="w-4 h-4 text-indigo-400" />
            </div>
            <div className="text-2xl font-extrabold text-indigo-400 font-mono">{statsPeriode.etudiantsUniques}</div>
            <div className="text-[10px] text-slate-500 mt-1">personnes différentes</div>
          </div>

          <div className="p-4 rounded-xl bg-slate-900/80 border border-slate-800">
            <div className="flex items-center justify-between text-slate-400 text-xs mb-1">
              <span>Ponctualité</span>
              <CheckCircle2 className="w-4 h-4 text-emerald-400" />
            </div>
            <div className="text-2xl font-extrabold text-emerald-400 font-mono">{statsPeriode.tauxPonctualite}%</div>
            <div className="text-[10px] text-slate-500 mt-1">{statsPeriode.aLHeure} à l'heure • {statsPeriode.enRetard} en retard</div>
          </div>

          <div className="p-4 rounded-xl bg-slate-900/80 border border-slate-800">
            <div className="flex items-center justify-between text-slate-400 text-xs mb-1">
              <span>Tours / Sorties</span>
              <Clock className="w-4 h-4 text-amber-400" />
            </div>
            <div className="text-2xl font-extrabold text-amber-400 font-mono">{statsPeriode.totalTours}</div>
            <div className="text-[10px] text-slate-500 mt-1">sorties temporaires comptabilisées</div>
          </div>
        </div>

        {/* Tableau des Enregistrements (Écran Normal) */}
        <div className="rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl overflow-hidden">
          <div className="p-4 border-b border-slate-700/80 flex items-center justify-between text-xs text-slate-400">
            <span>
              <strong>{pointagesFiltres.length}</strong> passage(s) enregistré(s) • {libellePeriode}
            </span>
            <button 
              onClick={chargerDonnees} 
              className="flex items-center gap-1.5 hover:text-white transition-colors"
            >
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
            <div className="py-16 text-center text-slate-400 space-y-2">
              <p className="text-sm">Aucun enregistrement ne correspond aux filtres pour cette période.</p>
              <p className="text-xs text-slate-500">Essayez de modifier la date ou de synchroniser la base.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-300">
                <thead className="bg-slate-900/80 text-xs font-semibold uppercase tracking-wider text-slate-400 border-b border-slate-700">
                  <tr>
                    <th className="py-3.5 px-4">Étudiant</th>
                    <th className="py-3.5 px-4">Classe</th>
                    {modePeriode !== 'jour' && <th className="py-3.5 px-4">Date</th>}
                    <th className="py-3.5 px-4">1ère Arrivée</th>
                    <th className="py-3.5 px-4">Ponctualité</th>
                    <th className="py-3.5 px-4 text-center">Tours</th>
                    <th className="py-3.5 px-4">Dernier Départ</th>
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
                      {modePeriode !== 'jour' && (
                        <td className="py-3.5 px-4 font-mono text-xs text-slate-400">
                          {p.date_jour}
                        </td>
                      )}
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
                        <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-bold ${
                          p.nombre_tours > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-slate-700/50 text-slate-400'
                        }`}>
                          {p.nombre_tours}
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
      </div>

      {/* SECTION EXCLUSIVE IMPRESSION (@media print) */}
      <div className="hidden print:block text-black bg-white p-6 font-sans">
        {/* En-tête Officiel */}
        <div className="border-b-2 border-black pb-4 mb-6">
          <div className="flex justify-between items-start">
            <div>
              <h1 className="text-lg font-black tracking-wide uppercase">
                INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE (ISGI)
              </h1>
              <p className="text-xs uppercase font-bold text-gray-700">
                Direction des Affaires Académiques • Surveillance Générale
              </p>
              <p className="text-[11px] text-gray-600">
                Campus Universitaire — République du Congo
              </p>
            </div>
            <div className="text-right">
              <div className="inline-block border-2 border-black px-3 py-1 font-black text-sm uppercase">
                FICHE OFFICIELLE DES PRÉSENCES
              </div>
              <p className="text-[10px] text-gray-600 mt-1">
                Édité le {new Date().toLocaleDateString('fr-FR')} à {new Date().toLocaleTimeString('fr-FR')}
              </p>
            </div>
          </div>

          <div className="mt-4 pt-3 border-t border-gray-300 flex justify-between items-center text-xs">
            <div>
              <span className="font-bold">Période :</span> {libellePeriode}
            </div>
            <div>
              <span className="font-bold">Classe :</span> {filtreClasse === 'tous' ? 'Toutes les promotions' : filtreClasse}
            </div>
            <div>
              <span className="font-bold">Total enregistrements :</span> {pointagesFiltres.length}
            </div>
          </div>
        </div>

        {/* Synthèse imprimée */}
        <div className="grid grid-cols-4 gap-2 mb-4 p-2.5 border border-gray-400 bg-gray-50 text-center text-xs">
          <div><strong>Total Passages :</strong> {statsPeriode.totalScans}</div>
          <div><strong>Étudiants Distincts :</strong> {statsPeriode.etudiantsUniques}</div>
          <div><strong>À l'heure :</strong> {statsPeriode.aLHeure} ({statsPeriode.tauxPonctualite}%)</div>
          <div><strong>En Retard :</strong> {statsPeriode.enRetard}</div>
        </div>

        {/* Tableau d'impression */}
        <table className="w-full text-left text-xs border-collapse border border-black mb-8">
          <thead>
            <tr className="bg-gray-200 border-b border-black text-[11px] font-bold">
              <th className="border border-black p-1.5 text-center w-8">N°</th>
              <th className="border border-black p-1.5 w-32">Matricule</th>
              <th className="border border-black p-1.5">Nom & Prénom</th>
              <th className="border border-black p-1.5">Classe</th>
              {modePeriode !== 'jour' && <th className="border border-black p-1.5 w-24">Date</th>}
              <th className="border border-black p-1.5 w-20 text-center">Arrivée</th>
              <th className="border border-black p-1.5 w-24 text-center">Ponctualité</th>
              <th className="border border-black p-1.5 w-16 text-center">Tours</th>
              <th className="border border-black p-1.5 w-20 text-center">Départ</th>
            </tr>
          </thead>
          <tbody>
            {pointagesFiltres.map((p, idx) => (
              <tr key={p.id} className="border-b border-gray-300 text-[11px]">
                <td className="border border-black p-1.5 text-center font-bold">{idx + 1}</td>
                <td className="border border-black p-1.5 font-mono">{p.matricule}</td>
                <td className="border border-black p-1.5 font-bold uppercase">{p.nom} {p.prenom}</td>
                <td className="border border-black p-1.5">{p.classe_nom}</td>
                {modePeriode !== 'jour' && (
                  <td className="border border-black p-1.5 font-mono text-center">{p.date_jour}</td>
                )}
                <td className="border border-black p-1.5 font-mono text-center font-semibold">
                  {p.heure_arrivee ? formaterHeure(p.heure_arrivee) : '—'}
                </td>
                <td className="border border-black p-1.5 text-center">
                  {p.statut_arrivee === 'a_l_heure' ? 'À l\'heure' : 'EN RETARD'}
                </td>
                <td className="border border-black p-1.5 text-center">{p.nombre_tours}</td>
                <td className="border border-black p-1.5 font-mono text-center">
                  {p.heure_depart ? formaterHeure(p.heure_depart) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Bloc Signatures officielles */}
        <div className="grid grid-cols-2 gap-8 pt-8 text-xs border-t border-gray-400">
          <div className="text-center">
            <p className="font-bold uppercase tracking-wider mb-14">Le Surveillant Général</p>
            <p className="text-gray-500 italic text-[11px]">(Signature et cachet)</p>
          </div>
          <div className="text-center">
            <p className="font-bold uppercase tracking-wider mb-14">La Direction Académique (DAC)</p>
            <p className="text-gray-500 italic text-[11px]">(Visa et observation)</p>
          </div>
        </div>
      </div>

      {/* Modal Détails & Timeline Chronologique */}
      {pointageDetail && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn print:hidden">
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
                <div className="text-[11px] text-blue-400 font-mono mt-0.5">Date : {pointageDetail.date_jour}</div>
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
