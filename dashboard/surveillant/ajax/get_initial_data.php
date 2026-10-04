<?php
// dashboard/surveillant/ajax/get_initial_data.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();
    $surveillant_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // Statistiques du jour
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as presents,
            COUNT(CASE WHEN p.statut = 'retard' THEN 1 END) as late,
            COUNT(CASE WHEN p.statut = 'absent' THEN 1 END) as absents,
            COUNT(*) as total
        FROM presences p
        WHERE DATE(p.date_heure) = ?
        AND p.surveillant_id = ?
    ");
    
    $stmt->execute([$today, $surveillant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Scans récents (derniers 10)
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.matricule,
            e.nom,
            e.prenom,
            c.nom as classe_nom
        FROM presences p
        JOIN etudiants e ON p.etudiant_id = e.id
        LEFT JOIN classes c ON e.classe_id = c.id
        WHERE DATE(p.date_heure) = ?
        AND p.surveillant_id = ?
        ORDER BY p.date_heure DESC
        LIMIT 10
    ");
    
    $stmt->execute([$today, $surveillant_id]);
    $recentScans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données
    $formattedScans = array_map(function($scan) {
        return [
            'student' => [
                'matricule' => $scan['matricule'],
                'nom' => $scan['nom'],
                'prenom' => $scan['prenom'],
                'classe' => $scan['classe_nom']
            ],
            'presence' => [
                'type_presence' => $scan['type_presence'],
                'statut' => $scan['statut'],
                'date_heure' => $scan['date_heure']
            ]
        ];
    }, $recentScans);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'recentScans' => $formattedScans
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}