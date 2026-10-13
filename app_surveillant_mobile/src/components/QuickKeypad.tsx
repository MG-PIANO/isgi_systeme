import React, { useState } from 'react';
import { Delete, Search, Check, X, User } from 'lucide-react';
import type { Etudiant } from '../types';

interface QuickKeypadProps {
  etudiants: Etudiant[];
  onValiderMatricule: (matricule: string) => void;
  onFermer: () => void;
}

export const QuickKeypad: React.FC<QuickKeypadProps> = ({ etudiants, onValiderMatricule, onFermer }) => {
  const [saisie, setSaisie] = useState('');

  const ajouterTouche = (val: string) => {
    if (saisie.length < 15) setSaisie(prev => prev + val);
  };

  const effacer = () => {
    setSaisie(prev => prev.slice(0, -1));
  };

  const effacerTout = () => {
    setSaisie('');
  };

  // Filtrage en direct par matricule ou nom
  const suggestions = etudiants.filter(e => 
    saisie.trim() !== '' && (
      e.matricule.toLowerCase().includes(saisie.toLowerCase()) ||
      e.nom.toLowerCase().includes(saisie.toLowerCase()) ||
      e.prenom.toLowerCase().includes(saisie.toLowerCase())
    )
  ).slice(0, 3);

  const handleValider = () => {
    if (saisie.trim()) {
      onValiderMatricule(saisie.trim());
    }
  };

  return (
    <div className="fixed inset-0 z-50 bg-slate-950/95 backdrop-blur-md flex flex-col justify-between p-4 pb-safe pt-safe animate-fadeIn">
      {/* En-tête */}
      <div className="flex items-center justify-between pb-3 border-b border-slate-800">
        <div>
          <h3 className="text-base font-bold text-white font-heading">
            Saisie Rapide du Matricule
          </h3>
          <p className="text-xs text-slate-400">En cas d'oubli de badge QR Code</p>
        </div>
        <button
          onClick={onFermer}
          className="p-2 rounded-full bg-slate-800 text-slate-400 hover:text-white"
        >
          <X className="w-5 h-5" />
        </button>
      </div>

      {/* Champ de saisie direct (Nom ou Matricule avec clavier smartphone) */}
      <div className="space-y-2">
        <div className="relative">
          <input
            type="text"
            value={saisie}
            onChange={(e) => setSaisie(e.target.value)}
            placeholder="Tapez le nom ou matricule..."
            autoFocus
            className="w-full px-4 py-3.5 pl-11 rounded-2xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 font-semibold text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          />
          <Search className="w-5 h-5 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          {saisie && (
            <button
              onClick={effacerTout}
              className="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-white"
            >
              <X className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Suggestions rapides en temps réel */}
      <div className="flex-1 overflow-y-auto space-y-2 my-2 pr-1 max-h-48">
        {suggestions.length > 0 ? (
          suggestions.map((e) => (
            <button
              key={e.id}
              onClick={() => onValiderMatricule(e.matricule)}
              className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:text-white text-left transition-all"
            >
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-full bg-slate-800 flex items-center justify-center text-xs font-bold text-blue-400 shrink-0">
                  {e.nom.charAt(0)}{e.prenom.charAt(0)}
                </div>
                <div>
                  <div className="text-xs font-bold text-white">{e.nom} {e.prenom}</div>
                  <div className="text-[11px] font-mono text-slate-400">{e.matricule} • {e.classe_nom || e.filiere}</div>
                </div>
              </div>
              <span className="text-[10px] font-bold px-2 py-1 bg-blue-500/20 text-blue-400 rounded-lg shrink-0">
                Pointer
              </span>
            </button>
          ))
        ) : (
          saisie.trim() !== '' && (
            <div className="text-center py-4 text-xs text-slate-500">
              Aucun étudiant trouvé pour "{saisie}"
            </div>
          )
        )}
      </div>

      {/* Pavé Numérique Tactile Rapide */}
      <div className="space-y-2">
        <div className="grid grid-cols-3 gap-2">
          {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((chiffre) => (
            <button
              key={chiffre}
              onClick={() => ajouterTouche(chiffre)}
              className="h-11 rounded-xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:scale-95 text-lg font-bold text-white shadow flex items-center justify-center transition-all"
            >
              {chiffre}
            </button>
          ))}
          <button
            onClick={effacerTout}
            className="h-11 rounded-xl bg-slate-900/80 border border-slate-800 active:bg-rose-600/30 text-xs font-bold text-rose-400 flex items-center justify-center uppercase tracking-wider"
          >
            Reset
          </button>
          <button
            onClick={() => ajouterTouche('0')}
            className="h-11 rounded-xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:scale-95 text-lg font-bold text-white shadow flex items-center justify-center transition-all"
          >
            0
          </button>
          <button
            onClick={effacer}
            className="h-11 rounded-xl bg-slate-900 border border-slate-800 active:bg-slate-800 text-slate-300 flex items-center justify-center"
          >
            <Delete className="w-5 h-5" />
          </button>
        </div>

        {/* Bouton Valider */}
        <button
          onClick={handleValider}
          disabled={!saisie.trim()}
          className="w-full py-3.5 rounded-2xl bg-blue-600 hover:bg-blue-500 disabled:opacity-40 text-white font-extrabold text-sm shadow-xl shadow-blue-600/30 flex items-center justify-center gap-2 active:scale-95 transition-all"
        >
          <Check className="w-5 h-5" />
          <span>Valider ({saisie || 'Saisie'})</span>
        </button>
      </div>
    </div>
  );
};
