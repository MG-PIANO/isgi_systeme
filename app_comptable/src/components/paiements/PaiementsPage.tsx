import { useState, useEffect } from 'react';
import { Search, Plus, Filter, CheckCircle, Clock, X, Info, AlertTriangle, Download, Printer } from 'lucide-react';
import { db, type Paiement } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { PAYMENT_TYPES, getFinancialStatus, getRecommendedAmount } from '../../utils/pricing';
import { exportToPDF } from '../../utils/pdfExport';
import { ReceiptTicket } from './ReceiptTicket';
import { useAuth } from '../../context/AuthContext';

export default function PaiementsPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [statutFilter, setStatutFilter] = useState('');
  const [vagueFilter, setVagueFilter] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);

  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [etudiantSearch, setEtudiantSearch] = useState('');
  const [printingPaiement, setPrintingPaiement] = useState<{ paiement: Paiement; etudiant: any } | null>(null);
  const [customTypePaiement, setCustomTypePaiement] = useState('');

  const initialFormData = {
    etudiant_id: '',
    type_paiement: 'Frais Scolaire',
    montant: 50000,
  };

  // Paiement form state
  const [formData, setFormData] = useState(initialFormData);
  
  const { user } = useAuth();

  const paiements = useLiveQuery(() => db.paiements.toArray(), []) || [];
  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []) || [];

  const filteredPaiements = paiements.filter(p => {
    // Find etudiant to search by name
    const etudiant = etudiants.find(e => e.matricule === p.etudiant_id || e.id === p.etudiant_id);
    const matchesSearch = 
      p.etudiant_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (p.reference_transaction && p.reference_transaction.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (etudiant && (etudiant.nom.toLowerCase().includes(searchTerm.toLowerCase()) || etudiant.prenom.toLowerCase().includes(searchTerm.toLowerCase())));
    const matchesStatut = statutFilter ? p.statut === statutFilter : true;
    const matchesVague = vagueFilter ? (
      vagueFilter === 'Soir'
        ? (etudiant?.vague?.toLowerCase().includes('soir'))
        : (!etudiant?.vague || etudiant?.vague?.toLowerCase().includes('jour'))
    ) : true;
    return matchesSearch && matchesStatut && matchesVague;
  });

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setFormData(prev => {
      const next = { ...prev, [name]: name === 'montant' ? Number(value) : value };
      
      // Auto-fill montant if student or type changes
      if (name === 'type_paiement' || name === 'etudiant_id') {
        const student = etudiants.find(et => et.matricule === next.etudiant_id || et.id === next.etudiant_id);
        if (student && next.type_paiement !== 'Autre') {
          next.montant = getRecommendedAmount(student, next.type_paiement);
        }
      }
      
      return next;
    });
  };

  const handleOpenModal = () => {
    setFormData(initialFormData);
    setCustomTypePaiement('');

    setIsModalOpen(true);
  };



  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    // Find the real matricule of selected student (in case id was selected)
    const selectedEtudiant = etudiants.find(et => et.id === formData.etudiant_id || et.matricule === formData.etudiant_id);
    if (!selectedEtudiant) return;

    const finalTypePaiement = formData.type_paiement === 'Autre' ? customTypePaiement : formData.type_paiement;

    const newPaiement: Paiement = {
      id: crypto.randomUUID(),
      etudiant_id: selectedEtudiant.matricule,
      type_paiement: finalTypePaiement as any,
      montant: Number(formData.montant),
      mode_paiement: 'Espèces',
      reference_transaction: `ESP-${Date.now()}`,
      statut: 'Réussi',
      gestionnaire_nom: user?.name || 'Secrétariat',
      is_synced: 0 as const,
      last_modified_at: new Date().toISOString()
    };
    await db.paiements.add(newPaiement);
    
    // Auto-trigger print for the new payment
    setPrintingPaiement({ paiement: newPaiement, etudiant: selectedEtudiant });

    setIsModalOpen(false);
    setFormData(initialFormData);
    setCustomTypePaiement('');

    // Auto-sync in background without blocking
    import('../../db/sync').then(({ syncData }) => {
      syncData();
    });
  };

  // Compute financial situation for selected student
  const selectedEtudiant = etudiants.find(e => e.matricule === formData.etudiant_id);
  let situationFinanciere = null;
  
  if (selectedEtudiant) {
    const totalScolaritePaye = paiements
      .filter(p => p.etudiant_id === selectedEtudiant.matricule && (!p.type_paiement || p.type_paiement === 'Frais Scolaire'))
      .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    
    situationFinanciere = getFinancialStatus(selectedEtudiant, totalScolaritePaye);
  }

  // Helper function to check if student is in arrears for table display
  const isStudentInArrears = (etudiantId: string) => {
    const e = etudiants.find(et => et.matricule === etudiantId);
    if (!e) return false;
    const paid = paiements
      .filter(p => p.etudiant_id === e.matricule && (!p.type_paiement || p.type_paiement === 'Frais Scolaire'))
      .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    const status = getFinancialStatus(e, paid);
    return status.isEnRetard;
  };

  const filteredDropdownEtudiants = etudiants.filter(e => 
    e.nom.toLowerCase().includes(etudiantSearch.toLowerCase()) || 
    e.prenom.toLowerCase().includes(etudiantSearch.toLowerCase()) || 
    e.matricule.toLowerCase().includes(etudiantSearch.toLowerCase())
  );

  const handleSelectEtudiant = (matricule: string) => {
    setFormData(prev => {
      const next = { ...prev, etudiant_id: matricule };
      const student = etudiants.find(et => et.matricule === matricule);
      if (student && next.type_paiement !== 'Autre') {
        next.montant = getRecommendedAmount(student, next.type_paiement);
      }
      return next;
    });
    setIsDropdownOpen(false);
    setEtudiantSearch('');
  };

  const handleExportPDF = () => {
    const columns = [
      { header: 'Date', dataKey: 'date' },
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom et Prénom', dataKey: 'nom' },
      { header: 'Type', dataKey: 'type' },
      { header: 'Montant', dataKey: 'montant' },
      { header: 'Caissier', dataKey: 'caissier' },
      { header: 'Mode', dataKey: 'mode' },
      { header: 'Référence', dataKey: 'reference' },
    ];
    
    const formattedData = filteredPaiements.map(p => {
      const etudiant = etudiants.find(e => e.matricule === p.etudiant_id);
      return {
        date: new Date(p.last_modified_at).toLocaleDateString('fr-FR'),
        matricule: p.etudiant_id,
        nom: etudiant ? `${etudiant.nom} ${etudiant.prenom}` : 'Inconnu',
        type: p.type_paiement || 'Frais Scolaire',
        montant: `${Number(p.montant).toLocaleString('fr-FR')} F`,
        caissier: p.gestionnaire_nom || 'N/A',
        mode: p.mode_paiement,
        reference: p.reference_transaction || 'N/A'
      };
    });

    exportToPDF(
      'Journal des Paiements',
      columns,
      formattedData,
      'isgi_paiements_global'
    );
  };

  useEffect(() => {
    if (printingPaiement) {
      setTimeout(() => {
        window.print();
        setPrintingPaiement(null);
      }, 100);
    }
  }, [printingPaiement]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Paiements</h2>
          <p className="text-on-surface-variant text-sm mt-1">Suivi des paiements de scolarité et divers</p>
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
            Nouveau Paiement
          </button>
        </div>
      </div>

      <ReceiptTicket paiement={printingPaiement?.paiement || null} etudiant={printingPaiement?.etudiant || null} />

      <div className="bg-surface-container rounded-2xl border border-outline-variant shadow-sm overflow-hidden print:hidden">
        {/* Filters */}
        <div className="p-4 border-b border-outline-variant flex flex-col md:flex-row gap-4">
          <div className="flex-1 relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-on-surface-variant" />
            <input 
              type="text" 
              placeholder="Rechercher par étudiant ou référence..." 
              className="w-full pl-10 pr-4 py-2 bg-surface-container-highest border-none rounded-full focus:ring-2 focus:ring-primary text-on-surface"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
          <div className="flex gap-2">
            <select 
              className="bg-surface-container-highest px-4 py-2 rounded-full text-on-surface border-none focus:ring-2 focus:ring-primary appearance-none cursor-pointer"
              value={statutFilter}
              onChange={(e) => setStatutFilter(e.target.value)}
            >
              <option value="">Tous les statuts</option>
              <option value="Réussi">Réussi</option>
              <option value="En attente">En attente</option>
              <option value="Échoué">Échoué</option>
            </select>
            <select 
              className="bg-surface-container-highest px-4 py-2 rounded-full text-on-surface border-none focus:ring-2 focus:ring-primary appearance-none cursor-pointer text-sm font-medium"
              value={vagueFilter}
              onChange={(e) => setVagueFilter(e.target.value)}
            >
              <option value="">Toutes les vagues</option>
              <option value="Jour">☀️ Cours du Jour</option>
              <option value="Soir">🌙 Cours du Soir</option>
            </select>
            <button className="p-2 rounded-full bg-surface-container-highest text-on-surface hover:bg-surface-variant">
              <Filter className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-surface-container-low border-b border-outline-variant">
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Date</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Étudiant</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Type</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Montant</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Caissier</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Mode</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Référence</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Statut</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredPaiements.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-8 text-center text-on-surface-variant">
                    Aucun paiement trouvé.
                  </td>
                </tr>
              ) : (
                filteredPaiements.map(paiement => {
                  const etudiant = etudiants.find(e => e.matricule === paiement.etudiant_id);
                  const nomEtudiant = etudiant ? `${etudiant.nom} ${etudiant.prenom}` : paiement.etudiant_id;
                  const enRetard = isStudentInArrears(paiement.etudiant_id);
                  
                  return (
                    <tr key={paiement.id} className="border-b border-outline-variant hover:bg-surface-container-lowest transition-colors">
                      <td className="p-4 text-on-surface-variant text-sm">
                        {new Date(paiement.last_modified_at).toLocaleDateString('fr-FR')}
                      </td>
                      <td className="p-4 text-on-surface font-medium">
                        <div className="flex items-center gap-2 flex-wrap">
                          {nomEtudiant}
                          {enRetard && <span className="w-2 h-2 rounded-full bg-error" title="En retard de paiement"></span>}
                          {etudiant && (
                            etudiant.vague?.toLowerCase().includes('soir') ? (
                              <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-purple-100 text-purple-800 border border-purple-200">
                                🌙 Soir
                              </span>
                            ) : (
                              <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100">
                                ☀️ Jour
                              </span>
                            )
                          )}
                        </div>
                        {etudiant && <div className={`text-xs ${enRetard ? 'text-error' : 'text-on-surface-variant'}`}>{etudiant.matricule} • {etudiant.niveau}</div>}
                      </td>
                      <td className="p-4 text-on-surface-variant text-sm">
                        <span className="bg-surface-variant text-on-surface px-2 py-1 rounded-md">
                          {paiement.type_paiement || 'Frais Scolaire'}
                        </span>
                      </td>
                      <td className="p-4 text-on-surface font-bold">{Number(paiement.montant).toLocaleString('fr-FR')} FCFA</td>
                      <td className="p-4 text-on-surface-variant text-sm">{paiement.gestionnaire_nom || '-'}</td>
                      <td className="p-4 text-on-surface-variant">{paiement.mode_paiement}</td>
                      <td className="p-4 text-on-surface-variant text-sm font-mono">{paiement.reference_transaction}</td>
                      <td className="p-4">
                        <span className={`flex items-center gap-1 w-max px-2 py-1 rounded-full text-xs font-semibold ${
                          paiement.statut === 'Réussi' ? 'bg-primary-container text-on-primary-container' :
                          paiement.statut === 'En attente' ? 'bg-secondary-container text-on-secondary-container' :
                          'bg-error-container text-on-error-container'
                        }`}>
                          {paiement.statut === 'Réussi' && <CheckCircle className="w-3 h-3" />}
                          {paiement.statut === 'En attente' && <Clock className="w-3 h-3" />}
                          {paiement.statut}
                        </span>
                      </td>
                      <td className="p-4 text-right">
                        <div className="flex justify-end gap-2">
                          <button 
                            onClick={() => {
                              if (etudiant) {
                                setPrintingPaiement({ paiement, etudiant });
                              }
                            }}
                            className="p-2 text-on-surface hover:text-primary hover:bg-primary-container/20 rounded-lg transition-colors"
                            title="Imprimer le reçu"
                          >
                            <Printer className="w-5 h-5" />
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

      {/* Modal Nouveau Paiement */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-lg shadow-xl overflow-hidden flex flex-col max-h-[90vh]">
            <div className="border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0">
              <h3 className="text-xl font-semibold text-on-surface">Nouveau Paiement</h3>
              <button onClick={() => setIsModalOpen(false)} className="p-2 rounded-full hover:bg-surface-container-highest">
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>
            
            <div className="overflow-y-auto min-h-0 flex-1">
              <form onSubmit={handleSubmit} className="p-6 space-y-5" id="paiement-form">
                <div className="space-y-2 relative">
                  <label className="text-sm font-medium text-on-surface">Étudiant</label>
                  
                  <div 
                    className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant cursor-pointer flex justify-between items-center"
                    onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                  >
                    <span className={formData.etudiant_id ? "text-on-surface" : "text-on-surface-variant"}>
                      {formData.etudiant_id 
                        ? (() => {
                            const e = etudiants.find(et => et.matricule === formData.etudiant_id);
                            return e ? `${e.nom} ${e.prenom} (${e.matricule}) - ${e.niveau}` : 'Sélectionner un étudiant...';
                          })()
                        : 'Sélectionner un étudiant...'}
                    </span>
                    <Search className="w-4 h-4 text-on-surface-variant" />
                  </div>

                  {isDropdownOpen && (
                    <div className="absolute z-10 w-full mt-1 bg-surface-container-highest border border-outline-variant rounded-xl shadow-lg max-h-60 flex flex-col top-full">
                      <div className="p-2 border-b border-outline-variant sticky top-0 bg-surface-container-highest rounded-t-xl">
                        <input 
                          type="text" 
                          autoFocus
                          placeholder="Rechercher nom, prénom, matricule..." 
                          className="w-full px-3 py-1.5 bg-surface-container border border-outline-variant rounded-lg text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                          value={etudiantSearch}
                          onChange={e => setEtudiantSearch(e.target.value)}
                          onClick={e => e.stopPropagation()}
                        />
                      </div>
                      <div className="overflow-y-auto p-1">
                        {filteredDropdownEtudiants.length === 0 ? (
                          <div className="p-3 text-sm text-center text-on-surface-variant">Aucun étudiant trouvé</div>
                        ) : (
                          filteredDropdownEtudiants.slice(0, 50).map(e => (
                            <div 
                              key={e.id} 
                              className={`px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-surface-variant ${formData.etudiant_id === e.matricule ? 'bg-primary-container text-on-primary-container font-medium' : 'text-on-surface'}`}
                              onClick={() => handleSelectEtudiant(e.matricule)}
                            >
                              {e.nom} {e.prenom} <span className="text-xs opacity-75">({e.matricule})</span> - {e.niveau}
                              {e.vague?.toLowerCase().includes('soir') ? (
                                <span className="ml-2 text-[10px] font-bold px-1.5 py-0.5 rounded bg-purple-100 text-purple-800 border border-purple-200">
                                  🌙 Soir
                                </span>
                              ) : (
                                <span className="ml-2 text-[10px] font-medium px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100">
                                  ☀️ Jour
                                </span>
                              )}
                            </div>
                          ))
                        )}
                      </div>
                    </div>
                  )}
                </div>

                {/* Résumé de situation financière */}
                {situationFinanciere && (
                  <div className={`border p-4 rounded-xl flex flex-col gap-2 ${
                    situationFinanciere.isEnRetard 
                      ? 'bg-error-container/30 border-error-container' 
                      : 'bg-primary-container/30 border-primary-container'
                  }`}>
                    <div className="flex items-center justify-between">
                      <div className={`flex items-center gap-2 font-bold ${
                        situationFinanciere.isEnRetard ? 'text-error' : 'text-primary'
                      }`}>
                        {situationFinanciere.isEnRetard ? <AlertTriangle className="w-5 h-5" /> : <Info className="w-5 h-5" />}
                        Situation Financière (Scolarité)
                      </div>
                      {selectedEtudiant && (
                        selectedEtudiant.vague?.toLowerCase().includes('soir') ? (
                          <span className="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 border border-purple-200">
                            🌙 Cours du Soir
                          </span>
                        ) : (
                          <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 border border-blue-200">
                            ☀️ Cours du Jour
                          </span>
                        )
                      )}
                    </div>
                    <div className="grid grid-cols-2 gap-4 mt-2">
                      <div>
                        <div className="text-xs text-on-surface-variant">Coût Annuel</div>
                        <div className="font-semibold">{situationFinanciere.coutAnnuel.toLocaleString('fr-FR')} F</div>
                      </div>
                      <div>
                        <div className="text-xs text-on-surface-variant">Total Déjà Payé</div>
                        <div className="font-semibold text-secondary">{situationFinanciere.totalScolaritePaye.toLocaleString('fr-FR')} F</div>
                      </div>
                      
                      <div className="col-span-2 pt-2 border-t border-outline-variant">
                        <div className="flex justify-between items-center">
                          <div>
                            <div className="text-xs text-on-surface-variant">Mensualité (x10 mois)</div>
                            <div className="font-semibold">{situationFinanciere.mensualite.toLocaleString('fr-FR')} F</div>
                          </div>
                          <div className="text-right">
                            <div className="text-xs text-on-surface-variant">Mois attendus ({situationFinanciere.expectedMonths})</div>
                            <div className="font-semibold">{situationFinanciere.montantAttendu.toLocaleString('fr-FR')} F</div>
                          </div>
                        </div>
                      </div>
                      
                      <div className="col-span-2 pt-2 border-t border-outline-variant flex justify-between items-center">
                        <div>
                          <div className="text-xs text-on-surface-variant">Reste à Payer (Global)</div>
                          <div className="font-bold text-lg text-primary">{situationFinanciere.resteAPayer.toLocaleString('fr-FR')} F</div>
                        </div>
                        {situationFinanciere.isEnRetard ? (
                          <div className="text-right text-error bg-error-container/50 px-3 py-1 rounded-lg">
                            <div className="text-xs font-semibold">Arriérés actuels</div>
                            <div className="font-bold text-lg">{situationFinanciere.arrieres.toLocaleString('fr-FR')} F</div>
                          </div>
                        ) : (
                          <div className="text-right text-secondary bg-secondary-container/50 px-3 py-1 rounded-lg">
                            <div className="text-xs font-semibold">Statut mensuel</div>
                            <div className="font-bold">À jour</div>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                )}

                <div className="space-y-2">
                  <label className="text-sm font-medium text-on-surface">Type de Paiement</label>
                  <select 
                    required 
                    name="type_paiement" 
                    value={formData.type_paiement} 
                    onChange={handleInputChange} 
                    className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant focus:ring-2 focus:ring-primary focus:border-transparent"
                  >
                    {PAYMENT_TYPES.map(type => (
                      <option key={type} value={type}>{type}</option>
                    ))}
                    <option value="Autre">Autre (Divers...)</option>
                  </select>
                  
                  {formData.type_paiement === 'Autre' && (
                    <input
                      type="text"
                      required
                      placeholder="Préciser le type de paiement..."
                      value={customTypePaiement}
                      onChange={(e) => setCustomTypePaiement(e.target.value)}
                      className="w-full mt-2 px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant focus:ring-2 focus:ring-primary focus:border-transparent"
                    />
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-on-surface">Montant (FCFA)</label>
                  <input 
                    required 
                    name="montant" 
                    type="number" 
                    min="0"
                    step="1000"
                    value={formData.montant} 
                    onChange={handleInputChange} 
                    className="w-full px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant focus:ring-2 focus:ring-primary focus:border-transparent" 
                  />
                  {formData.type_paiement === 'Frais Scolaire' && situationFinanciere && formData.montant > situationFinanciere.resteAPayer && (
                    <p className="text-xs text-error mt-1">Attention: Le montant saisi dépasse le reste à payer.</p>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="text-sm font-medium text-on-surface">Mode de Paiement</label>
                  <select 
                    disabled
                    className="w-full px-4 py-2 rounded-xl bg-surface-container-highest border border-outline-variant text-on-surface-variant cursor-not-allowed"
                  >
                    <option value="ESPECES">Espèces (Guichet)</option>
                  </select>
                  <p className="text-xs text-on-surface-variant mt-1">Seul le paiement en espèces est activé pour l'instant.</p>
                </div>
              </form>
            </div>
            
            <div className="flex justify-end gap-3 p-4 border-t border-outline-variant shrink-0 bg-surface-container-lowest">
              <button type="button" onClick={() => setIsModalOpen(false)} className="px-6 py-2 rounded-full font-medium text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                Annuler
              </button>
              <button type="submit" form="paiement-form" className="px-6 py-2 rounded-full font-medium bg-primary text-on-primary hover:bg-primary/90 transition-colors shadow-sm">
                Valider l'encaissement
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
