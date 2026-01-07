<?php

require_once __DIR__ . '/../controllers/MobileMoneyController.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Middleware d'authentification
function authenticateToken($token) {
    // Implémenter la vérification du token
    // Vérifier dans la table demande_inscriptions ou utilisateurs
    return true; // Pour le moment
}

// Routes API
$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

$controller = new MobileMoneyController();

// Routes pour Mobile Money
if (strpos($requestUri, '/api/mobile-money/initiate') !== false && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $controller->initiatePayment($data);
    
} elseif (strpos($requestUri, '/api/mobile-money/callback') !== false && $method === 'POST') {
    $controller->callback($_REQUEST);
    
} elseif (preg_match('/\/api\/mobile-money\/status\/([a-zA-Z0-9\-]+)/', $requestUri, $matches)) {
    $transactionId = $matches[1];
    $controller->checkStatus($transactionId);
    
} elseif (strpos($requestUri, '/api/payment/info') !== false && $method === 'GET') {
    // Récupérer les informations du paiement
    $token = $_GET['token'] ?? '';
    
    if (authenticateToken($token)) {
        // Récupérer les informations depuis la base de données
        $info = [
            'success' => true,
            'student_name' => 'Nom Étudiant',
            'matricule' => 'MAT-123',
            'fee_type' => 'Frais de scolarité',
            'amount' => 25000,
            'transaction_fee' => 350,
            'total_amount' => 25350
        ];
        
        echo json_encode($info);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Token invalide']);
    }
    
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint non trouvé']);
}