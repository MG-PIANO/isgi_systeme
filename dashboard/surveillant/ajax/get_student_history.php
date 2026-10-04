<?php
// dashboard/surveillant/ajax/get_student_history.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$student_id = $_GET['id'] ?? 0;
$today = date('Y-m-d');

try {
    $pdo = Database::getConnection();
    
    $stmt = $pdo->prepare("
        SELECT type_presence, date_heure, statut
        FROM presences
        WHERE etudiant_id = ?
        AND DATE(date_heure) = ?
        ORDER BY date_heure ASC
    ");
    
    $stmt->execute([$student_id, $today]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($history);
    
} catch (Exception $e) {
    echo json_encode([]);
}