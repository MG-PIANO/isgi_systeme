import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

export const supabase = createClient(supabaseUrl, supabaseKey, {
  auth: {
    persistSession: true,
    autoRefreshToken: true
  }
});
