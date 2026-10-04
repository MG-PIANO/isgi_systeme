import React, { useState, useEffect } from 'react';
import {
  BookOpen,
  Plus,
  Search,
  Edit,
  Trash2,
  CheckCircle2,
  X,
  BookMarked
} from 'lucide-react';
import { db, logAction } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Matiere } from '../../types';

export const MatieresPage: React.FC = () => {
  const [matieres, setMatieres] = useState<Matiere[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingMatiere, setEditingMatiere] = useState<Matiere | null>(null);
  const [formData, setFormData] = useState<Partial<Matiere>>({
    credits: 3,
    coefficient: 2.0
  });
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  useEffect(() => {
    loadMatieres();
  }, []);

  const loadMatieres = async () => {
    const data = await db.matieres.toArray();
    setMatieres(data);
  };

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  const filteredMatieres = matieres.filter(m => {
    const q = searchQuery.toLowerCase().trim();
    return !q || m.code.toLowerCase().includes(q) || m.nom.toLowerCase().includes(q);
  });

  const handleOpenAdd = () => {
    setEditingMatiere(null);
    setFormData({ credits: 3, coefficient: 2.0 });
    setIsModalOpen(true);
  };

  const handleOpenEdit = (m: Matiere) => {
    setEditingMatiere(m);
    setFormData({ ...m });
    setIsModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.code || !formData.nom) return;

    if (editingMatiere) {
      const updated: Matiere = {
        ...editingMatiere,
        ...formData,
        code: (formData.code || '').toUpperCase(),
        credits: Number(formData.credits) || 1,
        coefficient: Number(formData.coefficient) || 1.0
      } as Matiere;

      await db.matieres.put(updated);
      try {
        await supabase.from('matieres').update(updated).eq('id', updated.id);
      } catch {}

      await logAction('Modification Matière', 'Matières', `Modification de ${updated.code} - ${updated.nom}`);
      setMatieres(prev => prev.map(m => m.id === updated.id ? updated : m));
      showToast(`Matière "${updated.nom}" mise à jour.`);
    } else {
      const newMat: Matiere = {
        id: 'mat_' + Date.now(),
        code: (formData.code || '').toUpperCase(),
        nom: formData.nom || '',
        credits: Number(formData.credits) || 1,
        coefficient: Number(formData.coefficient) || 1.0,
        description: formData.description || '',
        created_at: new Date().toISOString()
      };

      await db.matieres.put(newMat);
      try {
        await supabase.from('matieres').insert([newMat]);
      } catch {}

      await logAction('Création Matière', 'Matières', `Création de ${newMat.code} - ${newMat.nom}`);
      setMatieres(prev => [...prev, newMat]);
      showToast(`Matière "${newMat.nom}" ajoutée.`);
    }

    setIsModalOpen(false);
  };

  const handleDelete = async (id: string, nom: string) => {
    if (!confirm(`Supprimer la matière "${nom}" ?`)) return;

    await db.matieres.delete(id);
    try {
      await supabase.from('matieres').delete().eq('id', id);
    } catch {}

    await logAction('Suppression Matière', 'Matières', `Suppression de ${nom}`);
    setMatieres(prev => prev.filter(m => m.id !== id));
    showToast(`Matière "${nom}" supprimée.`);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Catalogue des Matières</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Gestion des unités d'enseignement, crédits ECTS et coefficients
          </p>
        </div>

        <button
          onClick={handleOpenAdd}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors"
        >
          <Plus className="w-4 h-4" />
          <span>Nouvelle Matière</span>
        </button>
      </div>

      {/* Barre recherche */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant p-4 flex justify-between items-center gap-4">
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Rechercher par code (INF101) ou intitulé..."
            className="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:ring-2 focus:ring-primary outline-none"
          />
        </div>
        <span className="text-xs font-semibold text-on-surface-variant">
          Total : {filteredMatieres.length} matière(s)
        </span>
      </div>

      {/* Grille matières */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredMatieres.map((mat) => (
          <div
            key={mat.id}
            className="bg-surface-container rounded-2xl border border-outline-variant p-5 shadow-sm flex flex-col justify-between space-y-4 hover:border-primary transition-colors"
          >
            <div>
              <div className="flex justify-between items-center">
                <span className="px-2.5 py-1 rounded-md bg-secondary-container text-on-secondary-container text-xs font-bold font-mono">
                  {mat.code}
                </span>
                <div className="flex gap-1">
                  <button
                    onClick={() => handleOpenEdit(mat)}
                    className="p-1.5 text-on-surface-variant hover:text-primary rounded-lg"
                  >
                    <Edit className="w-4 h-4" />
                  </button>
                  <button
                    onClick={() => handleDelete(mat.id, mat.nom)}
                    className="p-1.5 text-on-surface-variant hover:text-error rounded-lg"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>

              <h4 className="font-bold text-sm text-on-surface mt-2 leading-snug">{mat.nom}</h4>
              <p className="text-xs text-on-surface-variant mt-1 line-clamp-2">{mat.description || 'Sans description'}</p>
            </div>

            <div className="pt-3 border-t border-outline-variant flex justify-between text-xs text-on-surface-variant font-medium">
              <span>{mat.credits} Crédits</span>
              <span>Coeff : {mat.coefficient}</span>
            </div>
          </div>
        ))}
      </div>

      {/* Modal Ajout/Édition */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 bg-on-surface/20 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest rounded-3xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4 text-xs">
            <div className="flex justify-between items-center pb-3 border-b border-outline-variant">
              <h3 className="text-base font-bold text-on-surface">
                {editingMatiere ? 'Modifier la Matière' : 'Ajouter une Matière'}
              </h3>
              <button onClick={() => setIsModalOpen(false)}>
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSave} className="space-y-3">
              <div>
                <label className="font-semibold block mb-1">Code Matière *</label>
                <input
                  type="text"
                  required
                  placeholder="Ex: INF101"
                  value={formData.code || ''}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest uppercase outline-none focus:ring-2 focus:ring-primary"
                />
              </div>

              <div>
                <label className="font-semibold block mb-1">Intitulé de la Matière *</label>
                <input
                  type="text"
                  required
                  placeholder="Ex: Algorithmique et Programmation"
                  value={formData.nom || ''}
                  onChange={(e) => setFormData({ ...formData, nom: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold block mb-1">Crédits (ECTS)</label>
                  <input
                    type="number"
                    min="1"
                    value={formData.credits || 3}
                    onChange={(e) => setFormData({ ...formData, credits: Number(e.target.value) })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                  />
                </div>
                <div>
                  <label className="font-semibold block mb-1">Coefficient</label>
                  <input
                    type="number"
                    step="0.5"
                    min="0.5"
                    value={formData.coefficient || 2.0}
                    onChange={(e) => setFormData({ ...formData, coefficient: Number(e.target.value) })}
                    className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest outline-none focus:ring-2 focus:ring-primary"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="px-4 py-2 rounded-full border border-outline-variant font-medium"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-full bg-primary text-on-primary font-medium hover:bg-primary/90"
                >
                  Enregistrer
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Toast */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-on-surface text-surface-container-lowest px-4 py-3 rounded-2xl shadow-xl flex items-center gap-2 text-xs font-medium">
          <CheckCircle2 className="w-4 h-4 text-green-400" />
          <span>{toastMessage}</span>
        </div>
      )}
    </div>
  );
};
