import { useState, useEffect, type ReactNode } from 'react';
import {
  LayoutDashboard,
  GraduationCap,
  CreditCard,
  School,
  BookOpen,
  ClipboardCheck,
  Award,
  FileSpreadsheet,
  Settings,
  LogOut,
  RefreshCw,
  Sun,
  Moon,
  Bell,
  Menu,
  X,
  UserPlus,
  CalendarDays,
  Clock,
  DoorOpen,
  MessageSquare,
  FolderKanban,
  FileCheck2,
  Video
} from 'lucide-react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, syncFromSupabase } from '../../db/db';
import { cn } from '../../lib/utils';
import { NotificationDropdown } from './NotificationDropdown';

interface LayoutProps {
  currentTab: string;
  onSelectTab: (tab: string) => void;
  onLogout: () => void;
  children: ReactNode;
}

export function Layout({ currentTab, onSelectTab, onLogout, children }: LayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  });
  const [isSyncing, setIsSyncing] = useState(false);
  const [dacUser, setDacUser] = useState<any>({
    nom: 'Secrétaire DAC',
    role: 'secretaire_dac',
    id: 'secretaire_dac_default'
  });

  useEffect(() => {
    try {
      const saved = localStorage.getItem('secretaire_dac_user');
      if (saved) setDacUser(JSON.parse(saved));
    } catch {}
  }, []);

  const unreadMessagesCount = useLiveQuery(async () => {
    try {
      const msgs = await db.messages.where('statut').notEqual('lu').toArray();
      const myId = dacUser?.id || 'dac_default';
      return msgs.filter(m => m.sender_id !== myId).length;
    } catch {
      return 0;
    }
  }, [dacUser?.id]) || 0;

  const pendingSujetsCount = useLiveQuery(async () => {
    try {
      return await db.sujets_evaluations.where('statut').equals('depose').count();
    } catch {
      return 0;
    }
  }) || 0;

  const pendingNotesCount = useLiveQuery(async () => {
    try {
      return await db.soumissions_notes.where('statut').equals('EN_ATTENTE').count();
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

  const handleSync = async () => {
    setIsSyncing(true);
    try {
      await syncFromSupabase();
    } finally {
      setIsSyncing(false);
    }
  };

  const navigation = [
    { id: 'dashboard', name: 'Tableau de bord', icon: LayoutDashboard },
    { id: 'messenger', name: 'Messenger ISGI', icon: MessageSquare, badge: unreadMessagesCount, hasRedDot: unreadMessagesCount > 0 },
    { id: 'sujets', name: 'Sujets d’Examens & Projets', icon: FolderKanban, badge: pendingSujetsCount },
    { id: 'inscriptions', name: 'Inscriptions', icon: UserPlus },
    { id: 'etudiants', name: 'Gestion Étudiants', icon: GraduationCap },
    { id: 'cartes', name: 'Cartes d’Étudiants', icon: CreditCard },
    { id: 'calendrier', name: 'Calendrier Académique', icon: CalendarDays },
    { id: 'emploi_du_temps', name: 'Emploi du Temps', icon: Clock },
    { id: 'salles', name: 'Gestion des Salles', icon: DoorOpen },
    { id: 'classes', name: 'Classes & Affectations', icon: School },
    { id: 'videotheque', name: 'Vidéothèque ISGI', icon: Video },
    { id: 'matieres', name: 'Catalogue Matières', icon: BookOpen },
    { id: 'presences', name: 'Pointage Présences', icon: ClipboardCheck },
    { id: 'notes', name: 'Saisie des Notes', icon: Award },
    { id: 'validation_notes', name: 'Validation Notes Profs', icon: FileCheck2, badge: pendingNotesCount, hasRedDot: pendingNotesCount > 0 },
    { id: 'bulletins', name: 'Bulletins & Délibérations', icon: FileSpreadsheet },
    { id: 'parametres', name: 'Paramètres', icon: Settings },
  ];


  const currentNav = navigation.find(n => n.id === currentTab);

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
        {/* Header Branding */}
        <div className="h-20 flex items-center px-6 border-b border-outline-variant">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-surface-container-lowest flex items-center justify-center overflow-hidden border border-outline-variant shadow-xs">
              <img src="./logo.jpg" alt="ISGI Logo" className="w-full h-full object-contain" />
            </div>
            <div className="font-bold text-xl tracking-tight text-on-surface flex items-center gap-2">
              <span>ISGI System</span>
              <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary text-on-primary">Secrétariat DAC</span>
            </div>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 px-4 py-6 space-y-1 overflow-y-auto custom-scrollbar">
          {navigation.map((item) => {
            const active = currentTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => {
                  onSelectTab(item.id);
                  setSidebarOpen(false);
                }}
                className={cn(
                  "w-full flex items-center gap-3 px-4 py-3 rounded-full font-medium transition-colors text-left text-sm group",
                  active 
                    ? "bg-secondary-container text-on-secondary-container font-semibold" 
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
              >
                <div className="relative shrink-0">
                  <item.icon className="w-5 h-5" />
                  {Boolean(item.hasRedDot) && (
                    <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-red-600 ring-2 ring-surface-container-low animate-pulse" />
                  )}
                </div>
                <span className="flex-1">{item.name}</span>
                {Boolean(item.badge && item.badge > 0) && (
                  <span className="px-2 py-0.5 text-xs font-bold rounded-full bg-primary text-on-primary shadow-xs">
                    {item.badge}
                  </span>
                )}
              </button>
            );
          })}
        </nav>

        {/* User Info & Logout */}
        <div className="p-4 border-t border-outline-variant mt-auto">
          <div className="flex items-center gap-3 mb-4 px-2">
            <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
              {dacUser.nom?.charAt(0).toUpperCase() || 'S'}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-on-surface truncate">{dacUser.nom || 'Secrétaire DAC'}</p>
              <p className="text-xs text-on-surface-variant truncate">Secrétariat Pédagogique</p>
            </div>
          </div>
          <button 
            onClick={onLogout}
            className="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-error hover:bg-error-container hover:text-on-error-container transition-colors"
          >
            <LogOut className="w-5 h-5" />
            <span className="font-medium text-sm">Déconnexion</span>
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex-1 flex flex-col h-screen overflow-hidden">
        {/* Top App Bar */}
        <header className="h-20 bg-surface-container-lowest border-b border-outline-variant flex items-center justify-between px-4 lg:px-8 shrink-0 z-30 print:hidden">
          <div className="flex items-center gap-4">
            <button 
              className="lg:hidden p-2 rounded-full hover:bg-surface-container-highest text-on-surface-variant"
              onClick={() => setSidebarOpen(true)}
            >
              <Menu className="w-6 h-6" />
            </button>
            <h1 className="text-xl lg:text-2xl font-semibold text-on-surface">
              {currentNav?.name || 'Secrétariat DAC'}
            </h1>
          </div>

          <div className="flex items-center gap-2 lg:gap-4">
            {/* Bouton Synchroniser */}
            <button 
              onClick={handleSync}
              disabled={isSyncing}
              className="flex items-center gap-2 bg-primary text-on-primary px-4 py-2 rounded-full text-xs lg:text-sm font-medium hover:bg-primary/90 transition-colors disabled:opacity-50"
            >
              <RefreshCw className={cn("w-4 h-4", isSyncing && "animate-spin")} />
              <span>{isSyncing ? 'Synchronisation...' : 'Synchroniser'}</span>
            </button>

            {/* Quick Messenger icon avec point rouge bien visible */}
            <button
              onClick={() => onSelectTab('messenger')}
              className={cn(
                "p-2 lg:p-3 rounded-full hover:bg-surface-container-highest transition-colors relative",
                currentTab === 'messenger' ? "text-primary bg-surface-container" : "text-on-surface-variant"
              )}
              title="Messagerie Instantanée ISGI"
            >
              <MessageSquare className="w-5 h-5" />
              {unreadMessagesCount > 0 && (
                <span className="absolute top-1.5 right-1.5 w-2.5 h-2.5 bg-red-600 rounded-full ring-2 ring-surface-container-lowest animate-pulse" />
              )}
            </button>

            {/* Thème Light/Dark */}
            <button 
              onClick={toggleTheme}
              className="p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors"
              title="Changer le thème"
            >
              {theme === 'dark' ? <Sun className="w-5 h-5" /> : <Moon className="w-5 h-5" />}
            </button>
            
            {/* Centre de notifications style Facebook */}
            <NotificationDropdown 
              onNavigate={(tab) => {
                onSelectTab(tab);
              }}
              currentUserId={dacUser?.id}
            />

            {/* Bouton Paramètres */}
            <button 
              onClick={() => onSelectTab('parametres')}
              className="p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors"
              title="Paramètres"
            >
              <Settings className="w-5 h-5" />
            </button>
            
            {/* Avatar Utilisateur */}
            <div className="flex items-center gap-3 ml-2 lg:ml-4 border-l border-outline-variant pl-4 lg:pl-6">
              <div className="w-10 h-10 rounded-full bg-tertiary-container flex items-center justify-center text-on-tertiary-container font-semibold">
                {dacUser.nom ? dacUser.nom.charAt(0).toUpperCase() : 'S'}
              </div>
              <div className="hidden lg:block text-sm">
                <div className="font-semibold text-on-surface">{dacUser.nom || 'Secrétaire DAC'}</div>
                <div className="text-xs text-on-surface-variant">Secrétariat Pédagogique</div>
              </div>
            </div>
          </div>
        </header>

        {/* Main Work Area */}
        <main className={cn(
          "flex-1 bg-surface-container-lowest",
          currentTab === 'messenger' 
            ? "p-2 lg:p-4 overflow-hidden flex flex-col min-h-0" 
            : "overflow-y-auto p-4 lg:p-8"
        )}>
          {children}
        </main>
      </div>
    </div>
  );
}
