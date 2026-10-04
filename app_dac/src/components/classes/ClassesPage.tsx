import React, { useState, useEffect } from 'react';
import {
  School,
  Plus,
  Users,
  BookOpen,
  UserCheck,
  Search,
  CheckSquare,
  Square,
  Trash2,
  CheckCircle2,
  X,
  Filter
} from 'lucide-react';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Classe, Etudiant, Matiere, Personnel, ClasseEtudiant, ClasseMatiere } from '../../types';

// Référentiel des Options et Filières de l'ISGI
const OPTIONS_ISGI = [
  "Gestion et Administration",
  "Technologie",
  "Droit privé et international",
  "Industrie"
];

const FILIERES_PAR_OPTION: Record<string, string[]> = {
  "Gestion et Administration": [
    "Comptabilité et gestion d'entreprise",
    "Audit et contrôle de gestion",
    "Commerce international",
    "Gestion en affaires mondiales",
    "Marketing et communication d'entreprise",
    "Gestion des finances",
    "GRH et administration des entreprises",
    "Entrepreneuriat et leadership international",
    "Droit international des affaires",
    "Logistique et transport",
    "Administration publique-privée",
    "Banque et Assurance"
  ],
  "Technologie": [
    "Réseaux Informatiques",
    "Télécommunications et fibre optique",
    "Maintenance informatique",
    "Animation 2D, 3D et motion design",
    "Génie informatique",
    "Génie logiciel",
    "Robotique et Intelligence Artificielle",
    "Développement web et mobile",
    "Programmation",
    "Infographie design graphique",
    "Audio visuel",
    "Sécurité Informatique",
    "Administration des Bases de Données"
  ],
  "Droit privé et international": [
    "Droit civil",
    "Droit commercial et immobilier",
    "Droit des affaires",
    "Droit international (commerce international)"
  ],
  "Industrie": [
    "Génie électrique et électronique",
    "Génie mécanique",
    "Génie civil & Architecture",
    "Maintenance du pétrole et du gaz",
    "Entretien et réparation des véhicules légers",
    "Entretien et réparation des véhicules Lourds",
    "Traitement du pétrole et du gaz",
    "Instrumentation pétrolière et gazière",
    "Opérateur topographe",
    "QHSE",
    "Froid et climatisation",
    "Energie nouvelle et renouvelable",
    "Maintenance et soudure industrielle",
    "Plomberie et Sanitaire"
  ]
};

function normalizeNiveau(n?: string): string {
  if (!n) return '';
  const s = n.toLowerCase().trim();
  if (s.includes('1') && (s.includes('licence') || s.includes('l1') || s.startsWith('l'))) return 'L1';
  if (s.includes('2') && (s.includes('licence') || s.includes('l2') || s.startsWith('l'))) return 'L2';
  if (s.includes('3') && (s.includes('licence') || s.includes('l3') || s.startsWith('l'))) return 'L3';
  if (s.includes('1') && (s.includes('master') || s.includes('m1') || s.startsWith('m'))) return 'M1';
  if (s.includes('2') && (s.includes('master') || s.includes('m2') || s.startsWith('m'))) return 'M2';
  return s;
}

function matchNiveaux(n1?: string, n2?: string): boolean {
  if (!n1 || !n2) return true;
  return normalizeNiveau(n1) === normalizeNiveau(n2);
}

export const ClassesPage: React.FC = () => {
  const [classes, setClasses] = useState<Classe[]>([]);
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [matieres, setMatieres] = useState<Matiere[]>([]);
  const [personnel, setPersonnel] = useState<Personnel[]>([]);
  const [classeEtudiants, setClasseEtudiants] = useState<ClasseEtudiant[]>([]);
  const [classeMatieres, setClasseMatieres] = useState<ClasseMatiere[]>([]);

  const [activeClasse, setActiveClasse] = useState<Classe | null>(null);
  const [activeTab, setActiveTab] = useState<'etudiants' | 'matieres'>('etudiants');

  const [isNewClasseModalOpen, setIsNewClasseModalOpen] = useState(false);
  const [isAssignEtudiantsModalOpen, setIsAssignEtudiantsModalOpen] = useState(false);
  const [isAssignMatiereModalOpen, setIsAssignMatiereModalOpen] = useState(false);

  const [classeForm, setClasseForm] = useState<Partial<Classe>>({
    niveau: 'Licence 1',
    annee_academique: '2025-2026',
    capacite_max: 40
  });

  const [selectedEtudiantIds, setSelectedEtudiantIds] = useState<string[]>([]);
  const [etudFilterNiveau, setEtudFilterNiveau] = useState('');
  const [etudFilterOption, setEtudFilterOption] = useState('');
  const [etudFilterFiliere, setEtudFilterFiliere] = useState('');
  const [etudSearchQuery, setEtudSearchQuery] = useState('');

  const [matiereForm, setMatiereForm] = useState<{
    matiere_id: string;
    enseignant_id: string;
    semestre: 'S1' | 'S2' | 'Annuel';
  }>({
    matiere_id: '',
    enseignant_id: '',
    semestre: 'S1'
  });

  const [toastMessage, setToastMessage] = useState<string | null>(null);

  useEffect(() => {
    loadAll();
  }, []);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  const loadAll = async () => {
    try {
      const cls = await db.classes.toArray();
      const etuds = await db.etudiants.toArray();
      const mats = await db.matieres.toArray();
      const pers = await db.personnel.toArray();
      const cEtuds = await db.classe_etudiants.toArray();
      const cMats = await db.classe_matieres.toArray();

      setClasses(cls);
      setEtudiants(etuds);
      setMatieres(mats);
      setPersonnel(pers);
      setClasseEtudiants(cEtuds);
      setClasseMatieres(cMats);

      if (cls.length > 0 && !activeClasse) {
        setActiveClasse(cls[0]);
      }
    } catch (e) {
      console.error(e);
    }
  };

  // Étudiants actuellement affectés dans la classe active
  const etudiantsDeLaClasse = activeClasse
    ? classeEtudiants
        .filter(ce => ce.classe_id === activeClasse.id && ce.statut === 'actif')
        .map(ce => etudiants.find(e => e.id === ce.etudiant_id))
        .filter(Boolean) as Etudiant[]
    : [];

  // Matières de la classe active
  const matieresDeLaClasse = activeClasse
    ? classeMatieres
        .filter(cm => cm.classe_id === activeClasse.id)
        .map(cm => {
          const mat = matieres.find(m => m.id === cm.matiere_id);
          const prof = personnel.find(p => p.id === cm.enseignant_id);
          return {
            ...cm,
            matiere: mat,
            enseignant: prof
          };
        })
    : [];

  const handleSaveClasse = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!classeForm.nom || !classeForm.code) return;

    const newCls: Classe = {
      id: 'cls_' + Date.now(),
      nom: classeForm.nom,
      code: classeForm.code.toUpperCase(),
      niveau: classeForm.niveau || 'Licence 1',
      filiere: classeForm.filiere || 'Informatique',
      annee_academique: classeForm.annee_academique || '2025-2026',
      capacite_max: Number(classeForm.capacite_max) || 40,
      salle_principale: classeForm.salle_principale || 'Amphi Principal',
      created_at: new Date().toISOString()
    };

    await db.classes.put(newCls);
    try {
      await supabase.from('classes').insert([newCls]);
    } catch {}

    await logAction('Création de Classe', 'Classes', `Création de la classe ${newCls.nom}`);
    setClasses(prev => [...prev, newCls]);
    setActiveClasse(newCls);
    setIsNewClasseModalOpen(false);
    setClasseForm({ niveau: 'Licence 1', annee_academique: '2025-2026', capacite_max: 40 });
    showToast(`Classe "${newCls.nom}" créée.`);
  };

  // Étudiants éligibles à l'affectation (non encore dans cette classe)
  const existingEtudIdsInClass = new Set(etudiantsDeLaClasse.map(e => e.id));
  const eligibleEtudiants = etudiants.filter(e => {
    if (existingEtudIdsInClass.has(e.id)) return false;
    const q = etudSearchQuery.toLowerCase().trim();
    const matchQ =
      !q ||
      e.nom.toLowerCase().includes(q) ||
      e.prenom.toLowerCase().includes(q) ||
      e.matricule.toLowerCase().includes(q);
    const matchNiveau = !etudFilterNiveau || matchNiveaux(e.niveau, etudFilterNiveau);
    const matchOption = !etudFilterOption || (e.option || '') === etudFilterOption;
    const matchFiliere = !etudFilterFiliere || (e.filiere || '') === etudFilterFiliere;
    return matchQ && matchNiveau && matchOption && matchFiliere;
  });

  // Filières disponibles selon l'option sélectionnée (cascade)
  const filieresDisponibles: string[] = etudFilterOption
    ? (FILIERES_PAR_OPTION[etudFilterOption] || [])
    : Object.values(FILIERES_PAR_OPTION).flat();

  const toggleSelectEtudiant = (id: string) => {
    setSelectedEtudiantIds(prev =>
      prev.includes(id) ? prev.filter(item => item !== id) : [...prev, id]
    );
  };

  const toggleSelectAllEligible = () => {
    if (selectedEtudiantIds.length === eligibleEtudiants.length && eligibleEtudiants.length > 0) {
      setSelectedEtudiantIds([]);
    } else {
      setSelectedEtudiantIds(eligibleEtudiants.map(e => e.id));
    }
  };

  // Enregistrer l'affectation des étudiants sélectionnés
  const handleAssignSelectedEtudiants = async () => {
    if (!activeClasse || selectedEtudiantIds.length === 0) return;

    const newAffectations: ClasseEtudiant[] = selectedEtudiantIds.map(etudId => ({
      id: 'ce_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7),
      classe_id: activeClasse.id,
      etudiant_id: etudId,
      annee_academique: activeClasse.annee_academique || '2025-2026',
      date_affectation: new Date().toISOString(),
      statut: 'actif'
    }));

    // 1. Sauvegarde locale immédiate garantie dans Dexie
    await db.classe_etudiants.bulkPut(newAffectations);

    // 2. Mise à jour de l'état React pour affichage instantané
    setClasseEtudiants(prev => [...prev, ...newAffectations]);
    setSelectedEtudiantIds([]);
    setIsAssignEtudiantsModalOpen(false);
    showToast(`${newAffectations.length} étudiant(s) affecté(s) à la classe ${activeClasse.nom}.`);

    // 3. Journaliser
    await logAction(
      'Affectation Étudiants',
      'Classes',
      `Affectation de ${newAffectations.length} étudiants dans ${activeClasse.nom}`
    );

    // 4. Synchronisation en tâche de fond avec Supabase si la table distante existe
    try {
      await supabase.from('classe_etudiants').insert(newAffectations);
    } catch {}
  };

  // Retirer un étudiant de la classe
  const handleRemoveEtudiantFromClasse = async (etudiantId: string) => {
    if (!activeClasse) return;
    const record = classeEtudiants.find(ce => ce.classe_id === activeClasse.id && ce.etudiant_id === etudiantId);
    if (record) {
      await db.classe_etudiants.delete(record.id);
      setClasseEtudiants(prev => prev.filter(item => item.id !== record.id));
      showToast("Étudiant retiré de la classe.");

      try {
        await supabase.from('classe_etudiants').delete().eq('id', record.id);
      } catch {}
    }
  };

  // Ajouter une matière à la classe
  const handleAddMatiereToClasse = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeClasse || !matiereForm.matiere_id) return;

    const targetMat = matieres.find(m => m.id === matiereForm.matiere_id);
    if (!targetMat) return;

    const newLink: ClasseMatiere = {
      id: 'cm_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7),
      classe_id: activeClasse.id,
      matiere_id: targetMat.id,
      enseignant_id: matiereForm.enseignant_id || undefined,
      semestre: matiereForm.semestre,
      credits: targetMat.credits,
      coefficient: targetMat.coefficient,
      created_at: new Date().toISOString()
    };

    await db.classe_matieres.put(newLink);
    setClasseMatieres(prev => [...prev, newLink]);
    setIsAssignMatiereModalOpen(false);
    setMatiereForm({ matiere_id: '', enseignant_id: '', semestre: 'S1' });
    showToast(`Matière "${targetMat.nom}" rattachée à la classe.`);

    try {
      await supabase.from('classe_matieres').insert([newLink]);
    } catch {}
  };

  const handleUpdateProfForMatiere = async (classeMatiereId: string, profId: string) => {
    const existing = classeMatieres.find(cm => cm.id === classeMatiereId);
    if (!existing) return;

    const updated = { ...existing, enseignant_id: profId || undefined };
    await db.classe_matieres.put(updated);
    setClasseMatieres(prev => prev.map(cm => cm.id === classeMatiereId ? updated : cm));
    showToast("Professeur mis à jour pour cette matière.");

    try {
      await supabase.from('classe_matieres').update({ enseignant_id: profId || null }).eq('id', classeMatiereId);
    } catch {}
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Classes & Affectations</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Organisation des promotions, affectation des étudiants et association des cours
          </p>
        </div>

        <button
          onClick={() => setIsNewClasseModalOpen(true)}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors"
        >
          <Plus className="w-4 h-4" />
          <span>Nouvelle Classe</span>
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne gauche : liste des classes */}
        <div className="lg:col-span-4 space-y-3">
          <div className="flex justify-between items-center px-1">
            <h3 className="font-semibold text-xs uppercase text-on-surface-variant">
              Promotions & Salles ({classes.length})
            </h3>
          </div>

          <div className="space-y-2.5">
            {classes.map((cls) => {
              const isActive = activeClasse?.id === cls.id;
              const etudCount = classeEtudiants.filter(ce => ce.classe_id === cls.id && ce.statut === 'actif').length;
              const matCount = classeMatieres.filter(cm => cm.classe_id === cls.id).length;

              return (
                <div
                  key={cls.id}
                  onClick={() => setActiveClasse(cls)}
                  className={`
                    p-4 rounded-2xl border cursor-pointer transition-all
                    ${isActive
                      ? 'bg-secondary-container text-on-secondary-container border-primary shadow-sm'
                      : 'bg-surface-container border-outline-variant hover:bg-surface-container-high/50 text-on-surface'
                    }
                  `}
                >
                  <div className="flex items-start justify-between">
                    <div>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-surface-container-highest text-on-surface">
                        {cls.niveau}
                      </span>
                      <h4 className="font-bold text-sm mt-1.5 leading-snug">{cls.nom}</h4>
                      <p className="text-xs text-on-surface-variant">{cls.code} • {cls.filiere}</p>
                    </div>
                  </div>

                  <div className="mt-3 pt-3 flex justify-between text-xs border-t border-outline-variant text-on-surface-variant">
                    <span className="flex items-center gap-1 font-medium">
                      <Users className="w-3.5 h-3.5" />
                      <strong>{etudCount}</strong> étudiants
                    </span>
                    <span className="flex items-center gap-1 font-medium">
                      <BookOpen className="w-3.5 h-3.5" />
                      <strong>{matCount}</strong> cours
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Colonne droite : Détails de la classe */}
        <div className="lg:col-span-8 bg-surface-container rounded-2xl border border-outline-variant p-6 space-y-6">
          {activeClasse ? (
            <>
              {/* Header de la classe active */}
              <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-4 border-b border-outline-variant gap-4">
                <div>
                  <h3 className="text-xl font-bold text-on-surface">{activeClasse.nom}</h3>
                  <p className="text-xs text-on-surface-variant mt-0.5">
                    {activeClasse.code} • {activeClasse.filiere} • Salle : {activeClasse.salle_principale || 'Amphi A'}
                  </p>
                </div>

                <div className="flex items-center bg-surface-container-lowest p-1 rounded-full border border-outline-variant">
                  <button
                    onClick={() => setActiveTab('etudiants')}
                    className={`px-4 py-1.5 rounded-full text-xs font-semibold transition-colors ${
                      activeTab === 'etudiants'
                        ? 'bg-primary text-on-primary'
                        : 'text-on-surface-variant hover:text-on-surface'
                    }`}
                  >
                    Étudiants ({etudiantsDeLaClasse.length})
                  </button>
                  <button
                    onClick={() => setActiveTab('matieres')}
                    className={`px-4 py-1.5 rounded-full text-xs font-semibold transition-colors ${
                      activeTab === 'matieres'
                        ? 'bg-primary text-on-primary'
                        : 'text-on-surface-variant hover:text-on-surface'
                    }`}
                  >
                    Matières & Profs ({matieresDeLaClasse.length})
                  </button>
                </div>
              </div>

              {/* Onglet 1 : Étudiants affectés */}
              {activeTab === 'etudiants' && (
                <div className="space-y-4">
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-on-surface-variant">
                      Effectif actuel : <strong>{etudiantsDeLaClasse.length}</strong> / {activeClasse.capacite_max || 40} places
                    </p>

                    <button
                      onClick={() => {
                        setEtudFilterNiveau(activeClasse.niveau);
                        setEtudFilterOption('');
                        setEtudFilterFiliere('');
                        setEtudSearchQuery('');
                        setSelectedEtudiantIds([]);
                        setIsAssignEtudiantsModalOpen(true);
                      }}
                      className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full text-xs font-medium hover:bg-primary/90 transition-colors shadow-xs"
                    >
                      <UserCheck className="w-4 h-4" />
                      <span>Affecter des Étudiants</span>
                    </button>
                  </div>

                  {etudiantsDeLaClasse.length === 0 ? (
                    <div className="p-8 text-center border-2 border-dashed border-outline-variant rounded-2xl bg-surface-container-lowest">
                      <Users className="w-8 h-8 mx-auto text-on-surface-variant mb-2" />
                      <p className="text-xs text-on-surface-variant">Aucun étudiant n'est encore affecté à cette classe.</p>
                      <button
                        onClick={() => {
                          setEtudFilterNiveau(activeClasse.niveau);
                          setEtudFilterOption('');
                          setEtudFilterFiliere('');
                          setEtudSearchQuery('');
                          setSelectedEtudiantIds([]);
                          setIsAssignEtudiantsModalOpen(true);
                        }}
                        className="mt-3 text-xs text-primary font-medium hover:underline"
                      >
                        + Sélectionner des étudiants maintenant
                      </button>
                    </div>
                  ) : (
                    <div className="bg-surface-container-lowest border border-outline-variant rounded-xl divide-y divide-outline-variant overflow-hidden">
                      {etudiantsDeLaClasse.map((etud, idx) => (
                        <div key={etud.id} className="p-3 flex justify-between items-center hover:bg-surface-container-high/40 transition-colors">
                          <div className="flex items-center gap-3">
                            <span className="text-xs font-bold text-on-surface-variant w-5">{idx + 1}.</span>
                            <div className="w-8 h-8 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center">
                              {etud.nom?.[0]}
                            </div>
                            <div>
                              <p className="text-xs font-bold text-on-surface">{etud.nom.toUpperCase()} {etud.prenom}</p>
                              <p className="text-[11px] text-on-surface-variant">{etud.matricule} • {etud.niveau} • {etud.filiere}</p>
                            </div>
                          </div>

                          <button
                            onClick={() => handleRemoveEtudiantFromClasse(etud.id)}
                            title="Retirer de cette classe"
                            className="text-on-surface-variant hover:text-error p-1.5 rounded-lg transition-colors"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {/* Onglet 2 : Matières & Profs */}
              {activeTab === 'matieres' && (
                <div className="space-y-4">
                  <div className="flex justify-between items-center">
                    <p className="text-xs text-on-surface-variant">
                      Programme pédagogique & Enseignants attitrés
                    </p>

                    <button
                      onClick={() => setIsAssignMatiereModalOpen(true)}
                      className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full text-xs font-medium hover:bg-primary/90 transition-colors shadow-xs"
                    >
                      <Plus className="w-4 h-4" />
                      <span>Ajouter une Matière</span>
                    </button>
                  </div>

                  {matieresDeLaClasse.length === 0 ? (
                    <div className="p-8 text-center border-2 border-dashed border-outline-variant rounded-2xl bg-surface-container-lowest">
                      <BookOpen className="w-8 h-8 mx-auto text-on-surface-variant mb-2" />
                      <p className="text-xs text-on-surface-variant">Aucune matière rattachée à cette classe.</p>
                      <button
                        onClick={() => setIsAssignMatiereModalOpen(true)}
                        className="mt-3 text-xs text-primary font-medium hover:underline"
                      >
                        + Rattacher un cours du catalogue
                      </button>
                    </div>
                  ) : (
                    <div className="bg-surface-container-lowest border border-outline-variant rounded-xl divide-y divide-outline-variant overflow-hidden">
                      {matieresDeLaClasse.map((item) => (
                        <div key={item.id} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                          <div>
                            <div className="flex items-center gap-2">
                              <span className="px-2 py-0.5 bg-surface-container-highest text-on-surface text-[10px] font-bold rounded">
                                {item.matiere?.code}
                              </span>
                              <h5 className="font-bold text-xs text-on-surface">{item.matiere?.nom}</h5>
                            </div>
                            <p className="text-[11px] text-on-surface-variant mt-1">
                              Crédits : {item.credits} • Coeff : {item.coefficient} • Semestre : {item.semestre}
                            </p>
                          </div>

                          <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-on-surface-variant">Professeur :</span>
                            <select
                              value={item.enseignant_id || ''}
                              onChange={(e) => handleUpdateProfForMatiere(item.id, e.target.value)}
                              className="p-1.5 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
                            >
                              <option value="">-- Non assigné --</option>
                              {personnel.map(prof => (
                                <option key={prof.id} value={prof.id}>
                                  {prof.nom.toUpperCase()} {prof.prenom}
                                </option>
                              ))}
                            </select>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </>
          ) : (
            <div className="p-12 text-center text-on-surface-variant">
              Sélectionnez une classe à gauche.
            </div>
          )}
        </div>
      </div>

      {/* Modal Créer Classe */}
      {isNewClasseModalOpen && (
        <div className="fixed inset-0 z-50 bg-on-surface/20 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest rounded-3xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl space-y-4 text-xs">
            <div className="flex justify-between items-center pb-3 border-b border-outline-variant">
              <h3 className="text-base font-bold text-on-surface">Créer une Nouvelle Classe</h3>
              <button onClick={() => setIsNewClasseModalOpen(false)}>
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveClasse} className="space-y-3">
              <div>
                <label className="font-semibold block mb-1">Nom de la classe *</label>
                <input
                  type="text"
                  required
                  placeholder="Ex: L1 Tronc Commun Technologie"
                  value={classeForm.nom || ''}
                  onChange={(e) => setClasseForm({ ...classeForm, nom: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold block mb-1">Code Classe *</label>
                  <input
                    type="text"
                    required
                    placeholder="Ex: L1-TCT"
                    value={classeForm.code || ''}
                    onChange={(e) => setClasseForm({ ...classeForm, code: e.target.value })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary uppercase"
                  />
                </div>
                <div>
                  <label className="font-semibold block mb-1">Niveau</label>
                  <select
                    value={classeForm.niveau || 'Licence 1'}
                    onChange={(e) => setClasseForm({ ...classeForm, niveau: e.target.value })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                  >
                    <option value="Licence 1">Licence 1 (L1)</option>
                    <option value="Licence 2">Licence 2 (L2)</option>
                    <option value="Licence 3">Licence 3 (L3)</option>
                    <option value="Master 1">Master 1 (M1)</option>
                    <option value="Master 2">Master 2 (M2)</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold block mb-1">Filière</label>
                  <input
                    type="text"
                    placeholder="Ex: Informatique"
                    value={classeForm.filiere || ''}
                    onChange={(e) => setClasseForm({ ...classeForm, filiere: e.target.value })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                  />
                </div>
                <div>
                  <label className="font-semibold block mb-1">Salle Principale</label>
                  <input
                    type="text"
                    placeholder="Ex: Amphi A"
                    value={classeForm.salle_principale || ''}
                    onChange={(e) => setClasseForm({ ...classeForm, salle_principale: e.target.value })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button
                  type="button"
                  onClick={() => setIsNewClasseModalOpen(false)}
                  className="px-4 py-2 rounded-full border border-outline-variant font-medium"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-full bg-primary text-on-primary font-medium hover:bg-primary/90"
                >
                  Créer la classe
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Affectation Étudiants (Cases à cocher) */}
      {isAssignEtudiantsModalOpen && activeClasse && (
        <div className="fixed inset-0 z-50 bg-on-surface/20 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest rounded-3xl border border-outline-variant max-w-2xl w-full p-6 shadow-2xl flex flex-col max-h-[85vh] text-xs">
            <div className="flex justify-between items-center pb-3 border-b border-outline-variant">
              <div>
                <h3 className="text-base font-bold text-on-surface">
                  Affecter des Étudiants dans {activeClasse.nom}
                </h3>
                <p className="text-[11px] text-on-surface-variant">
                  Cochez les étudiants puis cliquez sur "Ajouter à la classe"
                </p>
              </div>
              <button onClick={() => setIsAssignEtudiantsModalOpen(false)}>
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Filtres dans la modale */}
            <div className="py-3 space-y-2.5 border-b border-outline-variant">
              {/* Ligne 1 : Recherche + Niveau */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div className="relative">
                  <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    placeholder="Rechercher nom, matricule..."
                    value={etudSearchQuery}
                    onChange={(e) => setEtudSearchQuery(e.target.value)}
                    className="w-full pl-9 pr-3 py-2 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none"
                  />
                </div>

                <select
                  value={etudFilterNiveau}
                  onChange={(e) => setEtudFilterNiveau(e.target.value)}
                  className="p-2 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
                >
                  <option value="">Tous les niveaux</option>
                  <option value="Licence 1">Licence 1 (L1)</option>
                  <option value="Licence 2">Licence 2 (L2)</option>
                  <option value="Licence 3">Licence 3 (L3)</option>
                  <option value="Master 1">Master 1 (M1)</option>
                  <option value="Master 2">Master 2 (M2)</option>
                </select>
              </div>

              {/* Ligne 2 : Option + Filière + Compteur */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <select
                  value={etudFilterOption}
                  onChange={(e) => {
                    setEtudFilterOption(e.target.value);
                    setEtudFilterFiliere(''); // Reset filière quand option change
                  }}
                  className="p-2 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
                >
                  <option value="">Toutes les options</option>
                  {OPTIONS_ISGI.map(opt => (
                    <option key={opt} value={opt}>{opt}</option>
                  ))}
                </select>

                <select
                  value={etudFilterFiliere}
                  onChange={(e) => setEtudFilterFiliere(e.target.value)}
                  className="p-2 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
                  disabled={filieresDisponibles.length === 0}
                >
                  <option value="">Toutes les filières</option>
                  {filieresDisponibles.map(fil => (
                    <option key={fil} value={fil}>{fil}</option>
                  ))}
                </select>

                <div className="flex items-center justify-between">
                  <button
                    type="button"
                    onClick={toggleSelectAllEligible}
                    className="text-primary font-medium hover:underline text-xs"
                  >
                    {selectedEtudiantIds.length === eligibleEtudiants.length && eligibleEtudiants.length > 0
                      ? 'Tout décocher'
                      : `Tout cocher (${eligibleEtudiants.length})`}
                  </button>
                  <span className="font-bold text-on-surface bg-surface-container-highest px-2 py-1 rounded-md">
                    {selectedEtudiantIds.length} sélectionné(s)
                  </span>
                </div>
              </div>
            </div>

            {/* Liste déroulante des étudiants avec cases à cocher */}
            <div className="flex-1 overflow-y-auto divide-y divide-outline-variant py-2">
              {eligibleEtudiants.length === 0 ? (
                <div className="p-8 text-center text-on-surface-variant">
                  Aucun étudiant éligible trouvé pour ce filtre ({etudFilterNiveau || 'Tous'}).
                </div>
              ) : (
                eligibleEtudiants.map(etud => {
                  const isChecked = selectedEtudiantIds.includes(etud.id);
                  return (
                    <div
                      key={etud.id}
                      onClick={() => toggleSelectEtudiant(etud.id)}
                      className={`p-3 flex justify-between items-center cursor-pointer rounded-xl transition-colors ${
                        isChecked
                          ? 'bg-secondary-container text-on-secondary-container'
                          : 'hover:bg-surface-container-high/40 text-on-surface'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <div className="text-primary">
                          {isChecked ? <CheckSquare className="w-5 h-5" /> : <Square className="w-5 h-5 text-outline-variant" />}
                        </div>
                        <div>
                          <p className="font-bold text-sm">{etud.nom.toUpperCase()} {etud.prenom}</p>
                          <p className="text-[11px] opacity-75">
                            {etud.matricule} • <span className="font-semibold">{etud.niveau}</span> • {etud.filiere}
                          </p>
                        </div>
                      </div>

                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-surface-container-highest text-on-surface">
                        {etud.option || 'Tronc commun'}
                      </span>
                    </div>
                  );
                })
              )}
            </div>

            {/* Pied de modale avec validation */}
            <div className="flex justify-between items-center pt-3 border-t border-outline-variant">
              <span className="font-semibold text-on-surface">
                Total à ajouter : {selectedEtudiantIds.length}
              </span>

              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setIsAssignEtudiantsModalOpen(false)}
                  className="px-4 py-2 rounded-full border border-outline-variant font-medium"
                >
                  Annuler
                </button>
                <button
                  type="button"
                  onClick={handleAssignSelectedEtudiants}
                  disabled={selectedEtudiantIds.length === 0}
                  className="px-5 py-2 rounded-full bg-primary text-on-primary font-medium hover:bg-primary/90 disabled:opacity-50 transition-colors"
                >
                  Ajouter à la classe ({selectedEtudiantIds.length})
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal Associer Matière */}
      {isAssignMatiereModalOpen && activeClasse && (
        <div className="fixed inset-0 z-50 bg-on-surface/20 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest rounded-3xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4 text-xs">
            <div className="flex justify-between items-center pb-3 border-b border-outline-variant">
              <h3 className="text-base font-bold text-on-surface">Rattacher une Matière</h3>
              <button onClick={() => setIsAssignMatiereModalOpen(false)}>
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleAddMatiereToClasse} className="space-y-3">
              <div>
                <label className="font-semibold block mb-1">Matière *</label>
                <select
                  required
                  value={matiereForm.matiere_id}
                  onChange={(e) => setMatiereForm({ ...matiereForm, matiere_id: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none"
                >
                  <option value="">-- Sélectionner une matière --</option>
                  {matieres.map(m => (
                    <option key={m.id} value={m.id}>[{m.code}] {m.nom}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="font-semibold block mb-1">Enseignant Responsable</label>
                <select
                  value={matiereForm.enseignant_id}
                  onChange={(e) => setMatiereForm({ ...matiereForm, enseignant_id: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none"
                >
                  <option value="">-- Assigner plus tard --</option>
                  {personnel.map(prof => (
                    <option key={prof.id} value={prof.id}>{prof.nom.toUpperCase()} {prof.prenom}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="font-semibold block mb-1">Semestre</label>
                <select
                  value={matiereForm.semestre}
                  onChange={(e) => setMatiereForm({ ...matiereForm, semestre: e.target.value as any })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none"
                >
                  <option value="S1">Semestre 1 (S1)</option>
                  <option value="S2">Semestre 2 (S2)</option>
                  <option value="Annuel">Annuel</option>
                </select>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button
                  type="button"
                  onClick={() => setIsAssignMatiereModalOpen(false)}
                  className="px-4 py-2 rounded-full border border-outline-variant font-medium"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-full bg-primary text-on-primary font-medium hover:bg-primary/90"
                >
                  Rattacher
                </button>
              </div>
            </form>
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
