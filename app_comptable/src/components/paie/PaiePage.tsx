import { useState, useEffect } from 'react';
import { supabase } from '../../db/supabaseClient';
import { useAuth } from '../../context/AuthContext';
import { Plus, X, Printer } from 'lucide-react';

export function PaiePage() {
  const { user } = useAuth();
  const [paies, setPaies] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [generationData, setGenerationData] = useState({
    mois: new Date().getMonth() + 1 + '',
    annee: new Date().getFullYear().toString()
  });
  
  const [previewData, setPreviewData] = useState<any[]>([]);
  
  // For printing
  const [paieToPrint, setPaieToPrint] = useState<any>(null);

  const moisList = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
  ];

  useEffect(() => {
    fetchPaies();
  }, []);

  const fetchPaies = async () => {
    try {
      const { data, error } = await supabase
        .from('paies')
        .select('*, personnel(*)')
        .order('date_paiement', { ascending: false });
        
      if (error) throw error;
      setPaies(data || []);
    } catch (error) {
      console.error('Error fetching paies:', error);
    } finally {
      setLoading(false);
    }
  };

  const calculatePreview = async () => {
    try {
      const selectedMoisStr = moisList[parseInt(generationData.mois) - 1];
      const selectedAnnee = parseInt(generationData.annee);

      const [personnelRes, empruntsRes, pointagesRes] = await Promise.all([
        supabase.from('personnel').select('*'),
        supabase.from('emprunts').select('*')
          .eq('statut', 'en_cours')
          .eq('mois_deduction', selectedMoisStr)
          .eq('annee_deduction', selectedAnnee),
        supabase.from('pointages_enseignants').select('*')
          .eq('mois', selectedMoisStr)
          .eq('annee', selectedAnnee)
      ]);

      if (personnelRes.error) throw personnelRes.error;
      const allPersonnel = personnelRes.data || [];
      const emprunts = empruntsRes.data || [];
      const pointages = pointagesRes.data || [];

      const preview = allPersonnel.map(p => {
        let brut = 0;
        let heures_normales = 0;
        let taux_horaire = 0;

        if (p.type_personnel === 'administratif') {
          brut = p.salaire_base || 0;
          heures_normales = 173.33; // Temps plein par défaut
          taux_horaire = p.salaire_base ? p.salaire_base / 173.33 : 0;
        } else if (p.type_personnel === 'enseignant') {
          const pt = pointages.find(pt => pt.personnel_id === p.id);
          heures_normales = pt ? Number(pt.heures_validees) : 0;
          taux_horaire = p.taux_horaire || 0;
          brut = heures_normales * taux_horaire;
        }

        const deds = emprunts.filter(e => e.personnel_id === p.id);
        const totalDeductions = deds.reduce((sum, e) => sum + e.montant, 0);

        return {
          personnel: p,
          heures_normales,
          taux_horaire,
          heures_sup: 0,
          taux_heures_sup: p.type_personnel === 'enseignant' ? (p.taux_horaire || 0) * 1.25 : ((p.salaire_base || 0) / 173.33) * 1.25,
          brut_base: brut, // Salaire de base avant primes
          prime_anciennete: 0,
          prime_transport: 0,
          autres_primes: 0,
          impots_salaires: 0,
          cnss: 0,
          cimr: 0,
          deductions_avances: totalDeductions,
          empruntsIds: deds.map(e => e.id)
        };
      }); // Supprimé le filtre .filter(p => p.brut_base > 0 || p.heures_normales > 0) pour afficher les profs même avec 0 heure

      setPreviewData(preview);
    } catch (error) {
      console.error("Preview error:", error);
    }
  };

  useEffect(() => {
    if (showModal) {
      calculatePreview();
    }
  }, [generationData.mois, generationData.annee, showModal]);

  const updatePreviewField = (index: number, field: string, value: string) => {
    const newPreview = [...previewData];
    newPreview[index][field] = Number(value) || 0;
    
    // Si on modifie les heures normales d'un enseignant, on recalcule son brut_base
    if (field === 'heures_normales' && newPreview[index].personnel.type_personnel === 'enseignant') {
      newPreview[index].brut_base = newPreview[index].heures_normales * newPreview[index].taux_horaire;
    }
    
    setPreviewData(newPreview);
  };

  const getMontantBrut = (p: any) => {
    return p.brut_base + (p.heures_sup * p.taux_heures_sup) + p.prime_anciennete;
  };

  const getTotalGains = (p: any) => {
    return getMontantBrut(p) + p.prime_transport + p.autres_primes;
  };

  const getTotalDeductions = (p: any) => {
    return p.impots_salaires + p.cnss + p.cimr + p.deductions_avances;
  };

  const getNetAPayer = (p: any) => {
    return getTotalGains(p) - getTotalDeductions(p);
  };

  const handleGenerate = async () => {
    if (previewData.length === 0) {
      alert("Aucune paie à générer pour cette période.");
      return;
    }
    
    if (!window.confirm("Générer définitivement les fiches de paie pour cette période ?")) return;
    
    setSubmitting(true);
    try {
      const selectedMoisStr = moisList[parseInt(generationData.mois) - 1];
      const selectedAnnee = parseInt(generationData.annee);

      const paiesToInsert = previewData.map(p => ({
        personnel_id: p.personnel.id,
        mois: selectedMoisStr,
        annee: selectedAnnee,
        heures_normales: Math.round(p.heures_normales * 100) / 100, // NUMERIC in db
        heures_sup: Math.round(p.heures_sup * 100) / 100, // NUMERIC in db
        taux_horaire: Math.round(p.taux_horaire), // INTEGER in db
        montant_brut: Math.round(getMontantBrut(p)), // INTEGER in db
        prime_anciennete: Math.round(p.prime_anciennete), // INTEGER
        prime_transport: Math.round(p.prime_transport), // INTEGER
        autres_primes: Math.round(p.autres_primes), // INTEGER
        impots_salaires: Math.round(p.impots_salaires), // INTEGER
        cnss: Math.round(p.cnss), // INTEGER
        cimr: Math.round(p.cimr), // INTEGER
        emprunts_deduits: Math.round(p.deductions_avances), // INTEGER
        montant_net: Math.round(getNetAPayer(p)), // INTEGER
        paye_par: user?.name || 'Comptable'
      }));

      const { error: insertError } = await supabase.from('paies').insert(paiesToInsert);
      if (insertError) throw insertError;

      const allEmpruntsToUpdate = previewData.flatMap(p => p.empruntsIds);
      if (allEmpruntsToUpdate.length > 0) {
        await Promise.all(allEmpruntsToUpdate.map(id => 
          supabase.from('emprunts').update({ statut: 'rembourse' }).eq('id', id)
        ));
      }

      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: 'GENERATION_PAIE',
        description: `Génération de la paie pour ${selectedMoisStr} ${selectedAnnee}`
      }]);

      setShowModal(false);
      fetchPaies();
    } catch (error) {
      console.error('Error generating paies:', error);
      alert('Erreur lors de la génération.');
    } finally {
      setSubmitting(false);
    }
  };

  const printPaie = () => {
    window.print();
  };

  return (
    <div className="space-y-6">
      {/* Header (Hidden when printing) */}
      <div className="print:hidden flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Gestion de la Paie</h1>
          <p className="mt-1 text-sm text-gray-500">
            Calcul et génération des bulletins de paie (primes, impôts, CNSS, avances)
          </p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
        >
          <Plus className="h-4 w-4" />
          Générer la paie du mois
        </button>
      </div>

      {/* Content area (Hidden when printing) */}
      <div className="print:hidden bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-300">
            <thead>
              <tr className="bg-gray-50">
                <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Période</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Personnel</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Salaire Brut</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Primes Totales</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Retenues Totales</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Salaire Net</th>
                <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {loading ? (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-sm text-gray-500">Chargement...</td>
                </tr>
              ) : paies.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-sm text-gray-500">Aucune fiche de paie générée</td>
                </tr>
              ) : (
                paies.map((paie) => (
                  <tr key={paie.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900">
                      {paie.mois} {paie.annee}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                      {paie.personnel?.nom} {paie.personnel?.prenom}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      {paie.montant_brut.toLocaleString('fr-FR')} 
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-green-600">
                      +{(paie.prime_anciennete + paie.prime_transport + paie.autres_primes).toLocaleString('fr-FR')} 
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-red-500">
                      -{(paie.impots_salaires + paie.cnss + paie.cimr + paie.emprunts_deduits).toLocaleString('fr-FR')} 
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-bold text-gray-900">
                      {paie.montant_net.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                      <button 
                        onClick={() => {
                          setPaieToPrint(paie);
                          setTimeout(() => printPaie(), 100);
                        }}
                        className="text-blue-600 hover:text-blue-900 transition-colors flex items-center gap-1 justify-end ml-auto" 
                        title="Imprimer le bulletin"
                      >
                        <Printer className="h-4 w-4" />
                        <span className="hidden sm:inline">Imprimer</span>
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Generate Modal (Hidden when printing) */}
      {showModal && (
        <div className="print:hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-7xl overflow-hidden flex flex-col max-h-[95vh]">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
              <h2 className="text-lg font-semibold text-gray-900">Générer les bulletins de paie</h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-gray-500">
                <X className="h-5 w-5" />
              </button>
            </div>
            
            <div className="p-6 flex-shrink-0 border-b border-gray-100 bg-gray-50">
              <div className="flex items-end gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Mois</label>
                  <select
                    value={generationData.mois}
                    onChange={e => setGenerationData({ ...generationData, mois: e.target.value })}
                    className="w-40 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  >
                    {moisList.map((m, i) => <option key={i} value={i+1}>{m}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Année</label>
                  <input
                    type="number"
                    value={generationData.annee}
                    onChange={e => setGenerationData({ ...generationData, annee: e.target.value })}
                    className="w-32 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
              </div>
            </div>

            <div className="overflow-y-auto flex-1 p-6">
              
              {previewData.length === 0 ? (
                <div className="text-center py-8 text-gray-500 bg-gray-50 rounded-lg">
                  Aucun salaire à générer pour cette période.
                </div>
              ) : (
                <div className="space-y-6">
                  {previewData.map((p, index) => (
                    <div key={p.personnel.id} className="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                      <div className="flex justify-between items-center mb-4 pb-2 border-b">
                        <h4 className="font-bold text-gray-900">{p.personnel.nom} {p.personnel.prenom} <span className="text-sm font-normal text-gray-500">({p.personnel.type_personnel})</span></h4>
                        <div className="text-right">
                          <span className="text-sm text-gray-500">Net à Payer: </span>
                          <span className="text-xl font-bold text-green-600">{getNetAPayer(p).toLocaleString('fr-FR')} FCFA</span>
                        </div>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        {/* Gains */}
                        <div className="md:col-span-2 space-y-3">
                          <h5 className="text-sm font-semibold text-gray-700 bg-gray-50 p-2 rounded">Gains & Primes</h5>
                          <div className="grid grid-cols-2 gap-2 text-sm">
                            {p.personnel.type_personnel === 'administratif' ? (
                              <>
                                <div className="text-gray-600">Salaire de Base:</div>
                                <div className="font-medium">{p.brut_base.toLocaleString('fr-FR')}</div>
                              </>
                            ) : (
                              <>
                                <div className="text-gray-600 flex items-center gap-2">
                                  Heures prestées:
                                  <input type="number" value={p.heures_normales} onChange={e => updatePreviewField(index, 'heures_normales', e.target.value)} className="w-16 p-1 border rounded text-xs" /> h
                                </div>
                                <div className="font-medium text-gray-900">
                                  {p.brut_base.toLocaleString('fr-FR')} <span className="text-xs text-gray-500 font-normal">(Taux: {p.taux_horaire} FCFA/h)</span>
                                </div>
                              </>
                            )}
                            
                            <div className="text-gray-600 flex items-center gap-2">
                              Heures Sup.: 
                              <input type="number" value={p.heures_sup} onChange={e => updatePreviewField(index, 'heures_sup', e.target.value)} className="w-16 p-1 border rounded text-xs" /> h
                            </div>
                            <div className="font-medium text-green-600">+ {(p.heures_sup * p.taux_heures_sup).toLocaleString('fr-FR')}</div>

                            <div className="text-gray-600">Prime d'ancienneté:</div>
                            <div><input type="number" value={p.prime_anciennete} onChange={e => updatePreviewField(index, 'prime_anciennete', e.target.value)} className="w-full p-1 border rounded text-xs" /></div>

                            <div className="text-gray-600">Transport:</div>
                            <div><input type="number" value={p.prime_transport} onChange={e => updatePreviewField(index, 'prime_transport', e.target.value)} className="w-full p-1 border rounded text-xs" /></div>
                            
                            <div className="text-gray-600">Autres Primes:</div>
                            <div><input type="number" value={p.autres_primes} onChange={e => updatePreviewField(index, 'autres_primes', e.target.value)} className="w-full p-1 border rounded text-xs" /></div>
                          </div>
                        </div>

                        {/* Deductions */}
                        <div className="md:col-span-2 space-y-3">
                          <h5 className="text-sm font-semibold text-gray-700 bg-gray-50 p-2 rounded">Retenues & Déductions</h5>
                          <div className="grid grid-cols-2 gap-2 text-sm">
                            <div className="text-gray-600">Impôts s/salaires:</div>
                            <div><input type="number" value={p.impots_salaires} onChange={e => updatePreviewField(index, 'impots_salaires', e.target.value)} className="w-full p-1 border rounded text-xs text-red-600" /></div>

                            <div className="text-gray-600">C.N.S.S:</div>
                            <div><input type="number" value={p.cnss} onChange={e => updatePreviewField(index, 'cnss', e.target.value)} className="w-full p-1 border rounded text-xs text-red-600" /></div>
                            
                            <div className="text-gray-600">C.I.M.R:</div>
                            <div><input type="number" value={p.cimr} onChange={e => updatePreviewField(index, 'cimr', e.target.value)} className="w-full p-1 border rounded text-xs text-red-600" /></div>
                            
                            <div className="text-gray-600">Acomptes (Avances):</div>
                            <div className="font-medium text-red-600">- {p.deductions_avances.toLocaleString('fr-FR')}</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="p-6 border-t border-gray-100 bg-white flex justify-end gap-3 flex-shrink-0">
              <button
                type="button"
                onClick={() => setShowModal(false)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Annuler
              </button>
              <button
                onClick={handleGenerate}
                disabled={submitting || previewData.length === 0}
                className="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
              >
                {submitting ? 'Enregistrement...' : 'Valider et Enregistrer'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* PRINT TEMPLATE (Thermal format 80mm) */}
      {paieToPrint && (
        <div className="hidden print:block fixed inset-0 bg-white z-[9999] text-black font-mono w-full h-full" style={{ width: '80mm', padding: '5mm', margin: '0' }}>
          <div className="text-center mb-4">
            <h1 className="font-bold text-xl uppercase">ISGI</h1>
            <p className="text-sm">Institut des Sciences de Gestion</p>
            <p className="text-sm">Brazzaville, Congo</p>
            <div className="border-b border-black border-dashed my-2"></div>
            <h2 className="font-bold text-lg">REÇU DE PAIEMENT</h2>
            <h3 className="font-bold">(SALAIRE)</h3>
          </div>

          <div className="text-sm space-y-2 mb-4">
            <p><strong>Période:</strong> 01 au 31 {paieToPrint.mois} {paieToPrint.annee}</p>
            <p><strong>Date d'édition:</strong> {new Date(paieToPrint.date_paiement).toLocaleDateString('fr-FR')} à {new Date(paieToPrint.date_paiement).toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'})}</p>
            <p><strong>Employé(e):</strong> {paieToPrint.personnel?.nom} {paieToPrint.personnel?.prenom}</p>
            <p><strong>N° CNSS:</strong> {paieToPrint.personnel?.numero_cnss || 'N/A'}</p>
          </div>

          <div className="border-t border-b border-black border-dashed py-2 mb-4 text-sm space-y-1">
            <div className="flex justify-between">
              <span>Heures Normales</span>
              <span>{(paieToPrint.heures_normales * paieToPrint.taux_horaire).toLocaleString('fr-FR')}</span>
            </div>
            {paieToPrint.heures_sup > 0 && (
              <div className="flex justify-between">
                <span>Heures Sup.</span>
                <span>{(paieToPrint.heures_sup * paieToPrint.taux_horaire * 1.25).toLocaleString('fr-FR')}</span>
              </div>
            )}
            <div className="flex justify-between">
              <span>Prime d'ancienneté</span>
              <span>{paieToPrint.prime_anciennete.toLocaleString('fr-FR')}</span>
            </div>
            <div className="flex justify-between">
              <span>Transport</span>
              <span>{paieToPrint.prime_transport.toLocaleString('fr-FR')}</span>
            </div>
            {paieToPrint.autres_primes > 0 && (
              <div className="flex justify-between">
                <span>Autres Primes</span>
                <span>{paieToPrint.autres_primes.toLocaleString('fr-FR')}</span>
              </div>
            )}
            
            <div className="border-t border-black border-dashed mt-2 pt-2 flex justify-between">
              <span>Déductions (Impôts)</span>
              <span>- {paieToPrint.impots_salaires.toLocaleString('fr-FR')}</span>
            </div>
            <div className="flex justify-between">
              <span>C.N.S.S</span>
              <span>- {paieToPrint.cnss.toLocaleString('fr-FR')}</span>
            </div>
            <div className="flex justify-between">
              <span>C.I.M.R</span>
              <span>- {paieToPrint.cimr.toLocaleString('fr-FR')}</span>
            </div>
            <div className="flex justify-between">
              <span>Acomptes (Avances)</span>
              <span>- {paieToPrint.emprunts_deduits.toLocaleString('fr-FR')}</span>
            </div>
          </div>

          <div className="border-b border-black border-dashed pb-2 mb-4">
            <div className="flex justify-between items-end">
              <span className="font-bold text-lg">NET A PAYER</span>
              <span className="font-bold text-xl">{paieToPrint.montant_net.toLocaleString('fr-FR')}</span>
            </div>
            <div className="text-right text-xs mt-1">FCFA</div>
          </div>

          <div className="text-sm space-y-6">
            <p><strong>Caissier/Comptable:</strong><br/>{paieToPrint.paye_par}</p>
            <div className="text-center italic text-xs">
              <p>Reçu généré automatiquement.</p>
              <p>Merci pour votre service.</p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
