import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { UserPresence, Conversation, ConversationParticipant } from '../../types';
import {
  Search,
  X,
  MessageSquare,
  User,
  ShieldCheck,
  GraduationCap,
  Sparkles,
  Check
} from 'lucide-react';
import { cn } from '../../lib/utils';

interface NewChatModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentUserId: string;
  currentUserName: string;
  onSelectConversation: (convId: string) => void;
}

export const NewChatModal: React.FC<NewChatModalProps> = ({
  isOpen,
  onClose,
  currentUserId,
  currentUserName,
  onSelectConversation
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [activeTab, setActiveTab] = useState<'tous' | 'admin' | 'profs' | 'etudiants'>('tous');
  const [creating, setCreating] = useState(false);

  const presences = useLiveQuery(() => db.user_presences.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];

  if (!isOpen) return null;

  // Construire l'annuaire combiné
  const directory: {
    id: string;
    nom: string;
    role: string;
    category: 'admin' | 'profs' | 'etudiants';
    enLigne: boolean;
    derniereConnexion?: string;
    statutPerso?: string;
  }[] = [];

  // Ajouter depuis presences
  presences.forEach(p => {
    if (p.user_id === currentUserId) return;
    const cat = (p.role || '').toLowerCase().includes('enseign') || (p.role || '').toLowerCase().includes('prof')
      ? 'profs'
      : (p.role || '').toLowerCase().includes('étudiant') || (p.role || '').toLowerCase().includes('etud')
      ? 'etudiants'
      : 'admin';

    directory.push({
      id: p.user_id,
      nom: p.nom_complet,
      role: p.role || 'Membre ISGI',
      category: cat,
      enLigne: p.en_ligne,
      derniereConnexion: p.derniere_connexion,
      statutPerso: p.statut_perso
    });
  });

  // Ajouter depuis personnel non encore dans presences
  personnel.forEach(pers => {
    const userId = pers.id || `pers_${pers.matricule}`;
    if (directory.some(d => d.id === userId || d.id === pers.id) || userId === currentUserId) return;
    const isTeacher = pers.type_personnel === 'enseignant';
    directory.push({
      id: userId,
      nom: `${pers.nom.toUpperCase()} ${pers.prenom}`,
      role: pers.fonction || (isTeacher ? 'Enseignant' : 'Personnel'),
      category: isTeacher ? 'profs' : 'admin',
      enLigne: false
    });
  });

  // Ajouter un échantillon d'étudiants
  etudiants.slice(0, 15).forEach(etud => {
    const userId = etud.id || `etud_${etud.matricule}`;
    if (directory.some(d => d.id === userId || d.id === etud.id) || userId === currentUserId) return;
    directory.push({
      id: userId,
      nom: `${etud.nom.toUpperCase()} ${etud.prenom}`,
      role: `Étudiant (${etud.filiere || 'L1'})`,
      category: 'etudiants',
      enLigne: false
    });
  });

  // Filtrage
  const filtered = directory.filter(c => {
    if (activeTab !== 'tous' && c.category !== activeTab) return false;
    if (searchTerm.trim() !== '') {
      const q = searchTerm.toLowerCase();
      return c.nom.toLowerCase().includes(q) || c.role.toLowerCase().includes(q);
    }
    return true;
  });

  // Démarrer ou basculer sur la conversation directe
  const handleStartChat = async (contact: typeof directory[0]) => {
    setCreating(true);
    try {
      // 1. Vérifier si une conversation directe existe déjà entre ces deux utilisateurs
      const myParticipations = await db.conversation_participants
        .where('user_id')
        .equals(currentUserId)
        .toArray();

      const myConvIds = myParticipations.map(p => p.conversation_id);

      // Trouver si l'autre contact participe à l'une de ces conversations directes
      const otherParticipations = await db.conversation_participants
        .where('user_id')
        .equals(contact.id)
        .toArray();

      for (const otherPart of otherParticipations) {
        if (myConvIds.includes(otherPart.conversation_id)) {
          const conv = await db.conversations.get(otherPart.conversation_id);
          if (conv && conv.type === 'direct') {
            onSelectConversation(conv.id);
            onClose();
            return;
          }
        }
      }

      // 2. Sinon, créer une nouvelle conversation directe
      const convId = `conv_direct_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;
      const now = new Date().toISOString();

      const newConv: Conversation = {
        id: convId,
        type: 'direct',
        titre: contact.nom,
        created_by: currentUserId,
        dernier_message_texte: 'Conversation démarrée.',
        dernier_message_date: now,
        created_at: now,
        updated_at: now
      };
      await db.conversations.add(newConv);

      const part1: ConversationParticipant = {
        id: `part_${convId}_${currentUserId}`,
        conversation_id: convId,
        user_id: currentUserId,
        user_nom: currentUserName,
        statut_role: 'membre',
        joined_at: now
      };

      const part2: ConversationParticipant = {
        id: `part_${convId}_${contact.id}`,
        conversation_id: convId,
        user_id: contact.id,
        user_nom: contact.nom,
        user_role: contact.role,
        statut_role: 'membre',
        joined_at: now
      };

      await db.conversation_participants.bulkAdd([part1, part2]);

      onSelectConversation(convId);
      onClose();
    } catch (e: any) {
      alert("Erreur lors de l'ouverture de la discussion: " + e.message);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-200">
      <div className="bg-surface-container-lowest w-full max-w-md rounded-2xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
        {/* En-tête */}
        <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-primary/10 text-primary rounded-xl">
              <MessageSquare className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Nouvelle Discussion</h3>
              <p className="text-[11px] text-on-surface-variant">Choisir un membre de l’annuaire ISGI</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Recherche et Filtres */}
        <div className="p-3 border-b border-outline-variant space-y-2.5 bg-surface-container-lowest">
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par nom, fonction..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
              autoFocus
            />
          </div>

          <div className="flex items-center gap-1.5 overflow-x-auto custom-scrollbar text-xs">
            <button
              onClick={() => setActiveTab('tous')}
              className={cn(
                "px-2.5 py-1 rounded-full font-medium transition-colors shrink-0",
                activeTab === 'tous'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Tous ({directory.length})
            </button>
            <button
              onClick={() => setActiveTab('admin')}
              className={cn(
                "px-2.5 py-1 rounded-full font-medium transition-colors shrink-0",
                activeTab === 'admin'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Administration
            </button>
            <button
              onClick={() => setActiveTab('profs')}
              className={cn(
                "px-2.5 py-1 rounded-full font-medium transition-colors shrink-0",
                activeTab === 'profs'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Enseignants
            </button>
            <button
              onClick={() => setActiveTab('etudiants')}
              className={cn(
                "px-2.5 py-1 rounded-full font-medium transition-colors shrink-0",
                activeTab === 'etudiants'
                  ? "bg-primary text-on-primary font-bold shadow-xs"
                  : "bg-surface-container text-on-surface-variant hover:bg-surface-container-highest"
              )}
            >
              Étudiants
            </button>
          </div>
        </div>

        {/* Liste des contacts */}
        <div className="flex-1 overflow-y-auto divide-y divide-outline-variant/50 custom-scrollbar p-2">
          {filtered.length === 0 ? (
            <div className="py-8 text-center text-xs text-on-surface-variant">
              Aucun contact trouvé pour cette recherche.
            </div>
          ) : (
            filtered.map(contact => (
              <button
                key={contact.id}
                onClick={() => handleStartChat(contact)}
                disabled={creating}
                className="w-full flex items-center justify-between p-2.5 rounded-xl hover:bg-surface-container transition-colors text-left group"
              >
                <div className="flex items-center gap-3">
                  <div className="relative">
                    <div className="w-10 h-10 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-sm">
                      {contact.nom.charAt(0)}
                    </div>
                    {contact.enLigne && (
                      <span className="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-500 ring-2 ring-surface-container-lowest" />
                    )}
                  </div>
                  <div>
                    <h4 className="font-bold text-xs text-on-surface group-hover:text-primary transition-colors">
                      {contact.nom}
                    </h4>
                    <p className="text-[11px] text-on-surface-variant truncate max-w-[200px]">
                      {contact.role}
                    </p>
                    {contact.statutPerso && (
                      <p className="text-[10px] text-primary italic truncate max-w-[200px]">
                        « {contact.statutPerso} »
                      </p>
                    )}
                  </div>
                </div>

                <div className="text-right">
                  {contact.enLigne ? (
                    <span className="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                      En ligne
                    </span>
                  ) : (
                    <span className="text-[10px] text-on-surface-variant/60">
                      Hors ligne
                    </span>
                  )}
                </div>
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  );
};
