<?php
// dashboard/gestionnaire/get_dette_details.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4)
if (!isset($_SESSION['role_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Accès interdit']);
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    echo json_encode(['error' => 'Configuration base de données introuvable']);
    exit();
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Récupérer l'ID de la dette
    $dette_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($dette_id <= 0) {
        echo json_encode(['error' => 'ID de dette invalide']);
        exit();
    }
    
    // Récupérer les détails de la dette
    $query = "SELECT d.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
              CONCAT(e.prenom, ' ', e.nom) as nom_complet,
              aa.libelle as annee_academique,
              s.nom as site_nom,
              CONCAT(u.nom, ' ', u.prenom) as gestionnaire_nom
              FROM dettes d
              INNER JOIN etudiants e ON d.etudiant_id = e.id
              INNER JOIN annees_academiques aa ON d.annee_academique_id = aa.id
              INNER JOIN sites s ON e.site_id = s.id
              LEFT JOIN utilisateurs u ON d.gestionnaire_id = u.id
              WHERE d.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$dette_id]);
    $dette = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dette) {
        echo json_encode(['error' => 'Dette non trouvée']);
        exit();
    }
    
    // Récupérer les paiements associés
    $query_paiements = "SELECT p.*, tf.nom as type_frais 
                       FROM paiements p
                       INNER JOIN types_frais tf ON p.type_frais_id = tf.id
                       WHERE p.etudiant_id = ? AND p.annee_academique_id = ?
                       AND p.statut = 'valide'
                       ORDER BY p.date_paiement DESC";
    
    $stmt = $db->prepare($query_paiements);
    $stmt->execute([$dette['etudiant_id'], $dette['annee_academique_id']]);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer l'historique des modifications
    $query_historique = "SELECT hm.*, 
                        CONCAT(u.nom, ' ', u.prenom) as utilisateur_nom
                        FROM historique_modifications_dettes hm
                        LEFT JOIN utilisateurs u ON hm.utilisateur_id = u.id
                        WHERE hm.dette_id = ?
                        ORDER BY hm.date_modification DESC";
    
    $stmt = $db->prepare($query_historique);
    $stmt->execute([$dette_id]);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les échéances si plan de paiement
    $query_echeances = "SELECT * FROM echeances_dettes 
                       WHERE dette_id = ? 
                       ORDER BY date_echeance ASC";
    
    $stmt = $db->prepare($query_echeances);
    $stmt->execute([$dette_id]);
    $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Préparer la réponse
    $response = [
        'success' => true,
        'dette' => $dette,
        'paiements' => $paiements,
        'historique' => $historique,
        'echeances' => $echeances,
        'total_paiements' => count($paiements)
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
}
?>