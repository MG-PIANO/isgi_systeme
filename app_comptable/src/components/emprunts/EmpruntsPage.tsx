import React, { useState, useEffect, useMemo } from 'react';
import { supabase } from '../../db/supabaseClient';
import { useAuth } from '../../context/AuthContext';
import { Plus, Trash2, X, CheckCircle, Search } from 'lucide-react';
import { clsx } from 'clsx';

export function EmpruntsPage() {
  const { user } = useAuth();
  const [emprunts, setEmprunts] = useState<any[]>([]);
  const [personnel, setPersonnel] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Filtres
  const [searchQuery, setSearchQuery] = useState('');
  const [filterStatut, setFilterStatut] = useState('tous'); // tous | en_cours | rembourse

  const empruntsFiltres = useMemo(() => {
    return emprunts.filter(e => {
      const query = searchQuery.toLowerCase();
      const nom = `${e.personnel?.nom || ''} ${e.personnel?.prenom || ''}`.toLowerCase();
      const matchText = !query || nom.includes(query) || (e.motif || '').toLowerCase().includes(query);
      const matchStatut = filterStatut === 'tous' || e.statut === filterStatut;
      return matchText && matchStatut;
    });
  }, [emprunts, searchQuery, filterStatut]);

  const hasFilters = !!searchQuery || filterStatut !== 'tous';

  const [formData, setFormData] = useState({
    personnel_id: '',
    montant: '',
    motif: '',
    mois_deduction: new Date().getMonth() + 1 + '', // Next month roughly
    annee_deduction: new Date().getFullYear().toString()
  });

  const mois = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
  ];

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      const [empruntsRes, personnelRes] = await Promise.all([
        supabase.from('emprunts').select('*, personnel(nom, prenom)').order('date_emprunt', { ascending: false }),
        supabase.from('personnel').select('id, nom, prenom').order('nom', { ascending: true })
      ]);
      
      if (empruntsRes.error) throw empruntsRes.error;
      if (personnelRes.error) throw personnelRes.error;

      setEmprunts(empruntsRes.data || []);
      setPersonnel(personnelRes.data || []);
    } catch (error) {
      console.error('Error fetching data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.personnel_id || !formData.montant || isNaN(Number(formData.montant))) return;
    setSubmitting(true);
    
    try {
      const { error } = await supabase
        .from('emprunts')
        .insert([{
          personnel_id: formData.personnel_id,
          montant: Number(formData.montant),
          motif: formData.motif,
          mois_deduction: mois[parseInt(formData.mois_deduction) - 1] || 'Janvier',
          annee_deduction: Number(formData.annee_deduction),
          statut: 'en_cours'
        }]);

      if (error) throw error;
      
      const pers = personnel.find(p => p.id === formData.personnel_id);
      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: 'AJOUT_EMPRUNT',
        description: `Avance de ${formData.montant} FCFA accordée à ${pers?.nom} ${pers?.prenom}`
      }]);

      setShowModal(false);
      setFormData({ ...formData, montant: '', motif: '', personnel_id: '' });
      fetchData();
    } catch (error) {
      console.error('Error adding emprunt:', error);
      alert('Erreur lors de l\'enregistrement de l\'avance.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleMarquerRembourse = async (id: string, nom: string) => {
    if (!window.confirm(`Marquer cette avance de ${nom} comme remboursée (hors paie) ?`)) return;
    try {
      const { error } = await supabase.from('emprunts').update({ statut: 'rembourse' }).eq('id', id);
      if (error) throw error;
      fetchData();
    } catch (error) {
      console.error('Error updating emprunt:', error);
    }
  };

  const handleDelete = async (id: string) => {
    if (!window.confirm("Êtes-vous sûr de vouloir supprimer cette avance ?")) return;
    try {
      const { error } = await supabase.from('emprunts').delete().eq('id', id);
      if (error) throw error;
      fetchData();
    } catch (error) {
      console.error('Error deleting:', error);
    }
  };

  const totalEnCours = emprunts.filter(e => e.statut === 'en_cours').reduce((sum, e) => sum + e.montant, 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Avances & Emprunts</h1>
          <p className="mt-1 text-sm text-gray-500">
            Gestion des avances sur salaire du personnel
          </p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
        >
          <Plus className="h-4 w-4" />
          Nouvelle avance
        </button>
      </div>
      
      {/* KPI Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-xl border border-orange-200 bg-orange-50 p-6">
          <div className="flex items-center gap-2 text-sm font-medium text-orange-600">
            Total des avances en cours (non déduites)
          </div>
          <div className="mt-2 text-3xl font-bold text-orange-900">
            {totalEnCours.toLocaleString('fr-FR')} FCFA
          </div>
        </div>
      </div>

      {/* Barre de Recherche & Filtres */}
      <div className="bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5 p-4">
        <div className="flex flex-col sm:flex-row gap-3 items-center">
          <div className="relative flex-1 w-full">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par nom du personnel ou motif..."
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2 rounded-lg border border-gray-300 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
            {searchQuery && (
              <button onClick={() => setSearchQuery('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                <X className="h-4 w-4" />
              </button>
            )}
          </div>
          <select
            value={filterStatut}
            onChange={e => setFilterStatut(e.target.value)}
            className="rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="tous">Tous les statuts</option>
            <option value="en_cours">En cours (non déduit)</option>
            <option value="rembourse">Remboursé</option>
          </select>
          {hasFilters && (
            <button
              onClick={() => { setSearchQuery(''); setFilterStatut('tous'); }}
              className="text-sm text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap"
            >
              Effacer les filtres
            </button>
          )}
        </div>
      </div>

      {/* Content area */}
      <div className="bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-300">
            <thead>
              <tr className="bg-gray-50">
                <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Date</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Personnel</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Montant</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Déduction prévue</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Statut</th>
                <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {loading ? (
                <tr><td colSpan={6} className="py-10 text-center text-sm text-gray-500">Chargement...</td></tr>
              ) : empruntsFiltres.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-sm text-gray-500">
                    {hasFilters ? 'Aucune avance ne correspond à vos critères.' : 'Aucune avance enregistrée'}
                  </td>
                </tr>
              ) : (
                empruntsFiltres.map((emprunt) => (
                  <tr key={emprunt.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                      {new Date(emprunt.date_emprunt).toLocaleDateString('fr-FR')}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                      {emprunt.personnel?.nom} {emprunt.personnel?.prenom}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-bold text-gray-900">
                      {emprunt.montant.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      {emprunt.mois_deduction} {emprunt.annee_deduction}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                      <span className={clsx("inline-flex items-center rounded-md px-2 py-1 text-xs font-medium",
                        emprunt.statut === 'en_cours' ? "bg-orange-50 text-orange-700" : "bg-green-50 text-green-700"
                      )}>
                        {emprunt.statut === 'en_cours' ? 'En cours' : 'Remboursé'}
                      </span>
                    </td>
                    <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                      {emprunt.statut === 'en_cours' && (
                        <button 
                          onClick={() => handleMarquerRembourse(emprunt.id, emprunt.personnel?.nom)}
                          className="text-green-600 hover:text-green-900 mr-4 transition-colors"
                          title="Marquer comme remboursé"
                        >
                          <CheckCircle className="h-5 w-5" />
                        </button>
                      )}
                      <button 
                        onClick={() => handleDelete(emprunt.id)}
                        className="text-red-600 hover:text-red-900 transition-colors"
                        title="Supprimer"
                      >
                        <Trash2 className="h-5 w-5" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="text-lg font-semibold text-gray-900">Accorder une avance</h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-gray-500">
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Personnel</label>
                <select
                  required
                  value={formData.personnel_id}
                  onChange={e => setFormData({ ...formData, personnel_id: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                >
                  <option value="">Sélectionner un employé...</option>
                  {personnel.map(p => (
                    <option key={p.id} value={p.id}>{p.nom} {p.prenom}</option>
                  ))}
                </select>
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Montant (FCFA)</label>
                <input
                  type="number"
                  required
                  min="1"
                  value={formData.montant}
                  onChange={e => setFormData({ ...formData, montant: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Motif</label>
                <input
                  type="text"
                  value={formData.motif}
                  onChange={e => setFormData({ ...formData, motif: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  placeholder="Optionnel"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Mois de déduction</label>
                  <select
                    value={formData.mois_deduction}
                    onChange={e => setFormData({ ...formData, mois_deduction: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  >
                    {mois.map((m, i) => <option key={i} value={i+1}>{m}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Année</label>
                  <input
                    type="number"
                    required
                    min="2020"
                    value={formData.annee_deduction}
                    onChange={e => setFormData({ ...formData, annee_deduction: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
              </div>

              <div className="pt-4 flex justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                >
                  {submitting ? 'Enregistrement...' : 'Valider l\'avance'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
