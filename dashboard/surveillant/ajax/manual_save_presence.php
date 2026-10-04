<?php
// dashboard/surveillant/ajax/manual_save_presence.php

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
    
    // Vérifier les données requises
    if (empty($data['student_id']) || empty($data['type_presence'])) {
        throw new Exception('Données manquantes');
    }
    
    // Récupérer l'étudiant
    $stmt = $pdo->prepare("SELECT * FROM etudiants WHERE id = ?");
    $stmt->execute([$data['student_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Étudiant non trouvé');
    }
    
    // Déterminer le statut
    $date_heure = date('Y-m-d H:i:s');
    $statut = 'present';
    
    if (in_array($data['type_presence'], ['entree_ecole', 'entree_classe_matin'])) {
        $heure_limite = date('Y-m-d 08:30:00');
        if (strtotime($date_heure) > strtotime($heure_limite)) {
            $statut = 'retard';
        }
    }
    
    // Enregistrer la présence
    $stmt = $pdo->prepare("
        INSERT INTO presences 
        (etudiant_id, type_presence, date_heure, statut, surveillant_id, matiere_id, scan_data) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $scanData = json_encode($data['scan_data'] ?? []);
    
    $stmt->execute([
        $student['id'],
        $data['type_presence'],
        $date_heure,
        $statut,
        $_SESSION['user_id'],
        null, // matiere_id
        $scanData
    ]);
    
    $presence_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Présence enregistrée manuellement',
        'student' => [
            'id' => $student['id'],
            'matricule' => $student['matricule'],
            'nom' => $student['nom'],
            'prenom' => $student['prenom'],
            'classe' => getClasseName($pdo, $student['classe_id'])
        ],
        'presence' => [
            'id' => $presence_id,
            'type_presence' => $data['type_presence'],
            'statut' => $statut,
            'date_heure' => $date_heure
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getClasseName($pdo, $classe_id) {
    if (!$classe_id) return '';
    
    $stmt = $pdo->prepare("SELECT nom FROM classes WHERE id = ?");
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    return $classe ? $classe['nom'] : '';
}