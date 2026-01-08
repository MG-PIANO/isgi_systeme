<?php
// dashboard/etudiant/messagerie.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier que l'utilisateur est bien un étudiant
if ($_SESSION['role_id'] != 8) { // 8 = Étudiant
    header('Location: ' . ROOT_PATH . '/dashboard/access_denied.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Messagerie Étudiant";
    
    // Fonctions utilitaires avec validation
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format(floatval($amount), 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatDateTimeFr($date) {
        if (empty($date) || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date('d/m/Y H:i', $timestamp);
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'lu':
            case 'valide':
            case 'present':
                return '<span class="badge bg-success">Lu</span>';
            case 'non_lu':
            case 'en_attente':
                return '<span class="badge bg-warning">Non lu</span>';
            case 'urgent':
                return '<span class="badge bg-danger">Urgent</span>';
            case 'brouillon':
                return '<span class="badge bg-secondary">Brouillon</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Fonction sécurisée pour afficher du texte
    function safeHtml($text) {
        if ($text === null || $text === '') {
            return '';
        }
        return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Étudiant';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? intval($_SESSION['role_id']) : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? intval($_SESSION['site_id']) : null;
        }
        
        public static function getUserId() {
            return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        }
        
        public static function getEtudiantId() {
            return isset($_SESSION['etudiant_id']) ? intval($_SESSION['etudiant_id']) : null;
        }
    }
    
    // Récupérer l'ID de l'utilisateur
    $user_id = SessionManager::getUserId();
    $etudiant_id = SessionManager::getEtudiantId();
    
    // Récupérer les paramètres GET
    $action = isset($_GET['action']) ? $_GET['action'] : 'inbox';
    $message_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $destinataire_id = isset($_GET['to']) ? intval($_GET['to']) : 0;
    
    // Initialiser les variables
    $messages = array();
    $message_details = array();
    $contacts = array();
    $statistiques = array(
        'non_lus' => 0,
        'total' => 0,
        'envoyes' => 0,
        'brouillons' => 0
    );
    
    $error = null;
    $success = null;
    
    // Fonction pour exécuter les requêtes en toute sécurité
    function executeQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            return array();
        }
    }
    
    function executeSingleQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: array();
        } catch (Exception $e) {
            error_log("Single query error: " . $e->getMessage());
            return array();
        }
    }
    
    // Récupérer les informations de l'étudiant
    $info_etudiant = executeSingleQuery($db, 
        "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    // Récupérer les statistiques de messagerie
    if ($user_id) {
        // Messages reçus non lus
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM messages 
             WHERE destinataire_id = ? AND lu = 0",
            [$user_id]);
        $statistiques['non_lus'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Messages reçus total
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM messages 
             WHERE destinataire_id = ?",
            [$user_id]);
        $statistiques['total'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Messages envoyés
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM messages 
             WHERE expediteur_id = ?",
            [$user_id]);
        $statistiques['envoyes'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Messages en brouillon (à implémenter si table existe)
        $statistiques['brouillons'] = 0;
    }
    
    // Traitement des différentes actions
    switch ($action) {
        case 'view':
            // Afficher un message spécifique
            if ($message_id > 0) {
                $message_details = executeSingleQuery($db,
                    "SELECT m.*, 
                            CONCAT(exp.nom, ' ', exp.prenom) as expediteur_nom,
                            exp.photo_profil as expediteur_photo,
                            CONCAT(dest.nom, ' ', dest.prenom) as destinataire_nom,
                            dest.photo_profil as destinataire_photo
                     FROM messages m
                     JOIN utilisateurs exp ON m.expediteur_id = exp.id
                     JOIN utilisateurs dest ON m.destinataire_id = dest.id
                     WHERE m.id = ? 
                     AND (m.expediteur_id = ? OR m.destinataire_id = ?)",
                    [$message_id, $user_id, $user_id]);
                
                if ($message_details) {
                    // Marquer le message comme lu si c'est le destinataire
                    if ($message_details['destinataire_id'] == $user_id && $message_details['lu'] == 0) {
                        $db->prepare("UPDATE messages SET lu = 1, date_lecture = NOW() WHERE id = ?")
                           ->execute([$message_id]);
                        $message_details['lu'] = 1;
                    }
                }
            }
            break;
            
        case 'send':
            // Envoyer un nouveau message
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $destinataire_id = isset($_POST['destinataire_id']) ? intval($_POST['destinataire_id']) : 0;
                $sujet = isset($_POST['sujet']) ? trim($_POST['sujet']) : '';
                $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';
                $type_message = isset($_POST['type_message']) ? $_POST['type_message'] : 'normal';
                
                // Validation
                if (empty($destinataire_id)) {
                    $error = "Veuillez sélectionner un destinataire.";
                } elseif (empty($sujet)) {
                    $error = "Veuillez saisir un sujet.";
                } elseif (empty($contenu)) {
                    $error = "Veuillez saisir un message.";
                } else {
                    // Vérifier que le destinataire existe
                    $destinataire = executeSingleQuery($db,
                        "SELECT id FROM utilisateurs WHERE id = ? AND statut = 'actif'",
                        [$destinataire_id]);
                    
                    if ($destinataire) {
                        // Insérer le message
                        $stmt = $db->prepare("
                            INSERT INTO messages (expediteur_id, destinataire_id, sujet, contenu, type_message, date_envoi)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        
                        if ($stmt->execute([$user_id, $destinataire_id, $sujet, $contenu, $type_message])) {
                            $success = "Message envoyé avec succès!";
                            $action = 'sent'; // Rediriger vers les messages envoyés
                        } else {
                            $error = "Erreur lors de l'envoi du message.";
                        }
                    } else {
                        $error = "Destinataire invalide.";
                    }
                }
            }
            break;
            
        case 'reply':
            // Répondre à un message
            if ($message_id > 0) {
                $message_details = executeSingleQuery($db,
                    "SELECT m.*, 
                            CONCAT(exp.nom, ' ', exp.prenom) as expediteur_nom
                     FROM messages m
                     JOIN utilisateurs exp ON m.expediteur_id = exp.id
                     WHERE m.id = ? AND m.destinataire_id = ?",
                    [$message_id, $user_id]);
                
                if ($message_details) {
                    $destinataire_id = $message_details['expediteur_id'];
                    $action = 'compose'; // Passer en mode composition
                }
            }
            break;
            
        case 'delete':
            // Supprimer un message
            if ($message_id > 0) {
                $stmt = $db->prepare("DELETE FROM messages WHERE id = ? AND (expediteur_id = ? OR destinataire_id = ?)");
                if ($stmt->execute([$message_id, $user_id, $user_id])) {
                    $success = "Message supprimé avec succès!";
                    $action = 'inbox';
                } else {
                    $error = "Erreur lors de la suppression du message.";
                }
            }
            break;
    }
    
    // Récupérer les messages selon l'action
    switch ($action) {
        case 'inbox':
            // Boîte de réception
            $messages = executeQuery($db,
                "SELECT m.*, 
                        CONCAT(exp.nom, ' ', exp.prenom) as expediteur_nom,
                        exp.photo_profil as expediteur_photo
                 FROM messages m
                 JOIN utilisateurs exp ON m.expediteur_id = exp.id
                 WHERE m.destinataire_id = ?
                 ORDER BY m.date_envoi DESC
                 LIMIT 50",
                [$user_id]);
            break;
            
        case 'sent':
            // Messages envoyés
            $messages = executeQuery($db,
                "SELECT m.*, 
                        CONCAT(dest.nom, ' ', dest.prenom) as destinataire_nom,
                        dest.photo_profil as destinataire_photo
                 FROM messages m
                 JOIN utilisateurs dest ON m.destinataire_id = dest.id
                 WHERE m.expediteur_id = ?
                 ORDER BY m.date_envoi DESC
                 LIMIT 50",
                [$user_id]);
            break;
            
        case 'urgent':
            // Messages urgents
            $messages = executeQuery($db,
                "SELECT m.*, 
                        CONCAT(exp.nom, ' ', exp.prenom) as expediteur_nom,
                        exp.photo_profil as expediteur_photo
                 FROM messages m
                 JOIN utilisateurs exp ON m.expediteur_id = exp.id
                 WHERE m.destinataire_id = ? AND m.type_message = 'urgence'
                 ORDER BY m.date_envoi DESC
                 LIMIT 50",
                [$user_id]);
            break;
    }
    
    // Récupérer les contacts (professeurs, administration, autres étudiants)
    if ($info_etudiant && isset($info_etudiant['site_id']) && isset($info_etudiant['classe_id'])) {
        $site_id = intval($info_etudiant['site_id']);
        $classe_id = intval($info_etudiant['classe_id']);
        
        // Professeurs de la classe
        $contacts = executeQuery($db,
            "SELECT DISTINCT u.id, u.nom, u.prenom, u.email, u.photo_profil, 'Professeur' as type_contact,
                    e.grade, e.specialite
             FROM utilisateurs u
             JOIN enseignants e ON u.id = e.utilisateur_id
             JOIN matieres m ON e.id = m.enseignant_id
             JOIN classes c ON m.filiere_id = c.filiere_id AND m.niveau_id = c.niveau_id
             WHERE c.id = ? AND u.statut = 'actif'
             ORDER BY u.nom, u.prenom",
            [$classe_id]);
        
        // Administration du site
        $admin_contacts = executeQuery($db,
            "SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, 
                    CASE r.nom 
                        WHEN 'Administrateur Site' THEN 'Administration'
                        WHEN 'Gestionnaire Principal' THEN 'Service Financier'
                        WHEN 'DAC' THEN 'Affaires Académiques'
                        WHEN 'Surveillant Général' THEN 'Surveillance'
                        ELSE r.nom 
                    END as type_contact,
                    '' as grade, '' as specialite
             FROM utilisateurs u
             JOIN roles r ON u.role_id = r.id
             WHERE u.site_id = ? 
             AND u.statut = 'actif'
             AND r.id IN (1, 2, 3, 5, 6) -- Rôles admin/profs/gestionnaires
             ORDER BY r.nom, u.nom, u.prenom",
            [$site_id]);
        
        $contacts = array_merge($contacts, $admin_contacts);
        
        // Autres étudiants de la classe
        $etudiants_contacts = executeQuery($db,
            "SELECT u.id, u.nom, u.prenom, u.email, u.photo_profil, 'Étudiant' as type_contact,
                    '' as grade, '' as specialite
             FROM utilisateurs u
             JOIN etudiants e ON u.id = e.utilisateur_id
             WHERE e.classe_id = ? 
             AND u.id != ?
             AND u.statut = 'actif'
             ORDER BY u.nom, u.prenom",
            [$classe_id, $user_id]);
        
        $contacts = array_merge($contacts, $etudiants_contacts);
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . safeHtml($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safeHtml($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Summernote pour l'éditeur de texte riche -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs5.min.css" rel="stylesheet">
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #f8f9fa;
        --card-bg: #ffffff;
        --text-color: #212529;
        --text-muted: #6c757d;
        --sidebar-bg: #2c3e50;
        --sidebar-text: #ffffff;
        --border-color: #dee2e6;
    }
    
    [data-theme="dark"] {
        --primary-color: #3498db;
        --secondary-color: #2980b9;
        --accent-color: #e74c3c;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --text-color: #e0e0e0;
        --text-muted: #a0a0a0;
        --sidebar-bg: #1a1a1a;
        --sidebar-text: #ffffff;
        --border-color: #333333;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }
    
    .app-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }
    
    .sidebar-header {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
    }
    
    .sidebar-logo {
        width: 50px;
        height: 50px;
        background: var(--secondary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
    }
    
    .user-info {
        text-align: center;
        margin-bottom: 20px;
        padding: 0 15px;
    }
    
    .user-role {
        display: inline-block;
        padding: 4px 12px;
        background: var(--secondary-color);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        margin-top: 5px;
    }
    
    /* Navigation */
    .sidebar-nav {
        padding: 15px;
    }
    
    .nav-section {
        margin-bottom: 25px;
    }
    
    .nav-section-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.6);
        margin-bottom: 10px;
        padding: 0 10px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--sidebar-text);
        text-decoration: none;
        border-radius: 5px;
        margin-bottom: 5px;
        transition: all 0.3s;
    }
    
    .nav-link:hover, .nav-link.active {
        background-color: var(--secondary-color);
        color: white;
    }
    
    .nav-link i {
        width: 20px;
        margin-right: 10px;
        text-align: center;
    }
    
    .nav-badge {
        margin-left: auto;
        background: var(--accent-color);
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 10px;
    }
    
    /* Contenu principal */
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Stat cards */
    .stat-card {
        text-align: center;
        padding: 20px;
    }
    
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    
    /* Messagerie spécifique */
    .message-item {
        border-left: 4px solid transparent;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .message-item:hover {
        background-color: rgba(52, 152, 219, 0.1);
    }
    
    .message-item.unread {
        border-left-color: var(--secondary-color);
        background-color: rgba(52, 152, 219, 0.05);
    }
    
    .message-item.urgent {
        border-left-color: var(--accent-color);
    }
    
    .message-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }
    
    .message-preview {
        color: var(--text-muted);
        font-size: 0.9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    
    .message-attachments {
        font-size: 0.8rem;
        color: var(--secondary-color);
    }
    
    .message-view {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        border: 1px solid var(--border-color);
    }
    
    .message-header {
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 15px;
        margin-bottom: 20px;
    }
    
    .message-content {
        line-height: 1.6;
        font-size: 1rem;
    }
    
    .contact-card {
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        border: 1px solid var(--border-color);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .contact-card:hover {
        background-color: rgba(52, 152, 219, 0.1);
        border-color: var(--secondary-color);
    }
    
    .contact-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }
    
    .contact-type {
        font-size: 0.8rem;
        color: var(--text-muted);
    }
    
    .contact-specialite {
        font-size: 0.8rem;
        color: var(--secondary-color);
    }
    
    /* Éditeur de message */
    .compose-area {
        min-height: 300px;
    }
    
    .recipient-select {
        max-height: 200px;
        overflow-y: auto;
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .alert-info {
        background-color: rgba(23, 162, 184, 0.1);
        border-left: 4px solid var(--info-color);
    }
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 70px;
            overflow-x: hidden;
        }
        
        .sidebar-header, .user-info, .nav-section-title, .nav-link span {
            display: none;
        }
        
        .nav-link {
            justify-content: center;
            padding: 15px;
        }
        
        .nav-link i {
            margin-right: 0;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 70px;
            padding: 15px;
        }
        
        .stat-value {
            font-size: 1.5rem;
        }
    }
    
    /* En-têtes */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .content-header h2 {
        color: var(--text-color);
    }
    
    .content-header .text-muted {
        color: var(--text-muted);
    }
    
    /* Boutons */
    .btn-outline-light {
        color: var(--sidebar-text);
        border-color: var(--sidebar-text);
    }
    
    .btn-outline-light:hover {
        background-color: var(--sidebar-text);
        color: var(--sidebar-bg);
    }
    
    /* Formulaires */
    .form-control, .form-select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .form-control:focus, .form-select:focus {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
    }
    
    /* Onglets */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Textes spécifiques */
    .text-muted {
        color: var(--text-muted) !important;
    }
    
    .text-primary {
        color: var(--primary-color) !important;
    }
    
    .text-success {
        color: var(--success-color) !important;
    }
    
    .text-warning {
        color: var(--warning-color) !important;
    }
    
    .text-danger {
        color: var(--accent-color) !important;
    }
    
    .text-info {
        color: var(--info-color) !important;
    }
    
    /* Icônes */
    .fa-circle {
        font-size: 0.6rem;
        vertical-align: middle;
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI</h5>
                <div class="user-role">Étudiant</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo safeHtml(SessionManager::getUserName()); ?></p>
                <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                <small>Matricule: <?php echo safeHtml($info_etudiant['matricule']); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations Personnelles</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="messagerie.php" class="nav-link active">
                        <i class="fas fa-envelope"></i>
                        <span>Messagerie</span>
                        <?php if($statistiques['non_lus'] > 0): ?>
                        <span class="nav-badge"><?php echo $statistiques['non_lus']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Mes Professeurs</span>
                    </a>
                    <a href="annonces.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>Annonces</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="parametres.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Paramètres</span>
                    </a>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Contenu Principal -->
        <div class="main-content">
            <!-- En-tête -->
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-envelope me-2"></i>
                            Messagerie
                        </h2>
                        <p class="text-muted mb-0">
                            Gérez vos communications avec les professeurs et l'administration
                        </p>
                    </div>
                    <div class="btn-group">
                        <a href="messagerie.php?action=compose" class="btn btn-primary">
                            <i class="fas fa-pen"></i> Nouveau message
                        </a>
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'erreur/succès -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo safeHtml($success); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques et Actions rapides -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['non_lus']; ?></div>
                        <div class="stat-label">Messages non lus</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-envelope-open"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['total']; ?></div>
                        <div class="stat-label">Messages reçus</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['envoyes']; ?></div>
                        <div class="stat-label">Messages envoyés</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['brouillons']; ?></div>
                        <div class="stat-label">Brouillons</div>
                    </div>
                </div>
            </div>
            
            <!-- Section principale selon l'action -->
            <?php if ($action == 'compose'): ?>
            <!-- Composition d'un nouveau message -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-pen me-2"></i>
                                Nouveau message
                            </h5>
                        </div>
                        <div class="card-body">
                            <form action="messagerie.php?action=send" method="POST" id="messageForm">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="destinataire_id" class="form-label">Destinataire <span class="text-danger">*</span></label>
                                        <select class="form-select" id="destinataire_id" name="destinataire_id" required>
                                            <option value="">Sélectionner un destinataire...</option>
                                            <?php if(!empty($contacts)): ?>
                                                <?php 
                                                // Grouper les contacts par type
                                                $grouped_contacts = [];
                                                foreach($contacts as $contact) {
                                                    $type = $contact['type_contact'] ?? 'Autre';
                                                    if (!isset($grouped_contacts[$type])) {
                                                        $grouped_contacts[$type] = [];
                                                    }
                                                    $grouped_contacts[$type][] = $contact;
                                                }
                                                ?>
                                                <?php foreach($grouped_contacts as $type => $type_contacts): ?>
                                                <optgroup label="<?php echo safeHtml($type); ?>">
                                                    <?php foreach($type_contacts as $contact): ?>
                                                    <option value="<?php echo $contact['id']; ?>" 
                                                            <?php echo ($destinataire_id == $contact['id']) ? 'selected' : ''; ?>>
                                                        <?php echo safeHtml(($contact['nom'] ?? '') . ' ' . ($contact['prenom'] ?? '')); ?>
                                                        <?php if(!empty($contact['grade'])): ?>
                                                        (<?php echo safeHtml($contact['grade']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="type_message" class="form-label">Type de message</label>
                                        <select class="form-select" id="type_message" name="type_message">
                                            <option value="normal">Normal</option>
                                            <option value="urgence">Urgent</option>
                                            <option value="question">Question</option>
                                            <option value="rendezvous">Demande de rendez-vous</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="sujet" class="form-label">Sujet <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sujet" name="sujet" 
                                               value="<?php echo isset($_POST['sujet']) ? safeHtml($_POST['sujet']) : ''; ?>" 
                                               placeholder="Sujet du message" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="contenu" class="form-label">Message <span class="text-danger">*</span></label>
                                    <textarea class="form-control compose-area" id="contenu" name="contenu" rows="10" required><?php echo isset($_POST['contenu']) ? safeHtml($_POST['contenu']) : ''; ?></textarea>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <div>
                                        <button type="button" class="btn btn-secondary" onclick="saveAsDraft()">
                                            <i class="fas fa-save"></i> Enregistrer brouillon
                                        </button>
                                        <button type="button" class="btn btn-warning" onclick="window.location.href='messagerie.php'">
                                            <i class="fas fa-times"></i> Annuler
                                        </button>
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Envoyer le message
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($action == 'view' && !empty($message_details)): ?>
            <!-- Visualisation d'un message -->
            <div class="row">
                <div class="col-md-12">
                    <div class="message-view">
                        <div class="message-header">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h4><?php echo safeHtml($message_details['sujet'] ?? ''); ?></h4>
                                    <div class="d-flex align-items-center">
                                        <?php if(!empty($message_details['expediteur_photo'])): ?>
                                        <img src="<?php echo safeHtml($message_details['expediteur_photo']); ?>" 
                                             alt="Photo" class="message-avatar me-2">
                                        <?php else: ?>
                                        <div class="message-avatar bg-secondary d-flex align-items-center justify-content-center me-2">
                                            <i class="fas fa-user text-white"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong>De: </strong><?php echo safeHtml($message_details['expediteur_nom'] ?? ''); ?><br>
                                            <small class="text-muted">À: <?php echo safeHtml($message_details['destinataire_nom'] ?? ''); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted"><?php echo formatDateTimeFr($message_details['date_envoi'] ?? ''); ?></small><br>
                                    <?php echo getStatutBadge($message_details['type_message'] ?? 'normal'); ?>
                                    <?php echo ($message_details['lu'] ?? 0) == 1 ? getStatutBadge('lu') : getStatutBadge('non_lu'); ?>
                                </div>
                            </div>
                            
                            <div class="btn-group">
                                <a href="messagerie.php?action=reply&id=<?php echo $message_id; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-reply"></i> Répondre
                                </a>
                                <a href="messagerie.php?action=compose&to=<?php echo $message_details['expediteur_id']; ?>" 
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-pen"></i> Nouveau message
                                </a>
                                <a href="messagerie.php?action=delete&id=<?php echo $message_id; ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Voulez-vous vraiment supprimer ce message ?')">
                                    <i class="fas fa-trash"></i> Supprimer
                                </a>
                                <button class="btn btn-sm btn-outline-info" onclick="window.print()">
                                    <i class="fas fa-print"></i> Imprimer
                                </button>
                            </div>
                        </div>
                        
                        <div class="message-content mb-4">
                            <?php echo nl2br(safeHtml($message_details['contenu'] ?? '')); ?>
                        </div>
                        
                        <?php if(!empty($message_details['date_lecture'])): ?>
                        <div class="alert alert-info mt-4">
                            <i class="fas fa-check-circle"></i> Message lu le <?php echo formatDateTimeFr($message_details['date_lecture']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <a href="messagerie.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Retour à la messagerie
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Liste des messages (inbox, sent, etc.) -->
            <div class="row">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-folder me-2"></i>
                                Dossiers
                            </h6>
                        </div>
                        <div class="list-group list-group-flush">
                            <a href="messagerie.php?action=inbox" 
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $action == 'inbox' ? 'active' : ''; ?>">
                                <div>
                                    <i class="fas fa-inbox me-2"></i>
                                    Boîte de réception
                                </div>
                                <?php if($statistiques['non_lus'] > 0): ?>
                                <span class="badge bg-primary rounded-pill"><?php echo $statistiques['non_lus']; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="messagerie.php?action=sent" 
                               class="list-group-item list-group-item-action <?php echo $action == 'sent' ? 'active' : ''; ?>">
                                <i class="fas fa-paper-plane me-2"></i>
                                Messages envoyés
                            </a>
                            <a href="messagerie.php?action=urgent" 
                               class="list-group-item list-group-item-action <?php echo $action == 'urgent' ? 'active' : ''; ?>">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Messages urgents
                            </a>
                            <a href="messagerie.php?action=compose" 
                               class="list-group-item list-group-item-action text-primary">
                                <i class="fas fa-pen me-2"></i>
                                Nouveau message
                            </a>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-users me-2"></i>
                                Contacts fréquents
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="recipient-select">
                                <?php if(empty($contacts)): ?>
                                <div class="alert alert-info">
                                    <small>Aucun contact disponible</small>
                                </div>
                                <?php else: ?>
                                    <?php 
                                    // Limiter à 5 contacts fréquents
                                    $frequent_contacts = array_slice($contacts, 0, 5);
                                    ?>
                                    <?php foreach($frequent_contacts as $contact): ?>
                                    <div class="contact-card" 
                                         onclick="window.location.href='messagerie.php?action=compose&to=<?php echo $contact['id']; ?>'">
                                        <div class="d-flex align-items-center">
                                            <?php if(!empty($contact['photo_profil'])): ?>
                                            <img src="<?php echo safeHtml($contact['photo_profil']); ?>" 
                                                 alt="Photo" class="contact-avatar me-3">
                                            <?php else: ?>
                                            <div class="contact-avatar bg-secondary d-flex align-items-center justify-content-center me-3">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-bold"><?php echo safeHtml(($contact['nom'] ?? '') . ' ' . ($contact['prenom'] ?? '')); ?></div>
                                                <div class="contact-type"><?php echo safeHtml($contact['type_contact'] ?? ''); ?></div>
                                                <?php if(!empty($contact['specialite'])): ?>
                                                <div class="contact-specialite"><?php echo safeHtml($contact['specialite']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-9">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <?php if($action == 'inbox'): ?>
                                <i class="fas fa-inbox me-2"></i>Boîte de réception
                                <?php elseif($action == 'sent'): ?>
                                <i class="fas fa-paper-plane me-2"></i>Messages envoyés
                                <?php elseif($action == 'urgent'): ?>
                                <i class="fas fa-exclamation-triangle me-2"></i>Messages urgents
                                <?php endif; ?>
                            </h5>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary" onclick="selectAllMessages()">
                                    <i class="fas fa-check-square"></i> Tout sélectionner
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteSelectedMessages()">
                                    <i class="fas fa-trash"></i> Supprimer
                                </button>
                                <button class="btn btn-sm btn-outline-primary" onclick="markAsRead()">
                                    <i class="fas fa-envelope-open"></i> Marquer comme lu
                                </button>
                            </div>
                        </div>
                        
                        <div class="card-body p-0">
                            <?php if(empty($messages)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-envelope fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun message</h5>
                                <p class="text-muted">
                                    <?php if($action == 'inbox'): ?>
                                    Votre boîte de réception est vide.
                                    <?php elseif($action == 'sent'): ?>
                                    Vous n'avez envoyé aucun message.
                                    <?php elseif($action == 'urgent'): ?>
                                    Vous n'avez aucun message urgent.
                                    <?php endif; ?>
                                </p>
                                <a href="messagerie.php?action=compose" class="btn btn-primary mt-2">
                                    <i class="fas fa-pen"></i> Écrire un message
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 30px;">
                                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                            </th>
                                            <th style="width: 40px;"></th>
                                            <th>De/À</th>
                                            <th>Sujet</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($messages as $msg): 
                                            $is_unread = ($action == 'inbox' && ($msg['lu'] ?? 0) == 0);
                                            $is_urgent = ($msg['type_message'] ?? '') == 'urgence';
                                            $sender_name = ($action == 'inbox' || $action == 'urgent') ? 
                                                          ($msg['expediteur_nom'] ?? '') : 
                                                          ($msg['destinataire_nom'] ?? '');
                                            $sender_photo = ($action == 'inbox' || $action == 'urgent') ? 
                                                           ($msg['expediteur_photo'] ?? '') : 
                                                           ($msg['destinataire_photo'] ?? '');
                                        ?>
                                        <tr class="message-item <?php echo $is_unread ? 'unread' : ''; ?> <?php echo $is_urgent ? 'urgent' : ''; ?>" 
                                            onclick="window.location.href='messagerie.php?action=view&id=<?php echo $msg['id']; ?>'">
                                            <td onclick="event.stopPropagation()">
                                                <input type="checkbox" class="message-checkbox" value="<?php echo $msg['id']; ?>" 
                                                       onchange="updateSelectAll()">
                                            </td>
                                            <td>
                                                <?php if(!empty($sender_photo)): ?>
                                                <img src="<?php echo safeHtml($sender_photo); ?>" 
                                                     alt="Photo" class="message-avatar">
                                                <?php else: ?>
                                                <div class="message-avatar bg-secondary d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold"><?php echo safeHtml($sender_name); ?></div>
                                                <?php if($is_urgent): ?>
                                                <span class="badge bg-danger">Urgent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold"><?php echo safeHtml($msg['sujet'] ?? ''); ?></div>
                                                <div class="message-preview"><?php echo safeHtml(substr($msg['contenu'] ?? '', 0, 100)); ?>...</div>
                                            </td>
                                            <td>
                                                <small><?php echo formatDateFr($msg['date_envoi'] ?? '', 'd/m H:i'); ?></small>
                                                <?php if($is_unread): ?>
                                                <br><span class="badge bg-primary">Nouveau</span>
                                                <?php endif; ?>
                                            </td>
                                            <td onclick="event.stopPropagation()">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="messagerie.php?action=view&id=<?php echo $msg['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Voir">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if($action == 'inbox'): ?>
                                                    <a href="messagerie.php?action=reply&id=<?php echo $msg['id']; ?>" 
                                                       class="btn btn-outline-success" title="Répondre">
                                                        <i class="fas fa-reply"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <a href="messagerie.php?action=delete&id=<?php echo $msg['id']; ?>" 
                                                       class="btn btn-outline-danger" 
                                                       onclick="event.stopPropagation(); return confirm('Voulez-vous vraiment supprimer ce message ?')"
                                                       title="Supprimer">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if(!empty($messages)): ?>
                        <div class="card-footer">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    Affichage de <?php echo count($messages); ?> message(s)
                                </small>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item disabled">
                                            <a class="page-link" href="#">Précédent</a>
                                        </li>
                                        <li class="page-item active">
                                            <a class="page-link" href="#">1</a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">2</a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">3</a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">Suivant</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section : Conseils de communication -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>
                                Conseils pour une bonne communication
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-user-tie"></i> Avec les professeurs</h6>
                                        <ul class="mb-0 small">
                                            <li>Utilisez un ton respectueux</li>
                                            <li>Soyez clair et concis</li>
                                            <li>Précisez votre classe et matière</li>
                                            <li>Joignez les pièces nécessaires</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="alert alert-warning">
                                        <h6><i class="fas fa-users"></i> Avec l'administration</h6>
                                        <ul class="mb-0 small">
                                            <li>Mentionnez votre matricule</li>
                                            <li>Précisez le service concerné</li>
                                            <li>Soyez patient pour les réponses</li>
                                            <li>Conservez une trace écrite</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="alert alert-success">
                                        <h6><i class="fas fa-comments"></i> Bonnes pratiques</h6>
                                        <ul class="mb-0 small">
                                            <li>Relisez avant d'envoyer</li>
                                            <li>Utilisez des sujets clairs</li>
                                            <li>Ne partagez pas d'infos sensibles</li>
                                            <li>Archivez les messages importants</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs5.min.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        // Mettre à jour l'attribut
        html.setAttribute('data-theme', newTheme);
        
        // Sauvegarder dans un cookie (30 jours)
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        // Mettre à jour le bouton
        const button = event.target.closest('button');
        if (button) {
            const icon = button.querySelector('i');
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Gestion des sélections de messages
    function selectAllMessages() {
        const checkboxes = document.querySelectorAll('.message-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        document.getElementById('selectAll').checked = true;
    }
    
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.message-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }
    
    function updateSelectAll() {
        const checkboxes = document.querySelectorAll('.message-checkbox');
        const selectAll = document.getElementById('selectAll');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        selectAll.checked = allChecked;
    }
    
    function deleteSelectedMessages() {
        const selected = Array.from(document.querySelectorAll('.message-checkbox:checked'))
                              .map(cb => cb.value);
        
        if (selected.length === 0) {
            alert('Veuillez sélectionner au moins un message à supprimer.');
            return;
        }
        
        if (confirm(`Voulez-vous vraiment supprimer ${selected.length} message(s) ?`)) {
            // Envoyer une requête AJAX pour supprimer les messages
            fetch('messagerie.php?action=delete_multiple', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ messages: selected })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur lors de la suppression: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Erreur lors de la suppression.');
            });
        }
    }
    
    function markAsRead() {
        const selected = Array.from(document.querySelectorAll('.message-checkbox:checked'))
                              .map(cb => cb.value);
        
        if (selected.length === 0) {
            alert('Veuillez sélectionner au moins un message à marquer comme lu.');
            return;
        }
        
        fetch('messagerie.php?action=mark_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ messages: selected })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Erreur: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Erreur lors du marquage des messages.');
        });
    }
    
    function saveAsDraft() {
        const form = document.getElementById('messageForm');
        const formData = new FormData(form);
        
        // Envoyer une requête pour sauvegarder le brouillon
        fetch('messagerie.php?action=save_draft', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Brouillon sauvegardé avec succès!');
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            } else {
                alert('Erreur: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Erreur lors de la sauvegarde du brouillon.');
        });
    }
    
    // Initialiser le thème et Summernote
    document.addEventListener('DOMContentLoaded', function() {
        // Récupérer le thème sauvegardé
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Mettre à jour le bouton
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Initialiser Summernote si présent
        if (typeof $('#contenu').summernote === 'function') {
            $('#contenu').summernote({
                height: 200,
                toolbar: [
                    ['style', ['bold', 'italic', 'underline', 'clear']],
                    ['font', ['strikethrough', 'superscript', 'subscript']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                lang: 'fr-FR'
            });
        }
        
        // Recherche en temps réel dans la liste des messages
        const searchInput = document.getElementById('messageSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.message-item');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        
        // Recherche dans les contacts
        const contactSearch = document.getElementById('contactSearch');
        if (contactSearch) {
            contactSearch.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const contacts = document.querySelectorAll('.contact-card');
                
                contacts.forEach(contact => {
                    const text = contact.textContent.toLowerCase();
                    contact.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
    });
    
    // Gestion des raccourcis clavier
    document.addEventListener('keydown', function(e) {
        // Ctrl + N : Nouveau message
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'messagerie.php?action=compose';
        }
        
        // Ctrl + R : Actualiser
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            location.reload();
        }
        
        // Ctrl + F : Rechercher
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('messageSearch') || 
                               document.getElementById('contactSearch');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
    </script>
</body>
</html>