import React, { useState, useRef, useEffect } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { useNavigate } from 'react-router-dom';
import { 
  Bell, 
  CheckCheck, 
  UserPlus, 
  MessageSquare, 
  Clock, 
  CheckCircle2, 
  XCircle,
  CalendarDays
} from 'lucide-react';
import { db } from '../../db/db';
import type { NotificationItem } from '../../types/academic';
import { cn } from '../../lib/utils';

interface NotificationDropdownProps {
  currentUserId?: string;
}

export function NotificationDropdown({ currentUserId = 'gestionnaire_default' }: NotificationDropdownProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [activeFilter, setActiveFilter] = useState<'all' | 'unread' | 'actions'>('all');
  const dropdownRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  const notifications = useLiveQuery(async () => {
    try {
      const items = await db.notifications.toArray();
      return items.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
    } catch {
      return [];
    }
  }) || [];

  const unreadCount = notifications.filter(n => !n.lu).length;

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    }
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  const filteredNotifs = notifications.filter(n => {
    if (activeFilter === 'unread') return !n.lu;
    if (activeFilter === 'actions') return n.type === 'invitation';
    return true;
  });

  const markAsRead = async (id: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    try {
      await db.notifications.update(id, { lu: true });
    } catch (err) {
      console.error(err);
    }
  };

  const markAllAsRead = async () => {
    try {
      const unreadIds = notifications.filter(n => !n.lu).map(n => n.id);
      for (const id of unreadIds) {
        await db.notifications.update(id, { lu: true });
      }
    } catch (err) {
      console.error(err);
    }
  };

  const handleAcceptInvitation = async (notif: NotificationItem, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      if (notif.conversation_id) {
        const convId = notif.conversation_id;
        const participant = await db.conversation_participants
          .where({ conversation_id: convId, user_id: currentUserId })
          .first();

        if (participant) {
          await db.conversation_participants.update(participant.id, {
            statut_invitation: 'accepte',
            joined_at: new Date().toISOString()
          });
        }
      }
      await db.notifications.update(notif.id, { 
        lu: true, 
        titre: 'Invitation acceptée',
        description: `Vous avez rejoint la discussion avec succès.`
      });
      navigate('/messenger');
      setIsOpen(false);
    } catch (err) {
      console.error(err);
    }
  };

  const handleDeclineInvitation = async (notif: NotificationItem, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      if (notif.conversation_id) {
        const convId = notif.conversation_id;
        const participant = await db.conversation_participants
          .where({ conversation_id: convId, user_id: currentUserId })
          .first();

        if (participant) {
          await db.conversation_participants.update(participant.id, {
            statut_invitation: 'refuse'
          });
        }
      }
      await db.notifications.update(notif.id, { 
        lu: true, 
        titre: 'Invitation déclinée',
        description: `Vous avez refusé cette invitation.`
      });
    } catch (err) {
      console.error(err);
    }
  };

  const handleNotificationClick = (notif: NotificationItem) => {
    markAsRead(notif.id);
    if (notif.type === 'message' || notif.type === 'invitation' || notif.conversation_id) {
      navigate('/messenger');
    } else if (notif.lien_tab === 'emploi_temps' || notif.type === 'system') {
      navigate('/emploi-du-temps');
    } else if (notif.lien_tab === 'calendrier' || notif.type === 'calendrier') {
      navigate('/calendrier');
    }
    setIsOpen(false);
  };

  const getNotifIcon = (type: string) => {
    switch (type) {
      case 'invitation':
        return <UserPlus className="w-4 h-4 text-primary" />;
      case 'message':
        return <MessageSquare className="w-4 h-4 text-emerald-500" />;
      case 'calendrier':
        return <CalendarDays className="w-4 h-4 text-purple-500" />;
      default:
        return <Bell className="w-4 h-4 text-amber-500" />;
    }
  };

  const formatRelativeTime = (dateStr: string) => {
    try {
      const now = new Date();
      const date = new Date(dateStr);
      const diffMin = Math.floor((now.getTime() - date.getTime()) / (1000 * 60));
      if (diffMin < 1) return "À l'instant";
      if (diffMin < 60) return `Il y a ${diffMin} min`;
      const diffHours = Math.floor(diffMin / 60);
      if (diffHours < 24) return `Il y a ${diffHours} h`;
      const diffDays = Math.floor(diffHours / 24);
      return `Il y a ${diffDays} j`;
    } catch {
      return '';
    }
  };

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="p-2 lg:p-2.5 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors relative cursor-pointer"
        title="Notifications et demandes"
        aria-label="Centre de notifications"
      >
        <Bell className="w-5 h-5" />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-rose-600 text-white font-bold text-[10px] rounded-full flex items-center justify-center px-1 shadow-xs animate-pulse">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {isOpen && (
        <div className="absolute right-0 mt-2 w-80 sm:w-96 bg-surface-container-lowest border border-outline-variant rounded-2xl shadow-2xl z-50 overflow-hidden animate-in fade-in-50 zoom-in-95 duration-150">
          <div className="p-3.5 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-sm text-on-surface">Notifications</h3>
              {unreadCount > 0 && (
                <span className="px-2 py-0.5 text-[10px] font-bold bg-primary/10 text-primary rounded-full">
                  {unreadCount} nouvelle{unreadCount > 1 ? 's' : ''}
                </span>
              )}
            </div>
            {unreadCount > 0 && (
              <button
                onClick={markAllAsRead}
                className="text-[11px] font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer"
              >
                <CheckCheck className="w-3.5 h-3.5" /> Tout marquer lu
              </button>
            )}
          </div>

          <div className="flex border-b border-outline-variant px-3 py-1.5 gap-2 bg-surface-container-lowest text-xs">
            <button
              onClick={() => setActiveFilter('all')}
              className={cn(
                "px-2.5 py-1 rounded-lg font-medium transition-colors text-[11px]",
                activeFilter === 'all'
                  ? "bg-surface-container-high text-on-surface font-bold"
                  : "text-on-surface-variant hover:bg-surface-container"
              )}
            >
              Toutes ({notifications.length})
            </button>
            <button
              onClick={() => setActiveFilter('unread')}
              className={cn(
                "px-2.5 py-1 rounded-lg font-medium transition-colors text-[11px]",
                activeFilter === 'unread'
                  ? "bg-surface-container-high text-on-surface font-bold"
                  : "text-on-surface-variant hover:bg-surface-container"
              )}
            >
              Non lues ({unreadCount})
            </button>
            <button
              onClick={() => setActiveFilter('actions')}
              className={cn(
                "px-2.5 py-1 rounded-lg font-medium transition-colors text-[11px]",
                activeFilter === 'actions'
                  ? "bg-surface-container-high text-on-surface font-bold"
                  : "text-on-surface-variant hover:bg-surface-container"
              )}
            >
              Invitations
            </button>
          </div>

          <div className="max-h-[380px] overflow-y-auto divide-y divide-outline-variant/30">
            {filteredNotifs.length === 0 ? (
              <div className="p-8 text-center text-on-surface-variant">
                <Bell className="w-8 h-8 mx-auto mb-2 opacity-30" />
                <p className="text-xs">Aucune notification.</p>
              </div>
            ) : (
              filteredNotifs.map(notif => (
                <div
                  key={notif.id}
                  onClick={() => handleNotificationClick(notif)}
                  className={cn(
                    "p-3.5 flex gap-3 cursor-pointer transition-colors relative text-left",
                    notif.lu ? "hover:bg-surface-container/40" : "bg-primary/5 hover:bg-primary/10"
                  )}
                >
                  <div className="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center shrink-0 mt-0.5">
                    {getNotifIcon(notif.type)}
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-1 mb-0.5">
                      <h4 className={cn("text-xs leading-snug", notif.lu ? "font-semibold text-on-surface" : "font-bold text-on-surface")}>
                        {notif.titre}
                      </h4>
                      <span className="text-[10px] text-on-surface-variant shrink-0 flex items-center gap-0.5">
                        <Clock className="w-2.5 h-2.5" />
                        {formatRelativeTime(notif.created_at)}
                      </span>
                    </div>

                    {notif.description && (
                      <p className="text-[11px] text-on-surface-variant line-clamp-2 leading-relaxed">
                        {notif.description}
                      </p>
                    )}

                    {notif.type === 'invitation' && !notif.lu && (
                      <div className="mt-2.5 flex items-center gap-2">
                        <button
                          onClick={e => handleAcceptInvitation(notif, e)}
                          className="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold flex items-center gap-1 shadow-xs transition-colors cursor-pointer"
                        >
                          <CheckCircle2 className="w-3 h-3" /> Accepter
                        </button>
                        <button
                          onClick={e => handleDeclineInvitation(notif, e)}
                          className="px-2.5 py-1 bg-surface-container hover:bg-rose-500/20 text-rose-600 rounded-lg text-[10px] font-semibold flex items-center gap-1 transition-colors cursor-pointer"
                        >
                          <XCircle className="w-3 h-3" /> Refuser
                        </button>
                      </div>
                    )}
                  </div>

                  {!notif.lu && (
                    <div className="w-2 h-2 rounded-full bg-primary shrink-0 self-center" />
                  )}
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
