import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { 
  FolderKanban, 
  Send, 
  UploadCloud, 
  FileText, 
  FileCheck, 
  CheckCircle2, 
  XCircle, 
  Clock, 
  AlertCircle, 
  Plus, 
  Search, 
  Filter, 
  Download, 
  Eye, 
  Edit3, 
  Trash2, 
  School, 
  BookOpen, 
  User, 
  Calendar, 
  MessageSquare,
  Sparkles,
  Paperclip,
  Check,
  X,
  ExternalLink,
  HelpCircle
} from 'lucide-react';
import { db } from '../../db/db';
import type { SujetEvaluation, Classe, Matiere, Personnel } from '../../types';
import { cn } from '../../lib/utils';
import { fileToBase64, formatFileSize, downloadAttachment } from '../../utils/fileHelpers';
import {
  TOUS_LES_SEMESTRES,
  getSemestresForClasse,
  getDefaultSemestreForClasse,
  formatSemestreLabel
} from '../../utils/academicSemestres';

export function SujetsProjetsPage() {
  const currentUser = JSON.parse(localStorage.getItem('dac_user') || '{"id":"dac_default","nom":"Prof. M. DIALLO (DAC)","role":"DAC"}');

  const [activeTab, setActiveTab] = useState<'liste' | 'nouvelle_demande'>('liste');
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatut, setFilterStatut] = useState<string>('all');
  const [filterClasse, setFilterClasse] = useState<string>('all');
  const [filterType, setFilterType] = useState<string>('all');

  // Modal d'examen & validation DAC
  const [selectedSujetToReview, setSelectedSujetToReview] = useState<SujetEvaluation | null>(null);
  const [reviewComments, setReviewComments] = useState('');

  // Modal de simulation de dépôt professeur
  const [selectedSujetToSimulate, setSelectedSujetToSimulate] = useState<SujetEvaluation | null>(null);
  const [simulatedFile, setSimulatedFile] = useState<{ nom: string; url: string; taille: number } | null>(null);
  const [simulatedComment, setSimulatedComment] = useState('');

  // Formulaire nouvelle demande DAC vers Prof
  const [formClasseId, setFormClasseId] = useState('');
  const [formMatiereId, setFormMatiereId] = useState('');
  const [formEnseignantId, setFormEnseignantId] = useState('');
  const [formSemestre, setFormSemestre] = useState('S1');
  const [formTypeEval, setFormTypeEval] = useState<'Examen Final' | 'Projet' | 'DST' | 'Rattrapage'>('Examen Final');
  const [formTitre, setFormTitre] = useState('');
  const [formDescription, setFormDescription] = useState('');
  const [formDateLimite, setFormDateLimite] = useState('');
  const [modeleFile, setModeleFile] = useState<{ nom: string; url: string; taille: number } | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Données Dexie en direct
  const sujets = useLiveQuery(() => db.sujets_evaluations.toArray()) || [];
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const classeMatieres = useLiveQuery(() => db.classe_matieres.toArray()) || [];

  // Enseignants filtrés
  const enseignants = personnel.filter(p => p.type_personnel?.toLowerCase() === 'enseignant' || p.fonction?.toLowerCase().includes('enseignant') || p.fonction?.toLowerCase().includes('prof'));

  // Quand le DAC choisit une classe et matière, auto-détecter le prof associé
  const handleClasseOrMatiereChange = (cId: string, mId: string) => {
    if (cId && mId) {
      const link = classeMatieres.find(cm => cm.classe_id === cId && cm.matiere_id === mId);
      if (link && link.enseignant_id) {
        setFormEnseignantId(link.enseignant_id);
        return;
      }
    }
    // Si pas trouvé de lien précis, laisser la sélection libre
  };

  // Upload du fichier modèle par le DAC
  const handleUploadModele = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 20 * 1024 * 1024) {
      alert('Le fichier ne doit pas dépasser 20 Mo.');
      return;
    }

    try {
      const base64Url = await fileToBase64(file);
      setModeleFile({
        nom: file.name,
        url: base64Url,
        taille: file.size
      });
    } catch (err) {
      console.error(err);
      alert('Erreur lors du chargement du fichier modèle.');
    }
  };

  // Upload du sujet par le professeur (simulateur)
  const handleUploadSujetSimulate = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    try {
      const base64Url = await fileToBase64(file);
      setSimulatedFile({
        nom: file.name,
        url: base64Url,
        taille: file.size
      });
    } catch (err) {
      console.error(err);
      alert('Erreur lors du chargement du sujet.');
    }
  };

  // Création de la demande par le DAC
  const handleCreateDemande = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formClasseId || !formMatiereId || !formEnseignantId || !formDateLimite) {
      alert('Veuillez remplir les informations obligatoires (Classe, Matière, Professeur, Date limite).');
      return;
    }

    const selClasse = classes.find(c => c.id === formClasseId);
    const selMatiere = matieres.find(m => m.id === formMatiereId);
    const selProf = personnel.find(p => p.id === formEnseignantId);

    setIsSubmitting(true);
    try {
      const newId = `sujet_${Date.now()}`;
      const now = new Date().toISOString();

      const newSujet: SujetEvaluation = {
        id: newId,
        classe_id: formClasseId,
        classe_nom: selClasse ? `${selClasse.nom} (${selClasse.code})` : 'Classe',
        matiere_id: formMatiereId,
        matiere_nom: selMatiere ? selMatiere.nom : 'Matière',
        enseignant_id: formEnseignantId,
        enseignant_nom: selProf ? `${selProf.nom.toUpperCase()} ${selProf.prenom}` : 'Enseignant',
        type_evaluation: formTypeEval,
        semestre: formSemestre,
        titre: formTitre || `${formTypeEval} - ${selMatiere?.nom || ''}`,
        description: formDescription,
        date_limite: formDateLimite,
        modele_nom: modeleFile ? modeleFile.nom : 'Canevas_Officiel_Sujet_Examen_ISGI.docx',
        modele_url: modeleFile ? modeleFile.url : '#',
        modele_taille: modeleFile ? modeleFile.taille : 48500,
        statut: 'en_attente_depot',
        created_by: currentUser.id,
        created_at: now,
        updated_at: now
      };

      await db.sujets_evaluations.add(newSujet);

      // Notification pour le professeur
      await db.notifications.add({
        id: `notif_sujet_req_${newId}`,
        user_id: formEnseignantId,
        type: 'demande_sujet',
        titre: `Demande de sujet : ${formTypeEval}`,
        description: `Le DAC vous invite à déposer le sujet pour ${selMatiere?.nom} (${selClasse?.code}). Date limite : ${formDateLimite}.`,
        auteur_nom: currentUser.nom,
        auteur_role: 'DAC',
        lien_tab: 'sujets',
        lien_id: newId,
        lu: false,
        created_at: now
      });

      // Reset form
      setFormTitre('');
      setFormDescription('');
      setFormDateLimite('');
      setModeleFile(null);
      setActiveTab('liste');
      alert('Demande de sujet transmise avec succès au professeur !');
    } catch (err) {
      console.error(err);
      alert('Erreur lors de la création de la demande.');
    } finally {
      setIsSubmitting(false);
    }
  };

  // Validation ou Rejet par le DAC
  const handleValiderSujet = async (statut: 'valide' | 'a_corriger') => {
    if (!selectedSujetToReview) return;
    try {
      const now = new Date().toISOString();
      await db.sujets_evaluations.update(selectedSujetToReview.id, {
        statut,
        commentaires_dac: reviewComments.trim() || undefined,
        updated_at: now
      });

      // Notification envoyée au professeur
      await db.notifications.add({
        id: `notif_val_${selectedSujetToReview.id}_${Date.now()}`,
        user_id: selectedSujetToReview.enseignant_id,
        type: statut === 'valide' ? 'sujet_valide' : 'sujet_rejet',
        titre: statut === 'valide' ? 'Sujet validé par la Direction Académique' : 'Demande de correction sur votre sujet',
        description: statut === 'valide' 
          ? `Votre sujet de ${selectedSujetToReview.matiere_nom} (${selectedSujetToReview.classe_nom}) a été validé.`
          : `Remarques DAC : ${reviewComments.trim() || 'Des ajustements sont attendus sur le sujet déposé.'}`,
        auteur_nom: currentUser.nom,
        auteur_role: 'DAC',
        lien_tab: 'sujets',
        lien_id: selectedSujetToReview.id,
        lu: false,
        created_at: now
      });

      setSelectedSujetToReview(null);
      setReviewComments('');
    } catch (err) {
      console.error(err);
      alert('Erreur lors de la mise à jour du statut.');
    }
  };

  // Simulation de dépôt de sujet par le professeur
  const handleConfirmSimulationDepot = async () => {
    if (!selectedSujetToSimulate || !simulatedFile) {
      alert('Veuillez joindre un fichier de sujet pour simuler le dépôt.');
      return;
    }

    try {
      const now = new Date().toISOString();
      await db.sujets_evaluations.update(selectedSujetToSimulate.id, {
        statut: 'depose',
        sujet_nom: simulatedFile.nom,
        sujet_url: simulatedFile.url,
        sujet_taille: simulatedFile.taille,
        date_depot: now,
        updated_at: now
      });

      // Notification reçue par le DAC
      await db.notifications.add({
        id: `notif_dep_${selectedSujetToSimulate.id}_${Date.now()}`,
        user_id: 'dac_default',
        type: 'sujet_depose',
        titre: `Nouveau sujet déposé : ${selectedSujetToSimulate.matiere_nom}`,
        description: `${selectedSujetToSimulate.enseignant_nom} a déposé le sujet officiel pour la classe ${selectedSujetToSimulate.classe_nom}. ${simulatedComment ? `Note : "${simulatedComment}"` : ''}`,
        auteur_nom: selectedSujetToSimulate.enseignant_nom,
        auteur_role: 'Professeur',
        lien_tab: 'sujets',
        lien_id: selectedSujetToSimulate.id,
        lu: false,
        created_at: now
      });

      setSelectedSujetToSimulate(null);
      setSimulatedFile(null);
      setSimulatedComment('');
      alert('Dépôt simulé avec succès ! Le sujet apparaît maintenant comme "Déposé (À valider)" dans votre boîte de réception DAC.');
    } catch (err) {
      console.error(err);
      alert('Erreur lors du dépôt.');
    }
  };

  // Supprimer un enregistrement
  const handleDeleteSujet = async (id: string, titre: string) => {
    if (confirm(`Confirmez-vous la suppression de l'enregistrement "${titre}" ?`)) {
      await db.sujets_evaluations.delete(id);
    }
  };

  // Filtrage de la liste
  const filteredSujets = sujets.filter(s => {
    if (filterStatut !== 'all' && s.statut !== filterStatut) return false;
    if (filterClasse !== 'all' && s.classe_id !== filterClasse) return false;
    if (filterType !== 'all' && s.type_evaluation !== filterType) return false;

    if (searchTerm.trim()) {
      const q = searchTerm.toLowerCase();
      const matchMatiere = s.matiere_nom.toLowerCase().includes(q);
      const matchClasse = s.classe_nom.toLowerCase().includes(q);
      const matchProf = s.enseignant_nom.toLowerCase().includes(q);
      const matchTitre = (s.titre || '').toLowerCase().includes(q);
      return matchMatiere || matchClasse || matchProf || matchTitre;
    }
    return true;
  });

  // Statistiques
  const countTotal = sujets.length;
  const countEnAttente = sujets.filter(s => s.statut === 'en_attente_depot').length;
  const countDeposes = sujets.filter(s => s.statut === 'depose').length;
  const countValides = sujets.filter(s => s.statut === 'valide').length;

  return (
    <div className="space-y-6">
      {/* En-tête principal */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-surface-container-low p-6 rounded-3xl border border-outline-variant/60 shadow-xs">
        <div className="flex items-center gap-3.5">
          <div className="w-12 h-12 rounded-2xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
            <FolderKanban className="w-6 h-6" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl sm:text-2xl font-bold text-on-surface">Transmission & Réception des Sujets</h1>
              <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/15 text-primary">
                DAC ↔ Enseignants
              </span>
            </div>
            <p className="text-xs text-on-surface-variant mt-0.5">
              Espace réservé à l'envoi des modèles d'épreuves et à la réception sécurisée des sujets d'examens et projets par classe et matière.
            </p>
          </div>
        </div>

        {/* Boutons d'action tabs */}
        <div className="flex items-center gap-2">
          <button
            onClick={() => setActiveTab('liste')}
            className={cn(
              "px-4 py-2 text-xs font-semibold rounded-xl transition-all flex items-center gap-2",
              activeTab === 'liste'
                ? "bg-primary text-on-primary shadow-xs"
                : "bg-surface-container text-on-surface-variant hover:bg-surface-container-high"
            )}
          >
            <FileText className="w-4 h-4" />
            <span>Suivi & Réception ({sujets.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('nouvelle_demande')}
            className={cn(
              "px-4 py-2 text-xs font-semibold rounded-xl transition-all flex items-center gap-2",
              activeTab === 'nouvelle_demande'
                ? "bg-primary text-on-primary shadow-xs"
                : "bg-surface-container text-on-surface-variant hover:bg-surface-container-high"
            )}
          >
            <Plus className="w-4 h-4" />
            <span>Envoyer un Modèle au Prof</span>
          </button>
        </div>
      </div>

      {/* Cartes de Synthèse / KPIs */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/50 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center font-bold">
            <FileText className="w-5 h-5" />
          </div>
          <div>
            <p className="text-[11px] text-on-surface-variant font-medium">Total Sujets Demandés</p>
            <p className="text-xl font-black text-on-surface">{countTotal}</p>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/50 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold">
            <Clock className="w-5 h-5" />
          </div>
          <div>
            <p className="text-[11px] text-on-surface-variant font-medium">En Attente de Dépôt</p>
            <p className="text-xl font-black text-on-surface">{countEnAttente}</p>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/50 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center font-bold relative">
            <UploadCloud className="w-5 h-5" />
            {countDeposes > 0 && (
              <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-indigo-600 animate-ping" />
            )}
          </div>
          <div>
            <p className="text-[11px] text-on-surface-variant font-medium">Reçus (À Valider DAC)</p>
            <p className="text-xl font-black text-indigo-600 dark:text-indigo-400">{countDeposes}</p>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/50 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center font-bold">
            <CheckCircle2 className="w-5 h-5" />
          </div>
          <div>
            <p className="text-[11px] text-on-surface-variant font-medium">Sujets Validés ISGI</p>
            <p className="text-xl font-black text-emerald-600 dark:text-emerald-400">{countValides}</p>
          </div>
        </div>
      </div>

      {/* ==================================================================== */}
      {/* VUE 1 : TABLEAU DE BORD & LISTE DES SUJETS REÇUS */}
      {/* ==================================================================== */}
      {activeTab === 'liste' && (
        <div className="space-y-4">
          {/* Barre de filtres */}
          <div className="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/50 flex flex-wrap items-center gap-3">
            <div className="flex-1 min-w-[220px] relative">
              <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
              <input
                type="text"
                placeholder="Rechercher par matière, classe, professeur ou titre..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-9 pr-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
              />
            </div>

            {/* Filtre Statut */}
            <select
              value={filterStatut}
              onChange={(e) => setFilterStatut(e.target.value)}
              className="px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            >
              <option value="all">Tous les Statuts</option>
              <option value="depose">Sujets Reçus (À Valider)</option>
              <option value="en_attente_depot">En Attente du Prof</option>
              <option value="valide">Validés par le DAC</option>
              <option value="a_corriger">À Corriger</option>
            </select>

            {/* Filtre Classe */}
            <select
              value={filterClasse}
              onChange={(e) => setFilterClasse(e.target.value)}
              className="px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            >
              <option value="all">Toutes les Classes</option>
              {classes.map(c => (
                <option key={c.id} value={c.id}>{c.nom} ({c.code})</option>
              ))}
            </select>

            {/* Filtre Type */}
            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              className="px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            >
              <option value="all">Tous les Types</option>
              <option value="Examen Final">Examen Final</option>
              <option value="Projet">Projet</option>
              <option value="DST">DST</option>
              <option value="Rattrapage">Rattrapage</option>
            </select>
          </div>

          {/* Tableau des sujets */}
          <div className="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl overflow-hidden shadow-xs">
            {filteredSujets.length === 0 ? (
              <div className="py-16 text-center text-on-surface-variant">
                <FolderKanban className="w-12 h-12 mx-auto text-outline-variant mb-2 opacity-50" />
                <p className="font-semibold text-sm">Aucun sujet trouvé</p>
                <p className="text-xs text-on-surface-variant mt-1">
                  Lancez une nouvelle demande en envoyant un canevas ou modèle de sujet à un professeur.
                </p>
                <button
                  onClick={() => setActiveTab('nouvelle_demande')}
                  className="mt-4 px-4 py-2 bg-primary text-on-primary text-xs font-bold rounded-xl hover:bg-primary/90 transition-colors shadow-xs"
                >
                  Créer une demande
                </button>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-surface-container-low/70 border-b border-outline-variant text-on-surface-variant uppercase text-[10px] font-bold tracking-wider">
                    <tr>
                      <th className="py-3.5 px-4">Épreuve & Titre</th>
                      <th className="py-3.5 px-4">Classe</th>
                      <th className="py-3.5 px-4">Matière</th>
                      <th className="py-3.5 px-4">Professeur Assigné</th>
                      <th className="py-3.5 px-4">Date Limite</th>
                      <th className="py-3.5 px-4">Statut</th>
                      <th className="py-3.5 px-4">Fichiers (Modèle & Sujet)</th>
                      <th className="py-3.5 px-4 text-right">Actions DAC</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-outline-variant/40 text-on-surface">
                    {filteredSujets.map((sujet) => {
                      const isPastDeadline = new Date(sujet.date_limite).getTime() < Date.now();

                      return (
                        <tr key={sujet.id} className="hover:bg-surface-container-low/40 transition-colors">
                          {/* Titre & Type */}
                          <td className="py-3.5 px-4">
                            <div className="font-bold text-on-surface">{sujet.titre}</div>
                            <span className={cn(
                              "inline-block mt-1 text-[9px] font-bold px-2 py-0.5 rounded-full",
                              sujet.type_evaluation === 'Examen Final' && "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300",
                              sujet.type_evaluation === 'Projet' && "bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300",
                              sujet.type_evaluation === 'DST' && "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300",
                              sujet.type_evaluation === 'Rattrapage' && "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                            )}>
                              {sujet.type_evaluation}
                            </span>
                            {sujet.semestre && (
                              <span className="inline-block ml-1.5 mt-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-surface-container text-primary border border-outline-variant/60">
                                {sujet.semestre}
                              </span>
                            )}
                          </td>

                          {/* Classe */}
                          <td className="py-3.5 px-4 font-semibold text-on-surface">
                            <div className="flex items-center gap-1.5">
                              <School className="w-3.5 h-3.5 text-primary" />
                              <span>{sujet.classe_nom}</span>
                            </div>
                          </td>

                          {/* Matière */}
                          <td className="py-3.5 px-4 font-medium text-on-surface">
                            <div className="flex items-center gap-1.5">
                              <BookOpen className="w-3.5 h-3.5 text-secondary" />
                              <span>{sujet.matiere_nom}</span>
                            </div>
                          </td>

                          {/* Professeur */}
                          <td className="py-3.5 px-4">
                            <div className="flex items-center gap-2">
                              <div className="w-7 h-7 rounded-full bg-surface-container-high text-primary font-bold flex items-center justify-center text-[10px]">
                                {sujet.enseignant_nom.charAt(0)}
                              </div>
                              <span className="font-semibold text-on-surface">{sujet.enseignant_nom}</span>
                            </div>
                          </td>

                          {/* Date Limite */}
                          <td className="py-3.5 px-4">
                            <div className={cn(
                              "font-semibold flex items-center gap-1",
                              isPastDeadline && sujet.statut === 'en_attente_depot' ? "text-red-600 dark:text-red-400" : "text-on-surface"
                            )}>
                              <Calendar className="w-3.5 h-3.5" />
                              <span>{new Date(sujet.date_limite).toLocaleDateString('fr-FR')}</span>
                            </div>
                            {isPastDeadline && sujet.statut === 'en_attente_depot' && (
                              <span className="text-[10px] text-red-600 font-bold block mt-0.5">Délai dépassé</span>
                            )}
                          </td>

                          {/* Statut */}
                          <td className="py-3.5 px-4">
                            {sujet.statut === 'depose' && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 animate-pulse">
                                <UploadCloud className="w-3 h-3" />
                                <span>Déposé (À Valider)</span>
                              </span>
                            )}
                            {sujet.statut === 'en_attente_depot' && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                <Clock className="w-3 h-3" />
                                <span>En attente du Prof</span>
                              </span>
                            )}
                            {sujet.statut === 'valide' && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                <CheckCircle2 className="w-3 h-3" />
                                <span>Validé DAC</span>
                              </span>
                            )}
                            {sujet.statut === 'a_corriger' && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">
                                <XCircle className="w-3 h-3" />
                                <span>À Corriger</span>
                              </span>
                            )}
                          </td>

                          {/* Fichiers */}
                          <td className="py-3.5 px-4 space-y-1">
                            {/* Modèle DAC */}
                            <div className="flex items-center gap-1.5 text-[11px] text-on-surface-variant">
                              <span className="text-[9px] bg-surface-container-high px-1 py-0.5 rounded font-bold">Modèle</span>
                              <span className="truncate max-w-[110px]" title={sujet.modele_nom}>{sujet.modele_nom || 'Canevas.docx'}</span>
                            </div>

                            {/* Sujet déposé */}
                            {sujet.sujet_nom ? (
                              <div className="flex items-center gap-1.5">
                                <span className="text-[9px] bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 px-1 py-0.5 rounded font-bold">Sujet Prof</span>
                                <span className="font-semibold text-primary truncate max-w-[110px]" title={sujet.sujet_nom}>
                                  {sujet.sujet_nom}
                                </span>
                              </div>
                            ) : (
                              <span className="text-[10px] text-on-surface-variant italic">Pas encore de sujet</span>
                            )}
                          </td>

                          {/* Actions */}
                          <td className="py-3.5 px-4 text-right">
                            <div className="flex items-center justify-end gap-1.5">
                              {/* Bouton Examiner & Valider */}
                              {sujet.statut === 'depose' && (
                                <button
                                  onClick={() => {
                                    setSelectedSujetToReview(sujet);
                                    setReviewComments(sujet.commentaires_dac || '');
                                  }}
                                  className="px-2.5 py-1.5 bg-primary text-on-primary text-[11px] font-bold rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-1 shadow-xs"
                                  title="Examiner et valider ce sujet"
                                >
                                  <FileCheck className="w-3.5 h-3.5" />
                                  <span>Valider</span>
                                </button>
                              )}

                              {/* Bouton Simuler Dépôt Professeur (si en attente) */}
                              {sujet.statut === 'en_attente_depot' && (
                                <button
                                  onClick={() => {
                                    setSelectedSujetToSimulate(sujet);
                                    setSimulatedFile(null);
                                    setSimulatedComment('');
                                  }}
                                  className="px-2.5 py-1.5 bg-surface-container-highest text-primary text-[11px] font-semibold rounded-lg hover:bg-primary/10 transition-colors flex items-center gap-1"
                                  title="Simuler le dépôt du sujet côté professeur"
                                >
                                  <UploadCloud className="w-3.5 h-3.5" />
                                  <span>Simuler Dépôt Prof</span>
                                </button>
                              )}

                              {/* Voir détails & remarques */}
                              <button
                                onClick={() => {
                                  setSelectedSujetToReview(sujet);
                                  setReviewComments(sujet.commentaires_dac || '');
                                }}
                                className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
                                title="Voir les détails"
                              >
                                <Eye className="w-4 h-4" />
                              </button>

                              {/* Supprimer */}
                              <button
                                onClick={() => handleDeleteSujet(sujet.id, sujet.titre)}
                                className="p-1.5 rounded-lg text-on-surface-variant hover:bg-error-container hover:text-on-error-container transition-colors"
                                title="Supprimer cette demande"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ==================================================================== */}
      {/* VUE 2 : NOUVELLE DEMANDE DE SUJET (DAC VERS PROFESSEUR) */}
      {/* ==================================================================== */}
      {activeTab === 'nouvelle_demande' && (
        <div className="max-w-3xl mx-auto bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 sm:p-8 shadow-xs">
          <div className="flex items-center gap-3 pb-5 border-b border-outline-variant/60 mb-6">
            <div className="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
              <Send className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-lg font-bold text-on-surface">Envoyer une Demande de Sujet à un Enseignant</h2>
              <p className="text-xs text-on-surface-variant">
                Le DAC sélectionne la classe, la matière et transmet le modèle de sujet (Word ou PDF) au professeur concerné.
              </p>
            </div>
          </div>

          <form onSubmit={handleCreateDemande} className="space-y-5 text-xs">
            {/* Ligne 1 : Classe & Matière */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  1. Sélectionner la Classe *
                </label>
                <select
                  required
                  value={formClasseId}
                  onChange={(e) => {
                    const cId = e.target.value;
                    setFormClasseId(cId);
                    const targetCl = classes.find(c => c.id === cId);
                    if (targetCl) {
                      setFormSemestre(getDefaultSemestreForClasse(targetCl));
                    }
                    handleClasseOrMatiereChange(cId, formMatiereId);
                  }}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                >
                  <option value="">-- Choisir une classe --</option>
                  {classes.map(c => (
                    <option key={c.id} value={c.id}>{c.nom} ({c.code}) - {c.filiere}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  2. Sélectionner la Matière *
                </label>
                <select
                  required
                  value={formMatiereId}
                  onChange={(e) => {
                    const mId = e.target.value;
                    setFormMatiereId(mId);
                    handleClasseOrMatiereChange(formClasseId, mId);
                  }}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                >
                  <option value="">-- Choisir une matière --</option>
                  {matieres.map(m => (
                    <option key={m.id} value={m.id}>{m.nom} ({m.code})</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Ligne 2 : Professeur Associé, Semestre & Type d'Évaluation */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  3. Professeur Assigné *
                </label>
                <select
                  required
                  value={formEnseignantId}
                  onChange={(e) => setFormEnseignantId(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                >
                  <option value="">-- Choisir l'enseignant responsable --</option>
                  {enseignants.map(p => (
                    <option key={p.id} value={p.id}>{p.nom.toUpperCase()} {p.prenom} ({p.fonction || 'Enseignant'})</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  4. Semestre Universitaire
                </label>
                <select
                  value={formSemestre}
                  onChange={(e) => setFormSemestre(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary font-medium"
                >
                  {(formClasseId && classes.find(c => c.id === formClasseId)
                    ? getSemestresForClasse(classes.find(c => c.id === formClasseId))
                    : TOUS_LES_SEMESTRES
                  ).map(s => (
                    <option key={s.value} value={s.value}>{s.label}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  5. Type d'Épreuve / Projet *
                </label>
                <select
                  value={formTypeEval}
                  onChange={(e) => setFormTypeEval(e.target.value as any)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary font-semibold"
                >
                  <option value="Examen Final">Examen Final (Session Normale)</option>
                  <option value="Projet">Projet de Fin de Semestre / Tutoré</option>
                  <option value="DST">DST (Devoir sur Table)</option>
                  <option value="Rattrapage">Session de Rattrapage</option>
                </select>
              </div>
            </div>

            {/* Ligne 3 : Titre de l'épreuve & Date limite */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="sm:col-span-2">
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  Titre de l'épreuve ou du projet (Optionnel)
                </label>
                <input
                  type="text"
                  placeholder="Ex: Épreuve Écrite S1 - Algorithmes et Graphes"
                  value={formTitre}
                  onChange={(e) => setFormTitre(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  Date limite de dépôt impérative *
                </label>
                <input
                  type="date"
                  required
                  value={formDateLimite}
                  onChange={(e) => setFormDateLimite(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary font-semibold"
                />
              </div>
            </div>

            {/* Ligne 4 : Consignes particulières */}
            <div>
              <label className="block text-xs font-bold text-on-surface mb-1.5">
                Consignes, barème & directives particulières pour l'enseignant
              </label>
              <textarea
                rows={3}
                placeholder="Précisez la durée de l'épreuve (ex: 2h), les documents autorisés ou non, le barème sur 20 points, etc."
                value={formDescription}
                onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary resize-none"
              />
            </div>

            {/* Ligne 5 : Modèle de sujet Word ou PDF (Upload) */}
            <div className="p-5 bg-surface-container-low/70 rounded-2xl border border-dashed border-outline-variant">
              <label className="block text-xs font-bold text-on-surface mb-1">
                5. Joindre la Trame / Modèle de Sujet Officiel (Word .docx ou PDF)
              </label>
              <p className="text-[11px] text-on-surface-variant mb-3">
                Le professeur téléchargera ce canevas pour rédiger son sujet et vous le renvoyer rempli.
              </p>

              <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                <label className="inline-flex items-center gap-2 px-4 py-2.5 bg-surface-container-lowest border border-outline-variant rounded-xl cursor-pointer hover:bg-surface-container-high transition-colors font-semibold text-xs text-primary shadow-xs">
                  <UploadCloud className="w-4 h-4" />
                  <span>{modeleFile ? 'Changer le fichier modèle' : 'Choisir le modèle Word / PDF'}</span>
                  <input
                    type="file"
                    accept=".pdf,.docx,.doc"
                    onChange={handleUploadModele}
                    className="hidden"
                  />
                </label>

                {modeleFile ? (
                  <div className="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 px-3 py-1.5 rounded-xl text-emerald-800 dark:text-emerald-300 font-semibold text-xs">
                    <Check className="w-4 h-4" />
                    <span className="truncate max-w-xs">{modeleFile.nom}</span>
                    <span className="text-[10px] opacity-75">({formatFileSize(modeleFile.taille)})</span>
                  </div>
                ) : (
                  <span className="text-[11px] text-on-surface-variant italic">
                    (Si aucun fichier n'est téléversé, le canevas standard officiel ISGI 2026 sera automatiquement utilisé)
                  </span>
                )}
              </div>
            </div>

            {/* Boutons d'action */}
            <div className="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant">
              <button
                type="button"
                onClick={() => setActiveTab('liste')}
                className="px-4 py-2.5 rounded-xl border border-outline-variant font-semibold text-xs text-on-surface hover:bg-surface-container"
              >
                Annuler
              </button>
              <button
                type="submit"
                disabled={isSubmitting}
                className="px-6 py-2.5 bg-primary text-on-primary font-bold text-xs rounded-xl hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50 flex items-center gap-2"
              >
                <Send className="w-4 h-4" />
                <span>{isSubmitting ? 'Transmission en cours...' : 'Envoyer la Demande au Professeur'}</span>
              </button>
            </div>
          </form>
        </div>
      )}

      {/* ==================================================================== */}
      {/* MODAL 1 : EXAMEN & VALIDATION DU SUJET PAR LE DAC */}
      {/* ==================================================================== */}
      {selectedSujetToReview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-surface-container-lowest w-full max-w-xl rounded-3xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            {/* Header */}
            <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-primary/10 text-primary rounded-xl">
                  <FileCheck className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-sm text-on-surface">Fiche de Réception & Validation du Sujet</h3>
                  <p className="text-[11px] text-on-surface-variant">{selectedSujetToReview.matiere_nom} • {selectedSujetToReview.classe_nom}</p>
                </div>
              </div>
              <button
                onClick={() => setSelectedSujetToReview(null)}
                className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Contenu */}
            <div className="p-6 space-y-4 text-xs overflow-y-auto custom-scrollbar">
              {/* Récapitulatif */}
              <div className="grid grid-cols-2 gap-3 p-3.5 rounded-2xl bg-surface-container-low border border-outline-variant/40">
                <div>
                  <span className="text-[10px] text-on-surface-variant block">Type & Titre</span>
                  <span className="font-bold text-on-surface">{selectedSujetToReview.type_evaluation} - {selectedSujetToReview.titre}</span>
                </div>
                <div>
                  <span className="text-[10px] text-on-surface-variant block">Professeur</span>
                  <span className="font-bold text-on-surface">{selectedSujetToReview.enseignant_nom}</span>
                </div>
                <div>
                  <span className="text-[10px] text-on-surface-variant block">Date Limite</span>
                  <span className="font-semibold text-on-surface">{new Date(selectedSujetToReview.date_limite).toLocaleDateString('fr-FR')}</span>
                </div>
                <div>
                  <span className="text-[10px] text-on-surface-variant block">Statut Actuel</span>
                  <span className="font-bold text-primary">{selectedSujetToReview.statut.toUpperCase()}</span>
                </div>
              </div>

              {/* Fichier déposé par le professeur */}
              <div className="p-4 rounded-2xl bg-surface-container-high/60 border border-outline-variant/60 space-y-2">
                <label className="block text-xs font-bold text-on-surface">
                  📄 Fichier du Sujet Déposé par l'Enseignant
                </label>
                {selectedSujetToReview.sujet_nom ? (
                  <div className="flex items-center justify-between p-3 rounded-xl bg-surface-container-lowest border border-outline-variant">
                    <div className="flex items-center gap-2.5 min-w-0">
                      <div className="p-2 rounded-lg bg-red-500/10 text-red-600 font-bold">
                        <FileText className="w-4 h-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="font-bold text-on-surface truncate">{selectedSujetToReview.sujet_nom}</p>
                        <p className="text-[10px] text-on-surface-variant">
                          Déposé le {selectedSujetToReview.date_depot ? new Date(selectedSujetToReview.date_depot).toLocaleString('fr-FR') : 'Date récente'}
                          {selectedSujetToReview.sujet_taille ? ` • ${formatFileSize(selectedSujetToReview.sujet_taille)}` : ''}
                        </p>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => downloadAttachment(selectedSujetToReview.sujet_url || '#', selectedSujetToReview.sujet_nom || 'Sujet.pdf')}
                      className="px-3 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-semibold hover:bg-primary/90 transition-colors flex items-center gap-1.5 shadow-xs shrink-0"
                    >
                      <Download className="w-3.5 h-3.5" />
                      <span>Télécharger</span>
                    </button>
                  </div>
                ) : (
                  <div className="text-center py-4 text-on-surface-variant italic">
                    L'enseignant n'a pas encore déposé son sujet.
                  </div>
                )}
              </div>

              {/* Commentaires & Observations du DAC */}
              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  Observations / Remarques de validation du DAC
                </label>
                <textarea
                  rows={3}
                  placeholder="Ex: Sujet conforme aux objectifs pédagogiques, validé pour impression. OU : Veuillez réviser le barème de l'exercice 2..."
                  value={reviewComments}
                  onChange={(e) => setReviewComments(e.target.value)}
                  className="w-full px-3.5 py-2.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary resize-none"
                />
              </div>
            </div>

            {/* Actions de validation */}
            <div className="p-4 border-t border-outline-variant flex items-center justify-between gap-3 bg-surface-container-low">
              <button
                type="button"
                onClick={() => setSelectedSujetToReview(null)}
                className="px-4 py-2 rounded-xl border border-outline-variant font-semibold text-xs hover:bg-surface-container"
              >
                Fermer
              </button>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => handleValiderSujet('a_corriger')}
                  className="px-4 py-2 bg-surface-container-highest text-error font-semibold rounded-xl text-xs hover:bg-error-container hover:text-on-error-container transition-colors flex items-center gap-1.5"
                >
                  <XCircle className="w-4 h-4" />
                  <span>Demander Correction</span>
                </button>

                <button
                  type="button"
                  onClick={() => handleValiderSujet('valide')}
                  className="px-5 py-2 bg-emerald-600 text-white font-bold rounded-xl text-xs hover:bg-emerald-700 transition-colors shadow-sm flex items-center gap-1.5"
                >
                  <CheckCircle2 className="w-4 h-4" />
                  <span>Valider le Sujet</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ==================================================================== */}
      {/* MODAL 2 : SIMULATION DE DÉPÔT ENSEIGNANT (TEST INTERACTIF) */}
      {/* ==================================================================== */}
      {selectedSujetToSimulate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-surface-container-lowest w-full max-w-lg rounded-3xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col">
            <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-primary/10 text-primary rounded-xl">
                  <UploadCloud className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-sm text-on-surface">Simulateur : Dépôt du Sujet côté Professeur</h3>
                  <p className="text-[11px] text-on-surface-variant">
                    Vous testez l'action que réalise {selectedSujetToSimulate.enseignant_nom} pour vous transmettre son sujet
                  </p>
                </div>
              </div>
              <button
                onClick={() => setSelectedSujetToSimulate(null)}
                className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-6 space-y-4 text-xs">
              <div className="p-3 bg-surface-container-low rounded-xl border border-outline-variant/40 space-y-1">
                <p><span className="font-bold">Matière :</span> {selectedSujetToSimulate.matiere_nom}</p>
                <p><span className="font-bold">Classe :</span> {selectedSujetToSimulate.classe_nom}</p>
                <p><span className="font-bold">Date limite :</span> {new Date(selectedSujetToSimulate.date_limite).toLocaleDateString('fr-FR')}</p>
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  Joindre le sujet rédigé par le professeur (Word .docx ou PDF) *
                </label>
                <label className="flex flex-col items-center justify-center p-6 border-2 border-dashed border-outline-variant rounded-2xl cursor-pointer hover:bg-surface-container-high transition-colors text-center">
                  <UploadCloud className="w-8 h-8 text-primary mb-2" />
                  <span className="font-bold text-xs text-on-surface">
                    {simulatedFile ? simulatedFile.nom : 'Cliquez pour sélectionner le sujet du professeur'}
                  </span>
                  <span className="text-[10px] text-on-surface-variant mt-0.5">
                    {simulatedFile ? `${formatFileSize(simulatedFile.taille)} - Prêt à déposer` : 'PDF, DOCX, DOC jusqu\'à 20 Mo'}
                  </span>
                  <input
                    type="file"
                    accept=".pdf,.docx,.doc"
                    onChange={handleUploadSujetSimulate}
                    className="hidden"
                  />
                </label>
              </div>

              <div>
                <label className="block text-xs font-bold text-on-surface mb-1.5">
                  Message ou commentaire d'accompagnement de l'enseignant
                </label>
                <textarea
                  rows={2}
                  placeholder="Ex: Bonjour DAC, veuillez trouver ci-joint l'épreuve finale avec le barème corrigé."
                  value={simulatedComment}
                  onChange={(e) => setSimulatedComment(e.target.value)}
                  className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary resize-none"
                />
              </div>
            </div>

            <div className="p-4 border-t border-outline-variant flex items-center justify-end gap-2 bg-surface-container-low">
              <button
                type="button"
                onClick={() => setSelectedSujetToSimulate(null)}
                className="px-4 py-2 rounded-xl border border-outline-variant font-semibold text-xs hover:bg-surface-container"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={handleConfirmSimulationDepot}
                disabled={!simulatedFile}
                className="px-5 py-2 bg-primary text-on-primary font-bold rounded-xl text-xs hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50 flex items-center gap-1.5"
              >
                <Check className="w-4 h-4" />
                <span>Déposer le Sujet au DAC</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
