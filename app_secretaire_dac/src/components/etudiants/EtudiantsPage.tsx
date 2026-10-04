import React, { useState, useEffect, useMemo } from 'react';
import {
  Search,
  Filter,
  GraduationCap,
  Edit,
  Trash2,
  Download,
  CreditCard,
  Eye,
  AlertTriangle,
  X,
  CheckCircle2,
  Save,
  User,
  Clock,
  Check,
  FileText,
  FolderCheck,
  FileCheck2
} from 'lucide-react';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Etudiant } from '../../types';
import { exportToPDF } from '../../utils/pdfExport';
import { genererCarteEtudiantPDF } from '../../utils/carteExport';
import * as XLSX from 'xlsx';
import { cn } from '../../lib/utils';

export const DOCUMENT_FIELDS = [
  { id: 'docs_dossier_candidature', key: 'dossier_candidature', label: 'Dossier de candidature dûment rempli' },
  { id: 'docs_acte_naissance', key: 'acte_naissance', label: "Acte de Naissance / Extrait d'acte" },
  { id: 'docs_photos', key: 'photos', label: "02 Photos d'identités" },
  { id: 'docs_cni', key: 'cni', label: 'Photocopie C.N.I / Passeport / NIU' },
  { id: 'docs_rame', key: 'rame', label: '01 Paquet de Rame' },
  { id: 'docs_markers', key: 'markers', label: '01 Paquet de Markers Tableau' },
  { id: 'docs_enveloppe', key: 'enveloppe', label: 'Enveloppe kaki A4 & Chemise Cartonnée' },
  { id: 'docs_diplome', key: 'diplome', label: 'Une copie du dernier diplôme' },
  { id: 'docs_releves_notes', key: 'releves_notes', label: 'Relevés de notes de la dernière classe' },
  { id: 'docs_frais_inscription', key: 'frais_inscription', label: "Frais d'inscription (25.000 F)" },
  { id: 'docs_polo', key: 'polo', label: 'Un polo (5.000 F)' },
  { id: 'docs_carte_etudiant', key: 'carte_etudiant', label: "Carte d'étudiant (5.000 F)" },
  { id: 'docs_frais_stages', key: 'frais_stages', label: 'Frais de stages (35.000 F)' },
  { id: 'docs_frais_examens', key: 'frais_examens', label: "Frais d'examens (20.000 F)" },
  { id: 'docs_frais_tptd', key: 'frais_tptd', label: 'Frais TP/TD (30.000 F)' },
  { id: 'docs_assurance', key: 'assurance', label: 'Assurance (1.500 F)' }
];

export function parseDocumentsPhysiques(docsJson?: string): Record<string, boolean> {
  if (!docsJson) return {};
  try {
    const parsed = JSON.parse(docsJson);
    return typeof parsed === 'object' && parsed !== null ? parsed : {};
  } catch {
    return {};
  }
}

export function getDossierStats(etudiant: Etudiant) {
  const docs = parseDocumentsPhysiques(etudiant.documents_physiques);
  let count = 0;
  DOCUMENT_FIELDS.forEach(f => {
    if (docs[f.key] || docs[f.id]) {
      count++;
    }
  });
  const total = DOCUMENT_FIELDS.length;
  const isComplete = count === total;
  const percentage = Math.round((count / total) * 100);
  return { count, total, isComplete, percentage, docs };
}

const NIVEAUX = ["Licence 1", "Licence 2", "Licence 3", "Master 1", "Master 2"];
const CYCLES = ["Licence", "Master", "Cycle 1", "Cycle 2"];
const NIVEAUX_PAR_CYCLE: Record<string, string[]> = {
  "Licence": ["Licence 1", "Licence 2", "Licence 3"],
  "Master": ["Master 1", "Master 2"],
  "Cycle 1": ["Cycle 1 (Année 1)", "Cycle 1 (Année 2)", "Cycle 1 (Année 3)"],
  "Cycle 2": ["Cycle 2 (Année 1)", "Cycle 2 (Année 2)"]
};
const OPTION_FILIERES: Record<string, string[]> = {
  "Gestion et Administration": [
    "Comptabilité et gestion d'entreprise", "Audit et contrôle de gestion", "Commerce international",
    "Gestion en affaires mondiales", "Marketing et communication d'entreprise", "Gestion des finances",
    "GRH et administration des entreprises", "Entrepreneuriat et leadership international",
    "Droit international des affaires", "Logistique et transport", "Administration publique-privée", "Banque et Assurance"
  ],
  "Droit privé et international": [
    "Droit civil", "Droit commercial et immobilier", "Droit des affaires", "Droit international (commerce international)"
  ],
  "Technologie": [
    "Réseaux Informatiques", "Télécommunications et fibre optique", "Maintenance informatique",
    "Animation 2D, 3D et motion design", "Génie informatique", "Génie logiciel",
    "Robotique et Intelligence Artificielle", "Développement web et mobile", "Programmation",
    "Infographie design graphique", "Audio visuel", "Sécurité Informatique", "Administration des Bases de Données"
  ],
  "Industrie": [
    "Génie électrique et électronique", "Génie mécanique", "Génie civil & Architecture",
    "Maintenance du pétrole et du gaz", "Entretien et réparation des véhicules légers",
    "Entretien et réparation des véhicules Lourds", "Traitement du pétrole et du gaz",
    "Instrumentation pétrolière et gazière", "Opérateur topographe", "QHSE", "Froid et climatisation",
    "Energie nouvelle et renouvelable", "Maintenance et soudure industrielle", "Plomberie industrielle et Sanitaire"
  ]
};
const OPTIONS = Object.keys(OPTION_FILIERES);

export const EtudiantsPage: React.FC = () => {
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [loading, setLoading] = useState(true);

  // Filtres standards
  const [searchTerm, setSearchTerm] = useState('');
  const [vagueFilter, setVagueFilter] = useState('');
  const [niveauFilter, setNiveauFilter] = useState('');
  const [optionFilter, setOptionFilter] = useState('');
  const [filiereFilter, setFiliereFilter] = useState('');
  const [statutFilter, setStatutFilter] = useState('');

  // Filtres Pièces & Documents du dossier
  const [dossierFilter, setDossierFilter] = useState<'' | 'complet' | 'incomplet'>('');
  const [documentFilters, setDocumentFilters] = useState<Record<string, boolean>>({});
  const [docSearchFilter, setDocSearchFilter] = useState('');

  // Modals
  const [selectedEtudiant, setSelectedEtudiant] = useState<Etudiant | null>(null);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [editActiveTab, setEditActiveTab] = useState(0);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [editFormData, setEditFormData] = useState<Partial<Etudiant>>({});
  const [editDocsData, setEditDocsData] = useState<Record<string, boolean>>({});
  const [saving, setSaving] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  useEffect(() => { loadEtudiants(); }, []);

  const loadEtudiants = async () => {
    setLoading(true);
    try {
      const data = await db.etudiants.toArray();
      setEtudiants(data);
    } catch (e) { console.error(e); }
    finally { setLoading(false); }
  };

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  const filieresList = useMemo(() => {
    const list = etudiants.map(e => e.filiere).filter(Boolean);
    return Array.from(new Set(list));
  }, [etudiants]);

  // Statistiques globales des documents et dossiers
  const docCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    DOCUMENT_FIELDS.forEach(f => {
      counts[f.id] = 0;
    });
    let completCount = 0;
    let incompletCount = 0;

    etudiants.forEach(etud => {
      const stats = getDossierStats(etud);
      if (stats.isComplete) completCount++;
      else incompletCount++;

      DOCUMENT_FIELDS.forEach(f => {
        if (stats.docs[f.key] || stats.docs[f.id]) {
          counts[f.id] = (counts[f.id] || 0) + 1;
        }
      });
    });

    return { counts, completCount, incompletCount };
  }, [etudiants]);

  const toggleDocumentFilter = (docId: string) => {
    setDocumentFilters(prev => ({
      ...prev,
      [docId]: !prev[docId]
    }));
  };

  const activeDocKeys = useMemo(() => {
    return Object.entries(documentFilters)
      .filter(([_, active]) => active)
      .map(([key]) => key);
  }, [documentFilters]);

  const filteredEtudiants = useMemo(() =>
    etudiants
      .filter(etudiant => {
        const q = searchTerm.toLowerCase().trim();
        const matchSearch = !q ||
          etudiant.matricule.toLowerCase().includes(q) ||
          etudiant.nom.toLowerCase().includes(q) ||
          etudiant.prenom.toLowerCase().includes(q) ||
          (etudiant.telephone && etudiant.telephone.toLowerCase().includes(q)) ||
          (etudiant.email && etudiant.email.toLowerCase().includes(q));

        const matchVague = !vagueFilter || (
          vagueFilter === 'Soir'
            ? etudiant.vague?.toLowerCase().includes('soir')
            : (!etudiant.vague || etudiant.vague.toLowerCase().includes('jour'))
        );
        const matchNiveau  = !niveauFilter  || etudiant.niveau === niveauFilter;
        const matchOption  = !optionFilter  || etudiant.option === optionFilter;
        const matchFiliere = !filiereFilter || etudiant.filiere === filiereFilter;
        const matchStatut  = !statutFilter  || (etudiant.statut || 'Inscrit') === statutFilter;

        const stats = getDossierStats(etudiant);

        // Filtre État du Dossier (Complet vs Incomplet)
        if (dossierFilter === 'complet' && !stats.isComplete) return false;
        if (dossierFilter === 'incomplet' && stats.isComplete) return false;

        // Filtre par Pièces spécifiques requises (cochées)
        if (activeDocKeys.length > 0) {
          for (const docId of activeDocKeys) {
            const field = DOCUMENT_FIELDS.find(f => f.id === docId || f.key === docId);
            const hasDoc = field ? Boolean(stats.docs[field.key] || stats.docs[field.id]) : false;
            if (!hasDoc) return false;
          }
        }

        return matchSearch && matchVague && matchNiveau && matchOption && matchFiliere && matchStatut;
      })
      .sort((a, b) => a.matricule.localeCompare(b.matricule)),
    [etudiants, searchTerm, vagueFilter, niveauFilter, optionFilter, filiereFilter, statutFilter, dossierFilter, activeDocKeys]
  );

  // ---- Actions ----
  const handleOpenView = (etud: Etudiant) => {
    setSelectedEtudiant(etud);
    setIsViewModalOpen(true);
  };

  const handleOpenEdit = (etud: Etudiant) => {
    setSelectedEtudiant(etud);
    setEditFormData({ ...etud });
    const docs = parseDocumentsPhysiques(etud.documents_physiques);
    const initialDocs: Record<string, boolean> = {};
    DOCUMENT_FIELDS.forEach(f => {
      initialDocs[f.key] = Boolean(docs[f.key] || docs[f.id]);
    });
    setEditDocsData(initialDocs);
    setEditActiveTab(0);
    setIsEditModalOpen(true);
  };

  const handleEditChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setEditFormData(prev => {
      const updated = { ...prev, [name]: value };
      if (name === 'option') {
        updated.filiere = OPTION_FILIERES[value]?.[0] || '';
      }
      if (name === 'cycle_formation') {
        updated.niveau = NIVEAUX_PAR_CYCLE[value]?.[0] || '';
      }
      return updated;
    });
  };

  const handleToggleEditDoc = (key: string) => {
    setEditDocsData(prev => ({
      ...prev,
      [key]: !prev[key]
    }));
  };

  const handleSetAllDocs = (value: boolean) => {
    const updated: Record<string, boolean> = {};
    DOCUMENT_FIELDS.forEach(f => {
      updated[f.key] = value;
    });
    setEditDocsData(updated);
  };

  const handleSaveEdit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedEtudiant) return;
    setSaving(true);
    try {
      const updated: Etudiant = {
        ...selectedEtudiant,
        ...editFormData,
        documents_physiques: JSON.stringify(editDocsData),
        last_modified_at: new Date().toISOString()
      };
      await db.etudiants.put(updated);
      supabase.from('etudiants').update(updated).eq('id', updated.id).then(() => {}, () => {});
      await logAction('Modification Étudiant', 'Étudiants', `Modification de ${updated.matricule} (Dossier & Infos)`);
      setEtudiants(prev => prev.map(item => item.id === updated.id ? updated : item));
      setIsEditModalOpen(false);
      showToast(`Étudiant ${updated.matricule} et dossier mis à jour avec succès.`);
    } catch (err: any) {
      alert('Erreur : ' + err.message);
    } finally { setSaving(false); }
  };

  const handleOpenDelete = (etud: Etudiant) => {
    setSelectedEtudiant(etud);
    setIsDeleteModalOpen(true);
  };

  const handleConfirmDelete = async () => {
    if (!selectedEtudiant) return;
    setSaving(true);
    try {
      await db.etudiants.delete(selectedEtudiant.id);
      supabase.from('etudiants').delete().eq('id', selectedEtudiant.id).then(() => {}, () => {});
      await logAction('Suppression Étudiant', 'Étudiants', `Suppression de ${selectedEtudiant.matricule}`);
      setEtudiants(prev => prev.filter(item => item.id !== selectedEtudiant.id));
      setIsDeleteModalOpen(false);
      showToast(`Étudiant ${selectedEtudiant.matricule} supprimé.`);
    } catch (err: any) {
      alert('Erreur : ' + err.message);
    } finally { setSaving(false); }
  };

  const handleExportExcel = () => {
    const data = filteredEtudiants.map(e => {
      const stats = getDossierStats(e);
      return {
        'Matricule': e.matricule,
        'Nom': e.nom,
        'Prénom': e.prenom,
        'Vague': e.vague || 'Jour',
        'Sexe': e.sexe,
        'Niveau': e.niveau,
        'Filière': e.filiere,
        'Option': e.option || '',
        'Téléphone': e.telephone || '',
        'Statut': e.statut || 'Inscrit',
        'État Dossier': stats.isComplete ? 'Complet (16/16)' : `Incomplet (${stats.count}/16)`,
        'Pièces Fournies': `${stats.count}/${stats.total}`,
        'Taux Dossier': `${stats.percentage}%`
      };
    });
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Etudiants');
    XLSX.writeFile(wb, `etudiants_isgi_${new Date().toISOString().split('T')[0]}.xlsx`);
  };

  const handleExportPDF = () => {
    exportToPDF('RÉPERTOIRE OFFICIEL DES ÉTUDIANTS', [
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom & Prénom', dataKey: 'nom_complet' },
      { header: 'Vague', dataKey: 'vague' },
      { header: 'Sexe', dataKey: 'sexe' },
      { header: 'Niveau', dataKey: 'niveau' },
      { header: 'Filière', dataKey: 'filiere' },
      { header: 'Téléphone', dataKey: 'telephone' },
    ], filteredEtudiants.map(e => ({
      matricule: e.matricule,
      nom_complet: `${e.nom.toUpperCase()} ${e.prenom}`,
      vague: e.vague || 'Jour',
      sexe: e.sexe || 'M', niveau: e.niveau, filiere: e.filiere,
      telephone: e.telephone || 'N/A'
    })), 'Etudiants_ISGI.pdf');
  };

  // Réutilisable pour les champs
  const inputClass = "w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest focus:ring-2 focus:ring-primary outline-none text-sm";
  const labelClass = "text-xs font-semibold text-on-surface block mb-1";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Gestion des Étudiants</h2>
          <p className="text-on-surface-variant text-sm mt-1">Recherche multicritères, consultation, modification et suppression</p>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={handleExportExcel} className="flex items-center gap-2 px-4 py-2 bg-secondary text-on-secondary rounded-full font-medium text-sm hover:bg-secondary/90 transition-colors">
            <Download className="w-4 h-4" /><span>Excel</span>
          </button>
          <button onClick={handleExportPDF} className="flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-full font-medium text-sm hover:bg-primary/90 transition-colors">
            <Download className="w-4 h-4" /><span>PDF</span>
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {/* Sidebar Filtres */}
        <div className="lg:col-span-1 bg-surface-container-lowest rounded-2xl border border-outline-variant p-5 space-y-4">
          <div className="flex items-center justify-between pb-2 border-b border-outline-variant">
            <div className="flex items-center gap-2">
              <Filter className="w-5 h-5 text-primary" />
              <h3 className="font-semibold text-on-surface">Filtres</h3>
            </div>
            {(searchTerm || vagueFilter || niveauFilter || optionFilter || filiereFilter || statutFilter || dossierFilter || activeDocKeys.length > 0) && (
              <button
                onClick={() => {
                  setSearchTerm('');
                  setVagueFilter('');
                  setNiveauFilter('');
                  setOptionFilter('');
                  setFiliereFilter('');
                  setStatutFilter('');
                  setDossierFilter('');
                  setDocumentFilters({});
                  setDocSearchFilter('');
                }}
                className="text-xs text-primary font-semibold hover:underline"
              >
                Réinitialiser
              </button>
            )}
          </div>

          <div className="space-y-3">
            <div>
              <label className={labelClass}>Recherche Étudiant</label>
              <div className="relative">
                <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Nom, matricule, tél, email..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:ring-2 focus:ring-primary outline-none"
                />
              </div>
            </div>

            {/* FILTRE VAGUE */}
            <div>
              <label className={labelClass}>Vague d'études</label>
              <select
                value={vagueFilter}
                onChange={(e) => setVagueFilter(e.target.value)}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none font-medium"
              >
                <option value="">Toutes les vagues</option>
                <option value="Jour">☀️ Cours du Jour</option>
                <option value="Soir">🌙 Cours du Soir</option>
              </select>
            </div>

            {/* FILTRE ÉTAT DU DOSSIER */}
            <div>
              <label className={labelClass}>État du Dossier Physique</label>
              <select
                value={dossierFilter}
                onChange={(e) => setDossierFilter(e.target.value as any)}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none font-medium"
              >
                <option value="">Tous les dossiers ({etudiants.length})</option>
                <option value="complet">✓ Dossiers Complets 16/16 ({docCounts.completCount})</option>
                <option value="incomplet">⚠️ Dossiers Incomplets &lt; 16 ({docCounts.incompletCount})</option>
              </select>
            </div>

            <div>
              <label className={labelClass}>Niveau</label>
              <select
                value={niveauFilter}
                onChange={(e) => setNiveauFilter(e.target.value)}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Tous</option>
                {NIVEAUX.map(n => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>

            <div>
              <label className={labelClass}>Option (Domaine)</label>
              <select
                value={optionFilter}
                onChange={(e) => {
                  setOptionFilter(e.target.value);
                  setFiliereFilter('');
                }}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Toutes</option>
                {OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
              </select>
            </div>

            <div>
              <label className={labelClass}>Filière</label>
              <select
                value={filiereFilter}
                onChange={(e) => setFiliereFilter(e.target.value)}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Toutes</option>
                {(optionFilter && OPTION_FILIERES[optionFilter] ? OPTION_FILIERES[optionFilter] : filieresList).map(f => (
                  <option key={f} value={f}>{f}</option>
                ))}
              </select>
            </div>

            <div>
              <label className={labelClass}>Statut</label>
              <select
                value={statutFilter}
                onChange={(e) => setStatutFilter(e.target.value)}
                className="w-full p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Tous</option>
                <option value="Inscrit">Inscrit</option>
                <option value="En attente">En attente</option>
                <option value="Suspendu">Suspendu</option>
                <option value="Diplômé">Diplômé</option>
              </select>
            </div>

            {/* FILTRES AVANCÉS PAR PIÈCES & DOCUMENTS DEMANDÉS (STYLE GESTIONNAIRE) */}
            <div className="pt-3 border-t border-outline-variant">
              <div className="flex items-center justify-between mb-2">
                <label className="text-xs font-bold text-on-surface flex items-center gap-1.5">
                  <FolderCheck className="w-3.5 h-3.5 text-primary" />
                  <span>Pièces &amp; Documents ({activeDocKeys.length} sélec.)</span>
                </label>
                {activeDocKeys.length > 0 && (
                  <button
                    type="button"
                    onClick={() => setDocumentFilters({})}
                    className="text-[10px] text-primary hover:underline font-semibold"
                  >
                    Effacer
                  </button>
                )}
              </div>

              {/* Recherche rapide dans les documents */}
              <div className="relative mb-2">
                <Search className="w-3 h-3 text-on-surface-variant absolute left-2.5 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Filtrer une pièce..."
                  value={docSearchFilter}
                  onChange={(e) => setDocSearchFilter(e.target.value)}
                  className="w-full pl-7 pr-2 py-1 text-[11px] rounded-lg border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
                />
              </div>

              <div className="space-y-1 max-h-52 overflow-y-auto pr-1 custom-scrollbar">
                {DOCUMENT_FIELDS
                  .filter(doc => !docSearchFilter || doc.label.toLowerCase().includes(docSearchFilter.toLowerCase()))
                  .map(doc => {
                    const count = docCounts.counts[doc.id] || 0;
                    const isChecked = Boolean(documentFilters[doc.id]);
                    return (
                      <label
                        key={doc.id}
                        className={cn(
                          "flex items-center justify-between p-1.5 rounded-lg text-[11px] cursor-pointer transition-colors border",
                          isChecked
                            ? "bg-primary/10 border-primary/30 text-primary font-bold"
                            : "hover:bg-surface-container border-transparent text-on-surface-variant"
                        )}
                      >
                        <div className="flex items-center gap-2 truncate">
                          <input
                            type="checkbox"
                            checked={isChecked}
                            onChange={() => toggleDocumentFilter(doc.id)}
                            className="w-3.5 h-3.5 rounded text-primary focus:ring-primary border-outline-variant cursor-pointer shrink-0"
                          />
                          <span className="truncate" title={doc.label}>{doc.label}</span>
                        </div>
                        <span className={cn(
                          "text-[10px] font-mono px-1.5 py-0.5 rounded-full shrink-0 ml-1.5",
                          count === etudiants.length && etudiants.length > 0
                            ? "bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 font-bold"
                            : count > 0
                            ? "bg-surface-container-highest text-on-surface-variant font-medium"
                            : "bg-rose-500/10 text-rose-600 font-medium"
                        )}>
                          {count}/{etudiants.length}
                        </span>
                      </label>
                    );
                  })}
              </div>
            </div>

          </div>
        </div>

        {/* Table Étudiants */}
        <div className="lg:col-span-3 bg-surface-container-lowest rounded-2xl border border-outline-variant flex flex-col overflow-hidden">
          <div className="p-4 border-b border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <span className="text-sm font-semibold text-on-surface">
              {filteredEtudiants.length} étudiant(s) trouvé(s)
            </span>
            <div className="flex items-center gap-3 text-xs">
              <span className="flex items-center gap-1.5 text-emerald-700 dark:text-emerald-300 font-medium">
                <span className="w-2 h-2 rounded-full bg-emerald-500"></span>
                {docCounts.completCount} Dossier(s) Complet(s)
              </span>
              <span className="flex items-center gap-1.5 text-amber-700 dark:text-amber-300 font-medium">
                <span className="w-2 h-2 rounded-full bg-amber-500"></span>
                {docCounts.incompletCount} Incomplet(s)
              </span>
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-surface-container-highest text-on-surface-variant text-xs uppercase font-semibold border-b border-outline-variant">
                  <th className="py-3 px-4">Matricule</th>
                  <th className="py-3 px-4">Nom &amp; Prénom</th>
                  <th className="py-3 px-4">Vague</th>
                  <th className="py-3 px-4">Niveau</th>
                  <th className="py-3 px-4">Filière</th>
                  <th className="py-3 px-4">État Dossier</th>
                  <th className="py-3 px-4">Téléphone</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant text-sm text-on-surface">
                {loading ? (
                  <tr><td colSpan={8} className="py-12 text-center text-on-surface-variant">Chargement...</td></tr>
                ) : filteredEtudiants.length === 0 ? (
                  <tr><td colSpan={8} className="py-12 text-center text-on-surface-variant">Aucun étudiant ne correspond aux critères.</td></tr>
                ) : (
                  filteredEtudiants.map(etud => {
                    const stats = getDossierStats(etud);
                    return (
                      <tr key={etud.id} className="hover:bg-surface-container-high/50 transition-colors">
                        <td className="py-3 px-4 font-mono font-medium text-xs text-primary">{etud.matricule}</td>
                        <td className="py-3 px-4 font-medium">{etud.nom.toUpperCase()} {etud.prenom}</td>
                        <td className="py-3 px-4 text-xs whitespace-nowrap">
                          {etud.vague?.toLowerCase().includes('soir') ? (
                            <span className="font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 border border-purple-200 text-xs inline-flex items-center gap-1">
                              🌙 Soir
                            </span>
                          ) : (
                            <span className="font-medium px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100 text-xs inline-flex items-center gap-1">
                              ☀️ Jour
                            </span>
                          )}
                        </td>
                        <td className="py-3 px-4 text-xs">
                          <span className="px-2 py-1 rounded-md bg-surface-container-high text-on-surface font-semibold">{etud.niveau}</span>
                        </td>
                        <td className="py-3 px-4 text-xs text-on-surface-variant max-w-[160px] truncate" title={etud.filiere}>{etud.filiere}</td>
                        <td className="py-3 px-4 text-xs whitespace-nowrap">
                          {stats.isComplete ? (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">
                              <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                              <span>Complet (16/16)</span>
                            </span>
                          ) : stats.count > 0 ? (
                            <span
                              className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30"
                              title={`Fourni: ${stats.count}/16 pièces (Manque ${stats.total - stats.count})`}
                            >
                              <Clock className="w-3.5 h-3.5 text-amber-600" />
                              <span>{stats.count}/16 pièces</span>
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-500/15 text-rose-700 dark:text-rose-300 border border-rose-500/30">
                              <AlertTriangle className="w-3.5 h-3.5 text-rose-600" />
                              <span>Non fourni (0/16)</span>
                            </span>
                          )}
                        </td>
                        <td className="py-3 px-4 text-xs">{etud.telephone || 'N/A'}</td>
                        <td className="py-3 px-4 text-right">
                          <div className="flex items-center justify-end gap-1">
                            {/* Carte badge */}
                            <button onClick={() => genererCarteEtudiantPDF(etud)} title="Carte d'étudiant PDF"
                              className="p-1.5 text-on-surface-variant hover:bg-surface-container-high rounded-lg transition-colors">
                              <CreditCard className="w-4 h-4" />
                            </button>
                            {/* Voir le dossier complet */}
                            <button onClick={() => handleOpenView(etud)} title="Voir le dossier & pièces"
                              className="p-1.5 text-primary hover:bg-primary-container/20 rounded-lg transition-colors">
                              <Eye className="w-4 h-4" />
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
      </div>

      {/* ===== MODAL VOIR DOSSIER COMPLET ===== */}
      {isViewModalOpen && selectedEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-5xl max-h-[95vh] shadow-xl flex flex-col overflow-hidden">
            {/* Header */}
            <div className="bg-surface-container-low border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                  <User className="w-5 h-5 text-primary" />
                </div>
                <div>
                  <h3 className="text-lg font-bold text-on-surface">{selectedEtudiant.nom.toUpperCase()} {selectedEtudiant.prenom}</h3>
                  <p className="text-xs font-mono text-on-surface-variant">{selectedEtudiant.matricule}</p>
                </div>
              </div>
              <button onClick={() => setIsViewModalOpen(false)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>

            <div className="p-4 md:p-6 overflow-y-auto flex-1 min-h-0">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">

                {/* Colonne 1 : Identité */}
                <div className="space-y-4">
                  <h4 className="text-sm font-bold text-primary border-b border-outline-variant pb-2">Identité</h4>
                  <div className="space-y-3 text-sm">
                    {[
                      ['Matricule', selectedEtudiant.matricule],
                      ['Nom complet', `${selectedEtudiant.nom} ${selectedEtudiant.prenom}`],
                      ['Sexe', selectedEtudiant.sexe === 'M' ? 'Masculin' : 'Féminin'],
                      ['Date de naissance', selectedEtudiant.date_naissance],
                      ['Lieu de naissance', selectedEtudiant.lieu_naissance || '—'],
                      ['Nationalité', selectedEtudiant.nationalite || '—'],
                      ['Adresse', selectedEtudiant.adresse || '—'],
                      ['Ville', selectedEtudiant.ville || '—'],
                      ['Téléphone', selectedEtudiant.telephone],
                      ['Email', selectedEtudiant.email || '—'],
                      ['N° CNI', selectedEtudiant.numero_cni || '—'],
                      ['Situation matrimoniale', selectedEtudiant.situation_matrimoniale || '—'],
                    ].map(([label, val]) => (
                      <div key={label}>
                        <span className="text-on-surface-variant text-[11px] uppercase tracking-wider block">{label}</span>
                        <p className="font-medium text-on-surface">{val}</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Colonne 2 : Famille */}
                <div className="space-y-4">
                  <h4 className="text-sm font-bold text-primary border-b border-outline-variant pb-2">Famille & Tuteur</h4>
                  <div className="space-y-3 text-sm">
                    {[
                      ['Nom du Père', selectedEtudiant.nom_pere || '—'],
                      ['Profession du Père', selectedEtudiant.profession_pere || '—'],
                      ['Nom de la Mère', selectedEtudiant.nom_mere || '—'],
                      ['Profession de la Mère', selectedEtudiant.profession_mere || '—'],
                      ['Nom du Tuteur', selectedEtudiant.nom_tuteur || '—'],
                      ['Profession du Tuteur', selectedEtudiant.profession_tuteur || '—'],
                      ['Téléphone Tuteur', selectedEtudiant.telephone_tuteur || '—'],
                      ['Lieu de service Tuteur', selectedEtudiant.lieu_service_tuteur || '—'],
                    ].map(([label, val]) => (
                      <div key={label}>
                        <span className="text-on-surface-variant text-[11px] uppercase tracking-wider block">{label}</span>
                        <p className="font-medium text-on-surface">{val}</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Colonne 3 : Académique */}
                <div className="space-y-4">
                  <h4 className="text-sm font-bold text-primary border-b border-outline-variant pb-2">Parcours Académique</h4>
                  <div className="space-y-3 text-sm">
                    {[
                      ['Type étudiant', selectedEtudiant.type_etudiant || '—'],
                      ['Option', selectedEtudiant.option || '—'],
                      ['Filière', selectedEtudiant.filiere],
                      ['Cycle', selectedEtudiant.cycle_formation || '—'],
                      ['Niveau', selectedEtudiant.niveau],
                      ['Année académique', selectedEtudiant.annee_academique || '—'],
                      ['Vague d\'études', selectedEtudiant.vague?.toLowerCase().includes('soir') ? '🌙 Cours du Soir' : '☀️ Cours du Jour'],
                      ['Rentrée', selectedEtudiant.rentree || '—'],
                      ['Site de formation', selectedEtudiant.site_formation || '—'],
                      ['Série BAC', selectedEtudiant.serie_bac || '—'],
                      ['Année BAC', selectedEtudiant.annee_obtention_bac ? String(selectedEtudiant.annee_obtention_bac) : '—'],
                      ['Statut', selectedEtudiant.statut || 'Inscrit'],
                      ['Sync', selectedEtudiant.is_synced ? 'Synchronisé ✓' : 'Local (non synchronisé)'],
                    ].map(([label, val]) => (
                      <div key={label}>
                        <span className="text-on-surface-variant text-[11px] uppercase tracking-wider block">{label}</span>
                        <p className="font-medium text-on-surface">{val}</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Section Complète : Pièces & Documents Physiques du Dossier */}
                <div className="md:col-span-3 pt-5 border-t border-outline-variant space-y-4">
                  {(() => {
                    const stats = getDossierStats(selectedEtudiant);
                    return (
                      <>
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-surface-container-low p-4 rounded-2xl border border-outline-variant">
                          <div className="flex items-center gap-3">
                            <div className={cn(
                              "p-2.5 rounded-xl",
                              stats.isComplete ? "bg-emerald-500/20 text-emerald-700 dark:text-emerald-300" : "bg-amber-500/20 text-amber-700 dark:text-amber-300"
                            )}>
                              <FolderCheck className="w-5 h-5" />
                            </div>
                            <div>
                              <h4 className="text-sm font-bold text-on-surface">Pièces &amp; Documents Physiques du Dossier</h4>
                              <p className="text-xs text-on-surface-variant mt-0.5">
                                État de conformité du dossier d'inscription fourni par l'étudiant
                              </p>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <div className="w-36 bg-surface-container-highest rounded-full h-2.5 overflow-hidden">
                              <div
                                className={cn("h-full transition-all rounded-full", stats.isComplete ? "bg-emerald-600" : "bg-amber-500")}
                                style={{ width: `${stats.percentage}%` }}
                              />
                            </div>
                            <span className={cn(
                              "text-xs font-bold px-3 py-1 rounded-full",
                              stats.isComplete ? "bg-emerald-500/20 text-emerald-700 dark:text-emerald-300" : "bg-amber-500/20 text-amber-700 dark:text-amber-300"
                            )}>
                              {stats.count} / {stats.total} pièces ({stats.percentage}%)
                            </span>
                          </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 text-xs">
                          {DOCUMENT_FIELDS.map(doc => {
                            const isPresent = Boolean(stats.docs[doc.key] || stats.docs[doc.id]);
                            return (
                              <div
                                key={doc.id}
                                className={cn(
                                  "p-3 rounded-xl border flex items-center gap-2.5 transition-colors",
                                  isPresent
                                    ? "bg-emerald-500/10 border-emerald-500/30 text-on-surface font-medium"
                                    : "bg-surface-container-high/20 border-outline-variant text-on-surface-variant/60"
                                )}
                              >
                                {isPresent ? (
                                  <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                                ) : (
                                  <X className="w-4 h-4 text-on-surface-variant/40 shrink-0" />
                                )}
                                <span className="truncate" title={doc.label}>{doc.label}</span>
                              </div>
                            );
                          })}
                        </div>
                      </>
                    );
                  })()}
                </div>

              </div>
            </div>

            <div className="p-4 border-t border-outline-variant bg-surface-container-lowest shrink-0 flex justify-end gap-2">
              <button onClick={() => { setIsViewModalOpen(false); handleOpenEdit(selectedEtudiant); }}
                className="flex items-center gap-2 px-4 py-2 rounded-full bg-secondary text-on-secondary font-medium text-sm hover:bg-secondary/90 transition-colors">
                <Edit className="w-4 h-4" /> Modifier l'étudiant &amp; dossier
              </button>
              <button onClick={() => setIsViewModalOpen(false)}
                className="px-4 py-2 rounded-full border border-outline-variant font-medium text-sm hover:bg-surface-container-highest transition-colors">
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ===== MODAL MODIFIER (5 onglets complets avec gestion des pièces) ===== */}
      {isEditModalOpen && selectedEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col shadow-xl">
            {/* Header */}
            <div className="border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-lg font-bold text-on-surface">
                Modifier — <span className="font-mono text-primary">{selectedEtudiant.matricule}</span>
              </h3>
              <button onClick={() => setIsEditModalOpen(false)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>

            {/* Onglets */}
            <div className="flex border-b border-outline-variant bg-surface-container-lowest overflow-x-auto shrink-0">
              {['1. Identité', '2. Parents & Tuteur', '3. Parcours Académique', '4. Pièces & Documents', '5. Statut'].map((tab, i) => (
                <button key={i} type="button" onClick={() => setEditActiveTab(i)}
                  className={`px-5 py-3 text-sm font-medium whitespace-nowrap transition-colors ${editActiveTab === i ? 'text-primary border-b-2 border-primary font-bold' : 'text-on-surface-variant hover:text-on-surface'}`}>
                  {tab}
                </button>
              ))}
            </div>

            <form onSubmit={handleSaveEdit} className="flex flex-col flex-1 min-h-0">
              <div className="overflow-y-auto p-4 md:p-6 flex-1 min-h-0">

                {/* TAB 1 : IDENTITÉ */}
                {editActiveTab === 0 && (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div><label className={labelClass}>Nom *</label><input required name="nom" value={editFormData.nom || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Prénom *</label><input required name="prenom" value={editFormData.prenom || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Sexe</label>
                      <select name="sexe" value={editFormData.sexe || 'M'} onChange={handleEditChange} className={inputClass}>
                        <option value="M">Masculin</option><option value="F">Féminin</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Date de Naissance</label><input name="date_naissance" value={editFormData.date_naissance || ''} onChange={handleEditChange} type="date" className={inputClass} /></div>
                    <div><label className={labelClass}>Lieu de Naissance</label><input name="lieu_naissance" value={editFormData.lieu_naissance || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Nationalité</label><input name="nationalite" value={editFormData.nationalite || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div className="md:col-span-2"><label className={labelClass}>Adresse</label><input name="adresse" value={editFormData.adresse || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Ville</label><input name="ville" value={editFormData.ville || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Téléphone</label><input name="telephone" value={editFormData.telephone || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Email</label><input name="email" value={editFormData.email || ''} onChange={handleEditChange} type="email" className={inputClass} /></div>
                    <div><label className={labelClass}>N° CNI</label><input name="numero_cni" value={editFormData.numero_cni || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Situation Matrimoniale</label>
                      <select name="situation_matrimoniale" value={editFormData.situation_matrimoniale || 'Célibataire'} onChange={handleEditChange} className={inputClass}>
                        <option value="Célibataire">Célibataire</option>
                        <option value="Marié(e)">Marié(e)</option>
                        <option value="Divorcé(e)">Divorcé(e)</option>
                      </select>
                    </div>
                  </div>
                )}

                {/* TAB 2 : PARENTS & TUTEUR */}
                {editActiveTab === 1 && (
                  <div className="space-y-6">
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Père</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom</label><input name="nom_pere" value={editFormData.nom_pere || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession</label><input name="profession_pere" value={editFormData.profession_pere || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Mère</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom</label><input name="nom_mere" value={editFormData.nom_mere || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession</label><input name="profession_mere" value={editFormData.profession_mere || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Tuteur</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom</label><input name="nom_tuteur" value={editFormData.nom_tuteur || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession</label><input name="profession_tuteur" value={editFormData.profession_tuteur || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Téléphone Tuteur</label><input name="telephone_tuteur" value={editFormData.telephone_tuteur || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Lieu de service</label><input name="lieu_service_tuteur" value={editFormData.lieu_service_tuteur || ''} onChange={handleEditChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                  </div>
                )}

                {/* TAB 3 : ACADÉMIQUE */}
                {editActiveTab === 2 && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label className={labelClass}>Type d'étudiant</label>
                      <select name="type_etudiant" value={editFormData.type_etudiant || 'NORMAL'} onChange={handleEditChange} className={inputClass}>
                        <option value="NORMAL">Normal</option><option value="BOURSIER">Boursier</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Année d'obtention du BAC</label>
                      <input name="annee_obtention_bac" value={editFormData.annee_obtention_bac || ''} onChange={handleEditChange} type="number" min="1980" max="2050" className={inputClass} />
                    </div>
                    <div><label className={labelClass}>Série BAC</label>
                      <select name="serie_bac" value={editFormData.serie_bac || 'A'} onChange={handleEditChange} className={inputClass}>
                        {['A','C','D','E','F1','F2','F3','F4','G1','G2','G3','BG','H1','H2','H3','H4','H5','SANS BAC'].map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Option (Domaine)</label>
                      <select name="option" value={editFormData.option || ''} onChange={handleEditChange} className={inputClass}>
                        {OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Filière</label>
                      <select name="filiere" value={editFormData.filiere || ''} onChange={handleEditChange} className={inputClass}>
                        {(OPTION_FILIERES[editFormData.option || ''] || filieresList).map(f => <option key={f} value={f}>{f}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Cycle</label>
                      <select name="cycle_formation" value={editFormData.cycle_formation || 'Licence'} onChange={handleEditChange} className={inputClass}>
                        {CYCLES.map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Niveau</label>
                      <select name="niveau" value={editFormData.niveau || ''} onChange={handleEditChange} className={inputClass}>
                        {(NIVEAUX_PAR_CYCLE[editFormData.cycle_formation || 'Licence'] || NIVEAUX).map(n => <option key={n} value={n}>{n}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Rentrée</label>
                      <select name="rentree" value={editFormData.rentree || 'OCTOBRE'} onChange={handleEditChange} className={inputClass}>
                        <option value="OCTOBRE">Octobre</option><option value="JANVIER">Janvier</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Année Académique</label>
                      <select name="annee_academique" value={editFormData.annee_academique || '2026-2027'} onChange={handleEditChange} className={inputClass}>
                        <option value="2025-2026">2025-2026</option>
                        <option value="2026-2027">2026-2027</option>
                        <option value="2027-2028">2027-2028</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Vague d'études</label>
                      <select name="vague" value={editFormData.vague || 'Jour'} onChange={handleEditChange} className={inputClass}>
                        <option value="Jour">☀️ Cours du Jour</option>
                        <option value="Soir">🌙 Cours du Soir</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Site de Formation</label>
                      <input name="site_formation" value={editFormData.site_formation || 'ISGI'} onChange={handleEditChange} type="text" className={inputClass} />
                    </div>
                  </div>
                )}

                {/* TAB 4 : PIÈCES & DOCUMENTS PHYSIQUES FOURNIS */}
                {editActiveTab === 3 && (
                  <div className="space-y-4">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-surface-container-low p-4 rounded-2xl border border-outline-variant">
                      <div>
                        <h4 className="text-sm font-bold text-on-surface">Cochez les pièces physiques remises par l'étudiant</h4>
                        <p className="text-xs text-on-surface-variant mt-0.5">
                          Mettez à jour le dossier pour valider la conformité administrative
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => handleSetAllDocs(true)}
                          className="px-3 py-1 rounded-xl text-xs font-semibold bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors"
                        >
                          Tout cocher
                        </button>
                        <button
                          type="button"
                          onClick={() => handleSetAllDocs(false)}
                          className="px-3 py-1 rounded-xl text-xs font-semibold bg-surface-container-highest text-on-surface-variant hover:bg-surface-container-high transition-colors"
                        >
                          Tout décocher
                        </button>
                      </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                      {DOCUMENT_FIELDS.map(doc => {
                        const isChecked = Boolean(editDocsData[doc.key]);
                        return (
                          <label
                            key={doc.id}
                            className={cn(
                              "flex items-center gap-3 p-3 rounded-2xl border cursor-pointer transition-all",
                              isChecked
                                ? "bg-emerald-500/10 border-emerald-500/40 text-on-surface font-semibold"
                                : "bg-surface-container-low border-outline-variant text-on-surface-variant hover:bg-surface-container"
                            )}
                          >
                            <input
                              type="checkbox"
                              checked={isChecked}
                              onChange={() => handleToggleEditDoc(doc.key)}
                              className="w-4 h-4 rounded text-primary focus:ring-primary border-outline-variant cursor-pointer shrink-0"
                            />
                            <div className="flex-1 min-w-0">
                              <p className="text-xs truncate">{doc.label}</p>
                              <span className={cn(
                                "text-[10px]",
                                isChecked ? "text-emerald-600 dark:text-emerald-400 font-bold" : "text-on-surface-variant/60"
                              )}>
                                {isChecked ? '✓ Pièce fournie' : 'Non fournie'}
                              </span>
                            </div>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* TAB 5 : STATUT */}
                {editActiveTab === 4 && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label className={labelClass}>Statut d'inscription</label>
                      <select name="statut" value={editFormData.statut || 'Inscrit'} onChange={handleEditChange} className={inputClass}>
                        <option value="Inscrit">Inscrit</option>
                        <option value="En attente">En attente</option>
                        <option value="Suspendu">Suspendu</option>
                        <option value="Diplômé">Diplômé</option>
                      </select>
                    </div>
                  </div>
                )}
              </div>

              {/* Footer */}
              <div className="p-4 border-t border-outline-variant bg-surface-container-lowest shrink-0 flex justify-between items-center">
                <div>
                  {editActiveTab > 0 && (
                    <button type="button" onClick={() => setEditActiveTab(editActiveTab - 1)}
                      className="px-4 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors text-sm">
                      Précédent
                    </button>
                  )}
                </div>
                <div className="flex gap-2">
                  <button type="button" onClick={() => setIsEditModalOpen(false)}
                    className="px-4 py-2 rounded-full border border-outline-variant font-medium text-sm hover:bg-surface-container-highest transition-colors">
                    Annuler
                  </button>
                  {editActiveTab < 4 ? (
                    <button type="button" onClick={() => setEditActiveTab(editActiveTab + 1)}
                      className="px-5 py-2 rounded-full bg-secondary text-on-secondary font-medium text-sm hover:bg-secondary/90 transition-colors">
                      Suivant
                    </button>
                  ) : (
                    <button type="submit" disabled={saving}
                      className="flex items-center gap-2 px-5 py-2 rounded-full bg-primary text-on-primary font-medium text-sm hover:bg-primary/90 transition-colors disabled:opacity-50">
                      <Save className="w-4 h-4" />
                      {saving ? 'Enregistrement...' : 'Enregistrer les modifications'}
                    </button>
                  )}
                </div>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ===== MODAL SUPPRESSION ===== */}
      {isDeleteModalOpen && selectedEtudiant && (
        <div className="fixed inset-0 z-50 bg-on-surface/20 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest rounded-3xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-error">
              <div className="p-3 bg-error-container text-on-error-container rounded-2xl">
                <AlertTriangle className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-base font-bold text-on-surface">Confirmer la suppression</h3>
                <p className="text-xs text-error font-medium">Action irréversible</p>
              </div>
            </div>
            <div className="bg-surface-container rounded-xl p-4 space-y-1 text-sm">
              <p className="font-bold text-on-surface">{selectedEtudiant.nom.toUpperCase()} {selectedEtudiant.prenom}</p>
              <p className="font-mono text-xs text-primary">{selectedEtudiant.matricule}</p>
              <p className="text-xs text-on-surface-variant">{selectedEtudiant.niveau} — {selectedEtudiant.filiere}</p>
            </div>
            <p className="text-xs text-on-surface-variant leading-relaxed">
              Voulez-vous vraiment supprimer définitivement cet étudiant ? Cette action supprimera également toutes ses notes et présences associées.
            </p>
            <div className="flex justify-end gap-2 pt-3 border-t border-outline-variant">
              <button type="button" onClick={() => setIsDeleteModalOpen(false)}
                className="px-4 py-2 rounded-full border border-outline-variant hover:bg-surface-container-highest text-sm font-medium">
                Annuler
              </button>
              <button type="button" onClick={handleConfirmDelete} disabled={saving}
                className="px-5 py-2 rounded-full bg-error text-on-error text-sm font-medium hover:bg-error/90 transition-colors disabled:opacity-50">
                {saving ? 'Suppression...' : 'Supprimer définitivement'}
              </button>
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
