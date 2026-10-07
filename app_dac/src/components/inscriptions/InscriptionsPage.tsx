import { useState } from 'react';
import { Search, Plus, X, Eye, Download, UserPlus, QrCode } from 'lucide-react';
import { db, logAction } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { exportToPDF } from '../../utils/pdfExport';
import { supabase } from '../../db/supabaseClient';
import StudentIdentityQr, { type StudentQrIdentity } from './StudentIdentityQr';

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

const CYCLES = ["Licence", "Master", "Cycle 1", "Cycle 2"];
const NIVEAUX: Record<string, string[]> = {
  "Licence": ["Licence 1", "Licence 2", "Licence 3"],
  "Master": ["Master 1", "Master 2"],
  "Cycle 1": ["Cycle 1 (Année 1)", "Cycle 1 (Année 2)", "Cycle 1 (Année 3)"],
  "Cycle 2": ["Cycle 2 (Année 1)", "Cycle 2 (Année 2)"]
};

export default function InscriptionsPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [activeTab, setActiveTab] = useState(0);
  const [viewingEtudiant, setViewingEtudiant] = useState<any>(null);
  const [registeredEtudiant, setRegisteredEtudiant] = useState<StudentQrIdentity | null>(null);
  const [matriculeError, setMatriculeError] = useState('');

  const initialFormData = {
    nom: '', prenom: '', sexe: 'M', date_naissance: '', lieu_naissance: '',
    nationalite: 'CONGOLAISE', adresse: '', ville: 'Brazzaville', pays: 'Congo',
    telephone: '', email: '', numero_cni: '', profession: 'Etudiant', situation_matrimoniale: 'Célibataire',

    nom_pere: '', profession_pere: '', nom_mere: '', profession_mere: '',
    nom_tuteur: '', profession_tuteur: '', telephone_tuteur: '', lieu_service_tuteur: '',

    type_etudiant: 'NORMAL', annee_obtention_bac: new Date().getFullYear(),
    serie_bac: 'A', option: 'Gestion et Administration',
    filiere: "Comptabilité et gestion d'entreprise", niveau: 'Licence 1',
    vague: 'Jour',
    cycle_formation: 'Licence', rentree: 'OCTOBRE', annee_academique: '2026-2027', site_formation: 'ISGI',

    docs_dossier_candidature: false,
    docs_acte_naissance: false,
    docs_photos: false,
    docs_cni: false,
    docs_rame: false,
    docs_markers: false,
    docs_enveloppe: false,
    docs_diplome: false,
    docs_releves_notes: false,
    docs_frais_inscription: false,
    docs_polo: false,
    docs_carte_etudiant: false,
    docs_frais_stages: false,
    docs_frais_examens: false,
    docs_frais_tptd: false,
    docs_assurance: false,
  };

  const [formData, setFormData] = useState(initialFormData);

  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []);

  const filteredEtudiants = etudiants?.filter(e => {
    const matchesSearch =
      e.nom.toLowerCase().includes(searchTerm.toLowerCase()) ||
      e.prenom.toLowerCase().includes(searchTerm.toLowerCase()) ||
      e.matricule.toLowerCase().includes(searchTerm.toLowerCase());
    return matchesSearch;
  }) || [];

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target;
    if (type === 'checkbox') {
      const checked = (e.target as HTMLInputElement).checked;
      setFormData(prev => ({ ...prev, [name]: checked }));
    } else {
      setFormData(prev => {
        const newData = {
          ...prev,
          [name]: name === 'annee_obtention_bac' ? parseInt(value) || new Date().getFullYear() : value
        };
        if (name === 'option') {
          newData.filiere = OPTION_FILIERES[value]?.[0] || '';
        }
        if (name === 'cycle_formation') {
          newData.niveau = NIVEAUX[value]?.[0] || '';
        }
        return newData;
      });
    }
  };

  const handleOpenModal = () => {
    let settings: Record<string, unknown> = {};
    try {
      settings = JSON.parse(localStorage.getItem('isgi_settings') || '{}');
    } catch (error) {
      console.error('Impossible de lire les paramètres DAC:', error);
    }
    setFormData({
      ...initialFormData,
      annee_academique: typeof settings.anneeAcademique === 'string' && settings.anneeAcademique
        ? settings.anneeAcademique
        : initialFormData.annee_academique
    });
    setActiveTab(0);
    setMatriculeError('');
    setIsModalOpen(true);
  };

  const handleNextOrSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (activeTab < 3) {
      setActiveTab(activeTab + 1);
      return;
    }

    let settings: Record<string, unknown> = {};
    try {
      settings = JSON.parse(localStorage.getItem('isgi_settings') || '{}');
    } catch (error) {
      console.error('Impossible de lire les paramètres DAC:', error);
    }
    const matriculePrefix = typeof settings.matriculePrefix === 'string' && settings.matriculePrefix.trim()
      ? settings.matriculePrefix.trim().toUpperCase()
      : 'ISGI-2627-';
    if (!/^[A-Z0-9]+(?:-[A-Z0-9]+)*-$/.test(matriculePrefix)) {
      setMatriculeError('Le préfixe matricule est invalide. Corrigez-le dans les paramètres du DAC (ex. ISGI-2627-).');
      return;
    }
    setMatriculeError('');
    const existingStudents = await db.etudiants.toArray();
    const lastSequence = existingStudents.reduce((highest, student) => {
      if (!student.matricule.startsWith(matriculePrefix)) return highest;
      const sequence = Number(student.matricule.slice(matriculePrefix.length));
      return Number.isInteger(sequence) ? Math.max(highest, sequence) : highest;
    }, 0);
    const newMatricule = `${matriculePrefix}${(lastSequence + 1).toString().padStart(4, '0')}`;
    const newId = crypto.randomUUID();

    const etudiantData = {
      id: newId,
      matricule: newMatricule,
      nom: formData.nom, prenom: formData.prenom, sexe: formData.sexe,
      date_naissance: formData.date_naissance, lieu_naissance: formData.lieu_naissance,
      nationalite: formData.nationalite, adresse: formData.adresse, ville: formData.ville,
      pays: formData.pays, telephone: formData.telephone, email: formData.email,
      numero_cni: formData.numero_cni, profession: formData.profession,
      situation_matrimoniale: formData.situation_matrimoniale,
      nom_pere: formData.nom_pere, profession_pere: formData.profession_pere,
      nom_mere: formData.nom_mere, profession_mere: formData.profession_mere,
      nom_tuteur: formData.nom_tuteur, profession_tuteur: formData.profession_tuteur,
      telephone_tuteur: formData.telephone_tuteur, lieu_service_tuteur: formData.lieu_service_tuteur,
      type_etudiant: formData.type_etudiant,
      annee_obtention_bac: formData.annee_obtention_bac,
      serie_bac: formData.serie_bac,
      option: formData.option, filiere: formData.filiere, niveau: formData.niveau,
      vague: formData.vague || 'Jour',
      cycle_formation: formData.cycle_formation, rentree: formData.rentree,
      annee_academique: formData.annee_academique,
      site_formation: formData.site_formation,
      is_synced: 0 as const,
      last_modified_at: new Date().toISOString(),
      documents_physiques: JSON.stringify({
        dossier_candidature: formData.docs_dossier_candidature,
        acte_naissance: formData.docs_acte_naissance,
        photos: formData.docs_photos,
        cni: formData.docs_cni,
        rame: formData.docs_rame,
        markers: formData.docs_markers,
        enveloppe: formData.docs_enveloppe,
        diplome: formData.docs_diplome,
        releves_notes: formData.docs_releves_notes,
        frais_inscription: formData.docs_frais_inscription,
        polo: formData.docs_polo,
        carte_etudiant: formData.docs_carte_etudiant,
        frais_stages: formData.docs_frais_stages,
        frais_examens: formData.docs_frais_examens,
        frais_tptd: formData.docs_frais_tptd,
        assurance: formData.docs_assurance
      })
    };

    await db.etudiants.add(etudiantData);
    const registeredStudent = {
      matricule: newMatricule,
      nom: formData.nom,
      prenom: formData.prenom
    };

    // Sync Supabase en arrière-plan
    supabase.from('etudiants').insert([etudiantData]).then(() => {}, () => {});

    await logAction('Inscription Étudiant', 'Inscriptions', `Nouvel étudiant inscrit : ${newMatricule} - ${formData.nom} ${formData.prenom} (${formData.vague || 'Jour'})`);

    setRegisteredEtudiant(registeredStudent);
    setIsModalOpen(false);
    setActiveTab(0);
  };

  const handleExportPDF = () => {
    const columns = [
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom', dataKey: 'nom' },
      { header: 'Prénom', dataKey: 'prenom' },
      { header: 'Vague', dataKey: 'vague' },
      { header: 'Sexe', dataKey: 'sexe' },
      { header: 'Téléphone', dataKey: 'telephone' },
      { header: 'Filière', dataKey: 'filiere' },
      { header: 'Niveau', dataKey: 'niveau' },
    ];
    exportToPDF('Liste des Étudiants Inscrits', columns, filteredEtudiants.map(e => ({
      ...e,
      vague: e.vague || 'Jour'
    })), 'isgi_inscriptions');
  };

  const inputClass = "w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant text-on-surface focus:ring-2 focus:ring-primary outline-none";
  const labelClass = "text-sm font-medium text-on-surface block mb-1";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Inscriptions des Étudiants</h2>
          <p className="text-on-surface-variant text-sm mt-1">Gestion complète des dossiers d'inscription</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={handleExportPDF}
            className="flex items-center gap-2 bg-surface-container text-on-surface border border-outline-variant px-4 py-2 rounded-full font-medium hover:bg-surface-container-highest transition-colors"
          >
            <Download className="w-5 h-5" />
            PDF
          </button>
          <button
            onClick={handleOpenModal}
            className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium hover:bg-primary/90 transition-colors"
          >
            <UserPlus className="w-5 h-5" />
            Nouvelle Inscription
          </button>
        </div>
      </div>

      {/* Liste étudiants */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
        {/* Barre de recherche */}
        <div className="p-4 border-b border-outline-variant flex flex-col md:flex-row gap-4">
          <div className="flex-1 relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par nom, prénom ou matricule..."
              className="w-full pl-10 pr-4 py-2 bg-surface-container-highest border-none rounded-full focus:ring-2 focus:ring-primary text-on-surface outline-none"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
          <span className="text-sm font-medium text-on-surface-variant self-center">
            {filteredEtudiants.length} étudiant(s)
          </span>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-surface-container-low border-b border-outline-variant">
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Matricule</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Nom complet</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Vague</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Sexe</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Niveau</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Filière</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredEtudiants.length === 0 ? (
                <tr>
                  <td colSpan={7} className="p-8 text-center text-on-surface-variant">
                    <Plus className="w-8 h-8 mx-auto mb-2 opacity-20" />
                    Aucun étudiant inscrit. Cliquez sur «Nouvelle Inscription» pour commencer.
                  </td>
                </tr>
              ) : (
                filteredEtudiants.map(etudiant => (
                  <tr key={etudiant.id} className="border-b border-outline-variant hover:bg-surface-container-lowest transition-colors">
                    <td className="p-4 text-on-surface font-medium font-mono">{etudiant.matricule}</td>
                    <td className="p-4 text-on-surface font-semibold">{etudiant.nom.toUpperCase()} {etudiant.prenom}</td>
                    <td className="p-4 text-sm">
                      {etudiant.vague?.toLowerCase().includes('soir') ? (
                        <span className="font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 border border-purple-200 text-xs inline-flex items-center gap-1">
                          🌙 Soir
                        </span>
                      ) : (
                        <span className="font-medium px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100 text-xs inline-flex items-center gap-1">
                          ☀️ Jour
                        </span>
                      )}
                    </td>
                    <td className="p-4 text-on-surface-variant">{etudiant.sexe === 'M' ? 'Masculin' : 'Féminin'}</td>
                    <td className="p-4 text-on-surface-variant">{etudiant.niveau}</td>
                    <td className="p-4 text-on-surface-variant max-w-[200px] truncate" title={etudiant.filiere}>{etudiant.filiere}</td>
                    <td className="p-4 text-right">
                      <button
                        onClick={() => setViewingEtudiant(etudiant)}
                        className="p-2 text-primary hover:bg-primary-container hover:text-on-primary-container rounded-lg transition-colors"
                        title="Voir les détails"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ===== MODAL INSCRIPTION ===== */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col shadow-xl">
            {/* Header modal */}
            <div className="bg-surface-container-lowest border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-bold text-on-surface">Fiche d'Inscription Complète</h3>
              <button onClick={() => setIsModalOpen(false)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>

            {/* Onglets */}
            <div className="flex border-b border-outline-variant bg-surface-container-lowest overflow-x-auto shrink-0">
              {['1. Identité', '2. Parents & Tuteur', '3. Parcours Académique', '4. Pièces jointes'].map((tab, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => setActiveTab(i)}
                  className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${activeTab === i ? 'text-primary border-b-2 border-primary' : 'text-on-surface-variant hover:text-on-surface'}`}
                >
                  {tab}
                </button>
              ))}
            </div>

            <form onSubmit={handleNextOrSubmit} className="flex flex-col flex-1 min-h-0">
              {matriculeError && <p role="alert" className="mx-6 mt-4 rounded-lg bg-error-container p-3 text-sm text-on-error-container">{matriculeError}</p>}
              <div className="overflow-y-auto p-4 md:p-6 flex-1 min-h-0">

                {/* TAB 1: IDENTITÉ */}
                {activeTab === 0 && (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div><label className={labelClass}>Nom(s) *</label><input required name="nom" value={formData.nom} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Prénom(s) *</label><input required name="prenom" value={formData.prenom} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Sexe</label>
                      <select name="sexe" value={formData.sexe} onChange={handleInputChange} className={inputClass}>
                        <option value="M">Masculin</option><option value="F">Féminin</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Date de Naissance *</label><input required name="date_naissance" value={formData.date_naissance} onChange={handleInputChange} type="date" className={inputClass} /></div>
                    <div><label className={labelClass}>Lieu de Naissance</label><input name="lieu_naissance" value={formData.lieu_naissance} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Nationalité</label><input name="nationalite" value={formData.nationalite} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div className="md:col-span-2"><label className={labelClass}>Adresse</label><input name="adresse" value={formData.adresse} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Ville</label><input name="ville" value={formData.ville} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Téléphone</label><input name="telephone" value={formData.telephone} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>E-mail</label><input name="email" value={formData.email} onChange={handleInputChange} type="email" className={inputClass} /></div>
                    <div><label className={labelClass}>Numéro CNI</label><input name="numero_cni" value={formData.numero_cni} onChange={handleInputChange} type="text" className={inputClass} /></div>
                    <div><label className={labelClass}>Situation Matrimoniale</label>
                      <select name="situation_matrimoniale" value={formData.situation_matrimoniale} onChange={handleInputChange} className={inputClass}>
                        <option value="Célibataire">Célibataire</option>
                        <option value="Marié(e)">Marié(e)</option>
                        <option value="Divorcé(e)">Divorcé(e)</option>
                      </select>
                    </div>
                  </div>
                )}

                {/* TAB 2: PARENTS & TUTEUR */}
                {activeTab === 1 && (
                  <div className="space-y-6">
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations du Père</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom *</label><input required name="nom_pere" value={formData.nom_pere} onChange={handleInputChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession *</label><input required name="profession_pere" value={formData.profession_pere} onChange={handleInputChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations de la Mère</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom *</label><input required name="nom_mere" value={formData.nom_mere} onChange={handleInputChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession *</label><input required name="profession_mere" value={formData.profession_mere} onChange={handleInputChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations du Tuteur</h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label className={labelClass}>Nom et Prénom</label><input name="nom_tuteur" value={formData.nom_tuteur} onChange={handleInputChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Profession</label><input name="profession_tuteur" value={formData.profession_tuteur} onChange={handleInputChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Téléphone Tuteur</label><input name="telephone_tuteur" value={formData.telephone_tuteur} onChange={handleInputChange} type="text" className={inputClass} /></div>
                        <div><label className={labelClass}>Lieu de service</label><input name="lieu_service_tuteur" value={formData.lieu_service_tuteur} onChange={handleInputChange} type="text" className={inputClass} /></div>
                      </div>
                    </div>
                  </div>
                )}

                {/* TAB 3: ACADÉMIQUE */}
                {activeTab === 2 && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label className={labelClass}>Type d'étudiant</label>
                      <select name="type_etudiant" value={formData.type_etudiant} onChange={handleInputChange} className={inputClass}>
                        <option value="NORMAL">Normal</option><option value="BOURSIER">Boursier</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Année d'obtention du BAC *</label>
                      <input required name="annee_obtention_bac" value={formData.annee_obtention_bac} onChange={handleInputChange} type="number" min="1980" max="2050" className={inputClass} />
                    </div>
                    <div><label className={labelClass}>Série du BAC</label>
                      <select name="serie_bac" value={formData.serie_bac} onChange={handleInputChange} className={inputClass}>
                        {['A', 'C', 'D', 'E', 'F1', 'F2', 'F3', 'F4', 'G1', 'G2', 'G3', 'BG', 'H1', 'H2', 'H3', 'H4', 'H5', 'SANS BAC'].map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Option (Domaine)</label>
                      <select name="option" value={formData.option} onChange={handleInputChange} className={inputClass}>
                        {Object.keys(OPTION_FILIERES).map(opt => <option key={opt} value={opt}>{opt}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Filière</label>
                      <select name="filiere" value={formData.filiere} onChange={handleInputChange} className={inputClass}>
                        {(OPTION_FILIERES[formData.option] || []).map(fil => <option key={fil} value={fil}>{fil}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Cycle Formation</label>
                      <select name="cycle_formation" value={formData.cycle_formation} onChange={handleInputChange} className={inputClass}>
                        {CYCLES.map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Niveau</label>
                      <select name="niveau" value={formData.niveau} onChange={handleInputChange} className={inputClass}>
                        {(NIVEAUX[formData.cycle_formation] || []).map(n => <option key={n} value={n}>{n}</option>)}
                      </select>
                    </div>
                    <div><label className={labelClass}>Rentrée</label>
                      <select name="rentree" value={formData.rentree} onChange={handleInputChange} className={inputClass}>
                        <option value="OCTOBRE">Octobre</option><option value="JANVIER">Janvier</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Année Académique</label>
                      <select name="annee_academique" value={formData.annee_academique} onChange={handleInputChange} className={inputClass}>
                        <option value="2025-2026">2025-2026</option>
                        <option value="2026-2027">2026-2027</option>
                        <option value="2027-2028">2027-2028</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Vague d'études *</label>
                      <select name="vague" value={formData.vague} onChange={handleInputChange} className={inputClass}>
                        <option value="Jour">☀️ Cours du Jour</option>
                        <option value="Soir">🌙 Cours du Soir</option>
                      </select>
                    </div>
                    <div><label className={labelClass}>Site de Formation</label>
                      <input name="site_formation" value={formData.site_formation} onChange={handleInputChange} type="text" className={inputClass} />
                    </div>
                  </div>
                )}

                {/* TAB 4: DOCUMENTS */}
                {activeTab === 3 && (
                  <div>
                    <h4 className="text-base font-semibold text-on-surface mb-3 border-b border-outline-variant pb-2">Constitution du dossier</h4>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      {[
                        { name: 'docs_dossier_candidature', label: 'Dossier de candidature dûment remplis en lettres majuscules' },
                        { name: 'docs_acte_naissance', label: "Copie d'Actes de Naissance ou l'Extrait d'actes de Naissance" },
                        { name: 'docs_photos', label: "02 Photos d'identités portant au verso ses coordonnées" },
                        { name: 'docs_cni', label: 'Photocopie de la C.N.I / passeport / NIU' },
                        { name: 'docs_rame', label: '01 Paquet de Rame' },
                        { name: 'docs_markers', label: '01 Paquet de Markers Tableau' },
                        { name: 'docs_enveloppe', label: 'Une enveloppe kaki format A4 et une Chemise Cartonnée' },
                        { name: 'docs_diplome', label: 'Une copie du dernier diplôme' },
                        { name: 'docs_releves_notes', label: 'Relevés des notes de la dernière classe suivie' },
                        { name: 'docs_frais_inscription', label: "Frais d'inscription ............................. 25.000 F (Non remboursable)" },
                        { name: 'docs_polo', label: 'Un polo vendu à ............................. 5.000 FCFA sur place' },
                        { name: 'docs_carte_etudiant', label: "Une carte d'étudiant ............................. 5.000 FCFA" },
                        { name: 'docs_frais_stages', label: 'FRAIS DE STAGES ............................. 35.000 FCFA' },
                        { name: 'docs_frais_examens', label: "Frais d'examens ..................... 20.000 F pour les deux semestres" },
                        { name: 'docs_frais_tptd', label: 'Frais des TP/TD .............................. 30.000 FCFA pour les deux semestres' },
                        { name: 'docs_assurance', label: 'Assurance .................................. 1.500 F' }
                      ].map((doc) => (
                        <label key={doc.name} className="flex items-center gap-3 p-3 rounded-xl border border-outline-variant bg-surface-container-lowest cursor-pointer hover:bg-surface-container-low transition-colors">
                          <input
                            type="checkbox"
                            name={doc.name}
                            checked={formData[doc.name as keyof typeof formData] as boolean}
                            onChange={handleInputChange}
                            className="w-5 h-5 rounded border-outline-variant text-primary focus:ring-primary flex-shrink-0"
                          />
                          <span className="text-sm text-on-surface">{doc.label}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {/* Footer actions */}
              <div className="p-4 border-t border-outline-variant bg-surface-container-lowest shrink-0 flex justify-between items-center">
                <div>
                  {activeTab > 0 && (
                    <button type="button" onClick={() => setActiveTab(activeTab - 1)}
                      className="px-4 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                      Précédent
                    </button>
                  )}
                </div>
                <div className="flex gap-3">
                  <button type="button" onClick={() => setIsModalOpen(false)}
                    className="px-4 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                    Annuler
                  </button>
                  {activeTab < 3 ? (
                    <button type="submit"
                      className="px-6 py-2 rounded-full font-medium bg-secondary text-on-secondary hover:bg-secondary/90 transition-colors shadow-sm">
                      Suivant
                    </button>
                  ) : (
                    <button type="submit"
                      className="px-6 py-2 rounded-full font-medium bg-primary text-on-primary hover:bg-primary/90 transition-colors shadow-sm">
                      Valider l'inscription
                    </button>
                  )}
                </div>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ===== MODAL VOIR DÉTAILS ===== */}
      {viewingEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-5xl max-h-[95vh] shadow-xl flex flex-col overflow-hidden">
            <div className="bg-surface-container-lowest border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-semibold text-on-surface">Dossier — {viewingEtudiant.matricule}</h3>
              <button onClick={() => setViewingEtudiant(null)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>

            <div className="p-4 md:p-6 overflow-y-auto flex-1 min-h-0">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {/* Identité */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Identité</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Nom complet</span><p className="font-medium text-on-surface">{viewingEtudiant.nom} {viewingEtudiant.prenom}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Sexe</span><p className="font-medium text-on-surface">{viewingEtudiant.sexe === 'M' ? 'Masculin' : 'Féminin'}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Date & Lieu de naissance</span><p className="font-medium text-on-surface">{viewingEtudiant.date_naissance} à {viewingEtudiant.lieu_naissance}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Nationalité</span><p className="font-medium text-on-surface">{viewingEtudiant.nationalite}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Téléphone</span><p className="font-medium text-on-surface">{viewingEtudiant.telephone}</p></div>
                  </div>
                </div>

                {/* Contacts & Parents */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Contacts & Famille</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Email</span><p className="font-medium text-on-surface">{viewingEtudiant.email || 'Non renseigné'}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Adresse</span><p className="font-medium text-on-surface">{viewingEtudiant.adresse}, {viewingEtudiant.ville}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Père</span><p className="font-medium text-on-surface">{viewingEtudiant.nom_pere} ({viewingEtudiant.profession_pere})</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Mère</span><p className="font-medium text-on-surface">{viewingEtudiant.nom_mere} ({viewingEtudiant.profession_mere})</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Tuteur</span><p className="font-medium text-on-surface">{viewingEtudiant.nom_tuteur || '—'} — {viewingEtudiant.telephone_tuteur || '—'}</p></div>
                  </div>
                </div>

                {/* Académique */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Parcours Académique</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Type</span><p className="font-medium text-on-surface">{viewingEtudiant.type_etudiant}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Option & Filière</span><p className="font-medium text-on-surface">{viewingEtudiant.option}<br />{viewingEtudiant.filiere}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Niveau & Cycle</span><p className="font-medium text-on-surface">{viewingEtudiant.niveau} ({viewingEtudiant.cycle_formation})</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">BAC</span><p className="font-medium text-on-surface">Série {viewingEtudiant.serie_bac} — {viewingEtudiant.annee_obtention_bac}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Année académique</span><p className="font-medium text-on-surface">{viewingEtudiant.annee_academique}</p></div>
                    <div>
                      <span className="text-on-surface-variant text-xs uppercase tracking-wider block">Vague d'études</span>
                      <p className="font-medium text-on-surface">
                        {viewingEtudiant.vague?.toLowerCase().includes('soir') ? (
                          <span className="text-purple-700 bg-purple-50 px-2.5 py-0.5 rounded-md border border-purple-200 text-xs inline-flex items-center gap-1 font-semibold">
                            🌙 Cours du Soir
                          </span>
                        ) : (
                          <span className="text-blue-700 bg-blue-50 px-2.5 py-0.5 rounded-md border border-blue-200 text-xs inline-flex items-center gap-1 font-semibold">
                            ☀️ Cours du Jour
                          </span>
                        )}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              <div className="mt-6 border-t border-outline-variant pt-6">
                <h4 className="mb-4 text-center text-base font-bold text-primary">Code QR d'identification</h4>
                <StudentIdentityQr student={viewingEtudiant} />
              </div>
            </div>
          </div>
        </div>
      )}

      {registeredEtudiant && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-on-surface/60">
          <div className="w-full max-w-md rounded-2xl bg-surface-container-lowest p-6 shadow-xl">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h3 className="text-xl font-bold text-on-surface">Inscription enregistrée</h3>
                <p className="mt-1 text-sm text-on-surface-variant">Le QR d'identification est créé avec l'étudiant.</p>
              </div>
              <button onClick={() => setRegisteredEtudiant(null)} className="rounded-full p-2 hover:bg-surface-container-highest" aria-label="Fermer">
                <X className="h-5 w-5 text-on-surface-variant" />
              </button>
            </div>
            <div className="mb-4 flex items-center justify-center gap-2 text-primary">
              <QrCode className="h-5 w-5" />
              <span className="font-semibold">{registeredEtudiant.matricule}</span>
            </div>
            <StudentIdentityQr student={registeredEtudiant} />
            <button onClick={() => setRegisteredEtudiant(null)} className="mt-5 w-full rounded-full border border-outline-variant px-4 py-2 font-medium text-on-surface hover:bg-surface-container-highest">
              Terminer
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
