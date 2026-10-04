<?php
// dashboard/surveillant/ajax/save_presence.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo = Database::getConnection();
    
    // VÉRIFICATION OBLIGATOIRE: L'étudiant existe-t-il ?
    $student = verifyStudentExists($pdo, $data['student_id'], $data['matricule']);
    
    if (!$student) {
        throw new Exception('Étudiant non trouvé dans la base de données');
    }
    
    // Vérifier si l'étudiant est actif
    if ($student['statut'] !== 'actif') {
        throw new Exception('Étudiant non actif. Statut: ' . $student['statut']);
    }
    
    // Déterminer le statut
    $date_heure = date('Y-m-d H:i:s');
    $statut = determineStatut($data['type_presence'], $date_heure);
    
    // Vérifier les doublons
    if (isDuplicatePresence($pdo, $student['id'], $data['type_presence'])) {
        throw new Exception('Présence déjà enregistrée pour ce type aujourd\'hui');
    }
    
    // Enregistrer la présence
    $presenceId = savePresenceRecord($pdo, [
        'etudiant_id' => $student['id'],
        'type_presence' => $data['type_presence'],
        'date_heure' => $date_heure,
        'statut' => $statut,
        'surveillant_id' => $data['surveillant_id'],
        'matiere_id' => $data['matiere_id'] ?? null
    ]);
    
    // Récupérer les statistiques mises à jour
    $stats = getTodayStats($pdo, $data['surveillant_id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Présence enregistrée avec succès',
        'presence_id' => $presenceId,
        'student' => formatStudentResponse($student),
        'presence' => [
            'id' => $presenceId,
            'type_presence' => $data['type_presence'],
            'statut' => $statut,
            'date_heure' => $date_heure
        ],
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Fonctions de vérification
function verifyStudentExists($pdo, $studentId, $matricule) {
    $sql = "SELECT e.*, c.nom as classe_nom 
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            WHERE e.id = :id 
            AND e.matricule = :matricule
            AND e.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $studentId,
        ':matricule' => $matricule
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function determineStatut($typePresence, $dateHeure) {
    $statut = 'present';
    
    // Vérifier les retards pour les entrées
    if (in_array($typePresence, ['entree_ecole', 'entree_classe'])) {
        $heureLimite = strtotime(date('Y-m-d 08:30:00'));
        $heureScan = strtotime($dateHeure);
        
        if ($heureScan > $heureLimite) {
            $statut = 'retard';
        }
    }
    
    return $statut;
}

function isDuplicatePresence($pdo, $studentId, $typePresence) {
    $today = date('Y-m-d');
    
    $sql = "SELECT COUNT(*) as count 
            FROM presences 
            WHERE etudiant_id = :student_id 
            AND type_presence = :type_presence
            AND DATE(date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_id' => $studentId,
        ':type_presence' => $typePresence,
        ':today' => $today
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

function savePresenceRecord($pdo, $data) {
    $sql = "INSERT INTO presences 
            (etudiant_id, type_presence, date_heure, statut, surveillant_id, matiere_id) 
            VALUES (:etudiant_id, :type_presence, :date_heure, :statut, :surveillant_id, :matiere_id)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    
    return $pdo->lastInsertId();
}

function getTodayStats($pdo, $surveillantId) {
    $today = date('Y-m-d');
    
    $sql = "SELECT 
                COUNT(CASE WHEN statut = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN statut = 'retard' THEN 1 END) as late,
                COUNT(CASE WHEN statut = 'absent' THEN 1 END) as absent,
                COUNT(*) as total
            FROM presences 
            WHERE surveillant_id = :surveillant_id
            AND DATE(date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':surveillant_id' => $surveillantId,
        ':today' => $today
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function formatStudentResponse($student) {
    return [
        'id' => $student['id'],
        'matricule' => $student['matricule'],
        'nom' => $student['nom'],
        'prenom' => $student['prenom'],
        'classe' => $student['classe_nom'] ?? ''
    ];
}