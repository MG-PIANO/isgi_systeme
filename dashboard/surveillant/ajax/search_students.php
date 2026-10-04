<?php
// dashboard/surveillant/ajax/search_students.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$limit = 10;

try {
    $pdo = Database::getConnection();
    
    $sql = "
        SELECT e.id, e.matricule, e.nom, e.prenom, c.nom as classe_nom
        FROM etudiants e
        LEFT JOIN classes c ON e.classe_id = c.id
        WHERE e.statut = 'actif'
        AND (
            e.matricule LIKE ?
            OR e.nom LIKE ?
            OR e.prenom LIKE ?
            OR CONCAT(e.nom, ' ', e.prenom) LIKE ?
        )
        ORDER BY e.nom, e.prenom
        LIMIT ?
    ";
    
    $searchTerm = "%$query%";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($students);
    
} catch (Exception $e) {
    echo json_encode([]);
}