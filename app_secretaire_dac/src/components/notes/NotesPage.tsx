import React, { useState, useEffect, useMemo } from 'react';
import {
  Award,
  Save,
  CheckCircle2,
  Calculator,
  RefreshCw,
  BookOpen,
  BarChart2,
  FileSpreadsheet,
  Printer,
  Download,
  X,
  FileCheck2,
  Lock,
  Globe,
  Radio,
  Send,
  ShieldAlert
} from 'lucide-react';
import { useLiveQuery } from 'dexie-react-hooks';
import * as XLSX from 'xlsx';
import { db, logAction, setPublicationDAC, estPublieDAC } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Classe, Matiere, Etudiant, Note, ClasseEtudiant, ClasseMatiere } from '../../types';
import { getSemestresForClasse, getDefaultSemestreForClasse, formatSemestreLabel } from '../../utils/academicSemestres';
import { cn } from '../../lib/utils';

interface NoteInput {
  dst: string;
  dr: string;
  session: string;
  rattrapage: string;
}

/**
 * RÈGLES DE DÉLIBÉRATION ISGI
 *
 * Formule note matière :
 *   MG/20 = DST×20% + Devoir de Recherche×20% + Session×60%
 *   MG*CRD = MG × crédits de la matière
 *
 * Rattrapage : si note de rattrapage saisie, elle remplace MG si elle est meilleure.
 *
 * Moyenne générale pondérée :
 *   MG_général = Σ(MG_finale*CRD) / Σ(CRD)
 *
 * Décision en DEUX colonnes :
 *   OBSERVATION : VALIDE  → MG_général ≥ 10 ET toutes matières ≥ 6
 *                 AJOURNÉ → MG_général < 10 OU une matière < 6
 *
 *   MENTION     : ADMIS   → MG_général ≥ 10
 *                 AJOURNÉ → MG_général < 10
 *
 * Appréciation : Insuffisant / Passable / Assez Bien / Bien / Très Bien
 */

/** Calcul MG matière : DST×20% + DR×20% + Session×60% */
function computeMG(dst: number | null, dr: number | null, session: number | null): number | null {
  if (dst === null && dr === null && session === null) return null;
  const d = dst ?? 0;
  const r = dr ?? 0;
  const s = session ?? 0;
  return Number(((d * 0.20) + (r * 0.20) + (s * 0.60)).toFixed(2));
}

/** MG finale avec rattrapage (on garde le meilleur) */
function computeMGFinale(
  dst: number | null, dr: number | null, session: number | null, rattrapage: number | null
): number | null {
  const mg = computeMG(dst, dr, session);
  if (mg === null && rattrapage === null) return null;
  if (rattrapage !== null && mg !== null) return rattrapage > mg ? rattrapage : mg;
  if (rattrapage !== null) return rattrapage;
  return mg;
}

/** Appréciation selon la moyenne générale */
function getAppreciation(moy: number): string {
  if (moy >= 16) return 'Très Bien';
  if (moy >= 14) return 'Bien';
  if (moy >= 12) return 'Assez Bien';
  if (moy >= 10) return 'Passable';
  return 'Insuffisant';
}

/** OBSERVATION : VALIDE si MG≥10 ET toutes matières≥6, sinon AJOURNÉ */
function getObservation(mg: number | null, notesParMatiere: (number | null)[]): 'VALIDE' | 'AJOURNÉ' | 'EN_ATTENTE' {
  if (mg === null) return 'EN_ATTENTE';
  const toutesPresentes = notesParMatiere.every(n => n !== null);
  if (!toutesPresentes) return 'EN_ATTENTE';
  if (mg >= 10 && notesParMatiere.every(n => n !== null && n >= 6)) return 'VALIDE';
  return 'AJOURNÉ';
}

/** MENTION : ADMIS si MG≥10, sinon AJOURNÉ */
function getMentionDecision(mg: number | null): 'ADMIS' | 'AJOURNÉ' | 'EN_ATTENTE' {
  if (mg === null) return 'EN_ATTENTE';
  return mg >= 10 ? 'ADMIS' : 'AJOURNÉ';
}

interface NotesPageProps {
  onNavigate?: (tab: string) => void;
}

export const NotesPage: React.FC<NotesPageProps> = ({ onNavigate }) => {
  const [classes, setClasses] = useState<Classe[]>([]);
  const [matieres, setMatieres] = useState<Matiere[]>([]);
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [classeEtudiants, setClasseEtudiants] = useState<ClasseEtudiant[]>([]);
  const [classeMatieres, setClasseMatieres] = useState<ClasseMatiere[]>([]);
  const [existingNotes, setExistingNotes] = useState<Note[]>([]);

  const [selectedClasseId, setSelectedClasseId] = useState('');
  const [selectedMatiereId, setSelectedMatiereId] = useState('');
  const [selectedSemestre, setSelectedSemestre] = useState<string>('S1');

  const [notesInput, setNotesInput] = useState<{ [etudId: string]: NoteInput }>({});

  const [saving, setSaving] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [showPrintModal, setShowPrintModal] = useState(false);
  const [printViewMode, setPrintViewMode] = useState<'detaillee' | 'synthetique'>('detaillee');
  const [appSettings, setAppSettings] = useState<{ [key: string]: any }>({
    nomEcole: "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE",
    sigle: "ISGI",
    ville: "Brazzaville",
    enTeteMessageHaut: "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE",
    enTeteDirection: "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)",
    titreSignataire2: "Le Directeur des Affaires Académiques (DAC)"
  });

  const pendingSoumissionsClasse = useLiveQuery(async () => {
    if (!selectedClasseId) return [];
    try {
      const items = await db.soumissions_notes
        .where('classe_id')
        .equals(selectedClasseId)
        .toArray();
      return items.filter(s => s.statut === 'EN_ATTENTE');
    } catch {
      return [];
    }
  }, [selectedClasseId]) || [];

  // Publications officielles DAC (Notes & Moyennes)
  const publications = useLiveQuery(() => db.publications_academiques.toArray()) || [];
  const pubKey = `pub_notes_moyennes_${selectedClasseId}_${selectedSemestre}`;
  const currentPublication = publications.find(p => p.id === pubKey);
  const isPublishedForSelection = Boolean(currentPublication?.publie);
  const [isPublishing, setIsPublishing] = useState(false);

  const handleTogglePublication = async (newPublieState: boolean) => {
    if (!selectedClasseId || !selectedSemestre) {
      showToast("Veuillez sélectionner une classe et un semestre.");
      return;
    }
    setIsPublishing(true);
    try {
      await setPublicationDAC(
        'notes_moyennes',
        newPublieState,
        selectedClasseId,
        selectedSemestre,
        currentClasse?.nom,
        `Notes & Moyennes ${formatSemestreLabel(selectedSemestre)} - ${currentClasse?.nom}`
      );

      // Met à jour les notes existantes en mémoire
      setExistingNotes(prev => prev.map(n => {
        if (n.classe_id === selectedClasseId && n.semestre === selectedSemestre) {
          return { ...n, publie: newPublieState };
        }
        return n;
      }));

      showToast(
        newPublieState
          ? `📢 Résultats officiellement PUBLIÉS pour ${currentClasse?.nom} (${selectedSemestre}). Les étudiants et tuteurs peuvent désormais consulter leurs notes et moyennes !`
          : `🔒 Résultats retirés et passés en mode BROUILLON CONFIDENTIEL DAC pour ${currentClasse?.nom} (${selectedSemestre}).`
      );
    } catch (err: any) {
      showToast(`Erreur : ${err.message}`);
    } finally {
      setIsPublishing(false);
    }
  };

  useEffect(() => {
    loadBaseData();
    try {
      const saved = localStorage.getItem('isgi_settings');
      if (saved) {
        setAppSettings(prev => ({ ...prev, ...JSON.parse(saved) }));
      }
    } catch {}
  }, []);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  const loadBaseData = async () => {
    try {
      const cls = await db.classes.toArray();
      const mats = await db.matieres.toArray();
      const etuds = await db.etudiants.toArray();
      const cEtuds = await db.classe_etudiants.toArray();
      const cMats = await db.classe_matieres.toArray();
      const notes = await db.notes.toArray();

      setClasses(cls);
      setMatieres(mats);
      setEtudiants(etuds);
      setClasseEtudiants(cEtuds);
      setClasseMatieres(cMats);
      setExistingNotes(notes);

      if (cls.length > 0) {
        setSelectedClasseId(cls[0].id);
        setSelectedSemestre(getDefaultSemestreForClasse(cls[0]));
      }
    } catch (e) { console.error(e); }
  };

  const currentClasse = classes.find(c => c.id === selectedClasseId);

  // Semestres spécifiques selon le niveau de la classe (L1=S1/S2, L2=S3/S4, L3=S5/S6, M1=S7/S8, M2=S9/S10)
  const semestresDisponibles = useMemo(() => {
    return getSemestresForClasse(currentClasse);
  }, [currentClasse]);

  // Synchronisation automatique du semestre si changement de classe
  useEffect(() => {
    if (semestresDisponibles.length > 0) {
      const exists = semestresDisponibles.some(s => s.value === selectedSemestre);
      if (!exists) {
        setSelectedSemestre(semestresDisponibles[0].value);
      }
    }
  }, [semestresDisponibles, selectedSemestre]);

  const matieresDeLaClasse = useMemo(() =>
    classeMatieres
      .filter(cm => cm.classe_id === selectedClasseId)
      .map(cm => matieres.find(m => m.id === cm.matiere_id))
      .filter(Boolean) as Matiere[],
    [classeMatieres, matieres, selectedClasseId]
  );

  useEffect(() => {
    if (selectedClasseId) setSelectedMatiereId('');
  }, [selectedClasseId]);

  const etudiantsDeLaClasse = useMemo(() =>
    classeEtudiants
      .filter(ce => ce.classe_id === selectedClasseId && ce.statut === 'actif')
      .map(ce => etudiants.find(e => e.id === ce.etudiant_id))
      .filter(Boolean) as Etudiant[],
    [classeEtudiants, etudiants, selectedClasseId]
  );

  // Pré-remplir depuis les notes existantes
  useEffect(() => {
    if (!selectedClasseId || !selectedMatiereId) return;

    const initialInputs: { [k: string]: NoteInput } = {};
    etudiantsDeLaClasse.forEach(etud => {
      const noteKey = `note_${selectedClasseId}_${selectedMatiereId}_${etud.id}_${selectedSemestre}`;
      const found = existingNotes.find(n => n.id === noteKey);
      initialInputs[etud.id] = {
        dst:       found?.note_cc        !== undefined ? String(found.note_cc)        : '',
        dr:        found?.note_examen    !== undefined ? String(found.note_examen)    : '',
        session:   found?.note_finale    !== undefined ? String(found.note_finale)    : '',
        rattrapage: found?.note_rattrapage !== undefined ? String(found.note_rattrapage) : ''
      };
    });
    setNotesInput(initialInputs);
  }, [selectedClasseId, selectedMatiereId, selectedSemestre, existingNotes, etudiantsDeLaClasse.length]);

  const handleInputChange = (etudId: string, field: keyof NoteInput, value: string) => {
    if (value !== '') {
      const num = parseFloat(value);
      if (isNaN(num) || num < 0 || num > 20) return;
    }
    setNotesInput(prev => ({ ...prev, [etudId]: { ...prev[etudId], [field]: value } }));
  };

  const handleSaveNotes = async () => {
    if (!selectedClasseId || !selectedMatiereId || etudiantsDeLaClasse.length === 0) return;
    setSaving(true);
    try {
      const notesToSave: Note[] = [];
      const currentCls = classes.find(c => c.id === selectedClasseId);

      etudiantsDeLaClasse.forEach(etud => {
        const inp = notesInput[etud.id];
        if (!inp) return;

        const dst       = inp.dst       !== '' ? parseFloat(inp.dst)       : undefined;
        const dr        = inp.dr        !== '' ? parseFloat(inp.dr)        : undefined;
        const session   = inp.session   !== '' ? parseFloat(inp.session)   : undefined;
        const rattrapage = inp.rattrapage !== '' ? parseFloat(inp.rattrapage) : undefined;

        const mgFinale = computeMGFinale(dst ?? null, dr ?? null, session ?? null, rattrapage ?? null);

        notesToSave.push({
          id: `note_${selectedClasseId}_${selectedMatiereId}_${etud.id}_${selectedSemestre}`,
          classe_id:        selectedClasseId,
          matiere_id:       selectedMatiereId,
          etudiant_id:      etud.id,
          note_cc:          dst,          // DST
          note_examen:      dr,           // Devoir de Recherche
          note_finale:      session ?? 0,      // Note de session (brute, pour recharger)
          note_rattrapage:  rattrapage,
          semestre:         selectedSemestre,
          annee_academique: currentCls?.annee_academique || '2025-2026',
          saisi_par:        'DAC',
          observations:     mgFinale !== undefined ? String(mgFinale) : undefined,
          publie:           isPublishedForSelection,
          updated_at:       new Date().toISOString()
        });
      });

      await db.notes.bulkPut(notesToSave);
      supabase.from('notes').upsert(notesToSave).then(() => {}, () => {});

      await logAction('Saisie Notes', 'Notes',
        `Notes — Classe: ${currentCls?.nom || ''} — Matière: ${matieres.find(m => m.id === selectedMatiereId)?.nom || ''}`
      );

      setExistingNotes(prev => {
        const map = new Map(prev.map(n => [n.id, n]));
        notesToSave.forEach(n => map.set(n.id, n));
        return Array.from(map.values());
      });

      showToast(`${notesToSave.length} note(s) enregistrée(s) avec succès.`);
    } catch (err: any) {
      alert('Erreur : ' + err.message);
    } finally {
      setSaving(false);
    }
  };

  /** Récapitulatif délibération ultra-complet */
  const recapitulatif = useMemo(() => {
    return etudiantsDeLaClasse.map(etud => {
      const detailsParMatiere = matieresDeLaClasse.map(mat => {
        const noteKey = `note_${selectedClasseId}_${mat.id}_${etud.id}_${selectedSemestre}`;
        const found = existingNotes.find(note => note.id === noteKey);

        let dstVal: number | null = null;
        let drVal: number | null = null;
        let sessVal: number | null = null;
        let ratVal: number | null = null;

        if (mat.id === selectedMatiereId && notesInput[etud.id]) {
          const inp = notesInput[etud.id];
          dstVal = inp.dst !== '' ? parseFloat(inp.dst) : (found?.note_cc !== undefined ? found.note_cc : null);
          drVal = inp.dr !== '' ? parseFloat(inp.dr) : (found?.note_examen !== undefined ? found.note_examen : null);
          sessVal = inp.session !== '' ? parseFloat(inp.session) : (found?.note_finale !== undefined ? found.note_finale : null);
          ratVal = inp.rattrapage !== '' ? parseFloat(inp.rattrapage) : (found?.note_rattrapage !== undefined ? found.note_rattrapage : null);
        } else if (found) {
          dstVal = found.note_cc !== undefined ? found.note_cc : null;
          drVal = found.note_examen !== undefined ? found.note_examen : null;
          sessVal = found.note_finale !== undefined ? found.note_finale : null;
          ratVal = found.note_rattrapage !== undefined ? found.note_rattrapage : null;
        }

        const dstPond = dstVal !== null ? Number((dstVal * 0.20).toFixed(2)) : null;
        const drPond = drVal !== null ? Number((drVal * 0.20).toFixed(2)) : null;
        const sessPond = sessVal !== null ? Number((sessVal * 0.60).toFixed(2)) : null;

        let mg = computeMGFinale(dstVal, drVal, sessVal, ratVal);
        if (mg === null && found?.observations) {
          const v = parseFloat(found.observations);
          if (!isNaN(v)) mg = v;
        }

        const credits = mat.credits || 1;
        const points = mg !== null ? Number((mg * credits).toFixed(2)) : null;
        const isValide = mg !== null && mg >= 10;

        return {
          matiere: mat,
          dst: dstVal,
          dstPond,
          dr: drVal,
          drPond,
          session: sessVal,
          sessionPond: sessPond,
          rattrapage: ratVal,
          mg,
          credits,
          points,
          isValide
        };
      });

      const notesParMatiere = detailsParMatiere.map(d => d.mg);

      let totalPoints = 0;
      let totalCreditsInscrits = 0;
      let creditsValides = 0;

      detailsParMatiere.forEach(d => {
        totalCreditsInscrits += d.credits;
        if (d.mg !== null) {
          totalPoints += d.mg * d.credits;
          if (d.isValide) {
            creditsValides += d.credits;
          }
        }
      });

      const creditsRestants = totalCreditsInscrits - creditsValides;
      const tauxValidationCredits = totalCreditsInscrits > 0
        ? Number(((creditsValides / totalCreditsInscrits) * 100).toFixed(1))
        : 0;

      const evaluees = detailsParMatiere.filter(d => d.mg !== null);
      const evalCredits = evaluees.reduce<number>((s, d) => s + d.credits, 0);
      const mg = evalCredits > 0 ? Number((totalPoints / evalCredits).toFixed(2)) : null;
      const pourcentageGlobal = mg !== null ? Number(((mg / 20) * 100).toFixed(1)) : null;

      const observation = getObservation(mg, notesParMatiere);
      const mentionDecision = getMentionDecision(mg);
      const appreciation = mg !== null ? getAppreciation(mg) : '—';

      return {
        etud,
        detailsParMatiere,
        notesParMatiere,
        mg,
        pourcentageGlobal,
        tcTotal: totalCreditsInscrits,
        totalCreditsInscrits,
        creditsValides,
        creditsRestants,
        tauxValidationCredits,
        totalMGCRD: Number(totalPoints.toFixed(2)),
        observation,
        mentionDecision,
        appreciation
      };
    });
  }, [etudiantsDeLaClasse, matieresDeLaClasse, existingNotes, selectedClasseId, selectedSemestre, selectedMatiereId, notesInput]);

  const stats = useMemo(() => {
    const complets = recapitulatif.filter(r => r.observation !== 'EN_ATTENTE');
    const total = complets.length;
    const admis = complets.filter(r => r.mentionDecision === 'ADMIS').length;
    const admisValide = complets.filter(r => r.observation === 'VALIDE' && r.mentionDecision === 'ADMIS').length;
    const admisAjoune = complets.filter(r => r.observation === 'AJOURNÉ' && r.mentionDecision === 'ADMIS').length;
    const ajournes = complets.filter(r => r.mentionDecision === 'AJOURNÉ').length;
    const tauxReussite = total > 0 ? Number(((admis / total) * 100).toFixed(1)) : 0;
    const tauxEchec = total > 0 ? Number(((ajournes / total) * 100).toFixed(1)) : 0;

    const mgs = recapitulatif.map(r => r.mg).filter((m): m is number => m !== null);
    const moyennePromo = mgs.length > 0 ? Number((mgs.reduce((a, b) => a + b, 0) / mgs.length).toFixed(2)) : null;
    const maxMoyenne = mgs.length > 0 ? Math.max(...mgs) : null;
    const minMoyenne = mgs.length > 0 ? Math.min(...mgs) : null;

    return {
      total,
      admis,
      admisValide,
      admisAjoune,
      ajournes,
      tauxReussite,
      tauxEchec,
      moyennePromo,
      maxMoyenne,
      minMoyenne
    };
  }, [recapitulatif]);

  const recapTrie = useMemo(() =>
    [...recapitulatif].sort((a, b) => (b.mg ?? -1) - (a.mg ?? -1)),
    [recapitulatif]
  );

  /** EXPORT EXCEL ULTRA-COMPLET MULTI-FEUILLES */
  const handleExportXLS = () => {
    if (!currentClasse || recapitulatif.length === 0) {
      showToast("Aucune donnée disponible à exporter.");
      return;
    }

    const nomEcole = (appSettings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
    const sigle = appSettings.sigle || "ISGI";
    const ville = appSettings.ville || "Brazzaville";
    const direction = appSettings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)";
    const dateJour = new Date().toLocaleDateString('fr-FR');
    const semestreLabel = formatSemestreLabel(selectedSemestre);

    // ==========================================
    // FEUILLE 1 : RELEVÉ EXHAUSTIF DÉTAILLÉ (LMD)
    // ==========================================
    const aoaDetail: any[][] = [];
    aoaDetail.push([`${nomEcole} (${sigle})`]);
    aoaDetail.push([direction]);
    aoaDetail.push([`REGISTRE OFFICIEL EXHAUSTIF DES ÉVALUATIONS, PONDÉRATIONS, CRÉDITS ET RESTES (LMD)`]);
    aoaDetail.push([
      `Classe : ${currentClasse.nom}`,
      "",
      `Filière : ${currentClasse.filiere || 'Générale'}`,
      "",
      `Année Académique : ${currentClasse.annee_academique || '2025-2026'}`,
      "",
      `Semestre : ${semestreLabel}`,
      "",
      `Édité à ${ville}, le : ${dateJour}`
    ]);
    aoaDetail.push([]);

    // Ligne 6 : En-tête supérieur groupé
    const superHeaders: string[] = ["IDENTIFICATION ÉTUDIANT", "", "", "", "", ""];
    matieresDeLaClasse.forEach(m => {
      const mLabel = `[${m.code}] ${m.nom} (${m.credits || 1} CRD)`;
      superHeaders.push(mLabel, "", "", "", "", "", "", "", "", "", "");
    });
    superHeaders.push("SYNTHÈSE DE DÉLIBÉRATION (CRÉDITS, RESTES, POURCENTAGES & DÉCISIONS)", "", "", "", "", "", "", "", "", "", "");
    aoaDetail.push(superHeaders);

    // Ligne 7 : Colonnes individuelles détaillées
    const headersDetail: string[] = [
      "N°",
      "Matricule",
      "Nom",
      "Prénom",
      "Sexe",
      "Téléphone"
    ];

    matieresDeLaClasse.forEach(m => {
      headersDetail.push(
        `DST /20`,
        `DST (20%)`,
        `Dev. Rech /20`,
        `DR (20%)`,
        `Session /20`,
        `Sess (60%)`,
        `Rattrapage /20`,
        `MG Matière /20`,
        `Crédits (CRD)`,
        `Points (MG×CRD)`,
        `Statut UE`
      );
    });

    headersDetail.push(
      "Total Crédits Inscrits (TC)",
      "Crédits Validés (CRD-V)",
      "Crédits Restants (Non Validés)",
      "Taux Validation Crédits (%)",
      "Total Points (Σ MG×CRD)",
      "Moyenne Générale /20",
      "Pourcentage Global (%)",
      "Rang Promotion",
      "Observation (Valide/Ajourné)",
      "Décision Conseil (Admis/Ajourné)",
      "Mention & Appréciation"
    );
    aoaDetail.push(headersDetail);

    // Lignes étudiants (triées par mérite)
    recapTrie.forEach((item, index) => {
      const etud = item.etud;
      const row: any[] = [
        index + 1,
        etud.matricule,
        etud.nom.toUpperCase(),
        etud.prenom,
        etud.sexe || '—',
        etud.telephone || '—'
      ];

      item.detailsParMatiere.forEach(d => {
        row.push(
          d.dst !== null ? d.dst : "—",
          d.dstPond !== null ? d.dstPond : "—",
          d.dr !== null ? d.dr : "—",
          d.drPond !== null ? d.drPond : "—",
          d.session !== null ? d.session : "—",
          d.sessionPond !== null ? d.sessionPond : "—",
          d.rattrapage !== null ? d.rattrapage : "—",
          d.mg !== null ? d.mg : "—",
          d.credits,
          d.points !== null ? d.points : "—",
          d.mg !== null ? (d.isValide ? "VALIDÉ" : "NON VALIDÉ") : "EN ATTENTE"
        );
      });

      row.push(
        item.totalCreditsInscrits,
        item.creditsValides,
        item.creditsRestants,
        `${item.tauxValidationCredits}%`,
        item.totalMGCRD > 0 ? item.totalMGCRD : "—",
        item.mg !== null ? item.mg : "—",
        item.pourcentageGlobal !== null ? `${item.pourcentageGlobal}%` : "—",
        item.mg !== null ? `${index + 1}e` : "N/A",
        item.observation === 'VALIDE' ? 'VALIDE' : (item.observation === 'AJOURNÉ' ? 'AJOURNÉ' : 'EN ATTENTE'),
        item.mentionDecision === 'ADMIS' ? 'ADMIS' : (item.mentionDecision === 'AJOURNÉ' ? 'AJOURNÉ' : 'EN ATTENTE'),
        item.appreciation
      );

      aoaDetail.push(row);
    });

    // Ligne des moyennes de classe en bas de la feuille 1
    aoaDetail.push([]);
    const avgRow: any[] = ["MOYENNE DE LA PROMOTION", "", "", "", "", ""];
    matieresDeLaClasse.forEach((m, mIdx) => {
      const allDst = recapTrie.map(r => r.detailsParMatiere[mIdx].dst).filter((v): v is number => v !== null);
      const allDr = recapTrie.map(r => r.detailsParMatiere[mIdx].dr).filter((v): v is number => v !== null);
      const allSess = recapTrie.map(r => r.detailsParMatiere[mIdx].session).filter((v): v is number => v !== null);
      const allMg = recapTrie.map(r => r.detailsParMatiere[mIdx].mg).filter((v): v is number => v !== null);
      const allPts = recapTrie.map(r => r.detailsParMatiere[mIdx].points).filter((v): v is number => v !== null);

      const avgDst = allDst.length > 0 ? Number((allDst.reduce((a, b) => a + b, 0) / allDst.length).toFixed(2)) : "—";
      const avgDstPond = typeof avgDst === 'number' ? Number((avgDst * 0.2).toFixed(2)) : "—";
      const avgDr = allDr.length > 0 ? Number((allDr.reduce((a, b) => a + b, 0) / allDr.length).toFixed(2)) : "—";
      const avgDrPond = typeof avgDr === 'number' ? Number((avgDr * 0.2).toFixed(2)) : "—";
      const avgSess = allSess.length > 0 ? Number((allSess.reduce((a, b) => a + b, 0) / allSess.length).toFixed(2)) : "—";
      const avgSessPond = typeof avgSess === 'number' ? Number((avgSess * 0.6).toFixed(2)) : "—";
      const avgMg = allMg.length > 0 ? Number((allMg.reduce((a, b) => a + b, 0) / allMg.length).toFixed(2)) : "—";
      const avgPts = allPts.length > 0 ? Number((allPts.reduce((a, b) => a + b, 0) / allPts.length).toFixed(2)) : "—";

      avgRow.push(avgDst, avgDstPond, avgDr, avgDrPond, avgSess, avgSessPond, "—", avgMg, m.credits || 1, avgPts, "—");
    });

    const allCreditsValides = recapTrie.map(r => r.creditsValides);
    const avgCreditsValides = allCreditsValides.length > 0 ? Number((allCreditsValides.reduce((a, b) => a + b, 0) / allCreditsValides.length).toFixed(1)) : "—";
    const allCreditsRestants = recapTrie.map(r => r.creditsRestants);
    const avgCreditsRestants = allCreditsRestants.length > 0 ? Number((allCreditsRestants.reduce((a, b) => a + b, 0) / allCreditsRestants.length).toFixed(1)) : "—";
    const allPtsTotal = recapTrie.map(r => r.totalMGCRD).filter(p => p > 0);
    const avgPtsTotal = allPtsTotal.length > 0 ? Number((allPtsTotal.reduce((a, b) => a + b, 0) / allPtsTotal.length).toFixed(2)) : "—";

    avgRow.push(
      recapTrie[0]?.totalCreditsInscrits || "—",
      avgCreditsValides,
      avgCreditsRestants,
      "—",
      avgPtsTotal,
      stats.moyennePromo !== null ? stats.moyennePromo : "—",
      stats.moyennePromo !== null ? `${Number(((stats.moyennePromo / 20) * 100).toFixed(1))}%` : "—",
      "—",
      "—",
      "—",
      "—"
    );
    aoaDetail.push(avgRow);

    // Ligne des Taux de Réussite (%) par matière
    const passRow: any[] = ["TAUX DE RÉUSSITE PAR MATIÈRE (%)", "", "", "", "", ""];
    matieresDeLaClasse.forEach((m, mIdx) => {
      const allMg = recapTrie.map(r => r.detailsParMatiere[mIdx].mg).filter((v): v is number => v !== null);
      const valides = allMg.filter(v => v >= 10).length;
      const rate = allMg.length > 0 ? `${((valides / allMg.length) * 100).toFixed(1)}%` : "0%";
      passRow.push("—", "—", "—", "—", "—", "—", "—", rate, "—", "—", `${valides}/${allMg.length} admis`);
    });
    passRow.push("—", "—", "—", "—", "—", `${stats.tauxReussite}% Taux Promo`, "—", "—", "—", "—", "—");
    aoaDetail.push(passRow);

    aoaDetail.push([]);
    aoaDetail.push([`Fait à ${ville}, le ${dateJour}`, "", "", "", "", "", "", "", "", "", "", "Le Directeur des Affaires Académiques (DAC)"]);

    // ==========================================
    // FEUILLE 2 : PV D'AFFICHAGE AU TABLEAU
    // ==========================================
    const aoaAffichage: any[][] = [];
    aoaAffichage.push([`${nomEcole} (${sigle})`]);
    aoaAffichage.push([direction]);
    aoaAffichage.push([`PROCÈS-VERBAL OFFICIEL DE DÉLIBÉRATION & AFFICHAGE DES RÉSULTATS`]);
    aoaAffichage.push([
      `Classe : ${currentClasse.nom}`,
      "",
      `Filière : ${currentClasse.filiere || 'Générale'}`,
      "",
      `Année Académique : ${currentClasse.annee_academique || '2025-2026'}`,
      "",
      `Semestre : ${semestreLabel}`,
      "",
      `Affiché à ${ville}, le : ${dateJour}`
    ]);
    aoaAffichage.push([]);

    const headersAffichage = [
      "Rang",
      "N°",
      "Matricule",
      "Nom",
      "Prénom",
      "Sexe",
      ...matieresDeLaClasse.map(m => `[${m.code}] MG /20 (${m.credits || 1} crd)`),
      "Total Points (Σ MG×CRD)",
      "Crédits Validés",
      "Crédits Restants",
      "% Validation Crédits",
      "Moyenne Générale /20",
      "Pourcentage Global (%)",
      "Observation",
      "Décision Conseil",
      "Mention"
    ];
    aoaAffichage.push(headersAffichage);

    recapTrie.forEach((item, index) => {
      const etud = item.etud;
      const row = [
        item.mg !== null ? `${index + 1}e` : "—",
        index + 1,
        etud.matricule,
        etud.nom.toUpperCase(),
        etud.prenom,
        etud.sexe || '—',
        ...item.detailsParMatiere.map(d => (d.mg !== null ? d.mg : "—")),
        item.totalMGCRD > 0 ? item.totalMGCRD : "—",
        `${item.creditsValides} / ${item.totalCreditsInscrits}`,
        item.creditsRestants,
        `${item.tauxValidationCredits}%`,
        item.mg !== null ? item.mg : "—",
        item.pourcentageGlobal !== null ? `${item.pourcentageGlobal}%` : "—",
        item.observation,
        item.mentionDecision,
        item.appreciation
      ];
      aoaAffichage.push(row);
    });

    aoaAffichage.push([]);
    aoaAffichage.push(["BILAN SYNTHÉTIQUE DE LA PROMOTION"]);
    aoaAffichage.push(["Effectif Total", recapitulatif.length]);
    aoaAffichage.push(["Étudiants Évalués", stats.total]);
    aoaAffichage.push(["Total Admis", `${stats.admis} (${stats.tauxReussite}%)`]);
    aoaAffichage.push(["Admis Validés (Sans dette)", stats.admisValide]);
    aoaAffichage.push(["Admis Ajournés (Avec restes)", stats.admisAjoune]);
    aoaAffichage.push(["Total Ajournés (Échec)", `${stats.ajournes} (${stats.tauxEchec}%)`]);
    aoaAffichage.push(["Moyenne Générale Promo", stats.moyennePromo !== null ? `${stats.moyennePromo} / 20` : "—"]);
    aoaAffichage.push(["Plus Forte Moyenne", stats.maxMoyenne !== null ? `${stats.maxMoyenne} / 20` : "—"]);
    aoaAffichage.push(["Plus Faible Moyenne", stats.minMoyenne !== null ? `${stats.minMoyenne} / 20` : "—"]);
    aoaAffichage.push([]);
    aoaAffichage.push([`Fait à ${ville}, le ${dateJour}`, "", "", "", "", "Le Directeur des Affaires Académiques (DAC)"]);

    // ==========================================
    // FEUILLE 3 : STATISTIQUES & ANALYSE PÉDAGOGIQUE
    // ==========================================
    const aoaStats: any[][] = [];
    aoaStats.push([`${nomEcole} (${sigle})`]);
    aoaStats.push([direction]);
    aoaStats.push([`TABLEAU DE BORD STATISTIQUE, POURCENTAGES & ANALYSE PÉDAGOGIQUE`]);
    aoaStats.push([`Classe : ${currentClasse.nom} | Semestre : ${semestreLabel} | Année Académique : ${currentClasse.annee_academique || '2025-2026'}`]);
    aoaStats.push([]);

    aoaStats.push(["1. INDICATEURS CLÉS DE PERFORMANCE (KPIs)"]);
    aoaStats.push(["Indicateur", "Valeur Numérique", "Pourcentage / Interprétation"]);
    aoaStats.push(["Effectif total de la classe", recapitulatif.length, "100%"]);
    aoaStats.push(["Étudiants évalués / délibérés", stats.total, recapitulatif.length > 0 ? `${((stats.total / recapitulatif.length) * 100).toFixed(1)}%` : "0%"]);
    aoaStats.push(["Nombre total d'ADMIS", stats.admis, `${stats.tauxReussite}% (Taux de Réussite Global)`]);
    aoaStats.push(["Admis VALIDÉS (100% crédits acquis sans dette)", stats.admisValide, stats.total > 0 ? `${((stats.admisValide / stats.total) * 100).toFixed(1)}%` : "0%"]);
    aoaStats.push(["Admis AJOURNÉS (Admis avec crédits restants à rattraper)", stats.admisAjoune, stats.total > 0 ? `${((stats.admisAjoune / stats.total) * 100).toFixed(1)}%` : "0%"]);
    aoaStats.push(["Nombre total d'AJOURNÉS (Échec)", stats.ajournes, `${stats.tauxEchec}% (Taux d'Échec)`]);
    aoaStats.push(["Moyenne Générale de la Promotion", stats.moyennePromo !== null ? `${stats.moyennePromo} / 20` : "—", stats.moyennePromo !== null ? `${Number(((stats.moyennePromo / 20) * 100).toFixed(1))}%` : "—"]);
    aoaStats.push(["Plus forte moyenne enregistrée", stats.maxMoyenne !== null ? `${stats.maxMoyenne} / 20` : "—", stats.maxMoyenne !== null ? `Major de promo (${Number(((stats.maxMoyenne / 20) * 100).toFixed(1))}%)` : "—"]);
    aoaStats.push(["Plus faible moyenne enregistrée", stats.minMoyenne !== null ? `${stats.minMoyenne} / 20` : "—", stats.minMoyenne !== null ? `${Number(((stats.minMoyenne / 20) * 100).toFixed(1))}%` : "—"]);
    aoaStats.push([]);

    aoaStats.push(["2. ANALYSE STATISTIQUE ET TAUX DE RÉUSSITE PAR MATIÈRE"]);
    const headersMatStats = [
      "N°",
      "Code Matière",
      "Intitulé de la Matière",
      "Crédits (CRD)",
      "Moyenne DST /20",
      "Moyenne Dev. Recherche /20",
      "Moyenne Session /20",
      "Moyenne Matière /20",
      "Pourcentage Moyen (%)",
      "Étudiants Ayant Validé (≥ 10)",
      "Étudiants Ajournés (< 10)",
      "Taux de Réussite UE (%)"
    ];
    aoaStats.push(headersMatStats);

    matieresDeLaClasse.forEach((m, mIdx) => {
      const allDst = recapTrie.map(r => r.detailsParMatiere[mIdx].dst).filter((v): v is number => v !== null);
      const allDr = recapTrie.map(r => r.detailsParMatiere[mIdx].dr).filter((v): v is number => v !== null);
      const allSess = recapTrie.map(r => r.detailsParMatiere[mIdx].session).filter((v): v is number => v !== null);
      const allMg = recapTrie.map(r => r.detailsParMatiere[mIdx].mg).filter((v): v is number => v !== null);

      const avgDst = allDst.length > 0 ? Number((allDst.reduce((a, b) => a + b, 0) / allDst.length).toFixed(2)) : "—";
      const avgDr = allDr.length > 0 ? Number((allDr.reduce((a, b) => a + b, 0) / allDr.length).toFixed(2)) : "—";
      const avgSess = allSess.length > 0 ? Number((allSess.reduce((a, b) => a + b, 0) / allSess.length).toFixed(2)) : "—";
      const avgMg = allMg.length > 0 ? Number((allMg.reduce((a, b) => a + b, 0) / allMg.length).toFixed(2)) : "—";
      const pctMoyen = typeof avgMg === 'number' ? `${Number(((avgMg / 20) * 100).toFixed(1))}%` : "—";

      const valides = allMg.filter(v => v >= 10).length;
      const ajournes = allMg.filter(v => v < 10).length;
      const tauxReussiteMatiere = allMg.length > 0 ? `${((valides / allMg.length) * 100).toFixed(1)}%` : "0%";

      aoaStats.push([
        mIdx + 1,
        m.code,
        m.nom,
        m.credits || 1,
        avgDst,
        avgDr,
        avgSess,
        avgMg,
        pctMoyen,
        valides,
        ajournes,
        tauxReussiteMatiere
      ]);
    });

    aoaStats.push([]);
    aoaStats.push([`Fait à ${ville}, le ${dateJour}`, "", "", "", "", "", "", "Direction des Affaires Académiques (DAC)"]);

    // ==========================================
    // CRÉATION ET FORMATAGE DU CLASSEUR EXCEL
    // ==========================================
    const wb = XLSX.utils.book_new();

    const wsDetail = XLSX.utils.aoa_to_sheet(aoaDetail);
    const wsAffichage = XLSX.utils.aoa_to_sheet(aoaAffichage);
    const wsStats = XLSX.utils.aoa_to_sheet(aoaStats);

    // Largeurs de colonnes pour la feuille détaillée
    wsDetail['!cols'] = [
      { wch: 6 },  // N°
      { wch: 16 }, // Matricule
      { wch: 22 }, // Nom
      { wch: 20 }, // Prénom
      { wch: 8 },  // Sexe
      { wch: 16 }, // Téléphone
      ...matieresDeLaClasse.flatMap(() => [
        { wch: 10 }, // DST
        { wch: 10 }, // DST 20%
        { wch: 12 }, // DR
        { wch: 10 }, // DR 20%
        { wch: 12 }, // Sess
        { wch: 10 }, // Sess 60%
        { wch: 14 }, // Rat
        { wch: 14 }, // MG
        { wch: 10 }, // Crd
        { wch: 14 }, // Points
        { wch: 14 }  // Statut
      ]),
      { wch: 22 }, // TC
      { wch: 18 }, // CRD-V
      { wch: 22 }, // Restes
      { wch: 20 }, // Taux Valid %
      { wch: 22 }, // Pts
      { wch: 20 }, // MG
      { wch: 20 }, // % Global
      { wch: 12 }, // Rang
      { wch: 22 }, // Obs
      { wch: 22 }, // Decision
      { wch: 26 }  // Mention
    ];

    // Largeurs de colonnes pour l'affichage
    wsAffichage['!cols'] = [
      { wch: 8 },  // Rang
      { wch: 6 },  // N°
      { wch: 16 }, // Matricule
      { wch: 22 }, // Nom
      { wch: 20 }, // Prénom
      { wch: 8 },  // Sexe
      ...matieresDeLaClasse.map(() => ({ wch: 20 })),
      { wch: 22 }, // Pts
      { wch: 18 }, // Crd Val
      { wch: 18 }, // Crd Rest
      { wch: 18 }, // % Val
      { wch: 20 }, // MG
      { wch: 18 }, // % Global
      { wch: 20 }, // Obs
      { wch: 20 }, // Decision
      { wch: 24 }  // Mention
    ];

    // Largeurs de colonnes pour les statistiques
    wsStats['!cols'] = [
      { wch: 6 },
      { wch: 16 },
      { wch: 32 },
      { wch: 14 },
      { wch: 16 },
      { wch: 22 },
      { wch: 18 },
      { wch: 18 },
      { wch: 20 },
      { wch: 22 },
      { wch: 20 },
      { wch: 20 }
    ];

    XLSX.utils.book_append_sheet(wb, wsDetail, "Notes_Détaillées_LMD");
    XLSX.utils.book_append_sheet(wb, wsAffichage, "PV_Affichage_Tableau");
    XLSX.utils.book_append_sheet(wb, wsStats, "Statistiques_&_Pourcentages");

    const cleanClasseName = (currentClasse.nom || 'classe').replace(/[^a-zA-Z0-9_-]/g, '_');
    const fileName = `Grille_Complete_${cleanClasseName}_${selectedSemestre}_${new Date().toISOString().split('T')[0]}.xlsx`;
    XLSX.writeFile(wb, fileName);

    logAction('Export Excel Notes', 'Notes', `Téléchargement grille XLS exhaustive (3 feuilles): ${currentClasse.nom} (${selectedSemestre})`);
    showToast(`Grille complète multi-feuilles de ${currentClasse.nom} (${selectedSemestre}) exportée avec succès !`);
  };

  const currentMatiere = matieres.find(m => m.id === selectedMatiereId);
  const inputClass = "w-14 text-center p-1.5 font-semibold rounded-lg border border-outline-variant bg-surface-container text-on-surface focus:ring-2 focus:ring-primary outline-none text-xs";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Saisie des Notes & Délibération</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Formule : <strong>MG = DST×20% + Devoir de Recherche×20% + Session×60%</strong>
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <button
            onClick={handleExportXLS}
            disabled={!selectedClasseId || etudiantsDeLaClasse.length === 0}
            className="flex items-center gap-2 bg-secondary-container text-on-secondary-container px-4 py-2 rounded-full font-medium text-sm hover:bg-secondary-container/80 transition-colors disabled:opacity-50 shadow-xs"
            title="Télécharger la liste complète de la classe en Excel (.xlsx) avec toutes les matières pour affichage"
          >
            <FileSpreadsheet className="w-4 h-4 text-primary" />
            <span>Télécharger XLS (Affichage)</span>
          </button>
          <button
            onClick={() => setShowPrintModal(true)}
            disabled={!selectedClasseId || etudiantsDeLaClasse.length === 0}
            className="flex items-center gap-2 bg-surface-container-highest text-on-surface px-4 py-2 rounded-full font-medium text-sm hover:bg-surface-container-high transition-colors disabled:opacity-50 border border-outline-variant shadow-xs"
            title="Aperçu officiel et impression directe pour affichage au tableau"
          >
            <Printer className="w-4 h-4 text-primary" />
            <span>Imprimer PV Affichage</span>
          </button>
          <button
            onClick={handleSaveNotes}
            disabled={saving || !selectedMatiereId || etudiantsDeLaClasse.length === 0}
            className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors disabled:opacity-50 shadow-xs"
          >
            <Save className="w-4 h-4" />
            <span>{saving ? 'Enregistrement...' : 'Enregistrer'}</span>
          </button>
        </div>
      </div>

      {/* Légende */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
        <div className="flex items-center gap-2 bg-surface-container rounded-xl px-3 py-2 border border-outline-variant">
          <span className="w-2.5 h-2.5 rounded-full bg-green-500 flex-shrink-0"></span>
          <span><strong>VALIDE + ADMIS</strong> : MG≥10 &amp; toutes matières≥6</span>
        </div>
        <div className="flex items-center gap-2 bg-surface-container rounded-xl px-3 py-2 border border-outline-variant">
          <span className="w-2.5 h-2.5 rounded-full bg-blue-500 flex-shrink-0"></span>
          <span><strong>AJOURNÉ + ADMIS</strong> : MG≥10 mais matière &lt;6</span>
        </div>
        <div className="flex items-center gap-2 bg-surface-container rounded-xl px-3 py-2 border border-outline-variant">
          <span className="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0"></span>
          <span><strong>AJOURNÉ + AJOURNÉ</strong> : MG &lt;10</span>
        </div>
      </div>

      {/* Banner bordereaux profs en attente */}
      {pendingSoumissionsClasse.length > 0 && (
        <div className="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
          <div className="flex items-start sm:items-center gap-3">
            <div className="p-2.5 rounded-xl bg-amber-500/20 text-amber-700 dark:text-amber-300 shrink-0">
              <FileCheck2 className="w-5 h-5" />
            </div>
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                {pendingSoumissionsClasse.length} bordereau(x) de notes en attente de validation pour cette classe
              </p>
              <p className="text-xs text-amber-800/80 dark:text-amber-300/80 mt-0.5">
                Des évaluations (DST, Devoir de Recherche ou Session) ont été soumises par un enseignant. Validez-les pour les injecter directement dans ce tableau.
              </p>
            </div>
          </div>
          {onNavigate && (
            <button
              onClick={() => onNavigate('validation_notes')}
              className="px-4 py-2 rounded-full bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold transition-all shadow-xs shrink-0 flex items-center gap-2 self-end sm:self-center cursor-pointer"
            >
              <FileCheck2 className="w-4 h-4" />
              <span>Examiner &amp; Valider</span>
            </button>
          )}
        </div>
      )}

      {/* Bandeau de Publication Officielle DAC */}
      {selectedClasseId && (
        <div className={cn(
          "p-4 rounded-2xl border transition-all flex flex-col md:flex-row items-start md:items-center justify-between gap-4 shadow-sm",
          isPublishedForSelection
            ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-950 dark:text-emerald-200"
            : "bg-amber-500/10 border-amber-500/30 text-amber-950 dark:text-amber-200"
        )}>
          <div className="flex items-start gap-3">
            <div className={cn(
              "p-2.5 rounded-xl shrink-0 mt-0.5",
              isPublishedForSelection
                ? "bg-emerald-500/20 text-emerald-700 dark:text-emerald-300"
                : "bg-amber-500/20 text-amber-700 dark:text-amber-300"
            )}>
              {isPublishedForSelection ? <Globe className="w-5 h-5" /> : <Lock className="w-5 h-5" />}
            </div>
            <div>
              <div className="flex items-center gap-2 flex-wrap">
                <span className={cn(
                  "text-xs uppercase font-extrabold px-2.5 py-0.5 rounded-full tracking-wider border",
                  isPublishedForSelection
                    ? "bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/60 dark:text-emerald-300 dark:border-emerald-700"
                    : "bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/60 dark:text-amber-300 dark:border-amber-700"
                )}>
                  {isPublishedForSelection ? "Statut : RÉSULTATS & MOYENNES PUBLIÉS" : "Statut : BROUILLON CONFIDENTIEL DAC"}
                </span>
                {currentPublication?.date_publication && isPublishedForSelection && (
                  <span className="text-[11px] text-emerald-800/80 dark:text-emerald-300/80">
                    Publié le {new Date(currentPublication.date_publication).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                  </span>
                )}
              </div>
              <p className="text-xs font-semibold mt-1">
                {isPublishedForSelection
                  ? `Les notes, moyennes et délibérations de ${currentClasse?.nom || ''} (${formatSemestreLabel(selectedSemestre)}) sont OFFICIELLEMENT PUBLIÉES. Les étudiants, tuteurs et le portail peuvent consulter leurs notes.`
                  : `Ces résultats sont en mode BROUILLON CONFIDENTIEL DAC. Aucun autre compte (étudiant, tuteur, portail) ne peut y accéder tant que vous ne les publiez pas.`}
              </p>
            </div>
          </div>

          <button
            onClick={() => handleTogglePublication(!isPublishedForSelection)}
            disabled={isPublishing || etudiantsDeLaClasse.length === 0}
            className={cn(
              "px-4 py-2 rounded-full font-bold text-xs transition-all shadow-xs flex items-center gap-2 shrink-0 cursor-pointer disabled:opacity-50",
              isPublishedForSelection
                ? "bg-surface-container-lowest text-rose-600 border border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                : "bg-emerald-600 hover:bg-emerald-700 text-white hover:shadow-sm"
            )}
          >
            {isPublishing ? (
              <>
                <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                <span>Mise à jour...</span>
              </>
            ) : isPublishedForSelection ? (
              <>
                <Lock className="w-3.5 h-3.5" />
                <span>Dépublier (Rendre Confidentiel)</span>
              </>
            ) : (
              <>
                <Globe className="w-3.5 h-3.5" />
                <span>📢 Publier Officiellement les Résultats</span>
              </>
            )}
          </button>
        </div>
      )}

      {/* Sélecteurs */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant p-5 space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label className="text-xs font-semibold text-on-surface block mb-1">1. Classe *</label>
            <select
              value={selectedClasseId}
              onChange={(e) => { setSelectedClasseId(e.target.value); setSelectedMatiereId(''); }}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
            >
              <option value="">-- Sélectionner une classe --</option>
              {classes.map(c => <option key={c.id} value={c.id}>{c.nom} ({c.code})</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-semibold text-on-surface block mb-1">2. Matière *</label>
            <select
              value={selectedMatiereId}
              onChange={(e) => setSelectedMatiereId(e.target.value)}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
            >
              <option value="">-- Sélectionner une matière --</option>
              {matieresDeLaClasse.map(m => <option key={m.id} value={m.id}>[{m.code}] {m.nom} ({m.credits} crédits)</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-semibold text-on-surface block mb-1">
              3. Semestre {currentClasse ? `(${currentClasse.niveau || currentClasse.nom})` : '*'}
            </label>
            <select
              value={selectedSemestre}
              onChange={(e) => setSelectedSemestre(e.target.value)}
              className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none font-medium"
            >
              {semestresDisponibles.map(s => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="pt-2 border-t border-outline-variant flex flex-wrap justify-between gap-2 text-xs text-on-surface-variant font-medium">
          <span className="flex items-center gap-1.5">
            <Calculator className="w-4 h-4 text-primary" />
            <strong>DST×20% + Devoir Rech.×20% + Session×60%</strong>
            {currentMatiere && <span className="ml-2 text-primary">| {currentMatiere.credits} crédits</span>}
          </span>
          <span className="flex items-center gap-1.5">
            <BookOpen className="w-4 h-4 text-primary" />
            {etudiantsDeLaClasse.length} étudiant(s) — {matieresDeLaClasse.length} matière(s)
          </span>
        </div>
      </div>

      {/* ===== TABLE DE SAISIE ===== */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden">
        <div className="px-5 py-3 border-b border-outline-variant flex items-center gap-2">
          <Award className="w-4 h-4 text-primary" />
          <span className="font-semibold text-sm text-on-surface">
            Saisie des notes{currentMatiere ? ` — ${currentMatiere.nom}` : ''}
          </span>
        </div>

        {!selectedClasseId ? (
          <div className="p-12 text-center text-on-surface-variant text-xs">Sélectionnez une classe pour commencer.</div>
        ) : matieresDeLaClasse.length === 0 ? (
          <div className="p-12 text-center text-on-surface-variant text-xs">
            <BookOpen className="w-8 h-8 mx-auto mb-2 opacity-30" />
            Cette classe n'a pas de matières rattachées.
          </div>
        ) : !selectedMatiereId ? (
          <div className="p-12 text-center text-on-surface-variant text-xs">Sélectionnez une matière.</div>
        ) : etudiantsDeLaClasse.length === 0 ? (
          <div className="p-12 text-center text-on-surface-variant text-xs">Aucun étudiant affecté à cette classe.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                  <th className="py-3 px-2 text-center">N°</th>
                  <th className="py-3 px-3">Étudiant</th>
                  <th className="py-3 px-2 text-center">CRD</th>
                  {/* DST */}
                  <th className="py-3 px-2 text-center bg-blue-50 dark:bg-blue-950/20">
                    DST<br /><span className="text-[9px] font-normal normal-case">/20 × 20%</span>
                  </th>
                  <th className="py-3 px-2 text-center bg-blue-50 dark:bg-blue-950/20">
                    DST<br /><span className="text-[9px] font-normal normal-case">×0.2</span>
                  </th>
                  {/* Devoir de Recherche */}
                  <th className="py-3 px-2 text-center bg-purple-50 dark:bg-purple-950/20">
                    Dv. Rech.<br /><span className="text-[9px] font-normal normal-case">/20 × 20%</span>
                  </th>
                  <th className="py-3 px-2 text-center bg-purple-50 dark:bg-purple-950/20">
                    Dv.R<br /><span className="text-[9px] font-normal normal-case">×0.2</span>
                  </th>
                  {/* Session */}
                  <th className="py-3 px-2 text-center bg-amber-50 dark:bg-amber-950/20">
                    Session<br /><span className="text-[9px] font-normal normal-case">/20 × 60%</span>
                  </th>
                  <th className="py-3 px-2 text-center bg-amber-50 dark:bg-amber-950/20">
                    Sess.<br /><span className="text-[9px] font-normal normal-case">×0.6</span>
                  </th>
                  {/* Rattrapage */}
                  <th className="py-3 px-2 text-center bg-red-50 dark:bg-red-950/20">
                    Rattrap.<br /><span className="text-[9px] font-normal normal-case">/20</span>
                  </th>
                  {/* MG */}
                  <th className="py-3 px-2 text-center bg-green-50 dark:bg-green-950/20">
                    MG/20
                  </th>
                  <th className="py-3 px-2 text-center bg-green-50 dark:bg-green-950/20">
                    MG×CRD
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant text-on-surface bg-surface-container-lowest">
                {etudiantsDeLaClasse.map((etud, idx) => {
                  const inp = notesInput[etud.id] || { dst: '', dr: '', session: '', rattrapage: '' };
                  const dst       = inp.dst       !== '' ? parseFloat(inp.dst)       : null;
                  const dr        = inp.dr        !== '' ? parseFloat(inp.dr)        : null;
                  const session   = inp.session   !== '' ? parseFloat(inp.session)   : null;
                  const rattrapage = inp.rattrapage !== '' ? parseFloat(inp.rattrapage) : null;

                  const mgSession = computeMG(dst, dr, session);
                  const mgFinale  = computeMGFinale(dst, dr, session, rattrapage);
                  const crd       = currentMatiere?.credits || 1;
                  const mgCrd     = mgFinale !== null ? Number((mgFinale * crd).toFixed(2)) : null;

                  const mgColor = mgFinale === null ? '' :
                    mgFinale >= 10 ? 'text-green-600 dark:text-green-400' :
                    mgFinale >= 6  ? 'text-blue-600 dark:text-blue-400'  :
                    'text-red-600 dark:text-red-400';

                  const rattrapageActif = rattrapage !== null && mgSession !== null && rattrapage > mgSession;

                  return (
                    <tr key={etud.id} className="hover:bg-surface-container-high/40 transition-colors">
                      <td className="py-2 px-2 font-bold text-on-surface-variant text-center">{idx + 1}</td>
                      <td className="py-2 px-3">
                        <p className="font-bold text-on-surface leading-tight">{etud.nom.toUpperCase()} {etud.prenom}</p>
                        <p className="text-[10px] text-on-surface-variant font-mono">{etud.matricule}</p>
                      </td>

                      {/* CRD */}
                      <td className="py-2 px-2 text-center font-semibold text-on-surface-variant">{crd}</td>

                      {/* DST input */}
                      <td className="py-2 px-2 text-center bg-blue-50/50 dark:bg-blue-950/10">
                        <input type="number" step="0.25" min="0" max="20" placeholder="—"
                          value={inp.dst}
                          onChange={(e) => handleInputChange(etud.id, 'dst', e.target.value)}
                          className={`${inputClass} bg-blue-50 dark:bg-blue-950/20`}
                        />
                      </td>
                      {/* DST×0.2 */}
                      <td className="py-2 px-2 text-center text-on-surface-variant font-semibold bg-blue-50/30 dark:bg-blue-950/5">
                        {dst !== null ? (dst * 0.2).toFixed(2) : '—'}
                      </td>

                      {/* Devoir de Recherche input */}
                      <td className="py-2 px-2 text-center bg-purple-50/50 dark:bg-purple-950/10">
                        <input type="number" step="0.25" min="0" max="20" placeholder="—"
                          value={inp.dr}
                          onChange={(e) => handleInputChange(etud.id, 'dr', e.target.value)}
                          className={`${inputClass} bg-purple-50 dark:bg-purple-950/20`}
                        />
                      </td>
                      {/* DR×0.2 */}
                      <td className="py-2 px-2 text-center text-on-surface-variant font-semibold bg-purple-50/30 dark:bg-purple-950/5">
                        {dr !== null ? (dr * 0.2).toFixed(2) : '—'}
                      </td>

                      {/* Session input */}
                      <td className="py-2 px-2 text-center bg-amber-50/50 dark:bg-amber-950/10">
                        <input type="number" step="0.25" min="0" max="20" placeholder="—"
                          value={inp.session}
                          onChange={(e) => handleInputChange(etud.id, 'session', e.target.value)}
                          className={`${inputClass} bg-amber-50 dark:bg-amber-950/20`}
                        />
                      </td>
                      {/* Session×0.6 */}
                      <td className="py-2 px-2 text-center text-on-surface-variant font-semibold bg-amber-50/30 dark:bg-amber-950/5">
                        {session !== null ? (session * 0.6).toFixed(2) : '—'}
                      </td>

                      {/* Rattrapage input */}
                      <td className="py-2 px-2 text-center bg-red-50/50 dark:bg-red-950/10">
                        <input type="number" step="0.25" min="0" max="20" placeholder="—"
                          value={inp.rattrapage}
                          onChange={(e) => handleInputChange(etud.id, 'rattrapage', e.target.value)}
                          className={`${inputClass} bg-red-50 dark:bg-red-950/20`}
                        />
                        {rattrapageActif && (
                          <p className="text-[9px] text-green-600 mt-0.5">↑ retenu</p>
                        )}
                      </td>

                      {/* MG/20 */}
                      <td className="py-2 px-2 text-center bg-green-50/50 dark:bg-green-950/10">
                        {mgFinale !== null ? (
                          <span className={`font-bold text-sm ${mgColor}`}>
                            {mgFinale.toFixed(2)}
                          </span>
                        ) : (
                          <span className="text-on-surface-variant">—</span>
                        )}
                      </td>

                      {/* MG×CRD */}
                      <td className="py-2 px-2 text-center bg-green-50/30 dark:bg-green-950/5 font-semibold text-on-surface-variant">
                        {mgCrd !== null ? mgCrd.toFixed(2) : '—'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ===== RÉCAPITULATIF DE DÉLIBÉRATION ===== */}
      {selectedClasseId && matieresDeLaClasse.length > 0 && etudiantsDeLaClasse.length > 0 && (
        <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden">
          <div className="px-5 py-3 border-b border-outline-variant flex items-center justify-between flex-wrap gap-2">
            <div className="flex items-center gap-2 flex-wrap">
              <RefreshCw className="w-4 h-4 text-primary" />
              <span className="font-semibold text-sm text-on-surface">Délibération — Vue d'ensemble ({formatSemestreLabel(selectedSemestre)})</span>
              <span className="text-xs text-on-surface-variant">({matieresDeLaClasse.length} matière(s) • {etudiantsDeLaClasse.length} élève(s))</span>
              {isPublishedForSelection ? (
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-300/60 inline-flex items-center gap-1">
                  <Globe className="w-3 h-3" /> Résultats Publiés
                </span>
              ) : (
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300/60 inline-flex items-center gap-1">
                  <Lock className="w-3 h-3" /> Brouillon Confidentiel DAC
                </span>
              )}
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={handleExportXLS}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl bg-secondary-container text-on-secondary-container hover:bg-secondary-container/80 transition-colors shadow-xs"
                title="Télécharger toute la grille en fichier Excel XLS"
              >
                <FileSpreadsheet className="w-3.5 h-3.5 text-primary" />
                <span>Télécharger XLS</span>
              </button>
              <button
                onClick={() => setShowPrintModal(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl bg-surface-container-highest text-on-surface hover:bg-surface-container-high border border-outline-variant transition-colors shadow-xs"
                title="Imprimer pour affichage au tableau"
              >
                <Printer className="w-3.5 h-3.5 text-primary" />
                <span>Imprimer pour Affichage</span>
              </button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                  <th className="py-2.5 px-3 text-center">Rang</th>
                  <th className="py-2.5 px-3">Étudiant</th>
                  {matieresDeLaClasse.map(m => (
                    <th key={m.id} className="py-2.5 px-2 text-center" title={m.nom}>
                      {m.code}<br /><span className="text-[9px] font-normal normal-case">/20</span>
                    </th>
                  ))}
                  <th className="py-2.5 px-2 text-center">TC</th>
                  <th className="py-2.5 px-2 text-center" title="Crédits Validés / Crédits Restants">Créd. V/R</th>
                  <th className="py-2.5 px-2 text-center">TMG×CRD</th>
                  <th className="py-2.5 px-3 text-center bg-primary/10">MG /20</th>
                  <th className="py-2.5 px-2 text-center">% Global</th>
                  <th className="py-2.5 px-3 text-center">OBSERVATION</th>
                  <th className="py-2.5 px-3 text-center">MENTION</th>
                  <th className="py-2.5 px-3 text-center">Appréciation</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant bg-surface-container-lowest">
                {recapTrie.map((row, idx) => {
                  const obsColor =
                    row.observation === 'VALIDE'   ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' :
                    row.observation === 'AJOURNÉ'  ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' :
                    'bg-surface-container text-on-surface-variant';

                  const mentColor =
                    row.mentionDecision === 'ADMIS'   ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' :
                    row.mentionDecision === 'AJOURNÉ' ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' :
                    'bg-surface-container text-on-surface-variant';

                  return (
                    <tr key={row.etud.id} className="hover:bg-surface-container-high/40 transition-colors">
                      <td className="py-2.5 px-3 font-bold text-on-surface-variant text-center">{idx + 1}</td>
                      <td className="py-2.5 px-3">
                        <p className="font-bold text-on-surface">{row.etud.nom.toUpperCase()} {row.etud.prenom}</p>
                        <p className="text-[10px] font-mono text-on-surface-variant">{row.etud.matricule}</p>
                      </td>
                      {row.notesParMatiere.map((note, mIdx) => (
                        <td key={mIdx} className="py-2.5 px-2 text-center">
                          {note !== null ? (
                            <span className={`font-semibold ${note >= 10 ? 'text-green-600 dark:text-green-400' : note >= 6 ? 'text-blue-600 dark:text-blue-400' : 'text-red-600'}`}>
                              {note.toFixed(2)}
                            </span>
                          ) : (
                            <span className="text-on-surface-variant">—</span>
                          )}
                        </td>
                      ))}
                      {/* TC */}
                      <td className="py-2.5 px-2 text-center text-on-surface-variant font-semibold">{row.tcTotal}</td>
                      {/* Créd. V/R */}
                      <td className="py-2.5 px-2 text-center font-semibold text-xs whitespace-nowrap">
                        <span className="text-green-600 dark:text-green-400 font-bold">{row.creditsValides}V</span>
                        {" / "}
                        <span className={row.creditsRestants > 0 ? "text-amber-600 dark:text-amber-400 font-bold" : "text-slate-400"}>
                          {row.creditsRestants}R
                        </span>
                      </td>
                      {/* TMG×CRD */}
                      <td className="py-2.5 px-2 text-center text-on-surface-variant font-semibold">
                        {row.totalMGCRD > 0 ? row.totalMGCRD.toFixed(1) : '—'}
                      </td>
                      {/* MG général */}
                      <td className="py-2.5 px-3 text-center bg-primary/5">
                        {row.mg !== null ? (
                          <span className={`font-bold text-sm ${row.mg >= 10 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                            {row.mg.toFixed(2)}
                          </span>
                        ) : <span className="text-on-surface-variant">—</span>}
                      </td>
                      {/* % Global */}
                      <td className="py-2.5 px-2 text-center font-bold text-blue-700 dark:text-blue-400">
                        {row.pourcentageGlobal !== null ? `${row.pourcentageGlobal}%` : '—'}
                      </td>
                      {/* OBSERVATION */}
                      <td className="py-2.5 px-3 text-center">
                        {row.observation !== 'EN_ATTENTE' ? (
                          <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${obsColor}`}>
                            {row.observation}
                          </span>
                        ) : <span className="text-[10px] text-on-surface-variant">—</span>}
                      </td>
                      {/* MENTION */}
                      <td className="py-2.5 px-3 text-center">
                        {row.mentionDecision !== 'EN_ATTENTE' ? (
                          <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${mentColor}`}>
                            {row.mentionDecision}
                          </span>
                        ) : <span className="text-[10px] text-on-surface-variant">—</span>}
                      </td>
                      {/* Appréciation */}
                      <td className="py-2.5 px-3 text-center text-[11px] text-on-surface-variant italic">
                        {row.mg !== null ? row.appreciation : '—'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Statistiques globales */}
          <div className="border-t border-outline-variant px-5 py-4">
            <div className="flex items-center gap-2 mb-3">
              <BarChart2 className="w-4 h-4 text-primary" />
              <span className="font-semibold text-sm text-on-surface">Statistiques de la délibération &amp; Pourcentages</span>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-6 gap-3 text-xs">
              <div className="bg-surface-container rounded-xl p-3 text-center border border-outline-variant">
                <p className="text-2xl font-bold text-on-surface">{stats.total}</p>
                <p className="text-on-surface-variant mt-0.5">Total délibérés</p>
              </div>
              <div className="bg-blue-50 dark:bg-blue-950/30 rounded-xl p-3 text-center border border-blue-200 dark:border-blue-800">
                <p className="text-2xl font-bold text-blue-700 dark:text-blue-400">
                  {stats.admis} <span className="text-xs font-normal">({stats.tauxReussite}%)</span>
                </p>
                <p className="text-blue-600 dark:text-blue-300 mt-0.5">Total ADMIS</p>
              </div>
              <div className="bg-green-50 dark:bg-green-950/30 rounded-xl p-3 text-center border border-green-200 dark:border-green-800">
                <p className="text-2xl font-bold text-green-700 dark:text-green-400">{stats.admisValide}</p>
                <p className="text-green-600 dark:text-green-300 mt-0.5">Admis VALIDÉ (sans dette)</p>
              </div>
              <div className="bg-amber-50 dark:bg-amber-950/30 rounded-xl p-3 text-center border border-amber-200 dark:border-amber-800">
                <p className="text-2xl font-bold text-amber-700 dark:text-amber-400">{stats.admisAjoune}</p>
                <p className="text-amber-600 dark:text-amber-300 mt-0.5">Admis AJOURNÉ (avec restes)</p>
              </div>
              <div className="bg-red-50 dark:bg-red-950/30 rounded-xl p-3 text-center border border-red-200 dark:border-red-800">
                <p className="text-2xl font-bold text-red-700 dark:text-red-400">
                  {stats.ajournes} <span className="text-xs font-normal">({stats.tauxEchec}%)</span>
                </p>
                <p className="text-red-600 dark:text-red-300 mt-0.5">Total AJOURNÉS</p>
              </div>
              <div className="bg-purple-50 dark:bg-purple-950/30 rounded-xl p-3 text-center border border-purple-200 dark:border-purple-800">
                <p className="text-2xl font-bold text-purple-700 dark:text-purple-400">
                  {stats.moyennePromo !== null ? `${stats.moyennePromo}/20` : '—'}
                </p>
                <p className="text-purple-600 dark:text-purple-300 mt-0.5">Moyenne Promo</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ===== MODALE OFFICIELLE : PROCÈS-VERBAL & AFFICHAGE AU TABLEAU ===== */}
      {showPrintModal && currentClasse && (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center z-50 p-4">
          <div className="bg-surface-container-lowest text-on-surface rounded-2xl max-w-7xl w-full max-h-[94vh] flex flex-col shadow-2xl border border-outline-variant overflow-hidden">
            {/* Header de la modale */}
            <div className="px-6 py-4 border-b border-outline-variant flex justify-between items-center bg-surface-container flex-wrap gap-3">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-primary/10 text-primary">
                  <Printer className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-sm sm:text-base text-on-surface">
                    Procès-Verbal de Délibération &amp; Affichage Officiel des Notes
                  </h3>
                  <p className="text-xs text-on-surface-variant">
                    {currentClasse.nom} • {formatSemestreLabel(selectedSemestre)} • {recapitulatif.length} étudiants
                  </p>
                </div>
              </div>

              {/* Sélecteur de mode de vue */}
              <div className="flex items-center bg-surface-container-high p-1 rounded-xl border border-outline-variant text-xs">
                <button
                  type="button"
                  onClick={() => setPrintViewMode('synthetique')}
                  className={`px-3 py-1.5 rounded-lg font-semibold transition-all ${
                    printViewMode === 'synthetique'
                      ? 'bg-primary text-on-primary shadow-xs'
                      : 'text-on-surface-variant hover:text-on-surface'
                  }`}
                >
                  Vue Synthétique (Affichage Tableau)
                </button>
                <button
                  type="button"
                  onClick={() => setPrintViewMode('detaillee')}
                  className={`px-3 py-1.5 rounded-lg font-semibold transition-all ${
                    printViewMode === 'detaillee'
                      ? 'bg-primary text-on-primary shadow-xs'
                      : 'text-on-surface-variant hover:text-on-surface'
                  }`}
                >
                  Vue Détaillée (DST, DR, Sess, Restes)
                </button>
              </div>

              <div className="flex items-center gap-2">
                <button
                  onClick={handleExportXLS}
                  className="flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-xl bg-secondary-container text-on-secondary-container hover:bg-secondary-container/80 transition-colors shadow-xs"
                  title="Télécharger toute la grille en fichier Excel .xlsx avec 3 feuilles"
                >
                  <Download className="w-4 h-4" />
                  <span>Télécharger XLS (3 feuilles)</span>
                </button>
                <button
                  onClick={() => window.print()}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold rounded-xl bg-primary text-on-primary hover:bg-primary/90 transition-colors shadow-xs"
                  title="Imprimer directement sur papier ou en PDF"
                >
                  <Printer className="w-4 h-4" />
                  <span>Imprimer le PV</span>
                </button>
                <button
                  onClick={() => setShowPrintModal(false)}
                  className="p-2 rounded-xl hover:bg-surface-container-high text-on-surface-variant transition-colors"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>

            {/* Corps imprimable (identifié par #print-affichage pour le CSS d'impression) */}
            <div className="overflow-y-auto p-6 space-y-6 flex-1 bg-white text-slate-900 custom-scrollbar" id="print-affichage">
              {/* En-tête officiel ISGI */}
              <div className="flex justify-between items-center pb-4 border-b-2 border-slate-800">
                <div className="flex items-center gap-4">
                  <img src="./logo.jpg" alt="Logo ISGI" className="w-16 h-16 object-contain rounded-lg border border-slate-200" />
                  <div>
                    <p className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                      {appSettings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR"}
                    </p>
                    <h2 className="text-base sm:text-lg font-black text-slate-900 uppercase">
                      {appSettings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE"} ({appSettings.sigle || "ISGI"})
                    </h2>
                    <p className="text-xs font-bold text-blue-800">
                      {appSettings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"}
                    </p>
                  </div>
                </div>
                <div className="text-right">
                  <span className="inline-block px-3 py-1 bg-slate-100 text-slate-800 font-mono text-xs font-bold rounded-md border border-slate-300">
                    {printViewMode === 'detaillee' ? 'GRAND LIVRE DES NOTES (LMD)' : "PV D'AFFICHAGE OFFICIEL"}
                  </span>
                  <p className="text-xs text-slate-600 mt-1">
                    Fait à {appSettings.ville || 'Brazzaville'}, le {new Date().toLocaleDateString('fr-FR')}
                  </p>
                </div>
              </div>

              {/* Fiche Identification Promotion */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs text-slate-800 font-medium">
                <div><span className="text-slate-500 block text-[11px]">Classe :</span> <strong className="text-slate-900">{currentClasse.nom}</strong></div>
                <div><span className="text-slate-500 block text-[11px]">Filière :</span> <strong className="text-slate-900">{currentClasse.filiere || 'Générale'}</strong></div>
                <div><span className="text-slate-500 block text-[11px]">Année Académique :</span> <strong className="text-slate-900">{currentClasse.annee_academique || '2025-2026'}</strong></div>
                <div><span className="text-slate-500 block text-[11px]">Semestre :</span> <strong className="text-blue-700">{formatSemestreLabel(selectedSemestre)}</strong></div>
              </div>

              {/* Grille des notes pour affichage - MODE SYNTHÉTIQUE */}
              {printViewMode === 'synthetique' && (
                <div className="border border-slate-300 rounded-lg overflow-hidden">
                  <table className="w-full text-left border-collapse text-[11px]">
                    <thead>
                      <tr className="bg-slate-100 text-slate-800 uppercase font-bold border-b border-slate-300">
                        <th className="py-2 px-2 text-center border-r border-slate-300 w-8">N°</th>
                        <th className="py-2 px-2 border-r border-slate-300 w-24">Matricule</th>
                        <th className="py-2 px-2 border-r border-slate-300">Nom &amp; Prénom</th>
                        {matieresDeLaClasse.map(m => (
                          <th key={m.id} className="py-2 px-1.5 text-center border-r border-slate-300 text-[10px]" title={m.nom}>
                            {m.code}<br /><span className="text-[9px] font-normal">({m.credits || 1} crd)</span>
                          </th>
                        ))}
                        <th className="py-2 px-1.5 text-center border-r border-slate-300">Créd. Val.</th>
                        <th className="py-2 px-1.5 text-center border-r border-slate-300">Restes</th>
                        <th className="py-2 px-2 text-center border-r border-slate-300 bg-blue-50/80 font-bold">Moyenne</th>
                        <th className="py-2 px-1.5 text-center border-r border-slate-300">% Global</th>
                        <th className="py-2 px-1.5 text-center border-r border-slate-300">Rang</th>
                        <th className="py-2 px-2 text-center border-r border-slate-300">Observation</th>
                        <th className="py-2 px-2 text-center">Mention</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-200">
                      {recapTrie.map((row, idx) => (
                        <tr key={row.etud.id} className={idx % 2 === 1 ? 'bg-slate-50/50' : 'bg-white'}>
                          <td className="py-1.5 px-2 text-center font-bold border-r border-slate-200 text-slate-600">{idx + 1}</td>
                          <td className="py-1.5 px-2 font-mono text-[10px] border-r border-slate-200">{row.etud.matricule}</td>
                          <td className="py-1.5 px-2 font-bold text-slate-900 border-r border-slate-200">
                            {row.etud.nom.toUpperCase()} {row.etud.prenom}
                          </td>
                          {row.detailsParMatiere.map((d, mIdx) => (
                            <td key={mIdx} className="py-1.5 px-1 text-center border-r border-slate-200">
                              {d.mg !== null ? (
                                <span className={`font-semibold ${d.isValide ? 'text-green-700 font-bold' : d.mg >= 6 ? 'text-slate-800' : 'text-red-700 font-bold'}`}>
                                  {d.mg.toFixed(2)}
                                </span>
                              ) : <span className="text-slate-400">—</span>}
                            </td>
                          ))}
                          <td className="py-1.5 px-1.5 text-center border-r border-slate-200 font-semibold text-green-800">
                            {row.creditsValides}/{row.tcTotal}
                          </td>
                          <td className="py-1.5 px-1.5 text-center border-r border-slate-200 font-medium">
                            {row.creditsRestants > 0 ? (
                              <span className="text-amber-700 font-bold">{row.creditsRestants} crd</span>
                            ) : (
                              <span className="text-green-700 font-medium">0</span>
                            )}
                          </td>
                          <td className="py-1.5 px-2 text-center border-r border-slate-200 bg-blue-50/50 font-bold text-slate-900">
                            {row.mg !== null ? (
                              <span className={row.mg >= 10 ? 'text-green-700' : 'text-red-700'}>
                                {row.mg.toFixed(2)}
                              </span>
                            ) : '—'}
                          </td>
                          <td className="py-1.5 px-1.5 text-center border-r border-slate-200 font-semibold text-blue-700">
                            {row.pourcentageGlobal !== null ? `${row.pourcentageGlobal}%` : '—'}
                          </td>
                          <td className="py-1.5 px-1.5 text-center border-r border-slate-200 font-bold text-slate-700">
                            {row.mg !== null ? `${idx + 1}e` : '—'}
                          </td>
                          <td className="py-1.5 px-2 text-center border-r border-slate-200 font-bold text-[10px]">
                            {row.observation === 'VALIDE' ? (
                              <span className="text-green-700">VALIDE</span>
                            ) : row.observation === 'AJOURNÉ' ? (
                              <span className="text-amber-700">AJOURNÉ</span>
                            ) : '—'}
                          </td>
                          <td className="py-1.5 px-2 text-center font-bold text-[10px]">
                            {row.mentionDecision === 'ADMIS' ? (
                              <span className="text-blue-700">ADMIS</span>
                            ) : row.mentionDecision === 'AJOURNÉ' ? (
                              <span className="text-red-700">AJOURNÉ</span>
                            ) : '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {/* Grille des notes pour affichage - MODE DÉTAILLÉ */}
              {printViewMode === 'detaillee' && (
                <div className="border border-slate-300 rounded-lg overflow-x-auto">
                  <table className="w-full text-left border-collapse text-[10px]">
                    <thead>
                      <tr className="bg-slate-200 text-slate-800 uppercase font-bold border-b border-slate-300">
                        <th rowSpan={2} className="py-2 px-1 text-center border-r border-slate-300 w-6">N°</th>
                        <th rowSpan={2} className="py-2 px-1.5 border-r border-slate-300 w-20">Matricule</th>
                        <th rowSpan={2} className="py-2 px-2 border-r border-slate-300">Étudiant</th>
                        {matieresDeLaClasse.map(m => (
                          <th key={m.id} colSpan={5} className="py-1 px-1 text-center border-r border-slate-300 bg-slate-100 text-[9px]" title={m.nom}>
                            [{m.code}] {m.nom.length > 15 ? m.nom.slice(0, 15) + '...' : m.nom} ({m.credits || 1} crd)
                          </th>
                        ))}
                        <th colSpan={3} className="py-1 px-1 text-center border-r border-slate-300 bg-blue-50/80">Crédits</th>
                        <th rowSpan={2} className="py-2 px-1 text-center border-r border-slate-300 bg-blue-100 font-bold">MG /20</th>
                        <th rowSpan={2} className="py-2 px-1 text-center border-r border-slate-300">% Réussite</th>
                        <th rowSpan={2} className="py-2 px-1 text-center border-r border-slate-300">Rang</th>
                        <th rowSpan={2} className="py-2 px-1.5 text-center">Décision</th>
                      </tr>
                      <tr className="bg-slate-100 text-slate-700 uppercase font-semibold text-[8.5px] border-b border-slate-300">
                        {matieresDeLaClasse.map(m => (
                          <React.Fragment key={m.id}>
                            <th className="py-1 px-0.5 text-center border-r border-slate-200" title="DST 20%">DST</th>
                            <th className="py-1 px-0.5 text-center border-r border-slate-200" title="Devoir Recherche 20%">DR</th>
                            <th className="py-1 px-0.5 text-center border-r border-slate-200" title="Session 60%">Sess</th>
                            <th className="py-1 px-0.5 text-center border-r border-slate-200 font-bold bg-slate-50" title="Moyenne Générale Matière">MG</th>
                            <th className="py-1 px-0.5 text-center border-r border-slate-300 text-slate-500" title="Points (MG × Crédits)">Pts</th>
                          </React.Fragment>
                        ))}
                        <th className="py-1 px-1 text-center border-r border-slate-200">Inscr.</th>
                        <th className="py-1 px-1 text-center border-r border-slate-200 text-green-700">Val.</th>
                        <th className="py-1 px-1 text-center border-r border-slate-300 text-amber-700">Restes</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-200">
                      {recapTrie.map((row, idx) => (
                        <tr key={row.etud.id} className={idx % 2 === 1 ? 'bg-slate-50/60' : 'bg-white'}>
                          <td className="py-1 px-1 text-center font-bold border-r border-slate-200 text-slate-600">{idx + 1}</td>
                          <td className="py-1 px-1.5 font-mono text-[9px] border-r border-slate-200">{row.etud.matricule}</td>
                          <td className="py-1 px-2 font-bold text-slate-900 border-r border-slate-200 whitespace-nowrap">
                            {row.etud.nom.toUpperCase()} {row.etud.prenom}
                          </td>
                          {row.detailsParMatiere.map((d, mIdx) => (
                            <React.Fragment key={mIdx}>
                              <td className="py-1 px-0.5 text-center border-r border-slate-200 text-[9px]">
                                {d.dst !== null ? d.dst : '—'}
                              </td>
                              <td className="py-1 px-0.5 text-center border-r border-slate-200 text-[9px]">
                                {d.dr !== null ? d.dr : '—'}
                              </td>
                              <td className="py-1 px-0.5 text-center border-r border-slate-200 text-[9px]">
                                {d.session !== null ? d.session : '—'}
                              </td>
                              <td className="py-1 px-0.5 text-center border-r border-slate-200 font-bold bg-slate-50/50">
                                {d.mg !== null ? (
                                  <span className={d.isValide ? 'text-green-700' : 'text-red-700'}>
                                    {d.mg.toFixed(2)}
                                  </span>
                                ) : '—'}
                              </td>
                              <td className="py-1 px-0.5 text-center border-r border-slate-300 text-slate-500 text-[9px]">
                                {d.points !== null ? d.points.toFixed(1) : '—'}
                              </td>
                            </React.Fragment>
                          ))}
                          <td className="py-1 px-1 text-center border-r border-slate-200 font-medium">{row.tcTotal}</td>
                          <td className="py-1 px-1 text-center border-r border-slate-200 font-bold text-green-700">{row.creditsValides}</td>
                          <td className="py-1 px-1 text-center border-r border-slate-300 font-bold text-amber-700">{row.creditsRestants}</td>
                          <td className="py-1 px-1 text-center border-r border-slate-200 bg-blue-50/50 font-bold text-slate-900">
                            {row.mg !== null ? row.mg.toFixed(2) : '—'}
                          </td>
                          <td className="py-1 px-1 text-center border-r border-slate-200 font-bold text-blue-700">
                            {row.pourcentageGlobal !== null ? `${row.pourcentageGlobal}%` : '—'}
                          </td>
                          <td className="py-1 px-1 text-center border-r border-slate-200 font-bold text-slate-700">
                            {row.mg !== null ? `${idx + 1}e` : '—'}
                          </td>
                          <td className="py-1 px-1.5 text-center font-bold text-[9px]">
                            {row.mentionDecision === 'ADMIS' ? (
                              <span className="text-blue-700">ADMIS</span>
                            ) : (
                              <span className="text-red-700">AJOURNÉ</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {/* Statistiques et Signatures */}
              <div className="grid grid-cols-2 gap-4 pt-3 border-t border-slate-300 text-xs text-slate-700">
                <div className="space-y-1">
                  <p className="font-bold text-slate-900">Bilan de la Promotion :</p>
                  <p>• Effectif total : <strong>{recapitulatif.length} étudiants</strong> (Évalués : {stats.total})</p>
                  <p>• Taux de réussite : <strong className="text-green-700">{stats.tauxReussite}%</strong> ({stats.admis} Admis)</p>
                  <p>• Admis Validés (0 reste) : <strong>{stats.admisValide}</strong> | Admis Ajournés (avec restes) : <strong>{stats.admisAjoune}</strong> | Ajournés : <strong>{stats.ajournes}</strong> ({stats.tauxEchec}%)</p>
                  <p>• Moyenne générale de la classe : <strong>{stats.moyennePromo !== null ? `${stats.moyennePromo} / 20` : '—'}</strong></p>
                </div>
                <div className="text-right flex flex-col justify-between items-end">
                  <div>
                    <p className="font-bold text-slate-900">{appSettings.titreSignataire2 || "Le Directeur des Affaires Académiques (DAC)"}</p>
                    <p className="text-[10px] text-slate-500 italic mt-0.5">(Cachet et Signature autorisée)</p>
                  </div>
                  <div className="h-10 w-36 border-b border-dashed border-slate-400"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Toast */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-on-surface text-surface-container-lowest px-4 py-3 rounded-2xl shadow-xl flex items-center gap-2 text-xs font-medium">
          <CheckCircle2 className="w-4 h-4 text-green-400" />
          <span>{toastMessage}</span>
        </div>
      )}
    </div>
  );
};
