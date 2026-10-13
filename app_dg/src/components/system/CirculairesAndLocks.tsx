import { useState } from 'react';
import { 
  Lock, 
  Unlock, 
  Megaphone, 
  Plus, 
  CheckCircle2, 
  AlertTriangle, 
  Send,
  Trash2,
  Calendar,
  Layers
} from 'lucide-react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { Circulaire, SystemLock } from '../../types';

export function CirculairesAndLocks() {
  const circulaires = useLiveQuery(() => db.circulaires.toArray(), []) || [];
  const systemLocks = useLiveQuery(() => db.system_locks.toArray(), []) || [];

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [nouveauTitre, setNouveauTitre] = useState('');
  const [nouveauContenu, setNouveauContenu] = useState('');
  const [nouvellePriorite, setNouvellePriorite] = useState<'Normale' | 'Importante' | 'Urgente'>('Importante');
  const [nouvelleCible, setNouvelleCible] = useState<'Tous' | 'Étudiants & Tuteurs' | 'Personnel Enseignant' | 'Administration'>('Tous');

  // Basculer un verrou système
  const handleToggleLock = async (lock: SystemLock) => {
    if (!lock.id) return;
    const newStatus = !lock.est_verrouille;
    await db.system_locks.update(lock.id, {
      est_verrouille: newStatus,
      verrouille_par: 'Dr. Directeur Général',
      date_modification: new Date().toISOString()
    });

    await db.audit_logs.add({
      date_action: new Date().toISOString(),
      acteur_nom: 'Directeur Général',
      acteur_role: 'DG',
      action: newStatus ? 'Activation de verrou système' : 'Désactivation de verrou système',
      module: 'Système',
      details: `${lock.nom} : Basculé vers [${newStatus ? 'VERROUILLÉ' : 'DÉVERROUILLÉ'}]`,
      niveau: newStatus ? 'ATTENTION' : 'INFO'
    });
  };

  // Publier une circulaire
  const handlePublierCirculaire = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!nouveauTitre || !nouveauContenu) return;

    await db.circulaires.add({
      titre: nouveauTitre,
      contenu: nouveauContenu,
      priorite: nouvellePriorite,
      cible: nouvelleCible,
      date_publication: new Date().toISOString(),
      publie_par: 'Dr. Directeur Général',
      actif: true
    });

    await db.audit_logs.add({
      date_action: new Date().toISOString(),
      acteur_nom: 'Directeur Général',
      acteur_role: 'DG',
      action: 'Publication de circulaire',
      module: 'Système',
      details: `Circulaire officielle publiée : "${nouveauTitre}" à destination de: ${nouvelleCible}`,
      niveau: 'INFO'
    });

    setNouveauTitre('');
    setNouveauContenu('');
    setIsModalOpen(false);
  };

  // Basculer l'état actif/inactif d'une circulaire
  const handleToggleCirculaire = async (c: Circulaire) => {
    if (!c.id) return;
    await db.circulaires.update(c.id, { actif: !c.actif });
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header */}
      <div>
        <h2 className="text-2xl font-black text-on-surface">Circulaires & Verrous Périodiques</h2>
        <p className="text-xs text-on-surface-variant font-medium mt-1">
          Diffusion des consignes officielles du DG et verrouillage des processus sensibles de l'établissement
        </p>
      </div>

      {/* SECTION 1 : LES VERROUS SYSTÈME */}
      <div className="p-6 rounded-3xl bg-surface-container-lowest border border-outline-variant/60 shadow-xs space-y-4">
        <div className="flex items-center gap-2 text-sky-700 dark:text-sky-400">
          <Lock className="w-5 h-5" />
          <h3 className="font-black text-base text-on-surface">Verrous Périodiques & Clôtures Officielles</h3>
        </div>
        <p className="text-xs text-on-surface-variant">
          Ces interrupteurs maîtres permettent au DG de bloquer ou d'ouvrir les actions critiques sur toutes les applications de l'institut.
        </p>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
          {systemLocks.map((lock) => (
            <div
              key={lock.id}
              className={`p-5 rounded-3xl border transition-all space-y-3 ${
                lock.est_verrouille
                  ? 'bg-rose-500/10 border-rose-500/30'
                  : 'bg-emerald-500/10 border-emerald-500/30'
              }`}
            >
              <div className="flex items-center justify-between">
                <span className={`px-2.5 py-1 text-[10px] font-black uppercase rounded-full ${
                  lock.est_verrouille ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'
                }`}>
                  {lock.est_verrouille ? 'Verrouillé' : 'Ouvert / Autorisé'}
                </span>
                <div className={`p-2 rounded-2xl ${
                  lock.est_verrouille ? 'bg-rose-600 text-white' : 'bg-emerald-600 text-white'
                }`}>
                  {lock.est_verrouille ? <Lock className="w-4 h-4" /> : <Unlock className="w-4 h-4" />}
                </div>
              </div>

              <div>
                <h4 className="font-black text-sm text-on-surface">{lock.nom}</h4>
                <p className="text-xs text-on-surface-variant mt-1 leading-relaxed">
                  {lock.description}
                </p>
              </div>

              <div className="pt-2 border-t border-outline-variant/40 flex items-center justify-between">
                <span className="text-[10px] text-on-surface-variant">
                  {lock.date_modification ? new Date(lock.date_modification).toLocaleDateString('fr-FR') : 'Actif'}
                </span>
                <button
                  onClick={() => handleToggleLock(lock)}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all shadow-xs ${
                    lock.est_verrouille
                      ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                      : 'bg-rose-600 hover:bg-rose-700 text-white'
                  }`}
                >
                  {lock.est_verrouille ? 'Déverrouiller' : 'Verrouiller'}
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* SECTION 2 : LES CIRCULAIRES DU DG */}
      <div className="p-6 rounded-3xl bg-surface-container-lowest border border-outline-variant/60 shadow-xs space-y-4">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h3 className="font-black text-base text-on-surface">Circulaires & Notes de Service Publiées</h3>
            <p className="text-xs text-on-surface-variant">
              Les messages publiés ici s'affichent instantanément aux étudiants, parents et professeurs
            </p>
          </div>

          <button
            onClick={() => setIsModalOpen(true)}
            className="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs shadow-xs transition-colors"
          >
            <Plus className="w-4 h-4" />
            Nouvelle Circulaire
          </button>
        </div>

        <div className="space-y-3">
          {circulaires.length === 0 ? (
            <div className="py-8 text-center text-xs text-on-surface-variant">
              Aucune circulaire publiée pour le moment.
            </div>
          ) : (
            circulaires.slice().reverse().map((circ) => (
              <div
                key={circ.id}
                className={`p-5 rounded-3xl border transition-all flex flex-col md:flex-row items-start justify-between gap-4 ${
                  circ.actif
                    ? 'bg-surface-container border-outline-variant/60'
                    : 'bg-surface-container/40 border-dashed border-outline-variant opacity-60'
                }`}
              >
                <div className="space-y-2 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={`px-2.5 py-0.5 text-[10px] font-black rounded-full ${
                      circ.priorite === 'Urgente'
                        ? 'bg-rose-100 text-rose-800'
                        : circ.priorite === 'Importante'
                        ? 'bg-amber-100 text-amber-800'
                        : 'bg-blue-100 text-blue-800'
                    }`}>
                      {circ.priorite}
                    </span>
                    <span className="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-surface-container-highest text-on-surface-variant">
                      Cible: {circ.cible}
                    </span>
                    <span className="text-[11px] text-on-surface-variant">
                      • {new Date(circ.date_publication).toLocaleDateString('fr-FR')}
                    </span>
                  </div>

                  <h4 className="font-black text-sm text-on-surface">{circ.titre}</h4>
                  <p className="text-xs text-on-surface-variant leading-relaxed">
                    {circ.contenu}
                  </p>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={() => handleToggleCirculaire(circ)}
                    className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-colors ${
                      circ.actif
                        ? 'bg-slate-200 text-slate-800 hover:bg-slate-300'
                        : 'bg-emerald-600 text-white hover:bg-emerald-700'
                    }`}
                  >
                    {circ.actif ? 'Désactiver' : 'Réactiver'}
                  </button>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Modal Création Circulaire */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-surface-container-lowest max-w-lg w-full rounded-3xl p-6 border border-outline-variant shadow-2xl space-y-4">
            <h3 className="font-black text-lg text-on-surface">Publier une Circulaire Officielle du DG</h3>
            <p className="text-xs text-on-surface-variant">
              Ce message sera immédiatement répercuté auprès des publics concernés.
            </p>

            <form onSubmit={handlePublierCirculaire} className="space-y-4">
              <div className="space-y-1">
                <label className="text-xs font-bold text-on-surface">Titre de la Circulaire :</label>
                <input
                  required
                  type="text"
                  value={nouveauTitre}
                  onChange={(e) => setNouveauTitre(e.target.value)}
                  placeholder="Ex: Modalités de composition du 1er Semestre..."
                  className="w-full px-3.5 py-2 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="text-xs font-bold text-on-surface">Niveau d'Urgence :</label>
                  <select
                    value={nouvellePriorite}
                    onChange={(e) => setNouvellePriorite(e.target.value as any)}
                    className="w-full px-3.5 py-2 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface font-semibold"
                  >
                    <option value="Normale">Normale</option>
                    <option value="Importante">Importante</option>
                    <option value="Urgente">Urgente</option>
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="text-xs font-bold text-on-surface">Public Cible :</label>
                  <select
                    value={nouvelleCible}
                    onChange={(e) => setNouvelleCible(e.target.value as any)}
                    className="w-full px-3.5 py-2 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface font-semibold"
                  >
                    <option value="Tous">Tous</option>
                    <option value="Étudiants & Tuteurs">Étudiants & Tuteurs</option>
                    <option value="Personnel Enseignant">Personnel Enseignant</option>
                    <option value="Administration">Administration</option>
                  </select>
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-xs font-bold text-on-surface">Contenu du Communiqué :</label>
                <textarea
                  required
                  rows={4}
                  value={nouveauContenu}
                  onChange={(e) => setNouveauContenu(e.target.value)}
                  placeholder="Rédigez ici le texte intégral de la note de service..."
                  className="w-full p-3.5 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface leading-relaxed"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-on-surface hover:bg-surface-container"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex items-center gap-2 px-5 py-2 rounded-xl text-xs font-black bg-sky-600 hover:bg-sky-700 text-white shadow-xs"
                >
                  <Send className="w-3.5 h-3.5" />
                  Publier Officiellement
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
