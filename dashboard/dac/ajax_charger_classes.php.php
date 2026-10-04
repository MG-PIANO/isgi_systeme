<?php
session_start();
require_once '../../config/database.php';

$filiere_id = $_GET['filiere_id'] ?? null;
$site_id = $_GET['site_id'] ?? $_SESSION['site_id'] ?? null;

if (!$filiere_id || !$site_id) {
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle,
              aa.libelle as annee_libelle
              FROM classes c
              JOIN filieres f ON c.filiere_id = f.id
              JOIN niveaux n ON c.niveau_id = n.id
              JOIN annees_academiques aa ON c.annee_academique_id = aa.id
              WHERE c.filiere_id = :filiere_id AND c.site_id = :site_id
              ORDER BY f.nom, n.ordre";
    
    $stmt = $db->prepare($query);
    $stmt->execute(['filiere_id' => $filiere_id, 'site_id' => $site_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'classes' => $classes]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>