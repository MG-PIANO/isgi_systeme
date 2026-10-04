import React from 'react';
import type { Paiement } from '../../db/db';

interface ReceiptTicketProps {
  paiement: Paiement | null;
  etudiant: { nom: string; prenom: string; matricule: string } | null;
}

export const ReceiptTicket: React.FC<ReceiptTicketProps> = ({ paiement, etudiant }) => {
  if (!paiement || !etudiant) return null;

  return (
    <div id="print-receipt" className="hidden print:block bg-white text-black p-4 text-sm font-mono w-[80mm]">
      <div className="text-center mb-4">
        <h1 className="text-xl font-bold tracking-tight">ISGI</h1>
        <p className="text-xs">Institut Supérieur de Gestion et d'Ingénierie</p>
        <p className="text-xs text-black/60 mt-1">Reçu de Paiement</p>
        <p className="text-xs mt-2 border-b border-black pb-2">REÇU DE PAIEMENT</p>
      </div>

      <div className="mb-4 space-y-1 text-xs">
        <div className="flex justify-between">
          <span>Date:</span>
          <span>{new Date(paiement.created_at || paiement.last_modified_at || 0).toLocaleString('fr-FR')}</span>
        </div>
        <div className="flex justify-between">
          <span>Réf:</span>
          <span>{paiement.reference_transaction || 'N/A'}</span>
        </div>
        <div className="flex justify-between">
          <span>Type:</span>
          <span>{paiement.type_paiement || 'Frais Scolaire'}</span>
        </div>
        <div className="flex justify-between">
          <span>Mode:</span>
          <span>{paiement.mode_paiement}</span>
        </div>
        <div className="flex justify-between">
          <span>Caissier:</span>
          <span className="truncate max-w-[120px]">{paiement.gestionnaire_nom || 'Secrétariat'}</span>
        </div>
      </div>

      <div className="mb-4 space-y-1 text-xs border-y border-black py-2">
        <div className="flex justify-between font-bold">
          <span>ÉTUDIANT:</span>
          <span>{etudiant.matricule}</span>
        </div>
        <div className="text-right">
          {etudiant.nom} {etudiant.prenom}
        </div>
      </div>

      <div className="mb-6 space-y-2">
        <div className="flex justify-between font-bold text-base">
          <span>MONTANT PAYÉ:</span>
          <span>{Number(paiement.montant).toLocaleString('fr-FR')} F</span>
        </div>
      </div>

      <div className="text-center text-xs space-y-1 border-t border-black pt-4">
        <p>Signature / Cachet</p>
        <p className="mt-8 italic text-[10px]">Merci de votre confiance.</p>
        <p className="text-[10px]">Document généré automatiquement.</p>
      </div>
    </div>
  );
};
