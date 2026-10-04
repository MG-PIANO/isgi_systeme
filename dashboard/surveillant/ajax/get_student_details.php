<?php
// dashboard/surveillant/ajax/get_student_details.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$studentId = $_GET['id'] ?? 0;

try {
    $pdo = Database::getConnection();
    
    // Récupérer tous les détails de l'étudiant depuis la table etudiant
    $sql = "SELECT 
                e.*,
                c.nom as classe_nom,
                c.niveau as classe_niveau,
                c.salle as classe_salle,
                u.nom as surveillant_nom,
                u.prenom as surveillant_prenom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            LEFT JOIN utilisateurs u ON c.surveillant_id = u.id
            WHERE e.id = :id
            AND e.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Étudiant non trouvé');
    }
    
    // Vérifier l'accès du surveillant
    if ($student['surveillant_id'] != $_SESSION['user_id']) {
        // Vérifier si le surveillant a accès à cette classe
        $accessSql = "SELECT COUNT(*) as has_access 
                     FROM classes_surveillants 
                     WHERE classe_id = :classe_id 
                     AND surveillant_id = :surveillant_id";
        
        $accessStmt = $pdo->prepare($accessSql);
        $accessStmt->execute([
            ':classe_id' => $student['classe_id'],
            ':surveillant_id' => $_SESSION['user_id']
        ]);
        
        $access = $accessStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$access['has_access']) {
            throw new Exception('Accès non autorisé à cet étudiant');
        }
    }
    
    echo json_encode($student);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}