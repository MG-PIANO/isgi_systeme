import React, { useState, useEffect } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, logAction, pushItemToSupabase, deleteItemFromSupabase, setPublicationDAC } from '../../db/db';
import type { CalendrierAcademique, EvenementSpecial } from '../../types';
import { exporterCalendrierPDF } from '../../utils/academicPdfExport';
import { TOUS_LES_SEMESTRES, formatSemestreLabel } from '../../utils/academicSemestres';
import {
  CalendarDays,
  Plus,
  Search,
  Filter,
  Eye,
  Edit2,
  Trash2,
  Printer,
  Download,
  Clock,
  CheckCircle2,
  AlertCircle,
  PlayCircle,
  Calendar as CalendarIcon,
  ChevronLeft,
  ChevronRight,
  Sparkles,
  BookOpen,
  FileCheck,
  GraduationCap,
  Briefcase,
  Layers,
  HelpCircle,
  Share2
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function CalendrierPage() {
  const [activeView, setActiveView] = useState<'liste' | 'calendrier' | 'timeline'>('liste');
  const [filterAnnee, setFilterAnnee] = useState<string>('all');
  const [filterSemestre, setFilterSemestre] = useState<string>('all');
  const [filterStatut, setFilterStatut] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState<string>('');

  // Modales
  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [isViewModalOpen, setIsViewModalOpen] = useState<boolean>(false);
  const [editingCal, setEditingCal] = useState<CalendrierAcademique | null>(null);
  const [selectedCalForView, setSelectedCalForView] = useState<CalendrierAcademique | null>(null);

  // Vue calendrier mensuel
  const [currentDate, setCurrentDate] = useState<Date>(new Date());

  // Paramètres personnalisés (signatures, nom de l'école)
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

  // Récupération des données en temps réel depuis Dexie
  const calendriers = useLiveQuery(() => db.calendriers_academiques.toArray()) || [];

  // Formulaire d'ajout / édition
  const [formData, setFormData] = useState<Partial<CalendrierAcademique>>({
    annee_academique: '2025-2026',
    semestre: 'S1',
    type_rentree: 'Octobre (Principale)',
    date_debut_cours: '',
    date_fin_cours: '',
    date_debut_dst: '',
    date_fin_dst: '',
    date_debut_recherche: '',
    date_fin_recherche: '',
    date_debut_conge_etude: '',
    date_fin_conge_etude: '',
    date_debut_examens: '',
    date_fin_examens: '',
    date_reprise_cours: '',
    date_debut_stage: '',
    date_fin_stage: '',
    date_debut_rattrapage: '',
    date_fin_rattrapage: '',
    statut: 'planifie',
    publie: true,
    observations: '',
    evenements_speciaux: []
  });

  const [newEvent, setNewEvent] = useState<EvenementSpecial>({
    titre: '',
    date: '',
    type: 'fete',
    description: ''
  });

  // Liste unique des années
  const annees = Array.from(new Set(calendriers.map(c => c.annee_academique))).sort();

  // Filtrage
  const filteredCalendriers = calendriers.filter(cal => {
    if (filterAnnee !== 'all' && cal.annee_academique !== filterAnnee) return false;
    if (filterSemestre !== 'all' && cal.semestre !== filterSemestre) return false;
    if (filterStatut !== 'all' && cal.statut !== filterStatut) return false;
    if (searchTerm.trim() !== '') {
      const term = searchTerm.toLowerCase();
      const matchText = `${cal.annee_academique} ${cal.semestre} ${cal.type_rentree} ${cal.observations || ''}`.toLowerCase();
      if (!matchText.includes(term)) return false;
    }
    return true;
  });

  // Statistiques KPIs
  const totalCount = calendriers.length;
  const enCoursCount = calendriers.filter(c => c.statut === 'en_cours').length;
  const planifieCount = calendriers.filter(c => c.statut === 'planifie').length;
  const termineCount = calendriers.filter(c => c.statut === 'termine').length;

  // Ouvrir modal ajout
  const handleOpenAdd = () => {
    setEditingCal(null);
    setFormData({
      annee_academique: '2025-2026',
      semestre: 'S1',
      type_rentree: 'Octobre (Principale)',
      date_debut_cours: '',
      date_fin_cours: '',
      date_debut_dst: '',
      date_fin_dst: '',
      date_debut_recherche: '',
      date_fin_recherche: '',
      date_debut_conge_etude: '',
      date_fin_conge_etude: '',
      date_debut_examens: '',
      date_fin_examens: '',
      date_reprise_cours: '',
      date_debut_stage: '',
      date_fin_stage: '',
      date_debut_rattrapage: '',
      date_fin_rattrapage: '',
      statut: 'planifie',
      publie: true,
      observations: '',
      evenements_speciaux: []
    });
    setIsModalOpen(true);
  };

  // Ouvrir modal édition
  const handleOpenEdit = (cal: CalendrierAcademique) => {
    setEditingCal(cal);
    setFormData({ ...cal });
    setIsModalOpen(true);
  };

  // Sauvegarder
  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.annee_academique || !formData.date_debut_cours || !formData.date_fin_cours) {
      alert('Veuillez remplir au moins l’année académique, la date de début et la date de fin des cours.');
      return;
    }

    try {
      if (editingCal) {
        const payload = {
          ...formData,
          updated_at: new Date().toISOString()
        };
        await db.calendriers_academiques.update(editingCal.id, payload);
        await pushItemToSupabase('calendriers_academiques', { id: editingCal.id, ...payload });
        await logAction('Modification Calendrier', 'Calendrier Académique', `${formData.annee_academique} - ${formData.semestre}`);
      } else {
        const newId = 'cal_' + Date.now();
        const newRecord: CalendrierAcademique = {
          ...(formData as CalendrierAcademique),
          id: newId,
          created_at: new Date().toISOString()
        };
        await db.calendriers_academiques.add(newRecord);
        await pushItemToSupabase('calendriers_academiques', newRecord);
        await logAction('Création Calendrier', 'Calendrier Académique', `${newRecord.annee_academique} - ${newRecord.semestre}`);
      }
      setIsModalOpen(false);
    } catch (err: any) {
      alert('Erreur lors de l’enregistrement: ' + err.message);
    }
  };

  // Supprimer
  const handleDelete = async (id: string, label: string) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer le calendrier ${label} ?`)) {
      await db.calendriers_academiques.delete(id);
      await deleteItemFromSupabase('calendriers_academiques', id);
      await logAction('Suppression Calendrier', 'Calendrier Académique', label);
    }
  };

  // Basculer publication
  const togglePublication = async (cal: CalendrierAcademique) => {
    const updated = !cal.publie;
    await db.calendriers_academiques.update(cal.id, { publie: updated });
    await pushItemToSupabase('calendriers_academiques', { ...cal, publie: updated });
    await setPublicationDAC(
      'calendrier',
      updated,
      cal.id,
      cal.semestre,
      cal.annee_academique,
      `Calendrier Académique ${cal.annee_academique} (${cal.semestre})`
    );
    await logAction(
      updated ? 'Publication Calendrier (Accessible à tous)' : 'Dépublication Calendrier',
      'Calendrier Académique',
      `${cal.annee_academique} - ${cal.semestre}`
    );
  };

  // Ajouter événement spécial au formulaire
  const handleAddSpecialEvent = () => {
    if (!newEvent.titre || !newEvent.date) return;
    const currentEvents = formData.evenements_speciaux || [];
    setFormData({
      ...formData,
      evenements_speciaux: [...currentEvents, { ...newEvent, id: 'ev_' + Date.now() }]
    });
    setNewEvent({ titre: '', date: '', type: 'fete', description: '' });
  };

  const handleRemoveSpecialEvent = (index: number) => {
    const currentEvents = [...(formData.evenements_speciaux || [])];
    currentEvents.splice(index, 1);
    setFormData({ ...formData, evenements_speciaux: currentEvents });
  };

  // Formatage des dates
  const formatDateFr = (dateStr?: string) => {
    if (!dateStr) return '—';
    try {
      const parts = dateStr.split('-');
      if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
      }
      const d = new Date(dateStr);
      return d.toLocaleDateString('fr-FR');
    } catch {
      return dateStr;
    }
  };

  // Badges de statut
  const getStatutBadge = (statut: string) => {
    switch (statut) {
      case 'en_cours':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
            <PlayCircle className="w-3.5 h-3.5" />
            En cours
          </span>
        );
      case 'planifie':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
            <Clock className="w-3.5 h-3.5" />
            Planifié
          </span>
        );
      case 'termine':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">
            <CheckCircle2 className="w-3.5 h-3.5" />
            Terminé
          </span>
        );
      case 'annule':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">
            <AlertCircle className="w-3.5 h-3.5" />
            Annulé
          </span>
        );
      default:
        return <span className="text-xs">{statut}</span>;
    }
  };

  // Mois pour vue calendrier
  const monthNames = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
  ];

  const nextMonth = () => {
    setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));
  };

  const prevMonth = () => {
    setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
  };

  // Construction des jours du mois
  const year = currentDate.getFullYear();
  const month = currentDate.getMonth();
  const firstDayIndex = (new Date(year, month, 1).getDay() + 6) % 7; // Lundi = 0
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  // Trouver tous les événements du mois actif
  const getEventsForDay = (day: number) => {
    const dayStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const items: { label: string; color: string; type: string }[] = [];

    calendriers.forEach(cal => {
      // Cours
      if (cal.date_debut_cours && cal.date_fin_cours && dayStr >= cal.date_debut_cours && dayStr <= cal.date_fin_cours) {
        if (dayStr === cal.date_debut_cours) items.push({ label: `Rentrée Cours (${cal.semestre})`, color: 'bg-blue-500 text-white', type: 'cours' });
        else if (dayStr === cal.date_fin_cours) items.push({ label: `Fin Cours (${cal.semestre})`, color: 'bg-blue-600 text-white', type: 'cours' });
      }
      // DST
      if (cal.date_debut_dst && cal.date_fin_dst && dayStr >= cal.date_debut_dst && dayStr <= cal.date_fin_dst) {
        items.push({ label: `Devoirs Sur Table (${cal.semestre})`, color: 'bg-amber-500 text-white', type: 'dst' });
      }
      // Recherche
      if (cal.date_debut_recherche && cal.date_fin_recherche && dayStr >= cal.date_debut_recherche && dayStr <= cal.date_fin_recherche) {
        items.push({ label: `Devoirs Recherche (${cal.semestre})`, color: 'bg-indigo-500 text-white', type: 'recherche' });
      }
      // Congés d'études
      if (cal.date_debut_conge_etude && cal.date_fin_conge_etude && dayStr >= cal.date_debut_conge_etude && dayStr <= cal.date_fin_conge_etude) {
        items.push({ label: `Congé Révision (${cal.semestre})`, color: 'bg-emerald-500 text-white', type: 'conge' });
      }
      // Examens
      if (cal.date_debut_examens && cal.date_fin_examens && dayStr >= cal.date_debut_examens && dayStr <= cal.date_fin_examens) {
        items.push({ label: `Session Examens (${cal.semestre})`, color: 'bg-rose-500 text-white', type: 'examen' });
      }
      // Rattrapages
      if (cal.date_debut_rattrapage && cal.date_fin_rattrapage && dayStr >= cal.date_debut_rattrapage && dayStr <= cal.date_fin_rattrapage) {
        items.push({ label: `Rattrapages (${cal.semestre})`, color: 'bg-purple-500 text-white', type: 'rattrapage' });
      }
      // Stages
      if (cal.date_debut_stage && cal.date_fin_stage && dayStr >= cal.date_debut_stage && dayStr <= cal.date_fin_stage) {
        if (dayStr === cal.date_debut_stage) items.push({ label: `Début Stage (${cal.semestre})`, color: 'bg-slate-700 text-white', type: 'stage' });
      }
      // Événements spéciaux
      cal.evenements_speciaux?.forEach(ev => {
        if (ev.date === dayStr) {
          items.push({ label: ev.titre, color: 'bg-yellow-500 text-slate-900 font-bold', type: 'special' });
        }
      });
    });

    return items;
  };

  return (
    <div className="space-y-6">
      {/* En-tête Principal */}
      <div className="bg-surface-container-low p-6 rounded-2xl border border-outline-variant flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <CalendarDays className="w-7 h-7" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-on-surface">
                Calendrier de l'Année Académique
              </h1>
              <p className="text-sm text-on-surface-variant">
                Gestion des périodes d'enseignement, DST, examens, congés d'études, stages et rattrapages
              </p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            onClick={() => window.print()}
            className="flex items-center gap-2 px-4 py-2.5 rounded-full border border-outline-variant bg-surface-container-lowest text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors shadow-xs"
          >
            <Printer className="w-4 h-4 text-on-surface-variant" />
            <span>Imprimer</span>
          </button>

          <button
            onClick={handleOpenAdd}
            className="flex items-center gap-2 px-5 py-2.5 rounded-full bg-primary text-on-primary text-sm font-semibold hover:bg-primary/90 transition-colors shadow-sm"
          >
            <Plus className="w-4 h-4" />
            <span>Nouveau Calendrier</span>
          </button>
        </div>
      </div>

      {/* Cartes KPIs Statistiques */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-on-surface-variant uppercase tracking-wider">Total Calendriers</p>
            <h3 className="text-2xl font-bold text-on-surface mt-1">{totalCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Sessions et semestres</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
            <Layers className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Semestre En Cours</p>
            <h3 className="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{enCoursCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Actif sur le campus</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
            <PlayCircle className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Planifiés / À Venir</p>
            <h3 className="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{planifieCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Prochaines rentrées</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center">
            <Clock className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wider">Semestres Clôturés</p>
            <h3 className="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{termineCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Délibérations faites</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center">
            <CheckCircle2 className="w-6 h-6" />
          </div>
        </div>
      </div>

      {/* Barre d'Onglets et Filtres */}
      <div className="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant shadow-xs space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          {/* 3 Modes d'affichage */}
          <div className="flex items-center p-1 bg-surface-container rounded-xl border border-outline-variant">
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
              <span>Tableau / Liste</span>
            </button>

            <button
              onClick={() => setActiveView('calendrier')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                activeView === 'calendrier'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <CalendarIcon className="w-4 h-4" />
              <span>Vue Calendrier Mensuel</span>
            </button>

            <button
              onClick={() => setActiveView('timeline')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                activeView === 'timeline'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <Clock className="w-4 h-4" />
              <span>Frise Chronologique</span>
            </button>
          </div>

          {/* Recherche */}
          <div className="relative flex-1 max-w-xs">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-4 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            />
          </div>
        </div>

        {/* Filtres par sélection */}
        <div className="flex flex-wrap items-center gap-3 pt-2 border-t border-outline-variant">
          <div className="flex items-center gap-2 text-xs text-on-surface-variant">
            <Filter className="w-3.5 h-3.5" />
            <span className="font-medium">Filtrer par :</span>
          </div>

          <select
            value={filterAnnee}
            onChange={(e) => setFilterAnnee(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Toutes les années</option>
            {annees.map(a => (
              <option key={a} value={a}>{a}</option>
            ))}
          </select>

          <select
            value={filterSemestre}
            onChange={(e) => setFilterSemestre(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden font-medium"
          >
            <option value="all">Tous les semestres</option>
            {TOUS_LES_SEMESTRES.map(s => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
            <option value="Annuel">Annuel</option>
          </select>

          <select
            value={filterStatut}
            onChange={(e) => setFilterStatut(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Tous les statuts</option>
            <option value="en_cours">En cours</option>
            <option value="planifie">Planifié</option>
            <option value="termine">Terminé</option>
            <option value="annule">Annulé</option>
          </select>

          {(filterAnnee !== 'all' || filterSemestre !== 'all' || filterStatut !== 'all' || searchTerm) && (
            <button
              onClick={() => {
                setFilterAnnee('all');
                setFilterSemestre('all');
                setFilterStatut('all');
                setSearchTerm('');
              }}
              className="text-xs text-primary hover:underline font-medium ml-auto"
            >
              Réinitialiser
            </button>
          )}
        </div>
      </div>

      {/* ======================= VUE 1 : TABLEAU / LISTE ======================= */}
      {activeView === 'liste' && (
        <div className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="bg-surface-container border-b border-outline-variant text-on-surface-variant font-semibold uppercase tracking-wider">
                  <th className="py-3.5 px-4">Année & Semestre</th>
                  <th className="py-3.5 px-4">Type Rentrée</th>
                  <th className="py-3.5 px-4">Période de Cours</th>
                  <th className="py-3.5 px-4">Évaluations & Projets</th>
                  <th className="py-3.5 px-4">Examens & Rattrapages</th>
                  <th className="py-3.5 px-4">Stages</th>
                  <th className="py-3.5 px-4 text-center">Statut</th>
                  <th className="py-3.5 px-4 text-center">Publication</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/60 text-on-surface">
                {filteredCalendriers.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="py-12 text-center text-on-surface-variant">
                      <CalendarDays className="w-10 h-10 mx-auto mb-2 opacity-40" />
                      <p className="font-semibold text-sm">Aucun calendrier académique trouvé</p>
                      <p className="text-xs mt-1">Créez un nouveau calendrier pour commencer la planification</p>
                    </td>
                  </tr>
                ) : (
                  filteredCalendriers.map(cal => (
                    <tr key={cal.id} className="hover:bg-surface-container-high/40 transition-colors">
                      <td className="py-3.5 px-4">
                        <div className="font-bold text-sm text-on-surface">{cal.annee_academique}</div>
                        <div className="inline-flex items-center gap-1 mt-0.5 px-2 py-0.5 rounded-md bg-primary/10 text-primary font-semibold text-[11px]">
                          {cal.semestre}
                        </div>
                      </td>

                      <td className="py-3.5 px-4 font-medium text-on-surface-variant">
                        {cal.type_rentree}
                      </td>

                      <td className="py-3.5 px-4">
                        <div className="font-medium text-on-surface">
                          {formatDateFr(cal.date_debut_cours)} → {formatDateFr(cal.date_fin_cours)}
                        </div>
                        {cal.date_reprise_cours && (
                          <div className="text-[10px] text-on-surface-variant mt-0.5">
                            Reprise : {formatDateFr(cal.date_reprise_cours)}
                          </div>
                        )}
                      </td>

                      <td className="py-3.5 px-4 space-y-0.5">
                        {cal.date_debut_dst && (
                          <div className="flex items-center gap-1 text-[11px]">
                            <span className="font-semibold text-amber-600 dark:text-amber-400">DST :</span>
                            <span>{formatDateFr(cal.date_debut_dst)} - {formatDateFr(cal.date_fin_dst)}</span>
                          </div>
                        )}
                        {cal.date_debut_recherche && (
                          <div className="flex items-center gap-1 text-[11px]">
                            <span className="font-semibold text-indigo-600 dark:text-indigo-400">Recherche :</span>
                            <span>{formatDateFr(cal.date_debut_recherche)} - {formatDateFr(cal.date_fin_recherche)}</span>
                          </div>
                        )}
                        {cal.date_debut_conge_etude && (
                          <div className="flex items-center gap-1 text-[11px]">
                            <span className="font-semibold text-emerald-600 dark:text-emerald-400">Révision :</span>
                            <span>{formatDateFr(cal.date_debut_conge_etude)} - {formatDateFr(cal.date_fin_conge_etude)}</span>
                          </div>
                        )}
                      </td>

                      <td className="py-3.5 px-4 space-y-0.5">
                        {cal.date_debut_examens && (
                          <div className="flex items-center gap-1 text-[11px]">
                            <span className="font-semibold text-rose-600 dark:text-rose-400">Examens :</span>
                            <span>{formatDateFr(cal.date_debut_examens)} - {formatDateFr(cal.date_fin_examens)}</span>
                          </div>
                        )}
                        {cal.date_debut_rattrapage && (
                          <div className="flex items-center gap-1 text-[11px]">
                            <span className="font-semibold text-purple-600 dark:text-purple-400">Rattrapage :</span>
                            <span>{formatDateFr(cal.date_debut_rattrapage)} - {formatDateFr(cal.date_fin_rattrapage)}</span>
                          </div>
                        )}
                      </td>

                      <td className="py-3.5 px-4">
                        {cal.date_debut_stage ? (
                          <span className="text-[11px] font-medium text-on-surface-variant">
                            {formatDateFr(cal.date_debut_stage)} → {formatDateFr(cal.date_fin_stage)}
                          </span>
                        ) : (
                          <span className="text-on-surface-variant/50 italic text-[11px]">Non spécifié</span>
                        )}
                      </td>

                      <td className="py-3.5 px-4 text-center">
                        {getStatutBadge(cal.statut)}
                      </td>

                      <td className="py-3.5 px-4 text-center">
                        <button
                          onClick={() => togglePublication(cal)}
                          title={cal.publie ? "Cliquer pour dépublier" : "Cliquer pour publier"}
                          className={cn(
                            "px-2.5 py-1 rounded-full text-[11px] font-semibold transition-colors inline-flex items-center gap-1",
                            cal.publie
                              ? "bg-emerald-500/15 text-emerald-600 hover:bg-emerald-500/25"
                              : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
                          )}
                        >
                          <span className={cn("w-1.5 h-1.5 rounded-full", cal.publie ? "bg-emerald-500" : "bg-on-surface-variant")} />
                          {cal.publie ? 'Publié' : 'Brouillon'}
                        </button>
                      </td>

                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            onClick={() => {
                              setSelectedCalForView(cal);
                              setIsViewModalOpen(true);
                            }}
                            className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-primary transition-colors"
                            title="Voir la fiche détaillée"
                          >
                            <Eye className="w-4 h-4" />
                          </button>

                          <button
                            onClick={() => exporterCalendrierPDF(cal)}
                            className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-emerald-600 transition-colors"
                            title="Télécharger en PDF officiel"
                          >
                            <Download className="w-4 h-4" />
                          </button>

                          <button
                            onClick={() => handleOpenEdit(cal)}
                            className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-amber-600 transition-colors"
                            title="Modifier"
                          >
                            <Edit2 className="w-4 h-4" />
                          </button>

                          <button
                            onClick={() => handleDelete(cal.id, `${cal.annee_academique} - ${cal.semestre}`)}
                            className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-rose-600 transition-colors"
                            title="Supprimer"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ======================= VUE 2 : CALENDRIER MENSUEL ======================= */}
      {activeView === 'calendrier' && (
        <div className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs p-6 space-y-6">
          {/* Navigation du mois */}
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <h2 className="text-xl font-bold text-on-surface">
                {monthNames[month]} {year}
              </h2>
              <button
                onClick={() => setCurrentDate(new Date())}
                className="px-3 py-1 rounded-full text-xs font-semibold bg-surface-container text-on-surface hover:bg-surface-container-highest transition-colors"
              >
                Aujourd'hui
              </button>
            </div>

            <div className="flex items-center gap-2">
              <button
                onClick={prevMonth}
                className="p-2 rounded-full hover:bg-surface-container text-on-surface transition-colors"
              >
                <ChevronLeft className="w-5 h-5" />
              </button>
              <button
                onClick={nextMonth}
                className="p-2 rounded-full hover:bg-surface-container text-on-surface transition-colors"
              >
                <ChevronRight className="w-5 h-5" />
              </button>
            </div>
          </div>

          {/* Légende rapide */}
          <div className="flex flex-wrap items-center gap-4 text-xs font-medium pt-2 pb-1 border-b border-outline-variant">
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-blue-500" /> Cours</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-amber-500" /> DST</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-indigo-500" /> Recherche</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-emerald-500" /> Congé Révision</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-rose-500" /> Examens</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-purple-500" /> Rattrapages</div>
            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-sm bg-yellow-500" /> Cérémonie / Fête</div>
          </div>

          {/* Grille du calendrier */}
          <div className="grid grid-cols-7 gap-px bg-outline-variant rounded-xl overflow-hidden border border-outline-variant text-xs">
            {['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d) => (
              <div key={d} className="bg-surface-container p-2.5 text-center font-bold text-on-surface-variant">
                {d}
              </div>
            ))}

            {/* Cases vides pour début de mois */}
            {Array.from({ length: firstDayIndex }).map((_, i) => (
              <div key={`empty-${i}`} className="bg-surface-container-low/40 min-h-24 p-2 text-on-surface-variant/30" />
            ))}

            {/* Jours du mois */}
            {Array.from({ length: daysInMonth }).map((_, i) => {
              const day = i + 1;
              const isToday =
                new Date().getDate() === day &&
                new Date().getMonth() === month &&
                new Date().getFullYear() === year;
              const events = getEventsForDay(day);

              return (
                <div
                  key={`day-${day}`}
                  className={cn(
                    "bg-surface-container-lowest min-h-24 p-1.5 flex flex-col justify-between hover:bg-surface-container-high/30 transition-colors",
                    isToday && "bg-primary/5 ring-1 ring-primary inset-0"
                  )}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span
                      className={cn(
                        "w-6 h-6 rounded-full flex items-center justify-center font-bold text-xs",
                        isToday ? "bg-primary text-on-primary" : "text-on-surface"
                      )}
                    >
                      {day}
                    </span>
                    {events.length > 0 && (
                      <span className="text-[10px] font-semibold text-on-surface-variant">
                        {events.length} jalons
                      </span>
                    )}
                  </div>

                  {/* Badges d'événements */}
                  <div className="space-y-1 overflow-y-auto max-h-20 custom-scrollbar">
                    {events.map((ev, idx) => (
                      <div
                        key={idx}
                        className={cn(
                          "px-1.5 py-0.5 rounded text-[10px] truncate leading-tight font-medium shadow-2xs",
                          ev.color
                        )}
                        title={ev.label}
                      >
                        {ev.label}
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ======================= VUE 3 : FRISE CHRONOLOGIQUE (TIMELINE) ======================= */}
      {activeView === 'timeline' && (
        <div className="space-y-6">
          {filteredCalendriers.length === 0 ? (
            <div className="bg-surface-container-lowest p-12 text-center rounded-2xl border border-outline-variant text-on-surface-variant">
              <CalendarDays className="w-10 h-10 mx-auto mb-2 opacity-40" />
              <p className="font-semibold text-sm">Aucun calendrier disponible pour afficher la frise</p>
            </div>
          ) : (
            filteredCalendriers.map((cal) => {
              const timelineSteps = [
                {
                  titre: 'Début des Cours',
                  date: cal.date_debut_cours,
                  icon: BookOpen,
                  color: 'text-blue-500 bg-blue-500/10 border-blue-500',
                  desc: 'Lancement officiel des enseignements et travaux dirigés'
                },
                {
                  titre: 'Devoirs Sur Table (DST)',
                  date: cal.date_debut_dst ? `${formatDateFr(cal.date_debut_dst)} - ${formatDateFr(cal.date_fin_dst)}` : undefined,
                  rawDate: cal.date_debut_dst,
                  icon: FileCheck,
                  color: 'text-amber-500 bg-amber-500/10 border-amber-500',
                  desc: 'Évaluations continues sur table (DST / Contrôles périodiques)'
                },
                {
                  titre: 'Devoirs de Recherche & Projets',
                  date: cal.date_debut_recherche ? `${formatDateFr(cal.date_debut_recherche)} - ${formatDateFr(cal.date_fin_recherche)}` : undefined,
                  rawDate: cal.date_debut_recherche,
                  icon: Sparkles,
                  color: 'text-indigo-500 bg-indigo-500/10 border-indigo-500',
                  desc: 'Remise des devoirs de recherche individuels et projets en équipe'
                },
                {
                  titre: 'Fin des Cours du Semestre',
                  date: cal.date_fin_cours,
                  icon: BookOpen,
                  color: 'text-blue-600 bg-blue-600/10 border-blue-600',
                  desc: 'Achèvement des programmes et modules pédagogiques'
                },
                {
                  titre: 'Congés d’Études & Révisions',
                  date: cal.date_debut_conge_etude ? `${formatDateFr(cal.date_debut_conge_etude)} - ${formatDateFr(cal.date_fin_conge_etude)}` : undefined,
                  rawDate: cal.date_debut_conge_etude,
                  icon: Clock,
                  color: 'text-emerald-500 bg-emerald-500/10 border-emerald-500',
                  desc: 'Semaine banalisée pour révision intensive avant examens'
                },
                {
                  titre: 'Session d’Examens Semestriels',
                  date: cal.date_debut_examens ? `${formatDateFr(cal.date_debut_examens)} - ${formatDateFr(cal.date_fin_examens)}` : undefined,
                  rawDate: cal.date_debut_examens,
                  icon: GraduationCap,
                  color: 'text-rose-500 bg-rose-500/10 border-rose-500',
                  desc: 'Examens finaux et délibération des procès-verbaux'
                },
                {
                  titre: 'Session de Rattrapage',
                  date: cal.date_debut_rattrapage ? `${formatDateFr(cal.date_debut_rattrapage)} - ${formatDateFr(cal.date_fin_rattrapage)}` : undefined,
                  rawDate: cal.date_debut_rattrapage,
                  icon: AlertCircle,
                  color: 'text-purple-500 bg-purple-500/10 border-purple-500',
                  desc: 'Session de rattrapage pour les étudiants ajournés (< 6/20 par matière)'
                },
                {
                  titre: 'Stages en Entreprise & Professionnalisation',
                  date: cal.date_debut_stage ? `${formatDateFr(cal.date_debut_stage)} - ${formatDateFr(cal.date_fin_stage)}` : undefined,
                  rawDate: cal.date_debut_stage,
                  icon: Briefcase,
                  color: 'text-slate-700 bg-slate-700/10 border-slate-700',
                  desc: 'Immersion professionnelle en entreprise et soutenance de stage'
                }
              ].filter(step => step.date);

              return (
                <div key={cal.id} className="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant shadow-xs space-y-6">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-outline-variant gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="text-lg font-bold text-on-surface">
                          {cal.annee_academique} — {cal.semestre} ({cal.type_rentree})
                        </h3>
                        {getStatutBadge(cal.statut)}
                      </div>
                      <p className="text-xs text-on-surface-variant mt-0.5">
                        {cal.observations || 'Frise chronologique des grandes étapes académiques'}
                      </p>
                    </div>

                    <button
                      onClick={() => {
                        setSelectedCalForView(cal);
                        setIsViewModalOpen(true);
                      }}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container text-on-surface self-start transition-colors"
                    >
                      <Eye className="w-3.5 h-3.5 text-primary" />
                      Voir Fiche Complète
                    </button>
                  </div>

                  {/* Frise verticale élégante */}
                  <div className="relative pl-6 space-y-6 border-l-2 border-primary/20 ml-4">
                    {timelineSteps.map((step, idx) => (
                      <div key={idx} className="relative group">
                        {/* Point rond sur la ligne */}
                        <div className={cn(
                          "absolute -left-[31px] top-1 w-6 h-6 rounded-full border-2 bg-surface-container-lowest flex items-center justify-center transition-transform group-hover:scale-110",
                          step.color
                        )}>
                          <step.icon className="w-3 h-3" />
                        </div>

                        {/* Contenu de l'étape */}
                        <div className="bg-surface-container-low/60 hover:bg-surface-container-low p-4 rounded-xl border border-outline-variant/60 transition-colors">
                          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                            <h4 className="font-bold text-sm text-on-surface flex items-center gap-2">
                              {step.titre}
                            </h4>
                            <span className="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-surface-container text-primary">
                              {step.date}
                            </span>
                          </div>
                          <p className="text-xs text-on-surface-variant mt-1.5">
                            {step.desc}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}

      {/* ======================= MODALE 1 : NOUVEAU / MODIFIER CALENDRIER ======================= */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs overflow-y-auto">
          <div className="bg-surface-container-lowest w-full max-w-4xl rounded-2xl border border-outline-variant shadow-2xl my-8 overflow-hidden flex flex-col max-h-[90vh]">
            <div className="px-6 py-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-lg bg-primary/10 text-primary">
                  <CalendarDays className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-on-surface">
                    {editingCal ? 'Modifier le Calendrier Académique' : 'Nouveau Calendrier Académique'}
                  </h2>
                  <p className="text-xs text-on-surface-variant">Renseignez toutes les informations et dates des jalons</p>
                </div>
              </div>
              <button
                onClick={() => setIsModalOpen(false)}
                className="text-on-surface-variant hover:text-on-surface p-1 rounded-lg hover:bg-surface-container"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSave} className="p-6 space-y-6 overflow-y-auto custom-scrollbar flex-1">
              {/* Section 1 : Généralités */}
              <div className="space-y-4">
                <h3 className="text-xs font-bold uppercase tracking-wider text-primary flex items-center gap-1.5">
                  <Layers className="w-4 h-4" /> 1. Informations Générales
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">
                      Année Académique *
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="Ex: 2025-2026"
                      value={formData.annee_academique || ''}
                      onChange={(e) => setFormData({ ...formData, annee_academique: e.target.value })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">
                      Semestre *
                    </label>
                    <select
                      value={formData.semestre || 'S1'}
                      onChange={(e) => setFormData({ ...formData, semestre: e.target.value as any })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary font-medium"
                    >
                      {TOUS_LES_SEMESTRES.map(s => (
                        <option key={s.value} value={s.value}>
                          {s.label}
                        </option>
                      ))}
                      <option value="Annuel">Annuel (Session Complète)</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">
                      Type de Rentrée
                    </label>
                    <input
                      type="text"
                      placeholder="Ex: Octobre (Principale)"
                      value={formData.type_rentree || ''}
                      onChange={(e) => setFormData({ ...formData, type_rentree: e.target.value })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                    />
                  </div>
                </div>
              </div>

              {/* Section 2 : Périodes des cours & examens */}
              <div className="space-y-4">
                <h3 className="text-xs font-bold uppercase tracking-wider text-primary flex items-center gap-1.5">
                  <BookOpen className="w-4 h-4" /> 2. Dates Clés de l'Enseignement
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-surface-container-low border border-outline-variant/60">
                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">
                      Date Début des Cours *
                    </label>
                    <input
                      type="date"
                      required
                      value={formData.date_debut_cours || ''}
                      onChange={(e) => setFormData({ ...formData, date_debut_cours: e.target.value })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">
                      Date Fin des Cours *
                    </label>
                    <input
                      type="date"
                      required
                      value={formData.date_fin_cours || ''}
                      onChange={(e) => setFormData({ ...formData, date_fin_cours: e.target.value })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                    />
                  </div>
                </div>

                {/* DST & Devoirs de recherche */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-amber-600 dark:text-amber-400">Devoirs Sur Table (DST)</p>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Début DST</label>
                        <input
                          type="date"
                          value={formData.date_debut_dst || ''}
                          onChange={(e) => setFormData({ ...formData, date_debut_dst: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Fin DST</label>
                        <input
                          type="date"
                          value={formData.date_fin_dst || ''}
                          onChange={(e) => setFormData({ ...formData, date_fin_dst: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                    </div>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-indigo-600 dark:text-indigo-400">Devoirs de Recherche / Projets</p>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Début Recherche</label>
                        <input
                          type="date"
                          value={formData.date_debut_recherche || ''}
                          onChange={(e) => setFormData({ ...formData, date_debut_recherche: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Fin Recherche</label>
                        <input
                          type="date"
                          value={formData.date_fin_recherche || ''}
                          onChange={(e) => setFormData({ ...formData, date_fin_recherche: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                {/* Congés d'études & Examens */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-emerald-600 dark:text-emerald-400">Congés d’Études / Révisions</p>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Début Congé</label>
                        <input
                          type="date"
                          value={formData.date_debut_conge_etude || ''}
                          onChange={(e) => setFormData({ ...formData, date_debut_conge_etude: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Fin Congé</label>
                        <input
                          type="date"
                          value={formData.date_fin_conge_etude || ''}
                          onChange={(e) => setFormData({ ...formData, date_fin_conge_etude: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                    </div>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-rose-600 dark:text-rose-400">Examens Semestriels</p>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Début Examens</label>
                        <input
                          type="date"
                          value={formData.date_debut_examens || ''}
                          onChange={(e) => setFormData({ ...formData, date_debut_examens: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                      <div>
                        <label className="block text-[11px] text-on-surface-variant mb-1">Fin Examens</label>
                        <input
                          type="date"
                          value={formData.date_fin_examens || ''}
                          onChange={(e) => setFormData({ ...formData, date_fin_examens: e.target.value })}
                          className="w-full px-2.5 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                {/* Rattrapages, Reprise & Stages */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-purple-600 dark:text-purple-400">Rattrapages</p>
                    <div>
                      <label className="block text-[11px] text-on-surface-variant mb-1">Début</label>
                      <input
                        type="date"
                        value={formData.date_debut_rattrapage || ''}
                        onChange={(e) => setFormData({ ...formData, date_debut_rattrapage: e.target.value })}
                        className="w-full px-2 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface mb-2"
                      />
                      <label className="block text-[11px] text-on-surface-variant mb-1">Fin</label>
                      <input
                        type="date"
                        value={formData.date_fin_rattrapage || ''}
                        onChange={(e) => setFormData({ ...formData, date_fin_rattrapage: e.target.value })}
                        className="w-full px-2 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                      />
                    </div>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-primary">Reprise des Cours</p>
                    <div>
                      <label className="block text-[11px] text-on-surface-variant mb-1">Date de Reprise</label>
                      <input
                        type="date"
                        value={formData.date_reprise_cours || ''}
                        onChange={(e) => setFormData({ ...formData, date_reprise_cours: e.target.value })}
                        className="w-full px-2 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                      />
                    </div>
                  </div>

                  <div className="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-2">
                    <p className="text-xs font-bold text-slate-700 dark:text-slate-300">Stages en Entreprise</p>
                    <div>
                      <label className="block text-[11px] text-on-surface-variant mb-1">Début Stage</label>
                      <input
                        type="date"
                        value={formData.date_debut_stage || ''}
                        onChange={(e) => setFormData({ ...formData, date_debut_stage: e.target.value })}
                        className="w-full px-2 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface mb-2"
                      />
                      <label className="block text-[11px] text-on-surface-variant mb-1">Fin Stage</label>
                      <input
                        type="date"
                        value={formData.date_fin_stage || ''}
                        onChange={(e) => setFormData({ ...formData, date_fin_stage: e.target.value })}
                        className="w-full px-2 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs text-on-surface"
                      />
                    </div>
                  </div>
                </div>
              </div>

              {/* Section 3 : Statut, Publication & Remarques */}
              <div className="space-y-4">
                <h3 className="text-xs font-bold uppercase tracking-wider text-primary flex items-center gap-1.5">
                  <CheckCircle2 className="w-4 h-4" /> 3. Statut & Publication
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-on-surface mb-1">Statut</label>
                    <select
                      value={formData.statut || 'planifie'}
                      onChange={(e) => setFormData({ ...formData, statut: e.target.value as any })}
                      className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                    >
                      <option value="planifie">Planifié</option>
                      <option value="en_cours">En cours</option>
                      <option value="termine">Terminé</option>
                      <option value="annule">Annulé</option>
                    </select>
                  </div>

                  <div className="flex items-center gap-3 pt-6">
                    <input
                      type="checkbox"
                      id="publieCheck"
                      checked={formData.publie ?? true}
                      onChange={(e) => setFormData({ ...formData, publie: e.target.checked })}
                      className="w-4 h-4 text-primary rounded border-outline-variant"
                    />
                    <label htmlFor="publieCheck" className="text-xs font-medium text-on-surface cursor-pointer">
                      Publier officiellement ce calendrier aux enseignants et étudiants
                    </label>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Observations / Remarques administratives
                  </label>
                  <textarea
                    rows={2}
                    placeholder="Précisions sur les délibérations, conditions d'accès aux examens..."
                    value={formData.observations || ''}
                    onChange={(e) => setFormData({ ...formData, observations: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>
              </div>

              {/* Section 4 : Événements spéciaux */}
              <div className="space-y-3 pt-2 border-t border-outline-variant">
                <h3 className="text-xs font-bold uppercase tracking-wider text-primary flex items-center gap-1.5">
                  <Sparkles className="w-4 h-4" /> 4. Événements Spéciaux & Cérémonies
                </h3>

                <div className="flex flex-wrap items-center gap-2">
                  <input
                    type="text"
                    placeholder="Titre de l'événement (ex: Hackathon, Gala...)"
                    value={newEvent.titre}
                    onChange={(e) => setNewEvent({ ...newEvent, titre: e.target.value })}
                    className="flex-1 min-w-44 px-3 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs"
                  />
                  <input
                    type="date"
                    value={newEvent.date}
                    onChange={(e) => setNewEvent({ ...newEvent, date: e.target.value })}
                    className="px-3 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs"
                  />
                  <select
                    value={newEvent.type}
                    onChange={(e) => setNewEvent({ ...newEvent, type: e.target.value as any })}
                    className="px-3 py-1.5 bg-surface-container rounded-lg border border-outline-variant text-xs"
                  >
                    <option value="fete">Fête / Congé</option>
                    <option value="ceremonie">Cérémonie / Rentrée</option>
                    <option value="pedagogique">Pédagogique / Hackathon</option>
                    <option value="autre">Autre</option>
                  </select>
                  <button
                    type="button"
                    onClick={handleAddSpecialEvent}
                    className="px-3 py-1.5 rounded-lg bg-secondary text-on-secondary text-xs font-semibold"
                  >
                    + Ajouter
                  </button>
                </div>

                {formData.evenements_speciaux && formData.evenements_speciaux.length > 0 && (
                  <div className="flex flex-wrap gap-2 pt-2">
                    {formData.evenements_speciaux.map((ev, i) => (
                      <span
                        key={i}
                        className="inline-flex items-center gap-2 px-3 py-1 rounded-lg bg-surface-container border border-outline-variant text-xs"
                      >
                        <span className="font-semibold text-primary">{ev.titre}</span>
                        <span className="text-on-surface-variant">({formatDateFr(ev.date)})</span>
                        <button
                          type="button"
                          onClick={() => handleRemoveSpecialEvent(i)}
                          className="text-rose-500 hover:text-rose-700 ml-1 font-bold"
                        >
                          ✕
                        </button>
                      </span>
                    ))}
                  </div>
                )}
              </div>

              {/* Boutons formulaire */}
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
                  Enregistrer le Calendrier
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ======================= MODALE 2 : FICHE OFFICIELLE DÉTAILLÉE ======================= */}
      {isViewModalOpen && selectedCalForView && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-surface-container-lowest w-full max-w-3xl rounded-2xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div className="px-6 py-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low print:border-none">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold">
                  <GraduationCap className="w-6 h-6" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-on-surface">
                    Calendrier Académique Officiel
                  </h2>
                  <p className="text-xs text-on-surface-variant">
                    {selectedCalForView.annee_academique} — {selectedCalForView.semestre} ({selectedCalForView.type_rentree})
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => exporterCalendrierPDF(selectedCalForView)}
                  className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-emerald-600 text-xs font-semibold text-white hover:bg-emerald-700 transition-colors shadow-xs"
                  title="Télécharger au format PDF officiel"
                >
                  <Download className="w-3.5 h-3.5" />
                  Télécharger PDF
                </button>
                <button
                  onClick={() => window.print()}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-container text-xs font-semibold text-on-surface hover:bg-surface-container-highest transition-colors"
                >
                  <Printer className="w-3.5 h-3.5" />
                  Imprimer
                </button>
                <button
                  onClick={() => setIsViewModalOpen(false)}
                  className="text-on-surface-variant hover:text-on-surface p-1 rounded-lg hover:bg-surface-container"
                >
                  ✕
                </button>
              </div>
            </div>

            <div className="p-6 space-y-6 overflow-y-auto custom-scrollbar flex-1 text-xs">
              {/* En-tête institutionnel ISGI officiel avec Logo */}
              <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between pb-4 border-b border-outline-variant gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-14 h-14 rounded-xl bg-surface-container-lowest flex items-center justify-center overflow-hidden border border-outline-variant shadow-xs shrink-0">
                    <img src="./logo.jpg" alt="Logo ISGI" className="w-full h-full object-contain" />
                  </div>
                  <div>
                    {settings.enTeteMessageHaut && (
                      <p className="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mb-0.5">
                        {settings.enTeteMessageHaut}
                      </p>
                    )}
                    <h3 className="font-extrabold text-sm sm:text-base tracking-wide uppercase text-primary leading-tight">
                      {settings.nomEcole || "Institut Supérieur de Gestion et d'Ingénierie"} ({settings.sigle || "ISGI"})
                    </h3>
                    <p className="text-on-surface font-semibold text-xs mt-0.5">
                      {settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"}
                    </p>
                    <p className="text-on-surface-variant text-[11px]">
                      {settings.enTeteSousTitre || "Enseignement Supérieur Technique et Professionnel • Agréé par l'État"}
                    </p>
                  </div>
                </div>

                <div className="sm:text-right shrink-0">
                  <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary/10 text-primary font-bold text-xs">
                    {selectedCalForView.semestre} — {selectedCalForView.annee_academique}
                  </div>
                  <p className="text-[11px] text-on-surface-variant font-medium mt-1">
                    {selectedCalForView.type_rentree || 'Rentrée Principale'}
                  </p>
                </div>
              </div>

              {/* Grille des étapes */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-primary uppercase tracking-wide">Période des Enseignements</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_cours)} au {formatDateFr(selectedCalForView.date_fin_cours)}
                  </p>
                  {selectedCalForView.date_reprise_cours && (
                    <p className="text-[11px] text-on-surface-variant">
                      Reprise officielle des cours : {formatDateFr(selectedCalForView.date_reprise_cours)}
                    </p>
                  )}
                </div>

                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wide">Devoirs Sur Table (DST)</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_dst)} au {formatDateFr(selectedCalForView.date_fin_dst)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Comptant pour le contrôle continu (CC 1)</p>
                </div>

                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">Devoirs de Recherche & Projets</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_recherche)} au {formatDateFr(selectedCalForView.date_fin_recherche)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Travaux pratiques et mini-mémoires (CC 2)</p>
                </div>

                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wide">Congés d’Études / Révisions</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_conge_etude)} au {formatDateFr(selectedCalForView.date_fin_conge_etude)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Suspension des cours pour préparation aux épreuves</p>
                </div>

                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wide">Examens Semestriels</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_examens)} au {formatDateFr(selectedCalForView.date_fin_examens)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Épreuves écrites et pratiques finales</p>
                </div>

                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wide">Session de Rattrapage</p>
                  <p className="font-semibold text-sm text-on-surface">
                    {formatDateFr(selectedCalForView.date_debut_rattrapage)} au {formatDateFr(selectedCalForView.date_fin_rattrapage)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Seconde chance pour les notes inférieures à 06/20</p>
                </div>
              </div>

              {/* Stages */}
              {selectedCalForView.date_debut_stage && (
                <div className="p-4 rounded-xl bg-surface-container-low border border-outline-variant/60 space-y-1">
                  <p className="text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide">Période des Stages en Entreprise</p>
                  <p className="font-semibold text-sm text-on-surface">
                    Du {formatDateFr(selectedCalForView.date_debut_stage)} au {formatDateFr(selectedCalForView.date_fin_stage)}
                  </p>
                  <p className="text-[11px] text-on-surface-variant">Stage obligatoire d'immersion professionnelle et rédaction du rapport</p>
                </div>
              )}

              {/* Événements spéciaux */}
              {selectedCalForView.evenements_speciaux && selectedCalForView.evenements_speciaux.length > 0 && (
                <div className="space-y-2">
                  <p className="font-bold text-xs text-on-surface uppercase tracking-wide">Événements Particuliers</p>
                  <div className="space-y-1.5">
                    {selectedCalForView.evenements_speciaux.map((ev, i) => (
                      <div key={i} className="flex items-center justify-between p-2.5 rounded-lg bg-surface-container border border-outline-variant/60">
                        <span className="font-semibold text-on-surface">{ev.titre}</span>
                        <span className="text-primary font-medium">{formatDateFr(ev.date)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Observations */}
              {selectedCalForView.observations && (
                <div className="p-3.5 rounded-xl bg-surface-container border border-outline-variant/60">
                  <p className="font-semibold text-xs text-on-surface mb-1">Dispositions Particulières :</p>
                  <p className="text-on-surface-variant italic">{selectedCalForView.observations}</p>
                </div>
              )}

              {/* Signatures dynamiques paramétrables */}
              <div className="pt-8 grid grid-cols-2 text-center text-xs text-on-surface">
                <div>
                  <p className="font-bold text-on-surface">{settings.titreSignataire1 || 'Le Secrétaire Général'}</p>
                  <div className="h-14 flex items-end justify-center text-on-surface-variant/40 italic text-[11px]">
                    (Cachet & Signature)
                  </div>
                  {settings.nomSignataire1 && (
                    <p className="font-semibold text-primary mt-1">{settings.nomSignataire1}</p>
                  )}
                </div>
                <div>
                  <p className="font-bold text-on-surface">{settings.titreSignataire2 || 'Le Directeur Académique (DAC)'}</p>
                  <div className="h-14 flex items-end justify-center text-on-surface-variant/40 italic text-[11px]">
                    (Cachet & Signature)
                  </div>
                  {settings.nomSignataire2 && (
                    <p className="font-semibold text-primary mt-1">{settings.nomSignataire2}</p>
                  )}
                </div>
              </div>

              {settings.mentionBasDePage && (
                <div className="pt-4 text-center text-[10px] text-on-surface-variant/70 border-t border-outline-variant/40">
                  {settings.mentionBasDePage}
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
export default CalendrierPage;
