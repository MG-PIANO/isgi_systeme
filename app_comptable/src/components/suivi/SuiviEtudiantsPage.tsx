import { useState, useMemo } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import { Search, Filter, Eye, X, Download } from 'lucide-react';
import * as XLSX from 'xlsx';

const NIVEAUX = ["Licence 1", "Licence 2", "Licence 3", "Master 1", "Master 2"];
const OPTIONS = [
  "Gestion et Administration",
  "Informatique et Technologies",
  "Ingénierie et Sciences Appliquées",
  "Design et Multimédia"
];
const FILIERES_PAR_OPTION: Record<string, string[]> = {
  "Gestion et Administration": [
    "Comptabilité et gestion d'entreprise", "Audit et contrôle de gestion", "Commerce international",
    "Gestion en affaires mondiales", "Marketing et communication d'entreprise", "Gestion des finances",
    "Banque et microfinance", "Assurance", "Gestion des projets", "Logistique et Transport",
    "Douane et transit", "Gestion des ressources humaines", "Assistant de direction",
    "Hôtellerie et tourisme", "Gestion et administration de l'éducation", "Sciences politiques"
  ],
  "Informatique et Technologies": [
    "Génie logiciel et système d'information", "Réseau informatique et télécommunication",
    "Cybersécurité et piratage éthique", "Informatique industrielle", "Intelligence artificielle et Data Science",
    "Maintenance informatique", "Développement d'application mobile et web", "Bureautique", "Robotique"
  ],
  "Ingénierie et Sciences Appliquées": [
    "Bâtiment et travaux publics (BTP)", "Électromécanique", "Électricité industrielle",
    "Génie électrique", "Technologie de l'Automobile et ingénierie Mécanique",
    "Menuiserie et ébénisterie", "Froid et climatisation", "Énergies renouvelables", "Génie civil",
    "Qualité hygiène sécurité et environnement (QHSE)", "Génie pétrolier et gazier", "Forage et exploitation",
    "Mines et métallurgie", "Environnement et développement durable"
  ],
  "Design et Multimédia": [
    "Infographie et web design", "Photographie", "Montage vidéo", "Journalisme et communication",
    "Art et design graphique"
  ]
};

const DOCUMENT_FIELDS = [
  { id: 'docs_dossier_candidature', label: 'Dossier de candidature' },
  { id: 'docs_acte_naissance', label: 'Acte de Naissance' },
  { id: 'docs_photos', label: 'Photos d\'identités' },
  { id: 'docs_cni', label: 'C.N.I / Passeport / NIU' },
  { id: 'docs_rame', label: 'Rame' },
  { id: 'docs_markers', label: 'Markers' },
  { id: 'docs_enveloppe', label: 'Enveloppe et Chemise' },
  { id: 'docs_diplome', label: 'Diplôme' },
  { id: 'docs_releves_notes', label: 'Relevés de notes' },
  { id: 'docs_frais_inscription', label: 'Frais d\'inscription' },
  { id: 'docs_polo', label: 'Polo' },
  { id: 'docs_carte_etudiant', label: 'Carte d\'étudiant' },
  { id: 'docs_frais_stages', label: 'Frais de stages' },
  { id: 'docs_frais_examens', label: 'Frais d\'examens' },
  { id: 'docs_frais_tptd', label: 'Frais des TP/TD' },
  { id: 'docs_assurance', label: 'Assurance' }
];

export const SuiviEtudiantsPage = () => {
  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];
  
  const [searchTerm, setSearchTerm] = useState('');
  const [niveauFilter, setNiveauFilter] = useState('');
  const [filiereFilter, setFiliereFilter] = useState('');
  const [optionFilter, setOptionFilter] = useState('');
  const [vagueFilter, setVagueFilter] = useState('');
  const [documentFilters, setDocumentFilters] = useState<Record<string, boolean>>({});
  
  const [viewingEtudiant, setViewingEtudiant] = useState<any>(null);

  const toggleDocumentFilter = (docId: string) => {
    setDocumentFilters(prev => ({
      ...prev,
      [docId]: !prev[docId]
    }));
  };

  const filteredEtudiants = useMemo(() => {
    return etudiants
      .filter(etudiant => {
        // Search filter
        const matchSearch = !searchTerm || 
          etudiant.nom.toLowerCase().includes(searchTerm.toLowerCase()) ||
          etudiant.prenom.toLowerCase().includes(searchTerm.toLowerCase()) ||
          etudiant.matricule.toLowerCase().includes(searchTerm.toLowerCase());

        // Basic filters
        const matchNiveau = !niveauFilter || etudiant.niveau === niveauFilter;
        const matchFiliere = !filiereFilter || etudiant.filiere === filiereFilter;
        const matchOption = !optionFilter || etudiant.option === optionFilter;
        const matchVague = !vagueFilter || (
          vagueFilter === 'Soir'
            ? etudiant.vague?.toLowerCase().includes('soir')
            : (!etudiant.vague || etudiant.vague?.toLowerCase().includes('jour'))
        );

        // Document filters
        let matchDocs = true;
        const activeDocFilters = Object.entries(documentFilters).filter(([_, isActive]) => isActive).map(([key]) => key);
        
        if (activeDocFilters.length > 0) {
          let docs: any = {};
          if (etudiant.documents_physiques) {
            try { docs = JSON.parse(etudiant.documents_physiques); } catch (e) {}
          }
          
          for (const docKey of activeDocFilters) {
            // Strip the 'docs_' prefix to match JSON keys, e.g. 'docs_polo' -> 'polo'
            const jsonKey = docKey.replace('docs_', '');
            if (!docs[jsonKey]) {
              matchDocs = false;
              break;
            }
          }
        }

        return matchSearch && matchNiveau && matchFiliere && matchOption && matchVague && matchDocs;
      })
      .sort((a, b) => a.matricule.localeCompare(b.matricule));
  }, [etudiants, searchTerm, niveauFilter, filiereFilter, optionFilter, vagueFilter, documentFilters]);

  const handleExport = () => {
    const data = filteredEtudiants.map(e => ({
      'Matricule': e.matricule,
      'Nom': e.nom,
      'Prénom': e.prenom,
      'Sexe': e.sexe,
      'Vague': e.vague?.toLowerCase().includes('soir') ? 'Cours du Soir' : 'Cours du Jour',
      'Téléphone': e.telephone,
      'Filière': e.filiere,
      'Option': e.option,
      'Niveau': e.niveau
    }));
    
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Etudiants");
    XLSX.writeFile(wb, `suivi_etudiants_${new Date().toISOString().split('T')[0]}.xlsx`);
  };

  return (
    <div className="p-6 max-w-[1600px] mx-auto h-full flex flex-col">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 shrink-0">
        <div>
          <h1 className="text-2xl font-bold text-on-surface">Suivi des Étudiants</h1>
          <p className="text-on-surface-variant">Recherche multicritères et état des dossiers</p>
        </div>
        
        <button 
          onClick={handleExport}
          className="flex items-center gap-2 px-4 py-2 bg-secondary text-on-secondary rounded-lg hover:bg-secondary/90 transition-colors"
        >
          <Download className="w-5 h-5" />
          Exporter Excel
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6 min-h-0 flex-1">
        {/* Sidebar Filters */}
        <div className="lg:col-span-1 bg-surface-container-lowest rounded-2xl border border-outline-variant p-4 overflow-y-auto">
          <div className="flex items-center gap-2 mb-4">
            <Filter className="w-5 h-5 text-primary" />
            <h2 className="text-lg font-semibold text-on-surface">Filtres</h2>
          </div>

          <div className="space-y-4">
            {/* Search */}
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">Recherche</label>
              <div className="relative">
                <Search className="w-5 h-5 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Nom, prénom, matricule..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 rounded-xl border border-outline-variant bg-transparent focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all"
                />
              </div>
            </div>

            {/* Niveau */}
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">Niveau</label>
              <select
                value={niveauFilter}
                onChange={(e) => setNiveauFilter(e.target.value)}
                className="w-full p-2 rounded-xl border border-outline-variant bg-transparent focus:ring-2 focus:ring-primary focus:border-primary outline-none"
              >
                <option value="">Tous les niveaux</option>
                {NIVEAUX.map(n => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>

            {/* Option */}
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">Option</label>
              <select
                value={optionFilter}
                onChange={(e) => {
                  setOptionFilter(e.target.value);
                  setFiliereFilter('');
                }}
                className="w-full p-2 rounded-xl border border-outline-variant bg-transparent focus:ring-2 focus:ring-primary focus:border-primary outline-none"
              >
                <option value="">Toutes les options</option>
                {OPTIONS.map(o => <option key={o} value={o}>{o}</option>)}
              </select>
            </div>

            {/* Filiere */}
            {optionFilter && (
              <div>
                <label className="block text-sm font-medium text-on-surface mb-1">Filière</label>
                <select
                  value={filiereFilter}
                  onChange={(e) => setFiliereFilter(e.target.value)}
                  className="w-full p-2 rounded-xl border border-outline-variant bg-transparent focus:ring-2 focus:ring-primary focus:border-primary outline-none"
                >
                  <option value="">Toutes les filières</option>
                  {FILIERES_PAR_OPTION[optionFilter]?.map(f => <option key={f} value={f}>{f}</option>)}
                </select>
              </div>
            )}

            {/* Vague / Horaire */}
            <div>
              <label className="block text-sm font-medium text-on-surface mb-1">Vague / Horaire</label>
              <select
                value={vagueFilter}
                onChange={(e) => setVagueFilter(e.target.value)}
                className="w-full p-2 rounded-xl border border-outline-variant bg-transparent focus:ring-2 focus:ring-primary focus:border-primary outline-none"
              >
                <option value="">Toutes les vagues</option>
                <option value="Jour">☀️ Cours du Jour</option>
                <option value="Soir">🌙 Cours du Soir</option>
              </select>
            </div>

            <hr className="border-outline-variant my-4" />

            {/* Documents & Paiements */}
            <div>
              <h3 className="text-sm font-medium text-on-surface mb-2">A fourni / A payé :</h3>
              <div className="space-y-2 max-h-[300px] overflow-y-auto pr-2">
                {DOCUMENT_FIELDS.map(doc => (
                  <label key={doc.id} className="flex items-center gap-2 cursor-pointer hover:bg-surface-container-low p-1 rounded">
                    <input
                      type="checkbox"
                      checked={!!documentFilters[doc.id]}
                      onChange={() => toggleDocumentFilter(doc.id)}
                      className="w-4 h-4 rounded text-primary focus:ring-primary"
                    />
                    <span className="text-sm text-on-surface">{doc.label}</span>
                  </label>
                ))}
              </div>
            </div>

          </div>
        </div>

        {/* Main Content (Table) */}
        <div className="lg:col-span-3 bg-surface-container-lowest rounded-2xl border border-outline-variant overflow-hidden flex flex-col">
          <div className="p-4 border-b border-outline-variant bg-surface-container-lowest">
            <h2 className="font-medium text-on-surface">Résultats ({filteredEtudiants.length})</h2>
          </div>
          
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-surface-container-low border-b border-outline-variant">
                  <th className="p-4 text-sm font-semibold text-on-surface-variant">Matricule</th>
                  <th className="p-4 text-sm font-semibold text-on-surface-variant">Nom complet</th>
                  <th className="p-4 text-sm font-semibold text-on-surface-variant">Vague</th>
                  <th className="p-4 text-sm font-semibold text-on-surface-variant">Niveau</th>
                  <th className="p-4 text-sm font-semibold text-on-surface-variant">Option / Filière</th>
                  <th className="p-4 text-sm font-semibold text-on-surface-variant text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredEtudiants.map(etudiant => (
                  <tr key={etudiant.id} className="border-b border-outline-variant hover:bg-surface-container-low/50 transition-colors">
                    <td className="p-4 text-sm font-medium text-on-surface">{etudiant.matricule}</td>
                    <td className="p-4 text-sm text-on-surface">
                      {etudiant.nom} {etudiant.prenom}
                    </td>
                    <td className="p-4 text-sm">
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
                    <td className="p-4 text-sm text-on-surface">{etudiant.niveau}</td>
                    <td className="p-4 text-sm text-on-surface max-w-xs truncate" title={`${etudiant.option} - ${etudiant.filiere || ''}`}>
                      {etudiant.option} <br/> <span className="text-xs text-on-surface-variant">{etudiant.filiere}</span>
                    </td>
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
                ))}
                {filteredEtudiants.length === 0 && (
                  <tr>
                    <td colSpan={5} className="p-8 text-center text-on-surface-variant">
                      Aucun étudiant ne correspond à vos critères.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Modal View */}
      {viewingEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-2xl shadow-xl overflow-hidden flex flex-col">
            <div className="border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-semibold text-on-surface">Détails de l'étudiant - {viewingEtudiant.matricule}</h3>
              <button onClick={() => setViewingEtudiant(null)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>
            
            <div className="p-6 overflow-y-auto max-h-[70vh] space-y-6">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Nom</p>
                  <p className="text-on-surface">{viewingEtudiant.nom}</p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Prénom</p>
                  <p className="text-on-surface">{viewingEtudiant.prenom}</p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Sexe</p>
                  <p className="text-on-surface">{viewingEtudiant.sexe}</p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Téléphone</p>
                  <p className="text-on-surface">{viewingEtudiant.telephone}</p>
                </div>
              </div>

              <hr className="border-outline-variant" />
              
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Niveau</p>
                  <p className="text-on-surface">{viewingEtudiant.niveau}</p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Vague / Horaire</p>
                  <p className="text-on-surface font-semibold">
                    {viewingEtudiant.vague?.toLowerCase().includes('soir') ? '🌙 Cours du Soir' : '☀️ Cours du Jour'}
                  </p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Année Académique</p>
                  <p className="text-on-surface">{viewingEtudiant.annee_academique || 'N/A'}</p>
                </div>
                <div>
                  <p className="text-sm font-medium text-on-surface-variant">Option</p>
                  <p className="text-on-surface">{viewingEtudiant.option}</p>
                </div>
                <div className="col-span-2">
                  <p className="text-sm font-medium text-on-surface-variant">Filière</p>
                  <p className="text-on-surface">{viewingEtudiant.filiere || 'N/A'}</p>
                </div>
              </div>

            </div>
          </div>
        </div>
      )}

    </div>
  );
};
