-- Script d'ajout de la colonne 'vague' pour les Cours du Soir dans la table etudiants
ALTER TABLE public.etudiants ADD COLUMN IF NOT EXISTS vague VARCHAR(50) DEFAULT 'Jour';

-- Mise à jour des enregistrements existants pour s'assurer qu'aucun étudiant n'a une valeur nulle
UPDATE public.etudiants SET vague = 'Jour' WHERE vague IS NULL OR vague = '';

-- Index pour accélérer les recherches par vague (Jour / Soir)
CREATE INDEX IF NOT EXISTS idx_etudiants_vague ON public.etudiants(vague);
