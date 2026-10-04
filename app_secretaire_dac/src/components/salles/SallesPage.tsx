import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Salle, EmploiDuTempsItem } from '../../types';
import {
  DoorOpen,
  Plus,
  Search,
  Filter,
  Edit2,
  Trash2,
  Users,
  CheckCircle2,
  AlertTriangle,
  Clock,
  Layers,
  Sparkles,
  MapPin,
  Cpu,
  Monitor,
  Wifi,
  Wind,
  Video,
  Mic,
  Calendar,
  Building2
} from 'lucide-react';
import { cn } from '../../lib/utils';

export function SallesPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [filterStatut, setFilterStatut] = useState('all');
  const [filterBatiment, setFilterBatiment] = useState('all');
  const [viewMode, setViewMode] = useState<'cartes' | 'liste'>('cartes');

  // Modales
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isOccupationModalOpen, setIsOccupationModalOpen] = useState(false);
  const [editingSalle, setEditingSalle] = useState<Salle | null>(null);
  const [selectedSalleForOccupation, setSelectedSalleForOccupation] = useState<Salle | null>(null);

  // Formulaire salle
  const [formData, setFormData] = useState<Partial<Salle>>({
    nom: '',
    code: '',
    capacite: 40,
    type_salle: 'classe',
    batiment: 'Campus 1 - Bâtiment Principal',
    etage: 'RDC',
    equipements: ['Tableau blanc', 'Climatisation'],
    statut: 'disponible',
    description: ''
  });

  const [equipmentInput, setEquipmentInput] = useState('');

  // Requêtes temps réel depuis Dexie
  const salles = useLiveQuery(() => db.salles.toArray()) || [];
  const coursList = useLiveQuery(() => db.emplois_du_temps.toArray()) || [];
  const classes = useLiveQuery(() => db.classes.toArray()) || [];
  const matieres = useLiveQuery(() => db.matieres.toArray()) || [];

  const classeMap = new Map(classes.map(c => [c.id, c]));
  const matiereMap = new Map(matieres.map(m => [m.id, m]));

  // Liste unique des bâtiments
  const batiments = Array.from(new Set(salles.map(s => s.batiment).filter(Boolean))).sort();

  // Filtrage
  const filteredSalles = salles.filter(s => {
    if (filterType !== 'all' && s.type_salle !== filterType) return false;
    if (filterStatut !== 'all' && s.statut !== filterStatut) return false;
    if (filterBatiment !== 'all' && s.batiment !== filterBatiment) return false;
    if (searchTerm.trim() !== '') {
      const term = searchTerm.toLowerCase();
      const match = `${s.nom} ${s.code || ''} ${s.type_salle} ${s.batiment || ''} ${s.equipements?.join(' ') || ''}`.toLowerCase();
      if (!match.includes(term)) return false;
    }
    return true;
  });

  // KPIs
  const totalSalles = salles.length;
  const disponiblesCount = salles.filter(s => s.statut === 'disponible').length;
  const capaciteTotale = salles.reduce((acc, s) => acc + (Number(s.capacite) || 0), 0);
  const labosCount = salles.filter(s => s.type_salle === 'labo_info' || s.type_salle === 'labo_reseau').length;

  // Ouvrir modal ajout
  const handleOpenAdd = () => {
    setEditingSalle(null);
    setFormData({
      nom: '',
      code: '',
      capacite: 45,
      type_salle: 'classe',
      batiment: 'Campus 1 - Bâtiment Principal',
      etage: 'RDC',
      equipements: ['Tableau blanc', 'Climatisation'],
      statut: 'disponible',
      description: ''
    });
    setEquipmentInput('');
    setIsModalOpen(true);
  };

  // Ouvrir modal édition
  const handleOpenEdit = (salle: Salle) => {
    setEditingSalle(salle);
    setFormData({ ...salle });
    setEquipmentInput('');
    setIsModalOpen(true);
  };

  // Sauvegarder
  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.nom || !formData.capacite) {
      alert('Veuillez renseigner au moins le nom et la capacité de la salle.');
      return;
    }

    try {
      if (editingSalle) {
        const updated: Salle = {
          ...(formData as Salle),
          id: editingSalle.id,
          updated_at: new Date().toISOString()
        };
        await db.salles.put(updated);
        supabase.from('salles').upsert([updated]).then(() => {}, () => {});
        await logAction('Modification Salle', 'Salles', `Salle: ${updated.nom} (${updated.capacite} places)`);
      } else {
        const newId = 'sal_' + Date.now();
        const newRecord: Salle = {
          ...(formData as Salle),
          id: newId,
          created_at: new Date().toISOString()
        };
        await db.salles.add(newRecord);
        supabase.from('salles').insert([newRecord]).then(() => {}, () => {});
        await logAction('Création Salle', 'Salles', `Nouvelle salle: ${newRecord.nom} (${newRecord.capacite} places)`);
      }
      setIsModalOpen(false);
    } catch (err: any) {
      alert('Erreur: ' + err.message);
    }
  };

  // Supprimer
  const handleDelete = async (id: string, nom: string) => {
    const isUsed = coursList.some(c => c.salle === nom);
    if (isUsed) {
      const confirmForce = confirm(
        `Cette salle (${nom}) est actuellement référencée dans des emplois du temps. Voulez-vous vraiment la supprimer ?`
      );
      if (!confirmForce) return;
    } else {
      if (!confirm(`Supprimer définitivement la salle "${nom}" ?`)) return;
    }

    await db.salles.delete(id);
    supabase.from('salles').delete().eq('id', id).then(() => {}, () => {});
    await logAction('Suppression Salle', 'Salles', nom);
  };

  // Gérer équipements
  const handleAddEquipment = (item: string) => {
    if (!item.trim()) return;
    const current = formData.equipements || [];
    if (!current.includes(item.trim())) {
      setFormData({ ...formData, equipements: [...current, item.trim()] });
    }
    setEquipmentInput('');
  };

  const handleRemoveEquipment = (index: number) => {
    const current = [...(formData.equipements || [])];
    current.splice(index, 1);
    setFormData({ ...formData, equipements: current });
  };

  // Badges type
  const getTypeBadge = (type: string) => {
    switch (type) {
      case 'amphi':
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-500/15 text-blue-700 dark:text-blue-300 border border-blue-500/30">Amphithéâtre</span>;
      case 'labo_info':
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-500/30">Labo Informatique</span>;
      case 'labo_reseau':
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-pink-500/15 text-pink-700 dark:text-pink-300 border border-pink-500/30">Labo Réseaux</span>;
      case 'classe':
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">Salle de Cours</span>;
      case 'salle_reunion':
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30">Réunions</span>;
      default:
        return <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-surface-container text-on-surface">Autre</span>;
    }
  };

  // Badges statut
  const getStatutBadge = (statut: string) => {
    switch (statut) {
      case 'disponible':
        return (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-600 border border-emerald-500/30">
            <CheckCircle2 className="w-3 h-3" />
            Disponible
          </span>
        );
      case 'occupee':
        return (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-600 border border-rose-500/30">
            <Clock className="w-3 h-3" />
            Occupée
          </span>
        );
      case 'maintenance':
        return (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-600 border border-amber-500/30">
            <AlertTriangle className="w-3 h-3" />
            Maintenance
          </span>
        );
      case 'reservee':
        return (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/15 text-blue-600 border border-blue-500/30">
            <Calendar className="w-3 h-3" />
            Réservée
          </span>
        );
      default:
        return <span className="text-[10px]">{statut}</span>;
    }
  };

  return (
    <div className="space-y-6">
      {/* En-tête Principal */}
      <div className="bg-surface-container-low p-6 rounded-2xl border border-outline-variant flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <DoorOpen className="w-7 h-7" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-on-surface">
                Gestion des Salles de Classe & Laboratoires
              </h1>
              <p className="text-sm text-on-surface-variant">
                Répertoire centralisé, capacités d'accueil, équipements et affectations dans l'emploi du temps
              </p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-on-surface-variant uppercase tracking-wider">Total Salles</p>
            <h3 className="text-2xl font-bold text-on-surface mt-1">{totalSalles}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Enregistrées en base de données</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
            <Building2 className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Salles Disponibles</p>
            <h3 className="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{disponiblesCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Prêtes pour les cours</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
            <CheckCircle2 className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wider">Capacité Totale</p>
            <h3 className="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{capaciteTotale}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Places assises sur le campus</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center">
            <Users className="w-6 h-6" />
          </div>
        </div>

        <div className="bg-surface-container-lowest p-5 rounded-2xl border border-outline-variant shadow-xs flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-purple-600 dark:text-purple-400 uppercase tracking-wider">Laboratoires Spécialisés</p>
            <h3 className="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-1">{labosCount}</h3>
            <p className="text-xs text-on-surface-variant mt-1">Info, Réseaux & Systèmes</p>
          </div>
          <div className="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center">
            <Cpu className="w-6 h-6" />
          </div>
        </div>
      </div>

      {/* Barre de recherche et filtres */}
      <div className="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant shadow-xs space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-center p-1 bg-surface-container rounded-xl border border-outline-variant">
            <button
              onClick={() => setViewMode('cartes')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                viewMode === 'cartes'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <DoorOpen className="w-4 h-4" />
              <span>Vue Cartes</span>
            </button>
            <button
              onClick={() => setViewMode('liste')}
              className={cn(
                "flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold transition-all",
                viewMode === 'liste'
                  ? "bg-surface-container-lowest text-primary shadow-xs font-bold"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              <Layers className="w-4 h-4" />
              <span>Vue Tableau</span>
            </button>
          </div>

          <div className="relative flex-1 max-w-xs">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher une salle, équipement..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-4 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3 pt-2 border-t border-outline-variant">
          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold">
            <Filter className="w-3.5 h-3.5 text-primary" />
            <span>Type :</span>
          </div>
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Tous les types</option>
            <option value="amphi">Amphithéâtre</option>
            <option value="classe">Salle de cours</option>
            <option value="labo_info">Laboratoire informatique</option>
            <option value="labo_reseau">Laboratoire réseaux</option>
            <option value="salle_reunion">Salle de réunion</option>
          </select>

          <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold ml-2">
            <span>Statut :</span>
          </div>
          <select
            value={filterStatut}
            onChange={(e) => setFilterStatut(e.target.value)}
            className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
          >
            <option value="all">Tous les statuts</option>
            <option value="disponible">Disponible</option>
            <option value="occupee">Occupée</option>
            <option value="maintenance">Maintenance</option>
            <option value="reservee">Réservée</option>
          </select>

          {batiments.length > 0 && (
            <>
              <div className="flex items-center gap-1.5 text-xs text-on-surface-variant font-semibold ml-2">
                <span>Bâtiment :</span>
              </div>
              <select
                value={filterBatiment}
                onChange={(e) => setFilterBatiment(e.target.value)}
                className="px-3 py-1.5 rounded-lg bg-surface-container border border-outline-variant text-xs text-on-surface focus:outline-hidden"
              >
                <option value="all">Tous les bâtiments</option>
                {batiments.map(b => (
                  <option key={b} value={b}>{b}</option>
                ))}
              </select>
            </>
          )}

          <div className="ml-auto text-xs font-semibold text-on-surface-variant">
            {filteredSalles.length} salle(s) trouvée(s)
          </div>
        </div>
      </div>

      {/* ======================= VUE 1 : CARTES ======================= */}
      {viewMode === 'cartes' && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {filteredSalles.length === 0 ? (
            <div className="col-span-full bg-surface-container-lowest p-12 text-center rounded-2xl border border-outline-variant text-on-surface-variant">
              <DoorOpen className="w-12 h-12 mx-auto mb-2 opacity-40" />
              <p className="font-bold text-sm">Aucune salle trouvée</p>
              <p className="text-xs mt-1">Créez une nouvelle salle pour l'affecter aux emplois du temps</p>
            </div>
          ) : (
            filteredSalles.map((salle) => {
              const coursAssocies = coursList.filter(c => c.salle === salle.nom);

              return (
                <div
                  key={salle.id}
                  className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs hover:border-primary/50 transition-all flex flex-col justify-between p-5 space-y-4"
                >
                  <div className="space-y-3">
                    {/* Header Carte */}
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <div className="flex items-center gap-2">
                          <h3 className="font-bold text-base text-on-surface">
                            {salle.nom}
                          </h3>
                          {salle.code && (
                            <span className="font-mono text-[10px] font-bold px-1.5 py-0.5 rounded bg-surface-container text-on-surface-variant">
                              {salle.code}
                            </span>
                          )}
                        </div>
                        <p className="text-xs text-on-surface-variant mt-0.5 flex items-center gap-1">
                          <MapPin className="w-3 h-3 text-primary shrink-0" />
                          <span>{salle.batiment || 'Campus 1'} • {salle.etage || 'RDC'}</span>
                        </p>
                      </div>

                      {getStatutBadge(salle.statut)}
                    </div>

                    {/* Type & Capacité */}
                    <div className="flex items-center justify-between pt-1">
                      {getTypeBadge(salle.type_salle)}
                      <div className="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                        <Users className="w-4 h-4 text-primary" />
                        <span>{salle.capacite} places</span>
                      </div>
                    </div>

                    {/* Équipements */}
                    {salle.equipements && salle.equipements.length > 0 && (
                      <div className="pt-2 border-t border-outline-variant/60">
                        <span className="text-[10px] uppercase font-bold text-on-surface-variant tracking-wider block mb-1.5">
                          Équipements :
                        </span>
                        <div className="flex flex-wrap gap-1">
                          {salle.equipements.map((eq, i) => (
                            <span
                              key={i}
                              className="px-2 py-0.5 rounded-md bg-surface-container text-on-surface-variant text-[11px] font-medium"
                            >
                              {eq}
                            </span>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Cours Hebdo */}
                    <div className="p-2.5 rounded-xl bg-surface-container-low border border-outline-variant/60 flex items-center justify-between text-xs">
                      <span className="text-on-surface-variant">Charge hebdomadaire :</span>
                      <span className="font-bold text-primary">
                        {coursAssocies.length} cours programmés
                      </span>
                    </div>
                  </div>

                  {/* Boutons actions */}
                  <div className="pt-3 border-t border-outline-variant flex items-center justify-between gap-2">
                    <button
                      onClick={() => {
                        setSelectedSalleForOccupation(salle);
                        setIsOccupationModalOpen(true);
                      }}
                      className="px-3 py-1.5 rounded-xl border border-outline-variant bg-surface-container text-xs font-semibold text-on-surface hover:bg-surface-container-highest flex items-center gap-1.5 transition-colors"
                    >
                      <Calendar className="w-3.5 h-3.5 text-primary" />
                      <span>Planning d'occupation</span>
                    </button>
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}

      {/* ======================= VUE 2 : TABLEAU DÉTAILLÉ ======================= */}
      {viewMode === 'liste' && (
        <div className="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-outline-variant bg-surface-container text-on-surface-variant font-semibold text-xs">
                  <th className="py-3.5 px-4">Salle &amp; Code</th>
                  <th className="py-3.5 px-4">Type</th>
                  <th className="py-3.5 px-4">Capacité</th>
                  <th className="py-3.5 px-4">Bâtiment / Étage</th>
                  <th className="py-3.5 px-4">Équipements</th>
                  <th className="py-3.5 px-4 text-center">Statut</th>
                  <th className="py-3.5 px-4 text-center">Occupation</th>
                  <th className="py-3.5 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant text-xs text-on-surface">
                {filteredSalles.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-on-surface-variant">
                      <DoorOpen className="w-10 h-10 mx-auto mb-2 opacity-40" />
                      <p className="font-bold text-sm">Aucune salle trouvée</p>
                    </td>
                  </tr>
                ) : (
                  filteredSalles.map((salle) => {
                    const coursAssocies = coursList.filter(c => c.salle === salle.nom);

                    return (
                      <tr key={salle.id} className="hover:bg-surface-container-high/40 transition-colors">
                        <td className="py-3.5 px-4">
                          <div className="font-bold text-sm text-on-surface">{salle.nom}</div>
                          {salle.code && (
                            <span className="font-mono text-[10px] text-on-surface-variant">{salle.code}</span>
                          )}
                        </td>

                        <td className="py-3.5 px-4">
                          {getTypeBadge(salle.type_salle)}
                        </td>

                        <td className="py-3.5 px-4 font-bold text-primary">
                          {salle.capacite} places
                        </td>

                        <td className="py-3.5 px-4 text-on-surface-variant">
                          <div>{salle.batiment || 'Campus 1'}</div>
                          <div className="text-[10px]">{salle.etage || 'RDC'}</div>
                        </td>

                        <td className="py-3.5 px-4 max-w-xs">
                          <div className="flex flex-wrap gap-1">
                            {salle.equipements?.slice(0, 3).map((eq, i) => (
                              <span key={i} className="px-1.5 py-0.5 rounded bg-surface-container text-[10px]">
                                {eq}
                              </span>
                            ))}
                            {(salle.equipements?.length || 0) > 3 && (
                              <span className="text-[10px] text-on-surface-variant">
                                +{(salle.equipements?.length || 0) - 3}
                              </span>
                            )}
                          </div>
                        </td>

                        <td className="py-3.5 px-4 text-center">
                          {getStatutBadge(salle.statut)}
                        </td>

                        <td className="py-3.5 px-4 text-center font-bold text-primary">
                          {coursAssocies.length} cours
                        </td>

                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              onClick={() => {
                                setSelectedSalleForOccupation(salle);
                                setIsOccupationModalOpen(true);
                              }}
                              className="p-1.5 rounded-lg hover:bg-surface-container text-on-surface-variant hover:text-primary transition-colors"
                              title="Voir planning d'occupation"
                            >
                              <Calendar className="w-4 h-4" />
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

      {/* ======================= MODALE D'AJOUT / MODIFICATION SALLE ======================= */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs overflow-y-auto">
          <div className="bg-surface-container-lowest w-full max-w-xl rounded-2xl border border-outline-variant shadow-2xl my-8 overflow-hidden flex flex-col">
            <div className="px-6 py-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-lg bg-primary/10 text-primary">
                  <DoorOpen className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-on-surface">
                    {editingSalle ? 'Modifier la Salle' : 'Ajouter une Nouvelle Salle'}
                  </h2>
                  <p className="text-xs text-on-surface-variant">Enregistrement dans la base de données académique</p>
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
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Nom de la Salle *
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="Ex: Amphi A, Labo Info 1..."
                    value={formData.nom || ''}
                    onChange={(e) => setFormData({ ...formData, nom: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Code / Numéro Salle
                  </label>
                  <input
                    type="text"
                    placeholder="Ex: AMP-A, LAB-01..."
                    value={formData.code || ''}
                    onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Capacité (Places assises) *
                  </label>
                  <input
                    type="number"
                    required
                    min="1"
                    value={formData.capacite || 40}
                    onChange={(e) => setFormData({ ...formData, capacite: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Type de Salle *
                  </label>
                  <select
                    value={formData.type_salle || 'classe'}
                    onChange={(e) => setFormData({ ...formData, type_salle: e.target.value as any })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  >
                    <option value="amphi">Amphithéâtre</option>
                    <option value="classe">Salle de cours standard</option>
                    <option value="labo_info">Laboratoire informatique</option>
                    <option value="labo_reseau">Laboratoire réseaux / télécoms</option>
                    <option value="salle_reunion">Salle de réunion / Séminaire</option>
                    <option value="autre">Autre</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Bâtiment / Campus
                  </label>
                  <input
                    type="text"
                    placeholder="Ex: Campus 1 - Bâtiment Principal"
                    value={formData.batiment || ''}
                    onChange={(e) => setFormData({ ...formData, batiment: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-on-surface mb-1">
                    Étage
                  </label>
                  <input
                    type="text"
                    placeholder="Ex: RDC, 1er Étage..."
                    value={formData.etage || ''}
                    onChange={(e) => setFormData({ ...formData, etage: e.target.value })}
                    className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-on-surface mb-1">
                  Statut de Disponibilité
                </label>
                <select
                  value={formData.statut || 'disponible'}
                  onChange={(e) => setFormData({ ...formData, statut: e.target.value as any })}
                  className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                >
                  <option value="disponible">Disponible pour les cours</option>
                  <option value="occupee">Occupée</option>
                  <option value="maintenance">En travaux / Maintenance</option>
                  <option value="reservee">Réservée</option>
                </select>
              </div>

              {/* Équipements */}
              <div>
                <label className="block text-xs font-semibold text-on-surface mb-1">
                  Équipements inclus
                </label>
                <div className="flex items-center gap-2 mb-2">
                  <input
                    type="text"
                    placeholder="Ajouter un équipement (ex: Vidéoprojecteur, 35 PC...)"
                    value={equipmentInput}
                    onChange={(e) => setEquipmentInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        handleAddEquipment(equipmentInput);
                      }
                    }}
                    className="flex-1 px-3 py-1.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface"
                  />
                  <button
                    type="button"
                    onClick={() => handleAddEquipment(equipmentInput)}
                    className="px-3 py-1.5 rounded-xl bg-secondary text-on-secondary text-xs font-semibold"
                  >
                    + Ajouter
                  </button>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  {formData.equipements?.map((eq, i) => (
                    <span
                      key={i}
                      className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-surface-container border border-outline-variant text-xs font-medium"
                    >
                      <span>{eq}</span>
                      <button
                        type="button"
                        onClick={() => handleRemoveEquipment(i)}
                        className="text-rose-500 hover:text-rose-700 font-bold"
                      >
                        ✕
                      </button>
                    </span>
                  ))}
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-on-surface mb-1">
                  Description / Remarques
                </label>
                <textarea
                  rows={2}
                  placeholder="Informations supplémentaires..."
                  value={formData.description || ''}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-outline-variant">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-6 py-2 rounded-xl bg-primary text-on-primary text-xs font-semibold hover:bg-primary/90 shadow-sm"
                >
                  {editingSalle ? 'Mettre à jour' : 'Créer la Salle'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ======================= MODALE PLANNING D'OCCUPATION DE LA SALLE ======================= */}
      {isOccupationModalOpen && selectedSalleForOccupation && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
          <div className="bg-surface-container-lowest w-full max-w-2xl rounded-2xl border border-outline-variant shadow-2xl overflow-hidden p-6 space-y-4 max-h-[85vh] flex flex-col">
            <div className="flex items-center justify-between pb-3 border-b border-outline-variant">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-primary/10 text-primary">
                  <Calendar className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-base text-on-surface">
                    Planning d'Occupation : {selectedSalleForOccupation.nom}
                  </h3>
                  <p className="text-xs text-on-surface-variant">
                    {selectedSalleForOccupation.capacite} places • {selectedSalleForOccupation.batiment}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setIsOccupationModalOpen(false)}
                className="text-on-surface-variant hover:text-on-surface p-1 rounded-lg"
              >
                ✕
              </button>
            </div>

            <div className="flex-1 overflow-y-auto space-y-2 text-xs custom-scrollbar">
              {(() => {
                const coursDeLaSalle = coursList.filter(c => c.salle === selectedSalleForOccupation.nom);
                if (coursDeLaSalle.length === 0) {
                  return (
                    <div className="py-12 text-center text-on-surface-variant">
                      <CheckCircle2 className="w-8 h-8 mx-auto text-emerald-500 mb-1" />
                      <p className="font-bold">Cette salle est entièrement disponible</p>
                      <p className="text-xs mt-0.5">Aucun cours n'est actuellement programmé dans cette salle.</p>
                    </div>
                  );
                }

                return coursDeLaSalle.map((cours) => {
                  const cl = classeMap.get(cours.classe_id);
                  const mat = matiereMap.get(cours.matiere_id);

                  return (
                    <div
                      key={cours.id}
                      className="p-3 rounded-xl bg-surface-container-low border border-outline-variant/60 flex items-center justify-between"
                    >
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="font-bold text-primary">{cours.jour_semaine}</span>
                          <span className="font-mono text-[11px] font-bold text-on-surface">
                            {cours.heure_debut} à {cours.heure_fin}
                          </span>
                        </div>
                        <p className="font-medium text-on-surface mt-0.5">
                          {mat?.nom} ({mat?.code})
                        </p>
                        <p className="text-[11px] text-on-surface-variant">
                          Classe : {cl?.nom} ({cours.type_cours})
                        </p>
                      </div>

                      <span className="px-2 py-0.5 rounded-full bg-primary/10 text-primary font-bold text-[10px]">
                        {cours.semestre}
                      </span>
                    </div>
                  );
                });
              })()}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default SallesPage;
