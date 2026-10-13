import { useState, useMemo } from 'react';
import { Search, X, CheckSquare, Square, Users, CreditCard, ShieldCheck } from 'lucide-react';
import { db, type Etudiant, type Paiement } from '../../db/db';
import { PAYMENT_TYPES, getRecommendedAmount } from '../../utils/pricing';
import { cn } from '../../lib/utils';
import { exportToPDF } from '../../utils/pdfExport';

interface BatchPaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  etudiants: Etudiant[];
  userName?: string;
  onSuccess: (count: number, total: number) => void;
}

export function BatchPaymentModal({
  isOpen,
  onClose,
  etudiants,
  userName = 'Secrétariat',
  onSuccess
}: BatchPaymentModalProps) {
  const [typePaiement, setTypePaiement] = useState<string>('Inscription');
  const [customTypePaiement, setCustomTypePaiement] = useState<string>('');
  const [modePaiement, setModePaiement] = useState<'Espèces' | 'Airtel Money' | 'MTN Mobile Money' | 'Virement'>('Espèces');
  const [montantUnitaire, setMontantUnitaire] = useState<number>(25000);

  // Recherche et filtres d'étudiants
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [niveauFilter, setNiveauFilter] = useState<string>('all');
  const [vagueFilter, setVagueFilter] = useState<string>('all');

  // Map des étudiants sélectionnés avec leur montant individuel
  // Key: matricule, Value: montant
  const [selectedStudents, setSelectedStudents] = useState<Map<string, number>>(new Map());
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);

  // Auto-ajuster le montant unitaire selon le type de paiement
  const handleTypeChange = (newType: string) => {
    setTypePaiement(newType);
    if (newType === 'Inscription') setMontantUnitaire(25000);
    else if (newType === 'Réinscription') setMontantUnitaire(20000);
    else if (newType === 'Frais de Polo') setMontantUnitaire(10000);
    else if (newType === "Frais divers (Carte d'étudiant...)") setMontantUnitaire(5000);
    else if (newType === "Frais d'assurance") setMontantUnitaire(5000);
    else if (newType === 'Frais Scolaire' || newType === 'Mensualité') setMontantUnitaire(30000);

    // Mettre à jour les montants des étudiants déjà sélectionnés
    if (selectedStudents.size > 0) {
      const updated = new Map();
      selectedStudents.forEach((_, matricule) => {
        const student = etudiants.find(e => e.matricule === matricule);
        const amt = student ? getRecommendedAmount(student, newType) || montantUnitaire : montantUnitaire;
        updated.set(matricule, amt);
      });
      setSelectedStudents(updated);
    }
  };

  // Mettre à jour le montant unitaire pour tous les sélectionnés
  const handleMontantUnitaireChange = (newAmount: number) => {
    setMontantUnitaire(newAmount);
    const updated = new Map();
    selectedStudents.forEach((_, matricule) => {
      updated.set(matricule, newAmount);
    });
    setSelectedStudents(updated);
  };

  // Filtrage des étudiants
  const filteredEtudiants = useMemo(() => {
    return etudiants.filter(e => {
      const query = searchTerm.toLowerCase();
      const matchSearch =
        !query ||
        e.nom.toLowerCase().includes(query) ||
        e.prenom.toLowerCase().includes(query) ||
        e.matricule.toLowerCase().includes(query);

      const matchNiveau = niveauFilter === 'all' || e.niveau === niveauFilter;
      const matchVague =
        vagueFilter === 'all' ||
        (vagueFilter === 'Soir'
          ? (e.vague || '').toLowerCase().includes('soir')
          : !(e.vague || '').toLowerCase().includes('soir'));

      return matchSearch && matchNiveau && matchVague;
    });
  }, [etudiants, searchTerm, niveauFilter, vagueFilter]);

  // Basculer la sélection d'un étudiant
  const toggleStudent = (matricule: string) => {
    const next = new Map(selectedStudents);
    if (next.has(matricule)) {
      next.delete(matricule);
    } else {
      const student = etudiants.find(e => e.matricule === matricule);
      const amt = student ? getRecommendedAmount(student, typePaiement) || montantUnitaire : montantUnitaire;
      next.set(matricule, amt);
    }
    setSelectedStudents(next);
  };

  // Tout sélectionner parmi les filtrés
  const handleSelectAllFiltered = () => {
    const next = new Map(selectedStudents);
    filteredEtudiants.forEach(e => {
      if (!next.has(e.matricule)) {
        const amt = getRecommendedAmount(e, typePaiement) || montantUnitaire;
        next.set(e.matricule, amt);
      }
    });
    setSelectedStudents(next);
  };

  // Tout désélectionner
  const handleDeselectAll = () => {
    setSelectedStudents(new Map());
  };

  // Modifier le montant pour un étudiant particulier
  const handleStudentAmountChange = (matricule: string, amount: number) => {
    const next = new Map(selectedStudents);
    next.set(matricule, amount);
    setSelectedStudents(next);
  };

  // Calcul du montant total
  const totalAmount = useMemo(() => {
    let sum = 0;
    selectedStudents.forEach(amt => {
      sum += amt;
    });
    return sum;
  }, [selectedStudents]);

  // Validation et enregistrement groupé
  const handleSubmitBatch = async () => {
    if (selectedStudents.size === 0) {
      alert('Veuillez sélectionner au moins un étudiant.');
      return;
    }

    const finalType = typePaiement === 'Autre' ? customTypePaiement : typePaiement;
    if (!finalType) {
      alert('Veuillez préciser le type de paiement.');
      return;
    }

    if (!window.confirm(`Confirmez-vous l'encaissement groupé de ${selectedStudents.size} paiements pour un total de ${totalAmount.toLocaleString('fr-FR')} FCFA ?`)) {
      return;
    }

    setIsSubmitting(true);
    try {
      const batchRef = `GRP-${Date.now()}`;
      const now = new Date().toISOString();
      const paiementsToCreate: Paiement[] = [];

      selectedStudents.forEach((montant, matricule) => {
        paiementsToCreate.push({
          id: crypto.randomUUID(),
          etudiant_id: matricule,
          type_paiement: finalType,
          montant: Number(montant),
          mode_paiement: modePaiement,
          reference_transaction: `${batchRef}-${matricule.replace(/\s+/g, '')}`,
          statut: 'Réussi',
          gestionnaire_nom: userName,
          is_synced: 0,
          last_modified_at: now,
          created_at: now
        });
      });

      // Ajout en masse dans Dexie
      await db.paiements.bulkAdd(paiementsToCreate);

      // Déclenchement de la synchronisation en tâche de fond
      import('../../db/sync').then(({ syncData }) => {
        syncData();
      }).catch(() => {});

      // Option d'export bordereau groupé
      const printSummary = window.confirm('Paiements groupés enregistrés avec succès ! Souhaitez-vous imprimer ou exporter le bordereau récapitulatif ?');
      if (printSummary) {
        const columns = [
          { header: 'Matricule', dataKey: 'matricule' },
          { header: 'Nom & Prénom', dataKey: 'nom_complet' },
          { header: 'Type Paiement', dataKey: 'type' },
          { header: 'Mode', dataKey: 'mode' },
          { header: 'Montant Encaissé', dataKey: 'montant' }
        ];

        const data = paiementsToCreate.map(p => {
          const et = etudiants.find(e => e.matricule === p.etudiant_id);
          return {
            matricule: p.etudiant_id,
            nom_complet: et ? `${et.nom} ${et.prenom}` : '-',
            type: p.type_paiement,
            mode: p.mode_paiement,
            montant: `${Number(p.montant).toLocaleString('fr-FR')} FCFA`
          };
        });

        exportToPDF(
          `Bordereau Encaissement Groupé (${finalType}) - Réf: ${batchRef}`,
          columns,
          data,
          `bordereau_paiements_groupe_${Date.now()}`
        );
      }

      onSuccess(selectedStudents.size, totalAmount);
      onClose();
    } catch (err: any) {
      alert("Erreur lors de l'enregistrement groupé : " + err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="bg-white rounded-3xl max-w-4xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-slate-200 animate-in zoom-in-95 overflow-hidden">
        {/* Header */}
        <div className="p-5 bg-gradient-to-r from-slate-900 to-indigo-950 text-white flex items-center justify-between shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-white/10 flex items-center justify-center text-white">
              <Users className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-lg font-black tracking-tight">Paiement Multiple / Groupé</h2>
              <p className="text-xs text-slate-300">
                Enregistrez simultanément le paiement pour plusieurs étudiants (Inscriptions, Polos, Cartes...)
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-white/10 rounded-xl transition-colors">
            <X className="w-5 h-5 text-slate-300" />
          </button>
        </div>

        {/* Corps de la modale */}
        <div className="p-6 space-y-5 overflow-y-auto flex-1">
          {/* Étape 1 : Paramètres communs du paiement */}
          <div className="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
            <div className="text-xs font-black text-slate-700 uppercase tracking-wide flex items-center gap-2">
              <CreditCard className="w-4 h-4 text-blue-600" />
              1. Paramètres Communs du Lot
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-bold text-slate-600 mb-1">Type de Paiement</label>
                <select
                  value={typePaiement}
                  onChange={(e) => handleTypeChange(e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {PAYMENT_TYPES.map(t => (
                    <option key={t} value={t}>{t}</option>
                  ))}
                  <option value="Autre">Autre motif...</option>
                </select>
                {typePaiement === 'Autre' && (
                  <input
                    type="text"
                    placeholder="Préciser le motif..."
                    value={customTypePaiement}
                    onChange={(e) => setCustomTypePaiement(e.target.value)}
                    className="w-full mt-1.5 px-3 py-1.5 bg-white border border-slate-300 rounded-xl text-xs"
                  />
                )}
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 mb-1">Montant par Défaut (FCFA)</label>
                <input
                  type="number"
                  value={montantUnitaire}
                  onChange={(e) => handleMontantUnitaireChange(Number(e.target.value) || 0)}
                  className="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 mb-1">Mode d'Encaissement</label>
                <select
                  value={modePaiement}
                  onChange={(e) => setModePaiement(e.target.value as any)}
                  className="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="Espèces">Espèces</option>
                  <option value="Airtel Money">Airtel Money</option>
                  <option value="MTN Mobile Money">MTN Mobile Money</option>
                  <option value="Virement">Virement bancaire</option>
                </select>
              </div>
            </div>
          </div>

          {/* Étape 2 : Sélection multiple des étudiants */}
          <div className="space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
              <div className="text-xs font-black text-slate-700 uppercase tracking-wide flex items-center gap-2">
                <Users className="w-4 h-4 text-blue-600" />
                2. Sélection des Étudiants ({selectedStudents.size} sélectionné{selectedStudents.size > 1 ? 's' : ''})
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleSelectAllFiltered}
                  className="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-bold transition-colors"
                >
                  Tout cocher ({filteredEtudiants.length})
                </button>
                <button
                  type="button"
                  onClick={handleDeselectAll}
                  className="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-bold transition-colors"
                >
                  Tout décocher
                </button>
              </div>
            </div>

            {/* Barre de filtres étudiants */}
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative flex-1 min-w-[200px]">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                <input
                  type="text"
                  placeholder="Rechercher par nom, prénom, matricule..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <select
                value={niveauFilter}
                onChange={(e) => setNiveauFilter(e.target.value)}
                className="px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700"
              >
                <option value="all">Tous les Niveaux</option>
                <option value="Licence 1">Licence 1</option>
                <option value="Licence 2">Licence 2</option>
                <option value="Licence 3">Licence 3</option>
                <option value="Master 1">Master 1</option>
                <option value="Master 2">Master 2</option>
              </select>

              <select
                value={vagueFilter}
                onChange={(e) => setVagueFilter(e.target.value)}
                className="px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700"
              >
                <option value="all">Toutes les Vagues</option>
                <option value="Jour">Vague Jour</option>
                <option value="Soir">Cours du Soir</option>
              </select>
            </div>

            {/* Liste scrollable des étudiants */}
            <div className="border border-slate-200 rounded-2xl max-h-64 overflow-y-auto divide-y divide-slate-100">
              {filteredEtudiants.length === 0 ? (
                <div className="p-8 text-center text-xs text-slate-400">
                  Aucun étudiant ne correspond à cette recherche.
                </div>
              ) : (
                filteredEtudiants.map(et => {
                  const isSelected = selectedStudents.has(et.matricule);
                  const currentAmount = selectedStudents.get(et.matricule) ?? montantUnitaire;

                  return (
                    <div
                      key={et.matricule}
                      className={cn(
                        "p-3 flex items-center justify-between gap-3 transition-colors hover:bg-slate-50",
                        isSelected && "bg-blue-50/40"
                      )}
                    >
                      <div
                        onClick={() => toggleStudent(et.matricule)}
                        className="flex items-center gap-3 cursor-pointer flex-1"
                      >
                        <div className="text-blue-600">
                          {isSelected ? (
                            <CheckSquare className="w-5 h-5 text-blue-600" />
                          ) : (
                            <Square className="w-5 h-5 text-slate-300" />
                          )}
                        </div>

                        <div>
                          <div className="font-bold text-slate-900 text-xs">
                            {et.nom} {et.prenom}
                          </div>
                          <div className="text-[11px] text-slate-500 flex items-center gap-2">
                            <span className="font-mono font-semibold text-slate-700">{et.matricule}</span>
                            <span>•</span>
                            <span>{et.filiere} ({et.niveau})</span>
                            {et.vague && (
                              <span className="px-1.5 py-0.2 bg-slate-100 rounded text-[10px] font-bold text-slate-600">
                                {et.vague}
                              </span>
                            )}
                          </div>
                        </div>
                      </div>

                      {/* Montant individuel éditable si coché */}
                      {isSelected && (
                        <div className="flex items-center gap-1.5 shrink-0">
                          <label className="text-[11px] font-bold text-slate-500">Montant:</label>
                          <input
                            type="number"
                            value={currentAmount}
                            onChange={(e) => handleStudentAmountChange(et.matricule, Number(e.target.value) || 0)}
                            className="w-24 px-2 py-1 bg-white border border-blue-400 rounded-lg text-xs font-bold text-slate-900 text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                          <span className="text-[11px] font-bold text-slate-500">F</span>
                        </div>
                      )}
                    </div>
                  );
                })
              )}
            </div>
          </div>
        </div>

        {/* Footer avec récapitulatif & validation */}
        <div className="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shrink-0">
          <div>
            <div className="text-xs text-slate-500 font-bold">RÉCAPITULATIF DU LOT :</div>
            <div className="text-sm font-black text-slate-900 flex items-center gap-2">
              <span className="px-2 py-0.5 bg-blue-100 text-blue-800 rounded-md">
                {selectedStudents.size} Étudiant{selectedStudents.size > 1 ? 's' : ''}
              </span>
              <span>• Total:</span>
              <span className="text-emerald-600 text-base">
                {totalAmount.toLocaleString('fr-FR')} FCFA
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
              className="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-slate-800 rounded-xl"
            >
              Annuler
            </button>

            <button
              type="button"
              onClick={handleSubmitBatch}
              disabled={isSubmitting || selectedStudents.size === 0}
              className="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-xl text-xs font-black shadow-lg shadow-blue-500/25 transition-all flex items-center gap-2"
            >
              <ShieldCheck className="w-4 h-4" />
              {isSubmitting ? 'Traitement en cours...' : `Valider les ${selectedStudents.size} paiements`}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
