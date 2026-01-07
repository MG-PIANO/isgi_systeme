<?php
// dashboard/surveillant/ajax/process_qr_scan.php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$db = Database::getInstance()->getConnection();

try {
    // Vérifier les données reçues
    if (empty($input['matricule'])) {
        throw new Exception('Matricule manquant');
    }
    
    // Récupérer l'étudiant
    $query = "SELECT e.*, c.nom as classe_nom 
              FROM etudiants e 
              LEFT JOIN classes c ON e.classe_id = c.id 
              WHERE e.matricule = :matricule 
              AND e.site_id = :site_id 
              AND e.statut = 'actif'";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':matricule' => $input['matricule'],
        ':site_id' => $_SESSION['site_id']
    ]);
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Étudiant non trouvé ou inactif');
    }
    
    // Vérifier si une présence existe déjà pour aujourd'hui et ce type
    $today = date('Y-m-d');
    $checkQuery = "SELECT id, statut FROM presences 
                   WHERE etudiant_id = :etudiant_id 
                   AND DATE(date_heure) = :today 
                   AND type_presence = :type_presence";
    
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([
        ':etudiant_id' => $student['id'],
        ':today' => $today,
        ':type_presence' => $input['type_presence']
    ]);
    
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Mettre à jour la présence existante
        $updateQuery = "UPDATE presences SET 
                       date_heure = NOW(),
                       statut = 'present',
                       matiere_id = :matiere_id,
                       surveillant_id = :surveillant_id
                       WHERE id = :id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':matiere_id' => $input['matiere_id'] ?? null,
            ':surveillant_id' => $_SESSION['user_id'],
            ':id' => $existing['id']
        ]);
        
        $presenceId = $existing['id'];
    } else {
        // Créer une nouvelle présence
        $insertQuery = "INSERT INTO presences 
                       (etudiant_id, type_presence, date_heure, statut, matiere_id, surveillant_id, site_id, created_at) 
                       VALUES 
                       (:etudiant_id, :type_presence, NOW(), 'present', :matiere_id, :surveillant_id, :site_id, NOW())";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            ':etudiant_id' => $student['id'],
            ':type_presence' => $input['type_presence'],
            ':matiere_id' => $input['matiere_id'] ?? null,
            ':surveillant_id' => $_SESSION['user_id'],
            ':site_id' => $_SESSION['site_id']
        ]);
        
        $presenceId = $db->lastInsertId();
    }
    
    // Récupérer la présence créée
    $presenceQuery = "SELECT p.*, m.nom as matiere_nom 
                     FROM presences p 
                     LEFT JOIN matieres m ON p.matiere_id = m.id 
                     WHERE p.id = :id";
    
    $presenceStmt = $db->prepare($presenceQuery);
    $presenceStmt->execute([':id' => $presenceId]);
    $presence = $presenceStmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer le nom du surveillant
    $surveillantQuery = "SELECT nom, prenom FROM utilisateurs WHERE id = :id";
    $surveillantStmt = $db->prepare($surveillantQuery);
    $surveillantStmt->execute([':id' => $_SESSION['user_id']]);
    $surveillant = $surveillantStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'student' => [
            'id' => $student['id'],
            'matricule' => $student['matricule'],
            'nom' => $student['nom'],
            'prenom' => $student['prenom'],
            'classe' => $student['classe_nom']
        ],
        'presence' => [
            'id' => $presence['id'],
            'type' => $presence['type_presence'],
            'statut' => $presence['statut'],
            'date_heure' => $presence['date_heure'],
            'matiere' => $presence['matiere_nom']
        ],
        'surveillant' => $surveillant ? $surveillant['nom'] . ' ' . $surveillant['prenom'] : 'Inconnu'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>