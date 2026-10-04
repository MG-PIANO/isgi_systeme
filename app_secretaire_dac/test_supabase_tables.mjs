import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';
const supabase = createClient(supabaseUrl, supabaseKey);

async function check() {
  const tables = [
    'calendrier_academique',
    'calendriers_academiques',
    'emplois_du_temps',
    'emploi_du_temps',
    'salles',
    'classes',
    'matieres',
    'etudiants',
    'annees_academiques'
  ];
  for (const t of tables) {
    try {
      const { data, error } = await supabase.from(t).select('*').limit(2);
      if (error) {
        console.log(`Table '${t}': NOT FOUND or error (${error.code}):`, error.message);
      } else {
        console.log(`Table '${t}': EXISTS (sample count: ${data ? data.length : 0})`);
      }
    } catch (e) {
      console.log(`Table '${t}': Exception`, e.message);
    }
  }
}
check();
