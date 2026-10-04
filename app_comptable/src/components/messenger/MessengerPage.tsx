import React, { useState, useEffect, useRef } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, pushItemToSupabase } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import { useAuth } from '../../context/AuthContext';
import type { ConversationParticipant, MessageChat, UserPresence } from '../../types/academic';
import {
  MessageSquare,
  Search,
  Plus,
  Users,
  Paperclip,
  Send,
  Image as ImageIcon,
  Video,
  FileText,
  Download,
  CheckCheck,
  Info,
  ShieldCheck,
  Clock,
  UserCheck,
  UserX
} from 'lucide-react';
import { cn } from '../../lib/utils';
import { detectFileType, fileToBase64, formatFileSize, downloadAttachment } from '../../utils/fileHelpers';
import { NewChatModal } from './NewChatModal';
import { NewGroupModal } from './NewGroupModal';
import { ChatInfoDrawer } from './ChatInfoDrawer';

export function MessengerPage() {
  const { user } = useAuth();
  const currentUser = {
    id: user?.id || 'comptable_default',
    nom: user?.name || 'Comptable ISGI',
    role: user?.role || 'comptable'
  };

  const [activeConvId, setActiveConvId] = useState<string | null>(null);
  const [filterType, setFilterType] = useState<'all' | 'direct' | 'group' | 'unread'>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [inputText, setInputText] = useState('');
  const [isNewChatOpen, setIsNewChatOpen] = useState(false);
  const [isNewGroupOpen, setIsNewGroupOpen] = useState(false);
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [selectedImageModal, setSelectedImageModal] = useState<string | null>(null);
  const [attachmentMenuOpen, setAttachmentMenuOpen] = useState(false);
  const [uploading, setUploading] = useState(false);

  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileTypeRef = useRef<'image' | 'video' | 'document'>('image');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const allConversations = useLiveQuery(() => db.conversations.toArray()) || [];
  const allParticipants = useLiveQuery(() => db.conversation_participants.toArray()) || [];
  const allMessages = useLiveQuery(() => db.messages.toArray()) || [];
  const allPresences = useLiveQuery(() => db.user_presences.toArray()) || [];

  // Mettre à jour la présence de l'utilisateur connecté
  useEffect(() => {
    const updateMyPresence = async () => {
      const p: UserPresence = {
        user_id: currentUser.id,
        nom_complet: currentUser.nom,
        role: currentUser.role,
        en_ligne: true,
        derniere_connexion: new Date().toISOString(),
        statut_perso: 'En ligne sur l’espace Comptabilité ISGI'
      };
      await db.user_presences.put(p);
      pushItemToSupabase('user_presences', p);
    };
    updateMyPresence();

    const channel = supabase
      .channel('realtime_messenger_compta')
      .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'messages' }, payload => {
        const newMsg = payload.new as MessageChat;
        db.messages.put(newMsg);
        db.conversations.update(newMsg.conversation_id, {
          dernier_message_texte: newMsg.contenu || 'Nouveau message',
          dernier_message_date: newMsg.created_at,
          dernier_message_sender: newMsg.sender_nom
        });
      })
      .on('postgres_changes', { event: '*', schema: 'public', table: 'conversation_participants' }, payload => {
        if (payload.eventType === 'INSERT' || payload.eventType === 'UPDATE') {
          db.conversation_participants.put(payload.new as ConversationParticipant);
        }
      })
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [currentUser.id, currentUser.nom, currentUser.role]);

  // Défilement automatique vers le bas
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [allMessages, activeConvId]);

  // Conversations auxquelles participe l'utilisateur
  const myParticipationMap = new Map<string, ConversationParticipant>();
  allParticipants.forEach(p => {
    if (p.user_id === currentUser.id) {
      myParticipationMap.set(p.conversation_id, p);
    }
  });

  const myConversations = allConversations.filter(c => myParticipationMap.has(c.id));

  // Tri par date de dernier message décroissante
  myConversations.sort((a, b) => {
    const dateA = new Date(a.dernier_message_date || a.created_at || 0).getTime();
    const dateB = new Date(b.dernier_message_date || b.created_at || 0).getTime();
    return dateB - dateA;
  });

  const filteredConversations = myConversations.filter(c => {
    if (filterType === 'direct' && c.type !== 'direct') return false;
    if (filterType === 'group' && c.type !== 'group') return false;
    if (searchTerm.trim() !== '') {
      const q = searchTerm.toLowerCase();
      const matchTitre = (c.titre || '').toLowerCase().includes(q);
      const matchDesc = (c.description || '').toLowerCase().includes(q);
      return matchTitre || matchDesc;
    }
    return true;
  });

  const activeConversation = allConversations.find(c => c.id === activeConvId) || null;
  const activeParticipants = allParticipants.filter(p => p.conversation_id === activeConvId);
  const activeMessages = allMessages
    .filter(m => m.conversation_id === activeConvId)
    .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());

  const currentParticipation = activeConvId ? myParticipationMap.get(activeConvId) : null;
  const isPendingInvitation = currentParticipation?.statut_invitation === 'en_attente';

  // Accepter l'invitation
  const handleAcceptInvitation = async () => {
    if (!currentParticipation) return;
    const updated = { ...currentParticipation, statut_invitation: 'accepte' as const };
    await db.conversation_participants.put(updated);
    pushItemToSupabase('conversation_participants', updated);
  };

  // Décliner l'invitation
  const handleDeclineInvitation = async () => {
    if (!currentParticipation) return;
    const updated = { ...currentParticipation, statut_invitation: 'refuse' as const };
    await db.conversation_participants.put(updated);
    pushItemToSupabase('conversation_participants', updated);
    setActiveConvId(null);
  };

  // Envoi de message texte
  const handleSendMessage = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!inputText.trim() || !activeConvId) return;

    const textToSend = inputText.trim();
    setInputText('');

    const newMsgId = `msg_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;
    const now = new Date().toISOString();

    const newMsg: MessageChat = {
      id: newMsgId,
      conversation_id: activeConvId,
      sender_id: currentUser.id,
      sender_nom: currentUser.nom,
      sender_role: currentUser.role,
      type_message: 'text',
      contenu: textToSend,
      statut: 'envoye',
      created_at: now
    };

    await db.messages.add(newMsg);
    await db.conversations.update(activeConvId, {
      dernier_message_texte: textToSend,
      dernier_message_date: now,
      dernier_message_sender: currentUser.nom
    });

    pushItemToSupabase('messages', newMsg);
    pushItemToSupabase('conversations', {
      id: activeConvId,
      dernier_message_texte: textToSend,
      dernier_message_date: now,
      dernier_message_sender: currentUser.nom
    });
  };

  const triggerFileInput = (type: 'image' | 'video' | 'document') => {
    fileTypeRef.current = type;
    setAttachmentMenuOpen(false);
    if (fileInputRef.current) {
      if (type === 'image') fileInputRef.current.accept = 'image/*';
      else if (type === 'video') fileInputRef.current.accept = 'video/*';
      else fileInputRef.current.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip';
      fileInputRef.current.click();
    }
  };

  const handleFileSelected = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !activeConvId) return;

    if (file.size > 15 * 1024 * 1024) {
      alert('Ce fichier dépasse la limite de 15 Mo.');
      return;
    }

    setUploading(true);
    try {
      const base64 = await fileToBase64(file);
      const category = detectFileType(file);
      const newMsgId = `msg_file_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;
      const now = new Date().toISOString();
      const labelPreview = category === 'image' ? '📷 Photo' : category === 'video' ? '🎥 Vidéo' : `📄 ${file.name}`;

      const newMsg: MessageChat = {
        id: newMsgId,
        conversation_id: activeConvId,
        sender_id: currentUser.id,
        sender_nom: currentUser.nom,
        sender_role: currentUser.role,
        type_message: category,
        contenu: file.name,
        fichier_url: base64,
        fichier_nom: file.name,
        fichier_taille: file.size,
        fichier_type: file.type,
        statut: 'envoye',
        created_at: now
      };

      await db.messages.add(newMsg);
      await db.conversations.update(activeConvId, {
        dernier_message_texte: labelPreview,
        dernier_message_date: now,
        dernier_message_sender: currentUser.nom
      });

      pushItemToSupabase('messages', newMsg);
      pushItemToSupabase('conversations', {
        id: activeConvId,
        dernier_message_texte: labelPreview,
        dernier_message_date: now,
        dernier_message_sender: currentUser.nom
      });
    } catch (err: any) {
      alert("Erreur lors de l'envoi : " + err.message);
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const formatTime = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  };

  return (
    <div className="h-[calc(100vh-6.5rem)] flex rounded-2xl overflow-hidden border border-outline-variant bg-surface-container-lowest shadow-sm">
      <input
        type="file"
        ref={fileInputRef}
        onChange={handleFileSelected}
        className="hidden"
      />

      {/* Colonne gauche : liste des conversations */}
      <div className="w-80 sm:w-96 border-r border-outline-variant flex flex-col bg-surface-container-low shrink-0">
        <div className="p-4 border-b border-outline-variant flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-xl bg-primary text-on-primary flex items-center justify-center font-bold shadow-xs">
              <MessageSquare className="w-5 h-5" />
            </div>
            <div>
              <h2 className="font-bold text-sm text-on-surface">Messenger ISGI</h2>
              <p className="text-[11px] text-on-surface-variant">Messagerie instantanée & groupes</p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => setIsNewChatOpen(true)}
              className="p-2 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors"
              title="Nouvelle discussion directe"
            >
              <Plus className="w-4 h-4" />
            </button>
            <button
              onClick={() => setIsNewGroupOpen(true)}
              className="p-2 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface transition-colors"
              title="Créer un groupe"
            >
              <Users className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Barre de recherche */}
        <div className="p-3 border-b border-outline-variant">
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher une discussion..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs bg-surface-container rounded-xl border border-outline-variant text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
        </div>

        {/* Filtres */}
        <div className="flex border-b border-outline-variant px-3 py-2 gap-1.5 bg-surface-container-lowest text-xs">
          {(['all', 'direct', 'group'] as const).map(tab => (
            <button
              key={tab}
              onClick={() => setFilterType(tab)}
              className={cn(
                "px-3 py-1 rounded-lg font-medium transition-colors text-xs",
                filterType === tab
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "text-on-surface-variant hover:bg-surface-container"
              )}
            >
              {tab === 'all' ? 'Toutes' : tab === 'direct' ? 'Directes' : 'Groupes'}
            </button>
          ))}
        </div>

        {/* Liste des conversations */}
        <div className="flex-1 overflow-y-auto divide-y divide-outline-variant/30">
          {filteredConversations.length === 0 ? (
            <div className="p-8 text-center text-on-surface-variant">
              <MessageSquare className="w-8 h-8 mx-auto mb-2 opacity-30" />
              <p className="text-xs">Aucune conversation trouvée.</p>
              <button
                onClick={() => setIsNewChatOpen(true)}
                className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary/10 text-primary font-bold text-xs hover:bg-primary/20 transition-colors"
              >
                <Plus className="w-3.5 h-3.5" /> Démarrer un échange
              </button>
            </div>
          ) : (
            filteredConversations.map(conv => {
              const isActive = conv.id === activeConvId;
              const isGrp = conv.type === 'group';
              const part = myParticipationMap.get(conv.id);
              const isPending = part?.statut_invitation === 'en_attente';

              return (
                <div
                  key={conv.id}
                  onClick={() => setActiveConvId(conv.id)}
                  className={cn(
                    "p-3.5 flex items-start gap-3 cursor-pointer transition-colors relative",
                    isActive ? "bg-primary/10 border-l-4 border-primary" : "hover:bg-surface-container/60"
                  )}
                >
                  <div
                    className="w-10 h-10 rounded-2xl flex items-center justify-center text-white font-bold text-xs shrink-0 shadow-xs"
                    style={{ backgroundColor: conv.couleur || (isGrp ? '#0891b2' : '#2563eb') }}
                  >
                    {isGrp ? <Users className="w-5 h-5" /> : (conv.titre ? conv.titre.charAt(0).toUpperCase() : 'U')}
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between mb-0.5">
                      <h4 className={cn("text-xs font-semibold truncate", isActive ? "text-primary font-bold" : "text-on-surface")}>
                        {conv.titre || 'Discussion'}
                      </h4>
                      {conv.dernier_message_date && (
                        <span className="text-[10px] text-on-surface-variant shrink-0 ml-1">
                          {formatTime(conv.dernier_message_date)}
                        </span>
                      )}
                    </div>
                    <p className="text-[11px] text-on-surface-variant truncate">
                      {isPending ? '⏳ Invitation en attente de votre confirmation' : (conv.dernier_message_texte || 'Aucun message.')}
                    </p>
                  </div>
                </div>
              );
            })
          )}
        </div>
      </div>

      {/* Colonne droite : Chat actif */}
      <div className="flex-1 flex flex-col bg-surface-container-lowest overflow-hidden">
        {activeConversation ? (
          <>
            {/* Header du chat */}
            <div className="p-3.5 px-5 border-b border-outline-variant flex items-center justify-between bg-surface-container-low shrink-0">
              <div className="flex items-center gap-3">
                <div
                  className="w-10 h-10 rounded-2xl flex items-center justify-center text-white font-bold text-sm shadow-xs"
                  style={{ backgroundColor: activeConversation.couleur || (activeConversation.type === 'group' ? '#0891b2' : '#2563eb') }}
                >
                  {activeConversation.type === 'group' ? <Users className="w-5 h-5" /> : activeConversation.titre?.charAt(0).toUpperCase()}
                </div>
                <div>
                  <h3 className="font-bold text-xs text-on-surface flex items-center gap-2">
                    {activeConversation.titre}
                    {activeConversation.type === 'group' && (
                      <span className="px-1.5 py-0.5 rounded text-[10px] bg-secondary/10 text-secondary font-medium">
                        {activeParticipants.length} membres
                      </span>
                    )}
                  </h3>
                  <p className="text-[10px] text-on-surface-variant">
                    {activeConversation.type === 'group'
                      ? 'Groupe collaboratif ISGI'
                      : 'Canal direct sécurisé'}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-1">
                <button
                  onClick={() => setIsDrawerOpen(!isDrawerOpen)}
                  className="p-2 rounded-xl text-on-surface-variant hover:bg-surface-container transition-colors"
                  title="Détails du chat"
                >
                  <Info className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* Bandeau d'invitation en attente si nécessaire */}
            {isPendingInvitation && (
              <div className="p-3 bg-amber-500/10 border-b border-amber-500/20 flex items-center justify-between px-5 text-xs text-amber-900 dark:text-amber-200">
                <div className="flex items-center gap-2">
                  <Clock className="w-4 h-4 text-amber-600 shrink-0" />
                  <span>Vous avez été invité à cette discussion. Acceptez-vous de participer ?</span>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={handleAcceptInvitation}
                    className="inline-flex items-center gap-1 px-3 py-1 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 transition-colors"
                  >
                    <UserCheck className="w-3.5 h-3.5" /> Accepter
                  </button>
                  <button
                    onClick={handleDeclineInvitation}
                    className="inline-flex items-center gap-1 px-3 py-1 bg-rose-600 text-white rounded-lg text-xs font-bold hover:bg-rose-700 transition-colors"
                  >
                    <UserX className="w-3.5 h-3.5" /> Refuser
                  </button>
                </div>
              </div>
            )}

            {/* Messages */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-surface-container-lowest">
              {activeMessages.length === 0 ? (
                <div className="h-full flex flex-col items-center justify-center text-on-surface-variant">
                  <ShieldCheck className="w-10 h-10 text-primary mb-2 opacity-50" />
                  <p className="text-xs font-semibold">Début de la conversation sécurisée</p>
                  <p className="text-[11px] opacity-70 mt-0.5">Envoyez le premier message ou document ci-dessous.</p>
                </div>
              ) : (
                activeMessages.map(msg => {
                  const isMine = msg.sender_id === currentUser.id;
                  return (
                    <div
                      key={msg.id}
                      className={cn("flex flex-col max-w-[75%]", isMine ? "ml-auto items-end" : "mr-auto items-start")}
                    >
                      {!isMine && (
                        <div className="text-[10px] font-semibold text-on-surface-variant mb-1 px-1">
                          {msg.sender_nom} ({msg.sender_role || 'Membre'})
                        </div>
                      )}

                      <div
                        className={cn(
                          "rounded-2xl p-3 text-xs shadow-xs break-words",
                          isMine
                            ? "bg-primary text-on-primary rounded-tr-xs"
                            : "bg-surface-container rounded-tl-xs text-on-surface"
                        )}
                      >
                        {msg.type_message === 'text' && (
                          <div className="whitespace-pre-wrap">{msg.contenu}</div>
                        )}

                        {msg.type_message === 'image' && (
                          <div className="space-y-1">
                            <img
                              src={msg.fichier_url}
                              alt=""
                              onClick={() => setSelectedImageModal(msg.fichier_url || null)}
                              className="max-h-64 rounded-xl cursor-pointer hover:opacity-95 transition-opacity"
                            />
                            {msg.contenu && <p className="text-[11px] mt-1">{msg.contenu}</p>}
                          </div>
                        )}

                        {msg.type_message === 'document' && (
                          <div className="flex items-center gap-2.5 p-1">
                            <FileText className="w-6 h-6 shrink-0" />
                            <div className="min-w-0 flex-1">
                              <div className="font-semibold text-[11px] truncate">{msg.fichier_nom}</div>
                              <div className="text-[9px] opacity-80">{formatFileSize(msg.fichier_taille)}</div>
                            </div>
                            <button
                              onClick={() => downloadAttachment(msg.fichier_url || '', msg.fichier_nom || 'doc')}
                              className="p-1 rounded-md hover:bg-black/10 transition-colors"
                            >
                              <Download className="w-4 h-4" />
                            </button>
                          </div>
                        )}
                      </div>

                      <div className="flex items-center gap-1 text-[9px] text-on-surface-variant mt-1 px-1">
                        <span>{formatTime(msg.created_at)}</span>
                        {isMine && <CheckCheck className="w-3 h-3 text-primary" />}
                      </div>
                    </div>
                  );
                })
              )}
              <div ref={messagesEndRef} />
            </div>

            {/* Barre de saisie */}
            <div className="p-3 border-t border-outline-variant bg-surface-container-low shrink-0">
              <form onSubmit={handleSendMessage} className="flex items-center gap-2">
                <div className="relative">
                  <button
                    type="button"
                    onClick={() => setAttachmentMenuOpen(!attachmentMenuOpen)}
                    className="p-2 rounded-xl text-on-surface-variant hover:bg-surface-container transition-colors"
                    title="Joindre un fichier"
                  >
                    <Paperclip className="w-5 h-5" />
                  </button>

                  {attachmentMenuOpen && (
                    <div className="absolute bottom-full left-0 mb-2 w-48 bg-surface-container-lowest border border-outline-variant rounded-2xl shadow-xl p-1.5 space-y-1 z-30">
                      <button
                        type="button"
                        onClick={() => triggerFileInput('image')}
                        className="w-full flex items-center gap-2 px-3 py-2 text-xs text-on-surface hover:bg-surface-container rounded-xl transition-colors"
                      >
                        <ImageIcon className="w-4 h-4 text-cyan-600" /> Photo
                      </button>
                      <button
                        type="button"
                        onClick={() => triggerFileInput('video')}
                        className="w-full flex items-center gap-2 px-3 py-2 text-xs text-on-surface hover:bg-surface-container rounded-xl transition-colors"
                      >
                        <Video className="w-4 h-4 text-violet-600" /> Vidéo
                      </button>
                      <button
                        type="button"
                        onClick={() => triggerFileInput('document')}
                        className="w-full flex items-center gap-2 px-3 py-2 text-xs text-on-surface hover:bg-surface-container rounded-xl transition-colors"
                      >
                        <FileText className="w-4 h-4 text-emerald-600" /> Document PDF/Office
                      </button>
                    </div>
                  )}
                </div>

                <input
                  type="text"
                  placeholder="Écrivez votre message..."
                  value={inputText}
                  onChange={e => setInputText(e.target.value)}
                  className="flex-1 px-4 py-2.5 text-xs bg-surface-container rounded-xl border border-outline-variant text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-1 focus:ring-primary"
                />

                <button
                  type="submit"
                  disabled={!inputText.trim() || uploading}
                  className="p-2.5 rounded-xl bg-primary text-on-primary font-bold hover:opacity-90 transition-opacity disabled:opacity-50"
                >
                  <Send className="w-4 h-4" />
                </button>
              </form>
            </div>
          </>
        ) : (
          <div className="flex-1 flex flex-col items-center justify-center text-on-surface-variant p-8 text-center">
            <div className="w-16 h-16 rounded-3xl bg-primary/10 text-primary flex items-center justify-center mb-4">
              <MessageSquare className="w-8 h-8" />
            </div>
            <h3 className="text-base font-bold text-on-surface mb-1">Sélectionnez une conversation</h3>
            <p className="text-xs max-w-sm">
              Choisissez un échange à gauche ou créez une nouvelle discussion avec un membre ou un groupe de l'ISGI.
            </p>
          </div>
        )}
      </div>

      {/* Volet d'infos à droite */}
      {isDrawerOpen && activeConversation && (
        <ChatInfoDrawer
          isOpen={isDrawerOpen}
          onClose={() => setIsDrawerOpen(false)}
          conversation={activeConversation}
          participants={activeParticipants}
          messages={activeMessages}
          presences={allPresences}
          currentUserId={currentUser.id}
        />
      )}

      {/* Modale d'image */}
      {selectedImageModal && (
        <div
          className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4"
          onClick={() => setSelectedImageModal(null)}
        >
          <img src={selectedImageModal} alt="" className="max-w-full max-h-[90vh] rounded-2xl shadow-2xl" />
        </div>
      )}

      {/* Modales Création */}
      <NewChatModal
        isOpen={isNewChatOpen}
        onClose={() => setIsNewChatOpen(false)}
        currentUserId={currentUser.id}
        currentUserName={currentUser.nom}
        onSelectConversation={convId => setActiveConvId(convId)}
      />

      <NewGroupModal
        isOpen={isNewGroupOpen}
        onClose={() => setIsNewGroupOpen(false)}
        currentUserId={currentUser.id}
        currentUserName={currentUser.nom}
        onSelectConversation={convId => setActiveConvId(convId)}
      />
    </div>
  );
}
