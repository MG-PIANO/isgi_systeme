import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import type { CalendrierAcademique, EmploiDuTempsItem, Matiere, Personnel } from '../types/academic';

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

function getAppSettings(): any {
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) return JSON.parse(saved);
  } catch {}
  return {
    nomEcole: "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE",
    sigle: "ISGI",
    enTeteMessageHaut: "RÉPUBLIQUE DU CONGO • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR",
    enTeteDirection: "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)",
    enTeteSousTitre: "Enseignement Supérieur Technique, Professionnel et Managérial",
    titreSignataire1: "Le Secrétaire Général",
    nomSignataire1: "Direction Générale",
    titreSignataire2: "Le Directeur Académique (DAC)",
    nomSignataire2: "Direction Académique",
    mentionBasDePage: "Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI"
  };
}

// 1. EXPORT DU CALENDRIER ACADÉMIQUE EN PDF
export async function exporterCalendrierPDF(cal: CalendrierAcademique) {
  const settings = getAppSettings();
  const nomEcole = (settings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
  const enTeteMessageHaut = settings.enTeteMessageHaut || "RÉPUBLIQUE DU CONGO • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR";
  const enTeteDirection = settings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES ET DE LA PÉDAGOGIE (DAC)";
  const enTeteSousTitre = settings.enTeteSousTitre || "Enseignement Supérieur Technique, Professionnel et Managérial";

  const doc = new jsPDF({
    orientation: 'portrait',
    unit: 'mm',
    format: 'a4'
  });

  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  const logo = await loadLogoBase64();
  if (logo) {
    try {
      doc.addImage(logo, 'JPEG', 14, 9, 20, 20);
    } catch {}
  }

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

  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.8);
  doc.line(14, 34, pageWidth - 14, 34);

  doc.setFillColor(241, 245, 249);
  doc.roundedRect(14, 38, pageWidth - 28, 12, 2, 2, 'F');
  doc.setFontSize(12);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 23, 42);
  const docTitle = `CALENDRIER ACADÉMIQUE OFFICIEL — ${cal.semestre.toUpperCase()} (${cal.annee_academique})`;
  const tw = doc.getStringUnitWidth(docTitle) * doc.getFontSize() / doc.internal.scaleFactor;
  doc.text(docTitle, (pageWidth - tw) / 2, 45.5);

  const tableData = [
    ['Début officiel des enseignements (Cours)', formatDateFr(cal.date_debut_cours), 'Rentrée effective pédagogique'],
    ['Devoirs sur Table (DST)', `${formatDateFr(cal.date_debut_dst)} au ${formatDateFr(cal.date_fin_dst)}`, 'Évaluations continues'],
    ['Congé d’étude & Révisions', `${formatDateFr(cal.date_debut_conge_etude)} au ${formatDateFr(cal.date_fin_conge_etude)}`, 'Suspension des cours'],
    ['Session Principale d’Examens', `${formatDateFr(cal.date_debut_examens)} au ${formatDateFr(cal.date_fin_examens)}`, 'Épreuves écrites & orales'],
    ['Session de Rattrapage', `${formatDateFr(cal.date_debut_rattrapage)} au ${formatDateFr(cal.date_fin_rattrapage)}`, 'Seconde chance'],
    ['Fin officielle des cours', formatDateFr(cal.date_fin_cours), 'Clôture du semestre']
  ];

  autoTable(doc, {
    startY: 55,
    margin: { left: 14, right: 14 },
    head: [['Étape / Période Clé', 'Dates Calendaires Officielles', 'Observations']],
    body: tableData,
    headStyles: {
      fillColor: [30, 64, 175],
      textColor: 255,
      fontSize: 9,
      fontStyle: 'bold'
    },
    bodyStyles: {
      fontSize: 8.5,
      textColor: [30, 41, 59]
    },
    alternateRowStyles: {
      fillColor: [248, 250, 252]
    }
  });

  const finalY = (doc as any).lastAutoTable?.finalY || 160;

  doc.setFontSize(7.5);
  doc.setTextColor(100, 116, 139);
  doc.text(`Document officiel extrait le ${new Date().toLocaleDateString('fr-FR')} - Diffusion autorisée.`, 14, Math.min(finalY + 20, pageHeight - 15));

  doc.save(`Calendrier_Academique_${cal.semestre}_${cal.annee_academique}.pdf`);
}

// 2. EXPORT DE L'EMPLOI DU TEMPS EN PDF
export async function exporterEmploiDuTempsPDF(
  classeNom: string,
  semestre: string,
  coursList: EmploiDuTempsItem[],
  matieresMap: Map<string, Matiere>,
  enseignantsMap: Map<string, Personnel>,
  anneeAcademique: string = '2025-2026'
) {
  const settings = getAppSettings();
  const nomEcole = (settings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();

  const doc = new jsPDF({
    orientation: 'landscape',
    unit: 'mm',
    format: 'a4'
  });

  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  const logo = await loadLogoBase64();
  if (logo) {
    try {
      doc.addImage(logo, 'JPEG', 14, 8, 18, 18);
    } catch {}
  }

  doc.setTextColor(20, 45, 95);
  doc.setFontSize(13);
  doc.setFont('helvetica', 'bold');
  doc.text(nomEcole, 36, 14);

  doc.setFontSize(9);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text("DIRECTION DES AFFAIRES ACADÉMIQUES • EMPLOI DU TEMPS OFFICIEL", 36, 19);

  doc.setFont('helvetica', 'normal');
  doc.setTextColor(100, 116, 139);
  doc.text(`Classe : ${classeNom}  |  Semestre : ${semestre}  |  Année Académique : ${anneeAcademique}`, 36, 24);

  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.6);
  doc.line(14, 28, pageWidth - 14, 28);

  const jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
  const creneaux = [
    { start: '08:00', end: '10:00', label: '08h00 - 10h00' },
    { start: '10:15', end: '12:15', label: '10h15 - 12h15' },
    { start: '14:00', end: '16:00', label: '14h00 - 16h00' },
    { start: '16:15', end: '18:15', label: '16h15 - 18h15' }
  ];

  const tableHead = ['Jour', ...creneaux.map(c => c.label)];
  const tableBody = jours.map(j => {
    const row = [j];
    creneaux.forEach(c => {
      const match = coursList.find(cr =>
        cr.jour_semaine === j &&
        cr.heure_debut === c.start
      );
      if (match) {
        const mat = matieresMap.get(match.matiere_id);
        const ens = match.enseignant_id ? enseignantsMap.get(match.enseignant_id) : null;
        const matName = mat ? (mat.code ? `${mat.code} - ${mat.nom}` : mat.nom) : 'Cours';
        const ensName = ens ? `${ens.nom} ${ens.prenom}` : '';
        row.push(`${matName}\n${ensName ? `Prof: ${ensName}\n` : ''}Salle: ${match.salle}`);
      } else {
        row.push('—');
      }
    });
    return row;
  });

  autoTable(doc, {
    startY: 32,
    margin: { left: 14, right: 14 },
    head: [tableHead],
    body: tableBody,
    headStyles: {
      fillColor: [30, 64, 175],
      textColor: 255,
      fontSize: 8.5,
      fontStyle: 'bold',
      halign: 'center'
    },
    bodyStyles: {
      fontSize: 7.5,
      valign: 'middle',
      cellPadding: 3
    },
    columnStyles: {
      0: { fontStyle: 'bold', halign: 'center', cellWidth: 26, fillColor: [241, 245, 249] }
    },
    theme: 'grid'
  });

  const finalY = (doc as any).lastAutoTable?.finalY || 160;
  doc.setFontSize(7.5);
  doc.setTextColor(100, 116, 139);
  doc.text(`Document officiel certifié conforme par la Direction des Affaires Académiques - ISGI. Extrait le ${new Date().toLocaleDateString('fr-FR')}`, 14, Math.min(finalY + 12, pageHeight - 10));

  doc.save(`Emploi_du_Temps_${classeNom.replace(/\s+/g, '_')}_${semestre}.pdf`);
}
