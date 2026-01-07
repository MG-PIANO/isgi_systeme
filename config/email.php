<?php
// config/email.php - Configuration d'envoi d'emails
define('EMAIL_CONFIG', [
    'smtp_host' => 'smtp.gmail.com', // ou votre serveur SMTP
    'smtp_port' => 587,
    'smtp_username' => 'rogermoundou@gmail.com',
    'smtp_password' => 'gsfesfcvqwqbkxic', // Mot de passe d'application
    'smtp_secure' => 'tls',
    'from_email' => 'noreply@isgi.cg',
    'from_name' => 'ISGI - Plateforme Académique',
    'reply_to' => 'support@isgi.cg',
    
    // Templates d'emails
    'verification_subject' => 'Votre code de vérification ISGI',
    'reset_subject' => 'Réinitialisation de votre mot de passe ISGI',
    'welcome_subject' => 'Bienvenue sur la plateforme ISGI'
]);