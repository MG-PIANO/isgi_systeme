<?php
// dashboard/surveillant/ajax/get_scanner_data.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    $surveillantId = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // Statistiques du jour
    $stats = getTodayStats($pdo, $surveillantId, $today);
    
    // Étudiants scannés aujourd'hui
    $scannedStudents = getScannedStudentsToday($pdo, $surveillantId, $today);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'scannedStudents' => $scannedStudents
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getTodayStats($pdo, $surveillantId, $today) {
    $sql = "SELECT 
                COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN p.statut = 'retard' THEN 1 END) as late,
                COUNT(CASE WHEN p.statut = 'absent' THEN 1 END) as absent,
                COUNT(*) as total,
                
                -- Nombre total d'étudiants dans les classes surveillées
                (SELECT COUNT(DISTINCT e.id) 
                 FROM etudiants e
                 JOIN classes c ON e.classe_id = c.id
                 WHERE e.statut = 'actif'
                 AND c.surveillant_id = :surveillant_id) as total_etudiants
            FROM presences p
            WHERE p.surveillant_id = :surveillant_id2
            AND DATE(p.date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':surveillant_id' => $surveillantId,
        ':surveillant_id2' => $surveillantId,
        ':today' => $today
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getScannedStudentsToday($pdo, $surveillantId, $today) {
    $sql = "SELECT 
                p.id as presence_id,
                p.type_presence,
                p.statut,
                p.date_heure,
                e.id as student_id,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom
            FROM presences p
            JOIN etudiants e ON p.etudiant_id = e.id
            LEFT JOIN classes c ON e.classe_id = c.id
            WHERE p.surveillant_id = :surveillant_id
            AND DATE(p.date_heure) = :today
            ORDER BY p.date_heure DESC
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':surveillant_id' => $surveillantId,
        ':today' => $today
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($row) {
        return [
            'student' => [
                'id' => $row['student_id'],
                'matricule' => $row['matricule'],
                'nom' => $row['nom'],
                'prenom' => $row['prenom'],
                'classe' => $row['classe_nom']
            ],
            'presence' => [
                'id' => $row['presence_id'],
                'type_presence' => $row['type_presence'],
                'statut' => $row['statut'],
                'date_heure' => $row['date_heure']
            ]
        ];
    }, $results);
}