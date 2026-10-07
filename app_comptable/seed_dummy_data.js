import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';
const supabase = createClient(supabaseUrl, supabaseKey);

async function seedData() {
  console.log('Début de l\'insertion des données fictives...');

  // 1. Insertion du Personnel
  const personnelFictif = [
    {
      matricule: 'P-1001',
      prenom: 'Jean',
      nom: 'Dupont',
      email: 'jean.dupont@isgi.com',
      telephone: '0601020304',
      type_personnel: 'enseignant',
      salaire_base: 0,
      taux_horaire: 5000,
      numero_cnss: '12345678',
      date_entree: '2022-09-01'
    },
    {
      matricule: 'P-1002',
      prenom: 'Marie',
      nom: 'Curie',
      email: 'marie.curie@isgi.com',
      telephone: '0601020305',
      type_personnel: 'enseignant',
      salaire_base: 0,
      taux_horaire: 7500,
      numero_cnss: '87654321',
      date_entree: '2020-09-01'
    },
    {
      matricule: 'P-1003',
      prenom: 'Paul',
      nom: 'Martin',
      email: 'paul.martin@isgi.com',
      telephone: '0601020306',
      type_personnel: 'administratif',
      role_administratif: 'Secrétaire',
      salaire_base: 250000,
      numero_cnss: '11223344',
      date_entree: '2023-01-15'
    },
    {
      matricule: 'P-1004',
      prenom: 'Sophie',
      nom: 'Bernard',
      email: 'sophie.bernard@isgi.com',
      telephone: '0601020307',
      type_personnel: 'administratif',
      role_administratif: 'Gardien',
      salaire_base: 150000,
      numero_cnss: '55667788',
      date_entree: '2021-03-01'
    }
  ];

  const { data: pData, error: pError } = await supabase.from('personnel').insert(personnelFictif).select();
  if (pError) console.error('Erreur Personnel:', pError);
  else console.log('Personnel inséré avec succès.');

  // 2. Insertion de Dépenses Fictives (pour ce mois et le mois précédent)
  const currentYear = new Date().getFullYear();
  const currentMonth = new Date().getMonth();
  
  const dateMoisCourant = new Date(currentYear, currentMonth, 15).toISOString();
  const dateMoisPrecedent = new Date(currentYear, currentMonth - 1, 15).toISOString();

  const depensesFictives = [
    { categorie: 'fournitures', montant: 45000, description: 'Achat de papier et stylos', date_depense: dateMoisCourant, enregistre_par: 'Admin' },
    { categorie: 'factures', montant: 120000, description: 'Facture électricité', date_depense: dateMoisCourant, enregistre_par: 'Admin' },
    { categorie: 'loyer', montant: 500000, description: 'Loyer des locaux', date_depense: dateMoisPrecedent, enregistre_par: 'Admin' },
  ];

  const { error: dError } = await supabase.from('depenses').insert(depensesFictives);
  if (dError) console.error('Erreur Dépenses:', dError);
  else console.log('Dépenses insérées avec succès.');

  console.log('Terminé !');
}

seedData();
