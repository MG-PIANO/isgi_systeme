<?php
// dashboard/surveillant/ajax/process_qr_auto_save.php

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
    
    // Vérifier et traiter le QR code
    $matricule = $data['matricule'] ?? '';
    $type_presence = $data['type_presence'] ?? 'entree_ecole';
    
    // Récupérer l'ID de l'étudiant
    $stmt = $pdo->prepare("SELECT id, nom, prenom, classe_id FROM etudiants WHERE matricule = ?");
    $stmt->execute([$matricule]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception("Étudiant non trouvé");
    }
    
    // Déterminer le statut (présent, retard, etc.)
    $statut = 'present';
    $date_heure = date('Y-m-d H:i:s');
    
    // Vérifier si c'est un retard (après 8h30 pour l'entrée)
    if ($type_presence === 'entree_ecole' || $type_presence === 'entree_classe') {
        $heure_limite = date('Y-m-d 08:30:00');
        if (strtotime($date_heure) > strtotime($heure_limite)) {
            $statut = 'retard';
        }
    }
    
    // Enregistrer la présence
    $stmt = $pdo->prepare("
        INSERT INTO presences 
        (etudiant_id, type_presence, date_heure, statut, surveillant_id, matiere_id) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $student['id'],
        $type_presence,
        $date_heure,
        $statut,
        $_SESSION['user_id'],
        $data['matiere_id'] ?? null
    ]);
    
    // Récupérer les stats mises à jour
    $stats = getTodayStats($pdo);
    
    echo json_encode([
        'success' => true,
        'message' => 'Présence enregistrée automatiquement',
        'student' => [
            'id' => $student['id'],
            'matricule' => $matricule,
            'nom' => $student['nom'],
            'prenom' => $student['prenom'],
            'classe' => getClasseName($pdo, $student['classe_id'])
        ],
        'presence' => [
            'type_presence' => $type_presence,
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

function getTodayStats($pdo) {
    $date = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN statut = 'present' THEN 1 END) as presents,
            COUNT(CASE WHEN statut = 'absent' THEN 1 END) as absents,
            COUNT(CASE WHEN statut = 'retard' THEN 1 END) as late
        FROM presences 
        WHERE DATE(date_heure) = ?
    ");
    
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getClasseName($pdo, $classe_id) {
    $stmt = $pdo->prepare("SELECT nom FROM classes WHERE id = ?");
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    return $classe ? $classe['nom'] : '';
}