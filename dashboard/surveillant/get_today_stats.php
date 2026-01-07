<?php
// dashboard/surveillant/ajax/get_today_stats.php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$today = date('Y-m-d');

try {
    // Nombre total d'étudiants actifs
    $totalQuery = "SELECT COUNT(*) as total FROM etudiants 
                   WHERE site_id = :site_id AND statut = 'actif'";
    $totalStmt = $db->prepare($totalQuery);
    $totalStmt->execute([':site_id' => $site_id]);
    $total = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Présences aujourd'hui (par statut)
    $statsQuery = "SELECT 
                   COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as presents,
                   COUNT(CASE WHEN p.statut = 'retard' THEN 1 END) as retards,
                   COUNT(CASE WHEN p.statut = 'absent' THEN 1 END) as absents
                   FROM presences p
                   INNER JOIN etudiants e ON p.etudiant_id = e.id
                   WHERE DATE(p.date_heure) = :today 
                   AND p.site_id = :site_id
                   AND p.type_presence IN ('entree_ecole', 'entree_classe', 'examen')";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->execute([
        ':today' => $today,
        ':site_id' => $site_id
    ]);
    
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'presents' => (int)($stats['presents'] ?? 0),
        'retards' => (int)($stats['retards'] ?? 0),
        'absents' => (int)($stats['absents'] ?? 0),
        'total' => (int)$total
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>