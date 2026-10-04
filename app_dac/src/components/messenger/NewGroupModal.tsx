import React, { useState } from 'react';
import { useLiveQuery } from 'dexie-react-hooks';
import { db } from '../../db/db';
import type { Conversation, ConversationParticipant } from '../../types';
import {
  Users,
  X,
  Search,
  Check,
  Sparkles,
  Layers,
  Palette
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
  '#2563eb', // Bleu
  '#06b6d4', // Cyan
  '#10b981', // Émeraude
  '#8b5cf6', // Violet
  '#ec4899', // Rose
  '#f59e0b', // Ambre
  '#ea580c', // Orange
  '#475569'  // Ardoise
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

  // Déterminer la catégorie du créateur
  const myRole = localStorage.getItem('dac_user') ? JSON.parse(localStorage.getItem('dac_user')!).role || 'DAC' : 'DAC';
  
  const getUserCategory = (role: string, id: string): 'dg' | 'admin' | 'prof' | 'etudiant' => {
    const r = (role || '').toLowerCase();
    const i = (id || '').toLowerCase();
    if (r.includes('directeur général') || r.includes('dg') || i.includes('_dg')) return 'dg';
    if (r.includes('dac') || r.includes('secrétaire') || r.includes('directeur') || r.includes('comptab') || r.includes('admin') || i.includes('dac') || i.includes('user_sg') || i.includes('user_compta')) return 'admin';
    if (r.includes('enseignant') || r.includes('prof') || i.includes('prof')) return 'prof';
    return 'etudiant';
  };

  const myCategory = getUserCategory(myRole, currentUserId);

  const checkNeedsValidation = (targetRole: string, targetId: string) => {
    const targetCategory = getUserCategory(targetRole, targetId);
    if (myCategory === 'dg' || myCategory === 'admin') return false; // DG & Admin ajoutent directement
    if (myCategory === 'prof') {
      // Le prof ajoute les étudiants directement, mais doit faire une demande pour l'admin ou le DG
      return targetCategory === 'admin' || targetCategory === 'dg';
    }
    if (myCategory === 'etudiant') {
      // L'étudiant doit demander confirmation pour ajouter un prof, un admin ou le DG
      return targetCategory !== 'etudiant';
    }
    return false;
  };

  // Liste des contacts sélectionnables
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

  const filteredCandidates = candidates.filter(c => {
    if (!searchTerm.trim()) return true;
    const q = searchTerm.toLowerCase();
    return c.nom.toLowerCase().includes(q) || c.role.toLowerCase().includes(q);
  });

  const toggleSelectUser = (id: string) => {
    if (selectedUserIds.includes(id)) {
      setSelectedUserIds(selectedUserIds.filter(u => u !== id));
    } else {
      setSelectedUserIds([...selectedUserIds, id]);
    }
  };

  const handleCreateGroup = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!titre.trim()) {
      alert('Veuillez donner un nom au groupe.');
      return;
    }
    if (selectedUserIds.length === 0) {
      alert('Veuillez sélectionner au moins un autre membre pour ce groupe.');
      return;
    }

    setCreating(true);
    try {
      const convId = `conv_grp_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;
      const now = new Date().toISOString();

      const newGroup: Conversation = {
        id: convId,
        type: 'group',
        titre: titre.trim(),
        description: description.trim(),
        couleur,
        created_by: currentUserId,
        dernier_message_texte: `Groupe créé par ${currentUserName}.`,
        dernier_message_date: now,
        created_at: now,
        updated_at: now
      };
      await db.conversations.add(newGroup);

      // Créateur = Admin
      const participants: ConversationParticipant[] = [
        {
          id: `part_${convId}_${currentUserId}`,
          conversation_id: convId,
          user_id: currentUserId,
          user_nom: currentUserName,
          user_role: myRole,
          statut_role: 'admin',
          statut_invitation: 'direct',
          joined_at: now
        }
      ];

      // Membres invités selon les règles hiérarchiques
      for (const uId of selectedUserIds) {
        const c = candidates.find(cand => cand.id === uId);
        if (c) {
          const needsValidation = c.needsValidation;
          const statut_invitation = needsValidation ? 'en_attente' : 'direct';

          participants.push({
            id: `part_${convId}_${c.id}`,
            conversation_id: convId,
            user_id: c.id,
            user_nom: c.nom,
            user_role: c.role,
            statut_role: 'membre',
            statut_invitation,
            invite_par: currentUserId,
            invite_par_nom: currentUserName,
            joined_at: needsValidation ? undefined : now
          });

          // Créer une notification si validation requise
          if (needsValidation) {
            await db.notifications.add({
              id: `notif_grp_${convId}_${c.id}`,
              user_id: c.id,
              type: 'invitation_groupe',
              titre: 'Demande d\'ajout dans un groupe',
              description: `${currentUserName} (${myRole}) vous invite à rejoindre le groupe "${titre.trim()}". Votre validation est requise.`,
              auteur_nom: currentUserName,
              auteur_role: myRole,
              lien_tab: 'messenger',
              lien_id: convId,
              lu: false,
              donnees_extra: {
                conversation_id: convId,
                groupe_nom: titre.trim(),
                role_demandeur: myCategory
              },
              created_at: now
            });
          }
        }
      }

      await db.conversation_participants.bulkAdd(participants);

      // Message système initial
      await db.messages.add({
        id: `msg_init_${Date.now()}`,
        conversation_id: convId,
        sender_id: currentUserId,
        sender_nom: currentUserName,
        type_message: 'text',
        contenu: `A créé le groupe "${titre.trim()}". Bienvenue à tous les membres !`,
        created_at: now,
        statut: 'distribue'
      });

      onSelectConversation(convId);
      onClose();
    } catch (e: any) {
      alert('Erreur création groupe: ' + e.message);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-200">
      <div className="bg-surface-container-lowest w-full max-w-lg rounded-2xl border border-outline-variant shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        {/* En-tête */}
        <div className="p-4 border-b border-outline-variant flex items-center justify-between bg-surface-container-low">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-primary/10 text-primary rounded-xl">
              <Users className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-sm text-on-surface">Créer un Groupe de Discussion</h3>
              <p className="text-[11px] text-on-surface-variant">Échanges collaboratifs entre collègues ou classes</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleCreateGroup} className="flex-1 overflow-y-auto p-5 space-y-4 text-xs">
          {/* Nom du groupe & Couleur */}
          <div>
            <label className="block text-xs font-semibold text-on-surface mb-1">
              Nom du Groupe *
            </label>
            <div className="flex items-center gap-3">
              <div
                className="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-base shrink-0 shadow-xs"
                style={{ backgroundColor: couleur }}
              >
                {titre.trim() ? titre.trim().charAt(0).toUpperCase() : <Users className="w-5 h-5" />}
              </div>
              <input
                type="text"
                required
                placeholder="Ex: Commission Examens, L2 Génie Logiciel..."
                value={titre}
                onChange={(e) => setTitre(e.target.value)}
                className="flex-1 px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
              />
            </div>
          </div>

          {/* Choix de la couleur */}
          <div>
            <label className="block text-[11px] font-semibold text-on-surface-variant mb-1.5">
              Thème de couleur du groupe
            </label>
            <div className="flex items-center gap-2">
              {PALETTE_COLORS.map(c => (
                <button
                  type="button"
                  key={c}
                  onClick={() => setCouleur(c)}
                  className={cn(
                    "w-6 h-6 rounded-full transition-transform",
                    couleur === c ? "ring-2 ring-offset-2 ring-primary scale-110" : "opacity-80 hover:opacity-100"
                  )}
                  style={{ backgroundColor: c }}
                />
              ))}
            </div>
          </div>

          {/* Description */}
          <div>
            <label className="block text-xs font-semibold text-on-surface mb-1">
              Description / Objectif (Optionnel)
            </label>
            <textarea
              rows={2}
              placeholder="Rôle ou objet des échanges dans ce groupe..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="w-full px-3 py-2 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary resize-none"
            />
          </div>

          {/* Sélection des participants */}
          <div className="pt-2 border-t border-outline-variant space-y-2">
            <div className="flex items-center justify-between">
              <label className="block text-xs font-bold text-on-surface">
                Inviter des Membres ({selectedUserIds.length} sélectionné{selectedUserIds.length > 1 ? 's' : ''}) *
              </label>
            </div>

            {/* Règle institutionnelle ISGI */}
            <div className="p-2.5 rounded-xl bg-surface-container-high/60 border border-outline-variant/40 text-[10px] text-on-surface-variant leading-relaxed">
              <span className="font-bold text-on-surface">Règles des Groupes ISGI :</span> Les étudiants doivent obtenir l'accord d'un professeur ou de l'administration pour les intégrer. Les professeurs ajoutent directement les étudiants, mais l'ajout d'administrateurs nécessite validation. L'administration et le DG ont un accès direct.
            </div>

            <div className="relative">
              <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant" />
              <input
                type="text"
                placeholder="Filtrer les contacts..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-9 pr-3 py-1.5 bg-surface-container rounded-xl border border-outline-variant text-xs text-on-surface focus:outline-hidden focus:border-primary"
              />
            </div>

            <div className="max-h-48 overflow-y-auto divide-y divide-outline-variant/40 border border-outline-variant rounded-xl bg-surface-container-low p-1 custom-scrollbar">
              {filteredCandidates.map(contact => {
                const isSelected = selectedUserIds.includes(contact.id);
                return (
                  <div
                    key={contact.id}
                    onClick={() => toggleSelectUser(contact.id)}
                    className={cn(
                      "flex items-center justify-between p-2 rounded-lg cursor-pointer transition-colors",
                      isSelected ? "bg-primary/10 font-semibold" : "hover:bg-surface-container"
                    )}
                  >
                    <div className="flex items-center gap-2.5">
                      <div className="relative">
                        <div className="w-8 h-8 rounded-full bg-surface-container-high text-on-surface font-bold flex items-center justify-center text-xs">
                          {contact.nom.charAt(0)}
                        </div>
                        {contact.enLigne && (
                          <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-1 ring-surface-container-lowest" />
                        )}
                      </div>
                      <div>
                        <div className="flex items-center gap-1.5">
                          <p className="text-xs text-on-surface leading-tight">{contact.nom}</p>
                          {contact.needsValidation ? (
                            <span className="text-[9px] px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 font-semibold whitespace-nowrap">
                              Validation requise
                            </span>
                          ) : (
                            <span className="text-[9px] px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 font-semibold whitespace-nowrap">
                              Direct
                            </span>
                          )}
                        </div>
                        <p className="text-[10px] text-on-surface-variant">{contact.role}</p>
                      </div>
                    </div>

                    <div className={cn(
                      "w-5 h-5 rounded-md border flex items-center justify-center transition-colors",
                      isSelected ? "bg-primary border-primary text-on-primary" : "border-outline-variant"
                    )}>
                      {isSelected && <Check className="w-3.5 h-3.5" />}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="pt-3 border-t border-outline-variant flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container"
            >
              Annuler
            </button>
            <button
              type="submit"
              disabled={creating || !titre.trim() || selectedUserIds.length === 0}
              className="px-5 py-2 rounded-xl bg-primary text-on-primary text-xs font-bold hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50"
            >
              {creating ? 'Création...' : 'Créer le Groupe'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
