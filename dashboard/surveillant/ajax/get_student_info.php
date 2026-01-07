<?php
// dashboard/surveillant/ajax/get_student_info.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(null);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$student_id = $_GET['id'] ?? 0;

$query = "
    SELECT 
        e.*,
        c.nom as classe,
        (SELECT COUNT(*) FROM presences p WHERE p.etudiant_id = e.id AND DATE(p.date_heure) = CURDATE()) as presences_aujourdhui,
        (SELECT COUNT(*) FROM presences p WHERE p.etudiant_id = e.id AND p.statut = 'absent' AND DATE(p.date_heure) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as absences_semaine
    FROM etudiants e
    LEFT JOIN classes c ON e.classe_id = c.id
    WHERE e.id = :id 
      AND e.site_id = :site_id
    LIMIT 1
";

$stmt = $db->prepare($query);
$stmt->execute([
    ':id' => $student_id,
    ':site_id' => $site_id
]);

$student = $stmt->fetch(PDO::FETCH_ASSOC);

if ($student) {
    // Ajouter l'URL du QR code existant si disponible
    if (!empty($student['qr_code_data'])) {
        $filename = 'etudiant_' . $student['matricule'] . '.png';
        $filepath = '/uploads/qrcodes/' . $filename;
        $fullpath = dirname(dirname(dirname(__DIR__))) . $filepath;
        
        if (file_exists($fullpath)) {
            $student['existing_qr'] = $filepath;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($student);
?>