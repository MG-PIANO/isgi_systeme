import { useState, useEffect } from 'react';
import { Users, TrendingUp, AlertCircle, RefreshCw } from 'lucide-react';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { syncData } from '../../db/sync';
import { supabase } from '../../db/supabaseClient';

const chartData = [
  { name: 'Juin', inscriptions: 120 },
  { name: 'Juil', inscriptions: 250 },
  { name: 'Août', inscriptions: 400 },
  { name: 'Sept', inscriptions: 800 },
  { name: 'Oct', inscriptions: 1100 },
  { name: 'Nov', inscriptions: 1245 },
];

export default function DashboardPage() {
  const [isSyncing, setIsSyncing] = useState(false);
  const [totalDepenses, setTotalDepenses] = useState(0);
  const [totalPaies, setTotalPaies] = useState(0);

  const paiements = useLiveQuery(() => db.paiements.toArray(), []) || [];
  const totalRevenus = paiements.reduce((acc, p) => acc + Number(p.montant || 0), 0);
  
  const pendingSyncEtudiants = useLiveQuery(() => db.etudiants.where('is_synced').equals(0).count(), []) || 0;
  const pendingSyncPaiements = useLiveQuery(() => db.paiements.where('is_synced').equals(0).count(), []) || 0;
  const totalPendingSync = pendingSyncEtudiants + pendingSyncPaiements;

  useEffect(() => {
    fetchFinancials();
  }, []);

  const fetchFinancials = async () => {
    try {
      const [depensesRes, paiesRes] = await Promise.all([
        supabase.from('depenses').select('montant'),
        supabase.from('paies').select('montant_net')
      ]);
      
      const sumDepenses = (depensesRes.data || []).reduce((acc, curr) => acc + curr.montant, 0);
      const sumPaies = (paiesRes.data || []).reduce((acc, curr) => acc + curr.montant_net, 0);
      
      setTotalDepenses(sumDepenses);
      setTotalPaies(sumPaies);
    } catch (error) {
      console.error("Error fetching financials:", error);
    }
  };

  const soldeCaisse = totalRevenus - totalDepenses - totalPaies;

  const handleSync = async () => {
    setIsSyncing(true);
    try {
      await syncData();
    } finally {
      setIsSyncing(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Tableau de Bord</h2>
          <p className="text-on-surface-variant text-sm mt-1">Aperçu général de la scolarité</p>
        </div>
        <button 
          onClick={handleSync}
          disabled={isSyncing}
          className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium hover:bg-primary/90 transition-colors disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${isSyncing ? 'animate-spin' : ''}`} />
          {isSyncing ? 'Synchronisation...' : `Synchroniser (${totalPendingSync})`}
        </button>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-surface-container p-4 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-2">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-primary-container text-on-primary-container rounded-xl flex items-center justify-center">
              <TrendingUp className="w-5 h-5" />
            </div>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Revenus (Scolarité)</p>
            <p className="text-xl font-bold text-green-600 mt-1">{totalRevenus.toLocaleString('fr-FR')} FCFA</p>
          </div>
        </div>

        <div className="bg-surface-container p-4 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-2">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-error-container text-on-error-container rounded-xl flex items-center justify-center">
              <AlertCircle className="w-5 h-5" />
            </div>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Dépenses</p>
            <p className="text-xl font-bold text-red-600 mt-1">{totalDepenses.toLocaleString('fr-FR')} FCFA</p>
          </div>
        </div>

        <div className="bg-surface-container p-4 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-2">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-tertiary-container text-on-tertiary-container rounded-xl flex items-center justify-center">
              <Users className="w-5 h-5" />
            </div>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Masse Salariale</p>
            <p className="text-xl font-bold text-red-500 mt-1">{totalPaies.toLocaleString('fr-FR')} FCFA</p>
          </div>
        </div>

        <div className="bg-surface-container p-4 rounded-2xl border border-outline-variant shadow-sm flex flex-col gap-2">
          <div className="flex justify-between items-start">
            <div className="w-10 h-10 bg-secondary-container text-on-secondary-container rounded-xl flex items-center justify-center">
              <TrendingUp className="w-5 h-5" />
            </div>
          </div>
          <div>
            <p className="text-on-surface-variant text-xs font-medium">Solde en Caisse</p>
            <p className={`text-xl font-bold mt-1 ${soldeCaisse >= 0 ? 'text-blue-600' : 'text-red-600'}`}>
              {soldeCaisse.toLocaleString('fr-FR')} FCFA
            </p>
          </div>
        </div>
      </div>

      {/* Charts section */}
      <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-sm">
        <h3 className="text-lg font-bold text-on-surface mb-6">Évolution des Inscriptions</h3>
        <div className="h-[300px] w-full">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
              <defs>
                <linearGradient id="colorInscriptions" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#0053db" stopOpacity={0.3}/>
                  <stop offset="95%" stopColor="#0053db" stopOpacity={0}/>
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#c3c6d7" />
              <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#505f76', fontSize: 12 }} dy={10} />
              <YAxis axisLine={false} tickLine={false} tick={{ fill: '#505f76', fontSize: 12 }} />
              <Tooltip 
                contentStyle={{ backgroundColor: '#ffffff', borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                itemStyle={{ color: '#0053db', fontWeight: 'bold' }}
              />
              <Area isAnimationActive={false} type="monotone" dataKey="inscriptions" stroke="#0053db" strokeWidth={3} fillOpacity={1} fill="url(#colorInscriptions)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
}
