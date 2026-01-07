<?php
// api/mobile-payment.php

define('ROOT_PATH', dirname(dirname(__FILE__)));
require_once ROOT_PATH . '/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $db = Database::getInstance()->getConnection();
    
    $action = $_GET['action'] ?? '';
    
    // API pour initier un paiement
    if ($action == 'initiate' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Valider les données
        $required = ['token', 'demande_id', 'phone_number', 'operator'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Le champ $field est requis"]);
                exit;
            }
        }
        
        // Vérifier le token
        $check_token = $db->prepare("
            SELECT d.*, p.id as payment_id, p.montant, p.frais_transaction 
            FROM demande_inscriptions d 
            LEFT JOIN paiements p ON p.reference LIKE CONCAT('%', d.numero_demande, '%')
            WHERE d.token_paiement = ? 
            AND d.id = ? 
            AND d.date_expiration_token > NOW()
        ");
        
        $check_token->execute([$data['token'], $data['demande_id']]);
        $demande = $check_token->fetch(PDO::FETCH_ASSOC);
        
        if (!$demande) {
            http_response_code(401);
            echo json_encode(['error' => 'Token invalide ou expiré']);
            exit;
        }
        
        // Calculer le total
        $total_amount = $demande['montant'] + $demande['frais_transaction'];
        
        // Générer un ID de transaction
        $transaction_id = 'MTN-' . date('YmdHis') . '-' . uniqid();
        
        // Enregistrer la transaction
        $insert_transaction = "INSERT INTO mobile_money_transactions (
            payment_id, student_id, transaction_id, amount, phone_number,
            operator, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NOW())";
        
        $stmt = $db->prepare($insert_transaction);
        $stmt->execute([
            $demande['payment_id'],
            null, // student_id (pas encore créé)
            $transaction_id,
            $total_amount,
            $data['phone_number'],
            $data['operator']
        ]);
        
        // Simuler un appel à l'API MTN (à remplacer par le vrai appel)
        $external_id = 'EXT-' . time() . '-' . rand(1000, 9999);
        
        // En réponse, on simule le succès
        echo json_encode([
            'success' => true,
            'transaction_id' => $transaction_id,
            'external_id' => $external_id,
            'amount' => $total_amount,
            'message' => 'Paiement initié. Veuillez confirmer sur votre téléphone.'
        ]);
        
    } 
    // API pour vérifier le statut
    elseif ($action == 'status' && isset($_GET['transaction_id'])) {
        $transaction_id = $_GET['transaction_id'];
        
        $check_status = $db->prepare("
            SELECT * FROM mobile_money_transactions 
            WHERE transaction_id = ?
        ");
        $check_status->execute([$transaction_id]);
        $transaction = $check_status->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction non trouvée']);
            exit;
        }
        
        // Simuler la vérification (à remplacer par appel API MTN)
        $status = $transaction['status'];
        
        // Pour la démo, on simule un succès après 30 secondes
        $created_time = strtotime($transaction['created_at']);
        $current_time = time();
        
        if ($status == 'PENDING' && ($current_time - $created_time) > 30) {
            $status = 'SUCCESSFUL';
            
            // Mettre à jour la transaction
            $update = $db->prepare("
                UPDATE mobile_money_transactions 
                SET status = 'SUCCESSFUL', updated_at = NOW() 
                WHERE transaction_id = ?
            ");
            $update->execute([$transaction_id]);
            
            // Mettre à jour le paiement
            $update_payment = $db->prepare("
                UPDATE paiements 
                SET statut = 'valide', date_validation = NOW() 
                WHERE id = ?
            ");
            $update_payment->execute([$transaction['payment_id']]);
        }
        
        echo json_encode([
            'transaction_id' => $transaction_id,
            'status' => $status,
            'amount' => $transaction['amount'],
            'phone_number' => $transaction['phone_number']
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}