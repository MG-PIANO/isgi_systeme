import type { Etudiant } from '../db/db';

export const PAYMENT_TYPES = [
  "Frais Scolaire",
  "Inscription",
  "Réinscription",
  "Frais de TP / Accessoires TP",
  "Frais de stage",
  "Frais d'examen (Session)",
  "Frais de rattrapage",
  "Frais divers (Carte d'étudiant...)",
  "Frais d'assurance",
  "Frais de Polo"
] as const;

export type PaymentType = typeof PAYMENT_TYPES[number];

export function getAppSettings() {
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) return JSON.parse(saved);
  } catch(e) {}
  
  return {
    tarifs: {
      inscriptions: { nouvelle: 25000, reinscription: 20000, carteEtudiant: 5000, polo: 10000, assurance: 5000 },
      mensualites: { 'Licence 1': 25000, 'Licence 2': 30000, 'Licence 3': 35000 },
      cycleLocal: {
        gestion: { 'Licence 1': 250000, 'Licence 2': 300000, 'Licence 3': 350000 },
        gestionFrais: { s1_examen: 10000, s1_tp: 15000, s2_examen: 10000, s2_stage: 35000, s2_tp: 15000 },
        industrie: { 'Licence 1': 350000, 'Licence 2': 400000, 'Licence 3': 450000 },
        industrieFrais: { s1_tp: 25000, s1_examen: 10000, s2_tp: 25000, s2_examen: 10000, s2_stage: 35000 }
      },
      cycleInternational: { france: 2300000, londres: { 'Cycle 1': 800000, 'Cycle 2': 1000000 } },
      coursDuSoir: { 'Licence 1': 300000, 'Licence 2': 350000, 'Licence 3': 400000 }
    }
  };
}

export function getAnnualCost(etudiant: Etudiant): number {
  const settings = getAppSettings();
  const tarifs = settings.tarifs;

  const option = (etudiant.option || '').toLowerCase().trim();
  const niveau = (etudiant.niveau || '').trim();
  const cycle_formation = (etudiant.cycle_formation || '').toLowerCase().trim();
  const vague = (etudiant.vague || (etudiant as any).session_cours || '').toLowerCase().trim();
  const isSoir = vague.includes('soir');

  // Si l'étudiant est inscrit aux Cours du Soir
  if (isSoir) {
    if (tarifs.coursDuSoir && tarifs.coursDuSoir[niveau]) {
      return tarifs.coursDuSoir[niveau];
    }
    // Détection par numéro d'année (1ère, 2ème, 3ème année)
    if (niveau.includes('1')) return tarifs.coursDuSoir?.['Licence 1'] || 300000;
    if (niveau.includes('2')) return tarifs.coursDuSoir?.['Licence 2'] || 350000;
    if (niveau.includes('3')) return tarifs.coursDuSoir?.['Licence 3'] || 400000;
    return 300000;
  }

  const isIndustrie = option.includes('industrie');

  if (cycle_formation.includes('france')) return tarifs.cycleInternational.france || 2300000;
  if (cycle_formation.includes('londre')) return tarifs.cycleInternational.londres[niveau] || 800000;

  if (isIndustrie) {
    return tarifs.cycleLocal.industrie[niveau] || 0;
  } else {
    return tarifs.cycleLocal.gestion[niveau] || 0;
  }
}

export function getRecommendedAmount(etudiant: Etudiant | undefined, typePaiement: string): number {
  if (!etudiant) return 0;
  const settings = getAppSettings();
  const tarifs = settings.tarifs;
  
  const niveau = etudiant.niveau?.trim() || '';
  const option = (etudiant.option || '').toLowerCase();
  const isIndustrie = option.includes('industrie');
  const vague = (etudiant.vague || (etudiant as any).session_cours || '').toLowerCase().trim();
  const isSoir = vague.includes('soir');

  if (typePaiement === 'Inscription') return tarifs.inscriptions.nouvelle || 25000;
  if (typePaiement === 'Réinscription') return tarifs.inscriptions.reinscription || 20000;
  if (typePaiement === 'Frais Scolaire' || typePaiement === 'Mensualité') {
    if (isSoir) {
      return getAnnualCost(etudiant) / 10;
    }
    return tarifs.mensualites[niveau] || (getAnnualCost(etudiant) / 10);
  }
  if (typePaiement === "Frais divers (Carte d'étudiant...)") return tarifs.inscriptions.carteEtudiant || 5000;
  
  if (typePaiement === 'Frais de TP / Accessoires TP') {
    return isIndustrie ? (tarifs.cycleLocal.industrieFrais?.s1_tp || 25000) : (tarifs.cycleLocal.gestionFrais?.s1_tp || 15000);
  }
  if (typePaiement === "Frais d'examen (Session)") {
    return isIndustrie ? (tarifs.cycleLocal.industrieFrais?.s1_examen || 10000) : (tarifs.cycleLocal.gestionFrais?.s1_examen || 10000);
  }
  if (typePaiement === 'Frais de stage') {
    return isIndustrie ? (tarifs.cycleLocal.industrieFrais?.s2_stage || 35000) : (tarifs.cycleLocal.gestionFrais?.s2_stage || 35000);
  }
  if (typePaiement === 'Frais de Polo') return (tarifs.inscriptions as any)?.polo || 10000;
  if (typePaiement === "Frais d'assurance") return (tarifs.inscriptions as any)?.assurance || 5000;
  
  return 0;
}

export function isPaymentTypeInscription(type?: string): boolean {
  if (!type) return false;
  const t = type.toUpperCase();
  return t.includes('INSCRIPTION') || t.includes('DOSSIER') || t.includes('REINSCRIPTION');
}

export function isPaymentTypePolo(type?: string): boolean {
  if (!type) return false;
  const t = type.toUpperCase();
  return t.includes('POLO') || t.includes('TENUE');
}

export function isPaymentTypeCarte(type?: string): boolean {
  if (!type) return false;
  const t = type.toUpperCase();
  return t.includes('CARTE');
}

export function isPaymentTypeAssurance(type?: string): boolean {
  if (!type) return false;
  const t = type.toUpperCase();
  return t.includes('ASSURANCE');
}

export function isPaymentTypeFournitures(type?: string): boolean {
  if (!type) return false;
  const t = type.toUpperCase();
  return t.includes('MARQUEUR') || t.includes('RAME');
}

export function isPaymentTypeScolarite(type?: string): boolean {
  if (!type) return true;
  const t = type.toUpperCase();
  if (isPaymentTypeInscription(type) || isPaymentTypePolo(type) || isPaymentTypeCarte(type) || isPaymentTypeAssurance(type) || isPaymentTypeFournitures(type)) {
    return false;
  }
  return t.includes('SCOLAIRE') || t.includes('MENSUAL') || t.includes('TRANCHE') || t.includes('SCOLARITE');
}

export function getExpectedMonths(rentree: string, currentDate: Date = new Date(), anneeAcademique?: string): number {
  if (!anneeAcademique) {
    anneeAcademique = '2026-2027'; // Default fallback for current students without this field
  }
  
  const startYear = parseInt(anneeAcademique.split('-')[0]) || new Date().getFullYear();
  const isJanvier = rentree && rentree.toUpperCase().includes('JANV');
  
  // Date de début des cours (Octobre = 9, Janvier = 0)
  const startMonth = isJanvier ? 0 : 9;
  const actualStartYear = isJanvier ? startYear + 1 : startYear;
  
  const startDate = new Date(actualStartYear, startMonth, 1);
  
  // Si on est avant la rentrée, 0 mois attendu
  if (currentDate < startDate) {
    return 0;
  }
  
  // Différence en mois (ex: en Octobre = 0, en Novembre = 1, en Décembre = 2...)
  const monthDiff = (currentDate.getFullYear() - startDate.getFullYear()) * 12 + (currentDate.getMonth() - startDate.getMonth());
  
  // RÈGLE ISGI : L'étudiant règle le mois M jusqu'au 5 du mois suivant (M+1) !
  // En Octobre (monthDiff == 0), l'échéance court jusqu'au 5 Novembre : 0 mois d'arriéré.
  // En Novembre (monthDiff == 1), jusqu'au 5 Novembre : 0 mois. Après le 5 Novembre : 1 mois (Octobre dû).
  let expected = monthDiff;
  if (currentDate.getDate() <= 5) {
    expected = Math.max(0, expected - 1);
  }
  
  // Cap at 10 months (durée de l'année scolaire)
  if (expected < 0) expected = 0;
  if (expected > 10) expected = 10;
  
  return expected;
}

export function getFinancialStatus(etudiant: Etudiant, totalScolaritePaye: number, currentDate: Date = new Date()) {
  const coutAnnuel = getAnnualCost(etudiant);
  const mensualite = coutAnnuel / 10;
  const resteAPayer = Math.max(0, coutAnnuel - totalScolaritePaye);
  
  const expectedMonths = getExpectedMonths(etudiant.rentree || 'OCTOBRE', currentDate, etudiant.annee_academique);
  const montantAttendu = expectedMonths * mensualite;
  
  const arrieres = Math.max(0, montantAttendu - totalScolaritePaye);
  
  return {
    coutAnnuel,
    mensualite,
    totalScolaritePaye,
    resteAPayer,
    expectedMonths,
    montantAttendu,
    arrieres,
    isEnRetard: arrieres > 0
  };
}
