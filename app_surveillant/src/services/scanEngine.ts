// Moteur logique des scans et contrôle d'accès pour le Surveillant Général (ISGI System)
import type { 
  Etudiant, 
  PointageAcces, 
  ScanActionType, 
  ScanLogItem, 
  ScanProcessResult 
} from '../types';

/**
 * Heure limite par défaut pour considérer un étudiant à l'heure (format HH:mm)
 */
export const DEFAULT_HEURE_LIMITE_ARRIVEE = '08:15';

/**
 * Extrait le matricule propre à partir des données brutes d'un QR Code ou code-barres
 */
export function extraireMatriculeDepuisQR(rawData: string): string {
  if (!rawData) return '';
  const trimmed = rawData.trim();

  // 1. Format ISGI Standard: ETUDIANT:ISGI-2025-00019|NOM:...
  const matchEtudiant = trimmed.match(/ETUDIANT:([^|]+)/i);
  if (matchEtudiant && matchEtudiant[1]) {
    return matchEtudiant[1].trim();
  }

  // 2. Format JSON: {"matricule":"ISGI-2025-00019", ...}
  if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
    try {
      const parsed = JSON.parse(trimmed);
      if (parsed.matricule) return String(parsed.matricule).trim();
      if (parsed.id) return String(parsed.id).trim();
    } catch {
      // Ignorer l'erreur et continuer
    }
  }

  // 3. Format direct Matricule (ex: ISGI-2025-00019 ou ISGI/2026/001)
  const matchMatriculeDirect = trimmed.match(/(ISGI[-/][A-Za-z0-9_-]+)/i);
  if (matchMatriculeDirect && matchMatriculeDirect[1]) {
    return matchMatriculeDirect[1].trim();
  }

  // 4. Fallback: la chaîne elle-même si elle n'a pas de séparateurs bizarres
  return trimmed;
}

/**
 * Formate la date actuelle au format YYYY-MM-DD
 */
export function getDateAujourdhui(): string {
  const d = new Date();
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/**
 * Formate l'heure actuelle au format HH:mm:ss
 */
export function getHeureActuelle(): string {
  const d = new Date();
  return d.toTimeString().split(' ')[0]; // HH:mm:ss
}

/**
 * Détermine si une heure donnée est considérée en retard par rapport à l'heure limite
 */
export function estEnRetard(heureScan: string, heureLimite: string = DEFAULT_HEURE_LIMITE_ARRIVEE): boolean {
  const [hScan, mScan] = heureScan.split(':').map(Number);
  const [hLim, mLim] = heureLimite.split(':').map(Number);

  if (hScan > hLim) return true;
  if (hScan === hLim && mScan > mLim) return true;
  return false;
}

/**
 * Logique Métier Principale : Traite un scan pour un étudiant
 * Règle demandée :
 * - 1er scan du jour    = Heure d'arrivée (Ponctualité : À l'heure ou Retard)
 * - Scans intermédiaires = Nombre de tours / sorties temporaires
 * - Dernier scan du jour = Heure de départ définitive
 */
export function traiterScanEtudiant(
  etudiant: Etudiant,
  pointageExistant: PointageAcces | undefined | null,
  options?: {
    appareil?: 'mobile' | 'pc';
    surveillantId?: string;
    surveillantNom?: string;
    heureLimiteArrivee?: string;
    heureForcee?: string; // Utile pour tests ou simulations
  }
): ScanProcessResult {
  const appareil = options?.appareil || 'pc';
  const heureLimite = options?.heureLimiteArrivee || DEFAULT_HEURE_LIMITE_ARRIVEE;
  const nowHeure = options?.heureForcee || getHeureActuelle();
  const dateJour = getDateAujourdhui();
  const nowIso = new Date().toISOString();

  // CAS 1 : PREMIER SCAN DE LA JOURNÉE -> ARRIVÉE
  if (!pointageExistant) {
    const retard = estEnRetard(nowHeure, heureLimite);
    const statutArrivee: 'a_l_heure' | 'en_retard' = retard ? 'en_retard' : 'a_l_heure';

    const scanLog: ScanLogItem = {
      id: crypto.randomUUID(),
      timestamp: nowHeure,
      date_heure_iso: nowIso,
      type: 'arrivee',
      appareil,
      surveillant_id: options?.surveillantId,
      surveillant_nom: options?.surveillantNom,
      notes: retard ? `Arrivée en retard (après ${heureLimite})` : 'Arrivée à l\'heure'
    };

    const nouveauPointage: PointageAcces = {
      id: crypto.randomUUID(),
      etudiant_id: etudiant.id,
      matricule: etudiant.matricule,
      nom: etudiant.nom,
      prenom: etudiant.prenom,
      filiere: etudiant.filiere,
      niveau: etudiant.niveau,
      classe_nom: etudiant.classe_nom || `${etudiant.filiere} ${etudiant.niveau}`,
      photo_url: etudiant.photo_url,
      date_jour: dateJour,
      heure_arrivee: nowHeure,
      statut_arrivee: statutArrivee,
      nombre_tours: 0,
      heure_depart: undefined,
      statut_actuel: 'sur_site',
      appareil_dernier_scan: appareil,
      surveillant_id: options?.surveillantId,
      surveillant_nom: options?.surveillantNom,
      historique_scans: [scanLog],
      created_at: nowIso,
      updated_at: nowIso
    };

    const msg = retard 
      ? `Arrivée enregistrée à ${nowHeure} (Retard)` 
      : `Arrivée enregistrée à ${nowHeure} (À l'heure)`;

    return {
      success: true,
      message: msg,
      action: 'arrivee',
      etudiant,
      pointage: nouveauPointage,
      isFirstScanToday: true,
      estEnRetard: retard,
      tourNumero: 0,
      timestamp: nowHeure
    };
  }

  // CAS 2 : ÉTUDIANT DÉJÀ PRÉSENT AUJOURD'HUI -> GESTION DES TOURS & DÉPARTS
  const statutPrecedent = pointageExistant.statut_actuel;
  let action: ScanActionType;
  let nouveauStatut: 'sur_site' | 'sorti';
  let nouveauNombreTours = pointageExistant.nombre_tours;
  let tourNumero: number | undefined = undefined;
  let message = '';

  if (statutPrecedent === 'sur_site') {
    // L'étudiant sort de l'établissement (Tour supplémentaire ou départ)
    action = 'sortie_tour';
    nouveauStatut = 'sorti';
    nouveauNombreTours += 1;
    tourNumero = nouveauNombreTours;
    message = `Sortie enregistrée à ${nowHeure} (Tour N° ${tourNumero})`;
  } else {
    // L'étudiant était dehors et revient sur le site
    action = 'retour';
    nouveauStatut = 'sur_site';
    message = `Retour sur site enregistré à ${nowHeure}`;
  }

  const scanLog: ScanLogItem = {
    id: crypto.randomUUID(),
    timestamp: nowHeure,
    date_heure_iso: nowIso,
    type: action,
    tour_numero: tourNumero,
    appareil,
    surveillant_id: options?.surveillantId,
    surveillant_nom: options?.surveillantNom,
    notes: action === 'sortie_tour' ? `Sortie école - Tour ${tourNumero}` : 'Retour dans l\'enceinte de l\'école'
  };

  const pointageMisAJour: PointageAcces = {
    ...pointageExistant,
    nombre_tours: nouveauNombreTours,
    // Le dernier scan de sortie ou intermédiaire devient la dernière heure de départ connue
    heure_depart: nouveauStatut === 'sorti' ? nowHeure : pointageExistant.heure_depart,
    statut_actuel: nouveauStatut,
    appareil_dernier_scan: appareil,
    surveillant_id: options?.surveillantId || pointageExistant.surveillant_id,
    surveillant_nom: options?.surveillantNom || pointageExistant.surveillant_nom,
    historique_scans: [...pointageExistant.historique_scans, scanLog],
    updated_at: nowIso
  };

  return {
    success: true,
    message,
    action,
    etudiant,
    pointage: pointageMisAJour,
    isFirstScanToday: false,
    estEnRetard: pointageExistant.statut_arrivee === 'en_retard',
    tourNumero,
    timestamp: nowHeure
  };
}

/**
 * Calcule les statistiques globales du jour à partir de la liste des étudiants et des pointages
 */
export function calculerStatsJour(
  totalInscrits: number,
  pointagesAujourdhui: PointageAcces[]
): {
  totalInscrits: number;
  totalArrives: number;
  actuellementSurSite: number;
  actuellementSortis: number;
  aLHeure: number;
  enRetard: number;
  totalToursSorties: number;
  tauxPresence: number;
} {
  const totalArrives = pointagesAujourdhui.length;
  const actuellementSurSite = pointagesAujourdhui.filter(p => p.statut_actuel === 'sur_site').length;
  const actuellementSortis = pointagesAujourdhui.filter(p => p.statut_actuel === 'sorti').length;
  const aLHeure = pointagesAujourdhui.filter(p => p.statut_arrivee === 'a_l_heure').length;
  const enRetard = pointagesAujourdhui.filter(p => p.statut_arrivee === 'en_retard').length;
  
  const totalToursSorties = pointagesAujourdhui.reduce((acc, p) => acc + (p.nombre_tours || 0), 0);
  const tauxPresence = totalInscrits > 0 ? Math.round((totalArrives / totalInscrits) * 100) : 0;

  return {
    totalInscrits,
    totalArrives,
    actuellementSurSite,
    actuellementSortis,
    aLHeure,
    enRetard,
    totalToursSorties,
    tauxPresence
  };
}
