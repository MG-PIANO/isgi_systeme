<?php

class MobileMoneyTransaction {
    private $db;
    
    public function __construct() {
        $this->db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    public function createTransaction($data) {
        $sql = "INSERT INTO mobile_money_transactions (
            payment_id,
            student_id,
            transaction_id,
            external_transaction_id,
            amount,
            phone_number,
            operator,
            status,
            api_response,
            callback_data,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['payment_id'],
            $data['student_id'],
            $data['transaction_id'],
            $data['external_transaction_id'] ?? null,
            $data['amount'],
            $data['phone_number'],
            $data['operator'],
            $data['status'] ?? 'pending',
            json_encode($data['api_response'] ?? []),
            json_encode($data['callback_data'] ?? [])
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function updateTransaction($transactionId, $data) {
        $sql = "UPDATE mobile_money_transactions SET 
            external_transaction_id = ?,
            status = ?,
            api_response = ?,
            callback_data = ?,
            updated_at = NOW()
            WHERE transaction_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['external_transaction_id'] ?? null,
            $data['status'],
            json_encode($data['api_response'] ?? []),
            json_encode($data['callback_data'] ?? []),
            $transactionId
        ]);
    }
    
    public function findByTransactionId($transactionId) {
        $sql = "SELECT * FROM mobile_money_transactions WHERE transaction_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function findByExternalId($externalId) {
        $sql = "SELECT * FROM mobile_money_transactions WHERE external_transaction_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$externalId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}