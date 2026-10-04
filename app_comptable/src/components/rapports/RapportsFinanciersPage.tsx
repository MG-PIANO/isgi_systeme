import { useState, useEffect } from 'react';
import { supabase } from '../../db/supabaseClient';
import { TrendingUp, TrendingDown, RefreshCw, Download, FileText, Printer } from 'lucide-react';
import html2pdf from 'html2pdf.js';
import { useSettings } from '../../hooks/useSettings';

export function RapportsFinanciersPage() {
  const { settings } = useSettings();
  const [loading, setLoading] = useState(true);
  const [isExporting, setIsExporting] = useState(false);
  const [transactions, setTransactions] = useState({
    revenus: 0,
    depenses_ops: 0,
    depenses_paies: 0,
  });

  const today = new Date();
  const [periodicite, setPeriodicite] = useState<'annuel' | 'mensuel' | 'hebdo' | 'journalier'>('mensuel');
  const [filterAnnee, setFilterAnnee] = useState(today.getFullYear().toString());
  const [filterMois, setFilterMois] = useState((today.getMonth() + 1).toString()); // '1'-'12'
  const [filterDate, setFilterDate] = useState(today.toISOString().split('T')[0]); // YYYY-MM-DD

  const moisList = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
  ];

  // Calcule la plage de dates selon la périodicité choisie
  const getDateRange = (): { start: Date; end: Date; label: string } => {
    const annee = parseInt(filterAnnee);
    const mois = parseInt(filterMois) - 1;
    if (periodicite === 'annuel') {
      return {
        start: new Date(annee, 0, 1),
        end: new Date(annee, 11, 31, 23, 59, 59),
        label: `Exercice ${annee}`
      };
    } else if (periodicite === 'mensuel') {
      return {
        start: new Date(annee, mois, 1),
        end: new Date(annee, mois + 1, 0, 23, 59, 59),
        label: `${moisList[mois]} ${annee}`
      };
    } else if (periodicite === 'hebdo') {
      const baseDate = new Date(filterDate);
      // Lundi de la semaine
      const day = baseDate.getDay(); // 0=dim, 1=lun...
      const diffToMonday = (day === 0 ? -6 : 1 - day);
      const monday = new Date(baseDate);
      monday.setDate(baseDate.getDate() + diffToMonday);
      monday.setHours(0, 0, 0, 0);
      const sunday = new Date(monday);
      sunday.setDate(monday.getDate() + 6);
      sunday.setHours(23, 59, 59);
      return {
        start: monday,
        end: sunday,
        label: `Semaine du ${monday.toLocaleDateString('fr-FR')} au ${sunday.toLocaleDateString('fr-FR')}`
      };
    } else {
      // journalier
      const d = new Date(filterDate);
      d.setHours(0, 0, 0, 0);
      const dEnd = new Date(filterDate);
      dEnd.setHours(23, 59, 59, 999);
      return {
        start: d,
        end: dEnd,
        label: `Journée du ${d.toLocaleDateString('fr-FR')}`
      };
    }
  };

  useEffect(() => {
    fetchData();
  }, [periodicite, filterAnnee, filterMois, filterDate]);

  const fetchData = async () => {
    setLoading(true);
    try {
      const { start, end } = getDateRange();
      const dateStartIso = start.toISOString();
      const dateEndIso = end.toISOString();

      const [paiementsRes, depensesRes, paiesRes] = await Promise.all([
        supabase.from('paiements').select('montant').eq('statut', 'Réussi').gte('created_at', dateStartIso).lte('created_at', dateEndIso),
        supabase.from('depenses').select('montant').gte('date_depense', dateStartIso).lte('date_depense', dateEndIso),
        supabase.from('paies').select('montant_net').gte('date_paiement', dateStartIso).lte('date_paiement', dateEndIso)
      ]);

      if (paiementsRes.error) throw paiementsRes.error;
      if (depensesRes.error) throw depensesRes.error;
      if (paiesRes.error) throw paiesRes.error;

      const totalRevenus = (paiementsRes.data || []).reduce((acc, curr) => acc + curr.montant, 0);
      const totalDepenses = (depensesRes.data || []).reduce((acc, curr) => acc + curr.montant, 0);
      const totalPaies = (paiesRes.data || []).reduce((acc, curr) => acc + curr.montant_net, 0);

      setTransactions({ revenus: totalRevenus, depenses_ops: totalDepenses, depenses_paies: totalPaies });
    } catch (error) {
      console.error('Error fetching financial data:', error);
    } finally {
      setLoading(false);
    }
  };

  const exportPDF = () => {
    setIsExporting(true);
    setTimeout(() => {
      const element = document.getElementById('rapport-content');
      if (!element) { setIsExporting(false); return; }
      const { label } = getDateRange();
      const opt = {
        margin: 10,
        filename: `Rapport_Financier_${label.replace(/ /g, '_')}.pdf`,
        image: { type: 'jpeg' as const, quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' as const }
      };
      html2pdf().set(opt).from(element).save().then(() => { setIsExporting(false); });
    }, 100);
  };

  const totalCharges = transactions.depenses_ops + transactions.depenses_paies;
  const resultatNet = transactions.revenus - totalCharges;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 print:hidden">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Rapports Financiers</h1>
          <p className="mt-1 text-sm text-gray-500">
            Compte de résultat et suivi des flux de trésorerie
          </p>
        </div>
        
        <div className="flex flex-wrap gap-2 items-center">
          {/* Choix périodicité */}
          <div className="flex rounded-lg overflow-hidden border border-gray-300">
            {(['journalier', 'hebdo', 'mensuel', 'annuel'] as const).map(p => (
              <button
                key={p}
                onClick={() => setPeriodicite(p)}
                className={`px-3 py-2 text-sm font-medium transition-colors ${
                  periodicite === p
                    ? 'bg-blue-600 text-white'
                    : 'bg-white text-gray-600 hover:bg-gray-50'
                }`}
              >
                {p === 'journalier' ? 'Journalier' : p === 'hebdo' ? 'Hebdomadaire' : p === 'mensuel' ? 'Mensuel' : 'Annuel'}
              </button>
            ))}
          </div>

          {/* Inputs selon périodicité */}
          {(periodicite === 'journalier' || periodicite === 'hebdo') && (
            <input
              type="date"
              value={filterDate}
              onChange={e => setFilterDate(e.target.value)}
              className="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm py-2 px-3 border"
            />
          )}
          {periodicite === 'mensuel' && (
            <>
              <select
                value={filterMois}
                onChange={e => setFilterMois(e.target.value)}
                className="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm py-2 px-3 border"
              >
                {moisList.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
              </select>
              <input
                type="number"
                value={filterAnnee}
                onChange={e => setFilterAnnee(e.target.value)}
                className="w-24 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm py-2 px-3 border"
              />
            </>
          )}
          {periodicite === 'annuel' && (
            <input
              type="number"
              value={filterAnnee}
              onChange={e => setFilterAnnee(e.target.value)}
              className="w-24 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm py-2 px-3 border"
            />
          )}

          <button
            onClick={fetchData}
            className="p-2 bg-white border border-gray-300 rounded-md hover:bg-gray-50 shadow-sm"
            title="Rafraîchir"
          >
            <RefreshCw className={`h-4 w-4 text-gray-600 ${loading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Résumé */}
        <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-center items-center text-center print:hidden">
          <div className={`p-3 rounded-full mb-4 ${resultatNet >= 0 ? 'bg-green-100' : 'bg-red-100'}`}>
            {resultatNet >= 0 ? <TrendingUp className="h-8 w-8 text-green-600" /> : <TrendingDown className="h-8 w-8 text-red-600" />}
          </div>
          <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wider">Résultat Net (Bénéfice/Perte)</h2>
          <p className={`text-3xl font-bold mt-2 ${resultatNet >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            {resultatNet.toLocaleString('fr-FR')} FCFA
          </p>
        </div>

        {/* Compte de Résultat - Vue détaillée */}
        <div id="rapport-content" className="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <div className={`px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center ${isExporting ? 'hidden' : 'print:hidden'}`}>
            <h3 className="font-semibold text-gray-900 flex items-center gap-2">
              <FileText className="h-5 w-5 text-blue-600" />
              Compte de Résultat Simplifié
            </h3>
            <div className="flex gap-4">
              <button className="text-sm text-gray-600 hover:text-gray-900 flex items-center gap-1 font-medium" onClick={() => window.print()}>
                <Printer className="h-4 w-4" />
                Imprimer
              </button>
              <button className="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1 font-medium" onClick={exportPDF}>
                <Download className="h-4 w-4" />
                PDF
              </button>
            </div>
          </div>
          {/* Header visible uniquement à l'impression/PDF */}
          <div className={`${isExporting ? 'block' : 'hidden print:block'} px-8 py-8 border-b-2 border-primary/20 bg-white`}>
            <div className="flex flex-col items-center text-center">
              <img src="./logo.jpg" alt="Logo ISGI" className="h-24 mb-4 object-contain" />
              <h1 className="text-2xl font-black text-gray-900 uppercase tracking-wider">
                {settings.nomEcole}
              </h1>
              <p className="text-gray-500 font-bold tracking-widest mt-1 uppercase text-sm">{settings.sigle}</p>
              
              <div className="mt-8 border-t-2 border-b-2 border-gray-200 py-3 px-16 inline-block">
                <h2 className="text-xl font-bold text-primary uppercase tracking-wider">
                  {periodicite === 'journalier' ? 'Rapport Journalier' :
                   periodicite === 'hebdo' ? 'Rapport Hebdomadaire' :
                   periodicite === 'mensuel' ? 'Rapport Mensuel' : 'Compte de Résultat Annuel'}
                </h2>
                <p className="text-gray-600 font-medium mt-1">
                  Période : {getDateRange().label}
                </p>
              </div>
            </div>
            
            <div className="mt-8 flex justify-between text-sm text-gray-600 font-medium">
              <div>
                <p>Édité le : {new Date().toLocaleDateString('fr-FR')}</p>
              </div>
              <div className="text-right">
                <p>Département : Comptabilité & Finances</p>
                <p>Devise : Franc CFA (FCFA)</p>
              </div>
            </div>
          </div>
          
          <div className="p-0">
            <table className="min-w-full">
              <tbody className="divide-y divide-gray-100 text-sm">
                
                {/* PRODUITS */}
                <tr className="bg-green-50/30">
                  <td className="px-6 py-3 font-semibold text-green-800" colSpan={2}>PRODUITS (Revenus)</td>
                </tr>
                <tr>
                  <td className="px-6 py-3 text-gray-700 pl-10">Frais de scolarité & Inscriptions</td>
                  <td className="px-6 py-3 text-right font-medium text-gray-900">{transactions.revenus.toLocaleString('fr-FR')}</td>
                </tr>
                <tr className="border-b-2 border-gray-200 bg-gray-50/50">
                  <td className="px-6 py-3 font-bold text-gray-900 text-right">TOTAL PRODUITS (I)</td>
                  <td className="px-6 py-3 text-right font-bold text-green-700">{transactions.revenus.toLocaleString('fr-FR')}</td>
                </tr>

                {/* CHARGES */}
                <tr className="bg-red-50/30">
                  <td className="px-6 py-3 font-semibold text-red-800" colSpan={2}>CHARGES (Dépenses)</td>
                </tr>
                <tr>
                  <td className="px-6 py-3 text-gray-700 pl-10">Dépenses Opérationnelles (Achats, Entretien, Services)</td>
                  <td className="px-6 py-3 text-right font-medium text-gray-900">{transactions.depenses_ops.toLocaleString('fr-FR')}</td>
                </tr>
                <tr>
                  <td className="px-6 py-3 text-gray-700 pl-10">Frais de Personnel (Salaires, Primes)</td>
                  <td className="px-6 py-3 text-right font-medium text-gray-900">{transactions.depenses_paies.toLocaleString('fr-FR')}</td>
                </tr>
                <tr className="border-b-2 border-gray-200 bg-gray-50/50">
                  <td className="px-6 py-3 font-bold text-gray-900 text-right">TOTAL CHARGES (II)</td>
                  <td className="px-6 py-3 text-right font-bold text-red-700">{totalCharges.toLocaleString('fr-FR')}</td>
                </tr>

                {/* RESULTAT */}
                <tr className="bg-surface-container-low border-t border-gray-300">
                  <td className="px-6 py-6 font-bold text-gray-900 text-right text-base uppercase">
                    RÉSULTAT NET ({resultatNet >= 0 ? 'BÉNÉFICE' : 'PERTE'})
                  </td>
                  <td className={`px-6 py-6 text-right font-black text-2xl ${resultatNet >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                    {resultatNet.toLocaleString('fr-FR')} FCFA
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          {/* Footer visible uniquement à l'impression/PDF */}
          <div className={`${isExporting ? 'block' : 'hidden print:block'} px-8 py-12 mt-10`}>
            <div className="flex justify-between items-start pt-8 border-t border-gray-200">
              <div className="text-center">
                <p className="font-bold text-gray-900">{settings.nomComptable}</p>
                <div className="mt-16 border-b-2 border-dotted border-gray-400 w-40 mx-auto"></div>
              </div>
              <div className="text-center">
                <p className="font-bold text-gray-900">{settings.nomDG}</p>
                <div className="mt-16 border-b-2 border-dotted border-gray-400 w-40 mx-auto"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
