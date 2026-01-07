<?php
// config/qrcode_config.php

// Configuration des dossiers QR Code
define('QR_CODE_DIR', ROOT_PATH . '/uploads/qrcodes/');
define('QR_CODE_URL', '/uploads/qrcodes/');

// Sous-dossiers
define('QR_STUDENT_DIR', QR_CODE_DIR . 'students/');
define('QR_CLASS_DIR', QR_CODE_DIR . 'classes/');
define('QR_STAFF_DIR', QR_CODE_DIR . 'staff/');
define('QR_TEMP_DIR', QR_CODE_DIR . 'temp/');

// Créer les dossiers s'ils n'existent pas
function createQRDirectories() {
    $directories = [
        QR_CODE_DIR,
        QR_STUDENT_DIR,
        QR_CLASS_DIR,
        QR_STAFF_DIR,
        QR_TEMP_DIR
    ];
    
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}

// Nettoyer les fichiers temporaires
function cleanTempQRFiles($max_age_hours = 24) {
    $files = glob(QR_TEMP_DIR . '*');
    $now = time();
    $max_age = $max_age_hours * 3600;
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) > $max_age) {
                unlink($file);
            }
        }
    }
}

// Initialiser les dossiers
createQRDirectories();
cleanTempQRFiles();
?>