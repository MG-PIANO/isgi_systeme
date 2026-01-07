<?php

require_once __DIR__ . '/../config/mtn_config.php';

class MTNMobileMoneyService {
    private $config;
    private $baseUrl;
    private $subscriptionKey;
    private $apiUser;
    private $apiKey;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/mtn_config.php';
        $mtnConfig = $this->config['mtn_mobile_money'];
        
        $this->baseUrl = $mtnConfig['api_base_url'];
        $this->subscriptionKey = $mtnConfig['subscription_key'];
        $this->apiUser = $mtnConfig['api_user'];
        $this->apiKey = $mtnConfig['api_key'];
        $this->callbackUrl = $mtnConfig['callback_url'];
    }
    
    /**
     * Initier un paiement MTN Mobile Money
     */
    public function requestPayment($data) {
        $transactionId = $this->generateTransactionId();
        
        $payload = [
            'amount' => $data['amount'],
            'currency' => $this->config['mtn_mobile_money']['currency'],
            'externalId' => $transactionId,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $this->formatPhoneNumber($data['phone_number'])
            ],
            'payerMessage' => 'Paiement frais scolaires ISGI - ' . $data['reference'],
            'payeeNote' => 'Référence: ' . $data['reference'],
            'callbackUrl' => $this->callbackUrl
        ];
        
        $response = $this->makeApiRequest(
            '/collection/v1_0/requesttopay',
            'POST',
            $payload
        );
        
        if ($response['status'] === 202) {
            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'external_transaction_id' => $response['headers']['X-Reference-Id'] ?? null,
                'status' => 'PENDING',
                'message' => 'Paiement initié avec succès',
                'data' => $response['data']
            ];
        }
        
        return [
            'success' => false,
            'error' => $response['data']['message'] ?? 'Erreur lors de l\'initiation du paiement',
            'details' => $response['data']
        ];
    }
    
    /**
     * Vérifier le statut d'un paiement
     */
    public function checkPaymentStatus($externalTransactionId) {
        $response = $this->makeApiRequest(
            "/collection/v1_0/requesttopay/{$externalTransactionId}",
            'GET'
        );
        
        if ($response['status'] === 200) {
            return [
                'success' => true,
                'status' => $response['data']['status'],
                'financialTransactionId' => $response['data']['financialTransactionId'] ?? null,
                'amount' => $response['data']['amount'] ?? null,
                'currency' => $response['data']['currency'] ?? null,
                'data' => $response['data']
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Impossible de vérifier le statut du paiement',
            'details' => $response['data']
        ];
    }
    
    /**
     * Générer un token d'accès
     */
    private function getAccessToken() {
        // Implémenter la logique d'obtention du token OAuth2
        // Cette méthode dépend de l'implémentation spécifique de l'API MTN
        
        $authUrl = $this->baseUrl . '/collection/token/';
        $credentials = base64_encode($this->apiUser . ':' . $this->apiKey);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $credentials,
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['access_token'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Effectuer une requête API
     */
    private function makeApiRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $token = $this->getAccessToken();
        
        if (!$token) {
            return ['status' => 401, 'data' => ['message' => 'Token d\'accès non disponible']];
        }
        
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json',
            'X-Target-Environment: ' . ($this->config['mtn_mobile_money']['environment'] === 'production' ? 'production' : 'sandbox')
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'data' => json_decode($response, true) ?: $response,
            'headers' => $this->parseHeaders($responseHeaders)
        ];
    }
    
    /**
     * Formater le numéro de téléphone
     */
    private function formatPhoneNumber($phone) {
        // Supprimer les espaces et caractères spéciaux
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Ajouter l'indicatif si manquant
        if (substr($phone, 0, 1) !== '+') {
            // Supposons que c'est un numéro congolais
            $phone = '+242' . ltrim($phone, '0');
        }
        
        return $phone;
    }
    
    /**
     * Générer un ID de transaction unique
     */
    private function generateTransactionId() {
        return 'MTN-' . date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Parser les headers de réponse
     */
    private function parseHeaders($headerString) {
        $headers = [];
        $lines = explode("\r\n", $headerString);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        return $headers;
    }
    
    /**
     * Calculer les frais de transaction
     */
    public function calculateFees($amount) {
        $percentage = $this->config['mtn_mobile_money']['fees_percentage'];
        return ($amount * $percentage) / 100;
    }
    
    /**
     * Valider un numéro de téléphone MTN
     */
    public function validateMTNNumber($phone) {
        $phone = $this->formatPhoneNumber($phone);
        $prefixes = $this->config['mtn_mobile_money']['prefixes'];
        
        foreach ($prefixes as $prefix) {
            if (strpos($phone, $prefix) === 0) {
                return true;
            }
        }
        
        return false;
    }
}