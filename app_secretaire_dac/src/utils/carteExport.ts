import jsPDF from 'jspdf';
import type { Etudiant } from '../types';

export async function genererCarteEtudiantPDF(etudiant: Etudiant, filename?: string) {
  let appSettings: any = {};
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) appSettings = JSON.parse(saved);
  } catch (e) {}

  const nomEcole = appSettings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE";
  const sigle = appSettings.sigle || "ISGI";
  const annee = etudiant.annee_academique || appSettings.anneeAcademique || "2025-2026";

  // Format CR80 : 85.6 mm x 54 mm (orientation paysage)
  const doc = new jsPDF({
    orientation: 'landscape',
    unit: 'mm',
    format: [85.6, 54]
  });

  const width = 85.6;
  const height = 54;

  // 1. Fond de la carte (dégradé simulé / bordure)
  doc.setFillColor(255, 255, 255);
  doc.roundedRect(1, 1, width - 2, height - 2, 2.5, 2.5, 'F');
  doc.setDrawColor(210, 220, 235);
  doc.setLineWidth(0.4);
  doc.roundedRect(1, 1, width - 2, height - 2, 2.5, 2.5, 'S');

  // 2. En-tête bleu institutionnel
  doc.setFillColor(15, 45, 105); // #0f2d69 Bleu nuit ISGI
  doc.roundedRect(1, 1, width - 2, 13, 2.5, 2.5, 'F');
  // Couvrir les coins inférieurs du bandeau pour laisser droits
  doc.rect(1, 10, width - 2, 4, 'F');

  // Logo ISGI
  try {
    const imgData = await fetch('./logo.jpg')
      .then(res => res.blob())
      .then(blob => new Promise<string>((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.readAsDataURL(blob);
      }));
    doc.addImage(imgData, 'JPEG', 3, 2, 9, 9);
  } catch (e) {
    // Fallback texte logo
    doc.setFontSize(8);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(255, 255, 255);
    doc.text(sigle, 4, 7);
  }

  // Textes en-tête
  doc.setTextColor(255, 255, 255);
  doc.setFontSize(6.5);
  doc.setFont('helvetica', 'bold');
  doc.text(nomEcole.toUpperCase(), 14, 5.5);

  doc.setFontSize(5);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(200, 220, 255);
  doc.text("CARTE D'ÉTUDIANT OFFICIELLE", 14, 9);
  doc.text(`Année Académique : ${annee}`, 14, 12);

  // 3. Zone Photo (Gauche)
  const photoX = 4;
  const photoY = 16;
  const photoW = 22;
  const photoH = 27;

  doc.setFillColor(235, 240, 248);
  doc.roundedRect(photoX, photoY, photoW, photoH, 1.5, 1.5, 'F');
  doc.setDrawColor(180, 200, 230);
  doc.roundedRect(photoX, photoY, photoW, photoH, 1.5, 1.5, 'S');

  // Si l'étudiant a une photo URL, on essaie de la charger
  let photoAffichee = false;
  if (etudiant.photo_url) {
    try {
      const pData = await fetch(etudiant.photo_url)
        .then(res => res.blob())
        .then(blob => new Promise<string>((resolve) => {
          const r = new FileReader();
          r.onload = () => resolve(r.result as string);
          r.readAsDataURL(blob);
        }));
      doc.addImage(pData, 'JPEG', photoX, photoY, photoW, photoH);
      photoAffichee = true;
    } catch {}
  }

  if (!photoAffichee) {
    // Avatar généré avec initiales
    doc.setFillColor(30, 64, 175);
    doc.circle(photoX + photoW / 2, photoY + photoH / 2 - 2, 6, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(7.5);
    doc.setFont('helvetica', 'bold');
    const initials = `${(etudiant.prenom || '')[0] || ''}${(etudiant.nom || '')[0] || ''}`.toUpperCase();
    doc.text(initials, photoX + photoW / 2 - 2.5, photoY + photoH / 2 + 0.5);

    doc.setTextColor(100, 115, 140);
    doc.setFontSize(4);
    doc.setFont('helvetica', 'normal');
    doc.text("PHOTO", photoX + photoW / 2 - 4, photoY + photoH - 2);
  }

  // 4. Informations de l'étudiant (Droite)
  const infoX = 29;
  let currY = 18;

  // Badge Matricule
  doc.setFillColor(238, 244, 255);
  doc.roundedRect(infoX, currY - 2.5, 52, 4.5, 1, 1, 'F');
  doc.setTextColor(30, 64, 175);
  doc.setFontSize(5.5);
  doc.setFont('helvetica', 'bold');
  doc.text(`MATRICULE : ${etudiant.matricule}`, infoX + 2, currY + 0.5);

  currY += 5.5;

  // Nom & Prénom
  doc.setTextColor(20, 25, 35);
  doc.setFontSize(7.5);
  doc.setFont('helvetica', 'bold');
  const nomComplet = `${etudiant.nom.toUpperCase()} ${etudiant.prenom}`;
  doc.text(nomComplet.length > 26 ? nomComplet.substring(0, 25) + '...' : nomComplet, infoX, currY);

  currY += 4.5;

  // Lignes détails
  const printField = (label: string, value: string) => {
    doc.setFontSize(5);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(100, 110, 130);
    doc.text(label, infoX, currY);

    doc.setFont('helvetica', 'normal');
    doc.setTextColor(20, 30, 45);
    const valText = value || 'N/A';
    doc.text(valText.length > 28 ? valText.substring(0, 27) + '...' : valText, infoX + 16, currY);
    currY += 3.8;
  };

  printField("Né(e) le :", etudiant.date_naissance ? new Date(etudiant.date_naissance).toLocaleDateString('fr-FR') : 'N/A');
  printField("Niveau :", etudiant.niveau || 'Licence');
  printField("Filière :", etudiant.filiere || 'Génie Informatique');
  printField("Téléphone :", etudiant.telephone || 'N/A');

  // 5. Pied de carte & Sécurité
  doc.setDrawColor(220, 230, 245);
  doc.setLineWidth(0.3);
  doc.line(1, 46, width - 1, 46);

  doc.setFillColor(245, 248, 255);
  doc.rect(1, 46.2, width - 2, 6.8, 'F');

  doc.setFontSize(4.5);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text("VALIDITÉ : 1 AN", 4, 50.5);

  doc.setFont('helvetica', 'normal');
  doc.setTextColor(90, 100, 120);
  doc.text("La Direction des Affaires Académiques", width - 38, 50.5);

  // Sauvegarde
  const name = filename || `Carte_${etudiant.matricule}_${etudiant.nom}.pdf`;
  doc.save(name);
}

// Générer une planche de plusieurs cartes d'étudiants sur une feuille A4 (8 cartes par page)
export async function genererPlancheCartesPDF(etudiants: Etudiant[], nomFichier: string = "Planche_Cartes_Etudiants.pdf") {
  const doc = new jsPDF({
    orientation: 'portrait',
    unit: 'mm',
    format: 'a4'
  });

  const pageWidth = 210;
  const cardW = 85.6;
  const cardH = 54;
  const marginX = (pageWidth - (cardW * 2 + 10)) / 2; // centré
  const marginY = 15;
  const gapX = 10;
  const gapY = 12;

  let count = 0;
  let page = 1;

  for (let i = 0; i < etudiants.length; i++) {
    const etud = etudiants[i];
    const col = count % 2;
    const row = Math.floor(count / 2) % 4;

    if (count > 0 && count % 8 === 0) {
      doc.addPage();
      page++;
    }

    const x = marginX + col * (cardW + gapX);
    const y = marginY + row * (cardH + gapY);

    // Dessin miniature de la carte à la position (x, y)
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(x, y, cardW, cardH, 2, 2, 'F');
    doc.setDrawColor(180, 200, 225);
    doc.setLineWidth(0.3);
    doc.roundedRect(x, y, cardW, cardH, 2, 2, 'S');

    // Bandeau supérieur
    doc.setFillColor(15, 45, 105);
    doc.roundedRect(x, y, cardW, 11, 2, 2, 'F');
    doc.rect(x, y + 8, cardW, 3, 'F');

    doc.setTextColor(255, 255, 255);
    doc.setFontSize(6);
    doc.setFont('helvetica', 'bold');
    doc.text("ISGI - CARTE D'ÉTUDIANT", x + 4, y + 5);
    doc.setFontSize(4.5);
    doc.setFont('helvetica', 'normal');
    doc.text(`Année : ${etud.annee_academique || '2025-2026'}`, x + 4, y + 9);

    // Photo placeholder
    doc.setFillColor(235, 240, 250);
    doc.roundedRect(x + 4, y + 14, 18, 22, 1, 1, 'F');
    doc.setTextColor(80, 100, 130);
    doc.setFontSize(5);
    doc.text("PHOTO", x + 7, y + 25);

    // Textes
    doc.setTextColor(20, 30, 45);
    doc.setFontSize(6.5);
    doc.setFont('helvetica', 'bold');
    doc.text(`${etud.nom.toUpperCase()} ${etud.prenom}`.substring(0, 22), x + 25, y + 18);

    doc.setFontSize(5);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(80, 90, 110);
    doc.text(`Matricule : ${etud.matricule}`, x + 25, y + 23);
    doc.text(`Niveau : ${etud.niveau}`, x + 25, y + 27);
    doc.text(`Filière : ${(etud.filiere || '').substring(0, 24)}`, x + 25, y + 31);
    doc.text(`Tél : ${etud.telephone || 'N/A'}`, x + 25, y + 35);

    count++;
  }

  doc.save(nomFichier);
}
