import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { Conversation, ConversationParticipant, NotificationItem } from '../../types/academic';
import {
  Users,
  X,
  Search,
  Check
} from 'lucide-react';
import { cn } from '../../lib/utils';

interface NewGroupModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentUserId: string;
  currentUserName: string;
  onSelectConversation: (convId: string) => void;
}

const PALETTE_COLORS = [
  '#2563eb',
  '#06b6d4',
  '#10b981',
  '#8b5cf6',
  '#ec4899',
  '#f59e0b',
  '#ea580c',
  '#475569'
];

export const NewGroupModal: React.FC<NewGroupModalProps> = ({
  isOpen,
  onClose,
  currentUserId,
  currentUserName,
  onSelectConversation
}) => {
  const [titre, setTitre] = useState('');
  const [description, setDescription] = useState('');
  const [couleur, setCouleur] = useState(PALETTE_COLORS[0]);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedUserIds, setSelectedUserIds] = useState<string[]>([]);
  const [creating, setCreating] = useState(false);

  const presences = useLiveQuery(() => db.user_presences.toArray()) || [];
  const personnel = useLiveQuery(() => db.personnel.toArray()) || [];
  if (!isOpen) return null;

  const getUserCategory = (role: string, id: string): 'dg' | 'admin' | 'prof' | 'etudiant' => {
    const r = (role || '').toLowerCase();
    const i = (id || '').toLowerCase();
    if (r.includes('directeur général') || r.includes('dg') || i.includes('_dg')) return 'dg';
    if (r.includes('dac') || r.includes('secrétaire') || r.includes('directeur') || r.includes('comptab') || r.includes('admin') || i.includes('dac') || i.includes('user_sg') || i.includes('user_compta')) return 'admin';
    if (r.includes('enseignant') || r.includes('prof') || i.includes('prof')) return 'prof';
    return 'etudiant';
  };

  const checkNeedsValidation = (targetRole: string, targetId: string) => {
    const targetCategory = getUserCategory(targetRole, targetId);
    return targetCategory === 'dg';
  };

  const candidates: { id: string; nom: string; role: string; enLigne: boolean; needsValidation: boolean }[] = [];

  presences.forEach(p => {
    if (p.user_id === currentUserId) return;
    const role = p.role || 'Membre ISGI';
    candidates.push({
      id: p.user_id,
      nom: p.nom_complet,
      role,
      enLigne: p.en_ligne,
      needsValidation: checkNeedsValidation(role, p.user_id)
    });
  });

  personnel.forEach(pers => {
    const userId = pers.id || `pers_${pers.matricule}`;
    if (candidates.some(c => c.id === userId || c.id === pers.id) || userId === currentUserId) return;
    const role = pers.fonction || pers.type_personnel || 'Personnel';
    candidates.push({
      id: userId,
      nom: `${pers.nom.toUpperCase()} ${pers.prenom}`,
      role,
      enLigne: false,
      needsValidation: checkNeedsValidation(role, userId)
    });
  });

  const toggleSelectUser = (id: string) => {
    setSelectedUserIds(prev =>
      prev.includes(id) ? prev.filter(uid => uid !== id) : [...prev, id]
    );
  };

  const filteredCandidates = candidates.filter(c => {
    if (searchTerm.trim() === '') return true;
    const q = searchTerm.toLowerCase();
    return c.nom.toLowerCase().includes(q) || c.role.toLowerCase().includes(q);
  });

  const handleCreateGroup = async () => {
    if (!titre.trim()) {
      alert('Veuillez donner un nom à ce groupe.');
      return;
    }
    if (selectedUserIds.length === 0) {
      alert('Veuillez sélectionner au moins un participant pour ce groupe.');
      return;
    }

    setCreating(true);
    try {
      const convId = `conv_group_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;
      const now = new Date().toISOString();

      const newConv: Conversation = {
        id: convId,
        type: 'group',
        titre: titre.trim(),
        description: description.trim() || undefined,
        couleur,
        created_by: currentUserId,
        dernier_message_texte: `${currentUserName} a créé le groupe « ${titre.trim()} »`,
        dernier_message_date: now,
        created_at: now,
        updated_at: now
      };

      await db.conversations.add(newConv);

      const creatorParticipant: ConversationParticipant = {
        id: `part_${convId}_${currentUserId}`,
        conversation_id: convId,
        user_id: currentUserId,
        user_nom: currentUserName,
        statut_role: 'admin',
        statut_invitation: 'accepte',
        joined_at: now
      };

      const otherParticipants: ConversationParticipant[] = selectedUserIds.map(uid => {
        const cand = candidates.find(c => c.id === uid);
        const needsVal = cand?.needsValidation || false;
        return {
          id: `part_${convId}_${uid}`,
          conversation_id: convId,
          user_id: uid,
          user_nom: cand ? cand.nom : 'Membre',
          user_role: cand?.role,
          statut_role: 'membre',
          statut_invitation: needsVal ? 'en_attente' : 'accepte',
          invite_par: currentUserId,
          invite_par_nom: currentUserName,
          joined_at: now
        };
      });

      await db.conversation_participants.bulkAdd([creatorParticipant, ...otherParticipants]);

      for (const p of otherParticipants) {
        const notif: NotificationItem = {
          id: `notif_grp_${convId}_${p.user_id}`,
          user_id: p.user_id,
          type: p.statut_invitation === 'en_attente' ? 'invitation' : 'message',
          titre: `Groupe « ${titre} »`,
          description: p.statut_invitation === 'en_attente'
            ? `${currentUserName} vous a invité à rejoindre ce groupe. Confirmez pour y participer.`
            : `${currentUserName} vous a ajouté au groupe « ${titre} ».`,
          conversation_id: convId,
          auteur_nom: currentUserName,
          auteur_role: 'Secrétariat',
          lien_tab: 'messenger',
          lu: false,
          created_at: now
        };
        await db.notifications.put(notif);
      }

      onSelectConversation(convId);
      onClose();
    } catch (e: any) {
      alert('Erreur lors de la création du groupe : ' + e.message);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-200">
      <div className="bg-surface-container-lowest w-full max-w-lg rounded-2xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-secondary/10 text-secondary rounded-xl">
              <Users className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Créer un Nouveau Groupe</h3>
              <p className="text-[11px] text-on-surface-variant">Échanges administratifs et pédagogiques</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="p-4 space-y-4 overflow-y-auto flex-1">
          <div>
            <label className="block text-xs font-semibold text-on-surface mb-1">
              Nom du groupe <span className="text-error">*</span>
            </label>
            <input
              type="text"
              placeholder="Ex: Secrétariat & Admissions ISGI"
              value={titre}
              onChange={e => setTitre(e.target.value)}
              className="w-full px-3 py-2 text-xs bg-surface-container rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-on-surface mb-1">Description (optionnelle)</label>
            <input
              type="text"
              placeholder="Ex: Suivi des inscriptions, dossiers étudiants et requêtes"
              value={description}
              onChange={e => setDescription(e.target.value)}
              className="w-full px-3 py-2 text-xs bg-surface-container rounded-xl border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-on-surface mb-1.5">Couleur du groupe</label>
            <div className="flex items-center gap-2">
              {PALETTE_COLORS.map(c => (
                <button
                  key={c}
                  type="button"
                  onClick={() => setCouleur(c)}
                  className={cn(
                    "w-6 h-6 rounded-full transition-transform",
                    couleur === c ? "scale-125 ring-2 ring-offset-2 ring-primary" : "hover:scale-110"
                  )}
                  style={{ backgroundColor: c }}
                />
              ))}
            </div>
          </div>

          <div className="pt-2 border-t border-outline-variant">
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs font-semibold text-on-surface">
                Inviter des participants ({selectedUserIds.length} sélectionné{selectedUserIds.length > 1 ? 's' : ''})
              </label>
            </div>

            <div className="relative mb-2">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
              <input
                type="text"
                placeholder="Filtrer les contacts..."
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                className="w-full pl-8 pr-3 py-1.5 text-xs bg-surface-container rounded-lg border border-outline-variant text-on-surface focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>

            <div className="max-h-48 overflow-y-auto divide-y divide-outline-variant/30 border border-outline-variant rounded-xl">
              {filteredCandidates.map(cand => {
                const isSelected = selectedUserIds.includes(cand.id);
                return (
                  <div
                    key={cand.id}
                    onClick={() => toggleSelectUser(cand.id)}
                    className={cn(
                      "flex items-center justify-between p-2.5 text-xs cursor-pointer transition-colors",
                      isSelected ? "bg-primary/5" : "hover:bg-surface-container"
                    )}
                  >
                    <div className="flex items-center gap-2.5">
                      <div className="w-7 h-7 rounded-full bg-secondary/10 flex items-center justify-center font-bold text-[11px] text-secondary">
                        {cand.nom.charAt(0).toUpperCase()}
                      </div>
                      <div>
                        <div className="font-medium text-on-surface">{cand.nom}</div>
                        <div className="text-[10px] text-on-surface-variant">{cand.role}</div>
                      </div>
                    </div>

                    <div className={cn(
                      "w-4 h-4 rounded-md border flex items-center justify-center transition-colors",
                      isSelected
                        ? "bg-primary border-primary text-white"
                        : "border-outline-variant bg-surface-container"
                    )}>
                      {isSelected && <Check className="w-3 h-3 stroke-[3]" />}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        <div className="p-3 border-t border-outline-variant flex items-center justify-end gap-2 bg-surface-container-low">
          <button
            type="button"
            onClick={onClose}
            className="px-3 py-2 text-xs font-semibold rounded-xl text-on-surface-variant hover:bg-surface-container transition-colors"
          >
            Annuler
          </button>
          <button
            type="button"
            onClick={handleCreateGroup}
            disabled={creating || !titre.trim() || selectedUserIds.length === 0}
            className="px-4 py-2 text-xs font-bold rounded-xl bg-primary text-on-primary hover:opacity-90 transition-opacity disabled:opacity-50"
          >
            {creating ? 'Création en cours...' : 'Créer le Groupe'}
          </button>
        </div>
      </div>
    </div>
  );
};
