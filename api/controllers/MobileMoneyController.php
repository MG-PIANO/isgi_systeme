<?php

require_once __DIR__ . '/../services/MTNMobileMoneyService.php';
require_once __DIR__ . '/../models/MobileMoneyTransaction.php';

class MobileMoneyController {
    private $mtnService;
    private $transactionModel;
    private $db;
    
    public function __construct() {
        $this->mtnService = new MTNMobileMoneyService();
        $this->transactionModel = new MobileMoneyTransaction();
        $this->db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    /**
     * Initier un paiement Mobile Money
     */
    public function initiatePayment($request) {
        try {
            // Validation des données
            $required = ['student_id', 'amount', 'phone_number', 'type_frais_id', 'reference'];
            foreach ($required as $field) {
                if (!isset($request[$field]) || empty($request[$field])) {
                    return $this->jsonResponse(['error' => "Le champ $field est requis"], 400);
                }
            }
            
            // Vérifier si le numéro est MTN
            if (!$this->mtnService->validateMTNNumber($request['phone_number'])) {
                return $this->jsonResponse([
                    'error' => 'Numéro de téléphone invalide. Veuillez utiliser un numéro MTN Congo.'
                ], 400);
            }
            
            // Calculer les frais
            $fees = $this->mtnService->calculateFees($request['amount']);
            $totalAmount = $request['amount'] + $fees;
            
            // Créer l'enregistrement de paiement dans la base
            $paymentId = $this->createPaymentRecord([
                'etudiant_id' => $request['student_id'],
                'type_frais_id' => $request['type_frais_id'],
                'montant' => $request['amount'],
                'frais_transaction' => $fees,
                'mode_paiement' => 'MTN Mobile Money',
                'numero_telephone' => $request['phone_number'],
                'operateur_mobile' => 'MTN',
                'reference' => $request['reference'],
                'statut' => 'en_attente'
            ]);
            
            if (!$paymentId) {
                return $this->jsonResponse(['error' => 'Erreur lors de la création du paiement'], 500);
            }
            
            // Initier le paiement via MTN
            $paymentData = [
                'amount' => $totalAmount,
                'phone_number' => $request['phone_number'],
                'reference' => $request['reference']
            ];
            
            $mtnResponse = $this->mtnService->requestPayment($paymentData);
            
            if ($mtnResponse['success']) {
                // Enregistrer la transaction
                $this->transactionModel->createTransaction([
                    'payment_id' => $paymentId,
                    'student_id' => $request['student_id'],
                    'transaction_id' => $mtnResponse['transaction_id'],
                    'external_transaction_id' => $mtnResponse['external_transaction_id'],
                    'amount' => $totalAmount,
                    'phone_number' => $request['phone_number'],
                    'operator' => 'MTN',
                    'status' => $mtnResponse['status'],
                    'api_response' => $mtnResponse
                ]);
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Paiement initié avec succès',
                    'payment_id' => $paymentId,
                    'transaction_id' => $mtnResponse['transaction_id'],
                    'amount' => $request['amount'],
                    'fees' => $fees,
                    'total_amount' => $totalAmount,
                    'status' => 'PENDING',
                    'payment_url' => $this->generatePaymentUrl($mtnResponse['transaction_id']),
                    'qr_code_url' => $this->generateQRCode($mtnResponse['transaction_id'], $totalAmount)
                ], 201);
            } else {
                // Mettre à jour le statut du paiement en échec
                $this->updatePaymentStatus($paymentId, 'annule', $mtnResponse['error']);
                
                return $this->jsonResponse([
                    'error' => $mtnResponse['error'],
                    'details' => $mtnResponse['details']
                ], 400);
            }
            
        } catch (Exception $e) {
            error_log('Erreur initiation paiement: ' . $e->getMessage());
            return $this->jsonResponse(['error' => 'Erreur interne du serveur'], 500);
        }
    }
    
    /**
     * Callback pour les notifications MTN
     */
    public function callback($request) {
        try {
            // Vérifier la signature de la requête (si applicable)
            // MTN envoie généralement les données dans le corps de la requête
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data || !isset($data['externalId'])) {
                return $this->jsonResponse(['error' => 'Données invalides'], 400);
            }
            
            // Trouver la transaction
            $transaction = $this->transactionModel->findByExternalId($data['externalId']);
            
            if (!$transaction) {
                return $this->jsonResponse(['error' => 'Transaction non trouvée'], 404);
            }
            
            // Mettre à jour le statut de la transaction
            $newStatus = $this->mapMTNStatus($data['status'] ?? 'FAILED');
            
            $this->transactionModel->updateTransaction($transaction['transaction_id'], [
                'status' => $newStatus,
                'callback_data' => $data
            ]);
            
            // Mettre à jour le paiement principal
            if ($newStatus === 'SUCCESSFUL') {
                $this->updatePaymentStatus($transaction['payment_id'], 'valide', 'Paiement confirmé via MTN Mobile Money');
                
                // Envoyer un email de confirmation
                $this->sendPaymentConfirmation($transaction['student_id'], $transaction['payment_id']);
                
                // Générer un reçu
                $this->generateReceipt($transaction['payment_id']);
            } elseif ($newStatus === 'FAILED') {
                $this->updatePaymentStatus($transaction['payment_id'], 'annule', 'Paiement échoué via MTN Mobile Money');
            }
            
            return $this->jsonResponse(['success' => true, 'message' => 'Callback traité'], 200);
            
        } catch (Exception $e) {
            error_log('Erreur callback MTN: ' . $e->getMessage());
            return $this->jsonResponse(['error' => 'Erreur interne'], 500);
        }
    }
    
    /**
     * Vérifier le statut d'un paiement
     */
    public function checkStatus($transactionId) {
        try {
            $transaction = $this->transactionModel->findByTransactionId($transactionId);
            
            if (!$transaction) {
                return $this->jsonResponse(['error' => 'Transaction non trouvée'], 404);
            }
            
            // Si la transaction a un ID externe, vérifier auprès de MTN
            if ($transaction['external_transaction_id'] && $transaction['status'] === 'PENDING') {
                $statusResponse = $this->mtnService->checkPaymentStatus($transaction['external_transaction_id']);
                
                if ($statusResponse['success']) {
                    $newStatus = $this->mapMTNStatus($statusResponse['status']);
                    
                    // Mettre à jour la transaction
                    $this->transactionModel->updateTransaction($transactionId, [
                        'status' => $newStatus,
                        'api_response' => $statusResponse
                    ]);
                    
                    // Mettre à jour le paiement si nécessaire
                    if ($newStatus === 'SUCCESSFUL') {
                        $this->updatePaymentStatus($transaction['payment_id'], 'valide', 'Paiement confirmé');
                    }
                    
                    $transaction['status'] = $newStatus;
                }
            }
            
            return $this->jsonResponse([
                'transaction_id' => $transaction['transaction_id'],
                'external_id' => $transaction['external_transaction_id'],
                'status' => $transaction['status'],
                'amount' => $transaction['amount'],
                'payment_id' => $transaction['payment_id'],
                'last_updated' => $transaction['updated_at']
            ], 200);
            
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => 'Erreur de vérification'], 500);
        }
    }
    
    /**
     * Remboursement (si nécessaire)
     */
    public function refund($request) {
        // Implémenter la logique de remboursement MTN
        // Cette fonction nécessite des autorisations spéciales
    }
    
    /**
     * Créer un enregistrement de paiement
     */
    private function createPaymentRecord($data) {
        try {
            $sql = "INSERT INTO paiements (
                etudiant_id,
                type_frais_id,
                annee_academique_id,
                reference,
                montant,
                frais_transaction,
                mode_paiement,
                numero_telephone,
                operateur_mobile,
                date_paiement,
                statut,
                date_creation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, NOW())";
            
            // Obtenir l'année académique active
            $anneeAcademiqueId = $this->getActiveAcademicYear();
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['etudiant_id'],
                $data['type_frais_id'],
                $anneeAcademiqueId,
                $data['reference'],
                $data['montant'],
                $data['frais_transaction'],
                $data['mode_paiement'],
                $data['numero_telephone'],
                $data['operateur_mobile'],
                $data['statut']
            ]);
            
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('Erreur création paiement: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mettre à jour le statut d'un paiement
     */
    private function updatePaymentStatus($paymentId, $status, $comment = '') {
        try {
            $sql = "UPDATE paiements SET 
                statut = ?,
                commentaires = CONCAT(COALESCE(commentaires, ''), ?),
                date_modification = NOW()
                WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $status,
                ' | ' . date('Y-m-d H:i:s') . ': ' . $comment,
                $paymentId
            ]);
        } catch (Exception $e) {
            error_log('Erreur mise à jour paiement: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtenir l'année académique active
     */
    private function getActiveAcademicYear() {
        $sql = "SELECT id FROM annees_academiques WHERE statut = 'active' LIMIT 1";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : 1; // Par défaut
    }
    
    /**
     * Mapper les statuts MTN vers nos statuts
     */
    private function mapMTNStatus($mtnStatus) {
        $statusMap = [
            'PENDING' => 'PENDING',
            'SUCCESSFUL' => 'SUCCESSFUL',
            'FAILED' => 'FAILED',
            'CANCELLED' => 'CANCELLED',
            'EXPIRED' => 'EXPIRED'
        ];
        
        return $statusMap[strtoupper($mtnStatus)] ?? 'UNKNOWN';
    }
    
    /**
     * Générer l'URL de paiement
     */
    private function generatePaymentUrl($transactionId) {
        $baseUrl = 'https://votre-domaine.com/payment';
        return $baseUrl . '/status/' . $transactionId;
    }
    
    /**
     * Générer un QR Code pour le paiement
     */
    private function generateQRCode($transactionId, $amount) {
        // Utiliser une librairie QR Code
        $paymentData = [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'merchant' => 'ISGI Congo',
            'currency' => 'XAF'
        ];
        
        $dataString = json_encode($paymentData);
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($dataString);
        
        return $qrCodeUrl;
    }
    
    /**
     * Envoyer une confirmation par email
     */
    private function sendPaymentConfirmation($studentId, $paymentId) {
        // Récupérer les informations de l'étudiant
        $sql = "SELECT e.*, u.email FROM etudiants e 
                LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id 
                WHERE e.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student && $student['email']) {
            // Récupérer les détails du paiement
            $paymentSql = "SELECT * FROM paiements WHERE id = ?";
            $paymentStmt = $this->db->prepare($paymentSql);
            $paymentStmt->execute([$paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            
            // Envoyer l'email
            $to = $student['email'];
            $subject = 'Confirmation de paiement - ISGI Congo';
            $message = $this->buildPaymentConfirmationEmail($student, $payment);
            $headers = 'From: noreply@isgi.cg' . "\r\n" .
                      'Content-Type: text/html; charset=UTF-8';
            
            mail($to, $subject, $message, $headers);
        }
    }
    
    /**
     * Construire l'email de confirmation
     */
    private function buildPaymentConfirmationEmail($student, $payment) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Confirmation de paiement</title>
        </head>
        <body>
            <h2>Confirmation de Paiement - ISGI Congo</h2>
            <p>Bonjour {$student['prenom']} {$student['nom']},</p>
            <p>Votre paiement a été confirmé avec succès.</p>
            
            <h3>Détails du paiement :</h3>
            <ul>
                <li><strong>Référence :</strong> {$payment['reference']}</li>
                <li><strong>Montant :</strong> " . number_format($payment['montant'], 0, ',', ' ') . " FCFA</li>
                <li><strong>Frais de transaction :</strong> " . number_format($payment['frais_transaction'], 0, ',', ' ') . " FCFA</li>
                <li><strong>Total payé :</strong> " . number_format($payment['montant'] + $payment['frais_transaction'], 0, ',', ' ') . " FCFA</li>
                <li><strong>Mode de paiement :</strong> {$payment['mode_paiement']}</li>
                <li><strong>Date :</strong> {$payment['date_paiement']}</li>
            </ul>
            
            <p>Votre reçu est disponible dans votre espace étudiant.</p>
            
            <p>Cordialement,<br>L'équipe ISGI Congo</p>
        </body>
        </html>
        ";
    }
    
    /**
     * Générer un reçu
     */
    private function generateReceipt($paymentId) {
        // Implémenter la génération de reçu PDF
        // Utiliser une librairie comme TCPDF ou Dompdf
    }
    
    /**
     * Réponse JSON
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}