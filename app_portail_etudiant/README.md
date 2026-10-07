# Portail étudiant et tuteur ISGI

Application web responsive pour les étudiants et leurs tuteurs. Les comptes
sont créés avec un nom d’utilisateur, un mot de passe et un ou plusieurs
matricules. Le portail ne donne accès aux données scolaires qu’après validation
par un membre autorisé du personnel et activation par un code à usage unique
remis en personne.

## Déploiement Supabase

1. Vérifier que les schémas existants `etudiants`, `notes`,
   `presences_etudiants`, `emplois_du_temps`, `classes`, `classe_etudiants`,
   `videos` et `paiements` sont installés et alimentés.
2. Exécuter `supabase_portal_accounts.sql` dans le SQL Editor Supabase. Ce
   script crée uniquement les tables privées des demandes et des liens
   étudiant-compte ; il ne modifie pas les politiques des applications en
   place.
3. Configurer les UUID Auth vérifiés du personnel chargé des validations dans
   le secret Edge Function `PORTAL_REVIEWER_USER_IDS`, séparés par des virgules :

   ```powershell
   supabase secrets set PORTAL_REVIEWER_USER_IDS="uuid-dac,uuid-secretariat,uuid-comptable"
   ```

   N’inscrire que les comptes institutionnels réellement autorisés. L’API
   vérifie l’UUID Supabase Auth et ne se fie pas au rôle déclaré dans le
   navigateur.
4. Dans Supabase Auth, les comptes utilisent un e-mail interne synthétique
   (`nomutilisateur@accounts.isgi.cg`). Aucun e-mail n’est demandé ni envoyé.
5. Déployer les deux Edge Functions depuis la racine du dépôt :

   ```powershell
   supabase functions deploy portal-api --no-verify-jwt
   supabase functions deploy portal-staff
   ```

   Les fonctions utilisent les variables `SUPABASE_URL`,
   `SUPABASE_ANON_KEY` et `SUPABASE_SERVICE_ROLE_KEY` du projet Supabase.
   La clé `service_role` doit rester configurée côté Supabase, jamais dans le
   code du navigateur.
6. Installer et construire le site :

   ```powershell
   Set-Location app_portail_etudiant
   npm install
   npm run build
   ```

   Publier ensuite le contenu de `dist/` sur un hébergement HTTPS.

## Limite de sécurité avant mise en production

Le navigateur ne reçoit les notes, présences, paiements, vidéos et dossiers
étudiants que par l’Edge Function, qui filtre les données par les matricules
validés. Cependant, les anciennes applications utilisent encore certaines
tables Supabase avec des politiques permissives et des accès sans session
vérifiée. Tant que ces clients ne sont pas adaptés, un utilisateur technique
peut contourner l’interface du portail en appelant directement l’API Supabase.

**Ne pas ouvrir ce portail au public et ne pas exécuter**
`supabase_portal_hardening_pending.sql` pour l’instant : ce durcissement RLS
bloquerait aussi les applications Surveillant, Surveillant Mobile et
Informaticien, selon leur mode d’accès actuel. Il faudra d’abord adapter et
tester ces clients, puis réexaminer cette migration stricte.

## Flux de validation

- Une demande étudiante exige un seul matricule ; un tuteur peut en demander
  jusqu’à dix. Le serveur vérifie les matricules dans `etudiants`.
- L’inscription crée un compte Auth inactif pour les accès aux données, sans
  conserver le mot de passe dans les tables du portail.
- L’agent vérifie les dossiers et l’identité en personne avant d’approuver.
  Pour les tuteurs, la filiation doit être vérifiée manuellement : les
  matricules ne constituent pas à eux seuls une preuve de parenté.
- L’approbation génère un code aléatoire ; seule son empreinte est stockée.
  Le code brut est affiché une fois pour être communiqué en personne.
- Si la remise du code échoue, l’agent peut réémettre un code ; l’ancien devient
  immédiatement invalide.
- Les nouvelles demandes et tentatives d’activation sont limitées par heure ;
  les adresses IP ne sont conservées que sous forme d’empreinte.
- Après l’activation, l’Edge Function du portail ne renvoie que les dossiers
  liés au compte ; la limitation directe au niveau RLS reste différée comme
  décrit ci-dessus.

Les applications Comptable, DAC et Secrétaire DAC affichent le menu
« Demandes comptes étudiants ». Les agents doivent avoir une session Supabase
Auth valide et figurer dans `portal_staff` pour consulter ou traiter la file.
