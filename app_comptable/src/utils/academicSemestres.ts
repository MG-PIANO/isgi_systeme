import type { Classe } from '../types/academic';

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
  annee: number;
  niveauLibelle: string;
}

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
  },
  {
    value: 'Annuel',
    label: 'Cycle Annuel Complet',
    shortLabel: 'Annuel',
    numero: 0,
    cycle: 'Général',
    annee: 0,
    niveauLibelle: 'Tous niveaux'
  }
];

export function getSemestresForNiveau(niveau?: string): SemestreOption[] {
  if (!niveau) return TOUS_LES_SEMESTRES;
  const n = (niveau || '').toUpperCase().trim();

  if (n.includes('L1') || n.includes('1ÈRE') || n.includes('1ERE') || n.includes('LICENCE 1')) {
    return TOUS_LES_SEMESTRES.filter(s => s.value === 'S1' || s.value === 'S2');
  }
  if (n.includes('L2') || n.includes('2ÈME') || n.includes('2EME') || n.includes('LICENCE 2')) {
    return TOUS_LES_SEMESTRES.filter(s => s.value === 'S3' || s.value === 'S4');
  }
  if (n.includes('L3') || n.includes('3ÈME') || n.includes('3EME') || n.includes('LICENCE 3')) {
    return TOUS_LES_SEMESTRES.filter(s => s.value === 'S5' || s.value === 'S6');
  }
  if (n.includes('M1') || n.includes('MASTER 1') || n.includes('4ÈME') || n.includes('4EME')) {
    return TOUS_LES_SEMESTRES.filter(s => s.value === 'S7' || s.value === 'S8');
  }
  if (n.includes('M2') || n.includes('MASTER 2') || n.includes('5ÈME') || n.includes('5EME')) {
    return TOUS_LES_SEMESTRES.filter(s => s.value === 'S9' || s.value === 'S10');
  }

  return TOUS_LES_SEMESTRES;
}

export function getSemestresForClasse(classe?: Classe | null): SemestreOption[] {
  if (!classe) return TOUS_LES_SEMESTRES;
  return getSemestresForNiveau(classe.niveau || classe.nom || classe.code);
}

export function getDefaultSemestreForClasse(classe?: Classe | null): string {
  const options = getSemestresForClasse(classe);
  return options[0]?.value || 'S1';
}

export function formatSemestreLabel(codeSemestre?: string): string {
  if (!codeSemestre || codeSemestre === 'all') return 'Tous les semestres';
  const found = TOUS_LES_SEMESTRES.find(s => s.value.toUpperCase() === codeSemestre.toUpperCase());
  return found ? found.shortLabel : codeSemestre;
}
