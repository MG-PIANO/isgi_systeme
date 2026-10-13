import { useState, useEffect } from 'react';
import { Outlet, Link, useLocation } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Users, 
  CreditCard, 
  LogOut, 
  Wallet, 
  FileSpreadsheet, 
  DollarSign, 
  Menu, 
  Sun, 
  Moon, 
  TrendingUp,
  Lock,
  RefreshCw,
  Building2,
  FileText
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { cn } from '../../lib/utils';
import { NotificationDropdown } from './NotificationDropdown';
import { syncData } from '../../db/sync';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';

export function Layout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [isSyncing, setIsSyncing] = useState(false);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  });
  const location = useLocation();
  const { user, logout } = useAuth();

  const pendingSyncEtudiants = useLiveQuery(() => db.etudiants.where('is_synced').equals(0).count(), []) || 0;
  const pendingSyncPaiements = useLiveQuery(() => db.paiements.where('is_synced').equals(0).count(), []) || 0;
  const totalPendingSync = pendingSyncEtudiants + pendingSyncPaiements;

  useEffect(() => {
    localStorage.setItem('theme', theme);
    if (theme === 'dark') {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [theme]);

  const toggleTheme = () => setTheme(theme === 'light' ? 'dark' : 'light');

  const handleSync = async () => {
    setIsSyncing(true);
    try {
      await syncData();
    } finally {
      setIsSyncing(false);
    }
  };

  const navigation = [
    { name: 'Tableau de bord', href: '/', icon: LayoutDashboard },
    { name: 'Prévisions & ROI', href: '/previsions-roi', icon: TrendingUp },
    { name: 'Paiements', href: '/paiements', icon: CreditCard },
    { name: 'Dépenses', href: '/depenses', icon: Wallet },
    { name: 'Inscriptions', href: '/inscriptions', icon: Users },
    { name: 'Personnel & Équipe', href: '/personnel', icon: Users },
    { name: 'Circulaires & Verrous', href: '/systeme', icon: Lock },
    { name: 'Rapports Stratégiques', href: '/rapports', icon: FileText }
  ];

  return (
    <div className="min-h-screen bg-surface-container-lowest text-on-surface flex overflow-hidden">
      {/* Mobile Sidebar overlay */}
      {sidebarOpen && (
        <div 
          className="fixed inset-0 z-40 bg-on-surface/20 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <div className={cn(
        "fixed inset-y-0 left-0 z-50 w-72 bg-surface-container-low border-r border-outline-variant flex flex-col transition-transform duration-300 lg:static lg:translate-x-0 print:hidden",
        sidebarOpen ? "translate-x-0" : "-translate-x-full"
      )}>
        {/* Brand with Logo */}
        <div className="h-20 flex items-center px-6 border-b border-outline-variant">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-surface-container-lowest flex items-center justify-center overflow-hidden border border-outline-variant">
              <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain" />
            </div>
            <div>
              <div className="font-bold text-xl tracking-tight text-on-surface">ISGI System</div>
              <div className="text-[10px] font-black text-primary tracking-wider uppercase">Direction Générale</div>
            </div>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
          {navigation.map((item) => {
            const active = location.pathname === item.href;
            return (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  "flex items-center justify-between px-4 py-3 rounded-full font-medium transition-colors",
                  active 
                    ? "bg-secondary-container text-on-secondary-container font-bold" 
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
                onClick={() => setSidebarOpen(false)}
              >
                <div className="flex items-center gap-3">
                  <item.icon className="w-5 h-5" />
                  <span>{item.name}</span>
                </div>
              </Link>
            );
          })}
        </nav>

        {/* User Info & Logout */}
        <div className="p-4 border-t border-outline-variant mt-auto">
          <div className="flex items-center gap-3 mb-4 px-2">
            <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-sm">
              DG
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-bold text-on-surface truncate">{user?.name || 'Directeur Général'}</p>
              <p className="text-xs text-on-surface-variant truncate">Admin Principal</p>
            </div>
          </div>
          <button 
            onClick={logout}
            className="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-error hover:bg-error-container hover:text-on-error-container transition-colors cursor-pointer"
          >
            <LogOut className="w-5 h-5" />
            <span className="font-medium">Déconnexion</span>
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex-1 flex flex-col h-screen overflow-hidden">
        {/* Top App Bar */}
        <header className="h-20 bg-surface-container-lowest border-b border-outline-variant flex items-center justify-between px-4 lg:px-8 shrink-0 z-30 print:hidden">
          <div className="flex items-center gap-4">
            <button 
              className="lg:hidden p-2 rounded-full hover:bg-surface-container-highest text-on-surface-variant cursor-pointer"
              onClick={() => setSidebarOpen(true)}
            >
              <Menu className="w-6 h-6" />
            </button>
            <h1 className="text-xl lg:text-2xl font-bold text-on-surface">
              {navigation.find(n => n.href === location.pathname)?.name || 'Direction Générale'}
            </h1>
          </div>

          <div className="flex items-center gap-2 lg:gap-4">
            {/* Sync Cloud button */}
            <button
              onClick={handleSync}
              disabled={isSyncing}
              className="hidden sm:flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold bg-surface-container border border-outline-variant hover:bg-surface-container-highest transition-colors cursor-pointer"
              title="Synchroniser avec Supabase"
            >
              <RefreshCw className={`w-3.5 h-3.5 text-primary ${isSyncing ? 'animate-spin' : ''}`} />
              <span>{isSyncing ? 'Synchronisation...' : `Cloud Synced (${totalPendingSync})`}</span>
            </button>

            {/* Dark / Light toggle */}
            <button 
              onClick={toggleTheme}
              className="p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors cursor-pointer"
              title="Basculer le thème"
            >
              {theme === 'dark' ? <Sun className="w-5 h-5" /> : <Moon className="w-5 h-5" />}
            </button>
            
            {/* Cloche de notifications */}
            <NotificationDropdown currentUserId={user?.id || 'dg'} />
            
            {/* Profil Badge */}
            <div className="flex items-center gap-3 ml-2 lg:ml-4 border-l border-outline-variant pl-4 lg:pl-6">
              <div className="w-10 h-10 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-bold text-xs">
                DG
              </div>
              <div className="hidden lg:block text-sm">
                <div className="font-bold text-on-surface">Dr. Directeur Général</div>
                <div className="text-on-surface-variant text-xs">Gouvernance ISGI</div>
              </div>
            </div>
          </div>
        </header>

        {/* Page Content */}
        <main className="flex-1 overflow-y-auto bg-surface-container-lowest p-4 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
