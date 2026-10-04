import React, { useState } from 'react';
import type { Conversation, ConversationParticipant, MessageChat, UserPresence } from '../../types';
import {
  X,
  Users,
  User,
  ShieldCheck,
  FileText,
  Image as ImageIcon,
  Download,
  Calendar,
  Info,
  Clock,
  ExternalLink
} from 'lucide-react';
import { cn } from '../../lib/utils';
import { getFileBadgeInfo, formatFileSize, downloadAttachment } from '../../utils/fileHelpers';

interface ChatInfoDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  conversation: Conversation;
  participants: ConversationParticipant[];
  messages: MessageChat[];
  presences: UserPresence[];
  currentUserId: string;
}

export const ChatInfoDrawer: React.FC<ChatInfoDrawerProps> = ({
  isOpen,
  onClose,
  conversation,
  participants,
  messages,
  presences,
  currentUserId
}) => {
  const [activeTab, setActiveTab] = useState<'info' | 'medias' | 'docs'>('info');

  if (!isOpen) return null;

  const isGroup = conversation.type === 'group';

  // Médias partagés (images et vidéos)
  const sharedMedia = messages.filter(m => m.type_message === 'image' || m.type_message === 'video');

  // Documents partagés (pdf, docx, xlsx, etc.)
  const sharedDocs = messages.filter(m => m.type_message === 'document' || (m.fichier_url && m.type_message !== 'image' && m.type_message !== 'video'));

  const presenceMap = new Map(presences.map(p => [p.user_id, p]));

  return (
    <div className="w-80 border-l border-outline-variant bg-surface-container-lowest flex flex-col h-full animate-in slide-in-from-right duration-200">
      {/* Header */}
      <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
        <div className="flex items-center gap-2">
          <Info className="w-4 h-4 text-primary" />
          <h3 className="font-bold text-xs text-on-surface">
            {isGroup ? 'Détails du Groupe' : 'Profil du Contact'}
          </h3>
        </div>
        <button
          onClick={onClose}
          className="p-1 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Profil Banner */}
      <div className="p-5 text-center border-b border-outline-variant bg-surface-container-lowest">
        <div
          className="w-16 h-16 mx-auto rounded-2xl flex items-center justify-center text-white font-extrabold text-2xl shadow-sm mb-3"
          style={{ backgroundColor: conversation.couleur || '#2563eb' }}
        >
          {conversation.titre ? conversation.titre.charAt(0).toUpperCase() : <User className="w-8 h-8" />}
        </div>

        <h4 className="font-bold text-sm text-on-surface">{conversation.titre || 'Discussion'}</h4>
        <p className="text-[11px] text-on-surface-variant mt-0.5">
          {isGroup ? `${participants.length} membres` : (conversation.description || 'Membre ISGI')}
        </p>
        {conversation.description && isGroup && (
          <p className="text-xs text-on-surface-variant/80 mt-2 italic px-2">
            « {conversation.description} »
          </p>
        )}
      </div>

      {/* Tabs */}
      <div className="flex border-b border-outline-variant text-xs">
        <button
          onClick={() => setActiveTab('info')}
          className={cn(
            "flex-1 py-2.5 font-semibold text-center transition-colors border-b-2",
            activeTab === 'info'
              ? "border-primary text-primary font-bold"
              : "border-transparent text-on-surface-variant hover:text-on-surface"
          )}
        >
          {isGroup ? 'Membres' : 'Infos'}
        </button>
        <button
          onClick={() => setActiveTab('medias')}
          className={cn(
            "flex-1 py-2.5 font-semibold text-center transition-colors border-b-2",
            activeTab === 'medias'
              ? "border-primary text-primary font-bold"
              : "border-transparent text-on-surface-variant hover:text-on-surface"
          )}
        >
          Médias ({sharedMedia.length})
        </button>
        <button
          onClick={() => setActiveTab('docs')}
          className={cn(
            "flex-1 py-2.5 font-semibold text-center transition-colors border-b-2",
            activeTab === 'docs'
              ? "border-primary text-primary font-bold"
              : "border-transparent text-on-surface-variant hover:text-on-surface"
          )}
        >
          Fichiers ({sharedDocs.length})
        </button>
      </div>

      {/* Tab Content */}
      <div className="flex-1 overflow-y-auto custom-scrollbar p-3 text-xs">
        {/* Tab 1: Informations / Membres */}
        {activeTab === 'info' && (
          <div className="space-y-4">
            {isGroup ? (
              <div className="space-y-2">
                <p className="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider px-1">
                  Membres du Groupe ({participants.length})
                </p>
                <div className="divide-y divide-outline-variant/40 rounded-xl border border-outline-variant bg-surface-container-low overflow-hidden">
                  {participants.map(part => {
                    const pres = presenceMap.get(part.user_id);
                    const isOnline = pres?.en_ligne;
                    return (
                      <div key={part.id} className="p-2.5 flex items-center justify-between">
                        <div className="flex items-center gap-2.5">
                          <div className="relative">
                            <div className="w-8 h-8 rounded-full bg-surface-container-highest text-on-surface font-bold flex items-center justify-center text-xs">
                              {part.user_nom.charAt(0)}
                            </div>
                            {isOnline && (
                              <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-1 ring-surface-container-lowest" />
                            )}
                          </div>
                          <div>
                            <p className="font-bold text-xs text-on-surface leading-tight">
                              {part.user_nom} {part.user_id === currentUserId && '(Vous)'}
                            </p>
                            <p className="text-[10px] text-on-surface-variant">{part.user_role || 'Membre'}</p>
                          </div>
                        </div>

                        {part.statut_role === 'admin' && (
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-amber-500/15 text-amber-600 dark:text-amber-400">
                            Admin
                          </span>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            ) : (
              <div className="p-3 bg-surface-container-low rounded-xl border border-outline-variant space-y-2">
                <div>
                  <p className="text-[10px] text-on-surface-variant uppercase font-bold">Statut</p>
                  <p className="text-xs font-semibold text-emerald-600 flex items-center gap-1.5 mt-0.5">
                    <span className="w-2 h-2 rounded-full bg-emerald-500" />
                    En ligne
                  </p>
                </div>
                <div>
                  <p className="text-[10px] text-on-surface-variant uppercase font-bold">Rôle institutionnel</p>
                  <p className="text-xs text-on-surface mt-0.5 font-medium">{conversation.description || 'Membre ISGI'}</p>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Tab 2: Médias (Photos / Vidéos) */}
        {activeTab === 'medias' && (
          <div>
            {sharedMedia.length === 0 ? (
              <p className="text-center text-on-surface-variant py-8 italic text-xs">
                Aucune photo ou vidéo partagée.
              </p>
            ) : (
              <div className="grid grid-cols-3 gap-1.5">
                {sharedMedia.map(m => (
                  <div key={m.id} className="aspect-square rounded-lg overflow-hidden border border-outline-variant bg-surface-container relative group">
                    {m.type_message === 'image' ? (
                      <img src={m.fichier_url} alt={m.fichier_nom} className="w-full h-full object-cover" />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center bg-black text-white text-[10px]">
                        VIDEO
                      </div>
                    )}
                    <button
                      onClick={() => m.fichier_url && downloadAttachment(m.fichier_url, m.fichier_nom || 'media')}
                      className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white"
                      title="Télécharger"
                    >
                      <Download className="w-4 h-4" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Tab 3: Documents (PDF, DOCX, XLSX) */}
        {activeTab === 'docs' && (
          <div>
            {sharedDocs.length === 0 ? (
              <p className="text-center text-on-surface-variant py-8 italic text-xs">
                Aucun document PDF, Word ou Excel partagé.
              </p>
            ) : (
              <div className="space-y-2">
                {sharedDocs.map(m => {
                  const badge = getFileBadgeInfo(m.fichier_nom, m.fichier_type);
                  return (
                    <div
                      key={m.id}
                      className="p-2.5 rounded-xl border border-outline-variant bg-surface-container-low flex items-center justify-between gap-2 hover:bg-surface-container transition-colors"
                    >
                      <div className="flex items-center gap-2.5 overflow-hidden">
                        <span className={cn("px-2 py-1 rounded-md text-[10px] font-bold border shrink-0", badge.bgColor, badge.textColor, badge.borderColor)}>
                          {badge.label}
                        </span>
                        <div className="overflow-hidden">
                          <p className="text-xs font-semibold text-on-surface truncate">{m.fichier_nom || 'Document'}</p>
                          <p className="text-[10px] text-on-surface-variant">{formatFileSize(m.fichier_taille)}</p>
                        </div>
                      </div>

                      {m.fichier_url && (
                        <button
                          onClick={() => downloadAttachment(m.fichier_url!, m.fichier_nom || 'document')}
                          className="p-1.5 rounded-lg text-primary hover:bg-primary/10 transition-colors shrink-0"
                          title="Télécharger"
                        >
                          <Download className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};
