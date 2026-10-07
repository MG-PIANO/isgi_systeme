const fs = require('fs');

const content = fs.readFileSync('creation_comptes.html', 'utf8');
const urlMatch = content.match(/SUPABASE_URL\s*=\s*['"]([^'"]+)['"]/);
const keyMatch = content.match(/SUPABASE_ANON_KEY\s*=\s*['"]([^'"]+)['"]/);

if (urlMatch && keyMatch) {
  const supabaseUrl = urlMatch[1];
  const supabaseKey = keyMatch[1];
  
  async function run() {
    try {
      // Get OpenAPI schema from Supabase to see all columns of utilisateurs
      const resp = await fetch(`${supabaseUrl}/rest/v1/?apikey=${supabaseKey}`);
      const schema = await resp.json();
      const userDef = schema.definitions?.utilisateurs;
      console.log('=== UTILISATEURS COLUMNS IN SUPABASE ===');
      console.log(userDef ? Object.keys(userDef.properties) : 'No definition found');
      console.log(JSON.stringify(userDef?.properties, null, 2));
    } catch (err) {
      console.error('Fetch error:', err);
    }
  }
  run();
}
