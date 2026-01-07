<?php
// dashboard/admin_principal/validation_comptes_avancee.php

// ============================================
// CONFIGURATION ET SÉCURITÉ
// ============================================

// ÉTAPE CRITIQUE 1 : Inclure l'autoload de Composer pour PHPMailer
require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';

// Activer l'affichage des erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    // Si c'est une requête AJAX, renvoyer JSON
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Non authentifié']);
        exit();
    }
    // Sinon, rediriger vers la page de login
    header('Location: ../../auth/login.php');
    exit();
}

// Vérifier le rôle (administrateur seulement)
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
        exit();
    }
    header('Location: ../../dashboard/');
    exit();
}

// Définir ROOT_PATH pour le EmailSender
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Inclure la configuration de la base de données
require_once ROOT_PATH . '/config/database.php';

// Inclure la librairie d'envoi d'emails
$emailSenderPath = ROOT_PATH . '/libs/email_sender.php';
if (file_exists($emailSenderPath)) {
    require_once $emailSenderPath;
} else {
    // Journaliser l'erreur sans arrêter le script
    error_log("Erreur: Fichier email_sender.php introuvable dans $emailSenderPath");
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Configuration email manquante']);
        exit();
    }
}

// Vérifier si la classe Database existe
if (!class_exists('Database')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Configuration BDD manquante']);
        exit();
    }
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

// Vérifier que EmailSender est disponible
if (!class_exists('EmailSender')) {
    error_log("Attention: Classe EmailSender non trouvée, les emails ne seront pas envoyés");
}

// ============================================
// TRAITEMENT DES REQUÊTES AJAX
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        // Connexion à la base de données
        $db = Database::getInstance()->getConnection();
        
        // Actions selon le type
        switch ($_POST['ajax_action']) {
            case 'approve':
                if (!isset($_POST['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'ID utilisateur manquant']);
                    exit();
                }
                
                $user_id = intval($_POST['user_id']);
                $adminName = $_SESSION['user_name'] ?? 'Administrateur ISGI';
                $adminEmail = $_SESSION['user_email'] ?? 'admin@isgi.cg';
                
                // Récupérer les infos de l'utilisateur
                $stmt = $db->prepare("SELECT u.*, r.nom as role_nom, r.id as role_id FROM utilisateurs u 
                                     LEFT JOIN roles r ON u.role_id = r.id 
                                     WHERE u.id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
                    exit();
                }
                
                // Mettre à jour le statut
                $updateStmt = $db->prepare("UPDATE utilisateurs SET statut = 'actif', date_autorisation = NOW(), autorise_par = ? WHERE id = ?");
                if ($updateStmt->execute([$_SESSION['user_id'], $user_id])) {
                    
                    // Log de l'action
                    try {
                        if ($db->query("SHOW TABLES LIKE 'logs_activite'")->rowCount() > 0) {
                            $logStmt = $db->prepare("INSERT INTO logs_activite (utilisateur_id, action, details, date_action) VALUES (?, 'approbation_compte', ?, NOW())");
                            $logDetails = "Compte approuvé: " . $user['prenom'] . " " . $user['nom'] . " (" . $user['email'] . ")";
                            $logStmt->execute([$_SESSION['user_id'], $logDetails]);
                        }
                    } catch (Exception $e) {
                        // Ignorer les erreurs de log
                        error_log("Erreur log approbation: " . $e->getMessage());
                    }
                    
                    // Envoyer l'email de confirmation
                    $emailSent = false;
                    $emailError = '';
                    
                    try {
                        if (class_exists('EmailSender')) {
                            $emailData = [
                                'to' => $user['email'],
                                'name' => $user['prenom'] . ' ' . $user['nom'],
                                'role' => $user['role_id'] ?? 8,
                                'admin_name' => $adminName,
                                'admin_email' => $adminEmail
                            ];
                            
                            // Utiliser la méthode publique
                            $emailSent = EmailSender::sendAccountApproval($emailData);
                            
                            if (!$emailSent) {
                                $emailError = "L'email n'a pas pu être envoyé, mais le compte a été approuvé.";
                                error_log("Échec envoi email approbation pour user_id: $user_id");
                            } else {
                                error_log("Email d'approbation envoyé avec succès à: " . $user['email']);
                            }
                        } else {
                            $emailError = "Classe EmailSender non disponible.";
                            error_log("EmailSender non trouvé pour l'approbation");
                        }
                    } catch (Exception $e) {
                        $emailError = "Erreur lors de l'envoi de l'email: " . $e->getMessage();
                        error_log("Exception EmailSender approbation: " . $e->getMessage());
                    }
                    
                    $message = 'Compte approuvé avec succès !';
                    if ($emailSent) {
                        $message .= ' Un email de confirmation a été envoyé.';
                    } elseif ($emailError) {
                        $message .= ' ' . $emailError;
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => $message,
                        'user_id' => $user_id,
                        'email_sent' => $emailSent
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
                }
                break;
                
            case 'reject':
                if (!isset($_POST['user_id']) || !isset($_POST['reason'])) {
                    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
                    exit();
                }
                
                $user_id = intval($_POST['user_id']);
                $reason = htmlspecialchars($_POST['reason']);
                $adminName = $_SESSION['user_name'] ?? 'Administrateur ISGI';
                $adminEmail = $_SESSION['user_email'] ?? 'admin@isgi.cg';
                
                // Récupérer les infos de l'utilisateur
                $stmt = $db->prepare("SELECT u.*, r.nom as role_nom FROM utilisateurs u 
                                     LEFT JOIN roles r ON u.role_id = r.id 
                                     WHERE u.id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
                    exit();
                }
                
                // Mettre à jour le statut
                $updateStmt = $db->prepare("UPDATE utilisateurs SET statut = 'inactif', motif_refus = ?, date_refus = NOW(), refuse_par = ? WHERE id = ?");
                if ($updateStmt->execute([$reason, $_SESSION['user_id'], $user_id])) {
                    
                    // Log de l'action
                    try {
                        if ($db->query("SHOW TABLES LIKE 'logs_activite'")->rowCount() > 0) {
                            $logStmt = $db->prepare("INSERT INTO logs_activite (utilisateur_id, action, details, date_action) VALUES (?, 'refus_compte', ?, NOW())");
                            $logDetails = "Compte refusé: " . $user['prenom'] . " " . $user['nom'] . " - Motif: " . $reason;
                            $logStmt->execute([$_SESSION['user_id'], $logDetails]);
                        }
                    } catch (Exception $e) {
                        // Ignorer les erreurs de log
                        error_log("Erreur log refus: " . $e->getMessage());
                    }
                    
                    // Envoyer l'email de refus
                    $emailSent = false;
                    $emailError = '';
                    
                    try {
                        if (class_exists('EmailSender')) {
                            $emailData = [
                                'to' => $user['email'],
                                'name' => $user['prenom'] . ' ' . $user['nom'],
                                'reason' => $reason,
                                'admin_name' => $adminName,
                                'admin_email' => $adminEmail
                            ];
                            
                            // Utiliser la bonne méthode
                            $emailSent = EmailSender::sendAccountRejection($emailData);
                            
                            if (!$emailSent) {
                                $emailError = "L'email n'a pas pu être envoyé, mais le compte a été refusé.";
                                error_log("Échec envoi email refus pour user_id: $user_id");
                            } else {
                                error_log("Email de refus envoyé avec succès à: " . $user['email']);
                            }
                        } else {
                            $emailError = "Classe EmailSender non disponible.";
                            error_log("EmailSender non trouvé pour le refus");
                        }
                    } catch (Exception $e) {
                        $emailError = "Erreur lors de l'envoi de l'email: " . $e->getMessage();
                        error_log("Exception EmailSender refus: " . $e->getMessage());
                    }
                    
                    $message = 'Compte refusé avec succès !';
                    if ($emailSent) {
                        $message .= ' Un email de notification a été envoyé.';
                    } elseif ($emailError) {
                        $message .= ' ' . $emailError;
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => $message,
                        'user_id' => $user_id,
                        'email_sent' => $emailSent
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
                }
                break;
                
            case 'request_info':
                if (!isset($_POST['user_id']) || !isset($_POST['info'])) {
                    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
                    exit();
                }
                
                $user_id = intval($_POST['user_id']);
                $info = htmlspecialchars($_POST['info']);
                $adminName = $_SESSION['user_name'] ?? 'Administrateur ISGI';
                $adminEmail = $_SESSION['user_email'] ?? 'admin@isgi.cg';
                
                // Récupérer les infos de l'utilisateur
                $stmt = $db->prepare("SELECT u.*, r.nom as role_nom FROM utilisateurs u 
                                     LEFT JOIN roles r ON u.role_id = r.id 
                                     WHERE u.id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
                    exit();
                }
                
                // Mettre à jour le statut
                $updateStmt = $db->prepare("UPDATE utilisateurs SET statut = 'en_attente_info', info_demandee = ?, date_demande_info = NOW() WHERE id = ?");
                if ($updateStmt->execute([$info, $user_id])) {
                    
                    // Log de l'action
                    try {
                        if ($db->query("SHOW TABLES LIKE 'logs_activite'")->rowCount() > 0) {
                            $logStmt = $db->prepare("INSERT INTO logs_activite (utilisateur_id, action, details, date_action) VALUES (?, 'demande_info', ?, NOW())");
                            $logDetails = "Demande d'info envoyée à l'utilisateur ID: " . $user_id;
                            $logStmt->execute([$_SESSION['user_id'], $logDetails]);
                        }
                    } catch (Exception $e) {
                        // Ignorer les erreurs de log
                        error_log("Erreur log demande info: " . $e->getMessage());
                    }
                    
                    // Envoyer l'email de demande d'information
                    $emailSent = false;
                    $emailError = '';
                    
                    try {
                        if (class_exists('EmailSender')) {
                            $emailData = [
                                'to' => $user['email'],
                                'name' => $user['prenom'] . ' ' . $user['nom'],
                                'requested_info' => $info,
                                'deadline' => '48 heures',
                                'admin_name' => $adminName,
                                'admin_email' => $adminEmail
                            ];
                            
                            // Utiliser la bonne méthode
                            if (method_exists('EmailSender', 'sendInformationRequest')) {
                                $emailSent = EmailSender::sendInformationRequest($emailData);
                            } else {
                                $emailError = "Méthode sendInformationRequest non trouvée.";
                                error_log("Méthode sendInformationRequest non trouvée dans EmailSender");
                            }
                            
                            if (!$emailSent) {
                                $emailError = "L'email n'a pas pu être envoyé, mais la demande a été enregistrée.";
                                error_log("Échec envoi email demande info pour user_id: $user_id");
                            } else {
                                error_log("Email demande info envoyé avec succès à: " . $user['email']);
                            }
                        } else {
                            $emailError = "Classe EmailSender non disponible.";
                            error_log("EmailSender non trouvé pour demande info");
                        }
                    } catch (Exception $e) {
                        $emailError = "Erreur lors de l'envoi de l'email: " . $e->getMessage();
                        error_log("Exception EmailSender demande info: " . $e->getMessage());
                    }
                    
                    $message = 'Demande d\'information envoyée !';
                    if ($emailSent) {
                        $message .= ' Un email a été envoyé à l\'utilisateur.';
                    } elseif ($emailError) {
                        $message .= ' ' . $emailError;
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => $message,
                        'user_id' => $user_id,
                        'email_sent' => $emailSent
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
                }
                break;
                
            case 'test':
                // Test de la configuration email
                $testResult = [
                    'success' => true, 
                    'message' => 'Connexion AJAX réussie !',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'session_id' => session_id(),
                    'email_sender_available' => class_exists('EmailSender')
                ];
                
                // Tester l'envoi d'email si demandé
                if (isset($_POST['test_email']) && class_exists('EmailSender')) {
                    try {
                        $testEmailData = [
                            'to' => $_SESSION['user_email'] ?? 'admin@isgi.cg',
                            'name' => $_SESSION['user_name'] ?? 'Administrateur Test',
                            'role' => 1,
                            'admin_name' => 'Système ISGI',
                            'admin_email' => 'system@isgi.cg'
                        ];
                        
                        $emailTest = EmailSender::sendAccountApproval($testEmailData);
                        $testResult['email_test'] = $emailTest ? 'Email envoyé avec succès' : 'Échec envoi email';
                    } catch (Exception $e) {
                        $testResult['email_test'] = 'Erreur: ' . $e->getMessage();
                    }
                }
                
                echo json_encode($testResult);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
        }
        
    } catch (Exception $e) {
        error_log("Erreur AJAX validation_comptes: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage(),
            'error_code' => $e->getCode()
        ]);
    }
    
    exit(); // IMPORTANT : Arrêter l'exécution après traitement AJAX
}

// ============================================
// CODE NORMAL DE LA PAGE (POUR AFFICHAGE HTML)
// ============================================

try {
    // Connexion à la base de données
    $db = Database::getInstance()->getConnection();
    
    // Titre de la page
    $pageTitle = "Validation Avancée des Comptes";
    
    // Variables
    $error = null;
    $success = null;
    $comptes_en_attente = [];
    $total_en_attente = 0;
    
    // Traitement des actions batch (non-AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_batch'])) {
        $selected_ids = isset($_POST['selected_users']) ? $_POST['selected_users'] : [];
        
        if (empty($selected_ids)) {
            $error = "Veuillez sélectionner au moins un utilisateur.";
        } else {
            $action = $_POST['action_batch'];
            $success_count = 0;
            $error_count = 0;
            $adminName = $_SESSION['user_name'] ?? 'Administrateur ISGI';
            $adminEmail = $_SESSION['user_email'] ?? 'admin@isgi.cg';
            
            foreach ($selected_ids as $user_id) {
                $user_id = intval($user_id);
                
                try {
                    // Récupérer les infos de l'utilisateur
                    $stmt = $db->prepare("SELECT u.*, r.nom as role_nom, r.id as role_id FROM utilisateurs u 
                                         LEFT JOIN roles r ON u.role_id = r.id 
                                         WHERE u.id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        if ($action === 'approuver_batch') {
                            $stmt = $db->prepare("UPDATE utilisateurs SET statut = 'actif', date_autorisation = NOW(), autorise_par = ? WHERE id = ?");
                            if ($stmt->execute([$_SESSION['user_id'], $user_id])) {
                                // Envoyer l'email si EmailSender est disponible
                                if (class_exists('EmailSender')) {
                                    try {
                                        $emailData = [
                                            'to' => $user['email'],
                                            'name' => $user['prenom'] . ' ' . $user['nom'],
                                            'role' => $user['role_id'] ?? 8,
                                            'admin_name' => $adminName,
                                            'admin_email' => $adminEmail
                                        ];
                                        $emailResult = EmailSender::sendAccountApproval($emailData);
                                        if (!$emailResult) {
                                            error_log("Batch: Échec envoi email approbation pour user_id: $user_id");
                                        }
                                    } catch (Exception $e) {
                                        error_log("Batch Email Error: " . $e->getMessage());
                                    }
                                }
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        } elseif ($action === 'refuser_batch') {
                            $reason = "Refus en traitement batch";
                            $stmt = $db->prepare("UPDATE utilisateurs SET statut = 'inactif', motif_refus = ?, date_refus = NOW(), refuse_par = ? WHERE id = ?");
                            if ($stmt->execute([$reason, $_SESSION['user_id'], $user_id])) {
                                // Envoyer l'email si EmailSender est disponible
                                if (class_exists('EmailSender')) {
                                    try {
                                        $emailData = [
                                            'to' => $user['email'],
                                            'name' => $user['prenom'] . ' ' . $user['nom'],
                                            'reason' => $reason,
                                            'admin_name' => $adminName,
                                            'admin_email' => $adminEmail
                                        ];
                                        $emailResult = EmailSender::sendAccountRejection($emailData);
                                        if (!$emailResult) {
                                            error_log("Batch: Échec envoi email refus pour user_id: $user_id");
                                        }
                                    } catch (Exception $e) {
                                        error_log("Batch Email Error: " . $e->getMessage());
                                    }
                                }
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error_count++;
                    error_log("Batch Error user_id $user_id: " . $e->getMessage());
                }
            }
            
            if ($success_count > 0) {
                $success = "Action effectuée : $success_count compte(s) traité(s)";
                if ($error_count > 0) {
                    $success .= ", $error_count erreur(s)";
                }
                if (class_exists('EmailSender')) {
                    $success .= ". Des emails ont été envoyés aux utilisateurs concernés.";
                } else {
                    $success .= ". Note: Les emails n'ont pas été envoyés (EmailSender non disponible).";
                }
            } else {
                $error = "Aucun compte n'a pu être traité";
            }
        }
    }
    
    // Filtres
    $filtre_role = isset($_GET['role']) ? intval($_GET['role']) : 0;
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_date = isset($_GET['date']) ? $_GET['date'] : '';
    
    // Construire la requête avec filtres
    $query = "SELECT u.*, r.nom as role_nom, r.id as role_id, s.nom as site_nom, s.ville as site_ville,
              DATEDIFF(NOW(), u.date_creation) as jours_attente
              FROM utilisateurs u
              LEFT JOIN roles r ON u.role_id = r.id
              LEFT JOIN sites s ON u.site_id = s.id
              WHERE u.statut = 'en_attente'";
    
    $params = [];
    
    if ($filtre_role > 0) {
        $query .= " AND u.role_id = ?";
        $params[] = $filtre_role;
    }
    
    if ($filtre_site > 0) {
        $query .= " AND u.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if ($filtre_date) {
        $query .= " AND DATE(u.date_creation) = ?";
        $params[] = $filtre_date;
    }
    
    $query .= " ORDER BY u.date_creation ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $comptes_en_attente = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_en_attente = count($comptes_en_attente);
    
    // Récupérer les statistiques
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
            SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
            SUM(CASE WHEN statut = 'inactif' THEN 1 ELSE 0 END) as inactifs
        FROM utilisateurs
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les rôles pour les filtres
    $roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les sites pour les filtres
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors du chargement des données : " . $e->getMessage();
    error_log("Page Error validation_comptes: " . $e->getMessage());
}

// Messages de succès depuis l'URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// La partie HTML suit ici...
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --info-color: #17a2b8;
    }
    
    body {
        background-color: #f8f9fa;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 10px 10px 0 0 !important;
        padding: 15px 20px;
        font-weight: 600;
    }
    
    .table th {
        background-color: #f1f5f9;
        border-top: none;
        font-weight: 600;
        color: #2c3e50;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--secondary-color), var(--info-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 16px;
    }
    
    .btn-action {
        padding: 5px 10px;
        font-size: 12px;
        border-radius: 5px;
        margin: 0 2px;
    }
    
    .stats-card {
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .stats-icon {
        font-size: 40px;
        opacity: 0.9;
    }
    
    .highlight-priority {
        background-color: #fff3cd !important;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { background-color: #fff3cd; }
        50% { background-color: #ffeaa7; }
        100% { background-color: #fff3cd; }
    }
    
    .loader-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: none;
        justify-content: center;
        align-items: center;
    }
    
    .spinner {
        width: 60px;
        height: 60px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid var(--secondary-color);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .badge-priority {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 10px;
    }
    
    .action-buttons {
        white-space: nowrap;
    }
    
    /* Correction pour DataTables */
    #usersTable_wrapper {
        padding: 0;
    }
    
    #usersTable thead th {
        padding: 12px 10px;
    }
    
    #usersTable tbody td {
        padding: 12px 10px;
        vertical-align: middle;
    }
    
    .email-status {
        font-size: 12px;
        padding: 2px 6px;
        border-radius: 3px;
    }
    
    .email-sent {
        background-color: #d4edda;
        color: #155724;
    }
    
    .email-failed {
        background-color: #f8d7da;
        color: #721c24;
    }
    </style>
</head>
<body>
    <!-- Loader overlay -->
    <div class="loader-overlay" id="loaderOverlay">
        <div class="spinner"></div>
    </div>
    
    <div class="container-fluid py-4">
        <!-- En-tête -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-person-check-fill me-2"></i>
                            Validation des Comptes
                        </h2>
                        <p class="text-muted mb-0">Gérez les demandes d'inscription en attente de validation</p>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="testAjax()">
                            <i class="bi bi-wifi me-1"></i>Test AJAX
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#helpModal">
                            <i class="bi bi-question-circle me-1"></i>Aide
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Messages d'alerte -->
        <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card bg-primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">En attente</h6>
                            <h2 class="mb-0"><?php echo $total_en_attente; ?></h2>
                            <small>Comptes à valider</small>
                        </div>
                        <div class="stats-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card bg-success">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">Actifs</h6>
                            <h2 class="mb-0"><?php echo $stats['actifs'] ?? 0; ?></h2>
                            <small>Comptes approuvés</small>
                        </div>
                        <div class="stats-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card bg-danger">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">Inactifs</h6>
                            <h2 class="mb-0"><?php echo $stats['inactifs'] ?? 0; ?></h2>
                            <small>Comptes refusés</small>
                        </div>
                        <div class="stats-icon">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="stats-card bg-warning">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">Total</h6>
                            <h2 class="mb-0"><?php echo $stats['total'] ?? 0; ?></h2>
                            <small>Tous les comptes</small>
                        </div>
                        <div class="stats-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-funnel me-2"></i>Filtres de recherche
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Rôle</label>
                        <select name="role" class="form-select">
                            <option value="0">Tous les rôles</option>
                            <?php foreach($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo $filtre_role == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            <option value="0">Tous les sites</option>
                            <?php foreach($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>" <?php echo $filtre_site == $site['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date d'inscription</label>
                        <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filtre_date); ?>">
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-2"></i>Filtrer
                            </button>
                            <a href="validation_comptes_avancee.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Actions par lot -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-list-check me-2"></i>
                    Actions par lot
                </h5>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                    <label class="form-check-label" for="selectAllCheckbox">Sélectionner tout</label>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="batchForm">
                    <div class="mb-3">
                        <button type="submit" name="action_batch" value="approuver_batch" class="btn btn-success me-2" onclick="return confirm('Approuver la sélection ? Les emails seront envoyés.')">
                            <i class="bi bi-check-circle me-2"></i>Approuver la sélection
                        </button>
                        <button type="submit" name="action_batch" value="refuser_batch" class="btn btn-danger" onclick="return confirm('Refuser la sélection ? Les emails seront envoyés.')">
                            <i class="bi bi-x-circle me-2"></i>Refuser la sélection
                        </button>
                    </div>
                    
                    <!-- Tableau des comptes -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th width="30">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>Utilisateur</th>
                                    <th>Rôle</th>
                                    <th>Site</th>
                                    <th>Date demande</th>
                                    <th>Priorité</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($comptes_en_attente)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="bi bi-check2-circle text-success" style="font-size: 48px;"></i>
                                        <h4 class="mt-3">Aucun compte en attente</h4>
                                        <p class="text-muted">Toutes les demandes ont été traitées.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach($comptes_en_attente as $compte): 
                                    $jours_attente = $compte['jours_attente'] ?? 0;
                                    $priority_class = 'bg-success';
                                    $priority_text = 'Basse';
                                    
                                    if ($jours_attente > 7) {
                                        $priority_class = 'bg-danger';
                                        $priority_text = 'Haute';
                                        $row_class = 'highlight-priority';
                                    } elseif ($jours_attente > 3) {
                                        $priority_class = 'bg-warning';
                                        $priority_text = 'Moyenne';
                                        $row_class = '';
                                    } else {
                                        $row_class = '';
                                    }
                                ?>
                                <tr class="<?php echo $row_class; ?>" data-user-id="<?php echo $compte['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="user-checkbox" name="selected_users[]" value="<?php echo $compte['id']; ?>">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-3">
                                                <?php echo strtoupper(substr($compte['prenom'], 0, 1) . substr($compte['nom'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($compte['prenom'] . ' ' . $compte['nom']); ?></strong>
                                                <div class="text-muted small">
                                                    <?php echo htmlspecialchars($compte['email']); ?>
                                                    <?php if(!empty($compte['telephone'])): ?>
                                                    <br><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($compte['telephone']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($compte['role_nom'] ?? 'Non défini'); ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($compte['site_nom'])): ?>
                                        <div><?php echo htmlspecialchars($compte['site_nom']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($compte['site_ville'] ?? ''); ?></div>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($compte['date_creation'])); ?></div>
                                        <div class="text-muted small">Il y a <?php echo $jours_attente; ?> jour(s)</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-priority <?php echo $priority_class; ?>">
                                            <?php echo $priority_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-success btn-action" 
                                                    onclick="approveUser(<?php echo $compte['id']; ?>)">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-action"
                                                    onclick="rejectUser(<?php echo $compte['id']; ?>)">
                                                <i class="bi bi-x"></i>
                                            </button>
                                            <button type="button" class="btn btn-info btn-action"
                                                    data-bs-toggle="modal" data-bs-target="#userModal"
                                                    onclick="showUserDetails(<?php echo htmlspecialchars(json_encode($compte)); ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-action"
                                                    onclick="requestInfo(<?php echo $compte['id']; ?>)">
                                                <i class="bi bi-question-circle"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Détails Utilisateur -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-badge me-2"></i>
                        Détails du compte
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="user-avatar mx-auto" style="width: 80px; height: 80px; font-size: 24px;" id="modalAvatar"></div>
                        <h4 class="mt-3" id="modalName"></h4>
                        <p class="text-muted" id="modalEmail"></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-person me-2"></i>Informations personnelles</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr><th>Nom complet:</th><td id="modalFullName"></td></tr>
                                        <tr><th>Email:</th><td id="modalUserEmail"></td></tr>
                                        <tr><th>Téléphone:</th><td id="modalPhone"></td></tr>
                                        <tr><th>Rôle:</th><td id="modalRole"></td></tr>
                                        <tr><th>Site:</th><td id="modalSite"></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Statut du compte</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr><th>Date d'inscription:</th><td id="modalDate"></td></tr>
                                        <tr><th>En attente depuis:</th><td id="modalDays"></td></tr>
                                        <tr><th>Priorité:</th><td id="modalPriority"></td></tr>
                                        <tr><th>Statut:</th><td><span class="badge bg-warning">En attente</span></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary" onclick="approveFromModal()">
                        <i class="bi bi-check me-1"></i>Approuver ce compte
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Aide -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Aide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Comment utiliser cette page :</h6>
                    <ul>
                        <li><strong>Approuver un compte :</strong> Cliquez sur le bouton vert ✓ (Email envoyé)</li>
                        <li><strong>Refuser un compte :</strong> Cliquez sur le bouton rouge ✗ (Email envoyé)</li>
                        <li><strong>Voir les détails :</strong> Cliquez sur le bouton bleu 👁️</li>
                        <li><strong>Demander des infos :</strong> Cliquez sur le bouton orange ? (Email envoyé)</li>
                        <li><strong>Sélection multiple :</strong> Cochez plusieurs cases pour des actions par lot</li>
                    </ul>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note :</strong> Un email est automatiquement envoyé à l'utilisateur pour chaque action.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Compris</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // Variables globales
    let currentUserDetails = null;
    let dataTable = null;
    
    // Initialiser DataTables
    $(document).ready(function() {
        // Vérifier s'il y a des données
        const hasData = <?php echo !empty($comptes_en_attente) ? 'true' : 'false'; ?>;
        
        if (hasData) {
            dataTable = $('#usersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
                },
                pageLength: 25,
                order: [[5, 'desc']],
                columnDefs: [
                    { 
                        orderable: false, 
                        targets: [0, 6] 
                    },
                    { 
                        searchable: false, 
                        targets: [0, 5, 6] 
                    },
                    {
                        targets: [0],
                        className: 'dt-body-center dt-head-center'
                    }
                ],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
                initComplete: function() {
                    // S'assurer que les colonnes correspondent
                    const theadCols = $('#usersTable thead tr').children().length;
                    const tbodyCols = $('#usersTable tbody tr:first').children().length;
                    
                    if (theadCols !== tbodyCols) {
                        console.error('Erreur de colonnes: thead=' + theadCols + ', tbody=' + tbodyCols);
                    }
                }
            });
            
            // Gestion de la sélection multiple avec DataTables
            $('#selectAll').on('click', function() {
                const rows = dataTable.rows({ 'page': 'current' }).nodes();
                $('input[type="checkbox"]', rows).prop('checked', this.checked);
                $('#selectAllCheckbox').prop('checked', this.checked);
            });
            
            $('#selectAllCheckbox').on('click', function() {
                const rows = dataTable.rows({ 'page': 'current' }).nodes();
                $('input[type="checkbox"]', rows).prop('checked', this.checked);
                $('#selectAll').prop('checked', this.checked);
            });
            
            // Mettre à jour le checkbox principal
            $('#usersTable tbody').on('change', 'input[type="checkbox"]', function() {
                const total = $('input[type="checkbox"]', dataTable.rows({ 'page': 'current' }).nodes()).length;
                const checked = $('input[type="checkbox"]:checked', dataTable.rows({ 'page': 'current' }).nodes()).length;
                
                $('#selectAll').prop('checked', total === checked && total > 0);
                $('#selectAllCheckbox').prop('checked', total === checked && total > 0);
            });
        } else {
            // Pas de données, masquer le tableau DataTables
            $('#usersTable').DataTable({
                searching: false,
                ordering: false,
                info: false,
                paging: false
            });
        }
    });
    
    // Fonctions utilitaires
    function showLoader(show) {
        document.getElementById('loaderOverlay').style.display = show ? 'flex' : 'none';
    }
    
    // Fonction AJAX générique
    function sendAjaxRequest(action, data = {}) {
        showLoader(true);
        
        // Ajouter l'action et le token CSRF
        const requestData = {
            ajax_action: action,
            ...data
        };
        
        return $.ajax({
            url: '', // URL actuelle (même fichier)
            type: 'POST',
            data: requestData,
            dataType: 'json'
        }).always(function() {
            showLoader(false);
        });
    }
    
    // Tester la connexion AJAX
    function testAjax() {
        sendAjaxRequest('test')
            .done(function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Connexion AJAX OK',
                        text: response.message,
                        timer: 2000
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erreur',
                        text: response.message
                    });
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('Erreur AJAX:', textStatus, errorThrown);
                Swal.fire({
                    icon: 'error',
                    title: 'Erreur de connexion',
                    html: `Code: ${jqXHR.status}<br>Message: ${errorThrown}<br><br>
                           <small>Vérifiez que le fichier PHP est accessible.</small>`
                });
            });
    }
    
    // Approuver un utilisateur
    function approveUser(userId) {
        Swal.fire({
            title: 'Approuver ce compte ?',
            html: `
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Un email de confirmation sera envoyé à l'utilisateur.
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Oui, approuver et envoyer l\'email',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                sendAjaxRequest('approve', { user_id: userId })
                    .done(function(response) {
                        if (response.success) {
                            const emailStatus = response.email_sent ? 
                                '<span class="email-status email-sent"><i class="bi bi-envelope-check"></i> Email envoyé</span>' : 
                                '<span class="email-status email-failed"><i class="bi bi-envelope-x"></i> Email échoué</span>';
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Succès !',
                                html: `${response.message}<br><br>${emailStatus}`,
                                timer: 2000
                            }).then(() => {
                                // Supprimer la ligne du tableau
                                if (dataTable) {
                                    dataTable.row($(`tr[data-user-id="${userId}"]`)).remove().draw();
                                } else {
                                    $(`tr[data-user-id="${userId}"]`).fadeOut(500, function() {
                                        $(this).remove();
                                    });
                                }
                                
                                // Mettre à jour le compteur
                                updatePendingCount(-1);
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Erreur',
                                text: response.message
                            });
                        }
                    })
                    .fail(function(jqXHR) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Erreur technique',
                            text: `Code ${jqXHR.status}: ${jqXHR.statusText}`
                        });
                    });
            }
        });
    }
    
    // Refuser un utilisateur
    function rejectUser(userId) {
        Swal.fire({
            title: 'Refuser ce compte',
            html: `
                <div class="mb-3">
                    <label class="form-label">Motif du refus :</label>
                    <textarea id="rejectReason" class="form-control" rows="3" 
                              placeholder="Expliquez pourquoi vous refusez ce compte..."></textarea>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Un email sera envoyé à l'utilisateur avec ce motif.
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Confirmer le refus et envoyer l\'email',
            cancelButtonText: 'Annuler',
            preConfirm: () => {
                const reason = $('#rejectReason').val().trim();
                if (!reason) {
                    Swal.showValidationMessage('Veuillez indiquer un motif');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                sendAjaxRequest('reject', { 
                    user_id: userId, 
                    reason: result.value 
                })
                    .done(function(response) {
                        if (response.success) {
                            const emailStatus = response.email_sent ? 
                                '<span class="email-status email-sent"><i class="bi bi-envelope-check"></i> Email envoyé</span>' : 
                                '<span class="email-status email-failed"><i class="bi bi-envelope-x"></i> Email échoué</span>';
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Compte refusé',
                                html: `${response.message}<br><br>${emailStatus}`,
                                timer: 2000
                            }).then(() => {
                                // Supprimer la ligne du tableau
                                if (dataTable) {
                                    dataTable.row($(`tr[data-user-id="${userId}"]`)).remove().draw();
                                } else {
                                    $(`tr[data-user-id="${userId}"]`).fadeOut(500, function() {
                                        $(this).remove();
                                    });
                                }
                                
                                // Mettre à jour le compteur
                                updatePendingCount(-1);
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Erreur',
                                text: response.message
                            });
                        }
                    })
                    .fail(function(jqXHR) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Erreur technique',
                            text: `Code ${jqXHR.status}: ${jqXHR.statusText}`
                        });
                    });
            }
        });
    }
    
    // Demander des informations supplémentaires
    function requestInfo(userId) {
        Swal.fire({
            title: 'Demander des informations',
            html: `
                <div class="mb-3">
                    <label class="form-label">Quelles informations manquent ?</label>
                    <textarea id="requestedInfo" class="form-control" rows="4" 
                              placeholder="Listez les informations ou documents manquants..."></textarea>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Un email sera envoyé à l'utilisateur avec cette demande.
                </div>
            `,
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Envoyer la demande par email',
            cancelButtonText: 'Annuler',
            preConfirm: () => {
                const info = $('#requestedInfo').val().trim();
                if (!info) {
                    Swal.showValidationMessage('Veuillez spécifier les informations demandées');
                    return false;
                }
                return info;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                sendAjaxRequest('request_info', { 
                    user_id: userId, 
                    info: result.value 
                })
                    .done(function(response) {
                        if (response.success) {
                            const emailStatus = response.email_sent ? 
                                '<span class="email-status email-sent"><i class="bi bi-envelope-check"></i> Email envoyé</span>' : 
                                '<span class="email-status email-failed"><i class="bi bi-envelope-x"></i> Email échoué</span>';
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Demande envoyée',
                                html: `${response.message}<br><br>${emailStatus}`,
                                timer: 2000
                            }).then(() => {
                                // Mettre à jour le statut visuellement
                                $(`tr[data-user-id="${userId}"]`).addClass('table-warning');
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Erreur',
                                text: response.message
                            });
                        }
                    })
                    .fail(function(jqXHR) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Erreur technique',
                            text: `Code ${jqXHR.status}: ${jqXHR.statusText}`
                        });
                    });
            }
        });
    }
    
    // Afficher les détails d'un utilisateur dans le modal
    function showUserDetails(user) {
        currentUserDetails = user;
        
        // Initiales pour l'avatar
        const initials = (user.prenom ? user.prenom.charAt(0) : '') + (user.nom ? user.nom.charAt(0) : '');
        $('#modalAvatar').text(initials.toUpperCase());
        
        // Informations personnelles
        $('#modalName').text(user.prenom + ' ' + user.nom);
        $('#modalEmail').text(user.email);
        $('#modalFullName').text(user.prenom + ' ' + user.nom);
        $('#modalUserEmail').text(user.email);
        $('#modalPhone').text(user.telephone || 'Non renseigné');
        $('#modalRole').html(`<span class="badge bg-primary">${user.role_nom || 'Non défini'}</span>`);
        $('#modalSite').text(user.site_nom ? `${user.site_nom} (${user.site_ville || ''})` : 'Non assigné');
        $('#modalDate').text(new Date(user.date_creation).toLocaleDateString('fr-FR'));
        $('#modalDays').text((user.jours_attente || 0) + ' jour(s)');
        
        // Priorité
        let priorityText = 'Basse';
        let priorityClass = 'success';
        const jours = user.jours_attente || 0;
        
        if (jours > 7) {
            priorityText = 'Haute';
            priorityClass = 'danger';
        } else if (jours > 3) {
            priorityText = 'Moyenne';
            priorityClass = 'warning';
        }
        
        $('#modalPriority').html(`<span class="badge bg-${priorityClass}">${priorityText}</span>`);
    }
    
    // Approuver depuis le modal
    function approveFromModal() {
        if (currentUserDetails) {
            $('#userModal').modal('hide');
            setTimeout(() => {
                approveUser(currentUserDetails.id);
            }, 300);
        }
    }
    
    // Mettre à jour le compteur en attente
    function updatePendingCount(change) {
        const counter = document.querySelector('.stats-card.bg-primary h2');
        if (counter) {
            let current = parseInt(counter.textContent) || 0;
            current += change;
            counter.textContent = current;
            
            // Mettre à jour le badge si nécessaire
            if (current <= 0) {
                document.querySelector('.stats-card.bg-primary .stats-icon i').className = 'bi bi-check2-circle';
            }
        }
    }
    
    // Tester AJAX au chargement (optionnel)
    // $(document).ready(function() {
    //     setTimeout(testAjax, 1000);
    // });
    </script>
</body>
</html>