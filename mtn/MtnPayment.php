<?php
require_once __DIR__ . '/MtnApiClient.php';

class MtnPayment {
    private $apiClient;
    private $db;
    
    public function __construct($db) {
        $this->apiClient = new MtnApiClient();
        $this->db = $db;
    }
    
    /**
     * Initier un paiement
     */
    public function initiatePayment($params) {
        $required = ['amount', 'phone', 'matricule', 'etudiant_id', 'reference'];
        
        foreach ($required as $field) {
            if (empty($params[$field])) {
                throw new Exception("Le champ $field est requis");
            }
        }
        
        // Vérifier si un paiement est déjà en cours
        $existing = $this->getPendingPayment($params['etudiant_id']);
        if ($existing) {
            return [
                'status' => 'pending',
                'transaction_id' => $existing['transaction_id']
            ];
        }
        
        // Préparer les données pour l'API MTN
        $paymentData = [
            'amount' => $params['amount'],
            'currency' => MTN_CURRENCY,
            'externalId' => $params['reference'],
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $this->formatPhoneNumber($params['phone'])
            ],
            'payerMessage' => MTN_PAYER_MESSAGE,
            'payeeNote' => MTN_PAYEE_NOTE
        ];
        
        // Appeler l'API MTN
        $response = $this->apiClient->requestToPay($paymentData);
        
        if ($response['status'] === 'success') {
            // Enregistrer dans la base de données
            $this->savePaymentRequest([
                'transaction_id' => $response['transactionId'],
                'etudiant_id' => $params['etudiant_id'],
                'matricule' => $params['matricule'],
                'montant' => $params['amount'],
                'telephone' => $params['phone'],
                'reference' => $params['reference'],
                'statut' => 'PENDING',
                'api_response' => json_encode($response)
            ]);
            
            return [
                'status' => 'initiated',
                'transaction_id' => $response['transactionId'],
                'message' => 'Paiement initié avec succès'
            ];
        }
        
        throw new Exception("Échec de l'initiation du paiement: " . $response['message']);
    }
    
    /**
     * Vérifier le statut d'un paiement
     */
    public function checkPaymentStatus($transactionId) {
        return $this->apiClient->getPaymentStatus($transactionId);
    }
    
    /**
     * Formater le numéro de téléphone
     */
    private function formatPhoneNumber($phone) {
        // Nettoyer le numéro
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Ajouter l'indicatif Cameroun si absent
        if (substr($phone, 0, 3) !== '237' && strlen($phone) === 9) {
            $phone = '237' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Enregistrer la demande de paiement
     */
    private function savePaymentRequest($data) {
        $sql = "INSERT INTO paiements_mtn (
            transaction_id, etudiant_id, matricule, montant, telephone,
            reference, statut, api_response, date_creation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['transaction_id'],
            $data['etudiant_id'],
            $data['matricule'],
            $data['montant'],
            $data['telephone'],
            $data['reference'],
            $data['statut'],
            $data['api_response']
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Récupérer un paiement en attente
     */
    private function getPendingPayment($etudiantId) {
        $sql = "SELECT * FROM paiements_mtn 
                WHERE etudiant_id = ? 
                AND statut IN ('PENDING', 'INITIATED') 
                ORDER BY id DESC LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$etudiantId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Mettre à jour le statut d'un paiement
     */
    public function updatePaymentStatus($transactionId, $status, $additionalData = null) {
        $sql = "UPDATE paiements_mtn 
                SET statut = ?, date_maj = NOW()";
        
        $params = [$status];
        
        if ($additionalData) {
            $sql .= ", api_response = CONCAT(IFNULL(api_response, ''), ?)";
            $params[] = json_encode($additionalData);
        }
        
        $sql .= " WHERE transaction_id = ?";
        $params[] = $transactionId;
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
?>