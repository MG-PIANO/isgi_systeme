import { useState } from 'react';
import { Download, Wallet, ArrowUpRight, Calendar, CalendarDays, LineChart, PieChart, TrendingUp } from 'lucide-react';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { exportToPDF } from '../../utils/pdfExport';
import { ResponsiveContainer, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, PieChart as RechartsPie, Pie, Cell } from 'recharts';

const COLORS = ['#0053db', '#2196f3', '#4caf50', '#ff9800', '#f44336', '#9c27b0'];

export function FinancesPage() {
  const allPaiements = useLiveQuery(() => db.paiements.toArray(), []) || [];
  const paiements = allPaiements.filter(p => p.statut === 'Réussi' || !p.statut);
  
  const [selectedMonth, setSelectedMonth] = useState<number>(new Date().getMonth());
  const [selectedYear, setSelectedYear] = useState<number>(new Date().getFullYear());

  // Generate available years based on data or default to current
  const currentYear = new Date().getFullYear();
  const availableYears = Array.from(new Set([
    currentYear - 1, currentYear, currentYear + 1,
    ...paiements.map(p => new Date(p.created_at || p.last_modified_at || Date.now()).getFullYear())
  ])).sort((a, b) => b - a);

  // Time metrics
  const now = new Date();
  
  const isSameDay = (d1: Date, d2: Date) => 
    d1.getFullYear() === d2.getFullYear() && d1.getMonth() === d2.getMonth() && d1.getDate() === d2.getDate();
  
  const isSameMonth = (d1: Date, month: number, year: number) => 
    d1.getFullYear() === year && d1.getMonth() === month;

  // Calculs KPI
  const recettesJour = paiements.filter(p => isSameDay(new Date(p.created_at || p.last_modified_at || Date.now()), now))
    .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    
  const recettesMois = paiements.filter(p => isSameMonth(new Date(p.created_at || p.last_modified_at || Date.now()), now.getMonth(), now.getFullYear()))
    .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    
  const recettesAnnee = paiements.filter(p => new Date(p.created_at || p.last_modified_at || Date.now()).getFullYear() === now.getFullYear())
    .reduce((sum, p) => sum + Number(p.montant || 0), 0);

  // Données filtrées pour la vue principale
  const filteredPaiements = paiements.filter(p => isSameMonth(new Date(p.created_at || p.last_modified_at || Date.now()), selectedMonth, selectedYear));
  const totalFiltre = filteredPaiements.reduce((sum, p) => sum + Number(p.montant || 0), 0);

  // Répartition par type de paiement
  const typeDistribution = filteredPaiements.reduce((acc, p) => {
    const type = p.type_paiement || 'Non spécifié';
    if (!acc[type]) acc[type] = 0;
    acc[type] += Number(p.montant || 0);
    return acc;
  }, {} as Record<string, number>);

  const pieData = Object.entries(typeDistribution).map(([name, value]) => ({ name, value })).sort((a, b) => b.value - a.value);

  // Evolution journalière pour le mois sélectionné
  const daysInMonth = new Date(selectedYear, selectedMonth + 1, 0).getDate();
  const dailyData = Array.from({ length: daysInMonth }, (_, i) => {
    const day = i + 1;
    const dailyTotal = filteredPaiements
      .filter(p => new Date(p.created_at || p.last_modified_at || Date.now()).getDate() === day)
      .reduce((sum, p) => sum + Number(p.montant || 0), 0);
    return { day: `${day}/${selectedMonth + 1}`, total: dailyTotal };
  });

  const handleExportPDF = () => {
    const monthName = new Date(selectedYear, selectedMonth).toLocaleString('fr-FR', { month: 'long' });
    const title = `Rapport Financier - ${monthName} ${selectedYear}`;
    
    // Create summarized table data (by day)
    const tableData = dailyData.filter(d => d.total > 0).map(d => ({
      jour: d.day,
      montant: `${d.total.toLocaleString('fr-FR')} FCFA`
    }));
    
    tableData.push({ jour: 'TOTAL DU MOIS', montant: `${totalFiltre.toLocaleString('fr-FR')} FCFA` });

    exportToPDF(
      title,
      [
        { header: 'Date', dataKey: 'jour' },
        { header: 'Montant Encaissé', dataKey: 'montant' }
      ],
      tableData,
      `rapport_financier_${selectedMonth + 1}_${selectedYear}`
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Finances & Rapports</h2>
          <p className="text-on-surface-variant text-sm mt-1">Suivez les rentrées d'argent avec précision</p>
        </div>
      </div>

      {/* KPI Cards Global */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-4 relative overflow-hidden">
          <div className="absolute top-0 right-0 p-4 opacity-10 text-primary">
            <Wallet className="w-16 h-16" />
          </div>
          <div className="flex justify-between items-start z-10">
            <div className="w-12 h-12 bg-primary-container text-on-primary-container rounded-xl flex items-center justify-center">
              <Calendar className="w-6 h-6" />
            </div>
          </div>
          <div className="z-10">
            <p className="text-on-surface-variant text-sm font-medium">Recettes du Jour</p>
            <p className="text-3xl font-bold text-on-surface mt-1">{recettesJour.toLocaleString('fr-FR')} F</p>
          </div>
        </div>

        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-4 relative overflow-hidden">
          <div className="absolute top-0 right-0 p-4 opacity-10 text-secondary">
            <CalendarDays className="w-16 h-16" />
          </div>
          <div className="flex justify-between items-start z-10">
            <div className="w-12 h-12 bg-secondary-container text-on-secondary-container rounded-xl flex items-center justify-center">
              <TrendingUp className="w-6 h-6" />
            </div>
          </div>
          <div className="z-10">
            <p className="text-on-surface-variant text-sm font-medium">Recettes du Mois ({now.toLocaleString('fr-FR', { month: 'long' })})</p>
            <p className="text-3xl font-bold text-on-surface mt-1">{recettesMois.toLocaleString('fr-FR')} F</p>
          </div>
        </div>

        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-4 relative overflow-hidden">
          <div className="absolute top-0 right-0 p-4 opacity-10 text-tertiary">
            <LineChart className="w-16 h-16" />
          </div>
          <div className="flex justify-between items-start z-10">
            <div className="w-12 h-12 bg-tertiary-container text-on-tertiary-container rounded-xl flex items-center justify-center">
              <ArrowUpRight className="w-6 h-6" />
            </div>
          </div>
          <div className="z-10">
            <p className="text-on-surface-variant text-sm font-medium">Recettes Annuelles ({now.getFullYear()})</p>
            <p className="text-3xl font-bold text-on-surface mt-1">{recettesAnnee.toLocaleString('fr-FR')} F</p>
          </div>
        </div>
      </div>

      {/* Main Analysis Section */}
      <div className="bg-surface-container rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
        
        {/* Toolbar */}
        <div className="p-4 border-b border-outline-variant flex flex-col md:flex-row gap-4 justify-between items-center bg-surface-container-low">
          <div className="flex items-center gap-4 w-full md:w-auto">
            <select
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(Number(e.target.value))}
              className="px-4 py-2 bg-surface-container-highest border-none rounded-xl text-on-surface font-medium focus:ring-2 focus:ring-primary w-full md:w-auto"
            >
              {Array.from({ length: 12 }, (_, i) => {
                const date = new Date(2000, i, 1);
                return (
                  <option key={i} value={i}>
                    {date.toLocaleString('fr-FR', { month: 'long' }).charAt(0).toUpperCase() + date.toLocaleString('fr-FR', { month: 'long' }).slice(1)}
                  </option>
                );
              })}
            </select>
            <select
              value={selectedYear}
              onChange={(e) => setSelectedYear(Number(e.target.value))}
              className="px-4 py-2 bg-surface-container-highest border-none rounded-xl text-on-surface font-medium focus:ring-2 focus:ring-primary w-full md:w-auto"
            >
              {availableYears.map(year => (
                <option key={year} value={year}>{year}</option>
              ))}
            </select>
          </div>

          <div className="flex items-center gap-4 w-full md:w-auto font-bold text-xl px-4 py-1 bg-primary-container text-on-primary-container rounded-lg">
            Total : {totalFiltre.toLocaleString('fr-FR')} F
          </div>
          
          <button 
            onClick={handleExportPDF}
            className="flex items-center gap-2 bg-secondary text-on-secondary px-4 py-2 rounded-full font-medium hover:bg-secondary/90 transition-colors w-full md:w-auto justify-center"
          >
            <Download className="w-4 h-4" />
            Exporter le Rapport PDF
          </button>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 p-6">
          
          {/* Pie Chart */}
          <div className="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant shadow-sm">
            <h3 className="text-lg font-bold text-on-surface mb-6 flex items-center gap-2">
              <PieChart className="w-5 h-5 text-primary" />
              Répartition par Type de Paiement
            </h3>
            {pieData.length > 0 ? (
              <div className="h-[300px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <RechartsPie>
                    <Pie isAnimationActive={false} data={pieData} cx="50%" cy="50%" innerRadius={60} outerRadius={100} paddingAngle={5} dataKey="value">
                      {pieData.map((_entry, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(value: any) => `${Number(value).toLocaleString('fr-FR')} F`} />
                    <Legend />
                  </RechartsPie>
                </ResponsiveContainer>
              </div>
            ) : (
              <div className="h-[300px] flex items-center justify-center text-on-surface-variant italic">
                Aucun paiement pour ce mois.
              </div>
            )}
          </div>

          {/* Bar Chart */}
          <div className="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant shadow-sm">
            <h3 className="text-lg font-bold text-on-surface mb-6 flex items-center gap-2">
              <LineChart className="w-5 h-5 text-secondary" />
              Évolution Journalière
            </h3>
            <div className="h-[300px] w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={dailyData} margin={{ top: 5, right: 0, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#c3c6d7" />
                  <XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fill: '#505f76', fontSize: 10 }} />
                  <YAxis hide />
                  <Tooltip 
                    cursor={{ fill: 'rgba(0,0,0,0.05)' }}
                    contentStyle={{ backgroundColor: '#ffffff', borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                    formatter={(value: any) => [`${Number(value).toLocaleString('fr-FR')} F`, 'Recettes']}
                  />
                  <Bar isAnimationActive={false} dataKey="total" fill="#0053db" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>
          
        </div>
        
        {/* Table summary */}
        <div className="overflow-x-auto border-t border-outline-variant">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-surface-container-lowest border-b border-outline-variant">
                <th className="p-4 font-semibold text-on-surface-variant text-sm">Date</th>
                <th className="p-4 font-semibold text-on-surface-variant text-sm text-right">Recettes Journalières</th>
              </tr>
            </thead>
            <tbody>
              {dailyData.filter(d => d.total > 0).length === 0 ? (
                <tr>
                  <td colSpan={2} className="p-8 text-center text-on-surface-variant">
                    Aucun encaissement ce mois-ci.
                  </td>
                </tr>
              ) : (
                dailyData.filter(d => d.total > 0).map((day, idx) => (
                  <tr key={idx} className="border-b border-outline-variant hover:bg-surface-container-highest transition-colors">
                    <td className="p-4 text-on-surface font-medium">{day.day} {selectedYear}</td>
                    <td className="p-4 text-on-surface font-bold text-right text-primary">{day.total.toLocaleString('fr-FR')} FCFA</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
