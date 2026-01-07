<?php
// dashboard/etudiant/download_document.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Démarrer la session
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 8) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accès interdit');
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier les paramètres
if (!isset($_GET['type']) || !isset($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    exit('Paramètres manquants');
}

$type = $_GET['type'];
$etudiant_id = intval($_GET['id']);

// Vérifier que l'étudiant ne peut accéder qu'à ses propres documents
try {
    $db = Database::getInstance()->getConnection();
    
    // Vérifier si l'étudiant correspond à l'utilisateur connecté
    $query = $db->prepare(
        "SELECT e.id, u.id as user_id 
         FROM etudiants e
         JOIN utilisateurs u ON e.utilisateur_id = u.id
         WHERE e.id = ? AND u.id = ?"
    );
    $query->execute([$etudiant_id, $_SESSION['user_id']]);
    $etudiant = $query->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant) {
        header('HTTP/1.0 403 Forbidden');
        exit('Accès non autorisé à ces documents');
    }
    
    // Récupérer le chemin du document
    $query = $db->prepare("SELECT {$type} as chemin FROM etudiants WHERE id = ?");
    $query->execute([$etudiant_id]);
    $result = $query->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || empty($result['chemin'])) {
        header('HTTP/1.0 404 Not Found');
        exit('Document non trouvé');
    }
    
    $chemin_document = ROOT_PATH . '/' . $result['chemin'];
    
    // Vérifier que le fichier existe
    if (!file_exists($chemin_document)) {
        header('HTTP/1.0 404 Not Found');
        exit('Fichier non trouvé sur le serveur');
    }
    
    // Déterminer le type MIME
    $extension = strtolower(pathinfo($chemin_document, PATHINFO_EXTENSION));
    $mime_types = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    $content_type = $mime_types[$extension] ?? 'application/octet-stream';
    
    // Déterminer le nom du fichier
    $noms_documents = [
        'photo_identite' => 'photo-identite',
        'acte_naissance' => 'acte-naissance',
        'releve_notes' => 'releve-notes',
        'attestation_legalisee' => 'attestation-legalisee'
    ];
    
    $nom_fichier = ($noms_documents[$type] ?? $type) . '.' . $extension;
    
    // Envoyer les headers et le fichier
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . $nom_fichier . '"');
    header('Content-Length: ' . filesize($chemin_document));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($chemin_document);
    exit;
    
} catch (Exception $e) {
    header('HTTP/1.0 500 Internal Server Error');
    exit('Erreur serveur: ' . $e->getMessage());
}
?>