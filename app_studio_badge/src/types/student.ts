export interface Student {
  id: string;
  nom: string;
  prenom: string;
  matricule: string;
  filiere?: string;
  niveau?: string;
  lieu_naissance?: string;
  annee_academique?: string;
  photo_url?: string | null;
  photo_cached?: string | null;
  telephone?: string | null;
  email?: string | null;
}

export interface PhotoTransform {
  zoom: number;
  offsetX: number;
  offsetY: number;
  rotation: number; // in degrees (0, 90, 180, 270)
  brightness: number; // 80 - 140
  contrast: number; // 80 - 140
}

export type FilterStatus = 'all' | 'no-photo' | 'has-photo';
export type BadgeSide = 'recto' | 'verso';
