import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';

interface ColumnDef {
  header: string;
  dataKey: string;
}

export async function exportToPDF(
  title: string,
  columns: ColumnDef[],
  data: any[],
  filename: string
) {
  let appSettings: any = {};
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) appSettings = JSON.parse(saved);
  } catch (e) {}

  const nomEcole = (appSettings.nomEcole || "INSTITUT SUPÉRIEUR DE GESTION ET D'INGÉNIERIE").toUpperCase();
  const sigle = appSettings.sigle || "ISGI";
  const dacNom = appSettings.dacNom || "Directeur des Affaires Académiques";
  const enTeteMessageHaut = appSettings.enTeteMessageHaut || "RÉPUBLIQUE DE GUINÉE • MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE";
  const enTeteDirection = appSettings.enTeteDirection || "DIRECTION DES AFFAIRES ACADÉMIQUES & PÉDAGOGIE";
  const enTeteSousTitre = appSettings.enTeteSousTitre || "Enseignement Supérieur Technique, Professionnel et Managérial • Agréé par l'État";

  const doc = new jsPDF('landscape');
  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  const centerText = (text: string, y: number, size = 10, isBold = false) => {
    doc.setFontSize(size);
    doc.setFont('helvetica', isBold ? 'bold' : 'normal');
    const textWidth = (doc.getStringUnitWidth(text) * doc.getFontSize()) / doc.internal.scaleFactor;
    const x = (pageWidth - textWidth) / 2;
    doc.text(text, x, y);
  };

  try {
    const imgData = await fetch('./logo.jpg')
      .then(res => res.blob())
      .then(blob => new Promise<string>((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.readAsDataURL(blob);
      }));
    doc.addImage(imgData, 'JPEG', 14, 8, 20, 20);
  } catch (e) {
    console.warn('Failed to load logo', e);
  }

  // En-tête dynamique
  if (enTeteMessageHaut) {
    doc.setFontSize(7.5);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(100, 116, 139);
    doc.text(enTeteMessageHaut.toUpperCase(), 38, 11);
  }

  doc.setTextColor(24, 43, 73);
  doc.setFontSize(13);
  doc.setFont('helvetica', 'bold');
  doc.text(nomEcole, 38, 16);

  doc.setFontSize(9);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 64, 175);
  doc.text(enTeteDirection, 38, 21);

  doc.setFontSize(8.5);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(100, 110, 130);
  doc.text(`${enTeteSousTitre} • Année Académique : ${appSettings.anneeAcademique || '2025-2026'}`, 38, 26);

  // Ligne de séparation
  doc.setDrawColor(30, 64, 175);
  doc.setLineWidth(0.8);
  doc.line(14, 30, pageWidth - 14, 30);

  // Titre du document
  doc.setFillColor(240, 244, 250);
  doc.roundedRect(14, 38, pageWidth - 28, 12, 2, 2, 'F');
  doc.setFontSize(11);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(15, 40, 80);
  centerText(title.toUpperCase(), 46, 11, true);

  // Tableau
  autoTable(doc, {
    startY: 54,
    head: [columns.map(c => c.header)],
    body: data.map(row => columns.map(c => row[c.dataKey] ?? '')),
    theme: 'grid',
    headStyles: {
      fillColor: [30, 64, 175], // Bleu académique
      textColor: 255,
      fontSize: 9,
      fontStyle: 'bold',
      halign: 'center'
    },
    bodyStyles: {
      fontSize: 8.5,
      textColor: 40
    },
    alternateRowStyles: {
      fillColor: [248, 250, 253]
    },
    margin: { left: 14, right: 14 }
  });

  // Footer / Signatures
  const finalY = (doc as any).lastAutoTable?.finalY || 160;
  const sigY = Math.min(finalY + 15, pageHeight - 35);

  doc.setFontSize(9);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(80);
  doc.text(`Document généré le ${new Date().toLocaleDateString('fr-FR')} à ${new Date().toLocaleTimeString('fr-FR')}`, 14, sigY);

  doc.setFont('helvetica', 'bold');
  doc.text("Le Directeur des Affaires Académiques", pageWidth - 85, sigY);
  doc.setFont('helvetica', 'italic');
  doc.setFontSize(8);
  doc.text("(Signature et Cachet officiel)", pageWidth - 85, sigY + 6);

  doc.save(filename.endsWith('.pdf') ? filename : `${filename}.pdf`);
}
