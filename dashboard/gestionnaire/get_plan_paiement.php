<?php
// dashboard/gestionnaire/get_plan_paiement.php

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
    $dette_id = isset($_GET['dette_id']) ? intval($_GET['dette_id']) : 0;
    
    if ($dette_id <= 0) {
        echo json_encode(['error' => 'ID de dette invalide']);
        exit();
    }
    
    // Récupérer le plan de paiement actif
    $query_plan = "SELECT * FROM plans_paiement_dettes 
                   WHERE dette_id = ? AND statut = 'actif'
                   ORDER BY date_creation DESC LIMIT 1";
    
    $stmt = $db->prepare($query_plan);
    $stmt->execute([$dette_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les échéances
    $query_echeances = "SELECT * FROM echeances_dettes 
                       WHERE dette_id = ? 
                       ORDER BY numero_tranche ASC";
    
    $stmt = $db->prepare($query_echeances);
    $stmt->execute([$dette_id]);
    $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Préparer la réponse
    $response = [
        'success' => true,
        'plan' => $plan,
        'echeances' => $echeances
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
}
?>