import { useState, useEffect, useMemo } from 'react';
import { 
  Users, 
  TrendingUp, 
  AlertCircle, 
  RefreshCw, 
  Wallet, 
  DollarSign, 
  Building2, 
  ArrowUpRight,
  CheckCircle2,
  Lock,
  Layers,
  CalendarCheck
} from 'lucide-react';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { db } from '../../db/db';
import { useLiveQuery } from 'dexie-react-hooks';
import { syncData } from '../../db/sync';
import { supabase } from '../../db/supabaseClient';
import { Link } from 'react-router-dom';

export default function ExecutiveDashboardPage() {
  const [isSyncing, setIsSyncing] = useState(false);
  const [totalDepenses, setTotalDepenses] = useState(0);
  const [totalPaies, setTotalPaies] = useState(0);

  // Données réelles Dexie
  const etudiants = useLiveQuery(() => db.etudiants.toArray(), []) || [];
  const paiements = useLiveQuery(() => db.paiements.toArray(), []) || [];

  const totalRevenus = useMemo(() => {
    return paiements
      .filter(p => p.statut === 'Réussi' || !p.statut)
      .reduce((acc, p) => acc + Number(p.montant || 0), 0);
  }, [paiements]);

  const pendingSyncEtudiants = useLiveQuery(() => db.etudiants.where('is_synced').equals(0).count(), []) || 0;
  const pendingSyncPaiements = useLiveQuery(() => db.paiements.where('is_synced').equals(0).count(), []) || 0;
  const totalPendingSync = pendingSyncEtudiants + pendingSyncPaiements;

  // Effectif Jour / Soir réel
  const nbJour = etudiants.filter(e => !e.vague || e.vague.toLowerCase().includes('jour')).length;
  const nbSoir = etudiants.filter(e => e.vague && e.vague.toLowerCase().includes('soir')).length;

  useEffect(() => {
    fetchFinancials();
  }, []);

  const fetchFinancials = async () => {
    try {
      const [depensesRes, paiesRes] = await Promise.all([
        supabase.from('depenses').select('montant'),
        supabase.from('paies').select('montant_net')
      ]);

      const sumDepenses = (depensesRes.data || []).reduce((acc: number, curr: any) => acc + (Number(curr.montant) || 0), 0);
      const sumPaies = (paiesRes.data || []).reduce((acc: number, curr: any) => acc + (Number(curr.montant_net) || 0), 0);

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
      await fetchFinancials();
    } finally {
      setIsSyncing(false);
    }
  };

  // Dynamique des encaissements par mois réels
  const chartData = useMemo(() => {
    const moisNoms = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];
    const regroupement: Record<string, number> = {};

    paiements.forEach(p => {
      if (p.last_modified_at || p.created_at) {
        const d = new Date(p.last_modified_at || p.created_at!);
        const key = moisNoms[d.getMonth()];
        regroupement[key] = (regroupement[key] || 0) + Number(p.montant || 0);
      }
    });

    const data = Object.entries(regroupement).map(([name, total]) => ({
      name,
      total
    }));

    if (data.length === 0) {
      return [
        { name: 'Sept', total: 0 },
        { name: 'Oct', total: totalRevenus },
      ];
    }
    return data;
  }, [paiements, totalRevenus]);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-2xl font-bold text-on-surface">Tableau de Bord DG</h2>
          <p className="text-on-surface-variant text-sm mt-1">
            Gouvernance financière, académique et suivi global en temps réel
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            to="/previsions-roi"
            className="flex items-center gap-2 bg-secondary text-on-secondary px-4 py-2 rounded-full font-medium hover:bg-secondary/90 transition-colors shadow-xs"
          >
            <TrendingUp className="w-4 h-4" />
            Prévisions & ROI
          </Link>
          <button 
            onClick={handleSync}
            disabled={isSyncing}
            className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full font-medium hover:bg-primary/90 transition-colors disabled:opacity-50 shadow-xs cursor-pointer"
          >
            <RefreshCw className={`w-4 h-4 ${isSyncing ? 'animate-spin' : ''}`} />
            {isSyncing ? 'Synchronisation...' : `Synchroniser Cloud (${totalPendingSync})`}
          </button>
        </div>
      </div>

      {/* KPI Cards (Exactement le design et la rigueur du comptable) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Total Revenus */}
        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-xs flex flex-col justify-between">
          <div className="flex justify-between items-start">
            <span className="text-on-surface-variant text-xs font-bold uppercase tracking-wider">
              Revenus Encaissés
            </span>
            <div className="w-10 h-10 bg-primary-container text-on-primary-container rounded-xl flex items-center justify-center">
              <TrendingUp className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-2">
            <p className="text-2xl font-black text-emerald-600">
              {totalRevenus.toLocaleString('fr-FR')} FCFA
            </p>
            <p className="text-[11px] text-on-surface-variant mt-1">
              {paiements.length} paiements enregistrés
            </p>
          </div>
        </div>

        {/* Dépenses Opérationnelles */}
        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-xs flex flex-col justify-between">
          <div className="flex justify-between items-start">
            <span className="text-on-surface-variant text-xs font-bold uppercase tracking-wider">
              Dépenses & Fournitures
            </span>
            <div className="w-10 h-10 bg-error-container text-on-error-container rounded-xl flex items-center justify-center">
              <Wallet className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-2">
            <p className="text-2xl font-black text-rose-600">
              {totalDepenses.toLocaleString('fr-FR')} FCFA
            </p>
            <p className="text-[11px] text-on-surface-variant mt-1">
              Charges & décaissements validés
            </p>
          </div>
        </div>

        {/* Masse Salariale */}
        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-xs flex flex-col justify-between">
          <div className="flex justify-between items-start">
            <span className="text-on-surface-variant text-xs font-bold uppercase tracking-wider">
              Salaires Versés
            </span>
            <div className="w-10 h-10 bg-secondary-container text-on-secondary-container rounded-xl flex items-center justify-center">
              <DollarSign className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-2">
            <p className="text-2xl font-black text-on-surface">
              {totalPaies.toLocaleString('fr-FR')} FCFA
            </p>
            <p className="text-[11px] text-on-surface-variant mt-1">
              Paies enseignants & personnel
            </p>
          </div>
        </div>

        {/* Solde Net Disponible */}
        <div className="bg-surface-container p-5 rounded-2xl border border-outline-variant shadow-xs flex flex-col justify-between">
          <div className="flex justify-between items-start">
            <span className="text-on-surface-variant text-xs font-bold uppercase tracking-wider">
              Solde Net de Caisse
            </span>
            <div className="w-10 h-10 bg-surface-container-highest text-on-surface rounded-xl flex items-center justify-center">
              <Layers className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-2">
            <p className={`text-2xl font-black ${soldeCaisse >= 0 ? 'text-primary' : 'text-error'}`}>
              {soldeCaisse.toLocaleString('fr-FR')} FCFA
            </p>
            <p className="text-[11px] text-on-surface-variant mt-1">
              Trésorerie nette disponible
            </p>
          </div>
        </div>
      </div>

      {/* Rangée 2 : Effectif Réel & Graphique */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Graphique de revenus */}
        <div className="lg:col-span-2 bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="font-bold text-lg text-on-surface">Évolution des Recouvrements</h3>
              <p className="text-xs text-on-surface-variant">Flux des encaissements mensuels réels</p>
            </div>
            <Link 
              to="/paiements" 
              className="text-xs font-bold text-primary hover:underline flex items-center gap-1"
            >
              Voir le journal des paiements <ArrowUpRight className="w-4 h-4" />
            </Link>
          </div>

          <div className="h-64 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData}>
                <defs>
                  <linearGradient id="colorTotal" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#004ac6" stopOpacity={0.8}/>
                    <stop offset="95%" stopColor="#004ac6" stopOpacity={0}/>
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                <XAxis dataKey="name" />
                <YAxis />
                <Tooltip formatter={(value: any) => `${Number(value).toLocaleString('fr-FR')} FCFA`} />
                <Area type="monotone" dataKey="total" stroke="#004ac6" fillOpacity={1} fill="url(#colorTotal)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>

        {/* Population Étudiante Réelle */}
        <div className="bg-surface-container p-6 rounded-2xl border border-outline-variant shadow-xs space-y-4 flex flex-col justify-between">
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg text-on-surface">Effectif Étudiants</h3>
              <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold">
                <Users className="w-4 h-4" />
              </div>
            </div>
            <p className="text-xs text-on-surface-variant">Données réelles de la base étudiante</p>

            <div className="pt-2">
              <div className="text-4xl font-black text-on-surface">
                {etudiants.length.toLocaleString('fr-FR')}
              </div>
              <p className="text-xs font-medium text-on-surface-variant mt-1">Étudiants inscrits au total</p>
            </div>

            <div className="space-y-2 pt-2 border-t border-outline-variant">
              <div className="flex justify-between items-center text-xs">
                <span className="text-on-surface-variant">Vague Jour :</span>
                <span className="font-bold text-on-surface">{nbJour} étudiants</span>
              </div>
              <div className="flex justify-between items-center text-xs">
                <span className="text-on-surface-variant">Vague Soir :</span>
                <span className="font-bold text-on-surface">{nbSoir} étudiants</span>
              </div>
            </div>
          </div>

          <Link
            to="/inscriptions"
            className="w-full text-center py-2.5 rounded-full bg-secondary-container text-on-secondary-container text-xs font-bold hover:bg-secondary-container/80 transition-colors"
          >
            Consulter les inscriptions
          </Link>
        </div>
      </div>
    </div>
  );
}
