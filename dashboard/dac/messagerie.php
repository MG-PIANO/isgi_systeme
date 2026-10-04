<?php
// dashboard/dac/messagerie.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle DAC (role_id = 5)
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier que l'utilisateur est bien un DAC
if ($_SESSION['role_id'] != 5) { // 5 = DAC
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
    $pageTitle = "Messagerie - Directeur Académique";
    
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
            case 'urgence':
                return '<span class="badge bg-danger">Urgent</span>';
            case 'annonce':
                return '<span class="badge bg-info">Annonce</span>';
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
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Directeur Académique';
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
    }
    
    // Récupérer l'ID de l'utilisateur et du site
    $user_id = SessionManager::getUserId();
    $site_id = SessionManager::getSiteId();
    
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
        'brouillons' => 0,
        'urgent' => 0,
        'parent' => 0
    );
    
    $error = null;
    $success = null;
    $sujet_reply = '';
    
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
    
    // Récupérer les informations du DAC
    $info_dac = executeSingleQuery($db, 
        "SELECT u.*, s.nom as site_nom
         FROM utilisateurs u
         JOIN sites s ON u.site_id = s.id
         WHERE u.id = ?", 
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
        
        // Messages urgents
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM messages 
             WHERE destinataire_id = ? AND type_message = 'urgence'",
            [$user_id]);
        $statistiques['urgent'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Messages en brouillon (table brouillons n'existe pas, on utilise 0)
        $statistiques['brouillons'] = 0;
        
        // Messages de parents (à calculer si on peut identifier les parents)
        $statistiques['parent'] = 0;
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
                            exp.role_id as expediteur_role,
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
                } else {
                    $error = "Message non trouvé ou vous n'avez pas accès à ce message.";
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
                    // Vérifier si c'est un ID de classe (préfixé par 'class_')
                    if (strpos($destinataire_id, 'class_') === 0) {
                        // C'est un message à une classe entière
                        $class_id = intval(str_replace('class_', '', $destinataire_id));
                        
                        // Récupérer tous les étudiants de cette classe
                        $etudiants = executeQuery($db,
                            "SELECT u.id 
                             FROM utilisateurs u
                             JOIN etudiants e ON u.id = e.utilisateur_id
                             WHERE e.classe_id = ? AND u.statut = 'actif'",
                            [$class_id]);
                        
                        if (!empty($etudiants)) {
                            $success_count = 0;
                            foreach ($etudiants as $etudiant) {
                                $stmt = $db->prepare("
                                    INSERT INTO messages (expediteur_id, destinataire_id, sujet, contenu, type_message, date_envoi)
                                    VALUES (?, ?, ?, ?, ?, NOW())
                                ");
                                
                                if ($stmt->execute([$user_id, $etudiant['id'], $sujet, $contenu, $type_message])) {
                                    $success_count++;
                                }
                            }
                            $success = "Message envoyé à $success_count étudiant(s) de la classe!";
                            $action = 'sent';
                        } else {
                            $error = "Aucun étudiant trouvé dans cette classe.";
                        }
                    } else {
                        // C'est un message à un utilisateur individuel
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
                    $sujet_reply = "Re: " . $message_details['sujet'];
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
            
        case 'mark_read':
            // Marquer un message comme lu
            if ($message_id > 0) {
                $stmt = $db->prepare("UPDATE messages SET lu = 1, date_lecture = NOW() WHERE id = ? AND destinataire_id = ?");
                if ($stmt->execute([$message_id, $user_id])) {
                    $success = "Message marqué comme lu!";
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
                        exp.photo_profil as expediteur_photo,
                        exp.role_id as expediteur_role
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
                        dest.photo_profil as destinataire_photo,
                        dest.role_id as destinataire_role
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
                        exp.photo_profil as expediteur_photo,
                        exp.role_id as expediteur_role
                 FROM messages m
                 JOIN utilisateurs exp ON m.expediteur_id = exp.id
                 WHERE m.destinataire_id = ? AND m.type_message = 'urgence'
                 ORDER BY m.date_envoi DESC
                 LIMIT 50",
                [$user_id]);
            break;
    }
    
    // Récupérer les contacts (étudiants, professeurs, administration, classes)
    if ($site_id) {
        // Étudiants du site
        $contacts_etudiants = executeQuery($db,
            "SELECT DISTINCT u.id, u.nom, u.prenom, u.email, u.photo_profil, 'Étudiant' as type_contact,
                    e.matricule, e.classe_id,
                    c.nom as classe_nom
             FROM utilisateurs u
             JOIN etudiants e ON u.id = e.utilisateur_id
             JOIN classes c ON e.classe_id = c.id
             WHERE u.site_id = ? 
             AND u.statut = 'actif'
             ORDER BY u.nom, u.prenom
             LIMIT 100",
            [$site_id]);
        
        // Professeurs du site
        $contacts_professeurs = executeQuery($db,
            "SELECT DISTINCT u.id, u.nom, u.prenom, u.email, u.photo_profil, 'Professeur' as type_contact
             FROM utilisateurs u
             WHERE u.site_id = ? 
             AND u.role_id = 3
             AND u.statut = 'actif'
             ORDER BY u.nom, u.prenom
             LIMIT 100",
            [$site_id]);
        
        // Administration du site
        $contacts_admin = executeQuery($db,
            "SELECT DISTINCT u.id, u.nom, u.prenom, u.email, u.photo_profil, 
                    CASE r.nom 
                        WHEN 'Administrateur Site' THEN 'Administration'
                        WHEN 'Gestionnaire Principal' THEN 'Service Financier'
                        WHEN 'DAC' THEN 'Affaires Académiques'
                        WHEN 'Surveillant Général' THEN 'Surveillance'
                        ELSE r.nom 
                    END as type_contact
             FROM utilisateurs u
             JOIN roles r ON u.role_id = r.id
             WHERE u.site_id = ? 
             AND u.statut = 'actif'
             AND r.id IN (1, 2, 4, 5, 6) -- Exclure le rôle étudiant (8) et professeur (3)
             AND u.id != ? -- Exclure l'utilisateur courant
             ORDER BY r.nom, u.nom, u.prenom
             LIMIT 100",
            [$site_id, $user_id]);
        
        // Classes (pour envoyer à toute une classe)
        $contacts_classes = executeQuery($db,
            "SELECT CONCAT('class_', c.id) as id, 
                    c.nom as prenom, 
                    '' as nom, 
                    '' as email, 
                    '' as photo_profil, 
                    'Classe' as type_contact,
                    CONCAT(f.nom, ' - Niveau ', n.libelle) as specialite,
                    COUNT(e.id) as nombre_etudiants
             FROM classes c
             JOIN filieres f ON c.filiere_id = f.id
             JOIN niveaux n ON c.niveau_id = n.id
             LEFT JOIN etudiants e ON c.id = e.classe_id AND e.statut = 'actif'
             WHERE c.site_id = ?
             GROUP BY c.id
             ORDER BY f.nom, c.nom
             LIMIT 50",
            [$site_id]);
        
        $contacts = array_merge($contacts_etudiants, $contacts_professeurs, $contacts_admin, $contacts_classes);
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . safeHtml($e->getMessage());
    error_log("Messagerie DAC erreur: " . $e->getMessage());
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
        --secondary-color: #17a2b8;
        --accent-color: #e74c3c;
        --success-color: #28a745;
        --warning-color: #ffc107;
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
        --primary-color: #17a2b8;
        --secondary-color: #138496;
        --accent-color: #e74c3c;
        --success-color: #28a745;
        --warning-color: #ffc107;
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
        background-color: rgba(23, 162, 184, 0.1);
    }
    
    .message-item.unread {
        border-left-color: var(--secondary-color);
        background-color: rgba(23, 162, 184, 0.05);
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
        background-color: rgba(23, 162, 184, 0.1);
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
    
    .badge-role {
        font-size: 0.7em;
        padding: 2px 6px;
        margin-left: 5px;
    }
    
    .badge-professeur { background-color: #6f42c1; }
    .badge-etudiant { background-color: #20c997; }
    .badge-parent { background-color: #fd7e14; }
    .badge-admin { background-color: #6610f2; }
    .badge-classe { background-color: #17a2b8; }
    
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
        box-shadow: 0 0 0 0.25rem rgba(23, 162, 184, 0.25);
    }
    
    /* Onglets */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--secondary-color);
        color: white;
        border-color: var(--secondary-color);
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
    
    /* Indicateur de rôle */
    .role-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 5px;
    }
    
    .role-professeur { background-color: #6f42c1; }
    .role-etudiant { background-color: #20c997; }
    .role-parent { background-color: #fd7e14; }
    .role-admin { background-color: #6610f2; }
    .role-classe { background-color: #17a2b8; }
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
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo safeHtml(SessionManager::getUserName()); ?></p>
                <?php if(isset($info_dac['site_nom']) && !empty($info_dac['site_nom'])): ?>
                <small>Site: <?php echo safeHtml($info_dac['site_nom']); ?></small>
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
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Étudiants</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                    <a href="cartes_etudiant.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Cartes étudiant</span>
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
                    <a href="annonces.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>Annonces</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Examens</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier examens</span>
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
                            Messagerie - Directeur Académique
                        </h2>
                        <p class="text-muted mb-0">
                            Gérez vos communications avec étudiants, professeurs et administration
                        </p>
                    </div>
                    <div class="btn-group">
                        <a href="messagerie.php?action=compose" class="btn btn-info">
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
                        <div class="text-info stat-icon">
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
                        <div class="text-warning stat-icon">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['envoyes']; ?></div>
                        <div class="stat-label">Messages envoyés</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['urgent']; ?></div>
                        <div class="stat-label">Messages urgents</div>
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
                                                ksort($grouped_contacts);
                                                ?>
                                                <?php foreach($grouped_contacts as $type => $type_contacts): ?>
                                                <optgroup label="<?php echo safeHtml($type); ?>">
                                                    <?php foreach($type_contacts as $contact): 
                                                        $display_name = '';
                                                        if ($type == 'Classe') {
                                                            $display_name = safeHtml($contact['prenom'] ?? '') . ' (' . ($contact['nombre_etudiants'] ?? 0) . ' étudiants)';
                                                        } else {
                                                            $display_name = safeHtml(($contact['nom'] ?? '') . ' ' . ($contact['prenom'] ?? ''));
                                                        }
                                                    ?>
                                                    <option value="<?php echo $contact['id']; ?>" 
                                                            data-type="<?php echo $type; ?>"
                                                            <?php echo ($destinataire_id == $contact['id']) ? 'selected' : ''; ?>>
                                                        <?php echo $display_name; ?>
                                                        <?php if(!empty($contact['specialite']) && $type != 'Classe'): ?>
                                                        - <?php echo safeHtml($contact['specialite']); ?>
                                                        <?php endif; ?>
                                                        <?php if($type == 'Classe' && !empty($contact['specialite'])): ?>
                                                        - <?php echo safeHtml($contact['specialite']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <small class="text-muted">Pour envoyer à une classe entière, sélectionnez une classe dans la section "Classe"</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="type_message" class="form-label">Type de message</label>
                                        <select class="form-select" id="type_message" name="type_message">
                                            <option value="normal">Normal</option>
                                            <option value="urgence">Urgent</option>
                                            <option value="annonce">Annonce</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sujet" class="form-label">Sujet <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="sujet" name="sujet" 
                                           value="<?php echo isset($_POST['sujet']) ? safeHtml($_POST['sujet']) : (isset($sujet_reply) ? safeHtml($sujet_reply) : ''); ?>" 
                                           placeholder="Sujet du message" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="contenu" class="form-label">Message <span class="text-danger">*</span></label>
                                    <textarea class="form-control compose-area" id="contenu" name="contenu" rows="10" required><?php echo isset($_POST['contenu']) ? safeHtml($_POST['contenu']) : ''; ?></textarea>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <div>
                                        <button type="button" class="btn btn-secondary" onclick="window.location.href='messagerie.php'">
                                            <i class="fas fa-times"></i> Annuler
                                        </button>
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-info">
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
                                            <strong>De: </strong><?php echo safeHtml($message_details['expediteur_nom'] ?? ''); ?>
                                            <?php 
                                            $role_id = $message_details['expediteur_role'] ?? 0;
                                            $role_badge = '';
                                            switch($role_id) {
                                                case 2: $role_badge = 'badge-admin'; $role_text = 'Admin'; break;
                                                case 3: $role_badge = 'badge-professeur'; $role_text = 'Professeur'; break;
                                                case 5: $role_badge = 'badge-admin'; $role_text = 'DAC'; break;
                                                case 6: $role_badge = 'badge-admin'; $role_text = 'Surveillant'; break;
                                                case 8: $role_badge = 'badge-etudiant'; $role_text = 'Étudiant'; break;
                                                default: $role_badge = 'badge-secondary'; $role_text = 'Utilisateur';
                                            }
                                            ?>
                                            <span class="badge <?php echo $role_badge; ?> badge-role"><?php echo $role_text; ?></span>
                                            <br>
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
                                <a href="messagerie.php?action=reply&id=<?php echo $message_id; ?>" class="btn btn-sm btn-outline-info">
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
                                <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
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
                                <?php if($statistiques['urgent'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?php echo $statistiques['urgent']; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="messagerie.php?action=compose" 
                               class="list-group-item list-group-item-action text-info">
                                <i class="fas fa-pen me-2"></i>
                                Nouveau message
                            </a>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-users me-2"></i>
                                Contacts
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <a href="messagerie.php?action=compose&filter=professeurs" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><span class="role-indicator role-professeur"></span> Professeurs</span>
                                    <span class="badge bg-purple">
                                        <?php echo count(array_filter($contacts, function($c) { return $c['type_contact'] == 'Professeur'; })); ?>
                                    </span>
                                </a>
                                <a href="messagerie.php?action=compose&filter=etudiants" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><span class="role-indicator role-etudiant"></span> Étudiants</span>
                                    <span class="badge bg-teal">
                                        <?php echo count(array_filter($contacts, function($c) { return $c['type_contact'] == 'Étudiant'; })); ?>
                                    </span>
                                </a>
                                <a href="messagerie.php?action=compose&filter=administration" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><span class="role-indicator role-admin"></span> Administration</span>
                                    <span class="badge bg-indigo">
                                        <?php echo count(array_filter($contacts, function($c) { return $c['type_contact'] == 'Administration'; })); ?>
                                    </span>
                                </a>
                                <a href="messagerie.php?action=compose&filter=classes" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><span class="role-indicator role-classe"></span> Classes</span>
                                    <span class="badge bg-info">
                                        <?php echo count(array_filter($contacts, function($c) { return $c['type_contact'] == 'Classe'; })); ?>
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-search me-2"></i>
                                Recherche
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="input-group">
                                <input type="text" id="messageSearch" class="form-control" placeholder="Rechercher...">
                                <button class="btn btn-outline-info" type="button">
                                    <i class="fas fa-search"></i>
                                </button>
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
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteSelectedMessages()">
                                    <i class="fas fa-trash"></i> Supprimer
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="markAsRead()">
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
                                <a href="messagerie.php?action=compose" class="btn btn-info mt-2">
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
                                            $role_id = ($action == 'inbox' || $action == 'urgent') ? 
                                                      ($msg['expediteur_role'] ?? 0) : 
                                                      ($msg['destinataire_role'] ?? 0);
                                            
                                            $role_badge = '';
                                            switch($role_id) {
                                                case 2: $role_badge = 'badge-admin'; $role_text = 'Admin'; break;
                                                case 3: $role_badge = 'badge-professeur'; $role_text = 'Prof'; break;
                                                case 5: $role_badge = 'badge-admin'; $role_text = 'DAC'; break;
                                                case 6: $role_badge = 'badge-admin'; $role_text = 'Surv'; break;
                                                case 8: $role_badge = 'badge-etudiant'; $role_text = 'Étud'; break;
                                                default: $role_badge = 'badge-secondary'; $role_text = 'User';
                                            }
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
                                                <span class="badge <?php echo $role_badge; ?> badge-role"><?php echo $role_text; ?></span>
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
                                                       class="btn btn-outline-info" title="Voir">
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
            // Pour chaque message sélectionné, rediriger vers l'action delete
            selected.forEach(id => {
                window.open(`messagerie.php?action=delete&id=${id}`, '_blank');
            });
            // Recharger la page après un court délai
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
    }
    
    function markAsRead() {
        const selected = Array.from(document.querySelectorAll('.message-checkbox:checked'))
                              .map(cb => cb.value);
        
        if (selected.length === 0) {
            alert('Veuillez sélectionner au moins un message à marquer comme lu.');
            return;
        }
        
        // Pour chaque message sélectionné, marquer comme lu
        selected.forEach(id => {
            window.open(`messagerie.php?action=mark_read&id=${id}`, '_blank');
        });
        // Recharger la page après un court délai
        setTimeout(() => {
            location.reload();
        }, 1000);
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
                    ['insert', ['link']],
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
        
        // Gérer le filtre des contacts
        const urlParams = new URLSearchParams(window.location.search);
        const filter = urlParams.get('filter');
        const destinataireSelect = document.getElementById('destinataire_id');
        
        if (destinataireSelect && filter) {
            // Montrer seulement les contacts du type filtré
            Array.from(destinataireSelect.options).forEach(option => {
                const dataType = option.getAttribute('data-type');
                if (dataType && dataType.toLowerCase().includes(filter.toLowerCase())) {
                    option.style.display = '';
                } else if (option.value && dataType) {
                    option.style.display = 'none';
                }
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
            const searchInput = document.getElementById('messageSearch');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
    </script>
</body>
</html>