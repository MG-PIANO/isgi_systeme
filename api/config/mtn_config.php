<?php

return [
    'mtn_mobile_money' => [
        'api_base_url' => env('MTN_API_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'subscription_key' => env('MTN_SUBSCRIPTION_KEY', 'votre_subscription_key'),
        'api_user' => env('MTN_API_USER', 'votre_api_user'),
        'api_key' => env('MTN_API_KEY', 'votre_api_key'),
        'callback_url' => env('MTN_CALLBACK_URL', 'https://votre-domaine.com/api/mtn/callback'),
        'currency' => 'XAF',
        'environment' => env('MTN_ENVIRONMENT', 'sandbox'), // sandbox ou production
        
        // Configuration des frais (1.5% pour MTN)
        'fees_percentage' => 1.5,
        
        // Numéros de téléphone MTN Congo
        'prefixes' => ['+24204', '+24205', '+24206'],
    ],
    
    'payment' => [
        'default_timeout' => 300, // 5 minutes pour le paiement
        'max_retries' => 3,
        'payment_url_expiry' => 24, // Heures avant expiration du lien de paiement
    ],
];