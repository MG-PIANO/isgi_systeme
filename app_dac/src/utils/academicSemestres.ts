import type { Classe } from '../types';

export type SemestreCode =
  | 'S1'
  | 'S2'
  | 'S3'
  | 'S4'
  | 'S5'
  | 'S6'
  | 'S7'
  | 'S8'
  | 'S9'
  | 'S10'
  | 'Annuel';

export interface SemestreOption {
  value: string;
  label: string;
  shortLabel: string;
  numero: number;
  cycle: 'Licence' | 'Master' | 'Général';
  annee: number; // 1 = L1/1ère année, 2 = L2, 3 = L3, 4 = M1, 5 = M2
  niveauLibelle: string;
}

/**
 * Référentiel complet des semestres universitaires (Système LMD)
 * L1 : Semestres 1 et 2
 * L2 : Semestres 3 et 4
 * L3 : Semestres 5 et 6
 * Master 1 : Semestres 7 et 8
 * Master 2 : Semestres 9 et 10
 */
export const TOUS_LES_SEMESTRES: SemestreOption[] = [
  {
    value: 'S1',
    label: 'Semestre 1 (S1 — Licence 1 / 1ère Année)',
    shortLabel: 'Semestre 1 (S1)',
    numero: 1,
    cycle: 'Licence',
    annee: 1,
    niveauLibelle: 'Licence 1'
  },
  {
    value: 'S2',
    label: 'Semestre 2 (S2 — Licence 1 / 1ère Année)',
    shortLabel: 'Semestre 2 (S2)',
    numero: 2,
    cycle: 'Licence',
    annee: 1,
    niveauLibelle: 'Licence 1'
  },
  {
    value: 'S3',
    label: 'Semestre 3 (S3 — Licence 2 / 2ème Année)',
    shortLabel: 'Semestre 3 (S3)',
    numero: 3,
    cycle: 'Licence',
    annee: 2,
    niveauLibelle: 'Licence 2'
  },
  {
    value: 'S4',
    label: 'Semestre 4 (S4 — Licence 2 / 2ème Année)',
    shortLabel: 'Semestre 4 (S4)',
    numero: 4,
    cycle: 'Licence',
    annee: 2,
    niveauLibelle: 'Licence 2'
  },
  {
    value: 'S5',
    label: 'Semestre 5 (S5 — Licence 3 / 3ème Année)',
    shortLabel: 'Semestre 5 (S5)',
    numero: 5,
    cycle: 'Licence',
    annee: 3,
    niveauLibelle: 'Licence 3'
  },
  {
    value: 'S6',
    label: 'Semestre 6 (S6 — Licence 3 / 3ème Année)',
    shortLabel: 'Semestre 6 (S6)',
    numero: 6,
    cycle: 'Licence',
    annee: 3,
    niveauLibelle: 'Licence 3'
  },
  {
    value: 'S7',
    label: 'Semestre 7 (S7 — Master 1 / 4ème Année)',
    shortLabel: 'Semestre 7 (S7)',
    numero: 7,
    cycle: 'Master',
    annee: 4,
    niveauLibelle: 'Master 1'
  },
  {
    value: 'S8',
    label: 'Semestre 8 (S8 — Master 1 / 4ème Année)',
    shortLabel: 'Semestre 8 (S8)',
    numero: 8,
    cycle: 'Master',
    annee: 4,
    niveauLibelle: 'Master 1'
  },
  {
    value: 'S9',
    label: 'Semestre 9 (S9 — Master 2 / 5ème Année)',
    shortLabel: 'Semestre 9 (S9)',
    numero: 9,
    cycle: 'Master',
    annee: 5,
    niveauLibelle: 'Master 2'
  },
  {
    value: 'S10',
    label: 'Semestre 10 (S10 — Master 2 / 5ème Année)',
    shortLabel: 'Semestre 10 (S10)',
    numero: 10,
    cycle: 'Master',
    annee: 5,
    niveauLibelle: 'Master 2'
  }
];

/**
 * Détecte l'année d'études (1 à 5) à partir d'une classe ou de son libellé
 */
export function detectAnneeEtude(classe?: Classe | null | string): number {
  if (!classe) return 1;
  const str = (
    typeof classe === 'string'
      ? classe
      : `${classe.niveau || ''} ${classe.nom || ''} ${classe.code || ''}`
  ).toUpperCase();

  if (str.includes('M2') || str.includes('MASTER 2') || str.includes('5EME') || str.includes('5ÈME') || str.includes('5E ANNEE') || str.includes('CYCLE 2 (ANNÉE 2)')) return 5;
  if (str.includes('M1') || str.includes('MASTER 1') || str.includes('4EME') || str.includes('4ÈME') || str.includes('4E ANNEE') || str.includes('CYCLE 2 (ANNÉE 1)')) return 4;
  if (str.includes('L3') || str.includes('LICENCE 3') || str.includes('3EME') || str.includes('3ÈME') || str.includes('3E ANNEE') || str.includes('LP3') || str.includes('CYCLE 1 (ANNÉE 3)')) return 3;
  if (str.includes('L2') || str.includes('LICENCE 2') || str.includes('2EME') || str.includes('2ÈME') || str.includes('2E ANNEE') || str.includes('BTS 2') || str.includes('DUT 2') || str.includes('CYCLE 1 (ANNÉE 2)')) return 2;
  if (str.includes('L1') || str.includes('LICENCE 1') || str.includes('1ERE') || str.includes('1ÈRE') || str.includes('1E ANNEE') || str.includes('BTS 1') || str.includes('DUT 1') || str.includes('CYCLE 1 (ANNÉE 1)')) return 1;

  return 1;
}

/**
 * Retourne la liste des semestres correspondant au niveau de la classe
 * - Licence 1 : S1, S2
 * - Licence 2 : S3, S4
 * - Licence 3 : S5, S6
 * - Master 1  : S7, S8
 * - Master 2  : S9, S10
 */
export function getSemestresForClasse(classe?: Classe | null | string): SemestreOption[] {
  if (!classe) return TOUS_LES_SEMESTRES;
  const annee = detectAnneeEtude(classe);
  switch (annee) {
    case 1:
      return TOUS_LES_SEMESTRES.filter(s => s.value === 'S1' || s.value === 'S2');
    case 2:
      return TOUS_LES_SEMESTRES.filter(s => s.value === 'S3' || s.value === 'S4');
    case 3:
      return TOUS_LES_SEMESTRES.filter(s => s.value === 'S5' || s.value === 'S6');
    case 4:
      return TOUS_LES_SEMESTRES.filter(s => s.value === 'S7' || s.value === 'S8');
    case 5:
      return TOUS_LES_SEMESTRES.filter(s => s.value === 'S9' || s.value === 'S10');
    default:
      return TOUS_LES_SEMESTRES;
  }
}

/**
 * Retourne le semestre initial par défaut pour une classe donnée
 */
export function getDefaultSemestreForClasse(classe?: Classe | null | string): string {
  const semestres = getSemestresForClasse(classe);
  return semestres.length > 0 ? semestres[0].value : 'S1';
}

/**
 * Vérifie si un code semestre est valide pour une classe
 */
export function isSemestreValidForClasse(semestre: string, classe?: Classe | null | string): boolean {
  if (!classe) return true;
  const options = getSemestresForClasse(classe);
  return options.some(s => s.value.toUpperCase() === (semestre || '').toUpperCase());
}

/**
 * Formate un libellé clair pour un semestre
 */
export function formatSemestreLabel(semestre: string): string {
  if (!semestre) return '-';
  const found = TOUS_LES_SEMESTRES.find(s => s.value.toUpperCase() === (semestre || '').toUpperCase());
  if (found) return found.shortLabel;
  if (semestre.toUpperCase() === 'ANNUEL') return 'Annuel (Session Complète)';
  return `Semestre ${semestre}`;
}

/**
 * Formate un titre de semestre épuré pour les documents officiels
 * Ex: S1 -> "Semestre 1", S3 -> "Semestre 3", S10 -> "Semestre 10"
 */
export function formatSemestreTitre(semestre: string): string {
  if (!semestre) return 'Semestre';
  const found = TOUS_LES_SEMESTRES.find(s => s.value.toUpperCase() === (semestre || '').toUpperCase());
  if (found) return `Semestre ${found.numero}`;
  if (semestre.toUpperCase() === 'ANNUEL') return 'Session Annuelle';
  return `Semestre ${semestre.replace(/^S/i, '')}`;
}
