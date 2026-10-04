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
  
  const nomEcole = appSettings.nomEcole || "Institut Supérieur de Gestion et d'Ingénierie";
  const sigle = appSettings.sigle || "ISGI";
  const nomComptable = appSettings.nomComptable || "Le Comptable";
  const nomDG = appSettings.nomDG || "Le Directeur Général";

  // Create a new PDF document (A4, landscape usually better for tables)
  const doc = new jsPDF('landscape');
  const pageWidth = doc.internal.pageSize.width;
  const pageHeight = doc.internal.pageSize.height;

  // Center alignment helper
  const centerText = (text: string, y: number, size = 10, isBold = false) => {
    doc.setFontSize(size);
    doc.setFont('helvetica', isBold ? 'bold' : 'normal');
    const textWidth = doc.getStringUnitWidth(text) * doc.getFontSize() / doc.internal.scaleFactor;
    const x = (pageWidth - textWidth) / 2;
    doc.text(text, x, y);
  };

  try {
    // Load logo
    const imgData = await fetch('./logo.jpg').then(res => res.blob()).then(blob => new Promise<string>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result as string);
      reader.readAsDataURL(blob);
    }));
    doc.addImage(imgData, 'JPEG', pageWidth / 2 - 12, 10, 24, 24);
  } catch (e) {
    console.warn('Failed to load logo', e);
  }

  doc.setTextColor(0, 0, 0);
  centerText(nomEcole, 42, 18, true);
  
  doc.setTextColor(100, 100, 100);
  centerText(sigle, 48, 12, true);

  // Border and Title block
  doc.setDrawColor(200, 200, 200);
  doc.setLineWidth(0.5);
  doc.line(pageWidth / 4, 55, (pageWidth * 3) / 4, 55);
  
  doc.setTextColor(0, 74, 198); // primary blue
  centerText(title.toUpperCase(), 62, 14, true);

  doc.line(pageWidth / 4, 66, (pageWidth * 3) / 4, 66);

  // Metadatas
  doc.setTextColor(100, 100, 100);
  doc.setFontSize(10);
  doc.setFont('helvetica', 'normal');
  doc.text(`Édité le : ${new Date().toLocaleDateString('fr-FR')}`, 14, 76);
  doc.text('Département : Comptabilité & Finances', pageWidth - 14, 76, { align: 'right' });

  // Map the data for the table
  const tableData = data.map(item => {
    const rowData: any = {};
    columns.forEach(col => {
      let val = item[col.dataKey] !== undefined && item[col.dataKey] !== null 
        ? String(item[col.dataKey]) 
        : '';
      // Remplacer les espaces insécables de toLocaleString par des espaces normaux pour jsPDF
      val = val.replace(/[\u202f\u00a0]/g, ' ');
      rowData[col.dataKey] = val;
    });
    return rowData;
  });

  // Generate the table
  autoTable(doc, {
    startY: 82,
    columns: columns,
    body: tableData,
    theme: 'striped',
    headStyles: {
      fillColor: [33, 150, 243], // Primary blue color
      textColor: [255, 255, 255],
      fontStyle: 'bold',
    },
    styles: {
      fontSize: 9,
      cellPadding: 4,
    },
    alternateRowStyles: {
      fillColor: [245, 247, 250],
    },
    margin: { top: 82 },
    didDrawPage: function (data) {
      // Add page number at the bottom
      const pageCount = (doc.internal as any).pages.length - 1;
      doc.setFontSize(8);
      doc.setTextColor(150, 150, 150);
      doc.text(
        `Page ${data.pageNumber} / ${pageCount}`,
        pageWidth - 20,
        pageHeight - 10,
        { align: 'right' }
      );
    }
  });

  // Add signature on the very last page
  const finalY = (doc as any).lastAutoTable.finalY || 80;
  
  // Si on est trop proche de la fin de page, ajouter une page
  let sigY = finalY + 20;
  if (sigY > pageHeight - 40) {
    doc.addPage();
    sigY = 30;
  }
  
  doc.setFontSize(10);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(0, 0, 0);
  doc.text(nomComptable, pageWidth / 4, sigY, { align: 'center' });
  doc.text(nomDG, (pageWidth * 3) / 4, sigY, { align: 'center' });
  
  doc.setDrawColor(150, 150, 150);
  // jsPDF supports setLineDash
  const docAny = doc as any;
  if (typeof docAny.setLineDashPattern === 'function') {
    docAny.setLineDashPattern([2, 2], 0);
  } else if (typeof docAny.setLineDash === 'function') {
    docAny.setLineDash([2, 2], 0);
  }
  doc.line(pageWidth / 4 - 20, sigY + 25, pageWidth / 4 + 20, sigY + 25);
  doc.line((pageWidth * 3) / 4 - 20, sigY + 25, (pageWidth * 3) / 4 + 20, sigY + 25);
  
  // reset dash
  if (typeof docAny.setLineDashPattern === 'function') {
    docAny.setLineDashPattern([], 0);
  } else if (typeof docAny.setLineDash === 'function') {
    docAny.setLineDash([], 0);
  }

  // Save the PDF
  doc.save(`${filename}_${new Date().getTime()}.pdf`);
}

export function exportReceiptToPDF(paiement: any, etudiant: any) {
  let appSettings: any = {};
  try {
    const saved = localStorage.getItem('isgi_settings');
    if (saved) appSettings = JSON.parse(saved);
  } catch (e) {}
  
  const nomEcole = appSettings.nomEcole || "Institut Supérieur de Gestion et d'Ingénierie";
  const sigle = appSettings.sigle || "ISGI";

  // Format: [width, height] in mm. 80mm is standard thermal receipt width.
  const doc = new jsPDF({
    orientation: 'portrait',
    unit: 'mm',
    format: [80, 150] 
  });

  // Center alignment helper
  const centerText = (text: string, y: number, size = 10, isBold = false) => {
    doc.setFontSize(size);
    doc.setFont("helvetica", isBold ? "bold" : "normal");
    const textWidth = doc.getStringUnitWidth(text) * doc.getFontSize() / doc.internal.scaleFactor;
    const x = (80 - textWidth) / 2;
    doc.text(text, x, y);
  };

  // Header
  centerText(sigle, 10, 16, true);
  
  // Si le nom de l'école est très long, on peut simplement le réduire pour le ticket de caisse
  doc.setFontSize(8);
  doc.setFont("helvetica", "normal");
  const splitTitle = doc.splitTextToSize(nomEcole, 70);
  let titleY = 15;
  splitTitle.forEach((line: string) => {
    centerText(line, titleY, 8);
    titleY += 4;
  });
  
  centerText("Brazzaville, Congo", titleY, 8);
  
  doc.setLineWidth(0.5);
  doc.line(5, titleY + 3, 75, titleY + 3);
  centerText("RECU DE PAIEMENT", titleY + 8, 10, true);

  // Content
  doc.setFontSize(9);
  doc.setFont("helvetica", "normal");
  
  let y = titleY + 17;
  const lineSpacing = 6;
  
  const dateStr = new Date(paiement.created_at || paiement.last_modified_at || 0).toLocaleDateString('fr-FR');
  
  doc.text(`Date :`, 5, y); doc.text(dateStr, 75, y, { align: 'right' }); y += lineSpacing;
  doc.text(`Ref :`, 5, y); doc.text(paiement.reference_transaction || 'N/A', 75, y, { align: 'right' }); y += lineSpacing;
  doc.text(`Type :`, 5, y); doc.text(paiement.type_paiement || 'Frais Scolaire', 75, y, { align: 'right' }); y += lineSpacing;
  doc.text(`Mode :`, 5, y); doc.text(paiement.mode_paiement, 75, y, { align: 'right' }); y += lineSpacing;

  doc.line(5, y, 75, y); y += 4;
  
  doc.setFont("helvetica", "bold");
  doc.text(`ETUDIANT :`, 5, y); doc.text(etudiant.matricule, 75, y, { align: 'right' }); y += lineSpacing;
  doc.setFont("helvetica", "normal");
  doc.text(`${etudiant.nom} ${etudiant.prenom}`, 75, y, { align: 'right' }); y += lineSpacing + 2;

  doc.line(5, y, 75, y); y += 5;
  
  doc.setFontSize(12);
  doc.setFont("helvetica", "bold");
  doc.text(`MONTANT PAYE :`, 5, y); 
  const montantStr = `${Number(paiement.montant).toLocaleString('fr-FR')} F`.replace(/[\u202f\u00a0]/g, ' ');
  doc.text(montantStr, 75, y, { align: 'right' }); y += 10;

  doc.line(5, y, 75, y); y += 6;

  // Footer
  doc.setFontSize(8);
  doc.setFont("helvetica", "normal");
  doc.text("Signature / Cachet", 5, y);
  
  y += 20;
  centerText("Merci de votre confiance.", y, 8, true);
  centerText("Document generé automatiquement.", y + 4, 7);

  doc.save(`Recu_${etudiant.matricule}_${paiement.id}.pdf`);
}
