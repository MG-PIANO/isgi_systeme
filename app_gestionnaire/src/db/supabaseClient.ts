import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://vbdhmgrysrerlmgumafx.supabase.co';
const supabaseAnonKey = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';

// We use the anon key for the frontend.
// The secret key should NEVER be exposed in the frontend code.
export const supabase = createClient(supabaseUrl, supabaseAnonKey);
