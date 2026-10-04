<?php
// dashboard/surveillant/ajax/get_student_today_presences.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$studentId = $_GET['student_id'] ?? 0;
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $pdo = Database::getConnection();
    
    // Vérifier d'abord que l'étudiant existe
    $studentSql = "SELECT id FROM etudiants WHERE id = :id AND deleted_at IS NULL";
    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute([':id' => $studentId]);
    
    if (!$studentStmt->fetch()) {
        throw new Exception('Étudiant non trouvé');
    }
    
    // Récupérer les présences de l'étudiant pour la journée
    $sql = "SELECT 
                type_presence,
                statut,
                date_heure,
                matiere_id
            FROM presences 
            WHERE etudiant_id = :student_id
            AND DATE(date_heure) = :date
            ORDER BY date_heure ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_id' => $studentId,
        ':date' => $date
    ]);
    
    $presences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ajouter les noms des matières
    foreach ($presences as &$presence) {
        if ($presence['matiere_id']) {
            $matiereSql = "SELECT nom FROM matieres WHERE id = :id";
            $matiereStmt = $pdo->prepare($matiereSql);
            $matiereStmt->execute([':id' => $presence['matiere_id']]);
            $matiere = $matiereStmt->fetch(PDO::FETCH_ASSOC);
            $presence['matiere_nom'] = $matiere['nom'] ?? '';
        } else {
            $presence['matiere_nom'] = '';
        }
    }
    
    echo json_encode($presences);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}