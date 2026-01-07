<?php
define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));
require_once ROOT_PATH . '/config/database.php';

session_start();
$db = Database::getInstance()->getConnection();
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

if ($site_id === 0) {
    die(json_encode([]));
}

try {
    $query = "SELECT 
                rs.id as event_id,
                CONCAT(s.nom, ' - ', rs.evenement) as title,
                rs.date_debut as start,
                rs.date_fin as end,
                s.nom as salle_nom,
                rs.type_reservation,
                CONCAT('Réservation: ', rs.evenement, '\\nSalle: ', s.nom, '\\nType: ', rs.type_reservation) as description
              FROM reservations_salles rs
              LEFT JOIN salles s ON rs.salle_id = s.id
              WHERE s.site_id = :site_id
                AND rs.statut = 'confirmee'";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $events = [];
    
    foreach ($reservations as $r) {
        $events[] = [
            'id' => $r['event_id'],
            'title' => $r['title'],
            'start' => $r['start'],
            'end' => $r['end'],
            'description' => $r['description'],
            'color' => $r['type_reservation'] == 'examen' ? '#e74c3c' : 
                      ($r['type_reservation'] == 'reunion' ? '#f39c12' : '#3498db'),
            'textColor' => '#ffffff'
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($events);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
}