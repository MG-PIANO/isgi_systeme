import { useState, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { CalendrierAcademique } from '../../types/academic';
import { exporterCalendrierPDF } from '../../utils/academicPdfExport';
import { formatSemestreLabel } from '../../utils/academicSemestres';
import {
  CalendarDays,
  Printer,
  Search,
  CheckCircle2,
  Lock,
  Clock,
  BookOpen,
  GraduationCap,
  Briefcase,
  AlertCircle
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function CalendrierConsultationPage() {
  const [filterAnnee, setFilterAnnee] = useState<string>('all');
  const [filterSemestre, setFilterSemestre] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [activeView, setActiveView] = useState<'liste' | 'timeline'>('liste');

  const tousCalendriers = useLiveQuery(() => db.calendriers_academiques.toArray()) || [];

  const calendriersPublies = useMemo(() => {
    return tousCalendriers.filter(c => c.publie === true);
  }, [tousCalendriers]);

  const anneesDisponibles = useMemo(() => {
    return Array.from(new Set(calendriersPublies.map(c => c.annee_academique))).filter(Boolean).sort().reverse();
  }, [calendriersPublies]);

  const calendriersFiltres = useMemo(() => {
    return calendriersPublies.filter(c => {
      if (filterAnnee !== 'all' && c.annee_academique !== filterAnnee) return false;
      if (filterSemestre !== 'all' && c.semestre !== filterSemestre) return false;
      if (searchTerm.trim() !== '') {
        const q = searchTerm.toLowerCase();
        const matchSem = c.semestre.toLowerCase().includes(q);
        const matchAnn = c.annee_academique.toLowerCase().includes(q);
        const matchRentree = (c.type_rentree || '').toLowerCase().includes(q);
        return matchSem || matchAnn || matchRentree;
      }
      return true;
    });
  }, [calendriersPublies, filterAnnee, filterSemestre, searchTerm]);

  const formatDate = (dateStr?: string) => {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr);
      if (isNaN(d.getTime())) return dateStr;
      return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
    } catch {
      return dateStr;
    }
  };

  const handleExportPDF = (cal: CalendrierAcademique) => {
    exporterCalendrierPDF(cal);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary flex items-center gap-1">
              <CalendarDays className="w-3.5 h-3.5" /> Consultation Secrétariat
            </span>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-surface-container-high text-on-surface-variant">
              Lecture Seule
            </span>
          </div>
          <h1 className="text-2xl font-bold text-on-surface tracking-tight">Calendrier Académique</h1>
          <p className="text-sm text-on-surface-variant">
            Périodes officielles d’enseignements, révisions, examens et délibérations publiées par le DAC.
          </p>
        </div>

        <div className="flex bg-surface-container-low border border-outline-variant p-1 rounded-xl">
          <button
            onClick={() => setActiveView('liste')}
            className={cn(
              "px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer",
              activeView === 'liste' ? "bg-surface-container-lowest text-primary shadow-xs" : "text-on-surface-variant"
            )}
          >
            Vue Détaillée
          </button>
          <button
            onClick={() => setActiveView('timeline')}
            className={cn(
              "px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer",
              activeView === 'timeline' ? "bg-surface-container-lowest text-primary shadow-xs" : "text-on-surface-variant"
            )}
          >
            Chronologie (Timeline)
          </button>
        </div>
      </div>

      <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Année Académique
          </label>
          <select
            value={filterAnnee}
            onChange={e => setFilterAnnee(e.target.value)}
            className="w-full px-3 py-2 text-xs font-semibold bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
          >
            <option value="all">Toutes les années publiées</option>
            {anneesDisponibles.map(a => (
              <option key={a} value={a}>{a}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Semestre
          </label>
          <select
            value={filterSemestre}
            onChange={e => setFilterSemestre(e.target.value)}
            className="w-full px-3 py-2 text-xs font-semibold bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
          >
            <option value="all">Tous les semestres</option>
            {['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'].map(s => (
              <option key={s} value={s}>{formatSemestreLabel(s)}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Recherche
          </label>
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Ex: Rentrée, S1..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
        </div>
      </div>

      {calendriersPublies.length === 0 ? (
        <div className="rounded-3xl border border-amber-500/30 bg-amber-500/5 p-8 text-center max-w-2xl mx-auto my-12">
          <div className="w-16 h-16 mx-auto rounded-3xl bg-amber-500/10 text-amber-600 flex items-center justify-center mb-4 ring-8 ring-amber-500/5">
            <Lock className="w-8 h-8 stroke-[2.2]" />
          </div>
          <span className="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-500/10 text-amber-700 dark:text-amber-300 mb-2">
            Non Publié Officiellement
          </span>
          <h3 className="text-lg font-bold text-on-surface mb-2">
            Aucun calendrier académique publié par le DAC
          </h3>
          <p className="text-xs text-on-surface-variant leading-relaxed max-w-lg mx-auto">
            La Direction des Affaires Académiques n'a pas encore validé et publié de calendrier académique officiel pour cette période. Les dates seront consultables dès leur publication.
          </p>
          <div className="mt-5 p-3 rounded-2xl bg-surface-container-lowest border border-outline-variant inline-flex items-center gap-2 text-xs text-on-surface-variant">
            <AlertCircle className="w-4 h-4 text-amber-500 shrink-0" />
            <span>Seul le DAC dispose des droits d'édition et de publication officielle.</span>
          </div>
        </div>
      ) : calendriersFiltres.length === 0 ? (
        <div className="p-8 text-center text-on-surface-variant text-xs">
          Aucun calendrier publié ne correspond à vos critères de recherche.
        </div>
      ) : (
        <div className="space-y-6">
          {calendriersFiltres.map(cal => (
            <div key={cal.id} className="rounded-2xl border border-outline-variant bg-surface-container-lowest overflow-hidden shadow-xs">
              <div className="p-4 border-b border-outline-variant bg-surface-container-low flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold text-sm">
                    {cal.semestre}
                  </div>
                  <div>
                    <h3 className="text-sm font-bold text-on-surface flex items-center gap-2">
                      Calendrier Officiel — {cal.semestre} ({cal.annee_academique})
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20">
                        <CheckCircle2 className="w-3 h-3" /> Publié par le DAC
                      </span>
                    </h3>
                    <p className="text-[11px] text-on-surface-variant">
                      Type de rentrée : {cal.type_rentree || 'Principale'}
                    </p>
                  </div>
                </div>

                <button
                  onClick={() => handleExportPDF(cal)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-outline-variant bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold transition-colors cursor-pointer"
                >
                  <Printer className="w-3.5 h-3.5 text-primary" /> Exporter PDF
                </button>
              </div>

              {activeView === 'liste' ? (
                <div className="p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <BookOpen className="w-4 h-4 text-blue-600" />
                      Enseignements & Cours
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_cours)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_cours)}</span>
                    </p>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <Clock className="w-4 h-4 text-amber-600" />
                      Devoirs sur Table (DST)
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_dst)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_dst)}</span>
                    </p>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <CalendarDays className="w-4 h-4 text-purple-600" />
                      Congé d’étude & Révisions
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_conge_etude)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_conge_etude)}</span>
                    </p>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <GraduationCap className="w-4 h-4 text-rose-600" />
                      Session Principale d’Examens
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_examens)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_examens)}</span>
                    </p>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                      Session de Rattrapage
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_rattrapage)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_rattrapage)}</span>
                    </p>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low/60 border border-outline-variant/60 space-y-1">
                    <div className="flex items-center gap-2 font-bold text-on-surface">
                      <Briefcase className="w-4 h-4 text-cyan-600" />
                      Période de Stage & Projets
                    </div>
                    <p className="text-on-surface-variant text-[11px]">
                      Du <span className="font-semibold text-on-surface">{formatDate(cal.date_debut_stage)}</span> au <span className="font-semibold text-on-surface">{formatDate(cal.date_fin_stage)}</span>
                    </p>
                  </div>
                </div>
              ) : (
                <div className="p-6">
                  <div className="relative border-l-2 border-primary/30 ml-4 space-y-6">
                    <div className="relative pl-6">
                      <div className="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-blue-600 ring-4 ring-surface-container-lowest" />
                      <div className="font-bold text-xs text-on-surface">Rentrée & Début des Cours</div>
                      <div className="text-[11px] text-on-surface-variant">{formatDate(cal.date_debut_cours)}</div>
                    </div>

                    <div className="relative pl-6">
                      <div className="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-amber-600 ring-4 ring-surface-container-lowest" />
                      <div className="font-bold text-xs text-on-surface">Devoirs sur Table (DST)</div>
                      <div className="text-[11px] text-on-surface-variant">{formatDate(cal.date_debut_dst)} au {formatDate(cal.date_fin_dst)}</div>
                    </div>

                    <div className="relative pl-6">
                      <div className="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-purple-600 ring-4 ring-surface-container-lowest" />
                      <div className="font-bold text-xs text-on-surface">Congé d’étude & Révisions</div>
                      <div className="text-[11px] text-on-surface-variant">{formatDate(cal.date_debut_conge_etude)} au {formatDate(cal.date_fin_conge_etude)}</div>
                    </div>

                    <div className="relative pl-6">
                      <div className="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-rose-600 ring-4 ring-surface-container-lowest" />
                      <div className="font-bold text-xs text-on-surface">Session Principale d’Examens</div>
                      <div className="text-[11px] text-on-surface-variant">{formatDate(cal.date_debut_examens)} au {formatDate(cal.date_fin_examens)}</div>
                    </div>

                    <div className="relative pl-6">
                      <div className="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-emerald-600 ring-4 ring-surface-container-lowest" />
                      <div className="font-bold text-xs text-on-surface">Session de Rattrapage</div>
                      <div className="text-[11px] text-on-surface-variant">{formatDate(cal.date_debut_rattrapage)} au {formatDate(cal.date_fin_rattrapage)}</div>
                    </div>
                  </div>
                </div>
              )}

              {cal.observations && (
                <div className="p-3.5 px-5 bg-surface-container-low/40 border-t border-outline-variant text-[11px] text-on-surface-variant italic">
                  Note pédagogique : {cal.observations}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
