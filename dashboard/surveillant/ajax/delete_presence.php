<?php
// dashboard/surveillant/ajax/delete_presence.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$db = Database::getInstance()->getConnection();
$presence_id = $_POST['id'] ?? 0;

try {
    // Vérifier que la présence appartient au site du surveillant
    $query = "SELECT p.id FROM presences p 
              JOIN etudiants e ON p.etudiant_id = e.id 
              WHERE p.id = :id AND e.site_id = :site_id";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id' => $presence_id,
        ':site_id' => $_SESSION['site_id']
    ]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Présence non trouvée ou accès non autorisé');
    }
    
    // Supprimer la présence
    $query = "DELETE FROM presences WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $presence_id]);
    
    // Journaliser l'action
    $query = "
        INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, table_concernée, id_enregistrement, details)
        VALUES (:user_id, 'admin', 'suppression_presence', 'presences', :presence_id, 'Suppression d\'une présence')
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':presence_id' => $presence_id
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Présence supprimée avec succès'
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>