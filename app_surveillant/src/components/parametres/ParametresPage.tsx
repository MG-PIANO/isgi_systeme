import React, { useState, useEffect } from 'react';
import { 
  Settings, 
  Clock, 
  Save, 
  RotateCcw, 
  CheckCircle2, 
  Volume2, 
  ShieldCheck, 
  RefreshCw,
  Database
} from 'lucide-react';
import { db, initialiserBaseSurveillant, synchroniserAvecSupabase } from '../../db/db';

export const ParametresPage: React.FC = () => {
  const [heureLimite, setHeureLimite] = useState('08:15');
  const [nomSurveillant, setNomSurveillant] = useState('M. KOUAME');
  const [sauvegardeOk, setSauvegardeOk] = useState(false);
  const [syncEnCours, setSyncEnCours] = useState(false);
  const [messageSync, setMessageSync] = useState<string | null>(null);

  useEffect(() => {
    const charger = async () => {
      const paramHeure = await db.parametres.get('heure_limite_arrivee');
      if (paramHeure) setHeureLimite(paramHeure.valeur);

      const paramNom = await db.parametres.get('nom_surveillant');
      if (paramNom) setNomSurveillant(paramNom.valeur);
    };
    charger();
  }, []);

  const handleEnregistrer = async (e: React.FormEvent) => {
    e.preventDefault();
    await db.parametres.put({ cle: 'heure_limite_arrivee', valeur: heureLimite });
    await db.parametres.put({ cle: 'nom_surveillant', valeur: nomSurveillant });
    setSauvegardeOk(true);
    setTimeout(() => setSauvegardeOk(false), 3000);
  };

  const handleForcerSync = async () => {
    setSyncEnCours(true);
    setMessageSync(null);
    try {
      await synchroniserAvecSupabase();
      setMessageSync('Synchronisation Cloud Supabase réussie avec succès !');
    } catch {
      setMessageSync('Erreur lors de la synchronisation.');
    } finally {
      setSyncEnCours(false);
      setTimeout(() => setMessageSync(null), 4000);
    }
  };

  return (
    <div className="p-8 max-w-4xl mx-auto space-y-6">
      {/* En-tête */}
      <div>
        <h2 className="text-2xl font-extrabold text-white font-heading">
          Paramètres & Configuration du Portail
        </h2>
        <p className="text-sm text-slate-400 mt-1">
          Ajustement des règles de ponctualité, synchronisation et préférences du poste de surveillance.
        </p>
      </div>

      <div className="space-y-6">
        {/* Formulaire des Horaires de Rentrée */}
        <form onSubmit={handleEnregistrer} className="p-6 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-6">
          <div className="flex items-center gap-2 pb-3 border-b border-slate-700/60">
            <Clock className="w-5 h-5 text-blue-400" />
            <h3 className="text-base font-bold text-white font-heading">
              Horaires & Seuils de Retard
            </h3>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider block mb-2">
                Heure limite d'arrivée (Heure de fermeture portail)
              </label>
              <input
                type="time"
                value={heureLimite}
                onChange={(e) => setHeureLimite(e.target.value)}
                className="w-full px-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white font-mono text-sm focus:outline-none focus:border-blue-500"
              />
              <p className="text-[11px] text-slate-400 mt-1">
                Tout étudiant scanné après cet horaire est automatiquement classé "En Retard".
              </p>
            </div>

            <div>
              <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider block mb-2">
                Nom du Surveillant en charge
              </label>
              <input
                type="text"
                value={nomSurveillant}
                onChange={(e) => setNomSurveillant(e.target.value)}
                className="w-full px-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-white text-sm focus:outline-none focus:border-blue-500"
              />
              <p className="text-[11px] text-slate-400 mt-1">
                Mentionné sur les journaux de scans et l'application.
              </p>
            </div>
          </div>

          {sauvegardeOk && (
            <div className="p-3.5 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-emerald-400" />
              <span>Paramètres enregistrés avec succès !</span>
            </div>
          )}

          <div className="flex justify-end pt-2">
            <button
              type="submit"
              className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-600/30 transition-all"
            >
              <Save className="w-4 h-4" />
              <span>Enregistrer les Modifications</span>
            </button>
          </div>
        </form>

        {/* Section Base de Données & Cloud */}
        <div className="p-6 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-4">
          <div className="flex items-center gap-2 pb-3 border-b border-slate-700/60">
            <Database className="w-5 h-5 text-indigo-400" />
            <h3 className="text-base font-bold text-white font-heading">
              Synchronisation & Données Centrales
            </h3>
          </div>

          <p className="text-xs text-slate-400 leading-relaxed">
            L'application fonctionne en mode <strong>Offline-First</strong> (hors ligne avec Dexie.js). Cliquez sur le bouton ci-dessous pour forcer la mise à jour immédiate avec la base de données Supabase Cloud.
          </p>

          {messageSync && (
            <div className="p-3.5 rounded-xl bg-blue-500/20 border border-blue-500/30 text-blue-300 text-xs flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-blue-400" />
              <span>{messageSync}</span>
            </div>
          )}

          <div className="flex items-center gap-3 pt-2">
            <button
              onClick={handleForcerSync}
              disabled={syncEnCours}
              className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white font-semibold text-xs border border-slate-600 transition-all disabled:opacity-50"
            >
              <RefreshCw className={`w-4 h-4 ${syncEnCours ? 'animate-spin' : ''}`} />
              <span>Forcer Synchronisation Supabase</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
