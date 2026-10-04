import React, { useState, useEffect, useMemo } from 'react';
import { 
  IdCard, 
  Search, 
  Printer, 
  Download, 
  Filter, 
  QrCode, 
  GraduationCap, 
  Building2,
  CheckCircle2
} from 'lucide-react';
import { db } from '../../db/db';
import type { Etudiant } from '../../types';

export const BadgesPage: React.FC = () => {
  const [etudiants, setEtudiants] = useState<Etudiant[]>([]);
  const [recherche, setRecherche] = useState('');
  const [filtreClasse, setFiltreClasse] = useState('tous');
  const [etudiantSelectionne, setEtudiantSelectionne] = useState<Etudiant | null>(null);

  useEffect(() => {
    const charger = async () => {
      const data = await db.etudiants.where('statut').equals('actif').toArray();
      setEtudiants(data);
      if (data.length > 0) setEtudiantSelectionne(data[0]);
    };
    charger();
  }, []);

  const classesList = useMemo(() => {
    const set = new Set(etudiants.map(e => e.classe_nom || e.filiere).filter(Boolean));
    return Array.from(set);
  }, [etudiants]);

  const etudiantsFiltres = useMemo(() => {
    return etudiants.filter((e) => {
      const matchRech = 
        e.nom.toLowerCase().includes(recherche.toLowerCase()) ||
        e.prenom.toLowerCase().includes(recherche.toLowerCase()) ||
        e.matricule.toLowerCase().includes(recherche.toLowerCase());

      const classeEtud = e.classe_nom || e.filiere;
      const matchClasse = filtreClasse === 'tous' || classeEtud === filtreClasse;

      return matchRech && matchClasse;
    });
  }, [etudiants, recherche, filtreClasse]);

  // URL QR Code SVG via API publique rapide et haute résolution
  const getQrUrl = (qrData: string) => {
    return `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(qrData)}`;
  };

  const handleImprimer = () => {
    window.print();
  };

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-extrabold text-white font-heading">
            Générateur & Impression des Badges QR Code
          </h2>
          <p className="text-sm text-slate-400 mt-1">
            Générez et imprimez les cartes scolaires sécurisées pour le contrôle d'accès au portail.
          </p>
        </div>

        <button
          onClick={handleImprimer}
          className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-600/20 transition-all"
        >
          <Printer className="w-4 h-4" />
          <span>Imprimer la Carte</span>
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne GAUCHE : Sélection de l'étudiant */}
        <div className="lg:col-span-5 space-y-4">
          <div className="p-5 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-lg space-y-3">
            <div className="relative">
              <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="Rechercher par nom ou matricule..."
                className="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500 placeholder-slate-500"
              />
            </div>

            <select
              value={filtreClasse}
              onChange={(e) => setFiltreClasse(e.target.value)}
              className="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:outline-none focus:border-blue-500"
            >
              <option value="tous">Toutes les classes</option>
              {classesList.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>

          {/* Liste déroulante des étudiants */}
          <div className="rounded-2xl bg-slate-800/60 border border-slate-700/60 p-2 max-h-[500px] overflow-y-auto space-y-1">
            {etudiantsFiltres.map((e) => {
              const isSelected = etudiantSelectionne?.id === e.id;
              return (
                <button
                  key={e.id}
                  onClick={() => setEtudiantSelectionne(e)}
                  className={`w-full flex items-center justify-between p-3 rounded-xl transition-all text-left ${
                    isSelected 
                      ? 'bg-blue-600 text-white shadow-md' 
                      : 'hover:bg-slate-700/40 text-slate-300'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    {e.photo_url ? (
                      <img src={e.photo_url} alt={e.nom} className="w-10 h-10 rounded-full object-cover border border-white/20" />
                    ) : (
                      <div className="w-10 h-10 rounded-full bg-slate-700 flex items-center justify-center font-bold text-xs text-white">
                        {e.nom.charAt(0)}{e.prenom.charAt(0)}
                      </div>
                    )}
                    <div>
                      <div className="font-semibold text-sm leading-tight">{e.nom} {e.prenom}</div>
                      <div className={`text-xs font-mono ${isSelected ? 'text-blue-200' : 'text-slate-400'}`}>
                        {e.matricule}
                      </div>
                    </div>
                  </div>
                  <span className={`text-[11px] font-medium ${isSelected ? 'text-blue-100' : 'text-slate-400'}`}>
                    {e.classe_nom || e.filiere}
                  </span>
                </button>
              );
            })}
          </div>
        </div>

        {/* Colonne DROITE : Prévisualisation Carte Scolaire Pro */}
        <div className="lg:col-span-7 flex flex-col items-center justify-center">
          {etudiantSelectionne ? (
            <div className="space-y-6">
              <div className="text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">
                Aperçu Badge Officiel (Format Carte PVC 85 × 54 mm)
              </div>

              {/* CARTE SCOLAIRE RECTO */}
              <div 
                id="badge-to-print"
                className="w-[380px] h-[230px] rounded-2xl bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 p-5 border-2 border-blue-500/40 shadow-2xl relative overflow-hidden flex flex-col justify-between text-white selection:bg-none"
              >
                {/* Filigrane discret */}
                <div className="absolute right-0 top-0 bottom-0 w-1/2 bg-gradient-to-l from-blue-500/10 to-transparent pointer-events-none"></div>

                {/* En-tête Badge */}
                <div className="flex items-center justify-between border-b border-blue-500/30 pb-2.5">
                  <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center font-bold text-xs text-white">
                      <GraduationCap className="w-4 h-4" />
                    </div>
                    <div>
                      <div className="text-xs font-extrabold tracking-wider text-white">ISGI SYSTEM</div>
                      <div className="text-[9px] text-blue-300 font-medium">Institut Supérieur de Guinée</div>
                    </div>
                  </div>
                  <span className="px-2 py-0.5 rounded-full text-[9px] font-bold bg-blue-500/30 text-blue-200 border border-blue-400/40 uppercase">
                    Étudiant
                  </span>
                </div>

                {/* Corps Badge : Photo + Informations + QR Code */}
                <div className="flex items-center justify-between gap-3 my-auto">
                  {/* Photo */}
                  <div className="shrink-0">
                    {etudiantSelectionne.photo_url ? (
                      <img
                        src={etudiantSelectionne.photo_url}
                        alt={etudiantSelectionne.nom}
                        className="w-20 h-24 rounded-xl object-cover border-2 border-blue-400/50 shadow-md"
                      />
                    ) : (
                      <div className="w-20 h-24 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-lg text-slate-400">
                        {etudiantSelectionne.nom.charAt(0)}{etudiantSelectionne.prenom.charAt(0)}
                      </div>
                    )}
                  </div>

                  {/* Données Étudiant */}
                  <div className="flex-1 space-y-1">
                    <div className="text-sm font-bold text-white uppercase leading-tight font-heading">
                      {etudiantSelectionne.nom}
                    </div>
                    <div className="text-xs font-semibold text-blue-300 leading-tight">
                      {etudiantSelectionne.prenom}
                    </div>
                    <div className="text-[10px] text-slate-300 font-medium">
                      {etudiantSelectionne.classe_nom || etudiantSelectionne.filiere}
                    </div>
                    <div className="text-[10px] text-blue-400 font-mono font-bold pt-1">
                      {etudiantSelectionne.matricule}
                    </div>
                  </div>

                  {/* QR Code haute définition */}
                  <div className="shrink-0 p-1.5 bg-white rounded-xl shadow-md border border-slate-200">
                    <img
                      src={getQrUrl(etudiantSelectionne.qr_code_data || `ETUDIANT:${etudiantSelectionne.matricule}|NOM:${etudiantSelectionne.nom}|PRENOM:${etudiantSelectionne.prenom}|SITE:1`)}
                      alt="QR Code Badge"
                      className="w-20 h-20"
                    />
                  </div>
                </div>

                {/* Pied de Badge */}
                <div className="flex items-center justify-between border-t border-blue-500/20 pt-1.5 text-[9px] text-slate-400">
                  <span>Année 2025 - 2026</span>
                  <span className="font-mono text-blue-300">Contrôle d'accès portail</span>
                </div>
              </div>

              {/* Instructions pour impression */}
              <div className="p-4 rounded-xl bg-slate-800/40 border border-slate-700/50 text-xs text-slate-400 text-center max-w-sm mx-auto space-y-1">
                <p className="font-semibold text-slate-300">Impression Plastifiée ou Papier PVC</p>
                <p>Cliquez sur "Imprimer la Carte" pour envoyer directement le badge vers votre imprimante laser ou imprimante à badges.</p>
              </div>
            </div>
          ) : (
            <div className="text-slate-500 text-sm">Sélectionnez un étudiant pour afficher son badge.</div>
          )}
        </div>
      </div>
    </div>
  );
};
