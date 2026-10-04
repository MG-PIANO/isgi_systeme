import React from 'react';
import { 
  LayoutDashboard, 
  QrCode, 
  ClipboardList, 
  IdCard, 
  DoorOpen, 
  CalendarDays, 
  Settings,
  Flame,
  ArrowRightLeft
} from 'lucide-react';

export type TabType = 'dashboard' | 'scanner' | 'registre' | 'badges' | 'salles' | 'edt' | 'parametres';

interface SidebarProps {
  activeTab: TabType;
  setActiveTab: (tab: TabType) => void;
  countSurSite?: number;
}

export const Sidebar: React.FC<SidebarProps> = ({ activeTab, setActiveTab, countSurSite = 0 }) => {
  const menuItems = [
    {
      id: 'dashboard' as TabType,
      label: 'Tableau de Bord',
      icon: LayoutDashboard,
      badge: null
    },
    {
      id: 'scanner' as TabType,
      label: 'Poste de Scan',
      icon: QrCode,
      badge: 'Direct'
    },
    {
      id: 'registre' as TabType,
      label: 'Registre des Passages',
      icon: ClipboardList,
      badge: countSurSite > 0 ? `${countSurSite} sur site` : null
    },
    {
      id: 'badges' as TabType,
      label: 'Badges & QR Codes',
      icon: IdCard,
      badge: null
    },
    {
      id: 'salles' as TabType,
      label: 'État des Salles',
      icon: DoorOpen,
      badge: null
    },
    {
      id: 'edt' as TabType,
      label: 'Emplois du Temps',
      icon: CalendarDays,
      badge: null
    },
    {
      id: 'parametres' as TabType,
      label: 'Paramètres',
      icon: Settings,
      badge: null
    }
  ];

  return (
    <aside className="w-64 bg-slate-900 border-r border-slate-800 flex flex-col justify-between py-5 select-none shrink-0">
      <div className="px-3">
        {/* En-tête Navigation */}
        <div className="px-3 mb-4 text-[11px] font-bold uppercase tracking-wider text-slate-400">
          Navigation Principale
        </div>

        {/* Liste des Menus */}
        <nav className="space-y-1.5">
          {menuItems.map((item) => {
            const Icon = item.icon;
            const isActive = activeTab === item.id;

            return (
              <button
                key={item.id}
                onClick={() => setActiveTab(item.id)}
                className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl font-medium text-sm transition-all duration-200 ${
                  isActive
                    ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30'
                    : 'text-slate-300 hover:text-white hover:bg-slate-800/70'
                }`}
              >
                <div className="flex items-center gap-3">
                  <Icon className={`w-5 h-5 ${isActive ? 'text-white' : 'text-slate-400 group-hover:text-white'}`} />
                  <span>{item.label}</span>
                </div>

                {item.badge && (
                  <span
                    className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                      isActive
                        ? 'bg-white/20 text-white'
                        : item.id === 'scanner'
                        ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                        : 'bg-blue-500/20 text-blue-400 border border-blue-500/30'
                    }`}
                  >
                    {item.badge}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Raccourci Règle Métier & Info Portail */}
      <div className="px-4">
        <div className="p-3.5 rounded-xl bg-slate-800/60 border border-slate-700/60">
          <div className="flex items-center gap-2 text-xs font-semibold text-blue-400 mb-1.5">
            <ArrowRightLeft className="w-3.5 h-3.5" />
            <span>Règle des Scans</span>
          </div>
          <p className="text-[11px] text-slate-400 leading-relaxed">
            • <strong>1er scan :</strong> Arrivée<br />
            • <strong>Suivants :</strong> Tours & Sorties<br />
            • <strong>Dernier :</strong> Heure de départ
          </p>
        </div>
      </div>
    </aside>
  );
};
