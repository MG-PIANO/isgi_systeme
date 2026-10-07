const fs = require('fs');

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

const candidateTables = [
  'utilisateurs', 'roles', 'user_roles', 'profiles', 'personnel', 'users', 
  'user_presences', 'admins', 'enseignants', 'professeurs', 'etudiants', 'tuteurs',
  'gestionnaires', 'comptables', 'secretariat', 'dac'
];

async function check() {
  console.log('--- CHECKING SUPABASE TABLES ---');
  for (const t of candidateTables) {
    try {
      const resp = await fetch(`${supabaseUrl}/rest/v1/${t}?select=*&limit=5`, {
        headers: {
          'apikey': supabaseKey,
          'Authorization': `Bearer ${supabaseKey}`
        }
      });
      if (resp.ok) {
        const data = await resp.json();
        console.log(`Table '${t}': EXISTS! Rows: ${data.length}`);
        if (data.length > 0) {
          console.log(`Sample from '${t}':`, JSON.stringify(data[0], null, 2));
        }
      } else {
        // Table doesn't exist or forbidden
      }
    } catch (e) {
      // ignore
    }
  }
}
check();
