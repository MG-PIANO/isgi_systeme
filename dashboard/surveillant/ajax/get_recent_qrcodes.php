<?php
// dashboard/surveillant/ajax/get_recent_qrcodes.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];

// Récupérer les QR codes récemment générés
$qr_codes = [];

// 1. QR codes des étudiants (mis à jour récemment)
$query = "
    SELECT 
        e.matricule,
        CONCAT(e.nom, ' ', e.prenom) as name,
        e.qr_code_data as data,
        e.date_modification as date,
        'etudiant' as type
    FROM etudiants e
    WHERE e.site_id = :site_id 
      AND e.qr_code_data IS NOT NULL
      AND e.date_modification >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY e.date_modification DESC
    LIMIT 10
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id]);
$student_qrs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($student_qrs as $qr) {
    // Générer le chemin du fichier QR code
    $filename = 'etudiant_' . $qr['matricule'] . '.png';
    $filepath = '/uploads/qrcodes/' . $filename;
    $fullpath = dirname(dirname(dirname(__DIR__))) . $filepath;
    
    if (file_exists($fullpath)) {
        $qr_codes[] = [
            'name' => $qr['name'],
            'path' => $filepath,
            'filename' => $filename,
            'date' => date('d/m H:i', strtotime($qr['date'])),
            'type' => 'Étudiant'
        ];
    }
}

// 2. QR codes générés via le système (à partir des logs)
$query = "
    SELECT 
        details,
        date_action as date
    FROM logs_activite 
    WHERE utilisateur_id = :user_id 
      AND action LIKE '%qr_code%'
      AND date_action >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY date_action DESC
    LIMIT 5
";

$stmt = $db->prepare($query);
$stmt->execute([':user_id' => $_SESSION['user_id']]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($logs as $log) {
    // Extraire le nom du fichier du log
    if (preg_match('/qr_([^\.]+\.png)/', $log['details'], $matches)) {
        $filename = $matches[1];
        $filepath = '/uploads/qrcodes/' . $filename;
        $fullpath = dirname(dirname(dirname(__DIR__))) . $filepath;
        
        if (file_exists($fullpath)) {
            $qr_codes[] = [
                'name' => 'QR Code généré',
                'path' => $filepath,
                'filename' => $filename,
                'date' => date('d/m H:i', strtotime($log['date'])),
                'type' => 'Système'
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode(array_slice($qr_codes, 0, 10));
?>