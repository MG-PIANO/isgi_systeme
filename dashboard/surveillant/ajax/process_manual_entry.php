<?php
// dashboard/surveillant/ajax/process_manual_entry.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$db = Database::getInstance()->getConnection();
$surveillant_id = $_SESSION['user_id'];
$site_id = $_SESSION['site_id'];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $student_id = $data['student_id'] ?? 0;
    $type_presence = $data['type_presence'] ?? 'entree_ecole';
    $matiere_id = $data['matiere_id'] ?? null;
    
    // Récupérer l'étudiant
    $query = "
        SELECT e.*, c.nom as classe_nom 
        FROM etudiants e 
        LEFT JOIN classes c ON e.classe_id = c.id 
        WHERE e.id = :id 
        AND e.site_id = :site_id 
        AND e.statut = 'actif'
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id' => $student_id,
        ':site_id' => $site_id
    ]);
    
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant) {
        throw new Exception('Étudiant non trouvé ou inactif');
    }
    
    // Déterminer le statut
    $statut = 'present';
    $heure_actuelle = date('H:i');
    
    if ($type_presence == 'entree_classe' && $heure_actuelle > '08:30') {
        $statut = 'retard';
    }
    
    // Vérifier la duplication
    $today = date('Y-m-d');
    $query = "
        SELECT id FROM presences 
        WHERE etudiant_id = :etudiant_id 
        AND type_presence = :type_presence 
        AND DATE(date_heure) = :today
        LIMIT 1
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':etudiant_id' => $etudiant['id'],
        ':type_presence' => $type_presence,
        ':today' => $today
    ]);
    
    if ($stmt->fetch()) {
        throw new Exception('Présence déjà enregistrée aujourd\'hui');
    }
    
    // Enregistrer la présence
    $query = "
        INSERT INTO presences (
            etudiant_id,
            site_id,
            type_presence,
            date_heure,
            surveillant_id,
            matiere_id,
            statut,
            date_creation
        ) VALUES (
            :etudiant_id,
            :site_id,
            :type_presence,
            NOW(),
            :surveillant_id,
            :matiere_id,
            :statut,
            NOW()
        )
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':etudiant_id' => $etudiant['id'],
        ':site_id' => $site_id,
        ':type_presence' => $type_presence,
        ':surveillant_id' => $surveillant_id,
        ':matiere_id' => $matiere_id,
        ':statut' => $statut
    ]);
    
    $presence_id = $db->lastInsertId();
    
    // Récupérer la présence
    $query = "
        SELECT p.*, m.nom as matiere_nom 
        FROM presences p 
        LEFT JOIN matieres m ON p.matiere_id = m.id 
        WHERE p.id = :id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $presence_id]);
    $presence = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Journaliser
    $query = "
        INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, table_concernée, id_enregistrement, details)
        VALUES (:user_id, 'admin', 'manual_presence', 'presences', :presence_id, 
                CONCAT('Manuel: ', :matricule, ' - ', :type_presence))
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $surveillant_id,
        ':presence_id' => $presence_id,
        ':matricule' => $etudiant['matricule'],
        ':type_presence' => $type_presence
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Présence enregistrée manuellement',
        'student' => [
            'id' => $etudiant['id'],
            'matricule' => $etudiant['matricule'],
            'nom' => $etudiant['nom'],
            'prenom' => $etudiant['prenom'],
            'classe' => $etudiant['classe_nom']
        ],
        'presence' => $presence
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>