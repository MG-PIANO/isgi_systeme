<?php
// dashboard/admin_principal/view_document.php
session_start();

// Vérifier l'authentification
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Accès interdit');
}

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Inclure la configuration
require_once ROOT_PATH . '/config/database.php';

// Récupérer les paramètres
$file = $_GET['file'] ?? '';
$download = isset($_GET['download']);

if (empty($file)) {
    header('HTTP/1.0 400 Bad Request');
    die('Fichier non spécifié');
}

// Nettoyer le chemin
$file = str_replace(['..', '//', '\\'], '', $file);
$file_path = ROOT_PATH . '/' . $file;

// Vérifier si le fichier existe
if (!file_exists($file_path)) {
    header('HTTP/1.0 404 Not Found');
    echo "<h2>Fichier non trouvé</h2>";
    echo "<p>Le fichier suivant n'a pas été trouvé :</p>";
    echo "<code>" . htmlspecialchars($file_path) . "</code>";
    die();
}

// Vérifier que c'est bien dans le dossier uploads
if (strpos($file, 'uploads/') !== 0) {
    header('HTTP/1.0 403 Forbidden');
    die('Accès non autorisé à ce fichier');
}

// Déterminer le type MIME
$mime_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

$extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$content_type = $mime_types[$extension] ?? 'application/octet-stream';

// Envoyer les headers
if ($download) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
} else {
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
}

header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Lire et afficher le fichier
readfile($file_path);
exit;
?>