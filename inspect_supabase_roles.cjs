const fs = require('fs');

const content = fs.readFileSync('creation_comptes.html', 'utf8');
const urlMatch = content.match(/SUPABASE_URL\s*=\s*['"]([^'"]+)['"]/);
const keyMatch = content.match(/SUPABASE_ANON_KEY\s*=\s*['"]([^'"]+)['"]/);

if (urlMatch && keyMatch) {
  const supabaseUrl = urlMatch[1];
  const supabaseKey = keyMatch[1];
  
  async function run() {
    try {
      const resp = await fetch(`${supabaseUrl}/rest/v1/utilisateurs?select=*`, {
        headers: {
          'apikey': supabaseKey,
          'Authorization': `Bearer ${supabaseKey}`
        }
      });
      const data = await resp.json();
      console.log('=== SUPABASE UTILISATEURS RECORDS COUNT ===', data.length);
      console.log('=== DATA SAMPLE ===', JSON.stringify(data.slice(0, 10), null, 2));
      const roles = [...new Set((data || []).map(u => u.role))];
      console.log('\n=== DISTINCT ROLES IN SUPABASE utilisateurs ===');
      console.log(roles);
    } catch (err) {
      console.error('Fetch error:', err);
    }
  }
  run();
} else {
  console.log('Could not find Supabase URL/KEY in creation_comptes.html');
}
