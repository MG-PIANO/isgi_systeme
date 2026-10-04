import React, { useState, useRef, useEffect } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { 
  Bell, 
  Check, 
  CheckCheck, 
  UserPlus, 
  MessageSquare, 
  FileText, 
  X,
  ChevronRight,
  Clock,
  CheckCircle2,
  XCircle,
  Award
} from 'lucide-react';
import { db } from '../../db/db';
import type { NotificationItem } from '../../types';
import { cn } from '../../lib/utils';

interface NotificationDropdownProps {
  onNavigate: (tab: string, itemId?: string) => void;
  currentUserId?: string;
}

export function NotificationDropdown({ onNavigate, currentUserId = 'dac_default' }: NotificationDropdownProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [activeFilter, setActiveFilter] = useState<'all' | 'unread' | 'actions'>('all');
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Charger les notifications en direct depuis Dexie
  const notifications = useLiveQuery(async () => {
    try {
      const items = await db.notifications.toArray();
      // Trier par date décroissante
      return items.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
    } catch {
      return [];
    }
  }) || [];

  const unreadCount = notifications.filter(n => !n.lu).length;

  // Fermer le dropdown quand on clique en dehors
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

  // Filtrage des notifications
  const filteredNotifs = notifications.filter(n => {
    if (activeFilter === 'unread') return !n.lu;
    if (activeFilter === 'actions') return n.type === 'invitation_groupe' || n.type === 'sujet_depose';
    return true;
  });

  // Marquer une notification comme lue
  const markAsRead = async (id: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    try {
      await db.notifications.update(id, { lu: true });
    } catch (err) {
      console.error(err);
    }
  };

  // Marquer toutes comme lues
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

  // Accepter une invitation de groupe
  const handleAcceptInvitation = async (notif: NotificationItem, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      if (notif.donnees_extra?.conversation_id) {
        const convId = notif.donnees_extra.conversation_id;
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
        description: `Vous avez rejoint le groupe avec succès.`
      });
      onNavigate('messenger', notif.donnees_extra?.conversation_id);
      setIsOpen(false);
    } catch (err) {
      console.error(err);
    }
  };

  // Refuser une invitation de groupe
  const handleDeclineInvitation = async (notif: NotificationItem, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      if (notif.donnees_extra?.conversation_id) {
        const convId = notif.donnees_extra.conversation_id;
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

  // Clic sur un item
  const handleItemClick = async (notif: NotificationItem) => {
    if (!notif.lu) {
      await markAsRead(notif.id);
    }
    setIsOpen(false);
    if (notif.lien_tab) {
      onNavigate(notif.lien_tab, notif.lien_id);
    }
  };

  // Formatage de temps relatif
  const formatTime = (isoString: string) => {
    try {
      const diffMs = Date.now() - new Date(isoString).getTime();
      const diffMin = Math.floor(diffMs / 60000);
      const diffHours = Math.floor(diffMin / 60);
      const diffDays = Math.floor(diffHours / 24);

      if (diffMin < 1) return 'À l\'instant';
      if (diffMin < 60) return `Il y a ${diffMin} min`;
      if (diffHours < 24) return `Il y a ${diffHours} h`;
      if (diffDays === 1) return 'Hier';
      return `Il y a ${diffDays} j`;
    } catch {
      return '';
    }
  };

  const getIcon = (type: NotificationItem['type']) => {
    switch (type) {
      case 'invitation_groupe':
        return <UserPlus className="w-4 h-4 text-primary" />;
      case 'sujet_depose':
        return <FileText className="w-4 h-4 text-amber-500" />;
      case 'sujet_valide':
        return <CheckCircle2 className="w-4 h-4 text-emerald-500" />;
      case 'sujet_rejet':
        return <XCircle className="w-4 h-4 text-rose-500" />;
      case 'notes_soumises':
        return <Award className="w-4 h-4 text-amber-500" />;
      case 'notes_validees':
        return <CheckCircle2 className="w-4 h-4 text-emerald-500" />;
      case 'notes_rejetees':
        return <XCircle className="w-4 h-4 text-rose-500" />;
      case 'message':
        return <MessageSquare className="w-4 h-4 text-blue-500" />;
      case 'system':
      default:
        return <Bell className="w-4 h-4 text-purple-500" />;
    }
  };

  return (
    <div className="relative" ref={dropdownRef}>
      {/* Bouton Cloche avec pastille rouge style Facebook */}
      <button 
        onClick={() => setIsOpen(!isOpen)}
        className={cn(
          "p-2 lg:p-3 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-colors relative",
          isOpen && "bg-surface-container-highest text-primary"
        )}
        title="Centre de notifications ISGI"
      >
        <Bell className="w-5 h-5" />
        {unreadCount > 0 && (
          <span className="absolute top-1.5 right-1.5 min-w-4 h-4 px-1 bg-red-600 text-[10px] font-bold text-white rounded-full flex items-center justify-center animate-pulse shadow-sm border border-surface-container-lowest">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      {/* Dropdown Menu Style Facebook */}
      {isOpen && (
        <div className="absolute right-0 mt-3 w-80 sm:w-96 md:w-[420px] bg-surface-container-lowest border border-outline-variant/60 rounded-2xl shadow-2xl z-50 overflow-hidden animate-in fade-in zoom-in-95 duration-150">
          {/* Header du panneau */}
          <div className="p-4 border-b border-outline-variant/50 bg-surface-container-low/50">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <h3 className="font-bold text-lg text-on-surface">Notifications</h3>
                {unreadCount > 0 && (
                  <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">
                    {unreadCount} nouvelle{unreadCount > 1 ? 's' : ''}
                  </span>
                )}
              </div>
              {unreadCount > 0 && (
                <button
                  onClick={markAllAsRead}
                  className="text-xs text-primary hover:underline font-medium flex items-center gap-1"
                >
                  <CheckCheck className="w-3.5 h-3.5" />
                  <span>Tout marquer comme lu</span>
                </button>
              )}
            </div>

            {/* Onglets Filtres */}
            <div className="flex items-center gap-1 bg-surface-container-lowest p-1 rounded-xl border border-outline-variant/40">
              <button
                onClick={() => setActiveFilter('all')}
                className={cn(
                  "flex-1 py-1.5 text-xs font-semibold rounded-lg transition-all text-center",
                  activeFilter === 'all'
                    ? "bg-primary text-on-primary shadow-xs"
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
              >
                Toutes ({notifications.length})
              </button>
              <button
                onClick={() => setActiveFilter('unread')}
                className={cn(
                  "flex-1 py-1.5 text-xs font-semibold rounded-lg transition-all text-center",
                  activeFilter === 'unread'
                    ? "bg-primary text-on-primary shadow-xs"
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
              >
                Non lues ({unreadCount})
              </button>
              <button
                onClick={() => setActiveFilter('actions')}
                className={cn(
                  "flex-1 py-1.5 text-xs font-semibold rounded-lg transition-all text-center",
                  activeFilter === 'actions'
                    ? "bg-primary text-on-primary shadow-xs"
                    : "text-on-surface-variant hover:bg-surface-container-highest"
                )}
              >
                Demandes
              </button>
            </div>
          </div>

          {/* Liste des notifications */}
          <div className="max-h-[460px] overflow-y-auto divide-y divide-outline-variant/30 custom-scrollbar">
            {filteredNotifs.length === 0 ? (
              <div className="py-12 px-6 text-center text-on-surface-variant">
                <Bell className="w-10 h-10 mx-auto text-outline-variant mb-2 opacity-60" />
                <p className="text-sm font-medium">Aucune notification pour le moment</p>
                <p className="text-xs text-outline-variant mt-1">Vous êtes à jour dans vos activités.</p>
              </div>
            ) : (
              filteredNotifs.map((notif) => {
                const isGroupInvitation = notif.type === 'invitation_groupe' && notif.donnees_extra?.conversation_id;

                return (
                  <div
                    key={notif.id}
                    onClick={() => handleItemClick(notif)}
                    className={cn(
                      "p-3.5 sm:p-4 hover:bg-surface-container-highest/60 transition-colors cursor-pointer flex items-start gap-3 relative group",
                      !notif.lu && "bg-primary/5 dark:bg-primary/10"
                    )}
                  >
                    {/* Pastille non lue */}
                    {!notif.lu && (
                      <span className="absolute left-1.5 top-1/2 -translate-y-1/2 w-2 h-2 rounded-full bg-red-500" />
                    )}

                    {/* Icône avec badge */}
                    <div className="relative shrink-0 mt-0.5">
                      <div className="w-10 h-10 rounded-full bg-surface-container-high flex items-center justify-center border border-outline-variant/50">
                        {notif.auteur_nom ? (
                          <span className="font-bold text-xs text-on-surface">
                            {notif.auteur_nom.charAt(0).toUpperCase()}
                          </span>
                        ) : (
                          <Bell className="w-4 h-4 text-on-surface-variant" />
                        )}
                      </div>
                      <div className="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-surface-container-lowest border border-outline-variant/60 flex items-center justify-center shadow-xs">
                        {getIcon(notif.type)}
                      </div>
                    </div>

                    {/* Contenu de la notification */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-start justify-between gap-1">
                        <p className={cn(
                          "text-xs sm:text-sm text-on-surface leading-tight",
                          !notif.lu ? "font-bold" : "font-medium"
                        )}>
                          {notif.titre}
                        </p>
                        <span className="text-[10px] text-on-surface-variant shrink-0 whitespace-nowrap flex items-center gap-1">
                          <Clock className="w-2.5 h-2.5" />
                          {formatTime(notif.created_at)}
                        </span>
                      </div>

                      <p className="text-xs text-on-surface-variant mt-1 line-clamp-2 leading-relaxed">
                        {notif.description}
                      </p>

                      {/* Boutons d'action pour les invitations de groupe */}
                      {isGroupInvitation && !notif.lu && (
                        <div className="mt-2.5 flex items-center gap-2">
                          <button
                            onClick={(e) => handleAcceptInvitation(notif, e)}
                            className="px-3 py-1 bg-primary text-on-primary text-xs font-semibold rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-1 shadow-xs"
                          >
                            <Check className="w-3.5 h-3.5" />
                            <span>Confirmer / Rejoindre</span>
                          </button>
                          <button
                            onClick={(e) => handleDeclineInvitation(notif, e)}
                            className="px-3 py-1 bg-surface-container-highest text-on-surface text-xs font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors"
                          >
                            <span>Décliner</span>
                          </button>
                        </div>
                      )}

                      {/* Bouton d'action pour sujet déposé */}
                      {notif.type === 'sujet_depose' && (
                        <div className="mt-2">
                          <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-primary group-hover:underline">
                            <span>Examiner le sujet</span>
                            <ChevronRight className="w-3 h-3" />
                          </span>
                        </div>
                      )}
                    </div>

                    {/* Marquer comme lu individuel au survol */}
                    {!notif.lu && (
                      <button
                        onClick={(e) => markAsRead(notif.id, e)}
                        title="Marquer comme lu"
                        className="opacity-0 group-hover:opacity-100 p-1 rounded-full hover:bg-surface-container-highest text-on-surface-variant transition-opacity self-center"
                      >
                        <Check className="w-4 h-4" />
                      </button>
                    )}
                  </div>
                );
              })
            )}
          </div>

          {/* Footer du panneau */}
          <div className="p-3 border-t border-outline-variant/40 bg-surface-container-low/30 text-center">
            <button
              onClick={() => {
                setIsOpen(false);
                onNavigate('sujets');
              }}
              className="text-xs font-semibold text-primary hover:underline"
            >
              Voir la gestion des sujets d'examens & projets
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
