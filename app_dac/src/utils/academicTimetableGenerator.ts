import type {
  EmploiDuTempsSemestriel,
  SemaineEmploiDuTemps,
  DateImportanteCalendrier,
  CreneauCellule
} from '../types';

export const MONTH_NAMES_FR = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'
];

export const JOURS_SEMAINE_MAP: { [key: string]: number } = {
  'Lundi': 0,
  'Mardi': 1,
  'Mercredi': 2,
  'Jeudi': 3,
  'Vendredi': 4,
  'Samedi': 5,
  'Dimanche': 6
};

export function formatJourDateLabel(d: Date): string {
  const day = d.getDate();
  const month = MONTH_NAMES_FR[d.getMonth()];
  return `${day === 1 ? '1er' : day} ${month}`;
}

export function formatPeriodeLabel(dStart: Date, dEnd: Date): string {
  const dayS = dStart.getDate() === 1 ? '1er' : dStart.getDate();
  const monthS = MONTH_NAMES_FR[dStart.getMonth()];
  const yearS = dStart.getFullYear();

  const dayE = dEnd.getDate() === 1 ? '1er' : dEnd.getDate();
  const monthE = MONTH_NAMES_FR[dEnd.getMonth()];
  const yearE = dEnd.getFullYear();

  if (monthS === monthE && yearS === yearE) {
    return `${dayS} au ${dayE} ${monthS} ${yearS}`;
  } else if (yearS === yearE) {
    return `${dayS} ${monthS} au ${dayE} ${monthE} ${yearS}`;
  } else {
    return `${dayS} ${monthS} ${yearS} au ${dayE} ${monthE} ${yearE}`;
  }
}

export const DATES_IMPORTANTES_DEFAULT_S1: DateImportanteCalendrier[] = [
  { id: 'd1', date_periode: '12 octobre 2026', evenement: '🎓 Début de la rentrée académique' },
  { id: 'd2', date_periode: '24 décembre 2026 au 03 janvier 2027', evenement: '🎄 Congé de Noël et du Nouvel An' },
  { id: 'd3', date_periode: '04 janvier 2027', evenement: '📚 Reprise des cours' },
  { id: 'd4', date_periode: '18 février au 18 mars 2027', evenement: '🔬 TRAVAUX PRATIQUES — OBLIGATOIRES POUR TOUS LES ÉTUDIANTS' },
  { id: 'd5', date_periode: '19 au 21 mars 2027', evenement: '📖 Congé d\'étude' },
  { id: 'd6', date_periode: '22 au 27 mars 2027', evenement: '📝 EXAMEN DE FIN DE SEMESTRE 1 — OBLIGATOIRE POUR TOUS LES ÉTUDIANTS' },
  { id: 'd7', date_periode: '29 mars au 04 avril 2027', evenement: '✝️ Congé de Pâques' },
  { id: 'd8', date_periode: '05 avril 2027', evenement: '🎓 Reprise des cours et début du Semestre 2' },
  { id: 'd9', date_periode: '24 Avril 2027', evenement: '🎓 Proclamation des résultats' },
  { id: 'd10', date_periode: '03 au 08 Mai 2027', evenement: '📝 Examen de Rattrapage du semestre 1' }
];

export const DATES_IMPORTANTES_DEFAULT_S2: DateImportanteCalendrier[] = [
  { id: 'd1', date_periode: '05 Avril 2027', evenement: '🎓 Début de la rentrée académique (Semestre 2)' },
  { id: 'd2', date_periode: '28 Juin au 28 Juillet 2027', evenement: '🔬 TRAVAUX PRATIQUES — OBLIGATOIRES POUR TOUS LES ÉTUDIANTS' },
  { id: 'd3', date_periode: '29 Juillet au 01 Août 2027', evenement: '📖 Congé d\'étude' },
  { id: 'd4', date_periode: '02 au 07 Août 2027', evenement: '📝 EXAMEN DE FIN DE SEMESTRE 2 — OBLIGATOIRE POUR TOUS LES ÉTUDIANTS' },
  { id: 'd5', date_periode: '16 Août 2027', evenement: 'Début des stages en entreprise' },
  { id: 'd6', date_periode: '21 Août 2027', evenement: '🎓 Proclamation des résultats' },
  { id: 'd7', date_periode: '30 Août au 04 Septembre 2027', evenement: '📝 Examen de Rattrapage du semestre 2' }
];

export interface GenererOptions {
  classe_id: string;
  classe_nom: string;
  semestre: string;
  annee_academique: string;
  date_rentree: string; // YYYY-MM-DD
  nb_semaines?: number; // défaut 25
  creneaux?: string[];  // défaut ['08H00–10H00', '10H15–12H15']
  jours_selectionnes?: string[]; // défaut ['Lundi', 'Mercredi', 'Vendredi']
}

export function genererEmploiDuTempsSemestriel(opts: GenererOptions): EmploiDuTempsSemestriel {
  const nbSemaines = opts.nb_semaines || 25;
  const creneaux = opts.creneaux || ['08H00–10H00', '10H15–12H15'];
  const jours = opts.jours_selectionnes || ['Lundi', 'Mercredi', 'Vendredi'];
  const semestre = opts.semestre || 'S1';
  const semestreNum = semestre.replace(/^S/i, '') || '1';

  // Parser la date de début (s'assurer qu'on part d'un lundi)
  const rentreeDate = new Date(opts.date_rentree);
  const dayOfWeek = rentreeDate.getDay(); // 0 Dimanche, 1 Lundi...
  const diffToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
  const premierLundi = new Date(rentreeDate);
  premierLundi.setDate(rentreeDate.getDate() + diffToMonday);

  const semaines: SemaineEmploiDuTemps[] = [];

  for (let s = 1; s <= nbSemaines; s++) {
    const startSem = new Date(premierLundi);
    startSem.setDate(premierLundi.getDate() + (s - 1) * 7);

    const endSem = new Date(startSem);
    endSem.setDate(startSem.getDate() + 6);

    const periodeLabel = formatPeriodeLabel(startSem, endSem);

    // Initialisation des créneaux
    const creneauxData: SemaineEmploiDuTemps['creneaux_data'] = {};

    creneaux.forEach(cr => {
      creneauxData[cr] = {};
      jours.forEach(j => {
        const offset = JOURS_SEMAINE_MAP[j] ?? 0;
        const curDay = new Date(startSem);
        curDay.setDate(startSem.getDate() + offset);

        const dateJourLabel = formatJourDateLabel(curDay);

        // Détection de dates spéciales automatiques
        let type: CreneauCellule['type'] = 'cours';
        let textePersonnalise: string | undefined = undefined;

        // Fêtes de fin d'année (25 déc, 1er janv, etc.)
        const month = curDay.getMonth();
        const dateNum = curDay.getDate();
        if (month === 11 && dateNum === 25) {
          type = 'conge';
          textePersonnalise = '25 décembre 🎄 Congé';
        } else if (month === 11 && dateNum >= 24) {
          type = 'conge';
          textePersonnalise = `${dateJourLabel} 🎄 Congé`;
        } else if (month === 0 && dateNum <= 3) {
          type = 'conge';
          textePersonnalise = `${dateJourLabel} 🎄 Congé`;
        }

        creneauxData[cr][j] = {
          date_jour_label: dateJourLabel,
          type,
          texte_personnalise: textePersonnalise
        };
      });
    });

    // Détection de semaine spéciale globale
    let isSpecial = false;
    let specialTexte: string | undefined = undefined;
    let titreSemaine = `Semaine ${s}`;

    semaines.push({
      numero: s,
      titre_semaine: titreSemaine,
      date_debut: startSem.toISOString().split('T')[0],
      date_fin: endSem.toISOString().split('T')[0],
      periode_label: periodeLabel,
      is_special: isSpecial,
      special_texte: specialTexte,
      creneaux_data: creneauxData
    });
  }

  // Dates importantes par défaut
  const datesImportantes = semestre.toUpperCase().includes('2')
    ? [...DATES_IMPORTANTES_DEFAULT_S2]
    : [...DATES_IMPORTANTES_DEFAULT_S1];

  const now = new Date().toISOString();

  return {
    id: `edt_${opts.classe_id}_${semestre}_${opts.annee_academique.replace(/\s+/g, '_')}`,
    classe_id: opts.classe_id,
    classe_nom: opts.classe_nom,
    semestre,
    annee_academique: opts.annee_academique,
    titre: `CALENDRIER ACADEMIQUES SEMESTRE ${semestreNum}`,
    date_rentree: opts.date_rentree,
    nb_semaines: nbSemaines,
    creneaux,
    jours_selectionnes: jours,
    dates_importantes: datesImportantes,
    semaines,
    publie: false,
    created_at: now,
    updated_at: now
  };
}
