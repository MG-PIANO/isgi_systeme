import React, { useState, useEffect } from 'react';
import {
  Settings,
  School,
  Save,
  CheckCircle2,
  Calculator,
  FileText,
  Eye,
  Sparkles
} from 'lucide-react';
import { logAction } from '../../db/db';

export const ParametresPage: React.FC = () => {
  const [settings, setSettings] = useState<{ [key: string]: any }>({
    nomEcole: "Institut Supérieur de Gestion et d'Ingénierie",
    sigle: "ISGI",
    ville: "Brazzaville",
    enTeteMessageHaut: "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE",
    enTeteDirection: "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)",
    enTeteSousTitre: "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État",
    dacNom: "Prof. M. DIALLO",
    anneeAcademique: "2026-2027",
    matriculePrefix: "ISGI-2627-",
    pourcentageCC: 40,
    pourcentageExamen: 60,
    titreSignataire1: "Le Secrétaire Général",
    nomSignataire1: "Dr. A. KOUAME",
    titreSignataire2: "Le Directeur Académique (DAC)",
    nomSignataire2: "Prof. M. DIALLO",
    mentionBasDePage: "Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI"
  });

  const [toastMessage, setToastMessage] = useState<string | null>(null);

  useEffect(() => {
    try {
      const saved = localStorage.getItem('isgi_settings');
      if (saved) {
        const savedSettings = JSON.parse(saved);
        if (savedSettings.anneeAcademique === '2025-2026') savedSettings.anneeAcademique = '2026-2027';
        if (!savedSettings.matriculePrefix) savedSettings.matriculePrefix = 'ISGI-2627-';
        localStorage.setItem('isgi_settings', JSON.stringify(savedSettings));
        setSettings(prev => ({ ...prev, ...savedSettings }));
      }
    } catch (error) {
      console.error('Impossible de charger les paramètres DAC:', error);
    }
  }, []);

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    if (!/^[A-Z0-9]+(?:-[A-Z0-9]+)*-$/.test(String(settings.matriculePrefix || '').trim())) {
      setToastMessage("Le préfixe matricule doit se terminer par un tiret, par exemple ISGI-2627-.");
      setTimeout(() => setToastMessage(null), 3500);
      return;
    }
    localStorage.setItem('isgi_settings', JSON.stringify(settings));
    logAction('Mise à jour Paramètres', 'Paramètres', 'Modification des paramètres académiques et en-têtes PDF');
    setToastMessage("Paramètres et en-têtes PDF enregistrés avec succès.");
    setTimeout(() => setToastMessage(null), 3500);
  };

  return (
    <div className="space-y-6 max-w-4xl">
      <div>
        <h2 className="text-2xl font-bold text-on-surface">Paramètres Académiques & Documents</h2>
        <p className="text-on-surface-variant text-sm mt-1">
          Configuration de l'en-tête officiel des PDF, règles de délibération et signatures
        </p>
      </div>

      <form onSubmit={handleSave} className="space-y-6">
        {/* Identité de l'Établissement */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-4">
          <div className="flex items-center gap-3 pb-3 border-b border-outline-variant">
            <div className="p-2 bg-primary/10 text-primary rounded-xl">
              <School className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Identité de l'Établissement</h3>
              <p className="text-xs text-on-surface-variant">Nom, sigle et ville officielle affichés sur tous les documents</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div>
              <label className="font-medium text-on-surface block mb-1">
                Nom officiel de l'établissement
              </label>
              <input
                type="text"
                value={settings.nomEcole}
                onChange={(e) => setSettings({ ...settings, nomEcole: e.target.value })}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-medium"
              />
            </div>

            <div>
              <label className="font-medium text-on-surface block mb-1">
                Sigle de l'établissement
              </label>
              <input
                type="text"
                value={settings.sigle}
                onChange={(e) => setSettings({ ...settings, sigle: e.target.value })}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-medium"
              />
            </div>

            <div>
              <label className="font-medium text-on-surface block mb-1">
                Ville officielle (Délivrance documents)
              </label>
              <input
                type="text"
                placeholder="Ex: Brazzaville"
                value={settings.ville ?? "Brazzaville"}
                onChange={(e) => setSettings({ ...settings, ville: e.target.value })}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-medium"
              />
            </div>
          </div>
        </div>

        {/* Personnalisation de l'En-tête Officiel des Documents PDF */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-5">
          <div className="flex items-center justify-between pb-3 border-b border-outline-variant">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-primary/10 text-primary rounded-xl">
                <FileText className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-bold text-sm text-on-surface">En-tête Officiel des Documents PDF</h3>
                <p className="text-xs text-on-surface-variant">
                  Textes dynamiques imprimés en haut des Calendriers, Emplois du Temps, Bulletins et Fiches
                </p>
              </div>
            </div>
            <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
              Dynamique & Modifiable
            </span>
          </div>

          <div className="space-y-4 text-xs">
            <div>
              <label className="font-semibold text-on-surface block mb-1">
                Ligne 1 : Devise Officielle / Ministère / Message Supérieur (Tout en haut)
              </label>
              <input
                type="text"
                placeholder="Ex: RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE"
                value={settings.enTeteMessageHaut ?? "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE"}
                onChange={(e) => setSettings({ ...settings, enTeteMessageHaut: e.target.value })}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-medium"
              />
              <p className="text-[11px] text-on-surface-variant mt-1">
                Texte officiel affiché tout en haut à la tête du document PDF, au-dessus du nom de l'établissement.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="font-semibold text-on-surface block mb-1">
                  Ligne 2 : Direction / Service Pédagogique Émetteur
                </label>
                <input
                  type="text"
                  placeholder="Ex: DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"
                  value={settings.enTeteDirection ?? "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"}
                  onChange={(e) => setSettings({ ...settings, enTeteDirection: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-semibold text-primary"
                />
                <p className="text-[11px] text-on-surface-variant mt-1">
                  Nom de la direction affiché sous le nom de l'école.
                </p>
              </div>

              <div>
                <label className="font-semibold text-on-surface block mb-1">
                  Ligne 3 : Slogan / Statut & Mention d'Agrément
                </label>
                <input
                  type="text"
                  placeholder="Ex: Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État"
                  value={settings.enTeteSousTitre ?? "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État"}
                  onChange={(e) => setSettings({ ...settings, enTeteSousTitre: e.target.value })}
                  className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary"
                />
                <p className="text-[11px] text-on-surface-variant mt-1">
                  Mention officielle de reconnaissance ou filières de formation.
                </p>
              </div>
            </div>
          </div>

          {/* Aperçu en direct de l'en-tête PDF */}
          <div className="mt-4 p-4 rounded-xl bg-surface-container-lowest border border-outline-variant shadow-inner">
            <div className="flex items-center gap-2 mb-3 pb-2 border-b border-outline-variant/60 text-xs font-bold text-on-surface-variant uppercase tracking-wider">
              <Eye className="w-4 h-4 text-primary" />
              <span>Aperçu en direct du haut de page PDF</span>
            </div>
            <div className="bg-white text-slate-800 p-4 rounded-lg border border-slate-200 shadow-sm flex items-start gap-4">
              <div className="w-14 h-14 rounded-lg bg-slate-100 flex items-center justify-center border border-slate-200 shrink-0 overflow-hidden">
                <img src="./logo.jpg" alt="Logo" className="w-full h-full object-contain" />
              </div>
              <div className="flex-1 min-w-0 space-y-0.5">
                <p className="text-[9.5px] font-bold text-slate-500 uppercase tracking-wide truncate">
                  {settings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR"}
                </p>
                <h4 className="text-sm font-extrabold text-[#142d5f] uppercase tracking-wide truncate">
                  {settings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE"} ({settings.sigle || "ISGI"})
                </h4>
                <p className="text-[11px] font-bold text-[#1e40af] truncate">
                  {settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)"}
                </p>
                <p className="text-[10px] text-slate-500 truncate">
                  {settings.enTeteSousTitre || "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État"}
                </p>
                <div className="pt-2 border-b-2 border-[#1e40af] mt-1"></div>
              </div>
            </div>
          </div>
        </div>

        {/* Paramètres DAC */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-4">
          <div className="flex items-center gap-3 pb-3 border-b border-outline-variant">
            <div className="p-2 bg-primary-container text-on-primary-container rounded-xl">
              <Calculator className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Règles Académiques & Délibération</h3>
              <p className="text-xs text-on-surface-variant">Pondération Contrôle Continu / Examen</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div>
              <label className="font-medium text-on-surface block mb-1">Année Académique Active *</label>
              <input
                type="text"
                value={settings.anneeAcademique}
                onChange={(e) => setSettings({ ...settings, anneeAcademique: e.target.value })}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary"
              />
            </div>

            <div>
              <label className="font-medium text-on-surface block mb-1">Préfixe des matricules *</label>
              <input
                type="text"
                value={settings.matriculePrefix}
                onChange={(e) => setSettings({ ...settings, matriculePrefix: e.target.value.toUpperCase() })}
                placeholder="ISGI-2627-"
                pattern="[A-Z0-9]+(-[A-Z0-9]+)*-"
                required
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary font-mono"
              />
              <p className="mt-1 text-on-surface-variant">Exemple généré : {settings.matriculePrefix || 'ISGI-2627-'}0001</p>
            </div>

            <div>
              <label className="font-medium text-on-surface block mb-1">Pondération Contrôle Continu (CC) %</label>
              <input
                type="number"
                min="0"
                max="100"
                value={settings.pourcentageCC}
                onChange={(e) => {
                  const val = Number(e.target.value);
                  setSettings({ ...settings, pourcentageCC: val, pourcentageExamen: 100 - val });
                }}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface outline-none focus:ring-2 focus:ring-primary"
              />
            </div>

            <div>
              <label className="font-medium text-on-surface-variant block mb-1">Pondération Examen %</label>
              <input
                type="number"
                disabled
                value={settings.pourcentageExamen}
                className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-highest/60 text-on-surface-variant"
              />
            </div>
          </div>
        </div>

        {/* Configuration des Signatures Officielles sur les Documents */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm space-y-4">
          <div className="flex items-center gap-3 pb-3 border-b border-outline-variant">
            <div className="p-2 bg-primary/10 text-primary rounded-xl">
              <CheckCircle2 className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Signatures & Mentions Officielles (Calendrier, Emploi du Temps, Bulletins)</h3>
              <p className="text-xs text-on-surface-variant">Personnalisez les signataires et mentions de validation figurant sur les impressions</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            {/* Signataire 1 */}
            <div className="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant space-y-3">
              <span className="font-bold text-primary block">Signataire 1 (Ex: Secrétaire Général / DG)</span>
              <div>
                <label className="font-medium text-on-surface block mb-1">Titre Officiel</label>
                <input
                  type="text"
                  placeholder="Ex: Le Secrétaire Général"
                  value={settings.titreSignataire1 ?? "Le Secrétaire Général"}
                  onChange={(e) => setSettings({ ...settings, titreSignataire1: e.target.value })}
                  className="w-full p-2 rounded-lg border border-outline-variant bg-surface-container text-on-surface"
                />
              </div>
              <div>
                <label className="font-medium text-on-surface block mb-1">Nom Complet</label>
                <input
                  type="text"
                  placeholder="Ex: Dr. A. KOUAME"
                  value={settings.nomSignataire1 ?? "Dr. A. KOUAME"}
                  onChange={(e) => setSettings({ ...settings, nomSignataire1: e.target.value })}
                  className="w-full p-2 rounded-lg border border-outline-variant bg-surface-container text-on-surface"
                />
              </div>
            </div>

            {/* Signataire 2 */}
            <div className="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant space-y-3">
              <span className="font-bold text-primary block">Signataire 2 (Direction Académique / DAC)</span>
              <div>
                <label className="font-medium text-on-surface block mb-1">Titre Officiel</label>
                <input
                  type="text"
                  placeholder="Ex: Le Directeur Académique (DAC)"
                  value={settings.titreSignataire2 ?? "Le Directeur Académique (DAC)"}
                  onChange={(e) => setSettings({ ...settings, titreSignataire2: e.target.value })}
                  className="w-full p-2 rounded-lg border border-outline-variant bg-surface-container text-on-surface"
                />
              </div>
              <div>
                <label className="font-medium text-on-surface block mb-1">Nom Complet</label>
                <input
                  type="text"
                  placeholder="Ex: Prof. M. DIALLO"
                  value={settings.nomSignataire2 ?? settings.dacNom ?? "Prof. M. DIALLO"}
                  onChange={(e) => setSettings({ ...settings, nomSignataire2: e.target.value, dacNom: e.target.value })}
                  className="w-full p-2 rounded-lg border border-outline-variant bg-surface-container text-on-surface"
                />
              </div>
            </div>
          </div>

          <div className="text-xs">
            <label className="font-medium text-on-surface block mb-1">Mention légale de bas de page</label>
            <input
              type="text"
              placeholder="Ex: Document officiel certifié par la Direction des Affaires Académiques - ISGI"
              value={settings.mentionBasDePage ?? "Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI"}
              onChange={(e) => setSettings({ ...settings, mentionBasDePage: e.target.value })}
              className="w-full p-2.5 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface"
            />
          </div>
        </div>


        <div className="flex justify-end">
          <button
            type="submit"
            className="flex items-center gap-2 bg-primary text-on-primary px-6 py-2.5 rounded-full font-medium text-sm hover:bg-primary/90 transition-colors shadow-sm"
          >
            <Save className="w-4 h-4" />
            <span>Enregistrer les Paramètres</span>
          </button>
        </div>
      </form>

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
