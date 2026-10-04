import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { Conversation, ConversationParticipant } from '../../types/academic';
import {
  Search,
  X,
  MessageSquare,
  User,
  ShieldCheck,
  GraduationCap
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
  const [, setCreating] = useState(false);

  const presences = useLiveQuery(() => db.user_presences.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  const etudiants = useLiveQuery(() => db.etudiants.toArray()) || [];

  if (!isOpen) return null;

  const directory: {
    id: string;
    nom: string;
    role: string;
    category: 'admin' | 'profs' | 'etudiants';
    enLigne: boolean;
    derniereConnexion?: string;
    statutPerso?: string;
  }[] = [];

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

  personnel.forEach(pers => {
    const userId = pers.id || `pers_${pers.matricule}`;
    if (directory.some(d => d.id === userId || d.id === pers.id) || userId === currentUserId) return;
    const isTeacher = (pers.type_personnel || '').toLowerCase().includes('enseign');
    directory.push({
      id: userId,
      nom: `${pers.nom.toUpperCase()} ${pers.prenom}`,
      role: pers.fonction || (isTeacher ? 'Enseignant' : 'Personnel Administratif'),
      category: isTeacher ? 'profs' : 'admin',
      enLigne: false
    });
  });

  etudiants.slice(0, 30).forEach(etud => {
    const userId = etud.id || `etud_${etud.matricule}`;
    if (directory.some(d => d.id === userId || d.id === etud.id) || userId === currentUserId) return;
    directory.push({
      id: userId,
      nom: `${etud.nom.toUpperCase()} ${etud.prenom}`,
      role: `Étudiant (${etud.filiere || 'L1'} - ${etud.matricule})`,
      category: 'etudiants',
      enLigne: false
    });
  });

  const filtered = directory.filter(c => {
    if (activeTab !== 'tous' && c.category !== activeTab) return false;
    if (searchTerm.trim() !== '') {
      const q = searchTerm.toLowerCase();
      return c.nom.toLowerCase().includes(q) || c.role.toLowerCase().includes(q);
    }
    return true;
  });

  const handleStartChat = async (contact: typeof directory[0]) => {
    setCreating(true);
    try {
      const myParticipations = await db.conversation_participants
        .where('user_id')
        .equals(currentUserId)
        .toArray();

      const myConvIds = myParticipations.map(p => p.conversation_id);

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
        statut_invitation: 'accepte',
        joined_at: now
      };

      const part2: ConversationParticipant = {
        id: `part_${convId}_${contact.id}`,
        conversation_id: convId,
        user_id: contact.id,
        user_nom: contact.nom,
        user_role: contact.role,
        statut_role: 'membre',
        statut_invitation: 'accepte',
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
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="p-3 border-b border-outline-variant">
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
            <input
              type="text"
              placeholder="Rechercher par nom, fonction..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-2 text-xs bg-surface-container rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
        </div>

        <div className="flex border-b border-outline-variant bg-surface-container-low px-2 pt-1 gap-1 text-[11px]">
          {(['tous', 'admin', 'profs', 'etudiants'] as const).map(tab => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={cn(
                "px-3 py-1.5 font-medium rounded-t-lg transition-colors capitalize",
                activeTab === tab
                  ? "bg-surface-container-lowest text-primary font-bold border-t-2 border-primary shadow-xs"
                  : "text-on-surface-variant hover:text-on-surface"
              )}
            >
              {tab === 'tous' ? 'Tous' : tab === 'admin' ? 'Personnel' : tab === 'profs' ? 'Enseignants' : 'Étudiants'}
            </button>
          ))}
        </div>

        <div className="flex-1 overflow-y-auto divide-y divide-outline-variant/40 p-1">
          {filtered.length === 0 ? (
            <div className="p-8 text-center text-on-surface-variant">
              <User className="w-8 h-8 mx-auto mb-2 opacity-30" />
              <p className="text-xs">Aucun contact trouvé.</p>
            </div>
          ) : (
            filtered.map(contact => (
              <button
                key={contact.id}
                onClick={() => handleStartChat(contact)}
                className="w-full flex items-center justify-between p-3 rounded-xl hover:bg-surface-container transition-colors text-left group"
              >
                <div className="flex items-center gap-3">
                  <div className="relative">
                    <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center font-bold text-xs text-primary">
                      {contact.nom.charAt(0).toUpperCase()}
                    </div>
                    {contact.enLigne && (
                      <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-2 ring-surface-container-lowest" />
                    )}
                  </div>
                  <div>
                    <h4 className="font-semibold text-xs text-on-surface group-hover:text-primary transition-colors">
                      {contact.nom}
                    </h4>
                    <p className="text-[10px] text-on-surface-variant">{contact.role}</p>
                  </div>
                </div>

                <div className="flex items-center gap-1.5">
                  {contact.category === 'admin' && (
                    <span className="p-1 rounded-md bg-blue-500/10 text-blue-600 text-[10px]" title="Administration">
                      <ShieldCheck className="w-3 h-3" />
                    </span>
                  )}
                  {contact.category === 'profs' && (
                    <span className="p-1 rounded-md bg-purple-500/10 text-purple-600 text-[10px]" title="Enseignant">
                      <GraduationCap className="w-3 h-3" />
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
