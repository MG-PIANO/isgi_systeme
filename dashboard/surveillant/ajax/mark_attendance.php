<?php
// dashboard/surveillant/ajax/mark_attendance.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit();
}

$student_id = $_POST['student_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$student_id || !in_array($status, ['present', 'absent', 'retard', 'justifie'])) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit();
}

$db = Database::getInstance()->getConnection();
$surveillant_id = $_SESSION['user_id'];
$site_id = $_SESSION['site_id'];
$now = date('Y-m-d H:i:s');

try {
    // Vérifier que l'étudiant appartient au site du surveillant
    $query = "SELECT id FROM etudiants WHERE id = :id AND site_id = :site_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $student_id, ':site_id' => $site_id]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Étudiant non trouvé']);
        exit();
    }
    
    // Enregistrer la présence
    $query = "
        INSERT INTO presences (
            etudiant_id, 
            site_id, 
            type_presence, 
            date_heure, 
            surveillant_id, 
            statut,
            date_creation
        ) VALUES (
            :etudiant_id,
            :site_id,
            'entree_ecole',
            :date_heure,
            :surveillant_id,
            :statut,
            :date_creation
        )
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':etudiant_id' => $student_id,
        ':site_id' => $site_id,
        ':date_heure' => $now,
        ':surveillant_id' => $surveillant_id,
        ':statut' => $status,
        ':date_creation' => $now
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Présence enregistrée']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
?>