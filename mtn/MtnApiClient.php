<?php
require_once __DIR__ . '/../../config/mtn_config.php';

class MtnApiClient {
    private $apiKey;
    private $apiUser;
    private $subscriptionKey;
    private $baseUrl;
    
    public function __construct() {
        $this->apiKey = MTN_API_KEY;
        $this->apiUser = MTN_API_USER;
        $this->subscriptionKey = MTN_SUBSCRIPTION_KEY;
        $this->baseUrl = MTN_API_BASE_URL;
    }
    
    /**
     * Initier un paiement
     */
    public function requestToPay($paymentData) {
        $endpoint = $this->baseUrl . '/collection/v1_0/requesttopay';
        
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'X-Reference-Id: ' . uniqid(),
            'X-Target-Environment: sandbox', // 'production' en prod
            'Content-Type: application/json',
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey
        ];
        
        $response = $this->makeRequest($endpoint, 'POST', $headers, json_encode($paymentData));
        
        if (isset($response['http_code']) && $response['http_code'] == 202) {
            return [
                'status' => 'success',
                'transactionId' => $headers[1], // X-Reference-Id
                'message' => 'Paiement initié'
            ];
        }
        
        return [
            'status' => 'error',
            'message' => $response['error'] ?? 'Erreur inconnue'
        ];
    }
    
    /**
     * Vérifier le statut d'un paiement
     */
    public function getPaymentStatus($transactionId) {
        $endpoint = $this->baseUrl . "/collection/v1_0/requesttopay/{$transactionId}";
        
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'X-Target-Environment: sandbox',
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey
        ];
        
        return $this->makeRequest($endpoint, 'GET', $headers);
    }
    
    /**
     * Obtenir un token d'accès
     */
    private function getAccessToken() {
        // Vérifier si un token valide existe en session/cache
        if (isset($_SESSION['mtn_access_token']) && 
            isset($_SESSION['mtn_token_expiry']) && 
            $_SESSION['mtn_token_expiry'] > time()) {
            return $_SESSION['mtn_access_token'];
        }
        
        $endpoint = $this->baseUrl . '/collection/token/';
        $credentials = base64_encode($this->apiUser . ':' . $this->apiKey);
        
        $headers = [
            'Authorization: Basic ' . $credentials,
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey
        ];
        
        $response = $this->makeRequest($endpoint, 'POST', $headers);
        
        if (isset($response['access_token'])) {
            $_SESSION['mtn_access_token'] = $response['access_token'];
            $_SESSION['mtn_token_expiry'] = time() + 3500; // 1h - 100s
            return $response['access_token'];
        }
        
        throw new Exception('Impossible d\'obtenir le token d\'accès');
    }
    
    /**
     * Effectuer une requête HTTP
     */
    private function makeRequest($url, $method = 'GET', $headers = [], $data = null) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("MTN API Error: $error");
            return ['error' => $error];
        }
        
        $decoded = json_decode($response, true);
        
        return array_merge($decoded ?: [], ['http_code' => $httpCode]);
    }
}
?>