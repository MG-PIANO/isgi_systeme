<?php
// dashboard/surveillant/ajax/update_presence.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$db = Database::getInstance()->getConnection();
$surveillant_id = $_SESSION['user_id'];

try {
    // Récupérer les données
    $id = $_POST['id'] ?? 0;
    $etudiant_id = $_POST['etudiant_id'] ?? null;
    $type_presence = $_POST['type_presence'] ?? '';
    $statut = $_POST['statut'] ?? '';
    $date = $_POST['date'] ?? '';
    $heure = $_POST['heure'] ?? '';
    $matiere_id = $_POST['matiere_id'] ?? null;
    $salle = $_POST['salle'] ?? null;
    $motif_absence = $_POST['motif_absence'] ?? null;
    $observations = $_POST['observations'] ?? null;
    
    // Validation
    if (!$etudiant_id || !$type_presence || !$statut || !$date || !$heure) {
        throw new Exception('Tous les champs obligatoires doivent être remplis');
    }
    
    // Vérifier que l'étudiant appartient au même site
    $query = "SELECT site_id FROM etudiants WHERE id = :etudiant_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':etudiant_id' => $etudiant_id]);
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant || $etudiant['site_id'] != $_SESSION['site_id']) {
        throw new Exception('Étudiant non trouvé ou site incorrect');
    }
    
    // Combiner date et heure
    $date_heure = $date . ' ' . $heure . ':00';
    
    // Mettre à jour la présence
    $query = "
        UPDATE presences SET
            etudiant_id = :etudiant_id,
            type_presence = :type_presence,
            statut = :statut,
            date_heure = :date_heure,
            matiere_id = :matiere_id,
            salle = :salle,
            motif_absence = :motif_absence,
            observations = :observations,
            surveillant_id = :surveillant_id
        WHERE id = :id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id' => $id,
        ':etudiant_id' => $etudiant_id,
        ':type_presence' => $type_presence,
        ':statut' => $statut,
        ':date_heure' => $date_heure,
        ':matiere_id' => $matiere_id ?: null,
        ':salle' => $salle,
        ':motif_absence' => $motif_absence,
        ':observations' => $observations,
        ':surveillant_id' => $surveillant_id
    ]);
    
    // Notifier le parent si demandé
    if (isset($_POST['notifier_parent']) && in_array($statut, ['absent', 'retard'])) {
        // Récupérer les infos du parent
        $query = "SELECT telephone_parent, nom_tuteur, telephone_tuteur FROM etudiants WHERE id = :etudiant_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':etudiant_id' => $etudiant_id]);
        $etudiant_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // TODO: Implémenter l'envoi de notification SMS/Email
        // Pour l'instant, on log juste l'action
        error_log("Notification parent pour étudiant $etudiant_id - Statut: $statut");
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Présence mise à jour avec succès'
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>