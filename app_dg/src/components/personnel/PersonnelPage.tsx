import { useState, useEffect, useMemo } from 'react';
import { supabase } from '../../db/supabaseClient';
import { useAuth } from '../../context/AuthContext';
import { UserPlus, Trash2, X, Edit2, Search } from 'lucide-react';
import clsx from 'clsx';

export function PersonnelPage() {
  const { user } = useAuth();
  const [personnel, setPersonnel] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  // Filtres
  const [searchQuery, setSearchQuery] = useState('');
  const [filterType, setFilterType] = useState('tous'); // tous | enseignant | administratif

  // Filtrage côté client
  const personnelFiltres = useMemo(() => {
    return personnel.filter(p => {
      const query = searchQuery.toLowerCase();
      const matchText = !query ||
        (p.matricule || '').toLowerCase().includes(query) ||
        (p.nom || '').toLowerCase().includes(query) ||
        (p.prenom || '').toLowerCase().includes(query) ||
        (p.role_administratif || '').toLowerCase().includes(query);
      const matchType = filterType === 'tous' || p.type_personnel === filterType;
      return matchText && matchType;
    });
  }, [personnel, searchQuery, filterType]);

  const hasFilters = !!searchQuery || filterType !== 'tous';

  const [formData, setFormData] = useState({
    matricule: '',
    nom: '',
    prenom: '',
    type_personnel: 'administratif',
    role_administratif: '',
    numero_cnss: '',
    date_entree: '',
    telephone: '',
    email: '',
    taux_horaire: '',
    salaire_base: ''
  });

  useEffect(() => {
    fetchPersonnel();
  }, []);

  const fetchPersonnel = async () => {
    try {
      const { data, error } = await supabase
        .from('personnel')
        .select('*')
        .order('nom', { ascending: true });
        
      if (error) throw error;
      setPersonnel(data || []);
    } catch (error) {
      console.error('Error fetching personnel:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const payload = {
        matricule: formData.matricule,
        nom: formData.nom,
        prenom: formData.prenom,
        type_personnel: formData.type_personnel,
        role_administratif: formData.type_personnel === 'administratif' ? formData.role_administratif : null,
        numero_cnss: formData.numero_cnss || null,
        date_entree: formData.date_entree || null,
        telephone: formData.telephone,
        email: formData.email,
        taux_horaire: formData.type_personnel === 'enseignant' ? Number(formData.taux_horaire) : null,
        salaire_base: formData.type_personnel === 'administratif' ? Number(formData.salaire_base) : null,
      };

      let error;
      if (editingId) {
        const { error: updateError } = await supabase
          .from('personnel')
          .update(payload)
          .eq('id', editingId);
        error = updateError;
      } else {
        const { error: insertError } = await supabase
          .from('personnel')
          .insert([payload]);
        error = insertError;
      }

      if (error) throw error;
      
      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: editingId ? 'MODIFICATION_PERSONNEL' : 'AJOUT_PERSONNEL',
        description: `${editingId ? 'Modification' : 'Ajout'} du personnel ${formData.nom} ${formData.prenom}`
      }]);

      setShowModal(false);
      setEditingId(null);
      setFormData({
        matricule: '',
        nom: '',
        prenom: '',
        type_personnel: 'administratif',
        role_administratif: '',
        numero_cnss: '',
        date_entree: '',
        telephone: '',
        email: '',
        taux_horaire: '',
        salaire_base: ''
      });
      fetchPersonnel();
    } catch (error) {
      console.error('Error adding personnel:', error);
      alert('Erreur lors de l\'enregistrement. Le matricule doit être unique.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id: string, nom: string, prenom: string) => {
    if (!window.confirm("Êtes-vous sûr de vouloir supprimer cette personne ?")) return;
    try {
      const { error } = await supabase.from('personnel').delete().eq('id', id);
      if (error) throw error;

      await supabase.from('journal_activites').insert([{
        utilisateur_id: user?.id,
        utilisateur_nom: user?.name,
        type_action: 'SUPPRESSION_PERSONNEL',
        description: `Suppression du personnel ${nom} ${prenom}`
      }]);

      fetchPersonnel();
    } catch (error) {
      console.error('Error deleting personnel:', error);
      alert('Erreur lors de la suppression.');
    }
  };

  const handleEditClick = (p: any) => {
    setFormData({
      matricule: p.matricule || '',
      nom: p.nom || '',
      prenom: p.prenom || '',
      type_personnel: p.type_personnel || 'administratif',
      role_administratif: p.role_administratif || '',
      numero_cnss: p.numero_cnss || '',
      date_entree: p.date_entree || '',
      telephone: p.telephone || '',
      email: p.email || '',
      taux_horaire: p.taux_horaire?.toString() || '',
      salaire_base: p.salaire_base?.toString() || ''
    });
    setEditingId(p.id);
    setShowModal(true);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Gestion du Personnel</h1>
          <p className="mt-1 text-sm text-gray-500">
            Enseignants et personnel administratif
          </p>
        </div>
        <button 
            onClick={() => {
              setEditingId(null);
              setFormData({
                matricule: '', nom: '', prenom: '', type_personnel: 'administratif',
                role_administratif: '', numero_cnss: '', date_entree: '', telephone: '',
                email: '', taux_horaire: '', salaire_base: ''
              });
              setShowModal(true);
            }}
            className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
        >
          <UserPlus className="h-4 w-4" />
          Ajouter un personnel
        </button>
      </div>

      {/* Barre de Recherche & Filtres */}
      <div className="bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5 p-4">
        <div className="flex flex-col sm:flex-row gap-3 items-center">
          <div className="relative flex-1 w-full">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par matricule, nom, prénom, fonction..."
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
            value={filterType}
            onChange={e => setFilterType(e.target.value)}
            className="rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="tous">Tous les types</option>
            <option value="enseignant">Enseignants</option>
            <option value="administratif">Administratifs</option>
          </select>
          {hasFilters && (
            <button
              onClick={() => { setSearchQuery(''); setFilterType('tous'); }}
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
                <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Matricule</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Nom & Prénom</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Type</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Infos Salaire</th>
                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Contact</th>
                <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {loading ? (
                <tr><td colSpan={6} className="py-10 text-center text-sm text-gray-500">Chargement...</td></tr>
              ) : personnelFiltres.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-sm text-gray-500">
                    {hasFilters ? 'Aucun personnel ne correspond à vos critères.' : 'Aucun personnel enregistré'}
                  </td>
                </tr>
              ) : (
                personnelFiltres.map((p) => (
                  <tr key={p.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900">{p.matricule}</td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">{p.nom} {p.prenom}</td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      <span className={clsx("inline-flex items-center rounded-md px-2 py-1 text-xs font-medium",
                        p.type_personnel === 'enseignant' ? "bg-purple-50 text-purple-700" : "bg-blue-50 text-blue-700"
                      )}>
                        {p.type_personnel} {p.role_administratif ? `(${p.role_administratif})` : ''}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      {p.type_personnel === 'enseignant' ? (
                        <span>Taux: <b>{p.taux_horaire?.toLocaleString('fr-FR')} FCFA/h</b></span>
                      ) : (
                        <span>Base: <b>{p.salaire_base?.toLocaleString('fr-FR')} FCFA/mois</b></span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                      {p.telephone}<br/><span className="text-xs text-gray-400">{p.email}</span>
                    </td>
                    <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6 flex justify-end gap-2">
                      <button 
                        onClick={() => handleEditClick(p)}
                        className="text-blue-600 hover:text-blue-900 transition-colors"
                        title="Modifier"
                      >
                        <Edit2 className="h-4 w-4" />
                      </button>
                      <button 
                        onClick={() => handleDelete(p.id, p.nom, p.prenom)}
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
      </div>

      {/* Add Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-sm">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="text-lg font-semibold text-gray-900">{editingId ? 'Modifier un personnel' : 'Ajouter un personnel'}</h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-gray-500">
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Matricule</label>
                  <input
                    type="text"
                    required
                    value={formData.matricule}
                    onChange={e => setFormData({ ...formData, matricule: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Type de personnel</label>
                  <select
                    value={formData.type_personnel}
                    onChange={e => setFormData({ ...formData, type_personnel: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  >
                    <option value="administratif">Administratif</option>
                    <option value="enseignant">Enseignant</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                  <input
                    type="text"
                    required
                    value={formData.nom}
                    onChange={e => setFormData({ ...formData, nom: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                  <input
                    type="text"
                    required
                    value={formData.prenom}
                    onChange={e => setFormData({ ...formData, prenom: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                  <input
                    type="text"
                    value={formData.telephone}
                    onChange={e => setFormData({ ...formData, telephone: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={e => setFormData({ ...formData, email: e.target.value })}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                  />
                </div>

                {formData.type_personnel === 'enseignant' ? (
                  <div className="col-span-2">
                    <label className="block text-sm font-medium text-gray-700 mb-1">Taux Horaire (FCFA/heure)</label>
                    <input
                      type="number"
                      required
                      value={formData.taux_horaire}
                      onChange={e => setFormData({ ...formData, taux_horaire: e.target.value })}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                    />
                  </div>
                ) : (
                  <div className="col-span-2 grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                      <label className="block text-sm font-medium text-gray-700 mb-1">Rôle / Fonction (ex: Secrétaire, Gardien, etc.)</label>
                      <input
                        type="text"
                        required
                        value={formData.role_administratif}
                        onChange={e => setFormData({ ...formData, role_administratif: e.target.value })}
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                      />
                    </div>
                    <div className="col-span-2">
                      <label className="block text-sm font-medium text-gray-700 mb-1">Salaire de Base Mensuel (FCFA)</label>
                      <input
                        type="number"
                        required
                        value={formData.salaire_base}
                        onChange={e => setFormData({ ...formData, salaire_base: e.target.value })}
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                      />
                    </div>
                  </div>
                )}

                <div className="col-span-2 grid grid-cols-2 gap-4 mt-2 border-t pt-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">N° Immatriculation C.N.S.S.</label>
                    <input
                      type="text"
                      value={formData.numero_cnss}
                      onChange={e => setFormData({ ...formData, numero_cnss: e.target.value })}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Date d'entrée en service</label>
                    <input
                      type="date"
                      value={formData.date_entree}
                      onChange={e => setFormData({ ...formData, date_entree: e.target.value })}
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
                    />
                  </div>
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
