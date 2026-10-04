<?php
session_start();
require_once '../../config/database.php';

$classe_id = $_GET['classe_id'] ?? null;
$site_id = $_GET['site_id'] ?? $_SESSION['site_id'] ?? null;

if (!$classe_id || !$site_id) {
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Récupérer la filière de la classe
    $query = "SELECT c.filiere_id, c.niveau_id 
              FROM classes c 
              WHERE c.id = :classe_id AND c.site_id = :site_id";
    $stmt = $db->prepare($query);
    $stmt->execute(['classe_id' => $classe_id, 'site_id' => $site_id]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$classe) {
        echo json_encode(['success' => false, 'message' => 'Classe non trouvée']);
        exit;
    }
    
    // Récupérer les matières de la filière et du niveau
    $query = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
              FROM matieres m 
              JOIN filieres f ON m.filiere_id = f.id
              JOIN niveaux n ON m.niveau_id = n.id
              WHERE m.filiere_id = :filiere_id 
              AND m.niveau_id = :niveau_id
              AND m.site_id = :site_id
              ORDER BY m.code";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        'filiere_id' => $classe['filiere_id'],
        'niveau_id' => $classe['niveau_id'],
        'site_id' => $site_id
    ]);
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'matieres' => $matieres]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>