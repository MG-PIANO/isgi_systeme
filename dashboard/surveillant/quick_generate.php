<?php
// dashboard/surveillant/quick_generate.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/libs/phpqrcode/qrlib.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    exit('Accès non autorisé');
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];

// Fonction pour générer un QR code rapide
function quickGenerateQR($student_id, $db, $site_id) {
    // Récupérer l'étudiant
    $query = "SELECT * FROM etudiants WHERE id = :id AND site_id = :site_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $student_id, ':site_id' => $site_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) return null;
    
    // Générer les données
    $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
              "NOM:" . $student['nom'] . "|" .
              "PRENOM:" . $student['prenom'] . "|" .
              "SITE:" . $site_id . "|" .
              "TYPE:etudiant|" .
              "DATE:" . date('YmdHis');
    
    $filename = 'etudiant_' . $student['matricule'] . '_quick_' . time() . '.png';
    $filepath = ROOT_PATH . '/uploads/qrcodes/quick/' . $filename;
    
    // Créer le dossier si nécessaire
    if (!file_exists(dirname($filepath))) {
        mkdir(dirname($filepath), 0777, true);
    }
    
    // Générer le QR code
    QRcode::png($qr_data, $filepath, QR_ECLEVEL_H, 8, 1);
    
    // Mettre à jour la base
    $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE id = :id";
    $stmt_update = $db->prepare($update_query);
    $stmt_update->execute([
        ':qr_data' => $qr_data,
        ':id' => $student_id
    ]);
    
    return [
        'student' => $student,
        'qr_path' => '/uploads/qrcodes/quick/' . $filename,
        'qr_data' => $qr_data
    ];
}

// Traitement des requêtes
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch($action) {
        case 'get_student':
            $matricule = $_GET['matricule'] ?? '';
            $query = "SELECT id, matricule, nom, prenom FROM etudiants WHERE matricule = :matricule AND site_id = :site_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':matricule' => $matricule, ':site_id' => $site_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($student);
            break;
            
        case 'generate':
            $student_id = $_GET['student_id'] ?? 0;
            $result = quickGenerateQR($student_id, $db, $site_id);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['error' => 'Action non reconnue']);
    }
}
?>