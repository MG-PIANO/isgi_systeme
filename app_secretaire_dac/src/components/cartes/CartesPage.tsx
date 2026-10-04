import React, { useState, useEffect } from 'react';
import {
  CreditCard,
  Search,
  Download,
  Printer,
  Sparkles
} from 'lucide-react';
import { db } from '../../db/db';
import type { Etudiant, Classe } from '../../types';
import { genererCarteEtudiantPDF, genererPlancheCartesPDF } from '../../utils/carteExport';

export const CartesPage: React.FC = () => {
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [classes, setClasses] = useState<Classe[]>([]);
  const [selectedEtudiant, setSelectedEtudiant] = useState<Etudiant | null>(null);

  const [searchQuery, setSearchQuery] = useState('');
  const [filterClasse, setFilterClasse] = useState('');
  const [filterNiveau, setFilterNiveau] = useState('');
  const [generatingBatch, setGeneratingBatch] = useState(false);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      const etuds = await db.etudiants.toArray();
      const cls = await db.classes.toArray();
      setEtudiants(etuds);
      setClasses(cls);
      if (etuds.length > 0) {
        setSelectedEtudiant(etuds[0]);
      }
    } catch (e) {
      console.error(e);
    }
  };

  const filteredEtudiants = etudiants.filter((e) => {
    const q = searchQuery.toLowerCase().trim();
    const matchQ =
      !q ||
      e.nom.toLowerCase().includes(q) ||
      e.prenom.toLowerCase().includes(q) ||
      e.matricule.toLowerCase().includes(q);
    const matchNiveau = !filterNiveau || e.niveau === filterNiveau;
    return matchQ && matchNiveau;
  });

  const handlePrintBatch = async () => {
    if (filteredEtudiants.length === 0) return;
    setGeneratingBatch(true);
    try {
      await genererPlancheCartesPDF(filteredEtudiants, `Planche_Cartes_${filterNiveau || 'Tous'}.pdf`);
    } catch (err) {
      console.error(err);
    } finally {
      setGeneratingBatch(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Cartes & Badges d'Étudiants</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Génération de cartes conformes avec photo, informations académiques et export PDF
          </p>
        </div>

        <button
          onClick={handlePrintBatch}
          disabled={generatingBatch || filteredEtudiants.length === 0}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors disabled:opacity-50"
        >
          <Printer className="w-4 h-4" />
          <span>{generatingBatch ? 'Génération...' : `Imprimer la sélection (${filteredEtudiants.length})`}</span>
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne gauche : liste des étudiants */}
        <div className="lg:col-span-5 bg-surface-container rounded-2xl border border-outline-variant p-5 space-y-4">
          <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 className="font-semibold text-sm text-on-surface">Sélectionner un étudiant</h3>
            <span className="text-xs text-on-surface-variant">{filteredEtudiants.length} résultats</span>
          </div>

          <div className="space-y-2">
            <div className="relative">
              <Search className="w-4 h-4 text-on-surface-variant absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Rechercher nom, matricule..."
                className="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:ring-2 focus:ring-primary outline-none"
              />
            </div>

            <div className="grid grid-cols-2 gap-2">
              <select
                value={filterNiveau}
                onChange={(e) => setFilterNiveau(e.target.value)}
                className="p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Tous les Niveaux</option>
                <option value="Licence 1">Licence 1</option>
                <option value="Licence 2">Licence 2</option>
                <option value="Licence 3">Licence 3</option>
                <option value="Master 1">Master 1</option>
                <option value="Master 2">Master 2</option>
              </select>

              <select
                value={filterClasse}
                onChange={(e) => setFilterClasse(e.target.value)}
                className="p-2 text-xs rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none"
              >
                <option value="">Toutes les Classes</option>
                {classes.map(c => (
                  <option key={c.id} value={c.id}>{c.code} - {c.niveau}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="divide-y divide-outline-variant max-h-[460px] overflow-y-auto custom-scrollbar border rounded-xl border-outline-variant bg-surface-container-lowest">
            {filteredEtudiants.map((etud) => {
              const isSelected = selectedEtudiant?.id === etud.id;
              return (
                <div
                  key={etud.id}
                  onClick={() => setSelectedEtudiant(etud)}
                  className={`
                    p-3 flex items-center justify-between cursor-pointer transition-colors
                    ${isSelected ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'hover:bg-surface-container-high/50 text-on-surface'}
                  `}
                >
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center shrink-0">
                      {etud.nom?.[0] || 'E'}
                    </div>
                    <div>
                      <p className="text-xs font-bold leading-tight">
                        {etud.nom.toUpperCase()} {etud.prenom}
                      </p>
                      <p className="text-[11px] text-on-surface-variant">
                        {etud.matricule} • {etud.niveau}
                      </p>
                    </div>
                  </div>

                  <span className="text-[10px] px-2 py-0.5 rounded-full bg-surface-container-high text-on-surface">
                    {etud.filiere?.substring(0, 14)}
                  </span>
                </div>
              );
            })}
          </div>
        </div>

        {/* Colonne droite : Aperçu en direct */}
        <div className="lg:col-span-7">
          {selectedEtudiant ? (
            <div className="bg-surface-container rounded-2xl border border-outline-variant p-6 space-y-6">
              <div className="flex justify-between items-center pb-2 border-b border-outline-variant">
                <div>
                  <h3 className="font-bold text-base text-on-surface">Aperçu de la Carte d'Étudiant</h3>
                  <p className="text-xs text-on-surface-variant">Modèle officiel d'impression CR-80</p>
                </div>

                <button
                  onClick={() => genererCarteEtudiantPDF(selectedEtudiant)}
                  className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium text-xs hover:bg-primary/90 transition-colors"
                >
                  <Download className="w-4 h-4" />
                  <span>Télécharger PDF</span>
                </button>
              </div>

              {/* Simulation réaliste de la carte */}
              <div className="flex justify-center p-6 bg-surface-container-lowest rounded-2xl border border-outline-variant">
                <div className="w-[380px] h-[240px] bg-white text-slate-900 rounded-2xl shadow-xl overflow-hidden border border-slate-200 flex flex-col justify-between">
                  {/* Header Badge */}
                  <div className="bg-[#004ac6] text-white px-4 py-2.5 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <img src="./logo.jpg" alt="Logo" className="w-7 h-7 rounded-md object-contain bg-white p-0.5" />
                      <div>
                        <h4 className="font-bold text-xs leading-tight">ISGI SYSTEM</h4>
                        <p className="text-[9px] text-blue-100">CARTE D'ÉTUDIANT</p>
                      </div>
                    </div>
                    <span className="text-[9px] bg-white/20 px-2 py-0.5 rounded-full font-medium">
                      2025-2026
                    </span>
                  </div>

                  {/* Body Badge */}
                  <div className="px-4 py-3 flex items-center gap-4">
                    <div className="w-20 h-28 rounded-xl bg-slate-100 border border-slate-200 flex flex-col items-center justify-center shrink-0">
                      {selectedEtudiant.photo_url ? (
                        <img src={selectedEtudiant.photo_url} alt="Photo" className="w-full h-full object-cover rounded-xl" />
                      ) : (
                        <div className="w-10 h-10 rounded-full bg-blue-600 text-white font-bold text-sm flex items-center justify-center">
                          {selectedEtudiant.nom?.[0] || 'E'}
                        </div>
                      )}
                    </div>

                    <div className="space-y-1 text-xs">
                      <div className="inline-block px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-bold">
                        {selectedEtudiant.matricule}
                      </div>
                      <h3 className="font-bold text-sm text-slate-900">
                        {selectedEtudiant.nom.toUpperCase()} {selectedEtudiant.prenom}
                      </h3>
                      <p className="text-[11px] text-slate-500">Niveau : <strong className="text-slate-800">{selectedEtudiant.niveau}</strong></p>
                      <p className="text-[11px] text-slate-500">Filière : <strong className="text-slate-800">{selectedEtudiant.filiere}</strong></p>
                      <p className="text-[11px] text-slate-500">Tél : {selectedEtudiant.telephone || 'N/A'}</p>
                    </div>
                  </div>

                  {/* Footer Badge */}
                  <div className="bg-slate-50 border-t border-slate-200 px-4 py-1.5 flex justify-between text-[9px] text-slate-500 font-medium">
                    <span>DIRECTION DES AFFAIRES ACADÉMIQUES</span>
                    <span>Validité : 1 an</span>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="bg-surface-container rounded-2xl border border-outline-variant p-12 text-center text-on-surface-variant">
              Sélectionnez un étudiant dans la liste.
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
