import { useState, useEffect, useMemo } from 'react';
import { supabase } from '../../db/supabaseClient';
import { useAuth } from '../../context/AuthContext';
import { Plus, Search, Trash2, X } from 'lucide-react';
import { useSettings } from '../../hooks/useSettings';

export function DepensesPage() {
  const { user } = useAuth();
  const { settings } = useSettings();
  const [depenses, setDepenses] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Filtres de recherche
  const [searchQuery, setSearchQuery] = useState('');
  const [filterCategorie, setFilterCategorie] = useState('toutes');
  const [filterPeriode, setFilterPeriode] = useState('tous'); // tous | aujourd_hui | semaine | mois

  const [formData, setFormData] = useState({
    categorie: settings.categoriesDepenses[0] || 'Autre',
    montant: '',
    description: '',
    preuve_url: '',
    date_depense: new Date().toISOString().split('T')[0]
  });

  const categories = settings.categoriesDepenses;

  // Filtrage côté client en temps réel
  const depensesFiltrees = useMemo(() => {
    const now = new Date();
    return depenses.filter(d => {
      const query = searchQuery.toLowerCase();
      const matchText = !query ||
        (d.description || '').toLowerCase().includes(query) ||
        (d.enregistre_par || '').toLowerCase().includes(query) ||
        (d.categorie || '').toLowerCase().includes(query);

      const matchCat = filterCategorie === 'toutes' || d.categorie === filterCategorie;

      const dateD = new Date(d.date_depense);
      let matchPeriode = true;
      if (filterPeriode === 'aujourd_hui') {
        matchPeriode = dateD.toDateString() === now.toDateString();
      } else if (filterPeriode === 'semaine') {
        const startOfWeek = new Date(now);
        startOfWeek.setDate(now.getDate() - now.getDay());
        startOfWeek.setHours(0, 0, 0, 0);
        matchPeriode = dateD >= startOfWeek;
      } else if (filterPeriode === 'mois') {
        matchPeriode = dateD.getMonth() === now.getMonth() && dateD.getFullYear() === now.getFullYear();
      }
      return matchText && matchCat && matchPeriode;
    });
  }, [depenses, searchQuery, filterCategorie, filterPeriode]);

  const hasFilters = !!searchQuery || filterCategorie !== 'toutes' || filterPeriode !== 'tous';

  // Fetch data
  useEffect(() => {
    fetchDepenses();
  }, []);

  const fetchDepenses = async () => {
    try {
      const { data, error } = await supabase
        .from('depenses')
        .select('*')
        .order('date_depense', { ascending: false });
        
      if (error) throw error;
      setDepenses(data || []);
    } catch (error) {
      console.error('Error fetching depenses:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.montant || isNaN(Number(formData.montant))) return;
    setSubmitting(true);
    try {
      const { error } = await supabase
        .from('depenses')
        .insert([{
          categorie: formData.categorie,
          montant: Number(formData.montant),
          description: formData.description,
          preuve_url: formData.preuve_url,
          date_depense: new Date(formData.date_depense).toISOString(),
          enregistre_par: user?.name || 'Comptable'
        }]);

      if (error) throw error;
      
      // Log activity
      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: 'AJOUT_DEPENSE',
        description: `Dépense de ${formData.montant} FCFA enregistrée (${formData.categorie})`
      }]);

      setShowModal(false);
      setFormData({
        categorie: settings.categoriesDepenses[0] || 'Autre',
        montant: '',
        description: '',
        preuve_url: '',
        date_depense: new Date().toISOString().split('T')[0]
      });
      fetchDepenses();
    } catch (error) {
      console.error('Error adding depense:', error);
      alert('Erreur lors de l\'enregistrement de la dépense.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id: string, montant: number, categorie: string) => {
    if (!window.confirm("Êtes-vous sûr de vouloir supprimer cette dépense ?")) return;
    try {
      const { error } = await supabase.from('depenses').delete().eq('id', id);
      if (error) throw error;

      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: 'SUPPRESSION_DEPENSE',
        description: `Suppression d'une dépense de ${montant} FCFA (${categorie})`
      }]);

      fetchDepenses();
    } catch (error) {
      console.error('Error deleting depense:', error);
      alert('Erreur lors de la suppression.');
    }
  };

  const totalDepenses = depenses.reduce((sum, d) => sum + d.montant, 0);
  const totalFiltrees = depensesFiltrees.reduce((sum, d) => sum + d.montant, 0);

  return (
    <div className="space-y-6 relative">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Gestion des Dépenses</h1>
          <p className="mt-1 text-sm text-gray-500">
            Suivi des sorties de caisse et frais de fonctionnement
          </p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
        >
          <Plus className="h-4 w-4" />
          Enregistrer une dépense
        </button>
      </div>
      
      {/* KPI Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-xl border border-red-200 bg-red-50 p-6">
          <div className="flex items-center gap-2 text-sm font-medium text-red-600">
            Total des dépenses (tout)
          </div>
          <div className="mt-2 text-3xl font-bold text-red-900">
            {totalDepenses.toLocaleString('fr-FR')} FCFA
          </div>
        </div>
        {hasFilters && (
          <div className="rounded-xl border border-orange-200 bg-orange-50 p-6">
            <div className="text-sm font-medium text-orange-600">
              Résultat filtré ({depensesFiltrees.length} entrée{depensesFiltrees.length > 1 ? 's' : ''})
            </div>
            <div className="mt-2 text-3xl font-bold text-orange-900">
              {totalFiltrees.toLocaleString('fr-FR')} FCFA
            </div>
          </div>
        )}
      </div>

      {/* Barre de Recherche & Filtres */}
      <div className="bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5 p-4">
        <div className="flex flex-col sm:flex-row gap-3 items-center">
          <div className="relative flex-1 w-full">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par description, catégorie, enregistré par..."
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
            value={filterCategorie}
            onChange={e => setFilterCategorie(e.target.value)}
            className="rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="toutes">Toutes les catégories</option>
            {categories.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
          <select
            value={filterPeriode}
            onChange={e => setFilterPeriode(e.target.value)}
            className="rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="tous">Toutes les dates</option>
            <option value="aujourd_hui">Aujourd'hui</option>
            <option value="semaine">Cette semaine</option>
            <option value="mois">Ce mois</option>
          </select>
          {hasFilters && (
            <button
              onClick={() => { setSearchQuery(''); setFilterCategorie('toutes'); setFilterPeriode('tous'); }}
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
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Catégorie</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Description</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Montant</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Enregistré par</th>
                <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {loading ? (
                <tr><td colSpan={6} className="py-10 text-center text-sm text-gray-500">Chargement...</td></tr>
              ) : depensesFiltrees.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-sm text-gray-500">
                    {hasFilters ? 'Aucune dépense ne correspond à vos critères.' : 'Aucune dépense enregistrée'}
                  </td>
                </tr>
              ) : (
                depensesFiltrees.map((depense) => (
                  <tr key={depense.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                      {new Date(depense.date_depense).toLocaleDateString('fr-FR')}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
                        {depense.categorie}
                      </span>
                    </td>
                    <td className="px-3 py-4 text-sm text-gray-500 max-w-xs truncate" title={depense.description}>
                      {depense.description || '-'}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-bold text-red-600">
                      - {depense.montant.toLocaleString('fr-FR')} FCFA
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{depense.enregistre_par}</td>
                    <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                      <button 
                        onClick={() => handleDelete(depense.id, depense.montant, depense.categorie)}
                        className="text-red-600 hover:text-red-900 transition-colors"
                        title="Supprimer"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {!loading && depensesFiltrees.length > 0 && (
          <div className="px-6 py-3 border-t border-gray-100 text-sm text-gray-500">
            {depensesFiltrees.length} dépense(s) affichée(s) sur {depenses.length} au total
          </div>
        )}
      </div>

      {/* Add Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="text-lg font-semibold text-gray-900">Enregistrer une dépense</h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-gray-500">
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Date</label>
                <input
                  type="date"
                  required
                  value={formData.date_depense}
                  onChange={e => setFormData({ ...formData, date_depense: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                <select
                  value={formData.categorie}
                  onChange={e => setFormData({ ...formData, categorie: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                >
                  {categories.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Montant (FCFA)</label>
                <input
                  type="number"
                  required
                  min="0"
                  value={formData.montant}
                  onChange={e => setFormData({ ...formData, montant: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  placeholder="Ex: 50000"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Description / Motif</label>
                <textarea
                  value={formData.description}
                  onChange={e => setFormData({ ...formData, description: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  rows={3}
                  placeholder="Détails de la dépense..."
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preuve / Référence (Optionnel)</label>
                <input
                  type="text"
                  value={formData.preuve_url}
                  onChange={e => setFormData({ ...formData, preuve_url: e.target.value })}
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  placeholder="N° Facture ou lien"
                />
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
                  {submitting ? 'Enregistrement...' : 'Enregistrer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
