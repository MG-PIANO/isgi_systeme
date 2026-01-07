<?php
// dashboard/admin_principal/traitement_modification.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    $db = Database::getInstance()->getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $enseignant_id = intval($_POST['enseignant_id']);
        
        // Récupérer l'utilisateur_id de l'enseignant
        $query = "SELECT utilisateur_id FROM enseignants WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$enseignant_id]);
        $enseignant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$enseignant) {
            header('Location: enseignants.php?error=Enseignant non trouvé');
            exit();
        }
        
        $utilisateur_id = $enseignant['utilisateur_id'];
        
        // Données du formulaire
        $nom = $_POST['nom'] ?? '';
        $prenom = $_POST['prenom'] ?? '';
        $email = $_POST['email'] ?? '';
        $telephone = $_POST['telephone'] ?? '';
        $matricule = $_POST['matricule'] ?? '';
        $specialite = $_POST['specialite'] ?? '';
        $grade = $_POST['grade'] ?? 'Vacataire';
        $site_id = !empty($_POST['site_id']) ? intval($_POST['site_id']) : null;
        $date_embauche = $_POST['date_embauche'] ?? date('Y-m-d');
        $statut = $_POST['statut'] ?? 'actif';
        
        try {
            $db->beginTransaction();
            
            // Mettre à jour l'utilisateur
            $update_utilisateur = "UPDATE utilisateurs SET 
                                 nom = ?, prenom = ?, email = ?, telephone = ?, site_id = ?
                                 WHERE id = ?";
            $stmt_user = $db->prepare($update_utilisateur);
            $stmt_user->execute([$nom, $prenom, $email, $telephone, $site_id, $utilisateur_id]);
            
            // Mettre à jour l'enseignant
            $update_enseignant = "UPDATE enseignants SET 
                                matricule = ?, specialite = ?, grade = ?, site_id = ?, 
                                date_embauche = ?, statut = ?
                                WHERE id = ?";
            $stmt_ens = $db->prepare($update_enseignant);
            $stmt_ens->execute([$matricule, $specialite, $grade, $site_id, $date_embauche, $statut, $enseignant_id]);
            
            $db->commit();
            
            // Rediriger avec succès
            header("Location: enseignants.php?success=Enseignant modifié avec succès");
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: enseignant_edit.php?id=$enseignant_id&error=" . urlencode($e->getMessage()));
            exit();
        }
    } else {
        header('Location: enseignants.php');
        exit();
    }
    
} catch (Exception $e) {
    header("Location: enseignants.php?error=" . urlencode($e->getMessage()));
    exit();
}