<?php
// dashboard/surveillant/ajax/search_student.php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    echo json_encode([]);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$query = $_GET['q'] ?? '';
$filter = $_GET['filter'] ?? '';

try {
    $sql = "SELECT e.id, e.matricule, e.nom, e.prenom, e.qr_code_data, 
                   c.nom as classe, e.statut
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            WHERE e.site_id = :site_id 
            AND e.statut = 'actif'";
    
    $params = [':site_id' => $site_id];
    
    if (!empty($query)) {
        $sql .= " AND (e.matricule LIKE :query OR e.nom LIKE :query OR e.prenom LIKE :query)";
        $params[':query'] = "%$query%";
    }
    
    if ($filter === 'no_qr') {
        $sql .= " AND (e.qr_code_data IS NULL OR e.qr_code_data = '')";
    }
    
    $sql .= " ORDER BY e.nom, e.prenom LIMIT 20";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($students);
    
} catch (Exception $e) {
    echo json_encode([]);
}
?>