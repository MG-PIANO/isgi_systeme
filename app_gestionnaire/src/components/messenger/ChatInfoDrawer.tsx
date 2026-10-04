import React, { useState } from 'react';
import type { Conversation, ConversationParticipant, MessageChat, UserPresence } from '../../types/academic';
import {
  X,
  User,
  ShieldCheck,
  FileText,
  Image as ImageIcon,
  Download,
  Info
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
  presences
}) => {
  const [activeTab, setActiveTab] = useState<'info' | 'medias' | 'docs'>('info');

  if (!isOpen) return null;

  const isGroup = conversation.type === 'group';
  const sharedMedia = messages.filter(m => m.type_message === 'image' || m.type_message === 'video');
  const sharedDocs = messages.filter(m => m.type_message === 'document' || (m.fichier_url && m.type_message !== 'image' && m.type_message !== 'video'));
  const presenceMap = new Map(presences.map(p => [p.user_id, p]));

  return (
    <div className="w-80 border-l border-outline-variant bg-surface-container-lowest flex flex-col h-full animate-in slide-in-from-right duration-200">
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

      <div className="flex-1 overflow-y-auto p-4 space-y-3">
        {activeTab === 'info' && (
          <div>
            {isGroup ? (
              <div className="space-y-2">
                <div className="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-2">
                  Liste des membres ({participants.length})
                </div>
                {participants.map(p => {
                  const pres = presenceMap.get(p.user_id);
                  const isOnline = pres?.en_ligne ?? false;
                  return (
                    <div key={p.id} className="flex items-center justify-between p-2 rounded-xl bg-surface-container-low">
                      <div className="flex items-center gap-2.5">
                        <div className="relative">
                          <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center font-bold text-xs text-primary">
                            {p.user_nom.charAt(0).toUpperCase()}
                          </div>
                          {isOnline && (
                            <span className="absolute bottom-0 right-0 w-2 h-2 rounded-full bg-emerald-500 ring-2 ring-surface-container-lowest" />
                          )}
                        </div>
                        <div>
                          <div className="text-xs font-semibold text-on-surface flex items-center gap-1.5">
                            {p.user_nom}
                            {p.statut_role === 'admin' && (
                              <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                Admin
                              </span>
                            )}
                          </div>
                          <div className="text-[10px] text-on-surface-variant">
                            {p.user_role || (isOnline ? 'En ligne' : 'Hors ligne')}
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="space-y-3 text-xs">
                <div className="p-3 rounded-xl bg-surface-container-low border border-outline-variant">
                  <div className="font-semibold text-on-surface mb-1 flex items-center gap-1.5">
                    <ShieldCheck className="w-4 h-4 text-emerald-600" />
                    Chiffrement & Sécurité
                  </div>
                  <p className="text-[11px] text-on-surface-variant leading-relaxed">
                    Les échanges au sein du système ISGI sont sécurisés et historisés pour garantir la traçabilité académique et administrative.
                  </p>
                </div>
              </div>
            )}
          </div>
        )}

        {activeTab === 'medias' && (
          <div>
            {sharedMedia.length === 0 ? (
              <div className="text-center py-8 text-on-surface-variant">
                <ImageIcon className="w-8 h-8 mx-auto mb-2 opacity-30" />
                <p className="text-xs">Aucune photo ni vidéo partagée.</p>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-2">
                {sharedMedia.map(m => (
                  <div key={m.id} className="relative aspect-square rounded-xl overflow-hidden group border border-outline-variant bg-black">
                    {m.type_message === 'image' ? (
                      <img src={m.fichier_url} alt="" className="w-full h-full object-cover" />
                    ) : (
                      <video src={m.fichier_url} className="w-full h-full object-cover" />
                    )}
                    <button
                      onClick={() => downloadAttachment(m.fichier_url || '', m.fichier_nom || 'media')}
                      className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center text-white transition-opacity"
                    >
                      <Download className="w-5 h-5" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {activeTab === 'docs' && (
          <div>
            {sharedDocs.length === 0 ? (
              <div className="text-center py-8 text-on-surface-variant">
                <FileText className="w-8 h-8 mx-auto mb-2 opacity-30" />
                <p className="text-xs">Aucun document partagé.</p>
              </div>
            ) : (
              <div className="space-y-2">
                {sharedDocs.map(m => {
                  const badge = getFileBadgeInfo(m.fichier_nom, m.fichier_type);
                  return (
                    <div key={m.id} className="p-2.5 rounded-xl border border-outline-variant bg-surface-container-low flex items-center justify-between text-xs">
                      <div className="flex items-center gap-2.5 min-w-0">
                        <span className={cn("px-1.5 py-0.5 rounded text-[10px] font-bold border", badge.bgColor, badge.textColor, badge.borderColor)}>
                          {badge.label}
                        </span>
                        <div className="min-w-0">
                          <div className="font-semibold text-on-surface truncate">{m.fichier_nom || 'Fichier'}</div>
                          <div className="text-[10px] text-on-surface-variant">{formatFileSize(m.fichier_taille)}</div>
                        </div>
                      </div>
                      <button
                        onClick={() => downloadAttachment(m.fichier_url || '', m.fichier_nom || 'document')}
                        className="p-1.5 text-on-surface-variant hover:text-primary rounded-lg transition-colors"
                      >
                        <Download className="w-4 h-4" />
                      </button>
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
