import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import type { CalendrierAcademique, EmploiDuTempsItem, Matiere, Personnel } from '../types';

// Helper pour charger l'image du logo ISGI
async function loadLogoBase64(): Promise<string | null> {
  try {
    const res = await fetch('./logo.jpg');
    const blob = await res.blob();
    return new Promise<string>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result as string);
      reader.readAsDataURL(blob);
    });
  } catch (e) {
    console.warn('Impossible de charger le logo ISGI:', e);
    return null;
  }
}

// Helper pour formater les dates en français
function formatDateFr(dStr?: string): string {
  if (!dStr) return '-';
  try {
    const d = new Date(dStr);
    if (isNaN(d.getTime())) return dStr;
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
  } catch {
    return dStr;
  }
}

// Helper pour récupérer les paramètres configurés
function getAppSettings(): any {
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) return JSON.parse(saved);
  } catch {}
  return {
    nomEcole: "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE",
    sigle: "ISGI",
    enTeteMessageHaut: "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE",
    enTeteDirection: "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)",
    enTeteSousTitre: "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État",
    titreSignataire1: "Le Secrétaire Général",
    nomSignataire1: "Dr. A. KOUAME",
    titreSignataire2: "Le Directeur Académique (DAC)",
    nomSignataire2: "Prof. M. DIALLO",
    mentionBasDePage: "Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI"
  };
}

// ==============================================================================
// 1. EXPORT DU CALENDRIER ACADÉMIQUE EN PDF (Format A4 Portrait Officiel)
// ==============================================================================
export async function exporterCalendrierPDF(cal: CalendrierAcademique) {
  const settings = getAppSettings();
  const nomEcole = (settings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
  const sigle = settings.sigle || "ISGI";
  const enTeteMessageHaut = settings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE";
  const enTeteDirection = settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)";
  const enTeteSousTitre = settings.enTeteSousTitre || "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État";

  const doc = new jsPDF({
    orientation: 'portrait',
    unit: 'mm',
    format: 'a4'
  });

  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  // 1. Logo officiel
  const logo = await loadLogoBase64();
  if (logo) {
    try {
      doc.addImage(logo, 'JPEG', 14, 9, 20, 20);
    } catch {}
  }

  // 2. En-tête institutionnel dynamique configuré dans les Paramètres
  if (enTeteMessageHaut) {
    doc.setFontSize(7.5);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(100, 116, 139);
    doc.text(enTeteMessageHaut.toUpperCase(), 38, 11.5);
  }

  doc.setTextColor(20, 45, 95);
  doc.setFontSize(12.5);
  doc.setFont('helvetica', 'bold');
  doc.text(nomEcole, 38, 16.5);

  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(enTeteDirection, 38, 21.5);

  doc.setFont('helvetica', 'normal');
  doc.setTextColor(100, 116, 139);
  doc.text(enTeteSousTitre, 38, 26);
  doc.text(`Année Académique : ${cal.annee_academique} | Cycle Universitaire LMD`, 38, 30);

  // Ligne de séparation bleue institutionnelle
  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.8);
  doc.line(14, 34, pageWidth - 14, 34);

  // 3. Cartouche du Document : Titre Officiel
  doc.setFillColor(241, 245, 249);
  doc.roundedRect(14, 38, pageWidth - 28, 12, 2, 2, 'F');
  doc.setFontSize(12);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);
  const docTitle = `CALENDRIER ACADÉMIQUE OFFICIEL — ${cal.semestre.toUpperCase()} (${cal.annee_academique})`;
  const tw = doc.getStringUnitWidth(docTitle) * doc.getFontSize() / doc.internal.scaleFactor;
  doc.text(docTitle, (pageWidth - tw) / 2, 45.5);

  // Sous-titre rentrée et statut
  doc.setFontSize(9);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(71, 85, 105);
  const subTitle = `Rentrée : ${cal.type_rentree || 'Principale'} • Statut : ${cal.statut === 'en_cours' ? 'EN COURS (ACTIF)' : cal.statut === 'termine' ? 'CLÔTURÉ' : 'PRÉVISIONNEL / VALIDÉ'} • Document Public`;
  const stw = doc.getStringUnitWidth(subTitle) * doc.getFontSize() / doc.internal.scaleFactor;
  doc.text(subTitle, (pageWidth - stw) / 2, 48.5);

  // 4. Tableau des Étapes Pédagogiques
  const etapesData = [
    [
      '1. Période des Enseignements',
      `${formatDateFr(cal.date_debut_cours)} au ${formatDateFr(cal.date_fin_cours)}`,
      cal.date_reprise_cours ? `Reprise officielle des cours : ${formatDateFr(cal.date_reprise_cours)}` : 'Cours magistraux, travaux dirigés et travaux pratiques'
    ],
    [
      '2. Devoirs Sur Table (DST)',
      `${formatDateFr(cal.date_debut_dst)} au ${formatDateFr(cal.date_fin_dst)}`,
      'Première évaluation continue obligatoire (CC 1)'
    ],
    [
      '3. Devoirs de Recherche & Projets',
      `${formatDateFr(cal.date_debut_recherche)} au ${formatDateFr(cal.date_fin_recherche)}`,
      'Mini-mémoires, études de cas et soutenances de projets (CC 2)'
    ],
    [
      '4. Congés d\'Études / Révisions',
      `${formatDateFr(cal.date_debut_conge_etude)} au ${formatDateFr(cal.date_fin_conge_etude)}`,
      'Arrêt des enseignements pour préparation exclusive aux examens'
    ],
    [
      '5. Examens Semestriels',
      `${formatDateFr(cal.date_debut_examens)} au ${formatDateFr(cal.date_fin_examens)}`,
      'Épreuves écrites et pratiques finales de la session normale'
    ],
    [
      '6. Session de Rattrapage',
      `${formatDateFr(cal.date_debut_rattrapage)} au ${formatDateFr(cal.date_fin_rattrapage)}`,
      'Seconde chance pour les étudiants n\'ayant pas validé les crédits'
    ]
  ];

  if (cal.date_debut_stage && cal.date_fin_stage) {
    etapesData.push([
      '7. Période des Stages Professionnels',
      `${formatDateFr(cal.date_debut_stage)} au ${formatDateFr(cal.date_fin_stage)}`,
      'Immersion en entreprise et élaboration du rapport de stage'
    ]);
  }

  autoTable(doc, {
    startY: 53,
    head: [['Phase / Étape Pédagogique', 'Période Officielle', 'Description & Directives']],
    body: etapesData,
    theme: 'grid',
    headStyles: {
      fillColor: [30, 64, 175],
      textColor: [255, 255, 255],
      fontStyle: 'bold',
      fontSize: 9,
      halign: 'left'
    },
    styles: {
      fontSize: 8.5,
      cellPadding: 3.5,
      lineColor: [203, 213, 225],
      lineWidth: 0.2
    },
    columnStyles: {
      0: { fontStyle: 'bold', cellWidth: 55, textColor: [15, 23, 42] },
      1: { cellWidth: 60, fontStyle: 'bold', textColor: [30, 64, 175] },
      2: { textColor: [51, 65, 85] }
    }
  });

  let currentY = (doc as any).lastAutoTable.finalY + 6;

  // 5. Tableau des événements spéciaux si existants
  if (cal.evenements_speciaux && cal.evenements_speciaux.length > 0) {
    const eventsData = cal.evenements_speciaux.map((ev, i) => [
      `${i + 1}. ${ev.titre}`,
      formatDateFr(ev.date),
      ev.type === 'fete' ? 'Fête / Férié' : ev.type === 'pedagogique' ? 'Pédagogique' : ev.type === 'ceremonie' ? 'Cérémonie' : ev.type === 'reunion' ? 'Réunion' : 'Événement',
      ev.description || '-'
    ]);

    autoTable(doc, {
      startY: currentY,
      head: [['Événements Particuliers & Jours Fériés', 'Date', 'Type', 'Détails']],
      body: eventsData,
      theme: 'grid',
      headStyles: {
        fillColor: [71, 85, 105],
        textColor: [255, 255, 255],
        fontStyle: 'bold',
        fontSize: 8.5
      },
      styles: {
        fontSize: 8,
        cellPadding: 2.8,
        lineColor: [203, 213, 225],
        lineWidth: 0.2
      },
      columnStyles: {
        0: { fontStyle: 'bold', cellWidth: 65 },
        1: { cellWidth: 40, fontStyle: 'bold', textColor: [30, 64, 175] },
        2: { cellWidth: 35 },
        3: { textColor: [51, 65, 85] }
      }
    });

    currentY = (doc as any).lastAutoTable.finalY + 6;
  }

  // 6. Dispositions particulières / Observations
  if (cal.observations) {
    doc.setFillColor(248, 250, 252);
    doc.setDrawColor(203, 213, 225);
    doc.setLineWidth(0.3);
    doc.roundedRect(14, currentY, pageWidth - 28, 14, 1.5, 1.5, 'FD');

    doc.setFontSize(8);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(30, 64, 175);
    doc.text("Dispositions Particulières & Recommandations :", 18, currentY + 5);

    doc.setFont('helvetica', 'italic');
    doc.setTextColor(71, 85, 105);
    doc.text(cal.observations, 18, currentY + 10);

    currentY += 18;
  }

  // S'assurer qu'il y a assez d'espace pour les signatures, sinon nouvelle page
  if (currentY > pageHeight - 45) {
    doc.addPage();
    currentY = 20;
  }

  // 7. Signatures Officielles
  currentY += 4;
  doc.setDrawColor(203, 213, 225);
  doc.setLineWidth(0.4);
  doc.line(14, currentY, pageWidth - 14, currentY);

  currentY += 6;
  doc.setFontSize(9);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);

  // Colonne 1 : Secrétaire Général
  const signataire1Titre = settings.titreSignataire1 || 'Le Secrétaire Général';
  const signataire1Nom = settings.nomSignataire1 || 'Dr. A. KOUAME';
  doc.text(signataire1Titre, 35, currentY, { align: 'center' });
  doc.setFontSize(8);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(148, 163, 184);
  doc.text("(Cachet et Signature autorisée)", 35, currentY + 16, { align: 'center' });
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(signataire1Nom, 35, currentY + 22, { align: 'center' });

  // Colonne 2 : Directeur Académique (DAC)
  const signataire2Titre = settings.titreSignataire2 || 'Le Directeur Académique (DAC)';
  const signataire2Nom = settings.nomSignataire2 || 'Prof. M. DIALLO';
  doc.setFontSize(9);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);
  doc.text(signataire2Titre, pageWidth - 45, currentY, { align: 'center' });
  doc.setFontSize(8);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(148, 163, 184);
  doc.text("(Cachet et Signature autorisée)", pageWidth - 45, currentY + 16, { align: 'center' });
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(signataire2Nom, pageWidth - 45, currentY + 22, { align: 'center' });

  // Mention de bas de page officielle
  if (settings.mentionBasDePage) {
    doc.setFontSize(7.5);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(148, 163, 184);
    doc.text(settings.mentionBasDePage, pageWidth / 2, pageHeight - 8, { align: 'center' });
  }

  // Téléchargement
  const filename = `Calendrier_Academique_${cal.annee_academique}_${cal.semestre}.pdf`.replace(/\s+/g, '_');
  doc.save(filename);
}

// ==============================================================================
// 2. EXPORT DE L'EMPLOI DU TEMPS EN PDF (Format A4 Paysage Professionnel)
// ==============================================================================
export async function exporterEmploiDuTempsPDF(
  classeNom: string,
  semestre: string,
  anneeAcademique: string,
  coursList: EmploiDuTempsItem[],
  matieres: Matiere[],
  personnel: Personnel[]
) {
  const settings = getAppSettings();
  const nomEcole = (settings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
  const sigle = settings.sigle || "ISGI";
  const enTeteMessageHaut = settings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE";
  const enTeteDirection = settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)";
  const enTeteSousTitre = settings.enTeteSousTitre || "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État";

  const doc = new jsPDF({
    orientation: 'landscape',
    unit: 'mm',
    format: 'a4'
  });

  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  // 1. Logo officiel
  const logo = await loadLogoBase64();
  if (logo) {
    try {
      doc.addImage(logo, 'JPEG', 14, 6.5, 18, 18);
    } catch {}
  }

  // 2. En-tête institutionnel dynamique
  if (enTeteMessageHaut) {
    doc.setFontSize(7);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(100, 116, 139);
    doc.text(enTeteMessageHaut.toUpperCase(), 35, 9.5);
  }

  doc.setTextColor(20, 45, 95);
  doc.setFontSize(12);
  doc.setFont('helvetica', 'bold');
  doc.text(nomEcole, 35, 14.5);

  doc.setFontSize(8);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(enTeteDirection, 35, 19);

  doc.setFont('helvetica', 'normal');
  doc.setTextColor(100, 116, 139);
  doc.text(`${enTeteSousTitre} • ${semestre} (${anneeAcademique})`, 35, 23);

  // Date d'édition à droite
  doc.setFontSize(7.5);
  doc.setTextColor(100, 116, 139);
  doc.text(`Document officiel généré le : ${new Date().toLocaleDateString('fr-FR')}`, pageWidth - 14, 14.5, { align: 'right' });
  doc.text(`Classe : ${classeNom.toUpperCase()}`, pageWidth - 14, 19, { align: 'right' });

  // Ligne de séparation bleue
  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.8);
  doc.line(14, 26, pageWidth - 14, 26);

  // 3. Grille des Horaires et Jours (Lundi au Samedi)
  const jours: ('Lundi' | 'Mardi' | 'Mercredi' | 'Jeudi' | 'Vendredi' | 'Samedi')[] = [
    'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'
  ];

  const creneaux = [
    { start: '08:00', end: '10:00', label: '08h00 - 10h00' },
    { start: '10:15', end: '12:15', label: '10h15 - 12h15' },
    { start: '12:15', end: '14:00', label: '12h15 - 14h00\n(Pause Déjeuner)' },
    { start: '14:00', end: '16:00', label: '14h00 - 16h00' },
    { start: '16:15', end: '18:15', label: '16h15 - 18h15' }
  ];

  const matiereMap = new Map(matieres.map(m => [m.id, m]));
  const personnelMap = new Map(personnel.map(p => [p.id, p]));

  // Helper pour trouver le cours correspondant
  const findCourse = (jour: string, start: string) => {
    return coursList.find(c => c.jour_semaine === jour && c.heure_debut === start);
  };

  const tableRows = creneaux.map(cr => {
    if (cr.start === '12:15') {
      return [
        cr.label,
        { content: 'PAUSE PÉDAGOGIQUE ET DÉJEUNER', colSpan: 6, styles: { halign: 'center', fillColor: [241, 245, 249], textColor: [100, 116, 139], fontStyle: 'bold' } }
      ];
    }

    const row: any[] = [cr.label];
    jours.forEach(j => {
      const c = findCourse(j, cr.start);
      if (c) {
        const mat = matiereMap.get(c.matiere_id)?.nom || 'Cours';
        const prof = personnelMap.get(c.enseignant_id || '') ? `${personnelMap.get(c.enseignant_id!)!.nom}` : '';
        const type = c.type_cours || 'CM';
        const salle = c.salle || 'Salle';
        row.push(`${mat}\n[${type}] • ${salle}\n${prof ? 'Prof. ' + prof : ''}`);
      } else {
        row.push('-');
      }
    });
    return row;
  });

  autoTable(doc, {
    startY: 31,
    head: [['Horaires', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']],
    body: tableRows,
    theme: 'grid',
    headStyles: {
      fillColor: [30, 64, 175],
      textColor: [255, 255, 255],
      fontStyle: 'bold',
      fontSize: 8.5,
      halign: 'center'
    },
    styles: {
      fontSize: 7.5,
      cellPadding: 3,
      valign: 'middle',
      halign: 'center',
      lineColor: [203, 213, 225],
      lineWidth: 0.2
    },
    columnStyles: {
      0: { fontStyle: 'bold', cellWidth: 32, fillColor: [248, 250, 252], textColor: [30, 64, 175] },
      1: { cellWidth: 39 },
      2: { cellWidth: 39 },
      3: { cellWidth: 39 },
      4: { cellWidth: 39 },
      5: { cellWidth: 39 },
      6: { cellWidth: 39 }
    }
  });

  const finalY = (doc as any).lastAutoTable.finalY + 8;

  // 4. Signatures Officielles en bas
  const signataire1Titre = settings.titreSignataire1 || 'Le Secrétaire Général';
  const signataire1Nom = settings.nomSignataire1 || 'Dr. A. KOUAME';
  const signataire2Titre = settings.titreSignataire2 || 'Le Directeur Académique (DAC)';
  const signataire2Nom = settings.nomSignataire2 || 'Prof. M. DIALLO';

  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);

  // Colonne gauche
  doc.text(signataire1Titre, 45, finalY, { align: 'center' });
  doc.setFontSize(7.5);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(148, 163, 184);
  doc.text("(Cachet et Signature autorisée)", 45, finalY + 12, { align: 'center' });
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(signataire1Nom, 45, finalY + 17, { align: 'center' });

  // Colonne droite
  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);
  doc.text(signataire2Titre, pageWidth - 45, finalY, { align: 'center' });
  doc.setFontSize(7.5);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(148, 163, 184);
  doc.text("(Cachet et Signature autorisée)", pageWidth - 45, finalY + 12, { align: 'center' });
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(signataire2Nom, pageWidth - 45, finalY + 17, { align: 'center' });

  // Mention de bas de page officielle
  if (settings.mentionBasDePage) {
    doc.setFontSize(7);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(148, 163, 184);
    doc.text(settings.mentionBasDePage, pageWidth / 2, pageHeight - 6, { align: 'center' });
  }

  // Téléchargement
  const filename = `Emploi_du_Temps_${classeNom.replace(/\s+/g, '_')}_${semestre}.pdf`;
  doc.save(filename);
}
