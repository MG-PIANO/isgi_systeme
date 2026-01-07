<?php
// dashboard/surveillant/ajax/get_today_stats.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(['presents' => 0, 'absents' => 0]);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$today = date('Y-m-d');

// Statistiques du jour
$query = "
    SELECT 
        SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
        SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents
    FROM presences p
    WHERE p.site_id = :site_id 
      AND DATE(p.date_heure) = :today
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id, ':today' => $today]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'presents' => $stats['presents'] ?? 0,
    'absents' => $stats['absents'] ?? 0
]);
?>