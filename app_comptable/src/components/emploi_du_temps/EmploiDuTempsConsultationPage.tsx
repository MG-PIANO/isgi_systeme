import { useState, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { Classe, Matiere, Personnel } from '../../types/academic';
import { exporterEmploiDuTempsPDF } from '../../utils/academicPdfExport';
import {
  getSemestresForClasse,
  formatSemestreLabel
} from '../../utils/academicSemestres';
import {
  Clock,
  Search,
  Filter,
  Printer,
  Layers,
  MapPin,
  User,
  BookOpen,
  Lock,
  CheckCircle2,
  Calendar,
  AlertCircle
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function EmploiDuTempsConsultationPage() {
  const [activeView, setActiveView] = useState<'grille' | 'liste'>('grille');
  const [selectedClasseId, setSelectedClasseId] = useState<string>('');
  const [filterJour, setFilterJour] = useState<string>('all');
  const [filterSemestre, setFilterSemestre] = useState<string>('S1');
  const [searchTerm, setSearchTerm] = useState<string>('');

  // Récupération des données depuis Dexie (synchronisées avec le DAC et Supabase)
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const coursList = useLiveQuery(() => db.emplois_du_temps.toArray()) || [];
  const publications = useLiveQuery(() => db.publications_academiques.toArray()) || [];

  // Sélection par défaut de la première classe disponible si non sélectionnée
  const activeClasseId = selectedClasseId || (classes.length > 0 ? classes[0].id : '');

  const classeMap = useMemo(() => new Map<string, Classe>(classes.map(c => [c.id, c])), [classes]);
  const matiereMap = useMemo(() => new Map<string, Matiere>(matieres.map(m => [m.id, m])), [matieres]);
  const enseignantMap = useMemo(() => new Map<string, Personnel>(personnel.map(p => [p.id, p])), [personnel]);

  const currentClasse = classeMap.get(activeClasseId);
  const semestresDisponibles = useMemo(() => getSemestresForClasse(currentClasse), [currentClasse]);

  // Vérifier si l'emploi du temps de cette classe et de ce semestre est officiellement publié par le DAC
  const isPublishedForSelection = useMemo(() => {
    if (!activeClasseId) return false;
    // 1. Vérifier dans la table officielle publications_academiques
    const pubKey = `pub_emploi_du_temps_${activeClasseId}_${filterSemestre}`;
    const pub = publications.find(p => p.id === pubKey);
    if (pub && Boolean(pub.publie)) return true;

    // 2. Vérifier si les cours de cette classe ont le marqueur publie: true
    const coursClasse = coursList.filter(c =>
      c.classe_id === activeClasseId && (filterSemestre === 'all' || c.semestre === filterSemestre)
    );
    return coursClasse.length > 0 && coursClasse.every(c => c.publie);
  }, [publications, coursList, activeClasseId, filterSemestre]);

  // Cours filtrés visibles uniquement si publié
  const coursAffiches = useMemo(() => {
    if (!isPublishedForSelection || !activeClasseId) return [];
    return coursList.filter(c => {
      if (c.classe_id !== activeClasseId) return false;
      if (filterSemestre !== 'all' && c.semestre !== filterSemestre) return false;
      if (filterJour !== 'all' && c.jour_semaine !== filterJour) return false;
      if (searchTerm.trim() !== '') {
        const q = searchTerm.toLowerCase();
        const mat = matiereMap.get(c.matiere_id);
        const ens = c.enseignant_id ? enseignantMap.get(c.enseignant_id) : null;
        const matMatch = mat && (mat.nom.toLowerCase().includes(q) || mat.code.toLowerCase().includes(q));
        const ensMatch = ens && (`${ens.nom} ${ens.prenom}`.toLowerCase().includes(q));
        const salleMatch = c.salle.toLowerCase().includes(q);
        return matMatch || ensMatch || salleMatch;
      }
      return true;
    });
  }, [isPublishedForSelection, activeClasseId, filterSemestre, filterJour, searchTerm, coursList, matiereMap, enseignantMap]);

  const joursOrdre = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'] as const;
  const creneauxHoraires = [
    { start: '08:00', end: '10:00', label: '08h00 - 10h00' },
    { start: '10:15', end: '12:15', label: '10h15 - 12h15' },
    { start: '12:15', end: '14:00', label: '12h15 - 14h00 (Pause)' },
    { start: '14:00', end: '16:00', label: '14h00 - 16h00' },
    { start: '16:15', end: '18:15', label: '16h15 - 18h15' }
  ];

  const handleExportPDF = () => {
    if (!currentClasse) return;
    exporterEmploiDuTempsPDF(
      currentClasse.nom,
      filterSemestre,
      coursAffiches,
      matiereMap,
      enseignantMap,
      currentClasse.annee_academique || '2025-2026'
    );
  };

  return (
    <div className="space-y-6">
      {/* En-tête */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary flex items-center gap-1">
              <Clock className="w-3.5 h-3.5" /> Consultation Pédagogique
            </span>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-surface-container-high text-on-surface-variant">
              Lecture Seule
            </span>
          </div>
          <h1 className="text-2xl font-bold text-on-surface tracking-tight">Emploi du Temps</h1>
          <p className="text-sm text-on-surface-variant">
            Planning officiel des enseignements publié par la Direction des Affaires Académiques (DAC).
          </p>
        </div>

        <div className="flex items-center gap-3">
          {isPublishedForSelection && (
            <button
              onClick={handleExportPDF}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-outline-variant bg-surface-container hover:bg-surface-container-high text-on-surface font-semibold text-xs shadow-xs transition-colors cursor-pointer"
            >
              <Printer className="w-4 h-4 text-primary" /> Imprimer / Exporter PDF
            </button>
          )}

          <div className="flex bg-surface-container-low border border-outline-variant p-1 rounded-xl">
            <button
              onClick={() => setActiveView('grille')}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer",
                activeView === 'grille' ? "bg-surface-container-lowest text-primary shadow-xs" : "text-on-surface-variant"
              )}
            >
              Grille Hebdomadaire
            </button>
            <button
              onClick={() => setActiveView('liste')}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer",
                activeView === 'liste' ? "bg-surface-container-lowest text-primary shadow-xs" : "text-on-surface-variant"
              )}
            >
              Liste des Séances
            </button>
          </div>
        </div>
      </div>

      {/* Barre de Filtres */}
      <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {/* Sélecteur de Classe */}
        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Classe / Promotion
          </label>
          <div className="relative">
            <Layers className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <select
              value={activeClasseId}
              onChange={e => setSelectedClasseId(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs font-semibold bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            >
              {classes.length === 0 ? (
                <option value="">Aucune classe configurée</option>
              ) : (
                classes.map(c => (
                  <option key={c.id} value={c.id}>
                    {c.nom} ({c.code})
                  </option>
                ))
              )}
            </select>
          </div>
        </div>

        {/* Sélecteur de Semestre */}
        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Semestre Universitaire
          </label>
          <div className="relative">
            <Calendar className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <select
              value={filterSemestre}
              onChange={e => setFilterSemestre(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs font-semibold bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            >
              {semestresDisponibles.map(s => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
        </div>

        {/* Filtre par Jour */}
        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Jour de la semaine
          </label>
          <div className="relative">
            <Filter className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <select
              value={filterJour}
              onChange={e => setFilterJour(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs font-medium bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            >
              <option value="all">Tous les jours ouvrés</option>
              {joursOrdre.map(j => (
                <option key={j} value={j}>{j}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Recherche */}
        <div>
          <label className="block text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-1">
            Recherche libre
          </label>
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Matière, enseignant, salle..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs bg-surface-container-lowest rounded-xl border border-outline-variant text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
        </div>
      </div>

      {/* ==================================================================== */}
      {/* ÉTAT : PUBLIÉ VS NON PUBLIÉ PAR LE DAC */}
      {/* ==================================================================== */}
      {!isPublishedForSelection ? (
        <div className="rounded-3xl border border-amber-500/30 bg-amber-500/5 p-8 text-center max-w-2xl mx-auto my-12">
          <div className="w-16 h-16 mx-auto rounded-3xl bg-amber-500/10 text-amber-600 flex items-center justify-center mb-4 ring-8 ring-amber-500/5">
            <Lock className="w-8 h-8 stroke-[2.2]" />
          </div>
          <span className="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-500/10 text-amber-700 dark:text-amber-300 mb-2">
            Non Publié Officiellement
          </span>
          <h3 className="text-lg font-bold text-on-surface mb-2">
            Emploi du temps en cours d'élaboration par le DAC
          </h3>
          <p className="text-xs text-on-surface-variant leading-relaxed max-w-lg mx-auto">
            L'emploi du temps de la classe <span className="font-semibold text-on-surface">{currentClasse?.nom || 'sélectionnée'}</span> pour le <span className="font-semibold text-on-surface">{formatSemestreLabel(filterSemestre)}</span> n'a pas encore été validé et publié officiellement par la Direction des Affaires Académiques.
          </p>
          <div className="mt-5 p-3 rounded-2xl bg-surface-container-lowest border border-outline-variant inline-flex items-center gap-2 text-xs text-on-surface-variant">
            <AlertCircle className="w-4 h-4 text-amber-500 shrink-0" />
            <span>Les créneaux deviendront consultables dès que le DAC aura procédé à la publication officielle.</span>
          </div>
        </div>
      ) : (
        <>
          {/* Bandeau de validation officielle */}
          <div className="p-3.5 px-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-600 flex items-center justify-center shrink-0">
                <CheckCircle2 className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-xs font-bold text-emerald-800 dark:text-emerald-300">
                  Emploi du temps officiel publié
                </h4>
                <p className="text-[11px] text-emerald-700/80 dark:text-emerald-400">
                  Validé et certifié conforme par la Direction Académique • {currentClasse?.nom} ({formatSemestreLabel(filterSemestre)})
                </p>
              </div>
            </div>
            <span className="text-[10px] font-bold uppercase px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-700 dark:text-emerald-300">
              {coursAffiches.length} cours programmés
            </span>
          </div>

          {/* VUE GRILLE HEBDOMADAIRE */}
          {activeView === 'grille' && (
            <div className="rounded-2xl border border-outline-variant bg-surface-container-lowest overflow-hidden shadow-xs">
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse min-w-[800px]">
                  <thead>
                    <tr className="bg-surface-container-low border-b border-outline-variant">
                      <th className="p-3.5 text-xs font-bold text-on-surface uppercase tracking-wider text-center w-32 border-r border-outline-variant">
                        Jour
                      </th>
                      {creneauxHoraires.map(c => (
                        <th key={c.start} className="p-3 text-xs font-bold text-on-surface text-center border-r border-outline-variant last:border-r-0">
                          {c.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-outline-variant/50">
                    {joursOrdre.map(jour => {
                      if (filterJour !== 'all' && filterJour !== jour) return null;
                      return (
                        <tr key={jour} className="hover:bg-surface-container-low/40 transition-colors">
                          <td className="p-4 text-xs font-bold text-on-surface bg-surface-container-low/60 text-center border-r border-outline-variant">
                            {jour}
                          </td>
                          {creneauxHoraires.map(creneau => {
                            if (creneau.start === '12:15') {
                              return (
                                <td key={creneau.start} className="p-2 text-center text-[10px] text-on-surface-variant/50 bg-surface-container-low/30 border-r border-outline-variant">
                                  Pause
                                </td>
                              );
                            }

                            const match = coursAffiches.find(c =>
                              c.jour_semaine === jour &&
                              c.heure_debut === creneau.start
                            );

                            if (!match) {
                              return (
                                <td key={creneau.start} className="p-3 border-r border-outline-variant last:border-r-0 text-center text-xs text-on-surface-variant/30">
                                  —
                                </td>
                              );
                            }

                            const matiere = matiereMap.get(match.matiere_id);
                            const enseignant = match.enseignant_id ? enseignantMap.get(match.enseignant_id) : null;

                            return (
                              <td key={creneau.start} className="p-2 border-r border-outline-variant last:border-r-0 align-top">
                                <div
                                  className="p-2.5 rounded-xl border border-outline-variant shadow-xs text-xs space-y-1.5"
                                  style={{ borderLeftColor: match.couleur || '#0284c7', borderLeftWidth: '4px' }}
                                >
                                  <div className="flex items-center justify-between gap-1">
                                    <span className="font-bold text-[11px] text-on-surface truncate">
                                      {matiere ? matiere.nom : 'Matière'}
                                    </span>
                                    <span className="px-1.5 py-0.2 rounded text-[9px] font-bold bg-primary/10 text-primary">
                                      {match.type_cours}
                                    </span>
                                  </div>

                                  {matiere?.code && (
                                    <div className="text-[10px] text-on-surface-variant font-mono">
                                      {matiere.code}
                                    </div>
                                  )}

                                  {enseignant && (
                                    <div className="flex items-center gap-1 text-[10px] text-on-surface-variant">
                                      <User className="w-3 h-3 text-primary shrink-0" />
                                      <span className="truncate">{enseignant.nom} {enseignant.prenom}</span>
                                    </div>
                                  )}

                                  <div className="flex items-center gap-1 text-[10px] text-on-surface-variant font-medium">
                                    <MapPin className="w-3 h-3 text-rose-500 shrink-0" />
                                    <span className="truncate">{match.salle}</span>
                                  </div>
                                </div>
                              </td>
                            );
                          })}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* VUE LISTE */}
          {activeView === 'liste' && (
            <div className="rounded-2xl border border-outline-variant bg-surface-container-lowest overflow-hidden">
              <div className="divide-y divide-outline-variant/50">
                {coursAffiches.length === 0 ? (
                  <div className="p-8 text-center text-on-surface-variant text-xs">
                    Aucun cours trouvé avec les filtres sélectionnés.
                  </div>
                ) : (
                  coursAffiches.map(cours => {
                    const matiere = matiereMap.get(cours.matiere_id);
                    const enseignant = cours.enseignant_id ? enseignantMap.get(cours.enseignant_id) : null;

                    return (
                      <div key={cours.id} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-surface-container-low transition-colors">
                        <div className="flex items-start gap-3">
                          <div
                            className="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-xs shrink-0 shadow-xs"
                            style={{ backgroundColor: cours.couleur || '#0284c7' }}
                          >
                            <BookOpen className="w-5 h-5" />
                          </div>
                          <div>
                            <div className="flex items-center gap-2">
                              <h4 className="font-bold text-xs text-on-surface">
                                {matiere ? matiere.nom : 'Cours'}
                              </h4>
                              <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-primary/10 text-primary">
                                {cours.type_cours}
                              </span>
                            </div>
                            <p className="text-[11px] text-on-surface-variant mt-0.5 flex items-center gap-3">
                              <span>📅 {cours.jour_semaine} : {cours.heure_debut} - {cours.heure_fin}</span>
                              <span>📍 Salle : {cours.salle}</span>
                            </p>
                          </div>
                        </div>

                        <div className="text-right sm:border-l sm:border-outline-variant sm:pl-4">
                          <div className="text-xs font-semibold text-on-surface">
                            {enseignant ? `${enseignant.nom} ${enseignant.prenom}` : 'Enseignant non spécifié'}
                          </div>
                          <div className="text-[10px] text-on-surface-variant">
                            {enseignant?.fonction || 'Corps Professoral'}
                          </div>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
