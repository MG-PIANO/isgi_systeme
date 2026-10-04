import { useState } from 'react';
import { Search, Plus, X, Eye, Download } from 'lucide-react';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { exportToPDF } from '../../utils/pdfExport';

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
  const [statutFilter] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [activeTab, setActiveTab] = useState(0);

  const [viewingEtudiant, setViewingEtudiant] = useState<any>(null);

  const initialFormData = {
    nom: '', prenom: '', sexe: 'M', date_naissance: '', lieu_naissance: '',
    nationalite: 'CONGOLAISE', adresse: '', ville: 'Brazzaville', pays: 'Congo',
    telephone: '', email: '', numero_cni: '', profession: 'Etudiant', situation_matrimoniale: 'Célibataire',
    
    nom_pere: '', profession_pere: '', nom_mere: '', profession_mere: '',
    nom_tuteur: '', profession_tuteur: '', telephone_tuteur: '', lieu_service_tuteur: '',
    
    type_etudiant: 'NORMAL', annee_obtention_bac: new Date().getFullYear(), serie_bac: 'A', option: 'Gestion et Administration', filiere: 'Comptabilité et gestion d\'entreprise', niveau: 'Licence 1',
    cycle_formation: 'Licence', rentree: 'OCTOBRE', annee_academique: '2026-2027', site_formation: 'ISGI', vague: 'Jour',
    
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

  // Form state containing ALL fields
  const [formData, setFormData] = useState(initialFormData);

  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []);

  const filteredEtudiants = etudiants?.filter(e => {
    const matchesSearch = 
      e.nom.toLowerCase().includes(searchTerm.toLowerCase()) || 
      e.prenom.toLowerCase().includes(searchTerm.toLowerCase()) ||
      e.matricule.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatut = statutFilter ? e.type_etudiant === statutFilter : true;
    return matchesSearch && matchesStatut;
  }) || [];

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target;
    if (type === 'checkbox') {
      const checked = (e.target as HTMLInputElement).checked;
      setFormData(prev => ({ ...prev, [name]: checked }));
    } else {
      setFormData(prev => {
        const newData = { ...prev, [name]: name === 'annee_obtention_bac' ? parseInt(value) || new Date().getFullYear() : value };
        // Si on change l'option, on met à jour la filière par défaut pour éviter un état incohérent
        if (name === 'option') {
          newData.filiere = OPTION_FILIERES[value]?.[0] || '';
        }
        // Si on change le cycle, on met à jour le niveau par défaut
        if (name === 'cycle_formation') {
          newData.niveau = NIVEAUX[value]?.[0] || '';
        }
        return newData;
      });
    }
  };

  const handleOpenModal = () => {
    setFormData(initialFormData);
    setActiveTab(0);
    setIsModalOpen(true);
  };

  const handleNextOrSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    // Si on n'est pas à la dernière étape, on passe à l'étape suivante
    if (activeTab < 3) {
      setActiveTab(activeTab + 1);
      return;
    }

    // Sinon, on sauvegarde (dernière étape)
    const count = await db.etudiants.count();
    
    const etudiantData = {
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
      type_etudiant: formData.type_etudiant, annee_obtention_bac: formData.annee_obtention_bac, serie_bac: formData.serie_bac,
      option: formData.option, filiere: formData.filiere, niveau: formData.niveau, cycle_formation: formData.cycle_formation,
      rentree: formData.rentree, site_formation: formData.site_formation,
      vague: formData.vague || 'Jour',
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

    // Create new
    const newMatricule = `ISGI-2627-${(count + 1).toString().padStart(4, '0')}`;
    await db.etudiants.add({
      ...etudiantData,
      id: crypto.randomUUID(),
      matricule: newMatricule,
    });

    setIsModalOpen(false);
    setActiveTab(0);

    // Auto-sync in background without blocking
    import('../../db/sync').then(({ syncData }) => {
      syncData();
    });
  };

  const handleExportPDF = () => {
    const columns = [
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom', dataKey: 'nom' },
      { header: 'Prénom', dataKey: 'prenom' },
      { header: 'Sexe', dataKey: 'sexe' },
      { header: 'Vague', dataKey: 'vague' },
      { header: 'Téléphone', dataKey: 'telephone' },
      { header: 'Filière', dataKey: 'filiere' },
      { header: 'Niveau', dataKey: 'niveau' },
    ];
    
    exportToPDF(
      'Liste des Étudiants Inscrits',
      columns,
      filteredEtudiants,
      'isgi_etudiants'
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Étudiants Inscrits</h2>
          <p className="text-on-surface-variant text-sm mt-1">Gérez les inscriptions et les dossiers</p>
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
            <Plus className="w-5 h-5" />
            Nouvelle Inscription
          </button>
        </div>
      </div>

      <div className="bg-surface-container rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
        {/* Filters */}
        <div className="p-4 border-b border-outline-variant flex flex-col md:flex-row gap-4">
          <div className="flex-1 relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-on-surface-variant" />
            <input 
              type="text" 
              placeholder="Rechercher par nom, prénom ou matricule..." 
              className="w-full pl-10 pr-4 py-2 bg-surface-container-highest border-none rounded-full focus:ring-2 focus:ring-primary text-on-surface"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-surface-container-low border-b border-outline-variant">
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Matricule</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Nom complet</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Sexe</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Vague</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Niveau</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Filière</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Sync</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredEtudiants.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-8 text-center text-on-surface-variant">
                    Aucun étudiant trouvé.
                  </td>
                </tr>
              ) : (
                filteredEtudiants.map(etudiant => (
                  <tr key={etudiant.id} className="border-b border-outline-variant hover:bg-surface-container-lowest transition-colors">
                    <td className="p-4 text-on-surface font-medium">{etudiant.matricule}</td>
                    <td className="p-4 text-on-surface">{etudiant.nom} {etudiant.prenom}</td>
                    <td className="p-4 text-on-surface-variant">{etudiant.sexe}</td>
                    <td className="p-4 text-on-surface-variant">
                      {etudiant.vague?.toLowerCase().includes('soir') ? (
                        <span className="font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 border border-purple-200 text-xs">
                          🌙 Soir
                        </span>
                      ) : (
                        <span className="font-medium px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100 text-xs">
                          ☀️ Jour
                        </span>
                      )}
                    </td>
                    <td className="p-4 text-on-surface-variant">{etudiant.niveau}</td>
                    <td className="p-4 text-on-surface-variant max-w-[200px] truncate" title={etudiant.filiere}>{etudiant.filiere}</td>
                    <td className="p-4">
                      {etudiant.is_synced ? (
                        <div className="w-2 h-2 rounded-full bg-primary" title="Synchronisé"></div>
                      ) : (
                        <div className="w-2 h-2 rounded-full bg-error" title="Non synchronisé (local)"></div>
                      )}
                    </td>
                    <td className="p-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button onClick={() => setViewingEtudiant(etudiant)} className="p-2 text-primary hover:bg-primary-container hover:text-on-primary-container rounded-lg transition-colors" title="Voir les détails">
                          <Eye className="w-4 h-4" />
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

      {/* Modal Nouvelle Inscription Complet */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col shadow-xl">
            <div className="bg-surface-container-lowest border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-bold text-on-surface">Fiche d'Inscription Complète</h3>
              <button onClick={() => setIsModalOpen(false)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>
            
            <div className="flex border-b border-outline-variant bg-surface-container-lowest overflow-x-auto shrink-0">
              <button type="button" onClick={() => setActiveTab(0)} className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${activeTab === 0 ? 'text-primary border-b-2 border-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>1. Identité</button>
              <button type="button" onClick={() => setActiveTab(1)} className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${activeTab === 1 ? 'text-primary border-b-2 border-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>2. Parents & Tuteur</button>
              <button type="button" onClick={() => setActiveTab(2)} className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${activeTab === 2 ? 'text-primary border-b-2 border-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>3. Parcours Académique</button>
              <button type="button" onClick={() => setActiveTab(3)} className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${activeTab === 3 ? 'text-primary border-b-2 border-primary' : 'text-on-surface-variant hover:text-on-surface'}`}>4. Pièces jointes</button>
            </div>

            <form onSubmit={handleNextOrSubmit} className="flex flex-col flex-1 min-h-0">
              
              <div className="overflow-y-auto p-4 md:p-6 flex-1 min-h-0">
                {/* TAB 1: IDENTITE */}
              {activeTab === 0 && (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Nom(s) *</label>
                    <input required name="nom" value={formData.nom} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Prénom(s) *</label>
                    <input required name="prenom" value={formData.prenom} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Sexe</label>
                    <select name="sexe" value={formData.sexe} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      <option value="M">Masculin</option><option value="F">Féminin</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Date de Naissance *</label>
                    <input required name="date_naissance" value={formData.date_naissance} onChange={handleInputChange} type="date" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Lieu de Naissance</label>
                    <input name="lieu_naissance" value={formData.lieu_naissance} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Nationalité</label>
                    <input name="nationalite" value={formData.nationalite} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1 lg:col-span-2">
                    <label className="text-sm font-medium text-on-surface">Adresse</label>
                    <input name="adresse" value={formData.adresse} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Ville</label>
                    <input name="ville" value={formData.ville} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Téléphone</label>
                    <input name="telephone" value={formData.telephone} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">E-mail</label>
                    <input name="email" value={formData.email} onChange={handleInputChange} type="email" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Numéro CNI</label>
                    <input name="numero_cni" value={formData.numero_cni} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                </div>
              )}

              {/* TAB 2: PARENTS & TUTEUR */}
              {activeTab === 1 && (
                <div className="space-y-6">
                  <div>
                    <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations du Père</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Nom et Prénom *</label><input required name="nom_pere" value={formData.nom_pere} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Profession *</label><input required name="profession_pere" value={formData.profession_pere} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                    </div>
                  </div>
                  <div>
                    <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations de la Mère</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Nom et Prénom *</label><input required name="nom_mere" value={formData.nom_mere} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Profession *</label><input required name="profession_mere" value={formData.profession_mere} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                    </div>
                  </div>
                  <div>
                    <h4 className="text-sm font-bold text-on-surface mb-3 border-b border-outline-variant pb-2">Informations du Tuteur</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Nom et Prénom</label><input name="nom_tuteur" value={formData.nom_tuteur} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Profession</label><input name="profession_tuteur" value={formData.profession_tuteur} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Téléphone Tuteur</label><input name="telephone_tuteur" value={formData.telephone_tuteur} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                      <div className="space-y-1"><label className="text-sm font-medium text-on-surface">Lieu de service</label><input name="lieu_service_tuteur" value={formData.lieu_service_tuteur} onChange={handleInputChange} type="text" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" /></div>
                    </div>
                  </div>
                </div>
              )}

              {/* TAB 3: ACADEMIQUE */}
              {activeTab === 2 && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Type d'étudiant</label>
                    <select name="type_etudiant" value={formData.type_etudiant} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      <option value="NORMAL">Normal</option><option value="BOURSIER">Boursier</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Année d'obtention du BAC *</label>
                    <input required name="annee_obtention_bac" value={formData.annee_obtention_bac} onChange={handleInputChange} type="number" min="1980" max="2050" className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant" />
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Série du BAC</label>
                    <select name="serie_bac" value={formData.serie_bac} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      {['A', 'C', 'D', 'E', 'F1', 'F2', 'F3', 'F4', 'G1', 'G2', 'G3', 'BG', 'H1', 'H2', 'H3', 'H4', 'H5', 'SANS BAC'].map(s => <option key={s} value={s}>{s}</option>)}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Option (Domaine)</label>
                    <select name="option" value={formData.option} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      {Object.keys(OPTION_FILIERES).map(opt => <option key={opt} value={opt}>{opt}</option>)}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Filière</label>
                    <select name="filiere" value={formData.filiere} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      {(OPTION_FILIERES[formData.option] || []).map(fil => <option key={fil} value={fil}>{fil}</option>)}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Cycle Formation</label>
                    <select name="cycle_formation" value={formData.cycle_formation} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      {CYCLES.map(c => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Niveau</label>
                    <select name="niveau" value={formData.niveau} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      {(NIVEAUX[formData.cycle_formation] || []).map(n => <option key={n} value={n}>{n}</option>)}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Rentrée</label>
                    <select name="rentree" value={formData.rentree} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      <option value="OCTOBRE">Octobre</option><option value="JANVIER">Janvier</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Année Académique</label>
                    <select name="annee_academique" value={formData.annee_academique} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant">
                      <option value="2025-2026">2025-2026</option>
                      <option value="2026-2027">2026-2027</option>
                      <option value="2027-2028">2027-2028</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-sm font-medium text-on-surface">Vague / Horaire des cours *</label>
                    <select name="vague" value={formData.vague} onChange={handleInputChange} className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant font-medium">
                      <option value="Jour">☀️ Cours du Jour (Normal)</option>
                      <option value="Soir">🌙 Cours du Soir (Vague du soir)</option>
                    </select>
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
                      { name: 'docs_acte_naissance', label: 'Copie d\'Actes de Naissance ou l\'Extrait d\'actes de Naissance' },
                      { name: 'docs_photos', label: '02 Photos d\'identités portant au verso ses coordonnées' },
                      { name: 'docs_cni', label: 'Photocopie de la C.N.I / passeport/ NIU' },
                      { name: 'docs_rame', label: '01 Paquet de Rame' },
                      { name: 'docs_markers', label: '01 Paquet de Markers Tableau' },
                      { name: 'docs_enveloppe', label: 'Une enveloppe kaki format A4 et une Chemise Cartonnée' },
                      { name: 'docs_diplome', label: 'Une copie du dernier diplôme' },
                      { name: 'docs_releves_notes', label: 'Relevés des notes de la dernière classe suivie' },
                      { name: 'docs_frais_inscription', label: 'Frais d\'inscription .............................25.000 f (Non remboursable)' },
                      { name: 'docs_polo', label: 'Un polo vendu à............................. 5000frs sur place' },
                      { name: 'docs_carte_etudiant', label: 'Une carte d\'étudiant............................. 5000frs' },
                      { name: 'docs_frais_stages', label: 'FRAIS DE STAGES .............................35.000 FCFA' },
                      { name: 'docs_frais_examens', label: 'Frais d\'examens..................... 20000f pour les deux semestres' },
                      { name: 'docs_frais_tptd', label: 'Frais des TP/TD..............................30.000frs pour les deux semestres' },
                      { name: 'docs_assurance', label: 'Assurance ................................. 1500 F' }
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

              {/* FOOTER ACTIONS - Toujours visible en bas */}
              <div className="p-4 border-t border-outline-variant bg-surface-container-lowest shrink-0 flex justify-between items-center">
                <div>
                  {activeTab > 0 && (
                    <button type="button" onClick={() => setActiveTab(activeTab - 1)} className="px-4 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                      Précédent
                    </button>
                  )}
                </div>
                <div className="flex gap-3">
                  <button type="button" onClick={() => setIsModalOpen(false)} className="px-4 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                    Annuler
                  </button>
                  {activeTab < 3 ? (
                    <button type="submit" className="px-6 py-2 rounded-full font-medium bg-secondary text-on-secondary hover:bg-secondary/90 transition-colors shadow-sm">
                      Suivant
                    </button>
                  ) : (
                    <button type="submit" className="px-6 py-2 rounded-full font-medium bg-primary text-on-primary hover:bg-primary/90 transition-colors shadow-sm">
                      Valider l'inscription
                    </button>
                  )}
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
      {/* Modal Voir les détails (Redesigned for NO scrolling on desktop) */}
      {viewingEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-6xl max-h-[95vh] shadow-xl flex flex-col overflow-hidden">
            <div className="bg-surface-container-lowest border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-semibold text-on-surface">Détails de l'étudiant - {viewingEtudiant.matricule}</h3>
              <button onClick={() => setViewingEtudiant(null)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>
            
            <div className="p-4 md:p-6 overflow-y-auto flex-1 min-h-0">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8">
                
                {/* Colonne 1: Identité */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Identité</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Nom complet</span> <p className="font-medium text-on-surface">{viewingEtudiant.nom} {viewingEtudiant.prenom}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Sexe</span> <p className="font-medium text-on-surface">{viewingEtudiant.sexe === 'M' ? 'Masculin' : 'Féminin'}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Date & Lieu de naissance</span> <p className="font-medium text-on-surface">{viewingEtudiant.date_naissance} à {viewingEtudiant.lieu_naissance}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Nationalité</span> <p className="font-medium text-on-surface">{viewingEtudiant.nationalite}</p></div>
                  </div>
                </div>

                {/* Colonne 2: Coordonnées & Parents */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Contacts & Tuteur</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Téléphone & Email</span> <p className="font-medium text-on-surface">{viewingEtudiant.telephone} <br/> {viewingEtudiant.email || 'Aucun email'}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Adresse</span> <p className="font-medium text-on-surface">{viewingEtudiant.adresse}, {viewingEtudiant.ville}</p></div>
                    <div className="pt-2"><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Nom du Tuteur / Parent</span> <p className="font-medium text-on-surface">{viewingEtudiant.nom_tuteur || viewingEtudiant.nom_pere}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Téléphone Tuteur</span> <p className="font-medium text-on-surface">{viewingEtudiant.telephone_tuteur}</p></div>
                  </div>
                </div>

                {/* Colonne 3: Académique */}
                <div className="space-y-4">
                  <h4 className="text-base font-bold text-primary border-b border-outline-variant pb-2">Parcours Académique</h4>
                  <div className="space-y-3 text-sm">
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Type d'étudiant</span> <p className="font-medium text-on-surface">{viewingEtudiant.type_etudiant}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Option & Filière</span> <p className="font-medium text-on-surface">{viewingEtudiant.option} <br/> {viewingEtudiant.filiere}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Niveau & Cycle</span> <p className="font-medium text-on-surface">{viewingEtudiant.niveau} ({viewingEtudiant.cycle_formation})</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Série & Année du BAC</span> <p className="font-medium text-on-surface">Série {viewingEtudiant.serie_bac} ({viewingEtudiant.annee_obtention_bac})</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Vague / Horaire</span> <p className="font-semibold text-on-surface">{viewingEtudiant.vague?.toLowerCase().includes('soir') ? '🌙 Cours du Soir' : '☀️ Cours du Jour'}</p></div>
                    <div><span className="text-on-surface-variant text-xs uppercase tracking-wider block">Statut Sync</span> <p className="font-medium text-on-surface">{viewingEtudiant.is_synced ? 'Synchronisé' : 'Local (Non synchronisé)'}</p></div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
