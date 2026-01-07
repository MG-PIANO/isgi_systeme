<?php
session_start();
require_once 'config/database.php';

// Initialisation
$db = Database::getInstance()->getConnection();
$errors = [];
$form_data = [];

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les données du formulaire
    $etudiant_id = $_POST['etudiant_id'] ?? '';
    $matricule = $_POST['matricule'] ?? '';
    $annee_academique = $_POST['annee_academique'] ?? '';
    $filiere = $_POST['filiere'] ?? '';
    $niveau = $_POST['niveau'] ?? '';
    $cycle_formation = $_POST['cycle_formation'] ?? '';
    $type_rentree = $_POST['type_rentree'] ?? '';
    $site_formation = $_POST['site_formation'] ?? '';
    $mode_paiement = $_POST['mode_paiement'] ?? '';
    $numero_transaction = $_POST['numero_transaction'] ?? '';
    $date_paiement = $_POST['date_paiement'] ?? '';
    $periodicite_paiement = $_POST['periodicite_paiement'] ?? '';
    $motif = $_POST['motif'] ?? '';
    
    // Validation des données
    if (empty($etudiant_id) || empty($matricule)) {
        $errors[] = "Identifiant étudiant manquant.";
    }
    
    if (empty($filiere)) {
        $errors[] = "La filière est obligatoire.";
    }
    
    if (empty($niveau)) {
        $errors[] = "Le niveau est obligatoire.";
    }
    
    if (empty($type_rentree)) {
        $errors[] = "Le type de rentrée est obligatoire.";
    }
    
    if (empty($site_formation)) {
        $errors[] = "Le site de formation est obligatoire.";
    }
    
    if (empty($mode_paiement)) {
        $errors[] = "Le mode de paiement est obligatoire.";
    }
    
    if (empty($date_paiement)) {
        $errors[] = "La date de paiement est obligatoire.";
    }
    
    if (empty($periodicite_paiement)) {
        $errors[] = "La périodicité de paiement est obligatoire.";
    }
    
    // Si aucune erreur, procéder à l'enregistrement
    if (empty($errors)) {
        try {
            // Commencer une transaction
            $db->beginTransaction();
            
            // 1. Mettre à jour l'étudiant avec les nouvelles informations
            $stmt = $db->prepare("
                UPDATE etudiants 
                SET filiere = ?, 
                    niveau = ?, 
                    cycle_formation = ?, 
                    type_rentree = ?, 
                    site_formation = ?, 
                    mode_paiement = ?, 
                    periodicite_paiement = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $filiere,
                $niveau,
                $cycle_formation,
                $type_rentree,
                $site_formation,
                $mode_paiement,
                $periodicite_paiement,
                $etudiant_id
            ]);
            
            // 2. Enregistrer la réinscription dans la table dédiée
            $stmt = $db->prepare("
                INSERT INTO reinscriptions (
                    etudiant_id, 
                    matricule, 
                    annee_academique, 
                    filiere, 
                    niveau, 
                    type_rentree, 
                    site_formation, 
                    mode_paiement, 
                    numero_transaction, 
                    date_paiement, 
                    frais_payes, 
                    periodicite_paiement, 
                    motif, 
                    statut
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'valide')
            ");
            
            // Récupérer les frais de réinscription depuis les configurations
            $frais_reinscription = 25000; // Valeur par défaut
            try {
                $stmtConfig = $db->query("SELECT valeur FROM configurations WHERE cle = 'frais_reinscription'");
                $config = $stmtConfig->fetch();
                if ($config) {
                    $frais_reinscription = (float)$config['valeur'];
                }
            } catch (Exception $e) {
                // Utiliser la valeur par défaut en cas d'erreur
            }
            
            $stmt->execute([
                $etudiant_id,
                $matricule,
                $annee_academique,
                $filiere,
                $niveau,
                $type_rentree,
                $site_formation,
                $mode_paiement,
                $numero_transaction,
                $date_paiement,
                $frais_reinscription,
                $periodicite_paiement,
                $motif
            ]);
            
            // 3. Ajouter à l'historique des réinscriptions
            $stmt = $db->prepare("
                INSERT INTO historique_reinscriptions (
                    etudiant_id, 
                    annee_academique, 
                    filiere, 
                    niveau, 
                    mode_paiement, 
                    frais
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $etudiant_id,
                $annee_academique,
                $filiere,
                $niveau,
                $mode_paiement,
                $frais_reinscription
            ]);
            
            // 4. Valider la transaction
            $db->commit();
            
            // Stocker les informations de succès dans la session
            $_SESSION['reinscription_success'] = true;
            $_SESSION['annee_academique'] = $annee_academique;
            $_SESSION['matricule'] = $matricule;
            $_SESSION['filiere'] = $filiere;
            $_SESSION['niveau'] = $niveau;
            
            // Rediriger vers la page de réinscription avec un message de succès
            header('Location: reinscription.php?matricule=' . urlencode($matricule));
            exit();
            
        } catch (PDOException $e) {
            // En cas d'erreur, annuler la transaction
            $db->rollBack();
            error_log("Erreur lors de la réinscription: " . $e->getMessage());
            $errors[] = "Une erreur est survenue lors de l'enregistrement de votre réinscription.";
            
            // Stocker les erreurs et les données du formulaire pour réaffichage
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = [
                'filiere' => $filiere,
                'niveau' => $niveau,
                'cycle_formation' => $cycle_formation,
                'type_rentree' => $type_rentree,
                'site_formation' => $site_formation,
                'mode_paiement' => $mode_paiement,
                'numero_transaction' => $numero_transaction,
                'date_paiement' => $date_paiement,
                'periodicite_paiement' => $periodicite_paiement,
                'motif' => $motif
            ];
            
            // Rediriger vers la page de réinscription avec les erreurs
            header('Location: reinscription.php?matricule=' . urlencode($matricule));
            exit();
        }
    } else {
        // Stocker les erreurs et les données du formulaire pour réaffichage
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = [
            'filiere' => $filiere,
            'niveau' => $niveau,
            'cycle_formation' => $cycle_formation,
            'type_rentree' => $type_rentree,
            'site_formation' => $site_formation,
            'mode_paiement' => $mode_paiement,
            'numero_transaction' => $numero_transaction,
            'date_paiement' => $date_paiement,
            'periodicite_paiement' => $periodicite_paiement,
            'motif' => $motif
        ];
        
        // Rediriger vers la page de réinscription avec les erreurs
        header('Location: reinscription.php?matricule=' . urlencode($matricule));
        exit();
    }
} else {
    // Si pas de POST, rediriger vers la page de réinscription
    header('Location: reinscription.php');
    exit();
}