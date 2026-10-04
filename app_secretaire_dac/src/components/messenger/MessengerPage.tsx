import React, { useState, useEffect, useRef } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db, pushItemToSupabase } from '../../db/db';
import { supabase } from '../../db/supabaseClient';
import type { Conversation, ConversationParticipant, MessageChat, UserPresence } from '../../types';
import {
  MessageSquare,
  Search,
  Plus,
  Users,
  User,
  UserPlus,
  Paperclip,
  Send,
  Image as ImageIcon,
  Video,
  FileText,
  Download,
  Check,
  CheckCheck,
  Smile,
  MoreVertical,
  Phone,
  Video as VideoCall,
  Info,
  X,
  FileSpreadsheet,
  FileCode,
  ShieldCheck,
  Circle
} from 'lucide-react';
import { cn } from '../../lib/utils';
import { detectFileType, fileToBase64, formatFileSize, getFileBadgeInfo, downloadAttachment } from '../../utils/fileHelpers';
import { NewChatModal } from './NewChatModal';
import { NewGroupModal } from './NewGroupModal';
import { ChatInfoDrawer } from './ChatInfoDrawer';

export function MessengerPage() {
  // Utilisateur connecté
  const currentUser = JSON.parse(
    localStorage.getItem('dac_user') || '{"id":"dac_default","nom":"Prof. M. DIALLO (DAC)","role":"DAC"}'
  );

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

  // Fichier en cours d'envoi avant validation
  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileTypeRef = useRef<'image' | 'video' | 'document'>('image');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Requêtes réactives depuis Dexie
  const allConversations = useLiveQuery(() => db.conversations.toArray()) || [];
  const allParticipants = useLiveQuery(() => db.conversation_participants.toArray()) || [];
  const allMessages = useLiveQuery(() => db.messages.toArray()) || [];
  const allPresences = useLiveQuery(() => db.user_presences.toArray()) || [];

  // Mettre à jour la présence de l'utilisateur connecté à l'ouverture
  useEffect(() => {
    const updateMyPresence = async () => {
      const p: UserPresence = {
        user_id: currentUser.id,
        nom_complet: currentUser.nom,
        role: currentUser.role || 'DAC',
        en_ligne: true,
        derniere_connexion: new Date().toISOString(),
        statut_perso: 'En ligne sur l’espace Messenger ISGI'
      };
      await db.user_presences.put(p);
      pushItemToSupabase('user_presences', p);
    };
    updateMyPresence();

    const interval = setInterval(updateMyPresence, 60000);
    return () => clearInterval(interval);
  }, [currentUser.id]);

  // Écoute Supabase Realtime pour les nouveaux messages et présences
  useEffect(() => {
    try {
      const channel = supabase
        .channel('public:messenger_realtime')
        .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'messages' }, async (payload) => {
          const newMsg = payload.new as MessageChat;
          if (newMsg && newMsg.id) {
            await db.messages.put(newMsg);
            // Mettre à jour la conversation
            await db.conversations.update(newMsg.conversation_id, {
              dernier_message_texte: newMsg.contenu || (newMsg.type_message === 'image' ? '📷 Photo' : '📄 Document'),
              dernier_message_date: newMsg.created_at,
              dernier_message_sender: newMsg.sender_nom
            });
          }
        })
        .on('postgres_changes', { event: 'UPDATE', schema: 'public', table: 'user_presences' }, async (payload) => {
          const updated = payload.new as UserPresence;
          if (updated && updated.user_id) {
            await db.user_presences.put(updated);
          }
        })
        .subscribe();

      return () => {
        supabase.removeChannel(channel);
      };
    } catch {}
  }, []);

  // Conversations auxquelles l'utilisateur connecté participe
  const myConvIds = new Set(
    allParticipants.filter(p => p.user_id === currentUser.id).map(p => p.conversation_id)
  );

  const myConversations = allConversations
    .filter(c => myConvIds.has(c.id))
    .sort((a, b) => {
      const dateA = new Date(a.dernier_message_date || a.created_at || 0).getTime();
      const dateB = new Date(b.dernier_message_date || b.created_at || 0).getTime();
      return dateB - dateA;
    });

  // Sélectionner la première conversation par défaut si aucune sélectionnée
  useEffect(() => {
    if (!activeConvId && myConversations.length > 0) {
      setActiveConvId(myConversations[0].id);
    }
  }, [myConversations.length, activeConvId]);

  // Défilement automatique vers le bas à l'arrivée d'un message
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [allMessages.length, activeConvId]);

  // Conversation active
  const activeConversation = myConversations.find(c => c.id === activeConvId);

  // Participants de la conversation active
  const activeParticipants = allParticipants.filter(p => p.conversation_id === activeConvId);
  const myParticipantInfo = activeParticipants.find(p => p.user_id === currentUser.id);
  const isPendingInvitation = activeConversation?.type === 'group' && myParticipantInfo?.statut_invitation === 'en_attente';

  // Messages de la conversation active
  const activeMessages = allMessages
    .filter(m => m.conversation_id === activeConvId)
    .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());

  // Présences Map
  const presenceMap = new Map(allPresences.map(p => [p.user_id, p]));

  // Trouver l'autre participant pour une conversation directe
  const otherParticipant = activeConversation?.type === 'direct'
    ? activeParticipants.find(p => p.user_id !== currentUser.id)
    : null;

  const otherPresence = otherParticipant ? presenceMap.get(otherParticipant.user_id) : null;

  // Filtrage des conversations
  const filteredConversations = myConversations.filter(c => {
    if (filterType === 'direct' && c.type !== 'direct') return false;
    if (filterType === 'group' && c.type !== 'group') return false;
    if (searchTerm.trim() !== '') {
      const q = searchTerm.toLowerCase();
      const matchTitle = (c.titre || '').toLowerCase().includes(q);
      const matchLastMsg = (c.dernier_message_texte || '').toLowerCase().includes(q);
      return matchTitle || matchLastMsg;
    }
    return true;
  });

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

    // 1. Sauvegarde locale
    await db.messages.add(newMsg);

    // 2. Mise à jour de la conversation
    await db.conversations.update(activeConvId, {
      dernier_message_texte: textToSend,
      dernier_message_date: now,
      dernier_message_sender: currentUser.nom
    });

    // 3. Pousser vers Supabase
    pushItemToSupabase('messages', newMsg);
    pushItemToSupabase('conversations', {
      id: activeConvId,
      dernier_message_texte: textToSend,
      dernier_message_date: now,
      dernier_message_sender: currentUser.nom
    });
  };

  // Déclencher le sélecteur de fichier
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

  // Traiter le fichier sélectionné
  const handleFileSelected = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !activeConvId) return;

    // Limite de taille : 15 Mo pour éviter les saturations
    if (file.size > 15 * 1024 * 1024) {
      alert('Ce fichier dépasse la limite maximale autorisée de 15 Mo.');
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
      alert("Erreur lors de l'envoi du fichier: " + err.message);
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  // Helper pour formater l'heure des messages
  const formatTime = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  };

  // Helper pour formater la date de la conversation
  const formatConvDate = (dateStr?: string) => {
    if (!dateStr) return '';
    try {
      const d = new Date(dateStr);
      const today = new Date();
      if (d.toDateString() === today.toDateString()) {
        return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      }
      return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' });
    } catch {
      return '';
    }
  };

  return (
    <div className="h-[calc(100vh-6.5rem)] flex rounded-2xl overflow-hidden border border-outline-variant bg-surface-container-lowest shadow-sm">
      {/* Input de fichier caché */}
      <input
        type="file"
        ref={fileInputRef}
        onChange={handleFileSelected}
        className="hidden"
      />

      {/* ==================================================================== */}
      {/* COLONNE GAUCHE : LISTE DES CONVERSATIONS & CONTACTS */}
      {/* ==================================================================== */}
      <div className="w-80 sm:w-96 border-r border-outline-variant flex flex-col bg-surface-container-low shrink-0">
        {/* En-tête volet gauche */}
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
              className="p-2 rounded-xl bg-surface-container hover:bg-surface-container-highest text-on-surface transition-colors"
              title="Nouvelle discussion directe"
            >
              <Plus className="w-4 h-4" />
            </button>
            <button
              onClick={() => setIsNewGroupOpen(true)}
              className="p-2 rounded-xl bg-surface-container hover:bg-surface-container-highest text-on-surface transition-colors"
              title="Créer un groupe de discussion"
            >
              <Users className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Barre de recherche */}
        <div className="p-3 border-b border-outline-variant space-y-2.5 bg-surface-container-lowest">
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher une discussion..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-1.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
            />
          </div>

          {/* Filtres par onglets */}
          <div className="flex items-center gap-1.5 text-xs">
            <button
              onClick={() => setFilterType('all')}
              className={cn(
                "px-2.5 py-1 rounded-full font-semibold transition-colors",
                filterType === 'all'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Tous ({myConversations.length})
            </button>
            <button
              onClick={() => setFilterType('direct')}
              className={cn(
                "px-2.5 py-1 rounded-full font-semibold transition-colors",
                filterType === 'direct'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Privés
            </button>
            <button
              onClick={() => setFilterType('group')}
              className={cn(
                "px-2.5 py-1 rounded-full font-semibold transition-colors",
                filterType === 'group'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Groupes
            </button>
          </div>
        </div>

        {/* Liste des conversations */}
        <div className="flex-1 overflow-y-auto divide-y divide-outline-variant/40 custom-scrollbar">
          {filteredConversations.length === 0 ? (
            <div className="p-8 text-center text-xs text-on-surface-variant">
              <MessageSquare className="w-8 h-8 mx-auto mb-2 opacity-30" />
              <p className="font-semibold">Aucune conversation</p>
              <p className="mt-1 text-[11px]">Cliquez sur + pour démarrer un échange</p>
            </div>
          ) : (
            filteredConversations.map(conv => {
              const isSelected = conv.id === activeConvId;
              const isGroup = conv.type === 'group';

              // Pour direct, trouver l'autre contact
              const parts = allParticipants.filter(p => p.conversation_id === conv.id);
              const otherP = !isGroup ? parts.find(p => p.user_id !== currentUser.id) : null;
              const presence = otherP ? presenceMap.get(otherP.user_id) : null;
              const isOnline = presence?.en_ligne;

              const displayTitle = isGroup ? conv.titre : (otherP?.user_nom || conv.titre || 'Contact');

              return (
                <div
                  key={conv.id}
                  onClick={() => setActiveConvId(conv.id)}
                  className={cn(
                    "flex items-center gap-3 p-3 cursor-pointer transition-colors relative",
                    isSelected
                      ? "bg-surface-container-highest/80 border-l-4 border-primary"
                      : "hover:bg-surface-container/60"
                  )}
                >
                  {/* Avatar */}
                  <div className="relative shrink-0">
                    <div
                      className="w-11 h-11 rounded-full flex items-center justify-center text-white font-bold text-sm shadow-xs"
                      style={{ backgroundColor: conv.couleur || (isGroup ? '#2563eb' : '#0284c7') }}
                    >
                      {isGroup ? (
                        <Users className="w-5 h-5" />
                      ) : (
                        (displayTitle || 'C').charAt(0).toUpperCase()
                      )}
                    </div>

                    {/* Pastille de statut en ligne (pour conversations directes) */}
                    {!isGroup && isOnline && (
                      <span className="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-500 ring-2 ring-surface-container-low" />
                    )}
                  </div>

                  {/* Détails du chat */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between mb-1">
                      <h4 className={cn("text-xs truncate", isSelected ? "font-bold text-primary" : "font-semibold text-on-surface")}>
                        {displayTitle}
                      </h4>
                      <span className="text-[10px] text-on-surface-variant shrink-0 ml-1">
                        {formatConvDate(conv.dernier_message_date)}
                      </span>
                    </div>

                    <div className="flex items-center justify-between">
                      <p className="text-[11px] text-on-surface-variant truncate pr-2">
                        {conv.dernier_message_sender && (
                          <span className="font-semibold text-on-surface/80">
                            {conv.dernier_message_sender === currentUser.nom ? 'Vous : ' : `${conv.dernier_message_sender.split(' ')[0]} : `}
                          </span>
                        )}
                        {conv.dernier_message_texte || 'Aucun message'}
                      </p>
                    </div>
                  </div>
                </div>
              );
            })
          )}
        </div>
      </div>

      {/* ==================================================================== */}
      {/* COLONNE CENTRALE : DISCUSSION ACTIVE */}
      {/* ==================================================================== */}
      <div className="flex-1 flex flex-col bg-surface-container-lowest overflow-hidden">
        {activeConversation ? (
          <>
            {/* Barre d'en-tête de la discussion active */}
            <div className="h-16 px-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low/60 shrink-0">
              <div className="flex items-center gap-3">
                <div className="relative">
                  <div
                    className="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-sm shadow-xs"
                    style={{ backgroundColor: activeConversation.couleur || '#2563eb' }}
                  >
                    {activeConversation.type === 'group' ? (
                      <Users className="w-5 h-5" />
                    ) : (
                      (otherParticipant?.user_nom || activeConversation.titre || 'C').charAt(0).toUpperCase()
                    )}
                  </div>
                  {activeConversation.type === 'direct' && otherPresence?.en_ligne && (
                    <span className="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-500 ring-2 ring-surface-container-lowest" />
                  )}
                </div>

                <div>
                  <h3 className="font-bold text-xs sm:text-sm text-on-surface leading-tight">
                    {activeConversation.type === 'group'
                      ? activeConversation.titre
                      : (otherParticipant?.user_nom || activeConversation.titre)}
                  </h3>
                  <p className="text-[11px] text-on-surface-variant flex items-center gap-1.5">
                    {activeConversation.type === 'group' ? (
                      <span>{activeParticipants.length} participants</span>
                    ) : otherPresence?.en_ligne ? (
                      <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block" /> En ligne
                      </span>
                    ) : (
                      <span>Hors ligne</span>
                    )}
                  </p>
                </div>
              </div>

              {/* Boutons d'actions d'en-tête */}
              <div className="flex items-center gap-1">
                <button
                  onClick={() => setIsDrawerOpen(!isDrawerOpen)}
                  className={cn(
                    "p-2 rounded-xl transition-colors",
                    isDrawerOpen ? "bg-primary text-on-primary" : "text-on-surface-variant hover:bg-surface-container"
                  )}
                  title="Informations du chat et fichiers partagés"
                >
                  <Info className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* Bannière d'invitation en attente */}
            {isPendingInvitation && (
              <div className="p-3 bg-amber-50 dark:bg-amber-950/40 border-b border-amber-200 dark:border-amber-900/60 flex items-center justify-between gap-3 shrink-0">
                <div className="flex items-center gap-2.5">
                  <div className="p-2 rounded-xl bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-100 shrink-0">
                    <UserPlus className="w-4 h-4" />
                  </div>
                  <div>
                    <p className="text-xs font-bold text-amber-900 dark:text-amber-100">
                      Demande d'adhésion au groupe en attente
                    </p>
                    <p className="text-[11px] text-amber-800/90 dark:text-amber-300">
                      {myParticipantInfo?.invite_par_nom ? `Invité par ${myParticipantInfo.invite_par_nom}. ` : ''}
                      Veuillez accepter pour pouvoir lire et envoyer des messages dans ce groupe.
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={async () => {
                      if (myParticipantInfo) {
                        await db.conversation_participants.update(myParticipantInfo.id, {
                          statut_invitation: 'accepte',
                          joined_at: new Date().toISOString()
                        });
                      }
                    }}
                    className="px-3.5 py-1.5 bg-primary text-on-primary text-xs font-bold rounded-xl hover:bg-primary/90 transition-colors shadow-xs"
                  >
                    Accepter
                  </button>
                  <button
                    onClick={async () => {
                      if (myParticipantInfo) {
                        await db.conversation_participants.update(myParticipantInfo.id, {
                          statut_invitation: 'refuse'
                        });
                        setActiveConvId(null);
                      }
                    }}
                    className="px-3 py-1.5 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-error-container hover:text-on-error-container transition-colors"
                  >
                    Décliner
                  </button>
                </div>
              </div>
            )}

            {/* Fil des messages */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3 custom-scrollbar bg-surface-container-lowest/50">
              {activeMessages.length === 0 ? (
                <div className="h-full flex flex-col items-center justify-center text-center text-on-surface-variant text-xs">
                  <div className="w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center mb-2">
                    <MessageSquare className="w-6 h-6" />
                  </div>
                  <p className="font-bold text-sm text-on-surface">Début de la conversation</p>
                  <p className="text-xs text-on-surface-variant mt-1">Envoyez un premier message ou partagez un document.</p>
                </div>
              ) : (
                activeMessages.map((msg, index) => {
                  const isMe = msg.sender_id === currentUser.id;
                  const isImage = msg.type_message === 'image';
                  const isVideo = msg.type_message === 'video';
                  const isDocument = msg.type_message === 'document' || (msg.fichier_url && !isImage && !isVideo);

                  return (
                    <div
                      key={msg.id}
                      className={cn(
                        "flex flex-col",
                        isMe ? "items-end" : "items-start"
                      )}
                    >
                      {/* Nom de l'expéditeur dans les groupes */}
                      {!isMe && activeConversation.type === 'group' && (
                        <span className="text-[10px] font-bold text-primary mb-1 ml-1">
                          {msg.sender_nom} {msg.sender_role ? `(${msg.sender_role})` : ''}
                        </span>
                      )}

                      <div
                        className={cn(
                          "max-w-[85%] sm:max-w-[70%] rounded-2xl p-3 shadow-xs text-xs space-y-2",
                          isMe
                            ? "bg-primary text-on-primary rounded-tr-xs"
                            : "bg-surface-container-low text-on-surface border border-outline-variant/60 rounded-tl-xs"
                        )}
                      >
                        {/* 1. Affichage Image */}
                        {isImage && msg.fichier_url && (
                          <div className="rounded-xl overflow-hidden border border-black/10 cursor-pointer max-w-sm">
                            <img
                              src={msg.fichier_url}
                              alt={msg.fichier_nom || 'Image'}
                              className="w-full max-h-60 object-cover hover:opacity-95 transition-opacity"
                              onClick={() => setSelectedImageModal(msg.fichier_url!)}
                            />
                          </div>
                        )}

                        {/* 2. Affichage Vidéo */}
                        {isVideo && msg.fichier_url && (
                          <div className="rounded-xl overflow-hidden max-w-sm">
                            <video
                              controls
                              className="w-full max-h-60 rounded-xl"
                              src={msg.fichier_url}
                            />
                          </div>
                        )}

                        {/* 3. Affichage Document (PDF, Word, Excel, etc.) */}
                        {isDocument && (
                          <div className={cn(
                            "p-3 rounded-xl border flex items-center justify-between gap-3",
                            isMe ? "bg-white/10 border-white/20 text-white" : "bg-surface-container border-outline-variant"
                          )}>
                            <div className="flex items-center gap-2.5 overflow-hidden">
                              {(() => {
                                const badge = getFileBadgeInfo(msg.fichier_nom, msg.fichier_type);
                                return (
                                  <span className={cn("px-2 py-1 rounded-md text-[10px] font-extrabold border shrink-0", badge.bgColor, badge.textColor, badge.borderColor)}>
                                    {badge.label}
                                  </span>
                                );
                              })()}
                              <div className="overflow-hidden">
                                <p className="font-bold truncate text-xs">{msg.fichier_nom || 'Document attaché'}</p>
                                <p className="text-[10px] opacity-75">{formatFileSize(msg.fichier_taille)}</p>
                              </div>
                            </div>

                            {msg.fichier_url && (
                              <button
                                onClick={() => downloadAttachment(msg.fichier_url!, msg.fichier_nom || 'document')}
                                className={cn(
                                  "p-1.5 rounded-lg transition-colors shrink-0",
                                  isMe ? "hover:bg-white/20 text-white" : "hover:bg-surface-container-highest text-primary"
                                )}
                                title="Télécharger le document"
                              >
                                <Download className="w-4 h-4" />
                              </button>
                            )}
                          </div>
                        )}

                        {/* Contenu textuel */}
                        {msg.contenu && (!isDocument || msg.contenu !== msg.fichier_nom) && (
                          <p className="whitespace-pre-wrap break-words leading-relaxed">
                            {msg.contenu}
                          </p>
                        )}

                        {/* Horodatage & Statut de lecture */}
                        <div className={cn(
                          "flex items-center justify-end gap-1 text-[10px]",
                          isMe ? "text-on-primary/75" : "text-on-surface-variant"
                        )}>
                          <span>{formatTime(msg.created_at)}</span>
                          {isMe && (
                            msg.statut === 'lu' ? (
                              <CheckCheck className="w-3.5 h-3.5 text-cyan-300" />
                            ) : (
                              <Check className="w-3.5 h-3.5" />
                            )
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
              <div ref={messagesEndRef} />
            </div>

            {/* Barre de saisie et envoi */}
            {isPendingInvitation ? (
              <div className="p-4 border-t border-outline-variant bg-surface-container-low/80 text-center text-xs text-on-surface-variant font-medium flex items-center justify-center gap-2">
                <span className="text-base">🔒</span>
                <span>Vous devez accepter l'invitation ci-dessus pour pouvoir participer et envoyer des messages dans ce groupe.</span>
              </div>
            ) : (
              <div className="p-3 border-t border-outline-variant bg-surface-container-low/80 relative">
                {/* Menu popover pièces jointes */}
                {attachmentMenuOpen && (
                  <div className="absolute bottom-16 left-4 bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-xl p-2 z-30 flex flex-col gap-1 w-48 animate-in fade-in slide-in-from-bottom-2 duration-150">
                    <button
                      type="button"
                      onClick={() => triggerFileInput('image')}
                      className="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold text-on-surface hover:bg-surface-container transition-colors text-left"
                    >
                      <div className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600">
                        <ImageIcon className="w-4 h-4" />
                      </div>
                      <span>Photo / Image</span>
                    </button>

                    <button
                      type="button"
                      onClick={() => triggerFileInput('video')}
                      className="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold text-on-surface hover:bg-surface-container transition-colors text-left"
                    >
                      <div className="p-1.5 rounded-lg bg-violet-500/10 text-violet-600">
                        <Video className="w-4 h-4" />
                      </div>
                      <span>Vidéo</span>
                    </button>

                    <button
                      type="button"
                      onClick={() => triggerFileInput('document')}
                      className="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold text-on-surface hover:bg-surface-container transition-colors text-left"
                    >
                      <div className="p-1.5 rounded-lg bg-rose-500/10 text-rose-600">
                        <FileText className="w-4 h-4" />
                      </div>
                      <span>Document (PDF, Word, Excel)</span>
                    </button>
                  </div>
                )}

                <form onSubmit={handleSendMessage} className="flex items-center gap-2">
                  {/* Bouton Pièce jointe */}
                  <button
                    type="button"
                    onClick={() => setAttachmentMenuOpen(!attachmentMenuOpen)}
                    disabled={uploading}
                    className="p-2.5 rounded-xl text-on-surface-variant hover:bg-surface-container transition-colors shrink-0"
                    title="Joindre un fichier (photo, vidéo, pdf, docx, xls)"
                  >
                    <Paperclip className="w-5 h-5" />
                  </button>

                  {/* Champ texte */}
                  <input
                    type="text"
                    placeholder={uploading ? "Envoi du fichier en cours..." : "Écrivez votre message..."}
                    disabled={uploading}
                    value={inputText}
                    onChange={(e) => setInputText(e.target.value)}
                    className="flex-1 px-4 py-2.5 bg-surface-container rounded-2xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
                  />

                  {/* Bouton Envoyer */}
                  <button
                    type="submit"
                    disabled={!inputText.trim() || uploading}
                    className="p-2.5 rounded-xl bg-primary text-on-primary hover:bg-primary/90 transition-colors shrink-0 disabled:opacity-40 shadow-xs"
                  >
                    <Send className="w-4 h-4" />
                  </button>
                </form>
              </div>
            )}
          </>
        ) : (
          <div className="h-full flex flex-col items-center justify-center text-center text-on-surface-variant text-xs p-6">
            <div className="w-16 h-16 rounded-2xl bg-primary/10 text-primary flex items-center justify-center mb-3">
              <MessageSquare className="w-8 h-8" />
            </div>
            <h3 className="font-bold text-base text-on-surface">Espace Messenger ISGI</h3>
            <p className="text-xs text-on-surface-variant mt-1 max-w-sm">
              Sélectionnez une discussion à gauche pour afficher les messages ou démarrez un nouvel échange.
            </p>
          </div>
        )}
      </div>

      {/* ==================================================================== */}
      {/* VOLET LATÉRAL DROIT : INFOS DU CHAT (DRAWER) */}
      {/* ==================================================================== */}
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

      {/* Modale d'agrandissement d'image (Lightbox) */}
      {selectedImageModal && (
        <div
          className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 backdrop-blur-xs"
          onClick={() => setSelectedImageModal(null)}
        >
          <div className="relative max-w-3xl max-h-[90vh]">
            <img src={selectedImageModal} alt="Agrandissement" className="max-w-full max-h-[85vh] rounded-2xl object-contain" />
            <button
              onClick={() => setSelectedImageModal(null)}
              className="absolute -top-3 -right-3 p-2 rounded-full bg-white text-black font-bold shadow-lg"
            >
              ✕
            </button>
          </div>
        </div>
      )}

      {/* Modale Nouveau Chat Direct */}
      <NewChatModal
        isOpen={isNewChatOpen}
        onClose={() => setIsNewChatOpen(false)}
        currentUserId={currentUser.id}
        currentUserName={currentUser.nom}
        onSelectConversation={(id) => setActiveConvId(id)}
      />

      {/* Modale Nouveau Groupe */}
      <NewGroupModal
        isOpen={isNewGroupOpen}
        onClose={() => setIsNewGroupOpen(false)}
        currentUserId={currentUser.id}
        currentUserName={currentUser.nom}
        onSelectConversation={(id) => setActiveConvId(id)}
      />
    </div>
  );
}

export default MessengerPage;
