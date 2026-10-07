import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

const supabase = createClient(supabaseUrl, supabaseKey);

async function createAdmin() {
  const { data, error } = await supabase.from('utilisateurs').insert([{
    id: 'e4c6ff7f-9eb4-46ce-a0bc-6a261f9f3a0f',
    email: 'admin@gmail.com',
    nom_complet: 'Administrateur Général',
    role: 'admin',
    statut: 'actif'
  }]);

  if (error) {
    console.error('Erreur lors de l\'insertion:', error);
  } else {
    console.log('Compte admin créé avec succès dans la table utilisateurs !', data);
  }
}

createAdmin();
