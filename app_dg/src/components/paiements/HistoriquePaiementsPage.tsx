import { useState, useMemo } from 'react';
import { Search, Eye, X, History, CreditCard, Download } from 'lucide-react';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { getFinancialStatus } from '../../utils/pricing';
import { exportToPDF } from '../../utils/pdfExport';

export default function HistoriquePaiementsPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedEtudiantId, setSelectedEtudiantId] = useState<string | null>(null);

  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];
  const paiements = useLiveQuery(() => db.paiements.toArray()) || [];

  // Filter students based on search term
  const filteredEtudiants = useMemo(() => {
    return etudiants.filter(e => {
      const search = searchTerm.toLowerCase();
      return (
        e.nom.toLowerCase().includes(search) ||
        e.prenom.toLowerCase().includes(search) ||
        e.matricule.toLowerCase().includes(search)
      );
    });
  }, [etudiants, searchTerm]);

  // Selected student details
  const selectedEtudiant = etudiants.find(e => e.matricule === selectedEtudiantId);
  
  // Selected student's payments
  const studentPaiements = useMemo(() => {
    if (!selectedEtudiantId) return [];
    return paiements
      .filter(p => p.etudiant_id === selectedEtudiantId)
      .sort((a, b) => new Date(b.created_at || b.last_modified_at || 0).getTime() - new Date(a.created_at || a.last_modified_at || 0).getTime());
  }, [paiements, selectedEtudiantId]);

  // Financial status for selected student
  const situationFinanciere = useMemo(() => {
    if (!selectedEtudiant) return null;
    const paidScolarite = studentPaiements
      .filter(p => !p.type_paiement || p.type_paiement === 'Frais Scolaire')
      .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    return getFinancialStatus(selectedEtudiant, paidScolarite);
  }, [selectedEtudiant, studentPaiements]);

  const handleExportListPDF = () => {
    const columns = [
      { header: 'Matricule', dataKey: 'matricule' },
      { header: 'Nom et Prénom', dataKey: 'nom_complet' },
      { header: 'Niveau', dataKey: 'niveau' }
    ];
    
    const formattedData = filteredEtudiants.map(e => ({
      matricule: e.matricule,
      nom_complet: `${e.nom} ${e.prenom}`,
      niveau: e.niveau
    }));

    exportToPDF(
      'Liste des Étudiants',
      columns,
      formattedData,
      'isgi_etudiants_historique'
    );
  };

  const handleExportHistoryPDF = () => {
    if (!selectedEtudiant) return;
    
    const columns = [
      { header: 'Date', dataKey: 'date' },
      { header: 'Type', dataKey: 'type' },
      { header: 'Montant', dataKey: 'montant' },
      { header: 'Caissier', dataKey: 'caissier' },
      { header: 'Mode', dataKey: 'mode' },
      { header: 'Référence', dataKey: 'reference' }
    ];
    
    const formattedData = studentPaiements.map(p => ({
      date: new Date(p.created_at || p.last_modified_at || 0).toLocaleDateString('fr-FR'),
      type: p.type_paiement || 'Frais Scolaire',
      montant: `${Number(p.montant).toLocaleString('fr-FR')} F`,
      caissier: p.gestionnaire_nom || 'N/A',
      mode: p.mode_paiement,
      reference: p.reference_transaction || 'N/A'
    }));

    exportToPDF(
      `Historique Paiements - ${selectedEtudiant.nom} ${selectedEtudiant.prenom}`,
      columns,
      formattedData,
      `historique_${selectedEtudiant.matricule}`
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface flex items-center gap-2">
            <History className="w-6 h-6 text-primary" />
            Historique des Paiements
          </h2>
          <p className="text-on-surface-variant mt-1">Consultez l'historique complet des transactions de chaque étudiant</p>
        </div>
        <button 
          onClick={handleExportListPDF}
          className="flex items-center gap-2 bg-surface-container text-on-surface border border-outline-variant px-4 py-2 rounded-full font-medium hover:bg-surface-container-highest transition-colors"
        >
          <Download className="w-5 h-5" />
          PDF Liste
        </button>
      </div>

      <div className="bg-surface-container-lowest border border-outline-variant rounded-2xl overflow-hidden shadow-sm">
        <div className="p-4 border-b border-outline-variant flex flex-col sm:flex-row gap-4 items-center justify-between bg-surface-container-lowest">
          <div className="relative w-full sm:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par nom, prénom ou matricule..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-10 pr-4 py-2 bg-surface-container-low border border-outline-variant rounded-full text-on-surface focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
            />
          </div>
          <div className="text-sm font-medium text-on-surface-variant bg-surface-container-low px-4 py-2 rounded-full border border-outline-variant">
            {filteredEtudiants.length} Étudiant(s) trouvé(s)
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-outline-variant bg-surface-container-lowest">
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Matricule</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Nom complet</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Niveau</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant">Filière</th>
                <th className="p-4 font-semibold text-sm text-on-surface-variant text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-outline-variant/50">
              {filteredEtudiants.length === 0 ? (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-on-surface-variant">
                    Aucun étudiant trouvé.
                  </td>
                </tr>
              ) : (
                filteredEtudiants.map(etudiant => (
                  <tr key={etudiant.id} className="hover:bg-surface-container-low/50 transition-colors">
                    <td className="p-4">
                      <span className="font-mono text-xs bg-surface-container px-2 py-1 rounded-md text-on-surface-variant border border-outline-variant">
                        {etudiant.matricule}
                      </span>
                    </td>
                    <td className="p-4 font-medium text-on-surface">
                      {etudiant.nom} {etudiant.prenom}
                    </td>
                    <td className="p-4">
                      <span className="px-2.5 py-1 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                        {etudiant.niveau}
                      </span>
                    </td>
                    <td className="p-4 text-sm text-on-surface-variant">
                      {etudiant.filiere}
                    </td>
                    <td className="p-4 text-right">
                      <button
                        onClick={() => setSelectedEtudiantId(etudiant.matricule)}
                        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-primary hover:bg-primary-container hover:text-on-primary-container rounded-full transition-colors border border-primary/20 hover:border-transparent"
                      >
                        <Eye className="w-4 h-4" />
                        Voir Historique
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal Historique */}
      {selectedEtudiantId && selectedEtudiant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-on-surface/50">
          <div className="bg-surface-container-lowest rounded-2xl w-full max-w-4xl shadow-xl overflow-hidden flex flex-col max-h-[90vh]">
            <div className="border-b border-outline-variant px-6 py-4 flex justify-between items-center shrink-0 bg-surface-container-lowest">
              <div>
                <h3 className="text-xl font-bold text-on-surface flex items-center gap-3">
                  Historique de {selectedEtudiant.nom} {selectedEtudiant.prenom}
                  <span className="font-mono text-xs font-normal bg-surface-container px-2 py-1 rounded-md text-on-surface-variant border border-outline-variant">
                    {selectedEtudiant.matricule}
                  </span>
                </h3>
              </div>
              <button 
                onClick={() => setSelectedEtudiantId(null)} 
                className="p-2 rounded-full hover:bg-surface-container-highest transition-colors"
              >
                <X className="w-5 h-5 text-on-surface-variant" />
              </button>
            </div>
            
            <div className="overflow-y-auto min-h-0 flex-1 p-6 space-y-6">
              
              {/* Situation Financière */}
              {situationFinanciere && (
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <div className="bg-surface-container-low p-4 rounded-xl border border-outline-variant">
                    <div className="text-xs text-on-surface-variant mb-1">Coût Annuel (Scolarité)</div>
                    <div className="font-bold text-lg">{situationFinanciere.coutAnnuel.toLocaleString('fr-FR')} F</div>
                  </div>
                  <div className="bg-primary-container/20 p-4 rounded-xl border border-primary/20">
                    <div className="text-xs text-on-surface-variant mb-1">Total Déjà Payé (Scolarité)</div>
                    <div className="font-bold text-lg text-primary">{situationFinanciere.totalScolaritePaye.toLocaleString('fr-FR')} F</div>
                  </div>
                  <div className="bg-surface-container-low p-4 rounded-xl border border-outline-variant">
                    <div className="text-xs text-on-surface-variant mb-1">Reste à Payer</div>
                    <div className="font-bold text-lg">{situationFinanciere.resteAPayer.toLocaleString('fr-FR')} F</div>
                  </div>
                  <div className={`p-4 rounded-xl border ${situationFinanciere.isEnRetard ? 'bg-error-container/20 border-error/20' : 'bg-success-container/20 border-success/20'}`}>
                    <div className="text-xs mb-1 flex items-center gap-1">
                      {situationFinanciere.isEnRetard ? (
                        <span className="text-error font-medium">En retard</span>
                      ) : (
                        <span className="text-success font-medium">À jour</span>
                      )}
                    </div>
                    <div className="font-bold text-lg">
                      {situationFinanciere.isEnRetard 
                        ? `${situationFinanciere.arrieres.toLocaleString('fr-FR')} F (Arriérés)`
                        : "Aucun arriéré"
                      }
                    </div>
                  </div>
                </div>
              )}

              {/* Liste des Transactions */}
              <div>
                <div className="flex justify-between items-center mb-4">
                  <h4 className="font-bold text-on-surface flex items-center gap-2">
                    <CreditCard className="w-5 h-5 text-on-surface-variant" />
                    Liste des transactions
                  </h4>
                  <button 
                    onClick={handleExportHistoryPDF}
                    className="flex items-center gap-2 text-primary hover:bg-primary-container/50 px-3 py-1.5 rounded-full transition-colors text-sm font-medium border border-primary/20"
                  >
                    <Download className="w-4 h-4" />
                    PDF Historique
                  </button>
                </div>
                
                <div className="border border-outline-variant rounded-xl overflow-hidden">
                  <table className="w-full text-left text-sm">
                    <thead className="bg-surface-container-low border-b border-outline-variant">
                      <tr>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant">Date</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant">Type</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant text-right">Montant</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant">Caissier</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant">Mode</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant">Référence</th>
                        <th className="px-4 py-3 font-semibold text-on-surface-variant text-center">Statut</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-outline-variant/50 bg-surface-container-lowest">
                      {studentPaiements.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="px-4 py-8 text-center text-on-surface-variant">
                            Aucune transaction enregistrée pour cet étudiant.
                          </td>
                        </tr>
                      ) : (
                        studentPaiements.map((p) => {
                          const dateObj = new Date(p.created_at || p.last_modified_at || Date.now());
                          const dateStr = dateObj.toLocaleDateString('fr-FR', {
                            day: '2-digit', month: '2-digit', year: 'numeric'
                          });
                          
                          return (
                            <tr key={p.id} className="hover:bg-surface-container-low/30">
                              <td className="px-4 py-3 whitespace-nowrap text-on-surface-variant">{dateStr}</td>
                              <td className="px-4 py-3 font-medium text-on-surface">{p.type_paiement || 'Frais Scolaire'}</td>
                              <td className="px-4 py-3 font-bold text-right">{Number(p.montant).toLocaleString('fr-FR')} F</td>
                              <td className="px-4 py-3 text-on-surface-variant">{p.gestionnaire_nom || '-'}</td>
                              <td className="px-4 py-3 text-on-surface-variant">{p.mode_paiement}</td>
                              <td className="px-4 py-3">
                                <span className="font-mono text-xs">{p.reference_transaction}</span>
                              </td>
                              <td className="px-4 py-3 text-center">
                                <span className={`inline-flex px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider ${
                                  p.statut === 'Réussi' 
                                    ? 'bg-success-container text-on-success-container'
                                    : 'bg-surface-variant text-on-surface-variant'
                                }`}>
                                  {p.statut || 'Réussi'}
                                </span>
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
            
            <div className="p-4 border-t border-outline-variant bg-surface-container-low text-right shrink-0">
              <button 
                onClick={() => setSelectedEtudiantId(null)}
                className="px-6 py-2 rounded-full bg-surface-variant text-on-surface-variant hover:bg-surface-container-highest transition-colors font-medium"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
