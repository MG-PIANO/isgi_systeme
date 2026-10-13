import React, { useState, useEffect, useRef } from 'react';
import { 
  QrCode, 
  Search, 
  Camera, 
  CameraOff, 
  CheckCircle2, 
  AlertTriangle, 
  Volume2, 
  VolumeX, 
  User, 
  Clock, 
  Sparkles,
  RefreshCw,
  Zap,
  Building2,
  LogOut,
  ChevronRight,
  X
} from 'lucide-react';
import { 
  db, 
  sauvegarderPointage, 
  getPointageEtudiantJour, 
  rechercherEtudiants,
  synchroniserAvecSupabase 
} from '../../db/db';
import { extraireMatriculeDepuisQR, traiterScanEtudiant } from '../../services/scanEngine';
import type { Etudiant, PointageAcces, ScanProcessResult } from '../../types';
import { formaterHeure } from '../../lib/utils';

interface ScannerPageProps {
  surveillantId: string;
  surveillantNom: string;
}

export const ScannerPage: React.FC<ScannerPageProps> = ({ surveillantId, surveillantNom }) => {
  const [saisieTexte, setSaisieTexte] = useState('');
  const [etudiantsList, setEtudiantsList] = useState<Etudiant[]>([]);
  const [suggestions, setSuggestions] = useState<Etudiant[]>([]);
  const [suggestionsOuvertes, setSuggestionsOuvertes] = useState(false);
  const [etudiantsMultiples, setEtudiantsMultiples] = useState<Etudiant[] | null>(null);
  const [resultatDernierScan, setResultatDernierScan] = useState<ScanProcessResult | null>(null);
  const [cameraActive, setCameraActive] = useState(false);
  const [sonActif, setSonActif] = useState(true);
  const [messageErreur, setMessageErreur] = useState<string | null>(null);
  const [heureLimite, setHeureLimite] = useState('08:15');
  const [chargementSync, setChargementSync] = useState(false);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);

  // Charger les étudiants et paramètres
  const rechargerEtudiants = async (forcerSync: boolean = false) => {
    try {
      if (forcerSync) setChargementSync(true);

      // 1. Lire d'abord les étudiants locaux
      let etuds = await db.etudiants.toArray();

      // 2. Si la base locale est vide ou si l'utilisateur demande une sync forcée, contacter Supabase
      if (etuds.length === 0 || forcerSync) {
        await synchroniserAvecSupabase();
        etuds = await db.etudiants.toArray();
      }

      setEtudiantsList(etuds);

      const paramHeure = await db.parametres.get('heure_limite_arrivee');
      if (paramHeure) setHeureLimite(paramHeure.valeur);
    } catch (err) {
      console.error('Erreur chargement étudiants:', err);
    } finally {
      setChargementSync(false);
    }
  };

  useEffect(() => {
    rechargerEtudiants(false);
    inputRef.current?.focus();
  }, []);

  // Fermer les suggestions si on clique à l'extérieur
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setSuggestionsOuvertes(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Recherche en temps réel pour auto-complétion au fil de la frappe
  useEffect(() => {
    const texte = saisieTexte.trim();
    if (texte.length < 2) {
      setSuggestions([]);
      setSuggestionsOuvertes(false);
      return;
    }

    const t = texte.toLowerCase();
    const resultats = etudiantsList.filter((e) => {
      const mat = (e.matricule || '').toLowerCase();
      const nom = (e.nom || '').toLowerCase();
      const prenom = (e.prenom || '').toLowerCase();
      const complet1 = `${nom} ${prenom}`;
      const complet2 = `${prenom} ${nom}`;

      return (
        mat.includes(t) ||
        nom.includes(t) ||
        prenom.includes(t) ||
        complet1.includes(t) ||
        complet2.includes(t)
      );
    }).slice(0, 8); // Max 8 suggestions

    setSuggestions(resultats);
    setSuggestionsOuvertes(resultats.length > 0);
  }, [saisieTexte, etudiantsList]);

  // Émission d'un son synthétique agréable (Web Audio API)
  const jouerBip = (type: 'arrivee' | 'retard' | 'tour' | 'erreur') => {
    if (!sonActif) return;
    try {
      const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      if (type === 'arrivee') {
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        osc.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.08); // A5
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.25);
      } else if (type === 'retard') {
        osc.frequency.setValueAtTime(440, audioCtx.currentTime);
        osc.frequency.setValueAtTime(349.23, audioCtx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.3);
      } else if (type === 'tour') {
        osc.frequency.setValueAtTime(659.25, audioCtx.currentTime); // E5
        osc.frequency.setValueAtTime(523.25, audioCtx.currentTime + 0.08); // C5
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.22);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.22);
      } else {
        osc.frequency.setValueAtTime(220, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.2);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.2);
      }
    } catch {
      // Ignorer si audio restreint
    }
  };

  // Traiter le pointage d'un étudiant déjà identifié
  const pointerEtudiant = async (etudiant: Etudiant) => {
    setMessageErreur(null);
    setSuggestionsOuvertes(false);
    setEtudiantsMultiples(null);

    // Récupérer son pointage existant pour aujourd'hui
    const pointageExistant = await getPointageEtudiantJour(etudiant.matricule);

    // Appliquer le moteur logique ISGI
    const resultat = traiterScanEtudiant(etudiant, pointageExistant, {
      appareil: 'pc',
      surveillantId,
      surveillantNom,
      heureLimiteArrivee: heureLimite
    });

    // Sauvegarder dans Dexie et synchroniser avec Supabase
    await sauvegarderPointage(resultat.pointage);

    // Mettre à jour l'affichage
    setResultatDernierScan(resultat);
    setSaisieTexte('');

    // Son de retour
    if (resultat.action === 'arrivee') {
      jouerBip(resultat.estEnRetard ? 'retard' : 'arrivee');
    } else {
      jouerBip('tour');
    }

    // Refocus automatique pour le prochain scan
    inputRef.current?.focus();
  };

  // Traiter une saisie brute (QR code, douchette, matricule ou NOM)
  const executerRechercheOuScan = async (valeurBrute: string) => {
    setMessageErreur(null);
    setSuggestionsOuvertes(false);
    setEtudiantsMultiples(null);

    const texteNettoye = valeurBrute.trim();
    if (!texteNettoye) return;

    // 1. Tenter d'extraire un matricule formel (ex: ETUDIANT:ISGI-2025... ou ISGI-2026-0001)
    const matriculeExtrait = extraireMatriculeDepuisQR(texteNettoye);

    // 2. Si le texte est un matricule connu ou exact
    let etudiantTrouve = etudiantsList.find(
      (e) => e.matricule.toLowerCase() === matriculeExtrait.toLowerCase()
    );

    if (etudiantTrouve) {
      await pointerEtudiant(etudiantTrouve);
      return;
    }

    // 3. Recherche élargie par NOM, PRÉNOM ou MATRICULE (dans Dexie ou Supabase)
    const resultats = await rechercherEtudiants(texteNettoye);

    if (resultats.length === 1) {
      // Un seul étudiant trouvé : validation et pointage direct !
      await pointerEtudiant(resultats[0]);
    } else if (resultats.length > 1) {
      // Plusieurs correspondances : afficher la sélection
      setEtudiantsMultiples(resultats);
      setMessageErreur(`Plusieurs étudiants correspondent à « ${texteNettoye} ». Veuillez sélectionner l'étudiant concerné ci-dessous.`);
    } else {
      // Aucun résultat
      setMessageErreur(`Aucun étudiant trouvé pour « ${texteNettoye} ». Vérifiez le matricule ou le nom saisi.`);
      jouerBip('erreur');
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (saisieTexte.trim()) {
      executerRechercheOuScan(saisieTexte.trim());
    }
  };

  // Gestion Caméra Webcam
  const toggleCamera = async () => {
    if (cameraActive) {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
        streamRef.current = null;
      }
      setCameraActive(false);
    } else {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        streamRef.current = stream;
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          videoRef.current.play();
        }
        setCameraActive(true);
      } catch (err) {
        setMessageErreur('Impossible d\'accéder à la webcam ou caméra.');
      }
    }
  };

  // Nettoyage caméra au démontage
  useEffect(() => {
    return () => {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
      }
    };
  }, []);

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {/* En-tête */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-2xl font-extrabold text-white font-heading">
              Poste de Scan & Contrôle d'Accès
            </h2>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Prêt pour scan
            </span>
          </div>
          <p className="text-sm text-slate-400 mt-1">
            Compatible douchette code-barres USB, saisie matricule, recherche par nom/prénom et webcam.
          </p>
        </div>

        {/* Contrôles Son, Synchronisation & Caméra */}
        <div className="flex items-center gap-3">
          <button
            onClick={() => rechargerEtudiants(true)}
            disabled={chargementSync}
            className="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs font-semibold transition-all disabled:opacity-50"
            title="Synchroniser la liste des étudiants avec Supabase"
          >
            <RefreshCw className={`w-4 h-4 text-blue-400 ${chargementSync ? 'animate-spin' : ''}`} />
            <span>{chargementSync ? 'Synchronisation...' : 'Actualiser Base'}</span>
          </button>

          <button
            onClick={() => setSonActif(!sonActif)}
            className={`p-2.5 rounded-xl border transition-all ${
              sonActif 
                ? 'bg-blue-600/20 text-blue-400 border-blue-500/30 hover:bg-blue-600/30' 
                : 'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700'
            }`}
            title={sonActif ? 'Bip sonore actif' : 'Bip sonore coupé'}
          >
            {sonActif ? <Volume2 className="w-5 h-5" /> : <VolumeX className="w-5 h-5" />}
          </button>

          <button
            onClick={toggleCamera}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm border transition-all ${
              cameraActive
                ? 'bg-rose-500/20 text-rose-400 border-rose-500/30 hover:bg-rose-500/30'
                : 'bg-slate-800 text-slate-300 border-slate-700 hover:bg-slate-700 hover:text-white'
            }`}
          >
            {cameraActive ? (
              <>
                <CameraOff className="w-4 h-4" />
                <span>Couper Webcam</span>
              </>
            ) : (
              <>
                <Camera className="w-4 h-4" />
                <span>Activer Webcam</span>
              </>
            )}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* Colonne GAUCHE : Zone de Scan & Recherche */}
        <div className="lg:col-span-6 space-y-6">
          {/* Formulaire de Saisie / Douchette / Recherche par Nom */}
          <div ref={containerRef} className="p-6 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-4 relative">
            <div className="flex items-center justify-between">
              <label htmlFor="scan-input" className="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                <QrCode className="w-4 h-4 text-blue-400" />
                <span>Lecture Douchette, Matricule ou Recherche par Nom</span>
              </label>
              <span className="text-[11px] text-slate-400">Touche [Entrée] pour valider</span>
            </div>

            <form onSubmit={handleSubmit} className="flex gap-2 relative">
              <div className="relative flex-1">
                <Search className="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2" />
                <input
                  id="scan-input"
                  ref={inputRef}
                  type="text"
                  value={saisieTexte}
                  onChange={(e) => setSaisieTexte(e.target.value)}
                  onFocus={() => {
                    if (suggestions.length > 0) setSuggestionsOuvertes(true);
                  }}
                  placeholder="Tapez un Nom, Prénom ou Matricule (ex: KISSALOU, ELENGA)..."
                  className="w-full pl-12 pr-4 py-3.5 bg-slate-900 border-2 border-slate-700 focus:border-blue-500 rounded-xl text-white placeholder-slate-500 text-sm tracking-wide focus:outline-none focus:ring-4 focus:ring-blue-500/20 transition-all shadow-inner"
                  autoFocus
                  autoComplete="off"
                />

                {saisieTexte && (
                  <button
                    type="button"
                    onClick={() => {
                      setSaisieTexte('');
                      setSuggestions([]);
                      setSuggestionsOuvertes(false);
                      inputRef.current?.focus();
                    }}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white p-1"
                  >
                    <X className="w-4 h-4" />
                  </button>
                )}
              </div>

              <button
                type="submit"
                disabled={!saisieTexte.trim()}
                className="px-6 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-50 disabled:pointer-events-none text-white font-bold text-sm shadow-lg shadow-blue-600/30 transition-all shrink-0 flex items-center gap-1.5"
              >
                <span>Valider</span>
              </button>
            </form>

            {/* Menu Déroulant des Suggestions Dynamiques en Direct */}
            {suggestionsOuvertes && suggestions.length > 0 && (
              <div className="absolute left-6 right-6 top-[102px] z-50 bg-slate-900/95 backdrop-blur-md border border-blue-500/40 rounded-xl shadow-2xl max-h-72 overflow-y-auto divide-y divide-slate-800">
                <div className="px-3.5 py-2 text-[11px] font-semibold text-slate-400 bg-slate-950/60 flex items-center justify-between">
                  <span>Résultats correspondants ({suggestions.length}) :</span>
                  <span className="text-[10px] text-blue-400">Cliquez pour scanner directement</span>
                </div>
                {suggestions.map((etud) => (
                  <button
                    key={etud.id}
                    type="button"
                    onClick={() => pointerEtudiant(etud)}
                    className="w-full text-left px-4 py-3 hover:bg-blue-600/20 flex items-center justify-between gap-3 transition-colors group"
                  >
                    <div className="flex items-center gap-3">
                      <div className="w-9 h-9 rounded-lg bg-blue-600/20 text-blue-400 border border-blue-500/30 flex items-center justify-center font-bold text-xs uppercase shrink-0">
                        {etud.nom.charAt(0)}{etud.prenom.charAt(0)}
                      </div>
                      <div>
                        <div className="text-sm font-bold text-white group-hover:text-blue-300">
                          {etud.nom} {etud.prenom}
                        </div>
                        <div className="text-xs text-slate-400">
                          {etud.classe_nom || etud.filiere}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="font-mono text-xs px-2 py-0.5 rounded bg-slate-800 text-blue-400 border border-slate-700">
                        {etud.matricule}
                      </span>
                      <ChevronRight className="w-4 h-4 text-slate-500 group-hover:text-blue-400 transition-transform group-hover:translate-x-0.5" />
                    </div>
                  </button>
                ))}
              </div>
            )}

            {/* Message d'Erreur ou Info */}
            {messageErreur && (
              <div className="p-3.5 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2.5 animate-shake">
                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-400" />
                <span>{messageErreur}</span>
              </div>
            )}

            {/* Liste de sélection si homonymes / résultats multiples trouvés */}
            {etudiantsMultiples && etudiantsMultiples.length > 0 && (
              <div className="p-4 rounded-xl bg-slate-900 border border-amber-500/40 space-y-3">
                <div className="text-xs font-bold text-amber-300 flex items-center justify-between">
                  <span>Choisissez l'étudiant à pointer :</span>
                  <button 
                    onClick={() => setEtudiantsMultiples(null)}
                    className="text-slate-400 hover:text-white"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {etudiantsMultiples.map((etud) => (
                    <button
                      key={etud.id}
                      onClick={() => pointerEtudiant(etud)}
                      className="p-3 rounded-lg bg-slate-800 hover:bg-blue-600/30 border border-slate-700 hover:border-blue-500/50 text-left transition-all flex items-center justify-between"
                    >
                      <div>
                        <div className="text-xs font-bold text-white">{etud.nom} {etud.prenom}</div>
                        <div className="text-[11px] text-slate-400 font-mono">{etud.matricule}</div>
                        <div className="text-[10px] text-slate-500">{etud.classe_nom}</div>
                      </div>
                      <ChevronRight className="w-4 h-4 text-blue-400 shrink-0" />
                    </button>
                  ))}
                </div>
              </div>
            )}

            {/* Raccourcis de Test Rapide (Étudiants Réels de Supabase) */}
            <div className="pt-2 border-t border-slate-700/50">
              <div className="text-[11px] font-semibold text-slate-400 mb-2 flex items-center justify-between">
                <span className="flex items-center gap-1.5">
                  <Zap className="w-3.5 h-3.5 text-amber-400" />
                  <span>Étudiants de la base ({etudiantsList.length} inscrits) :</span>
                </span>
                <span className="text-[10px] text-slate-500">Cliquez pour tester en 1-Clic</span>
              </div>
              <div className="flex flex-wrap gap-2">
                {etudiantsList.slice(0, 6).map((etud) => (
                  <button
                    key={etud.id}
                    onClick={() => pointerEtudiant(etud)}
                    className="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-700 border border-slate-700 hover:border-blue-500/50 text-xs text-slate-300 transition-all flex items-center gap-1.5"
                  >
                    <span className="font-mono text-blue-400">{etud.matricule}</span>
                    <span className="text-slate-300 font-semibold">({etud.nom})</span>
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* Flux Vidéo Webcam (si activée) */}
          {cameraActive && (
            <div className="p-4 rounded-2xl bg-slate-800/60 border border-slate-700/60 shadow-xl space-y-3">
              <div className="flex items-center justify-between text-xs text-slate-300">
                <span className="font-semibold flex items-center gap-1.5">
                  <Camera className="w-4 h-4 text-emerald-400" />
                  Visée Caméra active
                </span>
                <span className="text-slate-400">Présentez le badge devant l'objectif</span>
              </div>
              <div className="relative aspect-video rounded-xl overflow-hidden bg-black border border-slate-700 flex items-center justify-center">
                <video ref={videoRef} autoPlay playsInline muted className="w-full h-full object-cover" />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                  <div className="w-48 h-48 border-2 border-emerald-400/80 rounded-2xl shadow-[0_0_20px_rgba(52,211,153,0.3)] animate-pulse"></div>
                </div>
              </div>
            </div>
          )}

          {/* Rappel Visuel de la Règle Métier */}
          <div className="p-5 rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/90 border border-slate-700/60 shadow-lg space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-indigo-400" />
              <span>Règle officielle des scans journaliers</span>
            </h4>
            <div className="grid grid-cols-3 gap-2.5 text-center text-xs">
              <div className="p-3 rounded-xl bg-slate-900/80 border border-emerald-500/30">
                <div className="font-bold text-emerald-400 mb-1">1er Scan</div>
                <div className="text-[11px] text-slate-300">Heure d'arrivée</div>
                <div className="text-[10px] text-slate-500 mt-1">À l'heure / Retard</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-900/80 border border-amber-500/30">
                <div className="font-bold text-amber-400 mb-1">Scans Suivants</div>
                <div className="text-[11px] text-slate-300">Tours & Sorties</div>
                <div className="text-[10px] text-slate-500 mt-1">Incrémentation</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-900/80 border border-blue-500/30">
                <div className="font-bold text-blue-400 mb-1">Dernier Scan</div>
                <div className="text-[11px] text-slate-300">Heure de départ</div>
                <div className="text-[10px] text-slate-500 mt-1">Définitif</div>
              </div>
            </div>
          </div>
        </div>

        {/* Colonne DROITE : Grand Écran de Validation Immédiat */}
        <div className="lg:col-span-6">
          {resultatDernierScan ? (
            <div className="p-6 rounded-2xl bg-slate-800/80 border border-slate-700/80 shadow-2xl space-y-6 animate-fadeIn">
              {/* Badge d'action grand format */}
              <div className={`p-4 rounded-xl text-center font-bold text-base flex items-center justify-center gap-2.5 shadow-lg ${
                resultatDernierScan.action === 'arrivee'
                  ? resultatDernierScan.estEnRetard
                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40'
                    : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                  : resultatDernierScan.action === 'sortie_tour'
                  ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
                  : 'bg-blue-500/20 text-blue-300 border border-blue-500/40'
              }`}>
                {resultatDernierScan.action === 'arrivee' ? (
                  resultatDernierScan.estEnRetard ? <AlertTriangle className="w-5 h-5 text-rose-400" /> : <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                ) : resultatDernierScan.action === 'sortie_tour' ? (
                  <LogOut className="w-5 h-5 text-amber-400" />
                ) : (
                  <Building2 className="w-5 h-5 text-blue-400" />
                )}
                <span>{resultatDernierScan.message}</span>
              </div>

              {/* Fiche Étudiant avec Photo */}
              <div className="flex flex-col sm:flex-row items-center gap-5 p-5 rounded-xl bg-slate-900/80 border border-slate-700/60">
                {resultatDernierScan.etudiant.photo_url ? (
                  <img
                    src={resultatDernierScan.etudiant.photo_url}
                    alt={resultatDernierScan.etudiant.nom}
                    className="w-24 h-24 rounded-2xl object-cover border-2 border-blue-500/40 shadow-md"
                  />
                ) : (
                  <div className="w-24 h-24 rounded-2xl bg-slate-800 border-2 border-slate-700 flex items-center justify-center text-slate-400 font-bold text-xl">
                    {resultatDernierScan.etudiant.nom.charAt(0)}{resultatDernierScan.etudiant.prenom.charAt(0)}
                  </div>
                )}

                <div className="text-center sm:text-left space-y-1">
                  <div className="inline-block px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">
                    {resultatDernierScan.etudiant.matricule}
                  </div>
                  <h3 className="text-xl font-extrabold text-white font-heading">
                    {resultatDernierScan.etudiant.nom} {resultatDernierScan.etudiant.prenom}
                  </h3>
                  <div className="text-sm text-slate-300 font-medium">
                    {resultatDernierScan.etudiant.classe_nom || resultatDernierScan.etudiant.filiere}
                  </div>
                  {resultatDernierScan.etudiant.telephone && (
                    <div className="text-xs text-slate-400">
                      Tél : {resultatDernierScan.etudiant.telephone}
                    </div>
                  )}
                </div>
              </div>

              {/* Statuts & Compteurs du jour */}
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">1ère Arrivée</div>
                  <div className="text-base font-bold text-white font-mono mt-0.5">
                    {formaterHeure(resultatDernierScan.pointage.heure_arrivee)}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">
                    {resultatDernierScan.pointage.statut_arrivee === 'a_l_heure' ? (
                      <span className="text-emerald-400">À l'heure</span>
                    ) : (
                      <span className="text-rose-400">En retard</span>
                    )}
                  </div>
                </div>

                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">Tours de Sorties</div>
                  <div className="text-base font-bold text-amber-400 font-mono mt-0.5">
                    {resultatDernierScan.pointage.nombre_tours}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">sorties temporaires</div>
                </div>

                <div className="p-3.5 rounded-xl bg-slate-900/60 border border-slate-700/50">
                  <div className="text-[11px] text-slate-400 uppercase font-semibold">Position Actuelle</div>
                  <div className="text-base font-bold font-mono mt-0.5">
                    {resultatDernierScan.pointage.statut_actuel === 'sur_site' ? (
                      <span className="text-emerald-400">Sur Site</span>
                    ) : (
                      <span className="text-amber-400">Sorti</span>
                    )}
                  </div>
                  <div className="text-[10px] mt-1 text-slate-400">
                    {resultatDernierScan.pointage.heure_depart ? `Départ: ${formaterHeure(resultatDernierScan.pointage.heure_depart)}` : 'Présent'}
                  </div>
                </div>
              </div>

              {/* Timeline chronologique des scans du jour */}
              <div className="space-y-2 pt-2 border-t border-slate-700/50">
                <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                  Historique des scans d'aujourd'hui
                </div>
                <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
                  {resultatDernierScan.pointage.historique_scans.map((log, index) => (
                    <div key={log.id} className="flex items-center justify-between p-2.5 rounded-lg bg-slate-900/50 border border-slate-700/40 text-xs">
                      <div className="flex items-center gap-2">
                        <span className="w-5 h-5 rounded-full bg-slate-800 text-slate-400 font-bold flex items-center justify-center text-[10px]">
                          {index + 1}
                        </span>
                        <span className="font-semibold text-white capitalize">
                          {log.type === 'arrivee' ? '1ère Arrivée' : log.type === 'sortie_tour' ? `Sortie (Tour N° ${log.tour_numero})` : 'Retour sur site'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2 font-mono text-slate-300">
                        <Clock className="w-3.5 h-3.5 text-blue-400" />
                        <span>{formaterHeure(log.timestamp)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          ) : (
            /* État d'attente / Placeholder */
            <div className="h-full min-h-[420px] rounded-2xl bg-slate-800/40 border border-dashed border-slate-700 flex flex-col items-center justify-center p-8 text-center text-slate-400 space-y-3">
              <div className="w-16 h-16 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center justify-center text-slate-500">
                <User className="w-8 h-8" />
              </div>
              <h3 className="text-lg font-bold text-white font-heading">
                En attente d'un scan...
              </h3>
              <p className="text-xs text-slate-400 max-w-sm">
                Scannez un QR code étudiant, tapez un matricule ou commencez à taper son nom pour pointer instantanément.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
