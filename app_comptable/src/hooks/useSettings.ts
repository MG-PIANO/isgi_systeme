import { useState, useEffect } from 'react';

export interface Deduction {
  id: string;
  nom: string;
  type: 'pourcentage' | 'fixe';
  valeur: number;
}

export interface Tarifs {
  inscriptions: {
    nouvelle: number;
    reinscription: number;
    carteEtudiant: number;
  };
  mensualites: Record<string, number>;
  cycleLocal: {
    gestion: Record<string, number>;
    gestionFrais: {
      s1_examen: number;
      s1_tp: number;
      s2_examen: number;
      s2_stage: number;
      s2_tp: number;
    };
    industrie: Record<string, number>;
    industrieFrais: {
      s1_tp: number;
      s1_examen: number;
      s2_tp: number;
      s2_examen: number;
      s2_stage: number;
    };
  };
  cycleInternational: {
    france: number;
    londres: Record<string, number>;
  };
  coursDuSoir: Record<string, number>;
}

export interface AppSettings {
  anneeAcademique: string;
  nomEcole: string;
  sigle: string;
  nomComptable: string;
  nomDG: string;
  tarifs: Tarifs;
  fraisScolarite?: Record<string, number>; // Legacy
  categoriesDepenses: string[];
  deductions: Deduction[];
}

export const DEFAULT_SETTINGS: AppSettings = {
  anneeAcademique: '2026-2027',
  nomEcole: "Institut Supérieur de Gestion et d'Ingénierie",
  sigle: 'ISGI',
  nomComptable: 'Le Comptable',
  nomDG: 'Le Directeur Général',
  tarifs: {
    inscriptions: {
      nouvelle: 25000,
      reinscription: 20000,
      carteEtudiant: 5000
    },
    mensualites: {
      'Licence 1': 25000,
      'Licence 2': 30000,
      'Licence 3': 35000
    },
    cycleLocal: {
      gestion: {
        'Licence 1': 250000,
        'Licence 2': 300000,
        'Licence 3': 350000
      },
      gestionFrais: {
        s1_examen: 10000,
        s1_tp: 15000,
        s2_examen: 10000,
        s2_stage: 35000,
        s2_tp: 15000
      },
      industrie: {
        'Licence 1': 350000,
        'Licence 2': 400000,
        'Licence 3': 450000
      },
      industrieFrais: {
        s1_tp: 25000,
        s1_examen: 10000,
        s2_tp: 25000,
        s2_examen: 10000,
        s2_stage: 35000
      }
    },
    cycleInternational: {
      france: 2300000,
      londres: {
        'Cycle 1': 800000,
        'Cycle 2': 1000000
      }
    },
    coursDuSoir: {
      'Licence 1': 300000,
      'Licence 2': 350000,
      'Licence 3': 400000
    }
  },
  categoriesDepenses: [
    'Électricité et Eau',
    'Loyer',
    'Achats et Fournitures',
    'Salaires Personnel',
    'Salaires Professeurs',
    'Entretien et Réparations',
    'Marketing et Publicité',
    'Frais Bancaires',
    'Internet et Communications',
    'Transport et Déplacements'
  ],
  deductions: [
    { id: '1', nom: 'IRPP', type: 'pourcentage', valeur: 5 },
    { id: '2', nom: 'CNSS', type: 'pourcentage', valeur: 2.5 }
  ]
};

export function useSettings() {
  const [settings, setSettings] = useState<AppSettings>(() => {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) {
      try {
        const parsed = JSON.parse(saved);
        return { 
          ...DEFAULT_SETTINGS, 
          ...parsed,
          tarifs: {
            ...DEFAULT_SETTINGS.tarifs,
            ...(parsed.tarifs || {}),
            coursDuSoir: {
              ...DEFAULT_SETTINGS.tarifs.coursDuSoir,
              ...(parsed.tarifs?.coursDuSoir || {})
            }
          }
        };
      } catch (e) {
        return DEFAULT_SETTINGS;
      }
    }
    return DEFAULT_SETTINGS;
  });

  useEffect(() => {
    localStorage.setItem('isgi_settings', JSON.stringify(settings));
  }, [settings]);

  const updateSettings = (newSettings: Partial<AppSettings>) => {
    setSettings(prev => ({ ...prev, ...newSettings }));
  };

  return { settings, updateSettings, DEFAULT_SETTINGS };
}
