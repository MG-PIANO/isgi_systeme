import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import type { Etudiant, Classe, Matiere, Note, Personnel } from '../types';

export interface LigneBulletin {
  matiere: Matiere;
  enseignantNom?: string;
  noteCC: number | null;
  noteExamen: number | null;
  noteFinale: number | null;
  points: number | null; // noteFinale * credits
  decision: 'Validé' | 'Ajourné' | 'Non évalué';
}

export interface DonneesBulletin {
  etudiant: Etudiant;
  classe: Classe;
  semestre: string;
  anneeAcademique: string;
  lignes: LigneBulletin[];
  totalCredits: number;
  totalCreditsValides: number;
  moyenneGenerale: number | null;
  rang: number | string;
  totalEtudiantsClasse: number;
  mention: string;
  appreciation: string;
}

export function getMention(moyenne: number | null): string {
  if (moyenne === null) return 'Non évalué';
  if (moyenne >= 16) return 'Très Bien';
  if (moyenne >= 14) return 'Bien';
  if (moyenne >= 12) return 'Assez Bien';
  if (moyenne >= 10) return 'Passable';
  return 'Ajourné';
}

export function getAppreciation(moyenne: number | null): string {
  if (moyenne === null) return 'Non évalué';
  if (moyenne >= 16) return 'Excellente performance académique. Félicitations du Conseil !';
  if (moyenne >= 14) return 'Très bon travail. Continuez avec la même rigueur.';
  if (moyenne >= 12) return 'Bon travail d’ensemble. Des progrès encore possibles.';
  if (moyenne >= 10) return 'Travail satisfaisant mais juste. Renforcez vos efforts.';
  return 'Résultats insuffisants. Répétez ou passez aux rattrapages.';
}

export async function exporterBulletinPDF(data: DonneesBulletin, filename?: string) {
  let appSettings: any = {};
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) appSettings = JSON.parse(saved);
  } catch (e) {}

  const nomEcole = (appSettings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
  const sigle = appSettings.sigle || "ISGI";
  const enTeteMessageHaut = appSettings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE";
  const enTeteDirection = appSettings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES & DE LA SCOLARITÉ";
  const enTeteSousTitre = appSettings.enTeteSousTitre || "Enseignement Supérieur Technique et Professionnel";

  const doc = new jsPDF('portrait');
  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  // 1. Logo et En-tête officiel
  try {
    const imgData = await fetch('./logo.jpg')
      .then(res => res.blob())
      .then(blob => new Promise<string>((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.readAsDataURL(blob);
      }));
    doc.addImage(imgData, 'JPEG', 14, 9, 20, 20);
  } catch (e) {
    console.warn('Logo load error', e);
  }

  // En-tête dynamique
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

  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(90, 100, 120);
  doc.text(enTeteSousTitre, 38, 26);

  // Ligne de séparation bleue
  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.8);
  doc.line(14, 31, pageWidth - 14, 31);

  // 2. Titre du Bulletin
  doc.setFillColor(243, 246, 253);
  doc.roundedRect(14, 37, pageWidth - 28, 11, 2, 2, 'F');
  doc.setFontSize(12);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(20, 40, 90);
  const titre = `BULLETIN OFFICIEL DE NOTES — ${data.semestre.toUpperCase()}`;
  const tw = doc.getStringUnitWidth(titre) * doc.getFontSize() / doc.internal.scaleFactor;
  doc.text(titre, (pageWidth - tw) / 2, 44.5);

  // 3. Fiche d'identification Étudiant & Classe
  doc.setFillColor(255, 255, 255);
  doc.setDrawColor(215, 225, 240);
  doc.setLineWidth(0.4);
  doc.roundedRect(14, 51, pageWidth - 28, 27, 2, 2, 'FD');

  doc.setFontSize(8.5);
  doc.setTextColor(90, 100, 120);
  doc.setFont('helvetica', 'bold');
  doc.text("NOM & PRÉNOM :", 18, 57);
  doc.text("MATRICULE :", 18, 63);
  doc.text("DATE DE NAISSANCE :", 18, 69);
  doc.text("CLASSE / GROUPE :", pageWidth / 2 + 5, 57);
  doc.text("FILIÈRE :", pageWidth / 2 + 5, 63);
  doc.text("ANNÉE ACADÉMIQUE :", pageWidth / 2 + 5, 69);

  doc.setTextColor(20, 30, 50);
  doc.setFont('helvetica', 'bold');
  doc.text(`${data.etudiant.nom.toUpperCase()} ${data.etudiant.prenom}`, 52, 57);
  doc.text(data.etudiant.matricule, 52, 63);
  doc.setFont('helvetica', 'normal');
  doc.text(data.etudiant.date_naissance ? new Date(data.etudiant.date_naissance).toLocaleDateString('fr-FR') : 'N/A', 52, 69);

  doc.text(data.classe.nom, pageWidth / 2 + 45, 57);
  doc.text(data.classe.filiere || data.etudiant.filiere || 'Informatique', pageWidth / 2 + 45, 63);
  doc.text(data.anneeAcademique, pageWidth / 2 + 45, 69);

  // 4. Tableau des matières
  const bodyRows = data.lignes.map(l => [
    l.matiere.code,
    l.matiere.nom,
    l.matiere.credits.toString(),
    l.matiere.coefficient.toString(),
    l.noteCC !== null ? l.noteCC.toFixed(2) : '-',
    l.noteExamen !== null ? l.noteExamen.toFixed(2) : '-',
    l.noteFinale !== null ? l.noteFinale.toFixed(2) : '-',
    l.decision,
    l.enseignantNom || 'Professeur'
  ]);

  autoTable(doc, {
    startY: 81,
    head: [[
      'Code',
      'Matière / Unité d’Enseignement',
      'Créd.',
      'Coeff.',
      'CC /20',
      'Exam /20',
      'Moy. /20',
      'Résultat',
      'Enseignant'
    ]],
    body: bodyRows,
    theme: 'grid',
    headStyles: {
      fillColor: [30, 64, 175],
      textColor: 255,
      fontSize: 8,
      fontStyle: 'bold',
      halign: 'center'
    },
    columnStyles: {
      0: { halign: 'center', cellWidth: 16 },
      1: { halign: 'left', cellWidth: 50 },
      2: { halign: 'center', cellWidth: 12 },
      3: { halign: 'center', cellWidth: 12 },
      4: { halign: 'center', cellWidth: 14 },
      5: { halign: 'center', cellWidth: 14 },
      6: { halign: 'center', cellWidth: 16, fontStyle: 'bold' },
      7: { halign: 'center', cellWidth: 16 },
      8: { halign: 'left' }
    },
    bodyStyles: {
      fontSize: 8,
      textColor: 40
    },
    alternateRowStyles: {
      fillColor: [248, 250, 253]
    },
    margin: { left: 14, right: 14 }
  });

  const finalY = (doc as any).lastAutoTable?.finalY || 160;

  // 5. Synthèse des résultats et délibération
  doc.setFillColor(243, 246, 253);
  doc.setDrawColor(215, 225, 240);
  doc.roundedRect(14, finalY + 5, pageWidth - 28, 36, 2, 2, 'FD');

  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text("BILAN DES RÉSULTATS ACADÉMIQUES :", 18, finalY + 12);

  doc.setFont('helvetica', 'normal');
  doc.setTextColor(40);
  doc.text(`• Total Crédits Inscrits : ${data.totalCredits}`, 18, finalY + 18);
  doc.text(`• Crédits Validés : ${data.totalCreditsValides} / ${data.totalCredits}`, 18, finalY + 23);
  doc.text(`• Rang de l'étudiant : ${data.rang} sur ${data.totalEtudiantsClasse} élèves`, 18, finalY + 28);
  doc.text(`• Décision du Conseil : ${data.moyenneGenerale && data.moyenneGenerale >= 10 ? 'ADMIS(E)' : 'AJOURNÉ(E)'}`, 18, finalY + 33);

  // Encadré Moyenne Générale
  doc.setFillColor(255, 255, 255);
  doc.roundedRect(pageWidth - 75, finalY + 9, 57, 28, 1.5, 1.5, 'FD');
  doc.setFontSize(7.5);
  doc.setTextColor(100, 110, 130);
  doc.text("MOYENNE GÉNÉRALE", pageWidth - 65, finalY + 15);

  doc.setFontSize(16);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(data.moyenneGenerale && data.moyenneGenerale >= 10 ? 30 : 180, data.moyenneGenerale && data.moyenneGenerale >= 10 ? 120 : 30, 40);
  const moyStr = data.moyenneGenerale !== null ? `${data.moyenneGenerale.toFixed(2)} / 20` : 'N/A';
  doc.text(moyStr, pageWidth - 65, finalY + 24);

  doc.setFontSize(8);
  doc.setTextColor(80);
  doc.text(`Mention : ${data.mention}`, pageWidth - 65, finalY + 32);

  // Appréciation
  doc.setFontSize(8);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(80);
  doc.text(`Appréciation : "${data.appreciation}"`, 14, finalY + 47);

  // 6. Signatures et Validation
  const sigY = finalY + 54;
  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(90);
  const ville = appSettings.ville || 'Brazzaville';
  doc.text(`Délivré à ${ville}, le ${new Date().toLocaleDateString('fr-FR')}`, 14, sigY);

  doc.setFont('helvetica', 'bold');
  doc.setTextColor(20, 40, 80);
  const titreDAC = appSettings.titreSignataire2 || "Le Directeur des Affaires Académiques (DAC)";
  doc.text(titreDAC, pageWidth - 85, sigY);
  doc.setFontSize(7.5);
  doc.setFont('helvetica', 'italic');
  doc.setTextColor(120);
  doc.text("(Cachet et Signature autorisée)", pageWidth - 85, sigY + 5);

  const fname = filename || `Bulletin_${data.etudiant.matricule}_${data.semestre}.pdf`;
  doc.save(fname);
}
