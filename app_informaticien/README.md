# ISGI Informaticien

Application Electron du service informatique : badges étudiants, prise de photo mobile et médiathèque vidéo.

## Démarrage local

```powershell
npm install
npm start
```

## Génération de l'APK Android

Prérequis : Node.js, Android Studio/SDK, Java 21 et une connexion réseau lors du premier build.

```powershell
npm install
npm run build:android
```

L'APK de test est généré sous `dist/ISGI-Informaticien-debug.apk`. Il contient l'application desktop adaptée aux écrans tactiles Android : navigation mobile, sélection de fichiers sur le téléphone, génération PDF/QR et connexion Supabase. C'est un APK de débogage ; une version de production doit être signée avant distribution publique.

L'URL du projet ISGI et sa clé publique sont intégrées comme dans les autres applications : l'utilisateur n'a pas à configurer Supabase. Connecte-toi avec un compte Supabase Auth dont le profil dans `public.utilisateurs` a un rôle Informaticien (ou un rôle administrateur) et le statut `actif`. La page web du studio photo par QR reste une interface web HTTPS distincte.

L'application utilise le même projet Supabase que les autres applications ISGI et vérifie automatiquement le profil et le rôle du compte à la connexion.

## Préparation Supabase

1. Exécuter le script de médiathèque `supabase_videos.sql` à la racine du dépôt s'il n'a pas déjà été appliqué. Il crée le bucket privé `videos-isgi`, les tables de vidéos et leurs règles d'accès.
2. Exécuter `supabase_photo_setup.sql` dans le SQL Editor Supabase. Il configure les photos, crée `public.informaticien_badges` et `public.informaticien_parametres` avec leurs politiques RLS, et autorise la suppression des vidéos appartenant au compte.
3. Vérifier que `public.classes` et `public.etudiants` sont accessibles en lecture aux comptes Informaticien. Les listes du formulaire de badge utilisent les filières et niveaux présents dans les fiches d'étudiants et les classes; aucune table `filieres` ou `niveaux` séparée n'est requise dans Supabase.
4. Créer le compte dans Supabase Auth et le profil correspondant dans `public.utilisateurs` avec un rôle autorisé et `statut = 'actif'`.

Si les journaux indiquent `Could not find the table 'public.informaticien_badges' in the schema cache`, l'étape 2 n'a pas encore été exécutée (ou le cache PostgREST n'a pas été actualisé). La connexion est conservée et les tables étudiants/classes restent consultables; les fonctions de badges et paramètres partagés nécessitent la migration.

Après création de l'utilisateur Auth dans le tableau de bord, son profil peut être ajouté avec :

```sql
INSERT INTO public.utilisateurs (id, email, nom_complet, role, statut)
SELECT id, email, 'Service Informatique', 'informaticien', 'actif'
FROM auth.users
WHERE email = 'adresse-informaticien@isgi.cg'
ON CONFLICT (id) DO UPDATE
SET role = 'informaticien', statut = 'actif';
```

Les étudiants, classes, badges et paramètres partagés (année par défaut et nom du directeur) sont chargés depuis Supabase à chaque connexion. L'historique des badges est enregistré dans `public.informaticien_badges`; ces données métier ne sont plus enregistrées dans `localStorage`. Seul le dossier de sortie des PDF reste une préférence propre à cet ordinateur.

Le QR code encode un objet JSON versionné (`type: ISGI_STUDENT`) avec le matricule, le nom et le prénom de l'étudiant. Les applications d'inscription Comptabilité, DAC et Secrétaire-DAC affichent et permettent de télécharger ce QR dès l'enregistrement, puis dans les détails de l'étudiant. Les parcours web DAC (ajout manuel et import CSV), Gestionnaire et validation des demandes affichent également le QR à la création. Le créateur de badges régénère le même contenu à partir de la fiche étudiant; il est imprimé sur le recto et inclus dans les exports PNG/PDF ainsi que dans les badges en lot. Ces informations peuvent être lues par une application de scan QR standard; le QR ne contient ni photo ni coordonnées privées. Le fichier `renderer/qrcode.bundle.js` est le bundle navigateur du générateur QR; il est régénéré par `npm run build:qrcode` et avant la compilation de l'installateur.

Les photos sont stockées dans le bucket privé `student-photos`. La fonction SQL `update_student_photo` ne modifie que `photo_url`, et seulement pour un compte Informaticien actif ou administrateur.

Les vidéos et miniatures sont téléversées dans le bucket privé `videos-isgi` et publiées dans `public.videos`. Les fichiers vidéo sont envoyés en flux depuis l'application desktop; les URLs signées permettent leur lecture sans rendre le bucket public. Une erreur d'accès aux tables ou aux buckets est affichée et n'est pas remplacée par des données locales ou fictives.

## Studio photo téléphone

Le contenu du dossier `mobile-photo/` est un mini-site statique à publier en HTTPS. Le nom provisoire configuré dans l'application est `https://photos.isgi.cg/`; remplace-le dans **Paramètres** lorsque le vrai domaine est décidé.

Avant le déploiement, renseigne `supabaseUrl` et la clé publique `anon` dans `mobile-photo/config.js`, puis publie les quatre fichiers du dossier (`index.html`, `app.js`, `style.css`, `config.js`) à la racine de ce sous-domaine. Ne place jamais de clé `service_role` dans ce site.

Le téléphone ouvre l'URL HTTPS et envoie les photos avec le même compte Informaticien. Depuis le desktop, synchronise les données pour afficher les photos actualisées.
