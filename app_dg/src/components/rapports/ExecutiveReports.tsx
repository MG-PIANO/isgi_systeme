import { useState } from 'react';
import { 
  FileText, 
  Download, 
  Printer, 
  Calendar, 
  ShieldCheck, 
  CheckCircle2, 
  Building2,
  TrendingUp,
  Users
} from 'lucide-react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';

export function ExecutiveReports() {
  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []) || [];
  const paiements = useLiveQuery(() => db.paiements.toArray(), []) || [];
  const depenses = useLiveQuery(() => db.depenses.toArray(), []) || [];

  const [dateDebut, setDateDebut] = useState('');
  const [dateFin, setDateFin] = useState('');

  // Calculs
  const totalEncaisse = paiements
    .filter(p => p.statut === 'Réussi' || !p.statut)
    .reduce((sum, p) => sum + (Number(p.montant) || 0), 0);

  const totalDepenses = depenses
    .filter(d => d.statut === 'Approuvée')
    .reduce((sum, d) => sum + (Number(d.montant) || 0), 0);

  const solde = totalEncaisse - totalDepenses;

  // Exporter Bilan Financier Officiel PDF
  const handleExportBilanFinancier = () => {
    const doc = new jsPDF();
    const dateStr = new Date().toLocaleDateString('fr-FR');

    // En-tête
    doc.setFontSize(16);
    doc.setTextColor(2, 132, 199);
    doc.text('INSTITUT SOUS-RÉGIONAL DU GABON (ISGI)', 14, 20);
    
    doc.setFontSize(10);
    doc.setTextColor(71, 85, 105);
    doc.text('DIRECTION GÉNÉRALE • RAPPORT EXÉCUTIF STRATÉGIQUE', 14, 26);
    doc.text(`Édité le : ${dateStr} par le Directeur Général`, 14, 32);

    doc.setLineWidth(0.5);
    doc.setDrawColor(203, 213, 225);
    doc.line(14, 36, 196, 36);

    // Titre
    doc.setFontSize(14);
    doc.setTextColor(15, 23, 42);
    doc.text('BILAN FINANCIER & SITUATION DE TRÉSORERIE', 14, 46);

    // Tableau Récapitulatif Global
    autoTable(doc, {
      startY: 52,
      head: [['Indicateur Clé', 'Montant (FCFA)', 'Statut']],
      body: [
        ['Total Recouvrements Scolarités & Inscriptions', `${totalEncaisse.toLocaleString('fr-FR')} FCFA`, 'Encaissé'],
        ['Total Décaissements & Charges Autorisées', `${totalDepenses.toLocaleString('fr-FR')} FCFA`, 'Décaissé'],
        ['Solde Net de Trésorerie Disponible', `${solde.toLocaleString('fr-FR')} FCFA`, solde >= 0 ? 'Excédentaire' : 'Déficitaire'],
      ],
      theme: 'grid',
      headStyles: { fillColor: [2, 132, 199], textColor: 255, fontStyle: 'bold' },
      styles: { fontSize: 10 }
    });

    // Tableau des Dépenses Récentes
    const lastY = (doc as any).lastAutoTable.finalY + 14;
    doc.setFontSize(12);
    doc.text('Détail des Dernières Dépenses Autorisées par la Direction', 14, lastY);

    const depenseRows = depenses.slice(0, 15).map(d => [
      d.date_depense ? new Date(d.date_depense).toLocaleDateString('fr-FR') : 'N/A',
      d.motif || d.libelle || 'Dépense',
      d.categorie || 'Général',
      d.beneficiaire || 'N/A',
      `${Number(d.montant).toLocaleString('fr-FR')} FCFA`,
      d.statut || 'Enregistré'
    ]);

    autoTable(doc, {
      startY: lastY + 4,
      head: [['Date', 'Motif', 'Catégorie', 'Bénéficiaire', 'Montant', 'Statut']],
      body: depenseRows,
      theme: 'striped',
      headStyles: { fillColor: [15, 23, 42], textColor: 255 },
      styles: { fontSize: 8 }
    });

    doc.save(`ISGI_Rapport_Financier_DG_${Date.now()}.pdf`);
  };

  // Exporter Rapport Inscriptions & Effectifs
  const handleExportRapportInscriptions = () => {
    const doc = new jsPDF();
    const dateStr = new Date().toLocaleDateString('fr-FR');

    doc.setFontSize(16);
    doc.setTextColor(2, 132, 199);
    doc.text('INSTITUT SOUS-RÉGIONAL DU GABON (ISGI)', 14, 20);
    doc.setFontSize(10);
    doc.setTextColor(71, 85, 105);
    doc.text('DIRECTION GÉNÉRALE • RAPPORT GÉNÉRAL DES EFFECTIFS', 14, 26);
    doc.text(`Édité le : ${dateStr} • Année Académique 2026-2027`, 14, 32);

    doc.setLineWidth(0.5);
    doc.setDrawColor(203, 213, 225);
    doc.line(14, 36, 196, 36);

    // Synthèse
    const inscritsJour = etudiants.filter(e => !e.vague || e.vague.toLowerCase().includes('jour')).length;
    const inscritsSoir = etudiants.filter(e => e.vague && e.vague.toLowerCase().includes('soir')).length;

    autoTable(doc, {
      startY: 44,
      head: [['Indicateur', 'Nombre d\'Étudiants']],
      body: [
        ['Effectif Global Enregistré', `${etudiants.length}`],
        ['Effectif en Vague Jour', `${inscritsJour}`],
        ['Effectif en Vague Soir', `${inscritsSoir}`],
      ],
      theme: 'grid',
      headStyles: { fillColor: [15, 23, 42], textColor: 255 }
    });

    const lastY = (doc as any).lastAutoTable.finalY + 12;
    doc.setFontSize(11);
    doc.setTextColor(15, 23, 42);
    doc.text('Extrait du Registre Matricule des Étudiants', 14, lastY);

    const etudRows = etudiants.slice(0, 40).map(e => [
      e.matricule,
      `${e.nom} ${e.prenom}`,
      e.filiere || 'N/A',
      e.niveau || 'N/A',
      e.vague || 'Jour'
    ]);

    autoTable(doc, {
      startY: lastY + 4,
      head: [['Matricule', 'Nom & Prénom', 'Filière', 'Niveau', 'Vague']],
      body: etudRows,
      theme: 'striped',
      headStyles: { fillColor: [2, 132, 199], textColor: 255 },
      styles: { fontSize: 8 }
    });

    doc.save(`ISGI_Rapport_Effectifs_DG_${Date.now()}.pdf`);
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header */}
      <div>
        <h2 className="text-2xl font-black text-on-surface">Rapports Stratégiques & Bilans Exécutifs</h2>
        <p className="text-xs text-on-surface-variant font-medium mt-1">
          Édition de documents officiels pour le Conseil d'Administration et les partenaires de l'ISGI
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Rapport 1 : Bilan Financier */}
        <div className="p-6 rounded-3xl bg-surface-container-lowest border border-outline-variant/60 shadow-xs space-y-4 flex flex-col justify-between">
          <div className="space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-800 flex items-center justify-center">
              <TrendingUp className="w-6 h-6" />
            </div>
            <h3 className="font-black text-base text-on-surface">Bilan Financier & Trésorerie</h3>
            <p className="text-xs text-on-surface-variant leading-relaxed">
              Génère le document PDF complet récapitulant les encaissements de scolarité, les dépenses autorisées et le solde net disponible de l'établissement.
            </p>
            <div className="p-3 rounded-2xl bg-surface-container text-xs space-y-1 font-semibold text-on-surface">
              <div>Total Encaissé : <strong>{totalEncaisse.toLocaleString('fr-FR')} FCFA</strong></div>
              <div>Total Dépenses : <strong>{totalDepenses.toLocaleString('fr-FR')} FCFA</strong></div>
              <div className="text-emerald-700">Solde Net : <strong>{solde.toLocaleString('fr-FR')} FCFA</strong></div>
            </div>
          </div>

          <button
            onClick={handleExportBilanFinancier}
            className="w-full flex items-center justify-center gap-2 py-3 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition-colors"
          >
            <Download className="w-4 h-4" />
            Télécharger Bilan Financier (PDF)
          </button>
        </div>

        {/* Rapport 2 : Rapport Effectifs & Académique */}
        <div className="p-6 rounded-3xl bg-surface-container-lowest border border-outline-variant/60 shadow-xs space-y-4 flex flex-col justify-between">
          <div className="space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-sky-100 text-sky-800 flex items-center justify-center">
              <Users className="w-6 h-6" />
            </div>
            <h3 className="font-black text-base text-on-surface">Rapport Général des Effectifs</h3>
            <p className="text-xs text-on-surface-variant leading-relaxed">
              Synthèse globale de la population étudiante inscrite : ventilation par promotion, filière, cycle et vague horaire (Jour vs Soir).
            </p>
            <div className="p-3 rounded-2xl bg-surface-container text-xs space-y-1 font-semibold text-on-surface">
              <div>Effectif Total : <strong>{etudiants.length} Étudiants</strong></div>
              <div>Vague Jour : <strong>{etudiants.filter(e => !e.vague || e.vague.toLowerCase().includes('jour')).length}</strong></div>
              <div>Vague Soir : <strong>{etudiants.filter(e => e.vague?.toLowerCase().includes('soir')).length}</strong></div>
            </div>
          </div>

          <button
            onClick={handleExportRapportInscriptions}
            className="w-full flex items-center justify-center gap-2 py-3 rounded-2xl bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs shadow-xs transition-colors"
          >
            <Download className="w-4 h-4" />
            Télécharger Rapport Effectifs (PDF)
          </button>
        </div>
      </div>
    </div>
  );
}
