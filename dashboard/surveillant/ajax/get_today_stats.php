<?php
// dashboard/surveillant/ajax/get_today_stats.php

define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));
require_once ROOT_PATH . '/config/database.php';

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
    // Compter les présences d'aujourd'hui
    $query_presences = "SELECT COUNT(*) as count FROM presences 
                       WHERE site_id = :site_id 
                       AND DATE(date_heure) = :today";
    
    $stmt_presences = $db->prepare($query_presences);
    $stmt_presences->execute([':site_id' => $site_id, ':today' => $today]);
    $presences = $stmt_presences->fetch(PDO::FETCH_ASSOC);
    
    // Compter les scans uniques
    $query_scans = "SELECT COUNT(DISTINCT qr_code_scanne) as count FROM presences 
                   WHERE site_id = :site_id 
                   AND DATE(date_heure) = :today
                   AND qr_code_scanne IS NOT NULL";
    
    $stmt_scans = $db->prepare($query_scans);
    $stmt_scans->execute([':site_id' => $site_id, ':today' => $today]);
    $scans = $stmt_scans->fetch(PDO::FETCH_ASSOC);
    
    // Compter les étudiants actifs
    $query_students = "SELECT COUNT(*) as count FROM etudiants 
                      WHERE site_id = :site_id 
                      AND statut = 'actif'";
    
    $stmt_students = $db->prepare($query_students);
    $stmt_students->execute([':site_id' => $site_id]);
    $students = $stmt_students->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'presences' => (int)$presences['count'],
        'scans' => (int)$scans['count'],
        'active_students' => (int)$students['count']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}