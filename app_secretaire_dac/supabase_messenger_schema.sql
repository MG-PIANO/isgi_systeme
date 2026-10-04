-- ==============================================================================
-- SCHÉMA SUPABASE SQL — ESPACE MESSENGER & MESSAGERIE INSTANTANÉE ISGI
-- ==============================================================================
-- Instructions :
-- 1. Ouvrez votre tableau de bord Supabase : https://supabase.com/dashboard
-- 2. Rendez-vous dans "SQL Editor"
-- 3. Créez une "New query", collez ce code et cliquez sur "Run"
-- ==============================================================================

-- 1. Table des Conversations (Privées 1-à-1 ou Groupes)
CREATE TABLE IF NOT EXISTS public.conversations (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL CHECK (type IN ('direct', 'group')),
    titre TEXT, -- Nom du groupe ou nom du contact pour affichage
    description TEXT,
    avatar_url TEXT,
    couleur TEXT DEFAULT '#2563eb',
    created_by TEXT NOT NULL,
    dernier_message_texte TEXT,
    dernier_message_date TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    dernier_message_sender TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. Table des Participants aux Conversations
CREATE TABLE IF NOT EXISTS public.conversation_participants (
    id TEXT PRIMARY KEY,
    conversation_id TEXT NOT NULL REFERENCES public.conversations(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL,
    user_nom TEXT NOT NULL,
    user_role TEXT,
    user_avatar TEXT,
    statut_role TEXT NOT NULL DEFAULT 'membre' CHECK (statut_role IN ('admin', 'membre')),
    joined_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    last_read_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    notifications_actives BOOLEAN DEFAULT true,
    UNIQUE(conversation_id, user_id)
);

-- 3. Table des Messages Échangés (Texte, Photos, Vidéos, PDF, Documents)
CREATE TABLE IF NOT EXISTS public.messages (
    id TEXT PRIMARY KEY,
    conversation_id TEXT NOT NULL REFERENCES public.conversations(id) ON DELETE CASCADE,
    sender_id TEXT NOT NULL,
    sender_nom TEXT NOT NULL,
    sender_role TEXT,
    sender_avatar TEXT,
    type_message TEXT NOT NULL DEFAULT 'text' CHECK (type_message IN ('text', 'image', 'video', 'audio', 'document')),
    contenu TEXT,
    fichier_url TEXT, -- URL de stockage ou DataURL base64
    fichier_nom TEXT,
    fichier_taille INTEGER, -- Taille en octets
    fichier_type TEXT, -- MIME Type (application/pdf, image/jpeg, etc.)
    lu_par JSONB DEFAULT '[]'::jsonb, -- Liste des user_id ayant lu le message
    statut TEXT DEFAULT 'envoye' CHECK (statut IN ('envoye', 'distribue', 'lu')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 4. Table des Statuts de Présence et Dernière Connexion
CREATE TABLE IF NOT EXISTS public.user_presences (
    user_id TEXT PRIMARY KEY,
    nom_complet TEXT NOT NULL,
    role TEXT,
    en_ligne BOOLEAN DEFAULT true,
    derniere_connexion TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    statut_perso TEXT DEFAULT 'Disponible',
    avatar_url TEXT
);

-- Index d'accélération des requêtes
CREATE INDEX IF NOT EXISTS idx_participants_user ON public.conversation_participants(user_id);
CREATE INDEX IF NOT EXISTS idx_participants_conv ON public.conversation_participants(conversation_id);
CREATE INDEX IF NOT EXISTS idx_messages_conv ON public.messages(conversation_id);
CREATE INDEX IF NOT EXISTS idx_messages_created ON public.messages(created_at);

-- ==============================================================================
-- SÉCURITÉ ROW LEVEL SECURITY (RLS) — CONFIDENTIALITÉ ABSOLUE
-- ==============================================================================
ALTER TABLE public.conversations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.conversation_participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.user_presences ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow all conversations access" ON public.conversations;
CREATE POLICY "Allow all conversations access" ON public.conversations FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all participants access" ON public.conversation_participants;
CREATE POLICY "Allow all participants access" ON public.conversation_participants FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all messages access" ON public.messages;
CREATE POLICY "Allow all messages access" ON public.messages FOR ALL USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS "Allow all user_presences access" ON public.user_presences;
CREATE POLICY "Allow all user_presences access" ON public.user_presences FOR ALL USING (true) WITH CHECK (true);

-- ==============================================================================
-- DONNÉES DE DÉPART : STATUTS DES UTILISATEURS DU CAMPUS
-- ==============================================================================
INSERT INTO public.user_presences (user_id, nom_complet, role, en_ligne, derniere_connexion, statut_perso)
VALUES 
('dac_default', 'Prof. M. DIALLO (DAC)', 'Directeur Académique', true, NOW(), 'En réunion de délibération'),
('user_sg', 'Dr. A. KOUAME', 'Secrétaire Général', true, NOW(), 'Disponible pour les dossiers étudiants'),
('user_dg', 'Dr. B. SANOGO', 'Directeur Général', false, NOW() - INTERVAL '25 minutes', 'En déplacement institutionnel'),
('user_compta', 'Mme. F. SYLLA', 'Chef Service Comptabilité', true, NOW(), 'Validation des inscriptions'),
('user_prof_algo', 'Dr. P. TRAORÉ', 'Enseignant Algorithmique & Web', true, NOW(), 'En cours magistral'),
('user_prof_reseau', 'Ing. M. NDIAYE', 'Enseignant Réseaux & Systèmes', false, NOW() - INTERVAL '2 hours', 'TP Réseaux'),
('user_etud_delegue', 'Moussa KONE', 'Délégué Promotion L2 GL', true, NOW(), 'Étudiant')
ON CONFLICT (user_id) DO UPDATE SET 
    en_ligne = EXCLUDED.en_ligne,
    derniere_connexion = EXCLUDED.derniere_connexion;

-- ==============================================================================
-- CONVERSATIONS INITIALES
-- ==============================================================================
INSERT INTO public.conversations (id, type, titre, description, couleur, created_by, dernier_message_texte, dernier_message_date, dernier_message_sender)
VALUES 
(
    'conv_dir_pedagogie', 'group', 'Conseil de Direction & Pédagogie',
    'Groupe officiel de coordination pédagogique, académique et administrative de l’ISGI',
    '#2563eb', 'dac_default',
    'Le calendrier académique officiel et les emplois du temps S1 sont disponibles.',
    NOW() - INTERVAL '5 minutes', 'Prof. M. DIALLO (DAC)'
),
(
    'conv_dept_info', 'group', 'Département Informatique & Télécoms',
    'Échanges entre enseignants, chefs de labos et direction académique',
    '#06b6d4', 'dac_default',
    'Les réservations pour le Labo Réseaux sont confirmées pour mercredi.',
    NOW() - INTERVAL '25 minutes', 'Ing. M. NDIAYE'
),
(
    'conv_direct_sg', 'direct', 'Dr. A. KOUAME (Secrétaire Général)',
    'Échange direct avec le Secrétariat Général',
    '#3b82f6', 'dac_default',
    'Avez-vous pu vérifier la liste des attestations de stage pour la L2 ?',
    NOW() - INTERVAL '40 minutes', 'Dr. A. KOUAME'
),
(
    'conv_direct_compta', 'direct', 'Mme. F. SYLLA (Comptabilité)',
    'Échange direct avec le Service Comptable',
    '#10b981', 'dac_default',
    'Les états financiers et reçus de scolarité pour les examens sont à jour.',
    NOW() - INTERVAL '2 hours', 'Mme. F. SYLLA'
)
ON CONFLICT (id) DO NOTHING;

-- PARTICIPANTS
INSERT INTO public.conversation_participants (id, conversation_id, user_id, user_nom, user_role, statut_role)
VALUES 
('part_dir_1', 'conv_dir_pedagogie', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'admin'),
('part_dir_2', 'conv_dir_pedagogie', 'user_sg', 'Dr. A. KOUAME', 'Secrétaire Général', 'membre'),
('part_dir_3', 'conv_dir_pedagogie', 'user_dg', 'Dr. B. SANOGO', 'Directeur Général', 'membre'),
('part_dir_4', 'conv_dir_pedagogie', 'user_compta', 'Mme. F. SYLLA', 'Comptable', 'membre'),

('part_info_1', 'conv_dept_info', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'admin'),
('part_info_2', 'conv_dept_info', 'user_prof_algo', 'Dr. P. TRAORÉ', 'Professeur', 'membre'),
('part_info_3', 'conv_dept_info', 'user_prof_reseau', 'Ing. M. NDIAYE', 'Professeur', 'membre'),

('part_sg_1', 'conv_direct_sg', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'membre'),
('part_sg_2', 'conv_direct_sg', 'user_sg', 'Dr. A. KOUAME', 'Secrétaire Général', 'membre'),

('part_compta_1', 'conv_direct_compta', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'membre'),
('part_compta_2', 'conv_direct_compta', 'user_compta', 'Mme. F. SYLLA', 'Comptable', 'membre')
ON CONFLICT (conversation_id, user_id) DO NOTHING;

-- MESSAGES INITIALS
INSERT INTO public.messages (id, conversation_id, sender_id, sender_nom, sender_role, type_message, contenu, statut, created_at)
VALUES 
('msg_dir_1', 'conv_dir_pedagogie', 'user_dg', 'Dr. B. SANOGO', 'Directeur Général', 'text', 'Bienvenue à tous sur l’espace d’échange et messagerie instantanée sécurisée de l’ISGI. Cet espace nous permet de fluidifier la coordination entre les départements.', 'lu', NOW() - INTERVAL '2 days'),
('msg_dir_2', 'conv_dir_pedagogie', 'user_sg', 'Dr. A. KOUAME', 'Secrétaire Général', 'text', 'Excellent dispositif. M. le Directeur Académique, les prévisions des dates d’examens sont-elles arrêtées ?', 'lu', NOW() - INTERVAL '1 day'),
('msg_dir_3', 'conv_dir_pedagogie', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'text', 'Bonjour M. le Directeur Général et M. le Secrétaire Général. Le calendrier académique officiel et les emplois du temps S1 sont disponibles et publiés pour l’ensemble des filières.', 'lu', NOW() - INTERVAL '5 minutes'),

('msg_sg_1', 'conv_direct_sg', 'user_sg', 'Dr. A. KOUAME', 'Secrétaire Général', 'text', 'Bonjour M. le Directeur Académique, avez-vous pu vérifier la liste des attestations de stage pour la promotion L2 Génie Logiciel ?', 'lu', NOW() - INTERVAL '40 minutes'),
('msg_sg_2', 'conv_direct_sg', 'dac_default', 'Prof. M. DIALLO (DAC)', 'DAC', 'text', 'Bonjour Dr. Kouamé, oui les attestations sont validées et prêtes pour signature au secrétariat.', 'distribue', NOW() - INTERVAL '20 minutes'),

('msg_compta_1', 'conv_direct_compta', 'user_compta', 'Mme. F. SYLLA', 'Comptabilité', 'text', 'Bonjour DAC, les états financiers et reçus de scolarité pour les examens sont à jour. Nous avons 94% des étudiants régularisés.', 'lu', NOW() - INTERVAL '2 hours')
ON CONFLICT (id) DO NOTHING;
