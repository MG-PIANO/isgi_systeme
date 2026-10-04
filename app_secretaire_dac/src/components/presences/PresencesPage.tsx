import React, { useState, useEffect, useMemo } from 'react';
import {
  ClipboardCheck,
  Users,
  UserCheck,
  UserX,
  Clock,
  Search,
  Filter,
  RefreshCw,
  BarChart2,
  Calendar,
  BookOpen,
  ChevronDown,
  ChevronUp,
  X
} from 'lucide-react';
import { db } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Classe, Matiere, Presence, Etudiant, ClasseEtudiant, ClasseMatiere } from '../../types';

const MOIS_FR = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];

type StatutPresence = 'present' | 'absent' | 'retard' | 'justifie';

type SeanceInfo = {
  key: string;          // "date|matiere_id"
  date: string;
  matiere_id: string;
  matiere_nom: string;
  heure: string;
};

type EtudiantSuivi = {
  etudiant: Etudiant;
  parSeance: Record<string, StatutPresence | null>;  // key = SeanceInfo.key
  nbPresent: number;
  nbAbsent: number;
  nbRetard: number;
  nbJustifie: number;
  tauxPresence: number;
  parMois: Record<number, { present: number; absent: number; total: number }>; // mois 0-11
};

export const PresencesPage: React.FC = () => {
  const [classes, setClasses] = useState<Classe[]>([]);
  const [matieres, setMatieres] = useState<Matiere[]>([]);
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [presences, setPresences] = useState<Presence[]>([]);
  const [classeEtudiants, setClasseEtudiants] = useState<ClasseEtudiant[]>([]);
  const [classeMatieres, setClasseMatieres] = useState<ClasseMatiere[]>([]);

  const [isLoading, setIsLoading] = useState(true);
  const [isSyncing, setIsSyncing] = useState(false);

  // Filtres principaux
  const [selectedClasseId, setSelectedClasseId] = useState('');
  const [selectedMatiereId, setSelectedMatiereId] = useState('');
  const [selectedMois, setSelectedMois] = useState<number | ''>('');

  // Vue
  type ViewMode = 'etudiants' | 'seances';
  const [viewMode, setViewMode] = useState<ViewMode>('etudiants');
  const [searchEtud, setSearchEtud] = useState('');

  // Détail étudiant (ligne dépliée)
  const [expandedEtudId, setExpandedEtudId] = useState<string | null>(null);
  // Détail séance modal
  const [detailSeance, setDetailSeance] = useState<{seance: SeanceInfo; data: EtudiantSuivi[]} | null>(null);

  useEffect(() => { loadData(); }, []);

  const loadData = async () => {
    setIsLoading(true);
    try {
      const [cls, mats, etuds, presArr, ceArr, cmArr] = await Promise.all([
        db.classes.toArray(),
        db.matieres.toArray(),
        db.etudiants.toArray(),
        db.presences.toArray(),
        db.classe_etudiants.toArray(),
        db.classe_matieres.toArray()
      ]);
      setClasses(cls);
      setMatieres(mats);
      setEtudiants(etuds);
      setPresences(presArr);
      setClasseEtudiants(ceArr);
      setClasseMatieres(cmArr);
      if (cls.length > 0) setSelectedClasseId(cls[0].id);
    } catch (e) { console.error(e); }
    finally { setIsLoading(false); }
  };

  const handleSyncSupabase = async () => {
    setIsSyncing(true);
    try {
      const { data, error } = await supabase.from('presences').select('*').order('date_seance', { ascending: false });
      if (!error && data) {
        await db.presences.bulkPut(data);
        setPresences(data);
      }
    } catch (e) { console.error(e); }
    finally { setIsSyncing(false); }
  };

  // ---- Matières de la classe sélectionnée ----
  const matieresDeLaClasse = useMemo(() =>
    classeMatieres
      .filter(cm => cm.classe_id === selectedClasseId)
      .map(cm => matieres.find(m => m.id === cm.matiere_id))
      .filter(Boolean) as Matiere[],
    [classeMatieres, matieres, selectedClasseId]
  );

  // ---- Étudiants de la classe sélectionnée ----
  const etudiantsDeLaClasse = useMemo(() =>
    classeEtudiants
      .filter(ce => ce.classe_id === selectedClasseId && ce.statut === 'actif')
      .map(ce => etudiants.find(e => e.id === ce.etudiant_id))
      .filter(Boolean) as Etudiant[],
    [classeEtudiants, etudiants, selectedClasseId]
  );

  // ---- Présences filtrées (classe + matière optionnelle + mois optionnel) ----
  const presencesFiltrees = useMemo(() =>
    presences.filter(p => {
      if (p.classe_id !== selectedClasseId) return false;
      if (selectedMatiereId && p.matiere_id !== selectedMatiereId) return false;
      if (selectedMois !== '') {
        const mois = new Date(p.date_seance).getMonth();
        if (mois !== selectedMois) return false;
      }
      return true;
    }),
    [presences, selectedClasseId, selectedMatiereId, selectedMois]
  );

  // ---- Séances distinctes ----
  const seances = useMemo<SeanceInfo[]>(() => {
    const map = new Map<string, SeanceInfo>();
    presencesFiltrees.forEach(p => {
      const key = `${p.date_seance}|${p.matiere_id || 'none'}`;
      if (!map.has(key)) {
        const mat = matieres.find(m => m.id === p.matiere_id);
        map.set(key, {
          key,
          date: p.date_seance,
          matiere_id: p.matiere_id || '',
          matiere_nom: mat?.nom || '—',
          heure: p.heure_debut || '—'
        });
      }
    });
    return Array.from(map.values()).sort((a, b) => a.date.localeCompare(b.date));
  }, [presencesFiltrees, matieres]);

  // ---- Suivi par étudiant ----
  const suiviParEtudiant = useMemo<EtudiantSuivi[]>(() => {
    return etudiantsDeLaClasse
      .filter(e => !searchEtud || `${e.nom} ${e.prenom} ${e.matricule}`.toLowerCase().includes(searchEtud.toLowerCase()))
      .map(etud => {
        const parSeance: Record<string, StatutPresence | null> = {};
        const parMois: Record<number, { present: number; absent: number; total: number }> = {};

        // initialiser toutes les séances à null (pas d'enregistrement)
        seances.forEach(s => { parSeance[s.key] = null; });

        // Remplir avec les données réelles
        presencesFiltrees.filter(p => p.etudiant_id === etud.id).forEach(p => {
          const key = `${p.date_seance}|${p.matiere_id || 'none'}`;
          parSeance[key] = p.statut as StatutPresence;

          const moisIdx = new Date(p.date_seance).getMonth();
          if (!parMois[moisIdx]) parMois[moisIdx] = { present: 0, absent: 0, total: 0 };
          parMois[moisIdx].total++;
          if (p.statut === 'present') parMois[moisIdx].present++;
          else parMois[moisIdx].absent++;
        });

        const presArr = presencesFiltrees.filter(p => p.etudiant_id === etud.id);
        const nbPresent  = presArr.filter(p => p.statut === 'present').length;
        const nbAbsent   = presArr.filter(p => p.statut === 'absent').length;
        const nbRetard   = presArr.filter(p => p.statut === 'retard').length;
        const nbJustifie = presArr.filter(p => p.statut === 'justifie').length;
        const total      = presArr.length;
        const tauxPresence = total > 0 ? Math.round((nbPresent / total) * 100) : 0;

        return { etudiant: etud, parSeance, nbPresent, nbAbsent, nbRetard, nbJustifie, tauxPresence, parMois };
      });
  }, [etudiantsDeLaClasse, presencesFiltrees, seances, searchEtud]);

  // Mois distincts présents dans les données
  const moisDisponibles = useMemo(() => {
    const set = new Set<number>();
    presences.filter(p => p.classe_id === selectedClasseId).forEach(p => {
      set.add(new Date(p.date_seance).getMonth());
    });
    return Array.from(set).sort((a, b) => a - b);
  }, [presences, selectedClasseId]);

  // KPIs globaux de la classe
  const kpis = useMemo(() => {
    const total    = presencesFiltrees.length;
    const presents = presencesFiltrees.filter(p => p.statut === 'present').length;
    const absents  = presencesFiltrees.filter(p => p.statut === 'absent').length;
    const taux     = total > 0 ? Math.round((presents / total) * 100) : 0;
    return { total, presents, absents, taux, nbSeances: seances.length };
  }, [presencesFiltrees, seances]);

  const tauxColor = (pct: number) =>
    pct >= 75 ? 'text-green-600 dark:text-green-400' :
    pct >= 50 ? 'text-amber-600 dark:text-amber-400' :
    'text-red-600 dark:text-red-400';

  const statutBadge = (s: StatutPresence | null) => {
    if (s === null) return <span className="text-[10px] text-on-surface-variant/40">—</span>;
    if (s === 'present')  return <span className="w-6 h-6 rounded-full bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 flex items-center justify-center text-[10px] font-bold mx-auto">P</span>;
    if (s === 'retard')   return <span className="w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 flex items-center justify-center text-[10px] font-bold mx-auto">R</span>;
    if (s === 'justifie') return <span className="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 flex items-center justify-center text-[10px] font-bold mx-auto">J</span>;
    return <span className="w-6 h-6 rounded-full bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 flex items-center justify-center text-[10px] font-bold mx-auto">A</span>;
  };

  if (isLoading) return (
    <div className="flex items-center justify-center h-64">
      <RefreshCw className="w-6 h-6 text-primary animate-spin mr-2" />
      <span className="text-on-surface-variant">Chargement...</span>
    </div>
  );

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Suivi des Présences</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Tableau de bord individuel — classe, matière et période
          </p>
        </div>
        <button onClick={handleSyncSupabase} disabled={isSyncing}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors disabled:opacity-50">
          <RefreshCw className={`w-4 h-4 ${isSyncing ? 'animate-spin' : ''}`} />
          {isSyncing ? 'Actualisation...' : 'Actualiser'}
        </button>
      </div>

      {/* ===== FILTRES ===== */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant p-4">
        <div className="flex items-center gap-2 mb-3">
          <Filter className="w-4 h-4 text-primary" />
          <span className="font-semibold text-sm text-on-surface">Filtres</span>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {/* Classe */}
          <div>
            <label className="text-xs font-medium text-on-surface block mb-1">Classe *</label>
            <select value={selectedClasseId}
              onChange={(e) => { setSelectedClasseId(e.target.value); setSelectedMatiereId(''); setSelectedMois(''); setExpandedEtudId(null); }}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary">
              <option value="">-- Choisir une classe --</option>
              {classes.map(c => <option key={c.id} value={c.id}>{c.nom} ({c.code})</option>)}
            </select>
          </div>

          {/* Matière (uniquement celles de la classe) */}
          <div>
            <label className="text-xs font-medium text-on-surface block mb-1">Matière</label>
            <select value={selectedMatiereId} onChange={(e) => { setSelectedMatiereId(e.target.value); setExpandedEtudId(null); }}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary"
              disabled={!selectedClasseId}>
              <option value="">Toutes les matières</option>
              {matieresDeLaClasse.map(m => <option key={m.id} value={m.id}>[{m.code}] {m.nom}</option>)}
            </select>
          </div>

          {/* Mois */}
          <div>
            <label className="text-xs font-medium text-on-surface block mb-1">Mois</label>
            <select value={selectedMois} onChange={(e) => setSelectedMois(e.target.value === '' ? '' : parseInt(e.target.value))}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary"
              disabled={!selectedClasseId}>
              <option value="">Toute l'année</option>
              {moisDisponibles.map(m => <option key={m} value={m}>{MOIS_FR[m]}</option>)}
            </select>
          </div>

          {/* Recherche étudiant */}
          <div>
            <label className="text-xs font-medium text-on-surface block mb-1">Rechercher un étudiant</label>
            <div className="relative">
              <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
              <input type="text" placeholder="Nom, matricule..." value={searchEtud} onChange={(e) => setSearchEtud(e.target.value)}
                className="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:ring-2 focus:ring-primary outline-none"
              />
            </div>
          </div>
        </div>
      </div>

      {!selectedClasseId ? (
        <div className="bg-surface-container rounded-2xl border border-outline-variant p-12 text-center">
          <ClipboardCheck className="w-12 h-12 mx-auto mb-3 text-on-surface-variant opacity-20" />
          <p className="text-on-surface-variant font-medium">Sélectionnez une classe pour commencer</p>
        </div>
      ) : (
        <>
          {/* ===== KPIs ===== */}
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div className="bg-surface-container rounded-2xl border border-outline-variant p-4 text-center">
              <p className="text-xl font-bold text-on-surface">{etudiantsDeLaClasse.length}</p>
              <p className="text-xs text-on-surface-variant mt-0.5">Étudiants</p>
            </div>
            <div className="bg-surface-container rounded-2xl border border-outline-variant p-4 text-center">
              <p className="text-xl font-bold text-primary">{kpis.nbSeances}</p>
              <p className="text-xs text-on-surface-variant mt-0.5">Séances</p>
            </div>
            <div className="bg-green-50 dark:bg-green-950/30 rounded-2xl border border-green-200 dark:border-green-800 p-4 text-center">
              <p className="text-xl font-bold text-green-600 dark:text-green-400">{kpis.presents}</p>
              <p className="text-xs text-green-600 dark:text-green-300 mt-0.5">Présences enregistrées</p>
            </div>
            <div className="bg-red-50 dark:bg-red-950/30 rounded-2xl border border-red-200 dark:border-red-800 p-4 text-center">
              <p className="text-xl font-bold text-red-600 dark:text-red-400">{kpis.absents}</p>
              <p className="text-xs text-red-600 dark:text-red-300 mt-0.5">Absences</p>
            </div>
            <div className="bg-blue-50 dark:bg-blue-950/30 rounded-2xl border border-blue-200 dark:border-blue-800 p-4 text-center">
              <p className={`text-xl font-bold ${tauxColor(kpis.taux)}`}>{kpis.taux}%</p>
              <p className="text-xs text-on-surface-variant mt-0.5">Taux global</p>
            </div>
          </div>

          {/* ===== SÉLECTEUR DE VUE ===== */}
          <div className="flex items-center gap-2 bg-surface-container rounded-full p-1 w-fit border border-outline-variant">
            <button onClick={() => setViewMode('etudiants')}
              className={`px-4 py-2 rounded-full text-sm font-medium transition-colors ${viewMode === 'etudiants' ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>
              <span className="flex items-center gap-2"><Users className="w-4 h-4" /> Par étudiant</span>
            </button>
            <button onClick={() => setViewMode('seances')}
              className={`px-4 py-2 rounded-full text-sm font-medium transition-colors ${viewMode === 'seances' ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>
              <span className="flex items-center gap-2"><Calendar className="w-4 h-4" /> Par séance</span>
            </button>
          </div>

          {/* ===================================================
              VUE 1 : PAR ÉTUDIANT — Tableau de suivi individuel
          ==================================================== */}
          {viewMode === 'etudiants' && (
            <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden">
              <div className="px-5 py-3 border-b border-outline-variant flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <BarChart2 className="w-4 h-4 text-primary" />
                  <span className="font-semibold text-sm text-on-surface">
                    Suivi individuel — {suiviParEtudiant.length} étudiant(s)
                  </span>
                </div>
                <div className="flex items-center gap-3 text-[10px] text-on-surface-variant font-semibold">
                  <span className="flex items-center gap-1"><span className="w-4 h-4 rounded-full bg-green-100 text-green-700 flex items-center justify-center font-bold">P</span>Présent</span>
                  <span className="flex items-center gap-1"><span className="w-4 h-4 rounded-full bg-red-100 text-red-700 flex items-center justify-center font-bold">A</span>Absent</span>
                  <span className="flex items-center gap-1"><span className="w-4 h-4 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center font-bold">R</span>Retard</span>
                  <span className="flex items-center gap-1"><span className="w-4 h-4 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold">J</span>Justifié</span>
                </div>
              </div>

              {suiviParEtudiant.length === 0 ? (
                <div className="p-12 text-center text-on-surface-variant text-sm">
                  Aucune donnée de présence disponible pour cette sélection.
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse text-xs">
                    <thead>
                      <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                        <th className="py-3 px-4 sticky left-0 bg-surface-container-highest z-10">Étudiant</th>
                        <th className="py-3 px-3 text-center">
                          <UserCheck className="w-3.5 h-3.5 inline text-green-500 mr-0.5" />P
                        </th>
                        <th className="py-3 px-3 text-center">
                          <UserX className="w-3.5 h-3.5 inline text-red-500 mr-0.5" />A
                        </th>
                        <th className="py-3 px-3 text-center">
                          <Clock className="w-3.5 h-3.5 inline text-amber-500 mr-0.5" />R
                        </th>
                        <th className="py-3 px-3 text-center">Taux</th>
                        {/* Colonnes par mois */}
                        {moisDisponibles
                          .filter(m => selectedMois === '' || m === selectedMois)
                          .map(m => (
                            <th key={m} className="py-3 px-2 text-center whitespace-nowrap text-[10px]">
                              {MOIS_FR[m]}
                            </th>
                          ))}
                        {/* Colonnes par séance (si filtré sur une matière ou peu de séances) */}
                        {seances.length <= 20 && seances.map(s => (
                          <th key={s.key} className="py-3 px-2 text-center whitespace-nowrap text-[10px]" title={s.matiere_nom}>
                            {new Date(s.date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })}
                            <br />
                            <span className="font-normal normal-case opacity-70">{s.matiere_nom.substring(0, 6)}</span>
                          </th>
                        ))}
                        <th className="py-3 px-3 text-center">Détail</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-outline-variant bg-surface-container-lowest">
                      {suiviParEtudiant.map(row => {
                        const isExpanded = expandedEtudId === row.etudiant.id;
                        return (
                          <React.Fragment key={row.etudiant.id}>
                            <tr className={`hover:bg-surface-container-high/40 transition-colors ${isExpanded ? 'bg-primary/5' : ''}`}>
                              {/* Étudiant */}
                              <td className="py-2.5 px-4 sticky left-0 bg-surface-container-lowest z-10">
                                <p className="font-bold text-on-surface leading-tight">{row.etudiant.nom.toUpperCase()} {row.etudiant.prenom}</p>
                                <p className="font-mono text-[10px] text-on-surface-variant">{row.etudiant.matricule}</p>
                              </td>
                              {/* Totaux */}
                              <td className="py-2.5 px-3 text-center font-bold text-green-600 dark:text-green-400">{row.nbPresent}</td>
                              <td className="py-2.5 px-3 text-center font-bold text-red-600 dark:text-red-400">{row.nbAbsent}</td>
                              <td className="py-2.5 px-3 text-center font-bold text-amber-600 dark:text-amber-400">{row.nbRetard}</td>
                              {/* Taux */}
                              <td className="py-2.5 px-3 text-center">
                                <div className="flex flex-col items-center gap-0.5">
                                  <span className={`font-bold text-sm ${tauxColor(row.tauxPresence)}`}>{row.tauxPresence}%</span>
                                  <div className="w-12 h-1 rounded-full bg-outline-variant overflow-hidden">
                                    <div className={`h-full ${row.tauxPresence >= 75 ? 'bg-green-500' : row.tauxPresence >= 50 ? 'bg-amber-500' : 'bg-red-500'}`}
                                      style={{ width: `${row.tauxPresence}%` }} />
                                  </div>
                                </div>
                              </td>
                              {/* Par mois */}
                              {moisDisponibles
                                .filter(m => selectedMois === '' || m === selectedMois)
                                .map(m => {
                                  const mData = row.parMois[m];
                                  if (!mData) return <td key={m} className="py-2.5 px-2 text-center text-on-surface-variant/30">—</td>;
                                  const mTaux = Math.round((mData.present / mData.total) * 100);
                                  return (
                                    <td key={m} className="py-2.5 px-2 text-center">
                                      <div className="flex flex-col items-center">
                                        <span className={`text-[11px] font-bold ${tauxColor(mTaux)}`}>{mTaux}%</span>
                                        <span className="text-[9px] text-on-surface-variant">{mData.present}/{mData.total}</span>
                                      </div>
                                    </td>
                                  );
                                })}
                              {/* Par séance (si peu de séances) */}
                              {seances.length <= 20 && seances.map(s => (
                                <td key={s.key} className="py-2.5 px-2 text-center">
                                  <div className="flex justify-center">
                                    {statutBadge(row.parSeance[s.key] ?? null)}
                                  </div>
                                </td>
                              ))}
                              {/* Bouton Détail */}
                              <td className="py-2.5 px-3 text-center">
                                <button onClick={() => setExpandedEtudId(isExpanded ? null : row.etudiant.id)}
                                  className="p-1.5 rounded-lg text-primary hover:bg-primary/10 transition-colors">
                                  {isExpanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
                                </button>
                              </td>
                            </tr>

                            {/* Ligne dépliée — Détail par séance */}
                            {isExpanded && (
                              <tr className="bg-primary/5">
                                <td colSpan={99} className="px-6 py-4">
                                  <h5 className="font-semibold text-sm text-on-surface mb-3">
                                    Toutes les séances — {row.etudiant.nom.toUpperCase()} {row.etudiant.prenom}
                                  </h5>
                                  {seances.length === 0 ? (
                                    <p className="text-xs text-on-surface-variant">Aucune séance dans cette période.</p>
                                  ) : (
                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2">
                                      {seances.map(s => {
                                        const statut = row.parSeance[s.key] ?? null;
                                        const bgCard =
                                          statut === 'present'  ? 'bg-green-50 dark:bg-green-950/30 border-green-200 dark:border-green-800' :
                                          statut === 'absent'   ? 'bg-red-50 dark:bg-red-950/30 border-red-200 dark:border-red-800' :
                                          statut === 'retard'   ? 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-800' :
                                          statut === 'justifie' ? 'bg-blue-50 dark:bg-blue-950/30 border-blue-200 dark:border-blue-800' :
                                          'bg-surface-container border-outline-variant';
                                        const labelColor =
                                          statut === 'present'  ? 'text-green-700 dark:text-green-300' :
                                          statut === 'absent'   ? 'text-red-700 dark:text-red-300' :
                                          statut === 'retard'   ? 'text-amber-700 dark:text-amber-300' :
                                          statut === 'justifie' ? 'text-blue-700 dark:text-blue-300' :
                                          'text-on-surface-variant';
                                        const label = statut === 'present' ? 'Présent ✓' : statut === 'absent' ? 'Absent ✗' : statut === 'retard' ? 'Retard ⏱' : statut === 'justifie' ? 'Justifié' : 'Non enregistré';

                                        return (
                                          <div key={s.key} className={`rounded-xl border p-2.5 ${bgCard}`}>
                                            <p className="text-[11px] font-bold text-on-surface">
                                              {new Date(s.date).toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' })}
                                            </p>
                                            <p className="text-[10px] text-on-surface-variant truncate">{s.matiere_nom}</p>
                                            {s.heure !== '—' && <p className="text-[9px] text-on-surface-variant">{s.heure}</p>}
                                            <p className={`text-[11px] font-bold mt-1.5 ${labelColor}`}>{label}</p>
                                          </div>
                                        );
                                      })}
                                    </div>
                                  )}
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* ===================================================
              VUE 2 : PAR SÉANCE — Liste des séances + présences
          ==================================================== */}
          {viewMode === 'seances' && (
            <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden">
              <div className="px-5 py-3 border-b border-outline-variant flex items-center gap-2">
                <Calendar className="w-4 h-4 text-primary" />
                <span className="font-semibold text-sm text-on-surface">
                  {seances.length} séance(s) — cliquez pour voir la liste complète
                </span>
              </div>

              {seances.length === 0 ? (
                <div className="p-12 text-center text-on-surface-variant text-sm">
                  Aucune séance enregistrée pour cette sélection.
                </div>
              ) : (
                <div className="divide-y divide-outline-variant">
                  {seances.map(seance => {
                    const presentsSeance = presencesFiltrees.filter(p =>
                      p.date_seance === seance.date && p.matiere_id === (seance.matiere_id || undefined)
                    );
                    const nbP = presentsSeance.filter(p => p.statut === 'present').length;
                    const nbA = presentsSeance.filter(p => p.statut === 'absent').length;
                    const nbR = presentsSeance.filter(p => p.statut === 'retard').length;
                    const total = presentsSeance.length;
                    const taux = total > 0 ? Math.round((nbP / total) * 100) : 0;

                    // Construire la liste complète pour cette séance
                    const listeSeance: EtudiantSuivi[] = suiviParEtudiant.map(row => ({
                      ...row,
                      // On récupère le statut pour cette séance précise
                    }));

                    return (
                      <button key={seance.key}
                        onClick={() => setDetailSeance({ seance, data: listeSeance })}
                        className="w-full text-left flex items-center gap-4 px-5 py-4 hover:bg-surface-container-high/40 transition-colors group">
                        {/* Date */}
                        <div className="text-center shrink-0 w-14">
                          <p className="text-lg font-bold text-on-surface leading-none">
                            {new Date(seance.date).getDate().toString().padStart(2, '0')}
                          </p>
                          <p className="text-[11px] text-on-surface-variant font-medium uppercase">
                            {MOIS_FR[new Date(seance.date).getMonth()]}
                          </p>
                        </div>

                        {/* Info */}
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm font-semibold text-on-surface">
                              {new Date(seance.date).toLocaleDateString('fr-FR', { weekday: 'long' })}
                            </span>
                            {seance.heure !== '—' && (
                              <span className="text-xs text-on-surface-variant font-mono">{seance.heure}</span>
                            )}
                          </div>
                          <div className="flex items-center gap-1.5 mt-0.5">
                            <BookOpen className="w-3.5 h-3.5 text-on-surface-variant shrink-0" />
                            <span className="text-xs text-on-surface-variant truncate">{seance.matiere_nom}</span>
                          </div>
                        </div>

                        {/* Chiffres */}
                        <div className="flex items-center gap-4 shrink-0 text-center">
                          <div>
                            <p className="text-base font-bold text-green-600 dark:text-green-400">{nbP}</p>
                            <p className="text-[10px] text-on-surface-variant">Présents</p>
                          </div>
                          <div>
                            <p className="text-base font-bold text-red-600 dark:text-red-400">{nbA}</p>
                            <p className="text-[10px] text-on-surface-variant">Absents</p>
                          </div>
                          {nbR > 0 && (
                            <div>
                              <p className="text-base font-bold text-amber-600 dark:text-amber-400">{nbR}</p>
                              <p className="text-[10px] text-on-surface-variant">Retards</p>
                            </div>
                          )}
                          <div className="w-16">
                            <p className={`text-base font-bold ${tauxColor(taux)}`}>{taux}%</p>
                            <div className="w-full h-1 rounded-full bg-outline-variant overflow-hidden mt-1">
                              <div className={`h-full ${taux >= 75 ? 'bg-green-500' : taux >= 50 ? 'bg-amber-500' : 'bg-red-500'}`}
                                style={{ width: `${taux}%` }} />
                            </div>
                          </div>
                        </div>
                        <ChevronDown className="w-4 h-4 text-on-surface-variant group-hover:text-primary transition-colors" />
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          )}
        </>
      )}

      {/* ===== MODAL DÉTAIL SÉANCE (vue par séance) ===== */}
      {detailSeance && (
        <div className="fixed inset-0 z-50 flex justify-end bg-on-surface/40" onClick={() => setDetailSeance(null)}>
          <div className="bg-surface-container-lowest w-full max-w-lg h-full overflow-y-auto shadow-2xl flex flex-col"
            onClick={(e) => e.stopPropagation()}>

            <div className="bg-surface-container-low border-b border-outline-variant px-6 py-4 shrink-0 flex items-start justify-between">
              <div>
                <h3 className="font-bold text-on-surface">
                  {new Date(detailSeance.seance.date).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })}
                </h3>
                <p className="text-xs text-on-surface-variant mt-0.5">{detailSeance.seance.matiere_nom} — {detailSeance.seance.heure}</p>
              </div>
              <button onClick={() => setDetailSeance(null)} className="p-2 rounded-full hover:bg-surface-container-highest mt-1">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>

            {/* Liste des étudiants avec leur statut */}
            <div className="p-4 flex-1 overflow-y-auto">
              <div className="space-y-2">
                {suiviParEtudiant.map(row => {
                  const statut = row.parSeance[detailSeance.seance.key] ?? null;
                  return (
                    <div key={row.etudiant.id}
                      className="flex items-center justify-between p-3 rounded-xl bg-surface-container border border-outline-variant">
                      <div>
                        <p className="font-semibold text-on-surface text-sm">{row.etudiant.nom.toUpperCase()} {row.etudiant.prenom}</p>
                        <p className="font-mono text-[10px] text-on-surface-variant">{row.etudiant.matricule}</p>
                      </div>
                      <div className="flex justify-center">
                        {statutBadge(statut)}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
