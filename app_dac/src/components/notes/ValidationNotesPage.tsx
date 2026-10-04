import React, { useState, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import {
  FileCheck2,
  Send,
  CheckCircle2,
  XCircle,
  Clock,
  AlertCircle,
  Eye,
  Search,
  School,
  User,
  Layers,
  Award,
  Check,
  X,
  AlertTriangle,
  FileSpreadsheet
} from 'lucide-react';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { SoumissionNotes, NoteProvisoire, TypeEpreuveSoumission, Matiere, Note, NotificationItem } from '../../types';
import { cn } from '../../lib/utils';
import {
  getSemestresForClasse,
  getDefaultSemestreForClasse,
  formatSemestreLabel
} from '../../utils/academicSemestres';

/** Calcul MG matière : DST×20% + DR×20% + Session×60% */
function computeMG(dst: number | null, dr: number | null, session: number | null): number | null {
  if (dst === null && dr === null && session === null) return null;
  const d = dst ?? 0;
  const r = dr ?? 0;
  const s = session ?? 0;
  return Number(((d * 0.20) + (r * 0.20) + (s * 0.60)).toFixed(2));
}

function computeMGFinale(
  dst: number | null, dr: number | null, session: number | null, rattrapage: number | null
): number | null {
  const mg = computeMG(dst, dr, session);
  if (mg === null && rattrapage === null) return null;
  if (rattrapage !== null && mg !== null) return rattrapage > mg ? rattrapage : mg;
  if (rattrapage !== null) return rattrapage;
  return mg;
}

export function ValidationNotesPage() {
  const currentUser = JSON.parse(localStorage.getItem('dac_user') || '{"id":"dac_default","nom":"Directeur Académique (DAC)","role":"DAC"}');

  const [activeTab, setActiveTab] = useState<'liste' | 'simulateur_prof'>('liste');
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatut, setFilterStatut] = useState<string>('all');
  const [filterClasse, setFilterClasse] = useState<string>('all');
  const [filterTypeEpreuve, setFilterTypeEpreuve] = useState<string>('all');

  // Modal d'examen et validation DAC
  const [selectedSoumission, setSelectedSoumission] = useState<SoumissionNotes | null>(null);
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [motifRejet, setMotifRejet] = useState('');
  const [isProcessing, setIsProcessing] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Formulaire Simulateur / Portail Enseignant
  const [formClasseId, setFormClasseId] = useState('');
  const [formMatiereId, setFormMatiereId] = useState('');
  const [formSemestre, setFormSemestre] = useState('S1');
  const [formEnseignantId, setFormEnseignantId] = useState('');
  const [formTypeEpreuve, setFormTypeEpreuve] = useState<TypeEpreuveSoumission>('DST');
  const [formCommentaire, setFormCommentaire] = useState('');
  const [inputNotes, setInputNotes] = useState<{
    [etudId: string]: { dst: string; dr: string; session: string; rattrapage: string }
  }>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Données Dexie en direct
  const soumissions = useLiveQuery(() => db.soumissions_notes.toArray()) || [];
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const classeEtudiants = useLiveQuery(() => db.classe_etudiants.toArray()) || [];
  const classeMatieres = useLiveQuery(() => db.classe_matieres.toArray()) || [];
  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];
  const existingNotes = useLiveQuery(() => db.notes.toArray()) || [];

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 4000);
  };

  // Enseignants filtrés
  const enseignants = useMemo(() => {
    return personnel.filter(p =>
      p.type_personnel?.toLowerCase() === 'enseignant' ||
      p.fonction?.toLowerCase().includes('enseignant') ||
      p.fonction?.toLowerCase().includes('prof')
    );
  }, [personnel]);

  // Classe sélectionnée dans le simulateur
  const currentSimClasse = useMemo(() => {
    return classes.find(c => c.id === formClasseId);
  }, [classes, formClasseId]);

  // Semestres autorisés pour cette classe
  const semestresDisponibles = useMemo(() => {
    return getSemestresForClasse(currentSimClasse);
  }, [currentSimClasse]);

  // Matières de la classe sélectionnée
  const matieresDeLaClasse = useMemo(() => {
    if (!formClasseId) return [];
    return classeMatieres
      .filter(cm => cm.classe_id === formClasseId)
      .map(cm => matieres.find(m => m.id === cm.matiere_id))
      .filter(Boolean) as Matiere[];
  }, [classeMatieres, formClasseId, matieres]);

  // Étudiants de la classe sélectionnée
  const etudiantsDeLaClasse = useMemo(() => {
    if (!formClasseId) return [];
    const ids = classeEtudiants.filter(ce => ce.classe_id === formClasseId).map(ce => ce.etudiant_id);
    return etudiants
      .filter(e => ids.includes(e.id))
      .sort((a, b) => a.nom.localeCompare(b.nom));
  }, [classeEtudiants, formClasseId, etudiants]);

  // Synchronisation automatique de l'enseignant et du semestre lors du choix de classe/matière
  const handleClasseSelect = (cId: string) => {
    setFormClasseId(cId);
    const cls = classes.find(c => c.id === cId);
    if (cls) {
      const defaultSem = getDefaultSemestreForClasse(cls);
      setFormSemestre(defaultSem);
    }
    setFormMatiereId('');
  };

  const handleMatiereSelect = (mId: string) => {
    setFormMatiereId(mId);
    if (formClasseId && mId) {
      const link = classeMatieres.find(cm => cm.classe_id === formClasseId && cm.matiere_id === mId);
      if (link && link.enseignant_id) {
        setFormEnseignantId(link.enseignant_id);
      }
    }
  };

  // Pré-remplissage des champs du simulateur avec les notes déjà connues s'il y en a
  const handleLoadExistingForSimulation = () => {
    if (!formClasseId || !formMatiereId || etudiantsDeLaClasse.length === 0) return;
    const initial: { [etudId: string]: { dst: string; dr: string; session: string; rattrapage: string } } = {};
    etudiantsDeLaClasse.forEach(e => {
      const noteKey = `note_${formClasseId}_${formMatiereId}_${e.id}_${formSemestre}`;
      const found = existingNotes.find(n => n.id === noteKey);
      initial[e.id] = {
        dst: found?.note_cc !== undefined ? String(found.note_cc) : '',
        dr: found?.note_examen !== undefined ? String(found.note_examen) : '',
        session: found?.note_finale !== undefined ? String(found.note_finale) : '',
        rattrapage: found?.note_rattrapage !== undefined ? String(found.note_rattrapage) : ''
      };
    });
    setInputNotes(initial);
    showToast("Notes déjà existantes chargées dans le simulateur.");
  };

  const handleNoteInputChange = (etudId: string, field: 'dst' | 'dr' | 'session' | 'rattrapage', val: string) => {
    if (val !== '') {
      const n = parseFloat(val);
      if (isNaN(n) || n < 0 || n > 20) return;
    }
    setInputNotes(prev => ({
      ...prev,
      [etudId]: {
        ...(prev[etudId] || { dst: '', dr: '', session: '', rattrapage: '' }),
        [field]: val
      }
    }));
  };

  // Envoi de la soumission par l'enseignant au DAC
  const handleSubmitByTeacher = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formClasseId || !formMatiereId || etudiantsDeLaClasse.length === 0) {
      alert("Veuillez sélectionner une classe, une matière et vous assurer que la classe contient des étudiants.");
      return;
    }

    setIsSubmitting(true);
    try {
      const targetClasse = classes.find(c => c.id === formClasseId);
      const targetMatiere = matieres.find(m => m.id === formMatiereId);
      const targetProf = personnel.find(p => p.id === formEnseignantId);
      const soumId = `soum_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;

      // Construire la liste des notes provisoires
      const notesProvList: NoteProvisoire[] = [];
      let totalMG = 0;
      let countWithMG = 0;

      etudiantsDeLaClasse.forEach(etud => {
        const inp = inputNotes[etud.id] || { dst: '', dr: '', session: '', rattrapage: '' };
        const dst = inp.dst !== '' ? parseFloat(inp.dst) : undefined;
        const dr = inp.dr !== '' ? parseFloat(inp.dr) : undefined;
        const session = inp.session !== '' ? parseFloat(inp.session) : undefined;
        const rattrapage = inp.rattrapage !== '' ? parseFloat(inp.rattrapage) : undefined;

        const mgEstimee = computeMGFinale(dst ?? null, dr ?? null, session ?? null, rattrapage ?? null) ?? undefined;
        if (mgEstimee !== undefined) {
          totalMG += mgEstimee;
          countWithMG++;
        }

        notesProvList.push({
          id: `np_${soumId}_${etud.id}`,
          soumission_id: soumId,
          classe_id: formClasseId,
          matiere_id: formMatiereId,
          etudiant_id: etud.id,
          matricule: etud.matricule,
          nom: etud.nom,
          prenom: etud.prenom,
          note_cc: dst,
          note_examen: dr,
          note_finale: session,
          note_rattrapage: rattrapage,
          mg_estimee: mgEstimee,
          semestre: formSemestre,
          annee_academique: targetClasse?.annee_academique || '2025-2026'
        });
      });

      const moyenneClasse = countWithMG > 0 ? Number((totalMG / countWithMG).toFixed(2)) : undefined;

      const newSoumission: SoumissionNotes = {
        id: soumId,
        classe_id: formClasseId,
        classe_nom: targetClasse?.nom || 'Classe',
        matiere_id: formMatiereId,
        matiere_nom: targetMatiere?.nom || 'Matière',
        matiere_code: targetMatiere?.code || '',
        enseignant_id: formEnseignantId,
        enseignant_nom: targetProf ? `${targetProf.nom} ${targetProf.prenom}` : (currentUser?.nom || 'Professeur Titulaire'),
        semestre: formSemestre,
        annee_academique: targetClasse?.annee_academique || '2025-2026',
        type_epreuve: formTypeEpreuve,
        statut: 'EN_ATTENTE',
        nombre_etudiants: etudiantsDeLaClasse.length,
        moyenne_classe: moyenneClasse,
        commentaires_enseignant: formCommentaire,
        notes: notesProvList,
        date_soumission: new Date().toISOString(),
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString()
      };

      // Sauvegarde Dexie
      await db.soumissions_notes.put(newSoumission);
      if (notesProvList.length > 0) {
        await db.notes_provisoires.bulkPut(notesProvList);
      }

      // Synchronisation Supabase en arrière-plan
      supabase.from('soumissions_notes').upsert([newSoumission]).then(() => {}, () => {});
      supabase.from('notes_provisoires').upsert(notesProvList).then(() => {}, () => {});

      // Création notification pour le DAC
      const notifDAC: NotificationItem = {
        id: `notif_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
        user_id: 'dac_default',
        type: 'notes_soumises',
        titre: `Nouveau relevé de notes reçu : ${targetClasse?.nom}`,
        description: `Le Professeur ${newSoumission.enseignant_nom} a transmis les notes de ${targetMatiere?.nom} (${formTypeEpreuve}) pour le ${formatSemestreLabel(formSemestre)}.`,
        auteur_nom: newSoumission.enseignant_nom,
        auteur_role: 'Enseignant',
        lien_tab: 'validation_notes',
        lien_id: soumId,
        lu: false,
        created_at: new Date().toISOString()
      };
      await db.notifications.put(notifDAC);
      supabase.from('notifications').insert([notifDAC]).then(() => {}, () => {});

      await logAction('Transmission Notes', 'Notes',
        `Relevé transmis par ${newSoumission.enseignant_nom} — ${targetClasse?.nom} • ${targetMatiere?.nom} (${formTypeEpreuve})`
      );

      showToast(`Relevé de notes (${formTypeEpreuve}) envoyé avec succès au DAC pour validation !`);
      setActiveTab('liste');
    } catch (err: any) {
      alert("Erreur lors de l'envoi : " + err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  // Validation DAC : injection dans les notes officielles
  const handleValidateByDAC = async (soumission: SoumissionNotes) => {
    if (!window.confirm(`Confirmez-vous la validation et la publication officielle de ce relevé (${soumission.type_epreuve}) pour la classe ${soumission.classe_nom} ?`)) {
      return;
    }

    setIsProcessing(true);
    try {
      const notesToUpdate: Note[] = [];
      const updatedNotesList = [...soumission.notes];

      for (const np of updatedNotesList) {
        const officialId = `note_${soumission.classe_id}_${soumission.matiere_id}_${np.etudiant_id}_${soumission.semestre}`;
        const existing = await db.notes.get(officialId);

        // Fusion intelligente selon le type d'épreuve transmis
        let newCC = existing?.note_cc;
        let newExamen = existing?.note_examen;
        let newFinale = existing?.note_finale ?? 0;
        let newRattrapage = existing?.note_rattrapage;

        if (soumission.type_epreuve === 'DST') {
          if (np.note_cc !== undefined) newCC = np.note_cc;
        } else if (soumission.type_epreuve === 'DEVOIR_RECHERCHE') {
          if (np.note_examen !== undefined) newExamen = np.note_examen;
        } else if (soumission.type_epreuve === 'SESSION') {
          if (np.note_finale !== undefined) newFinale = np.note_finale;
        } else if (soumission.type_epreuve === 'RATTRAPAGE') {
          if (np.note_rattrapage !== undefined) newRattrapage = np.note_rattrapage;
        } else {
          // 'TOUTES'
          if (np.note_cc !== undefined) newCC = np.note_cc;
          if (np.note_examen !== undefined) newExamen = np.note_examen;
          if (np.note_finale !== undefined) newFinale = np.note_finale;
          if (np.note_rattrapage !== undefined) newRattrapage = np.note_rattrapage;
        }

        const mgFinale = computeMGFinale(newCC ?? null, newExamen ?? null, newFinale ?? null, newRattrapage ?? null);

        notesToUpdate.push({
          id: officialId,
          classe_id: soumission.classe_id,
          matiere_id: soumission.matiere_id,
          etudiant_id: np.etudiant_id,
          note_cc: newCC,
          note_examen: newExamen,
          note_finale: newFinale,
          note_rattrapage: newRattrapage,
          semestre: soumission.semestre,
          annee_academique: soumission.annee_academique,
          saisi_par: `${soumission.enseignant_nom} (Validé DAC)`,
          observations: mgFinale !== null ? String(mgFinale) : undefined,
          updated_at: new Date().toISOString()
        });
      }

      // 1. Écrire dans db.notes
      await db.notes.bulkPut(notesToUpdate);
      supabase.from('notes').upsert(notesToUpdate).then(() => {}, () => {});

      // 2. Mettre à jour la soumission
      const updatedSoumission: SoumissionNotes = {
        ...soumission,
        statut: 'VALIDE',
        date_validation: new Date().toISOString(),
        valide_par: currentUser?.nom || 'DAC',
        updated_at: new Date().toISOString()
      };
      await db.soumissions_notes.put(updatedSoumission);
      supabase.from('soumissions_notes').upsert([updatedSoumission]).then(() => {}, () => {});

      // 3. Notification à l'enseignant
      if (soumission.enseignant_id) {
        const notifProf: NotificationItem = {
          id: `notif_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
          user_id: soumission.enseignant_id,
          type: 'notes_validees',
          titre: `Notes Validées : ${soumission.classe_nom}`,
          description: `Vos notes de ${soumission.matiere_nom} (${soumission.type_epreuve}) ont été validées et publiées officiellement par le DAC.`,
          auteur_nom: currentUser?.nom || 'DAC ISGI',
          auteur_role: 'DAC',
          lien_tab: 'notes',
          lien_id: soumission.id,
          lu: false,
          created_at: new Date().toISOString()
        };
        await db.notifications.put(notifProf);
        supabase.from('notifications').insert([notifProf]).then(() => {}, () => {});
      }

      await logAction('Validation Notes', 'Notes',
        `Validation bordereau (${soumission.type_epreuve}) — ${soumission.classe_nom} • ${soumission.matiere_nom} par le DAC`
      );

      setSelectedSoumission(null);
      showToast(`Relevé validé avec succès ! ${notesToUpdate.length} note(s) ont été injectées dans le registre officiel.`);
    } catch (err: any) {
      alert("Erreur lors de la validation : " + err.message);
    } finally {
      setIsProcessing(false);
    }
  };

  // Rejet avec demande de correction
  const handleRejectByDAC = async () => {
    if (!selectedSoumission) return;
    if (!motifRejet.trim()) {
      alert("Veuillez indiquer le motif du rejet ou les corrections demandées à l'enseignant.");
      return;
    }

    setIsProcessing(true);
    try {
      const updatedSoumission: SoumissionNotes = {
        ...selectedSoumission,
        statut: 'REJETE',
        remarques_dac: motifRejet.trim(),
        updated_at: new Date().toISOString()
      };

      await db.soumissions_notes.put(updatedSoumission);
      supabase.from('soumissions_notes').upsert([updatedSoumission]).then(() => {}, () => {});

      // Notification à l'enseignant
      if (selectedSoumission.enseignant_id) {
        const notifProf: NotificationItem = {
          id: `notif_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
          user_id: selectedSoumission.enseignant_id,
          type: 'notes_rejetees',
          titre: `Correction demandée sur les notes : ${selectedSoumission.classe_nom}`,
          description: `Le DAC a demandé une révision de votre bordereau de ${selectedSoumission.matiere_nom} : "${motifRejet.trim()}".`,
          auteur_nom: currentUser?.nom || 'DAC ISGI',
          auteur_role: 'DAC',
          lien_tab: 'notes',
          lien_id: selectedSoumission.id,
          lu: false,
          created_at: new Date().toISOString()
        };
        await db.notifications.put(notifProf);
        supabase.from('notifications').insert([notifProf]).then(() => {}, () => {});
      }

      await logAction('Rejet Notes', 'Notes',
        `Demande de correction relevé — ${selectedSoumission.classe_nom} • ${selectedSoumission.matiere_nom} : ${motifRejet.trim()}`
      );

      setShowRejectModal(false);
      setSelectedSoumission(null);
      setMotifRejet('');
      showToast("Bordereau renvoyé à l'enseignant avec vos instructions de correction.");
    } catch (err: any) {
      alert("Erreur : " + err.message);
    } finally {
      setIsProcessing(false);
    }
  };

  // Filtrage des bordereaux reçus
  const filteredSoumissions = useMemo(() => {
    return soumissions.filter(s => {
      const matchSearch =
        s.classe_nom.toLowerCase().includes(searchTerm.toLowerCase()) ||
        s.matiere_nom.toLowerCase().includes(searchTerm.toLowerCase()) ||
        s.enseignant_nom.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (s.matiere_code && s.matiere_code.toLowerCase().includes(searchTerm.toLowerCase()));

      const matchStatut = filterStatut === 'all' || s.statut === filterStatut;
      const matchClasse = filterClasse === 'all' || s.classe_id === filterClasse;
      const matchType = filterTypeEpreuve === 'all' || s.type_epreuve === filterTypeEpreuve;

      return matchSearch && matchStatut && matchClasse && matchType;
    }).sort((a, b) => new Date(b.date_soumission).getTime() - new Date(a.date_soumission).getTime());
  }, [soumissions, searchTerm, filterStatut, filterClasse, filterTypeEpreuve]);

  // Statistiques KPIs
  const stats = useMemo(() => {
    return {
      total: soumissions.length,
      enAttente: soumissions.filter(s => s.statut === 'EN_ATTENTE').length,
      valides: soumissions.filter(s => s.statut === 'VALIDE').length,
      rejetes: soumissions.filter(s => s.statut === 'REJETE').length
    };
  }, [soumissions]);

  return (
    <div className="space-y-6">
      {/* En-tête de la page */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <div className="flex items-center gap-2.5">
            <div className="p-2 rounded-xl bg-primary/10 text-primary">
              <FileCheck2 className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-2xl font-bold text-on-surface">Transmission &amp; Validation des Notes</h2>
              <p className="text-xs text-on-surface-variant">
                Workflow officiel Enseignants ➔ DAC : dépôt progressif par épreuve (DST, Devoir de Recherche, Session) et validation officielle.
              </p>
            </div>
          </div>
        </div>

        {/* Navigation par Onglets */}
        <div className="flex items-center bg-surface-container-high p-1 rounded-xl border border-outline-variant text-xs">
          <button
            onClick={() => setActiveTab('liste')}
            className={cn(
              "flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold transition-all",
              activeTab === 'liste'
                ? "bg-primary text-on-primary shadow-xs"
                : "text-on-surface-variant hover:text-on-surface"
            )}
          >
            <Layers className="w-4 h-4" />
            <span>Bordereaux Reçus par le DAC</span>
            {stats.enAttente > 0 && (
              <span className="px-1.5 py-0.2 text-[10px] rounded-full bg-amber-500 text-white font-bold animate-pulse">
                {stats.enAttente}
              </span>
            )}
          </button>
          <button
            onClick={() => setActiveTab('simulateur_prof')}
            className={cn(
              "flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold transition-all",
              activeTab === 'simulateur_prof'
                ? "bg-primary text-on-primary shadow-xs"
                : "text-on-surface-variant hover:text-on-surface"
            )}
          >
            <Send className="w-4 h-4" />
            <span>Portail Saisie Enseignant (Dépôt)</span>
          </button>
        </div>
      </div>

      {/* Cartes d'Indicateurs KPIs */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant flex items-center gap-3">
          <div className="p-3 rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400">
            <FileSpreadsheet className="w-5 h-5" />
          </div>
          <div>
            <p className="text-2xl font-black text-on-surface">{stats.total}</p>
            <p className="text-xs text-on-surface-variant">Total Relevés Transmis</p>
          </div>
        </div>

        <div className="bg-amber-50/60 dark:bg-amber-950/20 rounded-2xl p-4 border border-amber-200 dark:border-amber-800/40 flex items-center gap-3">
          <div className="p-3 rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
            <Clock className="w-5 h-5" />
          </div>
          <div>
            <p className="text-2xl font-black text-amber-700 dark:text-amber-400">{stats.enAttente}</p>
            <p className="text-xs text-amber-800/80 dark:text-amber-300">À Valider par le DAC</p>
          </div>
        </div>

        <div className="bg-green-50/60 dark:bg-green-950/20 rounded-2xl p-4 border border-green-200 dark:border-green-800/40 flex items-center gap-3">
          <div className="p-3 rounded-xl bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">
            <CheckCircle2 className="w-5 h-5" />
          </div>
          <div>
            <p className="text-2xl font-black text-green-700 dark:text-green-400">{stats.valides}</p>
            <p className="text-xs text-green-800/80 dark:text-green-300">Validés &amp; Injectés</p>
          </div>
        </div>

        <div className="bg-red-50/60 dark:bg-red-950/20 rounded-2xl p-4 border border-red-200 dark:border-red-800/40 flex items-center gap-3">
          <div className="p-3 rounded-xl bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300">
            <AlertCircle className="w-5 h-5" />
          </div>
          <div>
            <p className="text-2xl font-black text-red-700 dark:text-red-400">{stats.rejetes}</p>
            <p className="text-xs text-red-800/80 dark:text-red-300">En Correction Prof</p>
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* ONGLET 1 : LISTE DES BORDEREAUX REÇUS PAR LE DAC */}
      {/* ========================================================================= */}
      {activeTab === 'liste' && (
        <div className="space-y-4">
          {/* Barre de filtres et recherche */}
          <div className="bg-surface-container rounded-2xl p-4 border border-outline-variant flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2 flex-1 min-w-[240px]">
              <div className="relative flex-1">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
                <input
                  type="text"
                  placeholder="Rechercher classe, matière, enseignant..."
                  value={searchTerm}
                  onChange={e => setSearchTerm(e.target.value)}
                  className="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl bg-surface-container-highest text-on-surface border border-outline-variant outline-none focus:ring-2 focus:ring-primary"
                />
              </div>
            </div>

            <div className="flex items-center gap-2 flex-wrap text-xs">
              <select
                value={filterStatut}
                onChange={e => setFilterStatut(e.target.value)}
                className="px-3 py-1.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface outline-none"
              >
                <option value="all">Tous les statuts</option>
                <option value="EN_ATTENTE">En attente de validation</option>
                <option value="VALIDE">Validés</option>
                <option value="REJETE">À corriger</option>
              </select>

              <select
                value={filterTypeEpreuve}
                onChange={e => setFilterTypeEpreuve(e.target.value)}
                className="px-3 py-1.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface outline-none"
              >
                <option value="all">Toutes les épreuves</option>
                <option value="DST">DST uniquement (20%)</option>
                <option value="DEVOIR_RECHERCHE">Devoir de Recherche (20%)</option>
                <option value="SESSION">Session d'Examen (60%)</option>
                <option value="RATTRAPAGE">Rattrapage</option>
                <option value="TOUTES">Bordereau Complet</option>
              </select>

              <select
                value={filterClasse}
                onChange={e => setFilterClasse(e.target.value)}
                className="px-3 py-1.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface outline-none max-w-[160px]"
              >
                <option value="all">Toutes les classes</option>
                {classes.map(c => (
                  <option key={c.id} value={c.id}>{c.nom}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Tableau des bordereaux */}
          <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                    <th className="py-3 px-3">Statut</th>
                    <th className="py-3 px-3">Classe &amp; Semestre</th>
                    <th className="py-3 px-3">Matière</th>
                    <th className="py-3 px-3">Épreuve Transmise</th>
                    <th className="py-3 px-3">Enseignant</th>
                    <th className="py-3 px-2 text-center">Élèves</th>
                    <th className="py-3 px-2 text-center">Moy. Classe</th>
                    <th className="py-3 px-3">Date d'Envoi</th>
                    <th className="py-3 px-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-outline-variant bg-surface-container-lowest">
                  {filteredSoumissions.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="py-12 text-center text-on-surface-variant">
                        <FileCheck2 className="w-10 h-10 mx-auto opacity-30 mb-2" />
                        <p className="font-semibold text-sm">Aucun bordereau de notes reçu</p>
                        <p className="text-xs text-on-surface-variant/70 mt-1">
                          Les professeurs peuvent transmettre leurs notes par épreuve via le portail de saisie.
                        </p>
                      </td>
                    </tr>
                  ) : (
                    filteredSoumissions.map(s => {
                      const badgeStatut =
                        s.statut === 'VALIDE' ? 'bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300' :
                        s.statut === 'REJETE' ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' :
                        'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 animate-pulse';

                      const labelStatut =
                        s.statut === 'VALIDE' ? 'VALIDÉ & PUBLIÉ' :
                        s.statut === 'REJETE' ? 'À CORRIGER' : 'EN ATTENTE DAC';

                      const badgeEpreuve =
                        s.type_epreuve === 'DST' ? 'bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-300' :
                        s.type_epreuve === 'DEVOIR_RECHERCHE' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-300' :
                        s.type_epreuve === 'SESSION' ? 'bg-blue-100 text-blue-800 dark:bg-blue-950/50 dark:text-blue-300' :
                        s.type_epreuve === 'RATTRAPAGE' ? 'bg-orange-100 text-orange-800 dark:bg-orange-950/50 dark:text-orange-300' :
                        'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200';

                      return (
                        <tr key={s.id} className="hover:bg-surface-container-high/40 transition-colors">
                          <td className="py-3 px-3">
                            <span className={cn("px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide inline-block", badgeStatut)}>
                              {labelStatut}
                            </span>
                          </td>
                          <td className="py-3 px-3">
                            <p className="font-bold text-on-surface">{s.classe_nom}</p>
                            <span className="text-[10px] font-semibold text-primary font-mono">{formatSemestreLabel(s.semestre)}</span>
                          </td>
                          <td className="py-3 px-3">
                            <p className="font-bold text-on-surface">{s.matiere_nom}</p>
                            <span className="text-[10px] font-mono text-on-surface-variant">[{s.matiere_code || 'UE'}]</span>
                          </td>
                          <td className="py-3 px-3">
                            <span className={cn("px-2 py-0.5 rounded-md text-[10px] font-bold", badgeEpreuve)}>
                              {s.type_epreuve === 'DST' ? 'DST (20%)' :
                               s.type_epreuve === 'DEVOIR_RECHERCHE' ? 'Devoir Rech (20%)' :
                               s.type_epreuve === 'SESSION' ? 'Session (60%)' :
                               s.type_epreuve === 'RATTRAPAGE' ? 'Rattrapage' : 'Bordereau Complet'}
                            </span>
                          </td>
                          <td className="py-3 px-3 font-medium text-on-surface">
                            {s.enseignant_nom}
                          </td>
                          <td className="py-3 px-2 text-center font-bold text-on-surface">
                            {s.nombre_etudiants}
                          </td>
                          <td className="py-3 px-2 text-center font-bold text-blue-600 dark:text-blue-400">
                            {s.moyenne_classe !== undefined ? `${s.moyenne_classe}/20` : '—'}
                          </td>
                          <td className="py-3 px-3 text-on-surface-variant text-[11px]">
                            {new Date(s.date_soumission).toLocaleDateString('fr-FR', {
                              day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
                            })}
                          </td>
                          <td className="py-3 px-3 text-right">
                            <button
                              onClick={() => setSelectedSoumission(s)}
                              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-on-primary font-semibold text-xs hover:bg-primary/90 transition-colors shadow-xs"
                            >
                              <Eye className="w-3.5 h-3.5" />
                              <span>{s.statut === 'EN_ATTENTE' ? 'Examiner & Valider' : 'Consulter Notes'}</span>
                            </button>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* ONGLET 2 : PORTAIL / SIMULATEUR DE SAISIE ENSEIGNANT */}
      {/* ========================================================================= */}
      {activeTab === 'simulateur_prof' && (
        <form onSubmit={handleSubmitByTeacher} className="space-y-6">
          {/* Card Sélection Classe / Matière / Épreuve */}
          <div className="bg-surface-container rounded-2xl p-5 border border-outline-variant space-y-4 shadow-xs">
            <div className="flex items-center justify-between border-b border-outline-variant pb-3 flex-wrap gap-2">
              <div className="flex items-center gap-2 text-primary font-bold text-sm">
                <School className="w-4 h-4" />
                <span>1. Identification de l'Évaluation à Transmettre</span>
              </div>
              <button
                type="button"
                onClick={handleLoadExistingForSimulation}
                disabled={!formClasseId || !formMatiereId}
                className="text-xs font-semibold px-3 py-1.5 rounded-xl bg-secondary-container text-on-secondary-container hover:bg-secondary-container/80 transition-colors disabled:opacity-50"
              >
                Pré-charger les notes déjà enregistrées
              </button>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
              {/* Classe */}
              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Classe cible *</label>
                <select
                  required
                  value={formClasseId}
                  onChange={e => handleClasseSelect(e.target.value)}
                  className="w-full p-2.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface font-medium outline-none focus:ring-2 focus:ring-primary"
                >
                  <option value="">Sélectionner une classe</option>
                  {classes.map(c => (
                    <option key={c.id} value={c.id}>{c.nom} ({c.filiere || 'Générale'})</option>
                  ))}
                </select>
              </div>

              {/* Matière */}
              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Matière enseignée *</label>
                <select
                  required
                  disabled={!formClasseId}
                  value={formMatiereId}
                  onChange={e => handleMatiereSelect(e.target.value)}
                  className="w-full p-2.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface font-medium outline-none focus:ring-2 focus:ring-primary disabled:opacity-50"
                >
                  <option value="">Sélectionner la matière</option>
                  {matieresDeLaClasse.map(m => (
                    <option key={m.id} value={m.id}>[{m.code}] {m.nom} ({m.credits || 1} crd)</option>
                  ))}
                </select>
              </div>

              {/* Semestre */}
              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Semestre *</label>
                <select
                  required
                  value={formSemestre}
                  onChange={e => setFormSemestre(e.target.value)}
                  className="w-full p-2.5 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface font-medium outline-none focus:ring-2 focus:ring-primary"
                >
                  {semestresDisponibles.map(s => (
                    <option key={s.value} value={s.value}>{s.label}</option>
                  ))}
                </select>
              </div>

              {/* Type d'épreuve à transmettre */}
              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Épreuve transmise cette fois-ci *</label>
                <select
                  value={formTypeEpreuve}
                  onChange={e => setFormTypeEpreuve(e.target.value as TypeEpreuveSoumission)}
                  className="w-full p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/40 border border-blue-300 dark:border-blue-700 text-blue-900 dark:text-blue-200 font-bold outline-none focus:ring-2 focus:ring-primary"
                >
                  <option value="DST">DST uniquement (20%)</option>
                  <option value="DEVOIR_RECHERCHE">Devoir de Recherche uniquement (20%)</option>
                  <option value="SESSION">Session d'Examen uniquement (60%)</option>
                  <option value="RATTRAPAGE">Rattrapage uniquement</option>
                  <option value="TOUTES">Bordereau Complet (Toutes les notes)</option>
                </select>
              </div>
            </div>

            {/* Enseignant & Commentaire */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-2">
              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Professeur responsable</label>
                <select
                  value={formEnseignantId}
                  onChange={e => setFormEnseignantId(e.target.value)}
                  className="w-full p-2 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface font-medium outline-none"
                >
                  <option value="">Sélectionner ou laisser l'enseignant titulaire</option>
                  {enseignants.map(p => (
                    <option key={p.id} value={p.id}>{p.nom} {p.prenom} ({p.fonction || 'Enseignant'})</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-on-surface-variant font-semibold mb-1">Commentaire / Observation pour le DAC</label>
                <input
                  type="text"
                  placeholder="Ex : Notes du DST 1 suite au rattrapage du sujet 2..."
                  value={formCommentaire}
                  onChange={e => setFormCommentaire(e.target.value)}
                  className="w-full p-2 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface outline-none"
                />
              </div>
            </div>
          </div>

          {/* Grille de Saisie des Notes des Étudiants */}
          <div className="bg-surface-container rounded-2xl border border-outline-variant overflow-hidden shadow-xs">
            <div className="px-5 py-3 border-b border-outline-variant flex justify-between items-center bg-surface-container-high/50 flex-wrap gap-2">
              <div className="flex items-center gap-2">
                <Award className="w-4 h-4 text-primary" />
                <span className="font-bold text-sm text-on-surface">2. Bordereau de Notes des Étudiants</span>
                {formClasseId && (
                  <span className="text-xs text-on-surface-variant font-medium">({etudiantsDeLaClasse.length} élève(s))</span>
                )}
              </div>
              <div className="text-xs font-semibold text-primary">
                Formule : <span className="font-mono">MG = DST×20% + DR×20% + Session×60%</span>
              </div>
            </div>

            {!formClasseId ? (
              <div className="py-12 text-center text-on-surface-variant">
                <School className="w-10 h-10 mx-auto opacity-30 mb-2" />
                <p className="font-semibold text-sm">Veuillez d'abord sélectionner une classe ci-dessus.</p>
              </div>
            ) : etudiantsDeLaClasse.length === 0 ? (
              <div className="py-12 text-center text-on-surface-variant">
                <User className="w-10 h-10 mx-auto opacity-30 mb-2" />
                <p className="font-semibold text-sm">Aucun étudiant n'est inscrit dans cette classe.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                      <th className="py-2.5 px-3 text-center w-10">N°</th>
                      <th className="py-2.5 px-3">Matricule</th>
                      <th className="py-2.5 px-3">Nom &amp; Prénom</th>
                      <th className={cn(
                        "py-2.5 px-2 text-center",
                        formTypeEpreuve === 'DST' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/10 text-primary font-bold' : ''
                      )}>
                        DST /20 (20%)
                      </th>
                      <th className={cn(
                        "py-2.5 px-2 text-center",
                        formTypeEpreuve === 'DEVOIR_RECHERCHE' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/10 text-primary font-bold' : ''
                      )}>
                        Dev. Rech /20 (20%)
                      </th>
                      <th className={cn(
                        "py-2.5 px-2 text-center",
                        formTypeEpreuve === 'SESSION' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/10 text-primary font-bold' : ''
                      )}>
                        Session /20 (60%)
                      </th>
                      <th className={cn(
                        "py-2.5 px-2 text-center",
                        formTypeEpreuve === 'RATTRAPAGE' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/10 text-primary font-bold' : ''
                      )}>
                        Rattrapage /20
                      </th>
                      <th className="py-2.5 px-3 text-center bg-blue-50 dark:bg-blue-950/40 font-bold text-blue-900 dark:text-blue-200">
                        MG Finale /20
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-outline-variant bg-surface-container-lowest">
                    {etudiantsDeLaClasse.map((etud, idx) => {
                      const inp = inputNotes[etud.id] || { dst: '', dr: '', session: '', rattrapage: '' };
                      const dstVal = inp.dst !== '' ? parseFloat(inp.dst) : null;
                      const drVal = inp.dr !== '' ? parseFloat(inp.dr) : null;
                      const sessVal = inp.session !== '' ? parseFloat(inp.session) : null;
                      const ratVal = inp.rattrapage !== '' ? parseFloat(inp.rattrapage) : null;

                      const mgEstimee = computeMGFinale(dstVal, drVal, sessVal, ratVal);

                      return (
                        <tr key={etud.id} className="hover:bg-surface-container-high/40 transition-colors">
                          <td className="py-2 px-3 text-center text-slate-500 font-bold">{idx + 1}</td>
                          <td className="py-2 px-3 font-mono text-[11px] text-on-surface-variant">{etud.matricule}</td>
                          <td className="py-2 px-3 font-bold text-on-surface">
                            {etud.nom.toUpperCase()} {etud.prenom}
                          </td>

                          {/* DST */}
                          <td className={cn(
                            "py-1.5 px-2 text-center",
                            formTypeEpreuve === 'DST' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/5' : ''
                          )}>
                            <input
                              type="number"
                              step="0.25"
                              min="0"
                              max="20"
                              placeholder="—"
                              value={inp.dst}
                              onChange={e => handleNoteInputChange(etud.id, 'dst', e.target.value)}
                              className="w-16 text-center py-1 font-semibold rounded-lg border border-outline-variant bg-surface-container text-on-surface outline-none focus:ring-2 focus:ring-primary text-xs"
                            />
                          </td>

                          {/* Dev. Recherche */}
                          <td className={cn(
                            "py-1.5 px-2 text-center",
                            formTypeEpreuve === 'DEVOIR_RECHERCHE' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/5' : ''
                          )}>
                            <input
                              type="number"
                              step="0.25"
                              min="0"
                              max="20"
                              placeholder="—"
                              value={inp.dr}
                              onChange={e => handleNoteInputChange(etud.id, 'dr', e.target.value)}
                              className="w-16 text-center py-1 font-semibold rounded-lg border border-outline-variant bg-surface-container text-on-surface outline-none focus:ring-2 focus:ring-primary text-xs"
                            />
                          </td>

                          {/* Session */}
                          <td className={cn(
                            "py-1.5 px-2 text-center",
                            formTypeEpreuve === 'SESSION' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/5' : ''
                          )}>
                            <input
                              type="number"
                              step="0.25"
                              min="0"
                              max="20"
                              placeholder="—"
                              value={inp.session}
                              onChange={e => handleNoteInputChange(etud.id, 'session', e.target.value)}
                              className="w-16 text-center py-1 font-semibold rounded-lg border border-outline-variant bg-surface-container text-on-surface outline-none focus:ring-2 focus:ring-primary text-xs"
                            />
                          </td>

                          {/* Rattrapage */}
                          <td className={cn(
                            "py-1.5 px-2 text-center",
                            formTypeEpreuve === 'RATTRAPAGE' || formTypeEpreuve === 'TOUTES' ? 'bg-primary/5' : ''
                          )}>
                            <input
                              type="number"
                              step="0.25"
                              min="0"
                              max="20"
                              placeholder="—"
                              value={inp.rattrapage}
                              onChange={e => handleNoteInputChange(etud.id, 'rattrapage', e.target.value)}
                              className="w-16 text-center py-1 font-semibold rounded-lg border border-outline-variant bg-surface-container text-on-surface outline-none focus:ring-2 focus:ring-primary text-xs"
                            />
                          </td>

                          {/* MG Estimée */}
                          <td className="py-2 px-3 text-center bg-blue-50/50 dark:bg-blue-950/20 font-bold">
                            {mgEstimee !== null ? (
                              <span className={mgEstimee >= 10 ? 'text-green-600 dark:text-green-400' : 'text-red-600'}>
                                {mgEstimee.toFixed(2)}
                              </span>
                            ) : (
                              <span className="text-slate-400">—</span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            {/* Footer action d'envoi */}
            {formClasseId && etudiantsDeLaClasse.length > 0 && (
              <div className="p-4 border-t border-outline-variant bg-surface-container flex justify-between items-center flex-wrap gap-3">
                <p className="text-xs text-on-surface-variant">
                  En cliquant sur « Envoyer chez le DAC », ces notes seront placées dans la table provisoire en attente d'approbation.
                </p>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary text-on-primary font-bold text-sm hover:bg-primary/90 transition-all shadow-md disabled:opacity-50"
                >
                  <Send className="w-4 h-4" />
                  <span>{isSubmitting ? 'Transmission en cours...' : 'Envoyer le Relevé chez le DAC'}</span>
                </button>
              </div>
            )}
          </div>
        </form>
      )}

      {/* ========================================================================= */}
      {/* MODALE D'EXAMEN & VALIDATION DAC */}
      {/* ========================================================================= */}
      {selectedSoumission && (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center z-50 p-4">
          <div className="bg-surface-container-lowest text-on-surface rounded-2xl max-w-5xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-outline-variant overflow-hidden">
            {/* Header Modale */}
            <div className="px-6 py-4 border-b border-outline-variant flex justify-between items-center bg-surface-container">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-xs font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">
                    Bordereau : {selectedSoumission.type_epreuve}
                  </span>
                  <span className="text-xs text-on-surface-variant">
                    {formatSemestreLabel(selectedSoumission.semestre)}
                  </span>
                </div>
                <h3 className="font-bold text-base text-on-surface mt-0.5">
                  {selectedSoumission.classe_nom} • {selectedSoumission.matiere_nom}
                </h3>
              </div>
              <button
                onClick={() => setSelectedSoumission(null)}
                className="p-2 rounded-xl hover:bg-surface-container-high text-on-surface-variant transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Corps de la Modale */}
            <div className="overflow-y-auto p-6 space-y-4 flex-1 custom-scrollbar">
              {/* Infos récapitulatives */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-surface-container p-3.5 rounded-xl text-xs">
                <div><span className="text-on-surface-variant block">Enseignant :</span> <strong>{selectedSoumission.enseignant_nom}</strong></div>
                <div><span className="text-on-surface-variant block">Épreuve transmise :</span> <strong className="text-primary">{selectedSoumission.type_epreuve}</strong></div>
                <div><span className="text-on-surface-variant block">Nombre d'élèves :</span> <strong>{selectedSoumission.nombre_etudiants}</strong></div>
                <div><span className="text-on-surface-variant block">Date d'envoi :</span> <strong>{new Date(selectedSoumission.date_soumission).toLocaleDateString('fr-FR')}</strong></div>
              </div>

              {selectedSoumission.commentaires_enseignant && (
                <div className="p-3 rounded-xl bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 text-xs text-blue-900 dark:text-blue-200">
                  <span className="font-bold block mb-0.5">Message de l'enseignant :</span>
                  {selectedSoumission.commentaires_enseignant}
                </div>
              )}

              {selectedSoumission.remarques_dac && (
                <div className="p-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 text-xs text-red-900 dark:text-red-200">
                  <span className="font-bold block mb-0.5">Remarques de correction du DAC :</span>
                  {selectedSoumission.remarques_dac}
                </div>
              )}

              {/* Tableau des notes soumises */}
              <div className="border border-outline-variant rounded-xl overflow-hidden">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-surface-container-highest text-on-surface-variant uppercase font-semibold border-b border-outline-variant">
                      <th className="py-2.5 px-3 w-10 text-center">N°</th>
                      <th className="py-2.5 px-3">Matricule</th>
                      <th className="py-2.5 px-3">Nom &amp; Prénom</th>
                      <th className="py-2.5 px-2 text-center">DST /20 (20%)</th>
                      <th className="py-2.5 px-2 text-center">Dev. Rech /20 (20%)</th>
                      <th className="py-2.5 px-2 text-center">Session /20 (60%)</th>
                      <th className="py-2.5 px-2 text-center">Rattrapage /20</th>
                      <th className="py-2.5 px-3 text-center bg-primary/10 font-bold">Moyenne Finale</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-outline-variant bg-surface-container-lowest">
                    {selectedSoumission.notes.map((np, idx) => (
                      <tr key={np.id} className="hover:bg-surface-container-high/40">
                        <td className="py-2 px-3 text-center text-slate-500 font-bold">{idx + 1}</td>
                        <td className="py-2 px-3 font-mono text-[11px] text-on-surface-variant">{np.matricule}</td>
                        <td className="py-2 px-3 font-bold text-on-surface">
                          {np.nom.toUpperCase()} {np.prenom}
                        </td>
                        <td className="py-2 px-2 text-center font-semibold">
                          {np.note_cc !== undefined ? np.note_cc : <span className="text-slate-400">—</span>}
                        </td>
                        <td className="py-2 px-2 text-center font-semibold">
                          {np.note_examen !== undefined ? np.note_examen : <span className="text-slate-400">—</span>}
                        </td>
                        <td className="py-2 px-2 text-center font-semibold">
                          {np.note_finale !== undefined ? np.note_finale : <span className="text-slate-400">—</span>}
                        </td>
                        <td className="py-2 px-2 text-center font-semibold">
                          {np.note_rattrapage !== undefined ? np.note_rattrapage : <span className="text-slate-400">—</span>}
                        </td>
                        <td className="py-2 px-3 text-center bg-primary/5 font-bold">
                          {np.mg_estimee !== undefined ? (
                            <span className={np.mg_estimee >= 10 ? 'text-green-600 font-bold' : 'text-red-600 font-bold'}>
                              {np.mg_estimee.toFixed(2)}
                            </span>
                          ) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Footer Modale avec boutons de décision DAC */}
            <div className="px-6 py-4 border-t border-outline-variant bg-surface-container flex justify-between items-center flex-wrap gap-3">
              <div>
                {selectedSoumission.statut === 'VALIDE' ? (
                  <span className="flex items-center gap-1.5 text-xs font-bold text-green-700 dark:text-green-400">
                    <CheckCircle2 className="w-4 h-4" />
                    Relevé déjà validé et publié le {new Date(selectedSoumission.date_validation || '').toLocaleDateString('fr-FR')}
                  </span>
                ) : selectedSoumission.statut === 'REJETE' ? (
                  <span className="flex items-center gap-1.5 text-xs font-bold text-red-700 dark:text-red-400">
                    <XCircle className="w-4 h-4" />
                    Bordereau renvoyé à l'enseignant pour correction
                  </span>
                ) : (
                  <span className="text-xs text-on-surface-variant">
                    Le DAC contrôle les notes saisies avant l'injection définitive.
                  </span>
                )}
              </div>

              <div className="flex items-center gap-2">
                {selectedSoumission.statut === 'EN_ATTENTE' && (
                  <>
                    <button
                      type="button"
                      onClick={() => setShowRejectModal(true)}
                      disabled={isProcessing}
                      className="px-4 py-2 text-xs font-bold rounded-xl bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300 hover:bg-red-200 transition-colors"
                    >
                      Demander Correction
                    </button>
                    <button
                      type="button"
                      onClick={() => handleValidateByDAC(selectedSoumission)}
                      disabled={isProcessing}
                      className="flex items-center gap-1.5 px-5 py-2 text-xs font-bold rounded-xl bg-green-600 text-white hover:bg-green-700 transition-all shadow-sm"
                    >
                      <Check className="w-4 h-4" />
                      <span>Valider &amp; Injecter dans les notes</span>
                    </button>
                  </>
                )}
                <button
                  type="button"
                  onClick={() => setSelectedSoumission(null)}
                  className="px-4 py-2 text-xs font-semibold rounded-xl bg-surface-container-highest text-on-surface hover:bg-surface-container-high transition-colors"
                >
                  Fermer
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MODALE MOTIF DE REJET */}
      {showRejectModal && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-xs flex items-center justify-center z-60 p-4">
          <div className="bg-surface-container-lowest text-on-surface rounded-2xl max-w-md w-full p-5 shadow-2xl border border-outline-variant space-y-4">
            <div className="flex items-center gap-2.5 text-red-600">
              <AlertTriangle className="w-5 h-5" />
              <h4 className="font-bold text-base text-on-surface">Demander une Correction à l'Enseignant</h4>
            </div>
            <p className="text-xs text-on-surface-variant">
              Veuillez préciser le motif de correction. L'enseignant sera immédiatement notifié pour rectifier les notes.
            </p>
            <textarea
              required
              rows={4}
              placeholder="Ex : Veuillez compléter les notes de DST manquantes pour les matricules..."
              value={motifRejet}
              onChange={e => setMotifRejet(e.target.value)}
              className="w-full p-3 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface text-xs outline-none focus:ring-2 focus:ring-red-500"
            />
            <div className="flex justify-end gap-2 text-xs">
              <button
                type="button"
                onClick={() => setShowRejectModal(false)}
                className="px-4 py-2 rounded-xl bg-surface-container-highest text-on-surface font-semibold"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={handleRejectByDAC}
                disabled={isProcessing || !motifRejet.trim()}
                className="px-4 py-2 rounded-xl bg-red-600 text-white font-bold hover:bg-red-700 disabled:opacity-50"
              >
                Confirmer le Renvoi
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-on-surface text-surface-container-lowest px-4 py-3 rounded-2xl shadow-xl flex items-center gap-2 text-xs font-medium">
          <CheckCircle2 className="w-4 h-4 text-green-400" />
          <span>{toastMessage}</span>
        </div>
      )}
    </div>
  );
}
