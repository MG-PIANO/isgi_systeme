<?php
// dashboard/surveillant/ajax/mark_attendance.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));

// Activer l'affichage des erreurs (en développement)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Mettre à 1 en développement

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit();
}

// Inclure la configuration
require_once ROOT_PATH . '/config/database.php';

try {
    // Initialiser la connexion
    $db = Database::getInstance()->getConnection();
    
    // Récupérer les données
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : 'present';
    $type = isset($_POST['type']) ? $_POST['type'] : 'entree_ecole';
    $matiere_id = isset($_POST['matiere_id']) ? intval($_POST['matiere_id']) : null;
    $salle = isset($_POST['salle']) ? $_POST['salle'] : null;
    
    // Validation
    if ($student_id <= 0) {
        throw new Exception('ID étudiant invalide');
    }
    
    if (!in_array($status, ['present', 'absent', 'retard', 'justifie'])) {
        $status = 'present';
    }
    
    if (!in_array($type, ['entree_ecole', 'sortie_ecole', 'entree_classe', 'sortie_classe'])) {
        $type = 'entree_ecole';
    }
    
    // Vérifier si l'étudiant existe
    $query = "SELECT id, matricule, nom, prenom, site_id 
              FROM etudiants 
              WHERE id = :id AND statut = 'actif'";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $student_id]);
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant) {
        throw new Exception('Étudiant non trouvé ou inactif');
    }
    
    // Vérifier si une présence existe déjà pour aujourd'hui (entrée école)
    $date_aujourdhui = date('Y-m-d');
    if ($type === 'entree_ecole') {
        $query_check = "SELECT id FROM presences 
                       WHERE etudiant_id = :etudiant_id 
                       AND DATE(date_heure) = :date_aujourdhui 
                       AND type_presence = 'entree_ecole'";
        
        $stmt_check = $db->prepare($query_check);
        $stmt_check->execute([
            ':etudiant_id' => $student_id,
            ':date_aujourdhui' => $date_aujourdhui
        ]);
        
        if ($stmt_check->fetch()) {
            echo json_encode([
                'success' => false, 
                'message' => 'L\'étudiant a déjà été enregistré aujourd\'hui'
            ]);
            exit();
        }
    }
    
    // Enregistrer la présence
    $query_insert = "INSERT INTO presences 
                    (etudiant_id, site_id, type_presence, date_heure, 
                     surveillant_id, statut, matiere_id, salle, 
                     date_creation) 
                    VALUES 
                    (:etudiant_id, :site_id, :type_presence, NOW(), 
                     :surveillant_id, :statut, :matiere_id, :salle, 
                     NOW())";
    
    $stmt_insert = $db->prepare($query_insert);
    $result = $stmt_insert->execute([
        ':etudiant_id' => $student_id,
        ':site_id' => $_SESSION['site_id'],
        ':type_presence' => $type,
        ':surveillant_id' => $_SESSION['user_id'],
        ':statut' => $status,
        ':matiere_id' => $matiere_id,
        ':salle' => $salle
    ]);
    
    if ($result) {
        $presence_id = $db->lastInsertId();
        
        // Enregistrer dans les logs
        $query_log = "INSERT INTO logs_activite 
                     (utilisateur_id, utilisateur_type, action, table_concernée, 
                      id_enregistrement, details, date_action) 
                     VALUES 
                     (:user_id, 'admin', 'presence_enregistree', 'presences', 
                      :presence_id, :details, NOW())";
        
        $stmt_log = $db->prepare($query_log);
        $stmt_log->execute([
            ':user_id' => $_SESSION['user_id'],
            ':presence_id' => $presence_id,
            ':details' => "Présence enregistrée: {$etudiant['matricule']} - {$status}"
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => "Présence enregistrée avec succès pour {$etudiant['prenom']} {$etudiant['nom']}",
            'data' => [
                'presence_id' => $presence_id,
                'student_name' => $etudiant['prenom'] . ' ' . $etudiant['nom'],
                'student_matricule' => $etudiant['matricule'],
                'status' => $status,
                'type' => $type,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        throw new Exception('Erreur lors de l\'enregistrement');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur: ' . $e->getMessage()
    ]);
}