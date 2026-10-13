import React, { useState, useEffect, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type {
  EmploiDuTempsSemestriel,
  SemaineEmploiDuTemps,
  DateImportanteCalendrier,
  CreneauCellule,
  Classe,
  Matiere,
  Personnel,
  NotificationItem
} from '../../types';
import { exporterEmploiDuTempsOfficielDAC_PDF } from '../../utils/academicPdfExport';
import {
  genererEmploiDuTempsSemestriel,
  DATES_IMPORTANTES_DEFAULT_S1,
  DATES_IMPORTANTES_DEFAULT_S2,
  JOURS_SEMAINE_MAP
} from '../../utils/academicTimetableGenerator';
import {
  TOUS_LES_SEMESTRES,
  formatSemestreLabel
} from '../../utils/academicSemestres';
import {
  Calendar,
  Clock,
  Printer,
  Sparkles,
  Share2,
  CheckCircle2,
  Edit2,
  Trash2,
  Plus,
  BookOpen,
  User,
  AlertCircle,
  ChevronDown,
  ChevronUp,
  Settings,
  Layers,
  CalendarDays,
  FileText,
  Filter,
  Search,
  Check,
  X,
  Zap,
  Coffee,
  GraduationCap
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function EmploiDuTempsPage() {
  const [selectedClasseId, setSelectedClasseId] = useState<string>('');
  const [selectedSemestre, setSelectedSemestre] = useState<string>('S1');
  const [selectedAnnee, setSelectedAnnee] = useState<string>('2026-2027');
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [isPublishing, setIsPublishing] = useState<boolean>(false);

  // Modales
  const [isGenerateModalOpen, setIsGenerateModalOpen] = useState<boolean>(false);
  const [isDatesModalOpen, setIsDatesModalOpen] = useState<boolean>(false);
  const [showDatesBanner, setShowDatesBanner] = useState<boolean>(true);

  // Édition d'un créneau individuel
  const [editingCell, setEditingCell] = useState<{
    semaineNum: number;
    creneau: string;
    jour: string;
    data: CreneauCellule;
  } | null>(null);

  // Remplissage rapide d'une semaine
  const [fillingWeek, setFillingWeek] = useState<{
    semaineNum: number;
    matiere: string;
    enseignant: string;
    type: CreneauCellule['type'];
  } | null>(null);

  // Options pour la génération
  const [genRentreeDate, setGenRentreeDate] = useState<string>('2026-10-12');
  const [genNbSemaines, setGenNbSemaines] = useState<number>(25);
  const [genJours, setGenJours] = useState<string[]>(['Lundi', 'Mercredi', 'Vendredi']);
  const [genCreneaux, setGenCreneaux] = useState<string[]>(['08H00–10H00', '10H15–12H15']);

  // Données depuis IndexedDB
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const allEmplois = useLiveQuery(() => db.emplois_du_temps_semestriels.toArray()) || [];

  // Enseignants uniquement
  const enseignants = personnel.filter(p =>
    p.type_personnel?.toLowerCase().includes('enseign') ||
    p.fonction?.toLowerCase().includes('enseign') ||
    p.fonction?.toLowerCase().includes('prof')
  );

  // Sélectionner la première classe par défaut
  useEffect(() => {
    if (!selectedClasseId && classes.length > 0) {
      setSelectedClasseId(classes[0].id);
    }
  }, [classes, selectedClasseId]);

  const classeActuelle = classes.find(c => c.id === selectedClasseId);

  // Récupérer l'emploi du temps actuel pour cette classe et semestre
  const currentEmploi = useMemo(() => {
    if (!selectedClasseId) return null;
    return allEmplois.find(
      e => e.classe_id === selectedClasseId && e.semestre === selectedSemestre
    ) || null;
  }, [allEmplois, selectedClasseId, selectedSemestre]);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 4000);
  };

  // -------------------------------------------------------------
  // INITIALISATION / GÉNÉRATION DU SEMESTRE
  // -------------------------------------------------------------
  const handleGenererSemestre = async () => {
    if (!classeActuelle) {
      alert('Veuillez sélectionner une classe.');
      return;
    }

    const nouveau = genererEmploiDuTempsSemestriel({
      classe_id: classeActuelle.id,
      classe_nom: classeActuelle.nom,
      semestre: selectedSemestre,
      annee_academique: selectedAnnee,
      date_rentree: genRentreeDate,
      nb_semaines: genNbSemaines,
      creneaux: genCreneaux,
      jours_selectionnes: genJours
    });

    await db.emplois_du_temps_semestriels.put(nouveau);
    supabase.from('emplois_du_temps_semestriels').upsert(nouveau).then(() => {}, () => {});

    await logAction('DAC', 'Emploi du Temps', `Génération du calendrier académique pour ${classeActuelle.nom} (${selectedSemestre})`);
    setIsGenerateModalOpen(false);
    showToast(`✅ Calendrier académique de ${classeActuelle.nom} (${selectedSemestre}) généré avec succès !`);
  };

  // -------------------------------------------------------------
  // PUBLICATION OFFICIELLE DAC
  // -------------------------------------------------------------
  const handleTogglePublication = async () => {
    if (!currentEmploi || !classeActuelle) return;

    const nextStatus = !currentEmploi.publie;
    const actionText = nextStatus
      ? 'PUBLIER OFFICIELLEMENT ce calendrier académique (visible par les étudiants et enseignants)'
      : 'DÉPUBLIER ce calendrier académique (le repasser en brouillon DAC)';

    if (!window.confirm(`Confirmez-vous l'action : \n\n${actionText} ?`)) return;

    setIsPublishing(true);
    try {
      const updated: EmploiDuTempsSemestriel = {
        ...currentEmploi,
        publie: nextStatus,
        publie_par: 'DAC',
        date_publication: nextStatus ? new Date().toISOString() : undefined,
        updated_at: new Date().toISOString()
      };

      await db.emplois_du_temps_semestriels.put(updated);
      await supabase.from('emplois_du_temps_semestriels').upsert(updated);

      if (nextStatus) {
        const notif: NotificationItem = {
          id: `notif_edt_${Date.now()}`,
          user_id: 'all_students_teachers',
          type: 'system',
          titre: `Emploi du temps officiel : ${classeActuelle.nom}`,
          description: `Le calendrier académique officiel pour le ${formatSemestreLabel(selectedSemestre)} de la classe ${classeActuelle.nom} a été publié par le DAC.`,
          auteur_nom: 'Direction Académique (DAC)',
          auteur_role: 'DAC',
          lien_tab: 'emploi_temps',
          lu: false,
          created_at: new Date().toISOString()
        };
        await db.notifications.put(notif);
        supabase.from('notifications').insert([notif]).then(() => {}, () => {});
      }

      showToast(nextStatus ? '🎉 Calendrier officiel publié avec succès !' : '🔒 Calendrier dépublié (mode brouillon DAC).');
    } catch (err: any) {
      alert('Erreur lors de la publication : ' + err.message);
    } finally {
      setIsPublishing(false);
    }
  };

  // -------------------------------------------------------------
  // EXPORT PDF OFFICIEL FORMAT DAC
  // -------------------------------------------------------------
  const handleExportPDF = async () => {
    if (!currentEmploi) {
      alert('Veuillez d\'abord générer l\'emploi du temps.');
      return;
    }
    try {
      await exporterEmploiDuTempsOfficielDAC_PDF(currentEmploi);
      showToast('📄 Calendrier officiel PDF téléchargé avec succès !');
    } catch (err: any) {
      alert('Erreur lors de la génération du PDF : ' + err.message);
    }
  };

  // -------------------------------------------------------------
  // MISE A JOUR D'UNE CELLULE
  // -------------------------------------------------------------
  const handleSaveCell = async (cellData: CreneauCellule, applyToWholeWeek: boolean = false) => {
    if (!currentEmploi || !editingCell) return;

    const { semaineNum, creneau, jour } = editingCell;
    const updatedSemaines = currentEmploi.semaines.map(sem => {
      if (sem.numero !== semaineNum) return sem;

      const newCreneauxData = { ...sem.creneaux_data };

      if (applyToWholeWeek) {
        // Appliquer la matière et le professeur à tous les créneaux et jours de cette semaine
        currentEmploi.creneaux.forEach(cr => {
          if (!newCreneauxData[cr]) newCreneauxData[cr] = {};
          currentEmploi.jours_selectionnes.forEach(j => {
            const currentCell = newCreneauxData[cr][j] || {};
            newCreneauxData[cr][j] = {
              ...currentCell,
              matiere: cellData.matiere,
              matiere_id: cellData.matiere_id,
              enseignant: cellData.enseignant,
              enseignant_id: cellData.enseignant_id,
              type: cellData.type || 'cours',
              texte_personnalise: cellData.texte_personnalise
            };
          });
        });
      } else {
        // Appliquer uniquement à ce créneau spécifique
        if (!newCreneauxData[creneau]) newCreneauxData[creneau] = {};
        newCreneauxData[creneau][jour] = {
          ...newCreneauxData[creneau][jour],
          ...cellData
        };
      }

      return {
        ...sem,
        creneaux_data: newCreneauxData
      };
    });

    const updatedEmploi: EmploiDuTempsSemestriel = {
      ...currentEmploi,
      semaines: updatedSemaines,
      updated_at: new Date().toISOString()
    };

    await db.emplois_du_temps_semestriels.put(updatedEmploi);
    supabase.from('emplois_du_temps_semestriels').upsert(updatedEmploi).then(() => {}, () => {});

    setEditingCell(null);
    showToast(applyToWholeWeek ? '⚡ Semaine entière mise à jour !' : '✅ Créneau mis à jour !');
  };

  // -------------------------------------------------------------
  // ACTIONS RAPIDES PAR SEMAINE
  // -------------------------------------------------------------
  const handleSetWeekSpecial = async (semaineNum: number, specialType: 'tp' | 'conge' | 'examen' | 'conge_etude') => {
    if (!currentEmploi) return;

    const updatedSemaines = currentEmploi.semaines.map(sem => {
      if (sem.numero !== semaineNum) return sem;

      if (specialType === 'conge_etude') {
        return {
          ...sem,
          is_special: true,
          titre_semaine: "CONGÉ D'ÉTUDE",
          special_texte: "CONGÉ D'ÉTUDE — PRÉPARATION EXCLUSIVE AUX EXAMENS"
        };
      }

      // Marquer chaque créneau
      const newCreneauxData = { ...sem.creneaux_data };
      currentEmploi.creneaux.forEach(cr => {
        if (!newCreneauxData[cr]) newCreneauxData[cr] = {};
        currentEmploi.jours_selectionnes.forEach(j => {
          const currentCell = newCreneauxData[cr][j] || {};
          const dateLabel = currentCell.date_jour_label || '';
          let texte = '';
          if (specialType === 'conge') texte = `${dateLabel} 🎄 Congé`;
          else if (specialType === 'tp') texte = `${dateLabel} TP`;
          else if (specialType === 'examen') texte = `${dateLabel} Examens ${currentEmploi.semestre}`;

          newCreneauxData[cr][j] = {
            ...currentCell,
            type: specialType,
            texte_personnalise: texte,
            matiere: specialType === 'tp' ? 'Travaux Pratiques' : specialType === 'examen' ? 'Examens' : 'Congé',
            enseignant: ''
          };
        });
      });

      return {
        ...sem,
        is_special: false,
        creneaux_data: newCreneauxData
      };
    });

    const updatedEmploi: EmploiDuTempsSemestriel = {
      ...currentEmploi,
      semaines: updatedSemaines,
      updated_at: new Date().toISOString()
    };

    await db.emplois_du_temps_semestriels.put(updatedEmploi);
    supabase.from('emplois_du_temps_semestriels').upsert(updatedEmploi).then(() => {}, () => {});

    showToast(`Semaine ${semaineNum} configurée en ${specialType.toUpperCase()} !`);
  };

  // -------------------------------------------------------------
  // DATES IMPORTANTES DU CALENDRIER
  // -------------------------------------------------------------
  const handleSaveDatesImportantes = async (dates: DateImportanteCalendrier[]) => {
    if (!currentEmploi) return;
    const updated: EmploiDuTempsSemestriel = {
      ...currentEmploi,
      dates_importantes: dates,
      updated_at: new Date().toISOString()
    };
    await db.emplois_du_temps_semestriels.put(updated);
    supabase.from('emplois_du_temps_semestriels').upsert(updated).then(() => {}, () => {});
    setIsDatesModalOpen(false);
    showToast('Dates clés du calendrier mises à jour !');
  };

  return (
    <div className="space-y-6">
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-5 right-5 z-50 flex items-center gap-3 bg-slate-900 text-white px-5 py-3 rounded-xl shadow-2xl border border-slate-700 animate-in fade-in slide-in-from-top-4">
          <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          <span className="text-sm font-medium">{toastMessage}</span>
        </div>
      )}

      {/* Barre d'en-tête & Filtres principaux */}
      <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm space-y-4">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-blue-100 text-blue-700">
                Format Officiel DAC
              </span>
              <h1 className="text-xl lg:text-2xl font-black text-slate-900 tracking-tight">
                Calendrier Académique & Emploi du Temps
              </h1>
            </div>
            <p className="text-sm text-slate-500 mt-1">
              Établissement semestriel par semaine et par date selon les directives de la Direction Académique
            </p>
          </div>

          {/* Boutons d'actions */}
          <div className="flex flex-wrap items-center gap-2.5">
            <button
              onClick={() => setIsGenerateModalOpen(true)}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold shadow-sm shadow-blue-500/20 transition-all hover:scale-[1.02]"
            >
              <Zap className="w-4 h-4" />
              {currentEmploi ? 'Régénérer / Paramétrer' : 'Initialiser le Semestre'}
            </button>

            <button
              onClick={() => setIsDatesModalOpen(true)}
              disabled={!currentEmploi}
              className="inline-flex items-center gap-2 px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-semibold transition-colors disabled:opacity-50"
            >
              <CalendarDays className="w-4 h-4 text-slate-500" />
              Dates Clés
            </button>

            <button
              onClick={handleExportPDF}
              disabled={!currentEmploi}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold shadow-sm shadow-emerald-500/20 transition-all hover:scale-[1.02] disabled:opacity-50"
            >
              <Printer className="w-4 h-4" />
              Exporter PDF (Format DAC)
            </button>

            {currentEmploi && (
              <button
                onClick={handleTogglePublication}
                disabled={isPublishing}
                className={cn(
                  "inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all",
                  currentEmploi.publie
                    ? "bg-amber-100 text-amber-800 hover:bg-amber-200 border border-amber-300"
                    : "bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm shadow-indigo-500/20"
                )}
              >
                <Share2 className="w-4 h-4" />
                {currentEmploi.publie ? 'Dépublier' : 'Publier Officiellement'}
              </button>
            )}
          </div>
        </div>

        {/* Ligne de sélection : Classe, Semestre, Année */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 pt-3 border-t border-slate-100">
          <div>
            <label className="block text-xs font-bold text-slate-600 uppercase mb-1">Classe / Filière</label>
            <select
              value={selectedClasseId}
              onChange={(e) => setSelectedClasseId(e.target.value)}
              className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {classes.map(cl => (
                <option key={cl.id} value={cl.id}>
                  {cl.nom} ({cl.filiere || 'Filière'} - {cl.niveau || 'Niveau'})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-600 uppercase mb-1">Semestre</label>
            <select
              value={selectedSemestre}
              onChange={(e) => setSelectedSemestre(e.target.value)}
              className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {TOUS_LES_SEMESTRES.map(s => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-600 uppercase mb-1">Statut DAC</label>
            <div className="flex items-center h-10 px-3 bg-slate-50 border border-slate-200 rounded-xl">
              {currentEmploi ? (
                currentEmploi.publie ? (
                  <span className="inline-flex items-center gap-1.5 text-xs font-black text-emerald-700">
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    PUBLIÉ OFFICIELLEMENT (Accessible à tous)
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1.5 text-xs font-bold text-amber-700">
                    <span className="w-2 h-2 rounded-full bg-amber-500" />
                    BROUILLON CONFIDENTIEL (DAC Uniquement)
                  </span>
                )
              ) : (
                <span className="text-xs text-slate-400 italic">Non initialisé</span>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Si aucun calendrier n'existe pour cette sélection */}
      {!currentEmploi ? (
        <div className="bg-white rounded-2xl p-12 border-2 border-dashed border-slate-200 text-center space-y-4">
          <div className="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 mx-auto flex items-center justify-center">
            <Calendar className="w-8 h-8" />
          </div>
          <div className="max-w-md mx-auto">
            <h3 className="text-lg font-bold text-slate-800">
              Aucun calendrier semestriel généré pour {classeActuelle?.nom || 'cette classe'} ({selectedSemestre})
            </h3>
            <p className="text-sm text-slate-500 mt-1">
              Générez automatiquement la structure de l'emploi du temps avec les dates précises de chaque semaine, les créneaux et les jalons officiels de l'ISGI.
            </p>
          </div>
          <button
            onClick={() => setIsGenerateModalOpen(true)}
            className="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-lg shadow-blue-500/25 transition-all"
          >
            <Zap className="w-5 h-5" />
            Générer l'Emploi du Temps par Semaine & Date
          </button>
        </div>
      ) : (
        <>
          {/* Bandeau des Dates Importantes du Semestre */}
          <div className="bg-gradient-to-r from-slate-900 to-blue-950 text-white rounded-2xl p-4 lg:p-5 shadow-sm border border-slate-800">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <CalendarDays className="w-5 h-5 text-blue-400" />
                <h3 className="text-sm font-black tracking-wide uppercase">
                  Dates Importantes à Retenir du Calendrier Académique ({currentEmploi.semestre})
                </h3>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setIsDatesModalOpen(true)}
                  className="px-2.5 py-1 text-xs bg-white/10 hover:bg-white/20 rounded-lg font-semibold transition-colors"
                >
                  Modifier les dates
                </button>
                <button
                  onClick={() => setShowDatesBanner(!showDatesBanner)}
                  className="p-1 hover:bg-white/10 rounded-lg transition-colors"
                >
                  {showDatesBanner ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
                </button>
              </div>
            </div>

            {showDatesBanner && (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5 mt-4 pt-4 border-t border-white/10 text-xs">
                {(currentEmploi.dates_importantes || []).map((di, idx) => (
                  <div key={di.id || idx} className="flex items-start gap-2 bg-white/5 p-2.5 rounded-xl border border-white/10">
                    <span className="font-bold text-blue-300 shrink-0">{di.date_periode} :</span>
                    <span className="text-slate-200">{di.evenement}</span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Grille Principale de l'Emploi du Temps */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div className="p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-base font-black text-slate-900 tracking-tight">
                  CALENDRIER ACADÉMIQUE — {currentEmploi.titre}
                </h2>
                <p className="text-xs text-slate-500">
                  {currentEmploi.semaines.length} semaines planifiées • Jours de cours : {currentEmploi.jours_selectionnes.join(', ')} • Créneaux : {currentEmploi.creneaux.join(', ')}
                </p>
              </div>

              <div className="flex items-center gap-2 text-xs font-semibold text-slate-600">
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 text-amber-800 border border-amber-200 rounded-lg">
                  « Evaluation »
                </span>
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 text-purple-800 border border-purple-200 rounded-lg">
                  TP
                </span>
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-lg">
                  🎄 Congé
                </span>
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-blue-50 text-blue-800 border border-blue-200 rounded-lg">
                  Examens
                </span>
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-left">
                <thead>
                  <tr className="bg-slate-100 border-b-2 border-slate-300 text-xs font-black text-slate-800 uppercase tracking-wider">
                    <th className="py-3 px-3 border-r border-slate-200 text-center w-36">Semaine / Période</th>
                    <th className="py-3 px-2 border-r border-slate-200 text-center w-28">Heure</th>
                    {currentEmploi.jours_selectionnes.map(jour => (
                      <th key={jour} className="py-3 px-3 border-r border-slate-200 text-center">
                        {jour}
                      </th>
                    ))}
                    <th className="py-3 px-2 text-center w-28">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 text-xs">
                  {currentEmploi.semaines.map((sem) => {
                    if (sem.is_special) {
                      return (
                        <tr key={sem.numero} className="bg-slate-100/70 font-semibold hover:bg-slate-100">
                          <td className="py-3 px-3 border-r border-slate-200 text-center font-bold text-slate-900">
                            <div>{sem.titre_semaine || `Semaine ${sem.numero}`}</div>
                            <div className="text-[11px] font-normal text-slate-500 mt-0.5">{sem.periode_label}</div>
                          </td>
                          <td className="py-3 px-2 border-r border-slate-200 text-center text-slate-400">—</td>
                          <td colSpan={currentEmploi.jours_selectionnes.length} className="py-3 px-4 text-center font-bold text-slate-800 uppercase tracking-wide">
                            {sem.special_texte}
                          </td>
                          <td className="py-3 px-2 text-center">
                            <button
                              onClick={() => {
                                const updated = currentEmploi.semaines.map(s => s.numero === sem.numero ? { ...s, is_special: false } : s);
                                db.emplois_du_temps_semestriels.put({ ...currentEmploi, semaines: updated });
                              }}
                              className="px-2 py-1 bg-white hover:bg-slate-200 border border-slate-300 rounded text-[11px] font-semibold text-slate-700"
                            >
                              Restaurer
                            </button>
                          </td>
                        </tr>
                      );
                    }

                    return currentEmploi.creneaux.map((cr, crIdx) => (
                      <tr key={`${sem.numero}_${cr}`} className="hover:bg-blue-50/20 transition-colors">
                        {/* Colonne Semaine avec rowSpan */}
                        {crIdx === 0 && (
                          <td
                            rowSpan={currentEmploi.creneaux.length}
                            className="py-3 px-3 border-r border-slate-200 text-center align-middle bg-slate-50/50 font-bold text-slate-900"
                          >
                            <span className="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded font-black text-xs mb-1">
                              {sem.titre_semaine || `Semaine ${sem.numero}`}
                            </span>
                            <div className="text-[11px] font-medium text-slate-600 leading-tight">
                              {sem.periode_label}
                            </div>
                          </td>
                        )}

                        {/* Colonne Heure */}
                        <td className="py-3 px-2 border-r border-slate-200 text-center font-bold text-slate-700 whitespace-nowrap bg-slate-50/20">
                          {cr}
                        </td>

                        {/* Colonnes des Jours */}
                        {currentEmploi.jours_selectionnes.map((jour) => {
                          const cell = sem.creneaux_data?.[cr]?.[jour];
                          const hasContent = Boolean(cell?.matiere || cell?.texte_personnalise);

                          return (
                            <td
                              key={jour}
                              onClick={() => setEditingCell({
                                semaineNum: sem.numero,
                                creneau: cr,
                                jour,
                                data: cell || { date_jour_label: '', type: 'cours' }
                              })}
                              className={cn(
                                "py-2.5 px-3 border-r border-slate-200 align-top cursor-pointer transition-all hover:bg-blue-100/50 relative group",
                                cell?.type === 'conge' && "bg-emerald-50/40",
                                cell?.type === 'evaluation' && "bg-amber-50/40",
                                cell?.type === 'tp' && "bg-purple-50/40",
                                cell?.type === 'examen' && "bg-blue-50/40"
                              )}
                            >
                              {/* Date du jour */}
                              <div className="flex items-center justify-between text-[11px] font-bold text-slate-500 mb-1">
                                <span>{cell?.date_jour_label}</span>
                                {cell?.type === 'evaluation' && (
                                  <span className="text-[10px] font-black text-amber-700 bg-amber-100 px-1.5 py-0.2 rounded">
                                    « Evaluation »
                                  </span>
                                )}
                                {cell?.type === 'tp' && (
                                  <span className="text-[10px] font-black text-purple-700 bg-purple-100 px-1.5 py-0.2 rounded">
                                    TP
                                  </span>
                                )}
                                {cell?.type === 'examen' && (
                                  <span className="text-[10px] font-black text-blue-700 bg-blue-100 px-1.5 py-0.2 rounded">
                                    Examen
                                  </span>
                                )}
                                {cell?.type === 'conge' && (
                                  <span className="text-[10px] font-black text-emerald-700 bg-emerald-100 px-1.5 py-0.2 rounded">
                                    Congé
                                  </span>
                                )}
                              </div>

                              {/* Contenu */}
                              {cell?.texte_personnalise ? (
                                <div className="font-bold text-slate-800 text-center py-1">
                                  {cell.texte_personnalise}
                                </div>
                              ) : hasContent ? (
                                <div className="space-y-0.5">
                                  <div className="font-bold text-slate-900 leading-tight">
                                    {cell?.matiere}
                                  </div>
                                  {cell?.enseignant && (
                                    <div className="text-[11px] text-slate-600 flex items-center gap-1">
                                      <User className="w-3 h-3 text-slate-400 shrink-0" />
                                      <span>{cell.enseignant}</span>
                                    </div>
                                  )}
                                </div>
                              ) : (
                                <div className="h-9 flex items-center justify-center text-slate-300 group-hover:text-blue-500">
                                  <Plus className="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity" />
                                </div>
                              )}
                            </td>
                          );
                        })}

                        {/* Actions rapides par semaine */}
                        {crIdx === 0 && (
                          <td
                            rowSpan={currentEmploi.creneaux.length}
                            className="py-3 px-2 text-center align-middle bg-slate-50/30"
                          >
                            <div className="flex flex-col gap-1 items-center justify-center">
                              <button
                                onClick={() => setFillingWeek({
                                  semaineNum: sem.numero,
                                  matiere: '',
                                  enseignant: '',
                                  type: 'cours'
                                })}
                                title="Remplir toute la semaine"
                                className="px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-[10px] font-bold transition-colors w-full"
                              >
                                ⚡ Remplir
                              </button>
                              <button
                                onClick={() => handleSetWeekSpecial(sem.numero, 'conge')}
                                title="Mettre en congé"
                                className="px-2 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded text-[10px] font-semibold transition-colors w-full"
                              >
                                🌴 Congé
                              </button>
                              <button
                                onClick={() => handleSetWeekSpecial(sem.numero, 'tp')}
                                title="Mettre en TP"
                                className="px-2 py-0.5 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded text-[10px] font-semibold transition-colors w-full"
                              >
                                🔬 TP
                              </button>
                              <button
                                onClick={() => handleSetWeekSpecial(sem.numero, 'conge_etude')}
                                title="Congé d'étude"
                                className="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-[10px] font-semibold transition-colors w-full"
                              >
                                📖 Étude
                              </button>
                            </div>
                          </td>
                        )}
                      </tr>
                    ));
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {/* MODALE 1 : CONFIGURATION ET GÉNÉRATION DU SEMESTRE */}
      {isGenerateModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-5 animate-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                  <Zap className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-lg font-black text-slate-900">Paramétrage du Calendrier Semestriel</h3>
                  <p className="text-xs text-slate-500">Pour {classeActuelle?.nom} ({selectedSemestre})</p>
                </div>
              </div>
              <button onClick={() => setIsGenerateModalOpen(false)} className="text-slate-400 hover:text-slate-600">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">
                  Date de début de la rentrée (Premier Lundi)
                </label>
                <input
                  type="date"
                  value={genRentreeDate}
                  onChange={(e) => setGenRentreeDate(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
                <p className="text-[11px] text-slate-400 mt-1">Exemple : 12 octobre 2026 pour le Semestre 1</p>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">
                  Nombre de semaines planifiées
                </label>
                <input
                  type="number"
                  min="1"
                  max="35"
                  value={genNbSemaines}
                  onChange={(e) => setGenNbSemaines(Number(e.target.value))}
                  className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-2">
                  Jours de cours pour cette filière
                </label>
                <div className="grid grid-cols-3 gap-2">
                  {['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'].map(j => (
                    <label
                      key={j}
                      className={cn(
                        "flex items-center gap-2 p-2.5 rounded-xl border text-xs font-bold cursor-pointer transition-colors",
                        genJours.includes(j)
                          ? "bg-blue-50 border-blue-400 text-blue-900"
                          : "bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100"
                      )}
                    >
                      <input
                        type="checkbox"
                        checked={genJours.includes(j)}
                        onChange={(e) => {
                          if (e.target.checked) setGenJours([...genJours, j]);
                          else setGenJours(genJours.filter(x => x !== j));
                        }}
                        className="rounded text-blue-600 focus:ring-blue-500"
                      />
                      <span>{j}</span>
                    </label>
                  ))}
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">
                  Créneaux Horaires par jour
                </label>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={genCreneaux.join(', ')}
                    onChange={(e) => setGenCreneaux(e.target.value.split(',').map(s => s.trim()).filter(Boolean))}
                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  />
                </div>
                <p className="text-[11px] text-slate-400 mt-1">Séparés par des virgules (ex: 08H00–10H00, 10H15–12H15)</p>
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
              <button
                onClick={() => setIsGenerateModalOpen(false)}
                className="px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-800"
              >
                Annuler
              </button>
              <button
                onClick={handleGenererSemestre}
                className="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-blue-500/20"
              >
                Générer le Calendrier Daté
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODALE 2 : ÉDITION D'UNE CELLULE INDIVIDUELLE */}
      {editingCell && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4 animate-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <span className="text-[11px] font-bold text-blue-600 uppercase tracking-wide">
                  Semaine {editingCell.semaineNum} • {editingCell.jour} • {editingCell.creneau}
                </span>
                <h3 className="text-base font-black text-slate-900">
                  {editingCell.data.date_jour_label ? `Séance du ${editingCell.data.date_jour_label}` : 'Détail du cours'}
                </h3>
              </div>
              <button onClick={() => setEditingCell(null)} className="text-slate-400 hover:text-slate-600">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3.5">
              {/* Matière */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Matière</label>
                <div className="space-y-1.5">
                  <select
                    value={matieres.find(m => m.nom === editingCell.data.matiere)?.id || ''}
                    onChange={(e) => {
                      const sel = matieres.find(m => m.id === e.target.value);
                      if (sel) {
                        setEditingCell({
                          ...editingCell,
                          data: { ...editingCell.data, matiere: sel.nom, matiere_id: sel.id }
                        });
                      }
                    }}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold"
                  >
                    <option value="">-- Choisir une matière existante --</option>
                    {matieres.map(m => (
                      <option key={m.id} value={m.id}>{m.nom} ({m.code})</option>
                    ))}
                  </select>
                  <input
                    type="text"
                    placeholder="Ou saisir le nom librement (ex: Informatique Générale)"
                    value={editingCell.data.matiere || ''}
                    onChange={(e) => setEditingCell({
                      ...editingCell,
                      data: { ...editingCell.data, matiere: e.target.value }
                    })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  />
                </div>
              </div>

              {/* Enseignant */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Enseignant</label>
                <div className="space-y-1.5">
                  <select
                    value={enseignants.find(p => p.nom === editingCell.data.enseignant)?.id || ''}
                    onChange={(e) => {
                      const sel = enseignants.find(p => p.id === e.target.value);
                      if (sel) {
                        setEditingCell({
                          ...editingCell,
                          data: { ...editingCell.data, enseignant: `Mr ${sel.nom}`, enseignant_id: sel.id }
                        });
                      }
                    }}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold"
                  >
                    <option value="">-- Choisir un enseignant --</option>
                    {enseignants.map(p => (
                      <option key={p.id} value={p.id}>{p.nom} {p.prenom}</option>
                    ))}
                  </select>
                  <input
                    type="text"
                    placeholder="Ou saisir librement (ex: Mr Tertulien, Mr Moulari)"
                    value={editingCell.data.enseignant || ''}
                    onChange={(e) => setEditingCell({
                      ...editingCell,
                      data: { ...editingCell.data, enseignant: e.target.value }
                    })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  />
                </div>
              </div>

              {/* Type d'activité */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Type de séance</label>
                <div className="grid grid-cols-2 gap-2">
                  {[
                    { id: 'cours', label: 'Cours Standard' },
                    { id: 'evaluation', label: '« Evaluation »' },
                    { id: 'tp', label: 'TP' },
                    { id: 'conge', label: '🎄 Congé' },
                    { id: 'examen', label: '📝 Examen' }
                  ].map(t => (
                    <button
                      key={t.id}
                      type="button"
                      onClick={() => setEditingCell({
                        ...editingCell,
                        data: { ...editingCell.data, type: t.id as any }
                      })}
                      className={cn(
                        "py-2 px-2.5 rounded-xl border text-xs font-bold text-center transition-colors",
                        editingCell.data.type === t.id
                          ? "bg-blue-600 text-white border-blue-600"
                          : "bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100"
                      )}
                    >
                      {t.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Texte personnalisé */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">
                  Texte Personnalisé (Optionnel)
                </label>
                <input
                  type="text"
                  placeholder="Ex: 25 décembre 🎄 Congé, 22 mars Examens S1"
                  value={editingCell.data.texte_personnalise || ''}
                  onChange={(e) => setEditingCell({
                    ...editingCell,
                    data: { ...editingCell.data, texte_personnalise: e.target.value }
                  })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold"
                />
              </div>
            </div>

            <div className="space-y-2 pt-3 border-t border-slate-100">
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => handleSaveCell(editingCell.data, false)}
                  className="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-sm"
                >
                  Enregistrer ce créneau
                </button>
                <button
                  type="button"
                  onClick={() => handleSaveCell(editingCell.data, true)}
                  title="Applique cette matière et ce professeur sur tous les jours et heures de cette semaine"
                  className="py-2.5 px-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold rounded-xl text-xs"
                >
                  ⚡ Toute la semaine
                </button>
              </div>

              <button
                type="button"
                onClick={() => handleSaveCell({
                  date_jour_label: editingCell.data.date_jour_label,
                  matiere: '',
                  enseignant: '',
                  type: 'cours',
                  texte_personnalise: ''
                }, false)}
                className="w-full py-1.5 text-center text-xs text-rose-600 hover:text-rose-700 font-semibold"
              >
                Effacer le contenu de ce créneau
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODALE 3 : REMPLISSAGE RAPIDE DE TOUTE UNE SEMAINE */}
      {fillingWeek && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4 animate-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <span className="text-[11px] font-bold text-blue-600 uppercase">Action Rapide</span>
                <h3 className="text-base font-black text-slate-900">
                  Remplir toute la Semaine {fillingWeek.semaineNum}
                </h3>
              </div>
              <button onClick={() => setFillingWeek(null)} className="text-slate-400 hover:text-slate-600">
                <X className="w-5 h-5" />
              </button>
            </div>

            <p className="text-xs text-slate-500">
              Assignez en un clic la même matière et le même enseignant à tous les créneaux horaires de cette semaine (comme dans le calendrier ISGI).
            </p>

            <div className="space-y-3">
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Matière</label>
                <input
                  type="text"
                  placeholder="Ex: Mécanique Générale, MATHS, Dessin à la main..."
                  value={fillingWeek.matiere}
                  onChange={(e) => setFillingWeek({ ...fillingWeek, matiere: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Enseignant</label>
                <input
                  type="text"
                  placeholder="Ex: Mr Moulari, Mr Louheho, Mr Tertulien..."
                  value={fillingWeek.enseignant}
                  onChange={(e) => setFillingWeek({ ...fillingWeek, enseignant: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
              <button
                onClick={() => setFillingWeek(null)}
                className="px-3 py-2 text-xs font-semibold text-slate-600"
              >
                Annuler
              </button>
              <button
                onClick={() => {
                  if (!currentEmploi) return;
                  const updatedSemaines = currentEmploi.semaines.map(sem => {
                    if (sem.numero !== fillingWeek.semaineNum) return sem;
                    const newCreneauxData = { ...sem.creneaux_data };
                    currentEmploi.creneaux.forEach(cr => {
                      if (!newCreneauxData[cr]) newCreneauxData[cr] = {};
                      currentEmploi.jours_selectionnes.forEach(j => {
                        const cur = newCreneauxData[cr][j] || {};
                        newCreneauxData[cr][j] = {
                          ...cur,
                          matiere: fillingWeek.matiere,
                          enseignant: fillingWeek.enseignant,
                          type: 'cours'
                        };
                      });
                    });
                    return { ...sem, is_special: false, creneaux_data: newCreneauxData };
                  });
                  db.emplois_du_temps_semestriels.put({ ...currentEmploi, semaines: updatedSemaines });
                  setFillingWeek(null);
                  showToast(`Semaine ${fillingWeek.semaineNum} remplie avec succès !`);
                }}
                className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-sm"
              >
                Appliquer à tous les créneaux
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODALE 4 : ÉDITION DES DATES IMPORTANTES DU SEMESTRE */}
      {isDatesModalOpen && currentEmploi && (
        <DatesImportantesModal
          dates={currentEmploi.dates_importantes || []}
          semestre={currentEmploi.semestre}
          onClose={() => setIsDatesModalOpen(false)}
          onSave={handleSaveDatesImportantes}
        />
      )}
    </div>
  );
}

// -------------------------------------------------------------
// COMPOSANT MODALE : GESTION DES DATES IMPORTANTES
// -------------------------------------------------------------
function DatesImportantesModal({
  dates: initialDates,
  semestre,
  onClose,
  onSave
}: {
  dates: DateImportanteCalendrier[];
  semestre: string;
  onClose: () => void;
  onSave: (dates: DateImportanteCalendrier[]) => void;
}) {
  const [dates, setDates] = useState<DateImportanteCalendrier[]>([...initialDates]);
  const [newPeriode, setNewPeriode] = useState('');
  const [newEvenement, setNewEvenement] = useState('');

  const handleAdd = () => {
    if (!newPeriode.trim() || !newEvenement.trim()) return;
    setDates([
      ...dates,
      { id: `di_${Date.now()}`, date_periode: newPeriode.trim(), evenement: newEvenement.trim() }
    ]);
    setNewPeriode('');
    setNewEvenement('');
  };

  const handleDelete = (id: string) => {
    setDates(dates.filter(d => d.id !== id));
  };

  const handleResetDefaults = () => {
    const defaults = semestre.toUpperCase().includes('2')
      ? DATES_IMPORTANTES_DEFAULT_S2
      : DATES_IMPORTANTES_DEFAULT_S1;
    setDates([...defaults]);
  };

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 space-y-5 animate-in zoom-in-95">
        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
          <div className="flex items-center gap-2.5">
            <div className="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
              <CalendarDays className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-lg font-black text-slate-900">
                Dates Importantes à Retenir ({semestre})
              </h3>
              <p className="text-xs text-slate-500">
                Ces dates apparaîtront en tête du document officiel du DAC
              </p>
            </div>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Liste des dates */}
        <div className="space-y-2 max-h-80 overflow-y-auto pr-1">
          {dates.map((d, index) => (
            <div key={d.id || index} className="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border border-slate-200 text-xs">
              <input
                type="text"
                value={d.date_periode}
                onChange={(e) => {
                  const updated = [...dates];
                  updated[index].date_periode = e.target.value;
                  setDates(updated);
                }}
                className="w-48 px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-800"
              />
              <input
                type="text"
                value={d.evenement}
                onChange={(e) => {
                  const updated = [...dates];
                  updated[index].evenement = e.target.value;
                  setDates(updated);
                }}
                className="flex-1 px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-slate-800"
              />
              <button
                onClick={() => handleDelete(d.id)}
                className="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg transition-colors"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            </div>
          ))}
        </div>

        {/* Formulaire d'ajout rapide */}
        <div className="p-3 bg-blue-50/50 rounded-2xl border border-blue-100 space-y-2">
          <span className="text-xs font-bold text-blue-900">Ajouter un jalon</span>
          <div className="flex gap-2">
            <input
              type="text"
              placeholder="Ex: 12 octobre 2026"
              value={newPeriode}
              onChange={(e) => setNewPeriode(e.target.value)}
              className="w-48 px-3 py-1.5 bg-white border border-blue-200 rounded-xl text-xs font-semibold"
            />
            <input
              type="text"
              placeholder="Ex: 🎓 Début de la rentrée académique"
              value={newEvenement}
              onChange={(e) => setNewEvenement(e.target.value)}
              className="flex-1 px-3 py-1.5 bg-white border border-blue-200 rounded-xl text-xs"
            />
            <button
              onClick={handleAdd}
              className="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs"
            >
              Ajouter
            </button>
          </div>
        </div>

        <div className="flex items-center justify-between pt-3 border-t border-slate-100">
          <button
            onClick={handleResetDefaults}
            className="text-xs text-blue-600 hover:text-blue-800 font-semibold"
          >
            🔄 Rétablir le modèle par défaut ISGI
          </button>
          <div className="flex items-center gap-2">
            <button
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-slate-600"
            >
              Annuler
            </button>
            <button
              onClick={() => onSave(dates)}
              className="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-sm"
            >
              Enregistrer les Dates
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
