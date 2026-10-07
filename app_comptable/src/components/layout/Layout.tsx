import { useState, useEffect } from 'react';
import { Outlet, Link, useLocation } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Users, 
  CreditCard, 
  LogOut, 
  Wallet, 
  FileSpreadsheet, 
  Briefcase, 
  DollarSign, 
  Menu, 
  Sun, 
  Moon, 
  History, 
  PieChart, 
  ClipboardList, 
  Settings,
  MessageSquare,
  Clock,
  CalendarDays
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import { cn } from '../../lib/utils';
import { NotificationDropdown } from './NotificationDropdown';

export function Layout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  });
  const location = useLocation();
  const { user, logout } = useAuth();

  // Compteur dynamique de notifications ou messages non lus
  const unreadCount = useLiveQuery(async () => {
    try {
      const notifs = await db.notifications.toArray();
      return notifs.filter(n => !n.lu).length;
    } catch {
      return 0;
    }
  }) || 0;

  useEffect(() => {
    localStorage.setItem('theme', theme);
    if (theme === 'dark') {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [theme]);

  const toggleTheme = () => setTheme(theme === 'light' ? 'dark' : 'light');

  const navigation = [
    { name: 'Tableau de bord', href: '/', icon: LayoutDashboard },
    { name: 'Messenger', href: '/messenger', icon: MessageSquare, badge: unreadCount },
    { name: 'Emploi du temps', href: '/emploi-du-temps', icon: Clock },
    { name: 'Calendrier académique', href: '/calendrier', icon: CalendarDays },
    { name: 'Inscriptions', href: '/inscriptions', icon: Users },
    { name: 'Paiements', href: '/paiements', icon: CreditCard },
    { name: 'Dépenses', href: '/depenses', icon: Wallet },
    { name: 'Gestion du Personnel', href: '/personnel', icon: Users },
    { name: 'Paie Personnel', href: '/paie', icon: Briefcase },
    { name: 'Avances / Emprunts', href: '/emprunts', icon: DollarSign },
    { name: 'Suivi des étudiants', href: '/suivi-etudiants', icon: ClipboardList },
    { name: 'Demandes comptes étudiants', href: '/demandes-comptes', icon: Users },
    { name: 'Suivi mensuel', href: '/suivi-mensuel', icon: FileSpreadsheet },
    { name: 'Rapports Financiers', href: '/rapports-financiers', icon: PieChart },
    { name: 'Historique', href: '/historique', icon: History }
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
        <div className="h-20 flex items-center px-6 border-b border-outline-variant">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-surface-container-lowest flex items-center justify-center overflow-hidden">
              <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain" />
            </div>
            <div className="font-bold text-xl tracking-tight text-on-surface">ISGI System</div>
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
                    ? "bg-secondary-container text-on-secondary-container" 
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
                onClick={() => setSidebarOpen(false)}
              >
                <div className="flex items-center gap-3">
                  <item.icon className="w-5 h-5" />
                  <span>{item.name}</span>
                </div>
                {item.badge !== undefined && item.badge > 0 && (
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-600 text-white">
                    {item.badge > 99 ? '99+' : item.badge}
                  </span>
                )}
              </Link>
            );
          })}
        </nav>

        {/* User Info & Logout */}
        <div className="p-4 border-t border-outline-variant mt-auto">
          <div className="flex items-center gap-3 mb-4 px-2">
            <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold">
              {user?.name?.charAt(0).toUpperCase() || 'C'}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-on-surface truncate">{user?.name || 'Comptabilité'}</p>
              <p className="text-xs text-on-surface-variant truncate">{user?.email}</p>
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
            <h1 className="text-xl lg:text-2xl font-semibold text-on-surface">
              {navigation.find(n => n.href === location.pathname)?.name || 'ISGI'}
            </h1>
          </div>

          <div className="flex items-center gap-2 lg:gap-4">
            <button 
              onClick={toggleTheme}
              className="p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors cursor-pointer"
            >
              {theme === 'dark' ? <Sun className="w-5 h-5" /> : <Moon className="w-5 h-5" />}
            </button>
            
            {/* Cloche de notifications interactive avec dropdown */}
            <NotificationDropdown currentUserId={user?.id} />

            <Link 
              to="/parametres"
              className="p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors"
            >
              <Settings className="w-5 h-5" />
            </Link>
            
            <div className="flex items-center gap-3 ml-2 lg:ml-4 border-l border-outline-variant pl-4 lg:pl-6">
              <div className="w-10 h-10 rounded-full bg-tertiary-container flex items-center justify-center text-on-tertiary-container font-semibold">
                {user?.name ? user.name.charAt(0).toUpperCase() : 'C'}
              </div>
              <div className="hidden lg:block text-sm">
                <div className="font-semibold text-on-surface">{user?.name || 'Comptabilité'}</div>
                <div className="text-on-surface-variant text-xs">{user?.location || 'Brazzaville'}</div>
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
