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

Sur Android, le premier démarrage demande l'URL Supabase et la clé publique `anon` dans **Paramètres**, puis une connexion avec un profil Informaticien autorisé. La page web du studio photo par QR reste une interface web HTTPS distincte.

Configure l'URL du projet Supabase et sa clé `anon` dans **Paramètres**, puis connecte-toi avec un compte Supabase Auth dont le profil dans `public.utilisateurs` a un rôle `informaticien` (ou un rôle administrateur) et le statut `actif`.

## Préparation Supabase

1. Exécuter le script de médiathèque `supabase_videos.sql` à la racine du dépôt s'il n'a pas déjà été appliqué. Il crée le bucket privé `videos-isgi`, les tables de vidéos et leurs règles d'accès.
2. Exécuter `supabase_photo_setup.sql` dans le SQL Editor Supabase. Il ajoute aussi les règles pour qu'un Informaticien puisse supprimer uniquement ses propres vidéos.
3. Vérifier que `public.classes` et les tables étudiants/classe utilisées dans le projet sont accessibles en lecture aux comptes Informaticien.
4. Créer le compte dans Supabase Auth et le profil correspondant dans `public.utilisateurs` avec un rôle autorisé et `statut = 'actif'`.

Après création de l'utilisateur Auth dans le tableau de bord, son profil peut être ajouté avec :

```sql
INSERT INTO public.utilisateurs (id, email, nom_complet, role, statut)
SELECT id, email, 'Service Informatique', 'informaticien', 'actif'
FROM auth.users
WHERE email = 'adresse-informaticien@isgi.cg'
ON CONFLICT (id) DO UPDATE
SET role = 'informaticien', statut = 'actif';
```

Les photos sont stockées dans le bucket privé `student-photos`. La fonction SQL ne permet de modifier que `photo_url`, et seulement à un compte Informaticien actif ou administrateur.

Les vidéos sont téléversées dans le bucket privé `videos-isgi` et publiées dans `public.videos`. Les classes de publication sont chargées depuis `public.classes`; si la table n'est pas disponible, elles sont déduites uniquement des champs de classe présents sur les étudiants synchronisés. Aucune liste de classes fictive n'est générée.

## Studio photo téléphone

Le contenu du dossier `mobile-photo/` est un mini-site statique à publier en HTTPS. Le nom provisoire configuré dans l'application est `https://photos.isgi.cg/`; remplace-le dans **Paramètres** lorsque le vrai domaine est décidé.

Avant le déploiement, renseigne `supabaseUrl` et la clé publique `anon` dans `mobile-photo/config.js`, puis publie les quatre fichiers du dossier (`index.html`, `app.js`, `style.css`, `config.js`) à la racine de ce sous-domaine. Ne place jamais de clé `service_role` dans ce site.

Le téléphone doit ouvrir l'URL HTTPS, se connecter avec le même compte Informaticien, cadrer/valider la photo et l'envoyer. L'application desktop détecte ensuite le changement de `photo_url` dans Supabase et actualise le badge.
