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

      {/* Écran d'affichage de la saisie */}
      <div className="p-4 rounded-2xl bg-slate-900 border border-slate-800 text-center space-y-2">
        <div className="text-xs text-slate-400 font-medium">Matricule Étudiant</div>
        <div className="h-12 flex items-center justify-center font-mono text-2xl font-black text-blue-400 tracking-wider">
          {saisie || <span className="text-slate-600">ISGI-...</span>}
        </div>
      </div>

      {/* Suggestions rapides en temps réel */}
      {suggestions.length > 0 && (
        <div className="space-y-1.5">
          <div className="text-[11px] font-bold uppercase text-slate-400 px-1">Correspondances trouvées :</div>
          {suggestions.map((e) => (
            <button
              key={e.id}
              onClick={() => onValiderMatricule(e.matricule)}
              className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:text-white text-left transition-all"
            >
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-slate-800 flex items-center justify-center text-xs font-bold text-blue-400">
                  {e.nom.charAt(0)}
                </div>
                <div>
                  <div className="text-xs font-bold text-white">{e.nom} {e.prenom}</div>
                  <div className="text-[11px] font-mono text-slate-400">{e.matricule}</div>
                </div>
              </div>
              <span className="text-[10px] font-semibold text-slate-400">{e.classe_nom || e.filiere}</span>
            </button>
          ))}
        </div>
      )}

      {/* Pavé Numérique Tactile */}
      <div className="space-y-2">
        <div className="grid grid-cols-3 gap-2.5">
          {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((chiffre) => (
            <button
              key={chiffre}
              onClick={() => ajouterTouche(chiffre)}
              className="h-14 rounded-2xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:scale-95 text-xl font-bold text-white shadow-md flex items-center justify-center transition-all"
            >
              {chiffre}
            </button>
          ))}
          <button
            onClick={effacerTout}
            className="h-14 rounded-2xl bg-slate-900/80 border border-slate-800 active:bg-rose-600/30 text-xs font-bold text-rose-400 flex items-center justify-center uppercase tracking-wider"
          >
            Reset
          </button>
          <button
            onClick={() => ajouterTouche('0')}
            className="h-14 rounded-2xl bg-slate-900 border border-slate-800 active:bg-blue-600 active:scale-95 text-xl font-bold text-white shadow-md flex items-center justify-center transition-all"
          >
            0
          </button>
          <button
            onClick={effacer}
            className="h-14 rounded-2xl bg-slate-900 border border-slate-800 active:bg-slate-800 text-slate-300 flex items-center justify-center"
          >
            <Delete className="w-6 h-6" />
          </button>
        </div>

        {/* Bouton Valider */}
        <button
          onClick={handleValider}
          disabled={!saisie.trim()}
          className="w-full py-4 rounded-2xl bg-blue-600 hover:bg-blue-500 disabled:opacity-40 text-white font-extrabold text-base shadow-xl shadow-blue-600/30 flex items-center justify-center gap-2 active:scale-95 transition-all"
        >
          <Check className="w-5 h-5" />
          <span>Valider le Matricule</span>
        </button>
      </div>
    </div>
  );
};
