import React, { useState, useEffect, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, logAction, pushItemToSupabase, deleteItemFromSupabase, setPublicationDAC, estPublieDAC } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { EmploiDuTempsItem, Classe, Matiere, Personnel, Salle, NotificationItem } from '../../types';
import { exporterEmploiDuTempsPDF } from '../../utils/academicPdfExport';
import {
  TOUS_LES_SEMESTRES,
  getSemestresForClasse,
  getDefaultSemestreForClasse,
  formatSemestreLabel
} from '../../utils/academicSemestres';
import {
  Clock,
  Plus,
  Search,
  Filter,
  Printer,
  Calendar,
  Layers,
  MapPin,
  User,
  BookOpen,
  School,
  AlertTriangle,
  Edit2,
  Trash2,
  Eye,
  CheckCircle2,
  ChevronRight,
  Sparkles,
  Download,
  Lock,
  Globe,
  Share2,
  Radio,
  Send
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function EmploiDuTempsPage() {
  const [activeView, setActiveView] = useState<'grille' | 'liste' | 'salles'>('grille');
  const [selectedClasseId, setSelectedClasseId] = useState<string>('all');
  const [filterJour, setFilterJour] = useState<string>('all');
  const [filterEnseignant, setFilterEnseignant] = useState<string>('all');
  const [filterSalle, setFilterSalle] = useState<string>('all');
  const [filterSemestre, setFilterSemestre] = useState<string>('S1');
  const [searchTerm, setSearchTerm] = useState<string>('');

  // Publication & Notifications
  const [isPublishing, setIsPublishing] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Modales
  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [editingItem, setEditingItem] = useState<EmploiDuTempsItem | null>(null);
  const [selectedItemForView, setSelectedItemForView] = useState<EmploiDuTempsItem | null>(null);
  const [conflictWarning, setConflictWarning] = useState<string | null>(null);

  // Formulaire
  const [formData, setFormData] = useState<Partial<EmploiDuTempsItem>>({
    classe_id: '',
    matiere_id: '',
    enseignant_id: '',
    jour_semaine: 'Lundi',
    heure_debut: '08:00',
    heure_fin: '10:00',
    salle: 'Amphi A - Campus 1',
    type_cours: 'CM',
    semestre: 'S1',
    annee_academique: '2025-2026',
    statut: 'actif',
    publie: false,
    couleur: '#0284c7'
  });

  // Données depuis IndexedDB
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const coursList = useLiveQuery(() => db.emplois_du_temps.toArray()) || [];
  const salles = useLiveQuery(() => db.salles.toArray()) || [];
  const publications = useLiveQuery(() => db.publications_academiques.toArray()) || [];

  // Paramètres personnalisés (signatures, nom d'établissement)
  const [settings, setSettings] = useState<{ [key: string]: any }>({
    nomEcole: "Institut Supérieur de Gestion et d'Ingénierie",
    sigle: "ISGI",
    titreSignataire1: "Le Secrétaire Général",
    nomSignataire1: "Dr. A. KOUAME",
    titreSignataire2: "Le Directeur Académique (DAC)",
    nomSignataire2: "Prof. M. DIALLO",
    mentionBasDePage: "Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI"
  });

  useEffect(() => {
    try {
      const saved = localStorage.getItem('isgi_settings');
      if (saved) {
        setSettings(prev => ({ ...prev, ...JSON.parse(saved) }));
      }
    } catch {}
  }, []);

  // Enseignants uniquement
  const enseignants = personnel.filter(p =>
    p.type_personnel?.toLowerCase().includes('enseign') ||
    p.fonction?.toLowerCase().includes('enseign') ||
    p.fonction?.toLowerCase().includes('prof')
  );

  // Jours de la semaine
  const joursOrdre: ('Lundi' | 'Mardi' | 'Mercredi' | 'Jeudi' | 'Vendredi' | 'Samedi')[] = [
    'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'
  ];

  // Heures pour la grille
  const creneauxHoraires = [
    { start: '08:00', end: '10:00', label: '08h00 - 10h00' },
    { start: '10:15', end: '12:15', label: '10h15 - 12h15' },
    { start: '12:15', end: '14:00', label: '12h15 - 14h00 (Pause)' },
    { start: '14:00', end: '16:00', label: '14h00 - 16h00' },
    { start: '16:15', end: '18:15', label: '16h15 - 18h15' }
  ];

  // Salles uniques disponibles
  const sallesDisponibles = Array.from(new Set([
    'Amphi A - Campus 1',
    'Amphi B - Campus 1',
    'Labo Info 1',
    'Labo Info 2',
    'Labo Réseaux',
    'Salle 101',
    'Salle 102',
    'Salle 103',
    'Salle 201',
    'Salle 202',
    ...coursList.map(c => c.salle)
  ])).filter(Boolean).sort();

  // Helper maps
  const classeMap = new Map(classes.map(c => [c.id, c]));
  const matiereMap = new Map(matieres.map(m => [m.id, m]));
  const enseignantMap = new Map(personnel.map(p => [p.id, p]));

  // Statut de publication officiel DAC pour la classe et le semestre sélectionnés
  const pubKey = selectedClasseId !== 'all' ? `pub_emploi_du_temps_${selectedClasseId}_${filterSemestre}` : '';
  const currentPublication = useMemo(() => {
    if (!pubKey) return null;
    return publications.find(p => p.id === pubKey) || null;
  }, [publications, pubKey]);

  const isPublishedForSelection = useMemo(() => {
    if (selectedClasseId === 'all') return false;
    if (currentPublication) return Boolean(currentPublication.publie);
    const coursClasse = coursList.filter(c => c.classe_id === selectedClasseId && (filterSemestre === 'all' || c.semestre === filterSemestre));
    return coursClasse.length > 0 && coursClasse.every(c => c.publie);
  }, [currentPublication, coursList, selectedClasseId, filterSemestre]);

  const handleTogglePublication = async () => {
    if (selectedClasseId === 'all') {
      alert('Veuillez d\'abord sélectionner une classe spécifique dans le filtre ci-dessous pour gérer sa publication.');
      return;
    }
    const currentClasse = classeMap.get(selectedClasseId);
    const nextStatus = !isPublishedForSelection;
    const actionText = nextStatus
      ? 'PUBLIER OFFICIELLEMENT cet emploi du temps (il sera visible par les étudiants et professeurs)'
      : 'DÉPUBLIER cet emploi du temps (il redeviendra un brouillon confidentiel réservé au DAC)';

    if (!window.confirm(`Confirmez-vous l'action suivante : \n\n${actionText} pour la classe "${currentClasse?.nom}" (${formatSemestreLabel(filterSemestre)}) ?`)) {
      return;
    }

    setIsPublishing(true);
    try {
      await setPublicationDAC(
        'emploi_du_temps',
        nextStatus,
        selectedClasseId,
        filterSemestre,
        currentClasse?.annee_academique || '2025-2026'
      );

      if (nextStatus) {
        const notif: NotificationItem = {
          id: `notif_edt_${Date.now()}`,
          user_id: 'all_students_teachers',
          type: 'system',
          titre: `Emploi du temps officiel publié : ${currentClasse?.nom}`,
          description: `L'emploi du temps officiel pour le ${formatSemestreLabel(filterSemestre)} de la classe ${currentClasse?.nom} a été publié par le DAC.`,
          auteur_nom: 'Direction Académique (DAC)',
          auteur_role: 'DAC',
          lien_tab: 'emploi_temps',
          lu: false,
          created_at: new Date().toISOString()
        };
        await db.notifications.put(notif);
        supabase.from('notifications').insert([notif]).then(() => {}, () => {});
      }

      setToastMessage(
        nextStatus
          ? `✅ Emploi du temps de ${currentClasse?.nom} (${filterSemestre}) publié avec succès ! Accessible aux étudiants et professeurs.`
          : `🔒 Emploi du temps de ${currentClasse?.nom} (${filterSemestre}) dépublié. Mode confidentiel DAC activé.`
      );
      setTimeout(() => setToastMessage(null), 4000);
    } catch (err: any) {
      alert('Erreur lors de la publication : ' + err.message);
    } finally {
      setIsPublishing(false);
    }
  };

  // Obtenir le jour actuel
  const jourActuelIndex = new Date().getDay(); // 0 = Dimanche, 1 = Lundi, etc.
  const jourActuelNom = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][jourActuelIndex];

  // Filtrage des cours
  const filteredCours = coursList.filter(c => {
    if (selectedClasseId !== 'all' && c.classe_id !== selectedClasseId) return false;
    if (filterJour !== 'all' && c.jour_semaine !== filterJour) return false;
    if (filterEnseignant !== 'all' && c.enseignant_id !== filterEnseignant) return false;
    if (filterSalle !== 'all' && c.salle !== filterSalle) return false;
    if (filterSemestre !== 'all' && c.semestre !== filterSemestre) return false;

    if (searchTerm.trim() !== '') {
      const term = searchTerm.toLowerCase();
      const cl = classeMap.get(c.classe_id)?.nom || '';
      const mat = matiereMap.get(c.matiere_id)?.nom || '';
      const prof = enseignantMap.get(c.enseignant_id || '')?.nom || '';
      const match = `${cl} ${mat} ${prof} ${c.salle} ${c.type_cours}`.toLowerCase();
      if (!match.includes(term)) return false;
    }

    return true;
  });

  // KPIs
  const totalCoursCount = coursList.length;
  const coursAujourdhuiCount = coursList.filter(c => c.jour_semaine === jourActuelNom).length;
  const classesActivesCount = new Set(coursList.map(c => c.classe_id)).size;
  const sallesOccupeesCount = new Set(coursList.map(c => c.salle)).size;

  // Calcul du volume horaire total par semaine (heures)
  const volumeHoraireTotal = filteredCours.reduce((acc, c) => {
    const [sh, sm] = c.heure_debut.split(':').map(Number);
    const [eh, em] = c.heure_fin.split(':').map(Number);
    const diff = (eh * 60 + em - (sh * 60 + sm)) / 60;
    return acc + (diff > 0 ? diff : 0);
  }, 0);

  // Détecteur de conflits de salle ou d'enseignant
  const checkConflicts = (testItem: Partial<EmploiDuTempsItem>): string | null => {
    if (!testItem.jour_semaine || !testItem.heure_debut || !testItem.heure_fin) return null;

    const [tsh, tsm] = testItem.heure_debut.split(':').map(Number);
    const [teh, tem] = testItem.heure_fin.split(':').map(Number);
    const tStart = tsh * 60 + tsm;
    const tEnd = teh * 60 + tem;

    for (const other of coursList) {
      if (editingItem && other.id === editingItem.id) continue;
      if (other.jour_semaine !== testItem.jour_semaine) continue;
      if (other.semestre !== testItem.semestre) continue;

      const [osh, osm] = other.heure_debut.split(':').map(Number);
      const [oeh, oem] = other.heure_fin.split(':').map(Number);
      const oStart = osh * 60 + osm;
      const oEnd = oeh * 60 + oem;

      // Chevauchement horaire
      const overlap = (tStart < oEnd && tEnd > oStart);
      if (overlap) {
        // Conflit de salle
        if (testItem.salle && other.salle === testItem.salle) {
          const clNom = classeMap.get(other.classe_id)?.nom || 'une classe';
          return `Attention : La salle "${testItem.salle}" est déjà réservée pour ${clNom} de ${other.heure_debut} à ${other.heure_fin} !`;
        }
        // Conflit de professeur
        if (testItem.enseignant_id && other.enseignant_id === testItem.enseignant_id) {
          const profNom = enseignantMap.get(testItem.enseignant_id)?.nom || 'Cet enseignant';
          return `Attention : ${profNom} dispense déjà un cours dans ${other.salle} de ${other.heure_debut} à ${other.heure_fin} !`;
        }
        // Conflit de classe (même classe ayant deux cours en même temps)
        if (testItem.classe_id && other.classe_id === testItem.classe_id) {
          const matNom = matiereMap.get(other.matiere_id)?.nom || 'un autre cours';
          return `Attention : Cette classe a déjà le cours "${matNom}" programmé sur ce même créneau !`;
        }
      }
    }
    return null;
  };

  // Ouvrir modal ajout
  const handleOpenAdd = (defaultJour?: 'Lundi' | 'Mardi' | 'Mercredi' | 'Jeudi' | 'Vendredi' | 'Samedi', defaultStart?: string, defaultEnd?: string) => {
    setEditingItem(null);
    setConflictWarning(null);
    setFormData({
      classe_id: selectedClasseId !== 'all' ? selectedClasseId : (classes[0]?.id || ''),
      matiere_id: matieres[0]?.id || '',
      enseignant_id: enseignants[0]?.id || '',
      jour_semaine: defaultJour || 'Lundi',
      heure_debut: defaultStart || '08:00',
      heure_fin: defaultEnd || '10:00',
      salle: 'Amphi A - Campus 1',
      type_cours: 'CM',
      semestre: filterSemestre !== 'all' ? (filterSemestre as any) : 'S1',
      annee_academique: '2025-2026',
      statut: 'actif',
      publie: isPublishedForSelection,
      couleur: '#0284c7'
    });
    setIsModalOpen(true);
  };

  // Ouvrir modal édition
  const handleOpenEdit = (item: EmploiDuTempsItem) => {
    setEditingItem(item);
    setConflictWarning(null);
    setFormData({ ...item });
    setIsModalOpen(true);
  };

  // Sauvegarder
  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.classe_id || !formData.matiere_id || !formData.heure_debut || !formData.heure_fin || !formData.salle) {
      alert('Veuillez remplir la classe, la matière, les horaires et la salle.');
      return;
    }

    const conflict = checkConflicts(formData);
    if (conflict) {
      const confirmOverride = confirm(`${conflict}\n\nSouhaitez-vous quand même enregistrer ce cours ?`);
      if (!confirmOverride) return;
    }

    try {
      if (editingItem) {
        const payload = {
          ...formData,
          publie: formData.publie !== undefined ? formData.publie : isPublishedForSelection,
          updated_at: new Date().toISOString()
        };
        await db.emplois_du_temps.update(editingItem.id, payload);
        await pushItemToSupabase('emplois_du_temps', { id: editingItem.id, ...payload });
        const clName = classeMap.get(formData.classe_id)?.nom || '';
        const matName = matiereMap.get(formData.matiere_id)?.nom || '';
        await logAction('Modification Cours Emploi du Temps', 'Emploi du Temps', `${clName} - ${matName} (${formData.jour_semaine} ${formData.heure_debut})`);
      } else {
        const newId = 'edt_' + Date.now();
        const newRecord: EmploiDuTempsItem = {
          ...(formData as EmploiDuTempsItem),
          id: newId,
          publie: formData.publie !== undefined ? formData.publie : isPublishedForSelection,
          created_at: new Date().toISOString()
        };
        await db.emplois_du_temps.add(newRecord);
        await pushItemToSupabase('emplois_du_temps', newRecord);
        const clName = classeMap.get(newRecord.classe_id)?.nom || '';
        const matName = matiereMap.get(newRecord.matiere_id)?.nom || '';
        await logAction('Ajout Cours Emploi du Temps', 'Emploi du Temps', `${clName} - ${matName} (${newRecord.jour_semaine} ${newRecord.heure_debut})`);
      }
      setIsModalOpen(false);
    } catch (err: any) {
      alert('Erreur: ' + err.message);
    }
  };

  // Supprimer
  const handleDelete = async (id: string, label: string) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer ce cours (${label}) ?`)) {
      await db.emplois_du_temps.delete(id);
      await deleteItemFromSupabase('emplois_du_temps', id);
      await logAction('Suppression Cours Emploi du Temps', 'Emploi du Temps', label);
    }
  };

  // Couleurs pastel selon type de cours
  const getTypeBadgeColor = (type: string) => {
    switch (type) {
      case 'CM':
        return 'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-blue-500/30';
      case 'TD':
        return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
      case 'TP':
        return 'bg-purple-500/15 text-purple-700 dark:text-purple-300 border-purple-500/30';
      case 'Séminaire':
        return 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30';
      case 'Évaluation':
        return 'bg-rose-500/15 text-rose-700 dark:text-rose-300 border-rose-500/30';
      default:
        return 'bg-surface-container text-on-surface';
    }
  };

  const selectedClasse = selectedClasseId !== 'all' ? classeMap.get(selectedClasseId) : null;

  const handleExportPDF = async () => {
    const cl = selectedClasseId !== 'all' ? classeMap.get(selectedClasseId) : null;
    const clNom = cl ? cl.nom : 'Toutes Classes';
    const sem = filterSemestre !== 'all' ? filterSemestre : (cl ? getDefaultSemestreForClasse(cl) : 'S1');
    const annee = cl?.annee_academique || settings.anneeAcademique || '2025-2026';
    await exporterEmploiDuTempsPDF(clNom, sem, annee, filteredCours, matieres, personnel);
  };

  return (
    <div className="space-y-6">
      {/* En-tête officiel uniquement visible à l'impression */}
      <div className="hidden print:flex items-center justify-between pb-4 mb-4 border-b border-gray-400 gap-4">
        <div className="flex items-center gap-3">
          <img src="./logo.jpg" alt="Logo ISGI" className="w-14 h-14 object-contain" />
          <div>
            {settings.enTeteMessageHaut && (
              <p className="text-[9px] font-bold text-gray-600 uppercase mb-0.5">
                {settings.enTeteMessageHaut}
              </p>
            )}
            <h2 className="text-sm font-extrabold uppercase text-gray-900 leading-tight">
              {settings.nomEcole || "Institut Supérieur de Gestion et d'Ingénierie"} ({settings.sigle || "ISGI"})
            </h2>
            <p className="text-[11px] font-bold text-blue-900">
              {settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"}
            </p>
            <p className="text-[9px] text-gray-500">
              {settings.enTeteSousTitre || "Enseignement Supérieur Technique et Professionnel • Agréé par l'État"}
            </p>
          </div>
        </div>
        <div className="text-right">
          <h3 className="text-xs font-bold text-gray-900 uppercase">
            EMPLOI DU TEMPS HEBDOMADAIRE — {selectedClasse ? selectedClasse.nom : 'TOUTES CLASSES'}
          </h3>
          <p className="text-[10px] text-gray-600 mt-0.5">
            Semestre : {filterSemestre === 'all' ? (selectedClasse ? getDefaultSemestreForClasse(selectedClasse) : 'S1') : filterSemestre} • Année Académique : {settings.anneeAcademique || '2025-2026'}
          </p>
        </div>
      </div>

      {/* En-tête Principal */}
      <div className="bg-surface-container-low p-6 rounded-2xl border border-outline-variant flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <Clock className="w-7 h-7" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-on-surface">
                Emploi du Temps & Planning des Cours
              </h1>
              <p className="text-sm text-on-surface-variant">
                Organisation hebdomadaire des salles, classes, matières et enseignants sans chevauchement
              </p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            onClick={handleExportPDF}
            className="flex items-center gap-2 px-4 py-2.5 rounded-full bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors shadow-xs"
            title="Télécharger l'emploi du temps au format PDF officiel"
          >
            <Download className="w-4 h-4" />
            <span>Télécharger PDF</span>
          </button>

          <button
            onClick={() => window.print()}
            className="flex items-center gap-2 px-4 py-2.5 rounded-full border border-outline-variant bg-surface-container-lowest text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors shadow-xs"
          >
            <Printer className="w-4 h-4 text-on-surface-variant" />
            <span>Imprimer Planning</span>
          </button>

          <button
            onClick={() => handleOpenAdd()}
            className="flex items-center gap-2 px-5 py-2.5 rounded-full bg-primary text-on-primary text-sm font-semibold hover:bg-primary/90 transition-colors shadow-sm"
          >
            <Plus className="w-4 h-4" />
            <span>Ajouter un Cours</span>
          </button>
        </div>
      </div>

      {/* KPIs Statistiques */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-on-surface-variant uppercase tracking-wider">Cours Programmés</p>
            <h3 className="text-2xl font-bold text-on-surface mt-1">{totalCoursCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Sur l'ensemble de la semaine</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
            <BookOpen className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Cours Aujourd'hui ({jourActuelNom})</p>
            <h3 className="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{coursAujourdhuiCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Séances de la journée</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
            <Calendar className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Classes Actives</p>
            <h3 className="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{classesActivesCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Avec emploi du temps assigné</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center">
            <School className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-purple-600 dark:text-purple-400 uppercase tracking-wider">Salles Sollicitées</p>
            <h3 className="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-1">{sallesOccupeesCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Amphis, labos et salles</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center">
            <MapPin className="w-6 h-6" />
          </div>
        </div>
      </div>

      {/* Notification Toast Flottant */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 bg-surface-container-lowest border border-outline-variant px-5 py-3.5 rounded-2xl shadow-xl animate-in fade-in slide-in-from-bottom-5">
          <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          <span className="text-sm font-semibold text-on-surface">{toastMessage}</span>
        </div>
      )}

      {/* BANDEAU DE CONTRÔLE DE PUBLICATION PAR LE DAC */}
      <div className={cn(
        "p-5 rounded-2xl border transition-all flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-xs",
        selectedClasseId === 'all'
          ? "bg-surface-container-low border-outline-variant"
          : isPublishedForSelection
            ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-950 dark:text-emerald-100"
            : "bg-amber-500/10 border-amber-500/30 text-amber-950 dark:text-amber-100"
      )}>
        <div className="flex items-start md:items-center gap-3.5">
          <div className={cn(
            "w-12 h-12 rounded-xl flex items-center justify-center shrink-0 shadow-xs",
            selectedClasseId === 'all'
              ? "bg-surface-container-highest text-on-surface-variant"
              : isPublishedForSelection
                ? "bg-emerald-600 text-white"
                : "bg-amber-600 text-white"
          )}>
            {selectedClasseId === 'all' ? (
              <School className="w-6 h-6" />
            ) : isPublishedForSelection ? (
              <Globe className="w-6 h-6" />
            ) : (
              <Lock className="w-6 h-6" />
            )}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-sm font-bold text-on-surface">
                {selectedClasseId === 'all'
                  ? "Gestion de la Publication de l'Emploi du Temps"
                  : isPublishedForSelection
                    ? `Emploi du temps Officiel Publié • ${selectedClasse?.nom} (${formatSemestreLabel(filterSemestre)})`
                    : `Brouillon Confidentiel DAC • ${selectedClasse?.nom} (${formatSemestreLabel(filterSemestre)})`}
              </h3>
              {selectedClasseId !== 'all' && (
                <span className={cn(
                  "text-[10px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full border",
                  isPublishedForSelection
                    ? "bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-800"
                    : "bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-800"
                )}>
                  {isPublishedForSelection ? "Visible par tous" : "Confidentiel DAC (Non visible)"}
                </span>
              )}
            </div>
            <p className="text-xs text-on-surface-variant mt-1">
              {selectedClasseId === 'all'
                ? "Sélectionnez une classe dans le filtre ci-dessous pour publier ou verrouiller son planning auprès des étudiants et enseignants."
                : isPublishedForSelection
                  ? "✅ Cet emploi du temps est accessible en direct par les étudiants, les parents et les professeurs sur leurs applications."
                  : "🔒 Cet emploi du temps est en préparation. Seul le compte DAC peut le voir. Aucun étudiant ni professeur n'y a accès tant que vous ne publiez pas."}
            </p>
          </div>
        </div>

        {selectedClasseId !== 'all' && (
          <div className="flex items-center gap-2 shrink-0">
            <button
              onClick={handleTogglePublication}
              disabled={isPublishing}
              className={cn(
                "flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold transition-all shadow-xs cursor-pointer",
                isPublishedForSelection
                  ? "bg-surface-container-lowest text-amber-700 dark:text-amber-300 border border-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/50"
                  : "bg-emerald-600 text-white hover:bg-emerald-700 shadow-md shadow-emerald-600/20"
              )}
            >
              {isPublishedForSelection ? (
                <>
                  <Lock className="w-4 h-4" />
                  <span>{isPublishing ? "Traitement..." : "Dépublier (Masquer aux étudiants)"}</span>
                </>
              ) : (
                <>
                  <Globe className="w-4 h-4" />
                  <span>{isPublishing ? "Publication..." : "📢 Publier l'Emploi du Temps"}</span>
                </>
              )}
            </button>
          </div>
        )}
      </div>

      {/* Barre de navigation, vues & filtres */}
      <div className="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant shadow-xs space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          {/* Sélecteur de vues */}
          <div className="flex items-center p-1 bg-surface-container rounded-xl border border-outline-variant">
            <button
              onClick={() => setActiveView('grille')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                activeView === 'grille'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <Calendar className="w-4 h-4" />
              <span>Grille Hebdomadaire</span>
            </button>

            <button
              onClick={() => setActiveView('liste')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                activeView === 'liste'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <Layers className="w-4 h-4" />
              <span>Vue Tabulaire Détaillée</span>
            </button>

            <button
              onClick={() => setActiveView('salles')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                activeView === 'salles'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <MapPin className="w-4 h-4" />
              <span>Occupation des Salles</span>
            </button>
          </div>

          {/* Recherche */}
          <div className="relative flex-1 max-w-xs">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher matière, salle, enseignant..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-4 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            />
          </div>
        </div>

        {/* Filtres par sélection */}
        <div className="flex flex-wrap items-center gap-3 pt-2 border-t border-outline-variant">
          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold">
            <Filter className="w-3.5 h-3.5 text-primary" />
            <span>Classe :</span>
          </div>
          <select
            value={selectedClasseId}
            onChange={(e) => setSelectedClasseId(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs font-semibold text-primary focus:outline-hidden"
          >
            <option value="all">Toutes les classes</option>
            {classes.map(cl => (
              <option key={cl.id} value={cl.id}>{cl.nom} ({cl.code})</option>
            ))}
          </select>

          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold ml-2">
            <span>Semestre :</span>
          </div>
          <select
            value={filterSemestre}
            onChange={(e) => setFilterSemestre(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden font-medium"
          >
            <option value="all">Tous les semestres</option>
            {(selectedClasse ? getSemestresForClasse(selectedClasse) : TOUS_LES_SEMESTRES).map(s => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>

          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold ml-2">
            <span>Jour :</span>
          </div>
          <select
            value={filterJour}
            onChange={(e) => setFilterJour(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Tous les jours</option>
            {joursOrdre.map(j => (
              <option key={j} value={j}>{j}</option>
            ))}
          </select>

          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold ml-2">
            <span>Salle :</span>
          </div>
          <select
            value={filterSalle}
            onChange={(e) => setFilterSalle(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Toutes les salles</option>
            {sallesDisponibles.map(s => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>

          <div className="ml-auto text-xs font-semibold text-on-surface-variant">
            Volume affiché : <span className="text-primary font-bold">{volumeHoraireTotal}h</span> / sem
          </div>
        </div>
      </div>

      {/* ======================= VUE 1 : GRILLE HEBDOMADAIRE INTERACTIVE ======================= */}
      {activeView === 'grille' && (
        <div className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs p-6 space-y-4">
          {selectedClasse && (
            <div className="flex items-center justify-between pb-3 border-b border-outline-variant">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold">
                  <School className="w-6 h-6" />
                </div>
                <div>
                  <h3 className="font-bold text-base text-on-surface">
                    Emploi du temps officiel : {selectedClasse.nom}
                  </h3>
                  <p className="text-xs text-on-surface-variant">
                    Niveau : {selectedClasse.niveau} | Filière : {selectedClasse.filiere} | Année : {selectedClasse.annee_academique}
                  </p>
                </div>
              </div>

              <span className="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container text-xs font-bold">
                {filteredCours.length} séances hebdomadaires
              </span>
            </div>
          )}

          {/* Grille 6 Jours */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
            {joursOrdre.map((jour) => {
              const coursDuJour = filteredCours
                .filter(c => c.jour_semaine === jour)
                .sort((a, b) => a.heure_debut.localeCompare(b.heure_debut));

              const isToday = jour === jourActuelNom;

              return (
                <div
                  key={jour}
                  className={cn(
                    "flex flex-col bg-surface-container-low rounded-xl border border-outline-variant/70 overflow-hidden",
                    isToday && "ring-2 ring-primary bg-primary/5"
                  )}
                >
                  {/* Header du Jour */}
                  <div className={cn(
                    "p-3 border-b border-outline-variant flex items-center justify-between",
                    isToday ? "bg-primary text-on-primary font-bold" : "bg-surface-container text-on-surface font-semibold"
                  )}>
                    <div className="flex items-center gap-1.5 text-xs">
                      <span>{jour}</span>
                      {isToday && (
                        <span className="text-[10px] uppercase font-extrabold px-1.5 py-0.5 rounded-sm bg-white text-primary">
                          Aujourd'hui
                        </span>
                      )}
                    </div>
                    <button
                      onClick={() => handleOpenAdd(jour)}
                      className="p-1 rounded hover:bg-black/10 transition-colors"
                      title={`Ajouter un cours le ${jour}`}
                    >
                      <Plus className="w-3.5 h-3.5" />
                    </button>
                  </div>

                  {/* Liste des cours du jour */}
                  <div className="p-2 space-y-2 flex-1 min-h-72">
                    {coursDuJour.length === 0 ? (
                      <div className="h-full flex flex-col items-center justify-center text-center p-4 text-on-surface-variant/40">
                        <Clock className="w-6 h-6 mb-1 opacity-50" />
                        <span className="text-[11px] italic">Aucun cours</span>
                      </div>
                    ) : (
                      coursDuJour.map((cours) => {
                        const mat = matiereMap.get(cours.matiere_id);
                        const cl = classeMap.get(cours.classe_id);
                        const prof = enseignantMap.get(cours.enseignant_id || '');

                        return (
                          <div
                            key={cours.id}
                            className="p-2.5 rounded-xl border bg-surface-container-lowest border-outline-variant/80 hover:border-primary shadow-2xs hover:shadow-xs transition-all group relative cursor-pointer"
                            onClick={() => {
                              setSelectedItemForView(cours);
                              setIsDetailModalOpen(true);
                            }}
                          >
                            {/* Heure et Type */}
                            <div className="flex items-center justify-between mb-1.5">
                              <span className="text-[11px] font-bold text-primary">
                                {cours.heure_debut} - {cours.heure_fin}
                              </span>
                              <div className="flex items-center gap-1">
                                <span className={cn(
                                  "px-1.5 py-0.5 rounded text-[10px] font-bold border",
                                  getTypeBadgeColor(cours.type_cours)
                                )}>
                                  {cours.type_cours}
                                </span>
                                {cours.publie ? (
                                  <span className="px-1 py-0.5 rounded text-[9px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-300/60 inline-flex items-center gap-0.5" title="Visible par les étudiants et enseignants">
                                    <Globe className="w-2.5 h-2.5" /> Publié
                                  </span>
                                ) : (
                                  <span className="px-1 py-0.5 rounded text-[9px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300/60 inline-flex items-center gap-0.5" title="Brouillon confidentiel DAC (invisible aux autres)">
                                    <Lock className="w-2.5 h-2.5" /> Brouillon
                                  </span>
                                )}
                              </div>
                            </div>

                            {/* Matière */}
                            <h4 className="font-bold text-xs text-on-surface line-clamp-2 leading-tight">
                              {mat?.nom || 'Matière'}
                            </h4>
                            <span className="text-[10px] text-on-surface-variant font-mono">
                              {mat?.code}
                            </span>

                            {/* Classe (si vue Toutes les classes) */}
                            {selectedClasseId === 'all' && (
                              <div className="mt-1 text-[10px] font-semibold text-primary/80 truncate">
                                {cl?.nom}
                              </div>
                            )}

                            {/* Salle et Professeur */}
                            <div className="mt-2 pt-1.5 border-t border-outline-variant/50 flex flex-col gap-0.5 text-[10px] text-on-surface-variant">
                              <div className="flex items-center gap-1">
                                <MapPin className="w-3 h-3 text-on-surface-variant shrink-0" />
                                <span className="font-medium truncate">{cours.salle}</span>
                              </div>
                              {prof && (
                                <div className="flex items-center gap-1">
                                  <User className="w-3 h-3 text-on-surface-variant shrink-0" />
                                  <span className="truncate">{prof.nom} {prof.prenom}</span>
                                </div>
                              )}
                            </div>

                            {/* Actions rapides au hover */}
                            <div className="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5 bg-surface-container-lowest/90 rounded-md p-0.5 shadow-xs">
                              <button
                                onClick={(e) => {
                                  e.stopPropagation();
                                  handleOpenEdit(cours);
                                }}
                                className="p-1 rounded text-on-surface-variant hover:text-amber-600"
                                title="Modifier"
                              >
                                <Edit2 className="w-3 h-3" />
                              </button>
                              <button
                                onClick={(e) => {
                                  e.stopPropagation();
                                  handleDelete(cours.id, `${cours.jour_semaine} ${cours.heure_debut}`);
                                }}
                                className="p-1 rounded text-on-surface-variant hover:text-rose-600"
                                title="Supprimer"
                              >
                                <Trash2 className="w-3 h-3" />
                              </button>
                            </div>
                          </div>
                        );
                      })
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ======================= VUE 2 : TABLEAU DÉTAILLÉ ======================= */}
      {activeView === 'liste' && (
        <div className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="bg-surface-container border-b border-outline-variant text-on-surface-variant font-semibold uppercase tracking-wider">
                  <th className="py-3.5 px-4">Jour</th>
                  <th className="py-3.5 px-4">Horaire</th>
                  <th className="py-3.5 px-4">Classe</th>
                  <th className="py-3.5 px-4">Matière</th>
                  <th className="py-3.5 px-4">Enseignant</th>
                  <th className="py-3.5 px-4">Salle</th>
                  <th className="py-3.5 px-4 text-center">Type</th>
                  <th className="py-3.5 px-4 text-center">Semestre</th>
                  <th className="py-3.5 px-4 text-center">Visibilité</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/60 text-on-surface">
                {filteredCours.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="py-12 text-center text-on-surface-variant">
                      <Clock className="w-10 h-10 mx-auto mb-2 opacity-40" />
                      <p className="font-semibold text-sm">Aucun cours trouvé avec ces critères</p>
                    </td>
                  </tr>
                ) : (
                  filteredCours.map((cours) => {
                    const mat = matiereMap.get(cours.matiere_id);
                    const cl = classeMap.get(cours.classe_id);
                    const prof = enseignantMap.get(cours.enseignant_id || '');

                    return (
                      <tr key={cours.id} className="hover:bg-surface-container-high/40 transition-colors">
                        <td className="py-3.5 px-4">
                          <span className="font-bold text-xs px-2.5 py-1 rounded-full bg-surface-container text-on-surface">
                            {cours.jour_semaine}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 font-bold text-primary">
                          {cours.heure_debut} → {cours.heure_fin}
                        </td>

                        <td className="py-3.5 px-4">
                          <div className="font-semibold text-on-surface">{cl?.nom || 'Classe'}</div>
                          <div className="text-[10px] text-on-surface-variant">{cl?.niveau} - {cl?.filiere}</div>
                        </td>

                        <td className="py-3.5 px-4">
                          <div className="font-bold text-on-surface">{mat?.nom || 'Matière'}</div>
                          <div className="text-[10px] text-on-surface-variant font-mono">{mat?.code} (Coeff: {mat?.coefficient})</div>
                        </td>

                        <td className="py-3.5 px-4">
                          {prof ? (
                            <div>
                              <div className="font-medium text-on-surface">{prof.nom} {prof.prenom}</div>
                              <div className="text-[10px] text-on-surface-variant">{prof.matricule}</div>
                            </div>
                          ) : (
                            <span className="text-on-surface-variant/50 italic">Non assigné</span>
                          )}
                        </td>

                        <td className="py-3.5 px-4">
                          <span className="px-2.5 py-1 rounded-md bg-surface-container border border-outline-variant font-semibold text-[11px] text-on-surface inline-flex items-center gap-1">
                            <MapPin className="w-3 h-3 text-primary" />
                            {cours.salle}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 text-center">
                          <span className={cn(
                            "px-2 py-0.5 rounded text-[11px] font-bold border",
                            getTypeBadgeColor(cours.type_cours)
                          )}>
                            {cours.type_cours}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 text-center">
                          <span className="font-bold text-xs text-on-surface-variant">
                            {cours.semestre}
                          </span>
                        </td>

                        <td className="py-3.5 px-4 text-center">
                          {cours.publie ? (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-300/60 inline-flex items-center gap-1">
                              <Globe className="w-3 h-3" /> Publié
                            </span>
                          ) : (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300/60 inline-flex items-center gap-1">
                              <Lock className="w-3 h-3" /> Brouillon
                            </span>
                          )}
                        </td>

                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              onClick={() => {
                                setSelectedItemForView(cours);
                                setIsDetailModalOpen(true);
                              }}
                              className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-primary transition-colors"
                              title="Voir détails"
                            >
                              <Eye className="w-4 h-4" />
                            </button>
                            <button
                              onClick={() => handleOpenEdit(cours)}
                              className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-amber-600 transition-colors"
                              title="Modifier"
                            >
                              <Edit2 className="w-4 h-4" />
                            </button>
                            <button
                              onClick={() => handleDelete(cours.id, `${cours.jour_semaine} ${cours.heure_debut}`)}
                              className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-rose-600 transition-colors"
                              title="Supprimer"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ======================= VUE 3 : OCCUPATION DES SALLES ======================= */}
      {activeView === 'salles' && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {sallesDisponibles.map((salle) => {
            const coursDeLaSalle = coursList
              .filter(c => c.salle === salle)
              .sort((a, b) => a.jour_semaine.localeCompare(b.jour_semaine) || a.heure_debut.localeCompare(b.heure_debut));

            const totalHeures = coursDeLaSalle.reduce((acc, c) => {
              const [sh, sm] = c.heure_debut.split(':').map(Number);
              const [eh, em] = c.heure_fin.split(':').map(Number);
              return acc + (eh * 60 + em - (sh * 60 + sm)) / 60;
            }, 0);

            return (
              <div
                key={salle}
                className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex flex-col justify-between space-y-4"
              >
                <div>
                  <div className="flex items-center justify-between pb-2 border-b border-outline-variant">
                    <div className="flex items-center gap-2">
                      <div className="p-2 rounded-xl bg-purple-500/10 text-purple-600">
                        <MapPin className="w-5 h-5" />
                      </div>
                      <div>
                        <h3 className="font-bold text-sm text-on-surface">{salle}</h3>
                        <p className="text-[11px] text-on-surface-variant">
                          {coursDeLaSalle.length} créneaux réservés ({totalHeures}h / sem)
                        </p>
                      </div>
                    </div>
                  </div>

                  <div className="mt-3 space-y-1.5 max-h-52 overflow-y-auto custom-scrollbar">
                    {coursDeLaSalle.length === 0 ? (
                      <p className="text-xs text-on-surface-variant/40 italic py-4 text-center">
                        Aucun cours assigné à cette salle (Salle entièrement libre)
                      </p>
                    ) : (
                      coursDeLaSalle.map((cours) => {
                        const mat = matiereMap.get(cours.matiere_id);
                        const cl = classeMap.get(cours.classe_id);
                        return (
                          <div
                            key={cours.id}
                            className="p-2 rounded-lg bg-surface-container-low border border-outline-variant/60 flex items-center justify-between text-xs"
                          >
                            <div>
                              <span className="font-bold text-primary">{cours.jour_semaine}</span>{' '}
                              <span className="font-mono text-[11px] text-on-surface-variant">
                                ({cours.heure_debut} - {cours.heure_fin})
                              </span>
                              <div className="font-medium text-on-surface truncate text-[11px]">
                                {mat?.nom} — <span className="text-on-surface-variant">{cl?.nom}</span>
                              </div>
                            </div>
                            <span className={cn(
                              "px-1.5 py-0.5 rounded text-[10px] font-bold border shrink-0",
                              getTypeBadgeColor(cours.type_cours)
                            )}>
                              {cours.type_cours}
                            </span>
                          </div>
                        );
                      })
                    )}
                  </div>
                </div>

                <button
                  onClick={() => {
                    handleOpenAdd();
                    setFormData(prev => ({ ...prev, salle }));
                  }}
                  className="w-full py-2 rounded-xl border border-outline-variant bg-surface-container text-xs font-semibold text-primary hover:bg-surface-container-highest transition-colors flex items-center justify-center gap-1.5"
                >
                  <Plus className="w-3.5 h-3.5" />
                  Réserver un créneau dans cette salle
                </button>
              </div>
            );
          })}
        </div>
      )}

      {/* ======================= MODALE D'AJOUT / ÉDITION ======================= */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs overflow-y-auto">
          <div className="bg-surface-container-lowest w-full max-w-2xl rounded-2xl border border-outline-variant shadow-2xl my-8 overflow-hidden flex flex-col">
            <div className="px-6 py-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-lg bg-primary/10 text-primary">
                  <Clock className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-on-surface">
                    {editingItem ? 'Modifier le Cours' : 'Programmer un Nouveau Cours'}
                  </h2>
                  <p className="text-xs text-on-surface-variant">
                    Affectation de la classe, matière, enseignant, horaire et salle
                  </p>
                </div>
              </div>
              <button
                onClick={() => setIsModalOpen(false)}
                className="text-on-surface-variant hover:text-on-surface p-1 rounded-lg hover:bg-surface-container"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSave} className="p-6 space-y-4">
              {conflictWarning && (
                <div className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-300 text-xs flex items-start gap-2">
                  <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                  <span>{conflictWarning}</span>
                </div>
              )}

              {/* Classe et Semestre */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Classe *
                  </label>
                  <select
                    required
                    value={formData.classe_id || ''}
                    onChange={(e) => {
                      const cid = e.target.value;
                      const clObj = classes.find(c => c.id === cid);
                      const defSem = getDefaultSemestreForClasse(clObj);
                      setFormData({ ...formData, classe_id: cid, semestre: defSem as any });
                    }}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    <option value="">Sélectionner une classe</option>
                    {classes.map(cl => (
                      <option key={cl.id} value={cl.id}>{cl.nom} ({cl.code})</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Semestre {formData.classe_id && classes.find(c => c.id === formData.classe_id) ? `(${classes.find(c => c.id === formData.classe_id)?.niveau})` : ''}
                  </label>
                  <select
                    value={formData.semestre || 'S1'}
                    onChange={(e) => setFormData({ ...formData, semestre: e.target.value as any })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary font-medium"
                  >
                    {(formData.classe_id ? getSemestresForClasse(classes.find(c => c.id === formData.classe_id)) : TOUS_LES_SEMESTRES).map(s => (
                      <option key={s.value} value={s.value}>
                        {s.label}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Matière et Enseignant */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Matière *
                  </label>
                  <select
                    required
                    value={formData.matiere_id || ''}
                    onChange={(e) => setFormData({ ...formData, matiere_id: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    <option value="">Sélectionner une matière</option>
                    {matieres.map(m => (
                      <option key={m.id} value={m.id}>
                        {m.code} - {m.nom} (Coeff {m.coefficient})
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Enseignant Assigné
                  </label>
                  <select
                    value={formData.enseignant_id || ''}
                    onChange={(e) => setFormData({ ...formData, enseignant_id: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    <option value="">Aucun enseignant spécifié</option>
                    {enseignants.map(p => (
                      <option key={p.id} value={p.id}>
                        {p.nom} {p.prenom} ({p.matricule})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Jour et Type */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Jour de la semaine *
                  </label>
                  <select
                    value={formData.jour_semaine || 'Lundi'}
                    onChange={(e) => setFormData({ ...formData, jour_semaine: e.target.value as any })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    {joursOrdre.map(j => (
                      <option key={j} value={j}>{j}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Type de Cours *
                  </label>
                  <select
                    value={formData.type_cours || 'CM'}
                    onChange={(e) => setFormData({ ...formData, type_cours: e.target.value as any })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    <option value="CM">Cours Magistral (CM)</option>
                    <option value="TD">Travaux Dirigés (TD)</option>
                    <option value="TP">Travaux Pratiques (TP)</option>
                    <option value="Séminaire">Séminaire / Atelier</option>
                    <option value="Évaluation">Évaluation / Rattrapage</option>
                  </select>
                </div>
              </div>

              {/* Horaires et Salle */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Heure de Début *
                  </label>
                  <input
                    type="time"
                    required
                    value={formData.heure_debut || '08:00'}
                    onChange={(e) => setFormData({ ...formData, heure_debut: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Heure de Fin *
                  </label>
                  <input
                    type="time"
                    required
                    value={formData.heure_fin || '10:00'}
                    onChange={(e) => setFormData({ ...formData, heure_fin: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Salle de Cours *
                  </label>
                  <select
                    value={formData.salle || (salles[0]?.nom || 'Amphi A - Campus 1')}
                    onChange={(e) => setFormData({ ...formData, salle: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    {salles && salles.length > 0 ? (
                      salles.map(s => (
                        <option key={s.id} value={s.nom}>
                          {s.nom} ({s.capacite} places - {s.etage || s.type_salle})
                        </option>
                      ))
                    ) : (
                      sallesDisponibles.map(s => (
                        <option key={s} value={s}>{s}</option>
                      ))
                    )}
                  </select>
                  {formData.salle && formData.classe_id && (() => {
                    const salObj = salles.find(s => s.nom === formData.salle);
                    const clObj = classes.find(c => c.id === formData.classe_id);
                    if (salObj && clObj && clObj.capacite_max && clObj.capacite_max > salObj.capacite) {
                      return (
                        <p className="mt-1 text-[11px] text-amber-500 font-medium">
                          ⚠️ Capacité ({salObj.capacite} pl.) &lt; Effectif classe ({clObj.capacite_max} étud.)
                        </p>
                      );
                    }
                    return null;
                  })()}
                </div>
              </div>

              {/* Statut de publication individuelle */}
              <div className="flex items-center gap-3 p-3 rounded-xl bg-surface-container-low border border-outline-variant">
                <input
                  type="checkbox"
                  id="coursPublieCheck"
                  checked={Boolean(formData.publie)}
                  onChange={(e) => setFormData({ ...formData, publie: e.target.checked })}
                  className="w-4 h-4 rounded text-primary focus:ring-primary accent-primary cursor-pointer"
                />
                <label htmlFor="coursPublieCheck" className="text-xs font-semibold text-on-surface cursor-pointer select-none">
                  Séance officiellement publiée (visible par les étudiants et l'enseignant titulaire)
                </label>
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="px-4 py-2.5 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-6 py-2.5 rounded-xl bg-primary text-on-primary text-xs font-semibold hover:bg-primary/90 shadow-sm"
                >
                  {editingItem ? 'Mettre à Jour' : 'Ajouter au Planning'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ======================= MODALE DE CONSULTATION DÉTAILLÉE ======================= */}
      {isDetailModalOpen && selectedItemForView && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-surface-container-lowest w-full max-w-md rounded-2xl border border-outline-variant shadow-2xl overflow-hidden p-6 space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-outline-variant">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-primary/10 text-primary">
                  <Clock className="w-5 h-5" />
                </div>
                <h3 className="font-bold text-base text-on-surface">
                  Détail de la Séance
                </h3>
              </div>
              <button
                onClick={() => setIsDetailModalOpen(false)}
                className="text-on-surface-variant hover:text-on-surface p-1 rounded-lg"
              >
                ✕
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                <span className="text-[10px] text-on-surface-variant font-bold uppercase">Matière</span>
                <p className="text-sm font-bold text-on-surface mt-0.5">
                  {matiereMap.get(selectedItemForView.matiere_id)?.nom}
                </p>
                <p className="text-[11px] text-on-surface-variant font-mono">
                  {matiereMap.get(selectedItemForView.matiere_id)?.code} (Crédits : {matiereMap.get(selectedItemForView.matiere_id)?.credits})
                </p>
              </div>

              <div className="grid grid-cols-2 gap-2.5">
                <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                  <span className="text-[10px] text-on-surface-variant font-bold uppercase">Créneau</span>
                  <p className="text-xs font-bold text-primary mt-0.5">
                    {selectedItemForView.jour_semaine}
                  </p>
                  <p className="text-[11px] text-on-surface">
                    {selectedItemForView.heure_debut} à {selectedItemForView.heure_fin}
                  </p>
                </div>

                <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                  <span className="text-[10px] text-on-surface-variant font-bold uppercase">Salle</span>
                  <p className="text-xs font-bold text-on-surface mt-0.5">
                    {selectedItemForView.salle}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">
                    Type : {selectedItemForView.type_cours}
                  </p>
                </div>
              </div>

              <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                <span className="text-[10px] text-on-surface-variant font-bold uppercase">Classe Bénéficiaire</span>
                <p className="text-xs font-bold text-on-surface mt-0.5">
                  {classeMap.get(selectedItemForView.classe_id)?.nom}
                </p>
                <p className="text-[11px] text-on-surface-variant">
                  {classeMap.get(selectedItemForView.classe_id)?.niveau} - {classeMap.get(selectedItemForView.classe_id)?.filiere}
                </p>
              </div>

              <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                <span className="text-[10px] text-on-surface-variant font-bold uppercase">Enseignant Responsable</span>
                <p className="text-xs font-bold text-on-surface mt-0.5">
                  {enseignantMap.get(selectedItemForView.enseignant_id || '')?.nom || 'Non renseigné'}{' '}
                  {enseignantMap.get(selectedItemForView.enseignant_id || '')?.prenom || ''}
                </p>
                <p className="text-[11px] text-on-surface-variant">
                  {enseignantMap.get(selectedItemForView.enseignant_id || '')?.email || ''}
                </p>
              </div>
            </div>

            <div className="pt-3 border-t border-outline-variant flex items-center justify-end gap-2">
              <button
                onClick={() => {
                  setIsDetailModalOpen(false);
                  handleOpenEdit(selectedItemForView);
                }}
                className="px-4 py-2 rounded-xl bg-primary text-on-primary text-xs font-semibold hover:bg-primary/90"
              >
                Modifier cette séance
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Signatures officielles uniquement visibles à l'impression */}
      <div className="hidden print:grid grid-cols-2 pt-8 text-center text-xs text-gray-900 border-t border-gray-400 mt-6">
        <div>
          <p className="font-bold">{settings.titreSignataire1 || 'Le Secrétaire Général'}</p>
          <div className="h-14 flex items-end justify-center text-gray-400 italic text-[11px]">
            (Cachet & Signature)
          </div>
          {settings.nomSignataire1 && (
            <p className="font-semibold text-gray-800 mt-1">{settings.nomSignataire1}</p>
          )}
        </div>
        <div>
          <p className="font-bold">{settings.titreSignataire2 || 'Le Directeur Académique (DAC)'}</p>
          <div className="h-14 flex items-end justify-center text-gray-400 italic text-[11px]">
            (Cachet & Signature)
          </div>
          {settings.nomSignataire2 && (
            <p className="font-semibold text-gray-800 mt-1">{settings.nomSignataire2}</p>
          )}
        </div>
      </div>
      {settings.mentionBasDePage && (
        <div className="hidden print:block pt-3 text-center text-[9px] text-gray-500">
          {settings.mentionBasDePage}
        </div>
      )}
    </div>
  );
}
export default EmploiDuTempsPage;
