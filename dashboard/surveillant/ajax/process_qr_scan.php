<?php
// dashboard/surveillant/ajax/process_qr_scan.php
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
    
    $qr_data = $data['qr_data'] ?? '';
    $matricule = $data['matricule'] ?? '';
    $type_presence = $data['type_presence'] ?? 'entree_ecole';
    $matiere_id = $data['matiere_id'] ?? null;
    
    // Valider les données
    if (empty($matricule)) {
        throw new Exception('QR Code invalide: matricule manquant');
    }
    
    // Récupérer l'étudiant
    $query = "
        SELECT e.*, c.nom as classe_nom 
        FROM etudiants e 
        LEFT JOIN classes c ON e.classe_id = c.id 
        WHERE e.matricule = :matricule 
        AND e.site_id = :site_id 
        AND e.statut = 'actif'
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':matricule' => $matricule,
        ':site_id' => $site_id
    ]);
    
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant) {
        throw new Exception('Étudiant non trouvé ou inactif');
    }
    
    // Déterminer le statut
    $statut = 'present';
    $heure_actuelle = date('H:i');
    
    // Si c'est une entrée en classe après 8h, c'est un retard
    if ($type_presence == 'entree_classe' && $heure_actuelle > '08:30') {
        $statut = 'retard';
    }
    
    // Vérifier si une présence similaire existe déjà aujourd'hui
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
            qr_code_scanne,
            surveillant_id,
            matiere_id,
            statut,
            ip_address,
            date_creation
        ) VALUES (
            :etudiant_id,
            :site_id,
            :type_presence,
            NOW(),
            :qr_code,
            :surveillant_id,
            :matiere_id,
            :statut,
            :ip_address,
            NOW()
        )
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':etudiant_id' => $etudiant['id'],
        ':site_id' => $site_id,
        ':type_presence' => $type_presence,
        ':qr_code' => $qr_data,
        ':surveillant_id' => $surveillant_id,
        ':matiere_id' => $matiere_id,
        ':statut' => $statut,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $presence_id = $db->lastInsertId();
    
    // Récupérer les infos de la présence créée
    $query = "
        SELECT p.*, m.nom as matiere_nom 
        FROM presences p 
        LEFT JOIN matieres m ON p.matiere_id = m.id 
        WHERE p.id = :id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $presence_id]);
    $presence = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Journaliser l'action
    $query = "
        INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, table_concernée, id_enregistrement, details)
        VALUES (:user_id, 'admin', 'scan_qr_presence', 'presences', :presence_id, 
                CONCAT('Scan QR: ', :matricule, ' - ', :type_presence))
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $surveillant_id,
        ':presence_id' => $presence_id,
        ':matricule' => $matricule,
        ':type_presence' => $type_presence
    ]);
    
    // Réponse JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Présence enregistrée avec succès',
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