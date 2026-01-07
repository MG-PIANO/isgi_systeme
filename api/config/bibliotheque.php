<?php
// config/bibliotheque.php

// Configuration de la bibliothèque
return [
    // Paramètres généraux
    'site_name' => 'Bibliothèque ISGI',
    'site_url' => 'https://bibliotheque.isgi.cg',
    'admin_email' => 'bibliotheque@isgi.cg',
    
    // Paramètres des fichiers
    'upload' => [
        'max_file_size' => 50 * 1024 * 1024, // 50MB
        'allowed_types' => [
            'pdf' => ['application/pdf'],
            'images' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif']
        ],
        'upload_path' => ROOT_PATH . '/assets/uploads/',
        'convert_path' => ROOT_PATH . '/assets/uploads/converted/'
    ],
    
    // Paramètres PDF
    'pdf' => [
        'default_font' => 'Times New Roman',
        'default_font_size' => 12,
        'default_line_height' => 1.5,
        'default_margin' => '2cm',
        'conversion_api' => 'api/pdf_converter.php'
    ],
    
    // Paramètres d'édition
    'editor' => [
        'min_pages_for_publish' => 30,
        'auto_save_interval' => 30, // secondes
        'default_style' => 'font-family: "Times New Roman", Times, serif; line-height: 1.5;',
        'allowed_fonts' => [
            'Times New Roman',
            'Arial',
            'Georgia',
            'Courier New',
            'Verdana'
        ]
    ],
    
    // Paramètres d'affichage
    'display' => [
        'books_per_page' => 12,
        'popular_books_limit' => 5,
        'recent_books_limit' => 5,
        'thumbnails_per_row' => 4
    ],
    
    // Sécurité
    'security' => [
        'api_key' => 'ISGI_BIBLIO_2025',
        'session_timeout' => 3600, // 1 heure
        'max_login_attempts' => 5,
        'password_min_length' => 8
    ],
    
    // Statistiques
    'stats' => [
        'track_downloads' => true,
        'track_views' => true,
        'track_favorites' => true,
        'keep_history_days' => 365
    ]
];