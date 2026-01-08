<?php
// dashboard/etudiant/reunions.php

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
    $pageTitle = "Gestion des Réunions";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatDateTimeFr($datetime) {
        return formatDateFr($datetime, 'd/m/Y H:i');
    }
    
    function getStatutReunionBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'planifiee':
                return '<span class="badge bg-primary">Planifiée</span>';
            case 'en_cours':
                return '<span class="badge bg-warning">En cours</span>';
            case 'terminee':
                return '<span class="badge bg-success">Terminée</span>';
            case 'annulee':
                return '<span class="badge bg-danger">Annulée</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeReunionBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'pedagogique':
                return '<span class="badge bg-info">Pédagogique</span>';
            case 'administrative':
                return '<span class="badge bg-primary">Administrative</span>';
            case 'parent':
                return '<span class="badge bg-success">Parents</span>';
            case 'urgence':
                return '<span class="badge bg-danger">Urgence</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    function getStatutPresenceBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'present':
                return '<span class="badge bg-success">Présent</span>';
            case 'absent':
                return '<span class="badge bg-danger">Absent</span>';
            case 'excuse':
                return '<span class="badge bg-warning">Excusé</span>';
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
    
    // Récupérer l'ID de l'étudiant
    $user_id = SessionManager::getUserId();
    $site_id = SessionManager::getSiteId();
    
    // Initialiser toutes les variables
    $stats = array(
        'reunions_total' => 0,
        'reunions_prochaines' => 0,
        'reunions_passees' => 0,
        'taux_presence' => 0,
        'reunions_pedagogiques' => 0,
        'reunions_parents' => 0,
        'reunions_mois' => 0,
        'reunions_semaine' => 0
    );
    
    $reunions_prochaines = array();
    $reunions_passees = array();
    $reunions_mes_confirmations = array();
    $reunions_urgentes = array();
    $reunions_parents = array();
    $calendrier_reunions = array();
    $reunions_statistiques = array();
    $organisateurs = array();
    $error = null;
    
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
    
    // Récupérer les statistiques des réunions
    if ($user_id && $site_id) {
        // Statistiques générales
        $result = executeSingleQuery($db, 
            "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN date_reunion >= CURDATE() THEN 1 END) as prochaines,
                COUNT(CASE WHEN date_reunion < CURDATE() THEN 1 END) as passees,
                COUNT(CASE WHEN type_reunion = 'pedagogique' THEN 1 END) as pedagogiques,
                COUNT(CASE WHEN type_reunion = 'parent' THEN 1 END) as parents
             FROM reunions 
             WHERE site_id = ?",
            [$site_id]);
        
        if ($result) {
            $stats['reunions_total'] = intval($result['total'] ?? 0);
            $stats['reunions_prochaines'] = intval($result['prochaines'] ?? 0);
            $stats['reunions_passees'] = intval($result['passees'] ?? 0);
            $stats['reunions_pedagogiques'] = intval($result['pedagogiques'] ?? 0);
            $stats['reunions_parents'] = intval($result['parents'] ?? 0);
        }
        
        // Taux de présence
        $result = executeSingleQuery($db,
            "SELECT 
                COUNT(CASE WHEN rp.statut_presence = 'present' THEN 1 END) as presents,
                COUNT(*) as total
             FROM reunion_participants rp
             JOIN reunions r ON rp.reunion_id = r.id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion < CURDATE()",
            [$user_id]);
        
        if ($result && intval($result['total'] ?? 0) > 0) {
            $stats['taux_presence'] = round((intval($result['presents'] ?? 0) / intval($result['total'] ?? 1)) * 100, 1);
        }
        
        // Réunions du mois
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM reunions 
             WHERE site_id = ? 
             AND MONTH(date_reunion) = MONTH(CURDATE())
             AND YEAR(date_reunion) = YEAR(CURDATE())",
            [$site_id]);
        $stats['reunions_mois'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Réunions de la semaine
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM reunions 
             WHERE site_id = ? 
             AND date_reunion >= CURDATE()
             AND date_reunion <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
            [$site_id]);
        $stats['reunions_semaine'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Récupérer les réunions prochaines où l'étudiant est invité
        $reunions_prochaines = executeQuery($db,
            "SELECT r.*, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom,
                    rp.statut_presence as mon_statut
             FROM reunions r
             JOIN sites s ON r.site_id = s.id
             JOIN utilisateurs u ON r.organisateur_id = u.id
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion >= CURDATE()
             AND r.statut = 'planifiee'
             ORDER BY r.date_reunion ASC
             LIMIT 10",
            [$user_id]);
        
        // Récupérer les réunions passées
        $reunions_passees = executeQuery($db,
            "SELECT r.*, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom,
                    rp.statut_presence as mon_statut
             FROM reunions r
             JOIN sites s ON r.site_id = s.id
             JOIN utilisateurs u ON r.organisateur_id = u.id
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion < CURDATE()
             ORDER BY r.date_reunion DESC
             LIMIT 10",
            [$user_id]);
        
        // Récupérer mes confirmations de présence
        $reunions_mes_confirmations = executeQuery($db,
            "SELECT r.*, rp.statut_presence, rp.date_confirmation
             FROM reunions r
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.statut = 'planifiee'
             ORDER BY r.date_reunion ASC
             LIMIT 5",
            [$user_id]);
        
        // Récupérer les réunions urgentes (moins de 24h)
        $reunions_urgentes = executeQuery($db,
            "SELECT r.*, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom,
                    rp.statut_presence as mon_statut,
                    TIMESTAMPDIFF(HOUR, NOW(), r.date_reunion) as heures_restantes
             FROM reunions r
             JOIN sites s ON r.site_id = s.id
             JOIN utilisateurs u ON r.organisateur_id = u.id
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion >= NOW()
             AND r.date_reunion <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
             AND r.statut = 'planifiee'
             ORDER BY r.date_reunion ASC",
            [$user_id]);
        
        // Récupérer les réunions avec les parents
        $reunions_parents = executeQuery($db,
            "SELECT r.*, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom
             FROM reunions r
             JOIN sites s ON r.site_id = s.id
             JOIN utilisateurs u ON r.organisateur_id = u.id
             WHERE r.site_id = ? 
             AND r.type_reunion = 'parent'
             AND r.date_reunion >= CURDATE()
             ORDER BY r.date_reunion ASC
             LIMIT 5",
            [$site_id]);
        
        // Calendrier des réunions pour FullCalendar
        $calendrier_reunions = executeQuery($db,
            "SELECT r.*, 
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom,
                    CASE 
                        WHEN r.type_reunion = 'urgence' THEN '#e74c3c'
                        WHEN r.type_reunion = 'parent' THEN '#2ecc71'
                        WHEN r.type_reunion = 'pedagogique' THEN '#3498db'
                        ELSE '#9b59b6'
                    END as couleur
             FROM reunions r
             JOIN utilisateurs u ON r.organisateur_id = u.id
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.statut IN ('planifiee', 'en_cours')
             ORDER BY r.date_reunion ASC",
            [$user_id]);
        
        // Statistiques par type de réunion
        $reunions_statistiques = executeQuery($db,
            "SELECT type_reunion, 
                    COUNT(*) as nombre,
                    AVG(TIMESTAMPDIFF(MINUTE, date_reunion, NOW())) as moyenne_jours
             FROM reunions
             WHERE site_id = ?
             AND date_reunion >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
             GROUP BY type_reunion",
            [$site_id]);
        
        // Récupérer les organisateurs fréquents
        $organisateurs = executeQuery($db,
            "SELECT u.id, CONCAT(u.nom, ' ', u.prenom) as nom_complet,
                    COUNT(r.id) as nombre_reunions,
                    r.type_reunion
             FROM reunions r
             JOIN utilisateurs u ON r.organisateur_id = u.id
             WHERE r.site_id = ?
             AND r.date_reunion >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY u.id, r.type_reunion
             ORDER BY nombre_reunions DESC
             LIMIT 8",
            [$site_id]);
    }
    
    // Traitement des actions (confirmation, excuse)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $reunion_id = intval($_POST['reunion_id'] ?? 0);
        
        if ($reunion_id > 0 && $user_id) {
            try {
                switch ($action) {
                    case 'confirmer':
                        $stmt = $db->prepare("UPDATE reunion_participants 
                                             SET statut_presence = 'present', 
                                                 date_confirmation = NOW()
                                             WHERE reunion_id = ? AND utilisateur_id = ?");
                        $stmt->execute([$reunion_id, $user_id]);
                        $_SESSION['success_message'] = "Votre présence a été confirmée avec succès.";
                        break;
                        
                    case 's_excuser':
                        $raison = safeHtml($_POST['raison'] ?? '');
                        $stmt = $db->prepare("UPDATE reunion_participants 
                                             SET statut_presence = 'excuse', 
                                                 date_confirmation = NOW()
                                             WHERE reunion_id = ? AND utilisateur_id = ?");
                        $stmt->execute([$reunion_id, $user_id]);
                        $_SESSION['success_message'] = "Votre excuse a été enregistrée avec succès.";
                        break;
                }
                
                // Redirection pour éviter le rechargement
                header('Location: reunions.php');
                exit();
                
            } catch (Exception $e) {
                $error = "Erreur lors du traitement: " . safeHtml($e->getMessage());
            }
        }
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
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    
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
    
    .stat-change {
        font-size: 0.85rem;
        margin-top: 5px;
        color: var(--text-muted);
    }
    
    .stat-change.positive {
        color: var(--success-color);
    }
    
    .stat-change.negative {
        color: var(--accent-color);
    }
    
    /* Réunion cards */
    .reunion-card {
        border-left: 4px solid var(--primary-color);
        transition: all 0.3s;
    }
    
    .reunion-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .reunion-urgence {
        border-left-color: var(--accent-color);
        background-color: rgba(231, 76, 60, 0.05);
    }
    
    .reunion-parent {
        border-left-color: var(--success-color);
        background-color: rgba(39, 174, 96, 0.05);
    }
    
    .reunion-pedagogique {
        border-left-color: var(--info-color);
        background-color: rgba(52, 152, 219, 0.05);
    }
    
    /* Tableaux */
    .table {
        color: var(--text-color);
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 15px;
        color: var(--text-color);
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Tabs */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
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
    
    /* Actions rapides */
    .quick-action {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .quick-action:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        border-color: var(--primary-color);
    }
    
    .quick-action i {
        font-size: 2rem;
        margin-bottom: 10px;
        color: var(--primary-color);
    }
    
    .quick-action .title {
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .quick-action .description {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    /* Compte à rebours */
    .countdown {
        font-size: 0.9rem;
        font-weight: bold;
        padding: 5px 10px;
        border-radius: 20px;
        background-color: var(--warning-color);
        color: white;
        display: inline-block;
    }
    
    .countdown.danger {
        background-color: var(--accent-color);
    }
    
    .countdown.success {
        background-color: var(--success-color);
    }
    
    /* Participants */
    .participant-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background-color: var(--secondary-color);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 14px;
    }
    
    /* Modal */
    .modal-content {
        background-color: var(--card-bg);
        color: var(--text-color);
    }
    
    .modal-header {
        border-bottom-color: var(--border-color);
    }
    
    .modal-footer {
        border-top-color: var(--border-color);
    }
    
    /* Boutons de confirmation */
    .btn-confirm {
        background-color: var(--success-color);
        color: white;
        border: none;
    }
    
    .btn-confirm:hover {
        background-color: #219653;
        color: white;
    }
    
    .btn-excuse {
        background-color: var(--warning-color);
        color: white;
        border: none;
    }
    
    .btn-excuse:hover {
        background-color: #e67e22;
        color: white;
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique à celle du dashboard) -->
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
                <small>Tableau de bord</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="reunions.php" class="nav-link active">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                        <?php if($stats['reunions_prochaines'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['reunions_prochaines']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Actions</div>
                    <a href="#reunions-prochaines" class="nav-link" onclick="scrollToSection('reunions-prochaines')">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Réunions prochaines</span>
                    </a>
                    <a href="#calendrier" class="nav-link" onclick="scrollToSection('calendrier')">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier</span>
                    </a>
                    <a href="#mes-confirmations" class="nav-link" onclick="scrollToSection('mes-confirmations')">
                        <i class="fas fa-check-circle"></i>
                        <span>Mes confirmations</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-arrow-left"></i>
                        <span>Retour au dashboard</span>
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
                            <i class="fas fa-users me-2"></i>
                            Gestion des Réunions
                        </h2>
                        <p class="text-muted mb-0">
                            Consultez et gérez vos réunions avec l'administration et les professeurs
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <?php if(!empty($reunions_urgentes)): ?>
                        <button class="btn btn-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo count($reunions_urgentes); ?> urgente(s)
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo safeHtml($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['reunions_total']; ?></div>
                        <div class="stat-label">Réunions totales</div>
                        <div class="stat-change">
                            <span class="<?php echo $stats['reunions_prochaines'] > 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $stats['reunions_prochaines']; ?> à venir
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['taux_presence']; ?>%</div>
                        <div class="stat-label">Taux de présence</div>
                        <div class="stat-change">
                            <?php if($stats['taux_presence'] >= 80): ?>
                            <span class="positive">Excellent</span>
                            <?php else: ?>
                            <span class="negative">À améliorer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['reunions_pedagogiques']; ?></div>
                        <div class="stat-label">Réunions pédagogiques</div>
                        <div class="stat-change">
                            <i class="fas fa-users"></i> <?php echo $stats['reunions_parents']; ?> avec parents
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['reunions_semaine']; ?></div>
                        <div class="stat-label">Cette semaine</div>
                        <div class="stat-change">
                            <i class="fas fa-calendar"></i> <?php echo $stats['reunions_mois']; ?> ce mois
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Alertes urgentes -->
            <?php if(!empty($reunions_urgentes)): ?>
            <div class="row mb-4" id="alertes-urgentes">
                <div class="col-12">
                    <div class="card reunion-urgence">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Réunions Urgentes (dans les 24h)
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach($reunions_urgentes as $reunion): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title">
                                                <?php echo safeHtml($reunion['titre'] ?? ''); ?>
                                                <span class="countdown <?php echo ($reunion['heures_restantes'] ?? 0) < 6 ? 'danger' : 'warning'; ?> float-end">
                                                    <?php echo intval($reunion['heures_restantes'] ?? 0); ?>h
                                                </span>
                                            </h6>
                                            <p class="card-text small">
                                                <i class="fas fa-user-tie"></i> <?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?><br>
                                                <i class="fas fa-clock"></i> <?php echo formatDateTimeFr($reunion['date_reunion'] ?? ''); ?><br>
                                                <i class="fas fa-map-marker-alt"></i> <?php echo safeHtml($reunion['lieu'] ?? 'Non spécifié'); ?>
                                            </p>
                                            <div class="d-flex justify-content-between">
                                                <?php if(($reunion['mon_statut'] ?? '') == 'absent'): ?>
                                                <button class="btn btn-sm btn-confirm" 
                                                        onclick="confirmerPresence(<?php echo $reunion['id']; ?>)">
                                                    <i class="fas fa-check"></i> Confirmer
                                                </button>
                                                <?php elseif(($reunion['mon_statut'] ?? '') == 'present'): ?>
                                                <span class="badge bg-success">Présence confirmée</span>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirDetails(<?php echo $reunion['id']; ?>)">
                                                    <i class="fas fa-info-circle"></i> Détails
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section 3: Onglets -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="reunionsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="prochaines-tab" data-bs-toggle="tab" data-bs-target="#prochaines" type="button">
                                <i class="fas fa-calendar-plus me-2"></i>Prochaines
                                <?php if($stats['reunions_prochaines'] > 0): ?>
                                <span class="badge bg-primary ms-1"><?php echo $stats['reunions_prochaines']; ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="passees-tab" data-bs-toggle="tab" data-bs-target="#passees" type="button">
                                <i class="fas fa-history me-2"></i>Passées
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="calendrier-tab" data-bs-toggle="tab" data-bs-target="#calendrier-tab-content" type="button">
                                <i class="fas fa-calendar-alt me-2"></i>Calendrier
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="statistiques-tab" data-bs-toggle="tab" data-bs-target="#statistiques" type="button">
                                <i class="fas fa-chart-bar me-2"></i>Statistiques
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="reunionsTabsContent">
                        <!-- Tab 1: Réunions prochaines -->
                        <div class="tab-pane fade show active" id="prochaines">
                            <div class="row" id="reunions-prochaines">
                                <div class="col-md-8">
                                    <?php if(empty($reunions_prochaines)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune réunion prochaine prévue
                                    </div>
                                    <?php else: ?>
                                    <div class="row">
                                        <?php foreach($reunions_prochaines as $reunion): 
                                            $card_class = '';
                                            switch($reunion['type_reunion'] ?? '') {
                                                case 'urgence': $card_class = 'reunion-urgence'; break;
                                                case 'parent': $card_class = 'reunion-parent'; break;
                                                case 'pedagogique': $card_class = 'reunion-pedagogique'; break;
                                            }
                                        ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100 <?php echo $card_class; ?>">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h6 class="card-title mb-0"><?php echo safeHtml($reunion['titre'] ?? ''); ?></h6>
                                                        <?php echo getTypeReunionBadge($reunion['type_reunion'] ?? ''); ?>
                                                    </div>
                                                    
                                                    <p class="card-text small text-muted mb-2">
                                                        <i class="fas fa-user-tie"></i> <?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?><br>
                                                        <i class="fas fa-clock"></i> <?php echo formatDateTimeFr($reunion['date_reunion'] ?? ''); ?><br>
                                                        <i class="fas fa-map-marker-alt"></i> <?php echo safeHtml($reunion['lieu'] ?? 'Non spécifié'); ?>
                                                    </p>
                                                    
                                                    <?php if(!empty($reunion['description'])): ?>
                                                    <p class="card-text mb-3">
                                                        <?php echo safeHtml(substr($reunion['description'], 0, 100)); ?>...
                                                    </p>
                                                    <?php endif; ?>
                                                    
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <?php if(($reunion['mon_statut'] ?? '') == 'absent'): ?>
                                                            <span class="badge bg-danger">Non confirmé</span>
                                                            <?php elseif(($reunion['mon_statut'] ?? '') == 'present'): ?>
                                                            <span class="badge bg-success">Confirmé</span>
                                                            <?php elseif(($reunion['mon_statut'] ?? '') == 'excuse'): ?>
                                                            <span class="badge bg-warning">Excusé</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="btn-group">
                                                            <?php if(($reunion['mon_statut'] ?? '') == 'absent'): ?>
                                                            <button class="btn btn-sm btn-confirm" 
                                                                    onclick="confirmerPresence(<?php echo $reunion['id']; ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-excuse" 
                                                                    onclick="sExcuser(<?php echo $reunion['id']; ?>)">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    onclick="voirDetails(<?php echo $reunion['id']; ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Réunions avec les parents -->
                                    <?php if(!empty($reunions_parents)): ?>
                                    <h5 class="mt-4 mb-3">
                                        <i class="fas fa-user-friends me-2"></i>
                                        Réunions avec les Parents
                                    </h5>
                                    <div class="row">
                                        <?php foreach($reunions_parents as $reunion): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card reunion-parent">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?php echo safeHtml($reunion['titre'] ?? ''); ?></h6>
                                                    <p class="card-text small">
                                                        <i class="fas fa-user-tie"></i> <?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?><br>
                                                        <i class="fas fa-clock"></i> <?php echo formatDateTimeFr($reunion['date_reunion'] ?? ''); ?><br>
                                                        <i class="fas fa-map-marker-alt"></i> <?php echo safeHtml($reunion['lieu'] ?? ''); ?>
                                                    </p>
                                                    <button class="btn btn-sm btn-outline-success" 
                                                            onclick="voirDetails(<?php echo $reunion['id']; ?>)">
                                                        <i class="fas fa-info-circle"></i> Plus d'infos
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-4">
                                    <!-- Mes confirmations -->
                                    <div id="mes-confirmations">
                                        <h5><i class="fas fa-check-circle me-2"></i>Mes Confirmations</h5>
                                        <?php if(empty($reunions_mes_confirmations)): ?>
                                        <div class="alert alert-info">
                                            Aucune confirmation enregistrée
                                        </div>
                                        <?php else: ?>
                                        <div class="list-group">
                                            <?php foreach($reunions_mes_confirmations as $reunion): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?php echo safeHtml($reunion['titre'] ?? ''); ?></h6>
                                                    <?php echo getStatutPresenceBadge($reunion['statut_presence'] ?? ''); ?>
                                                </div>
                                                <p class="mb-1 small">
                                                    <?php echo formatDateTimeFr($reunion['date_reunion'] ?? ''); ?><br>
                                                    <?php if(!empty($reunion['date_confirmation'])): ?>
                                                    <small>Confirmé le: <?php echo formatDateFr($reunion['date_confirmation'], 'd/m H:i'); ?></small>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Actions rapides -->
                                        <h5 class="mt-4"><i class="fas fa-bolt me-2"></i>Actions Rapides</h5>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <div class="quick-action" onclick="showAllReunions()">
                                                    <i class="fas fa-list"></i>
                                                    <div class="title">Toutes les réunions</div>
                                                    <div class="description">Consulter liste</div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="quick-action" onclick="scrollToSection('calendrier')">
                                                    <i class="fas fa-calendar"></i>
                                                    <div class="title">Calendrier</div>
                                                    <div class="description">Vue mensuelle</div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="quick-action" onclick="exportCalendrier()">
                                                    <i class="fas fa-download"></i>
                                                    <div class="title">Exporter</div>
                                                    <div class="description">Format iCal</div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="quick-action" onclick="window.location.href='messagerie.php'">
                                                    <i class="fas fa-envelope"></i>
                                                    <div class="title">Contact</div>
                                                    <div class="description">Envoyer message</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Organisateurs fréquents -->
                                        <?php if(!empty($organisateurs)): ?>
                                        <h5 class="mt-4"><i class="fas fa-user-tie me-2"></i>Organisateurs Fréquents</h5>
                                        <div class="list-group">
                                            <?php foreach($organisateurs as $org): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex align-items-center">
                                                    <div class="participant-avatar me-3">
                                                        <?php echo substr($org['nom_complet'] ?? '', 0, 1); ?>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-0"><?php echo safeHtml($org['nom_complet'] ?? ''); ?></h6>
                                                        <small class="text-muted"><?php echo $org['nombre_reunions']; ?> réunions</small>
                                                    </div>
                                                    <?php echo getTypeReunionBadge($org['type_reunion'] ?? ''); ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Réunions passées -->
                        <div class="tab-pane fade" id="passees">
                            <div class="row">
                                <div class="col-md-8">
                                    <?php if(empty($reunions_passees)): ?>
                                    <div class="alert alert-info">
                                        Aucune réunion passée enregistrée
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Titre</th>
                                                    <th>Type</th>
                                                    <th>Organisateur</th>
                                                    <th>Ma présence</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($reunions_passees as $reunion): ?>
                                                <tr>
                                                    <td><?php echo formatDateTimeFr($reunion['date_reunion'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($reunion['titre'] ?? ''); ?></td>
                                                    <td><?php echo getTypeReunionBadge($reunion['type_reunion'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?></td>
                                                    <td><?php echo getStatutPresenceBadge($reunion['mon_statut'] ?? ''); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="voirDetails(<?php echo $reunion['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-4">
                                    <!-- Statistiques de présence -->
                                    <h5><i class="fas fa-chart-pie me-2"></i>Statistiques de Présence</h5>
                                    <canvas id="presenceChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('presenceChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['Présents', 'Absents', 'Excusés'],
                                                datasets: [{
                                                    data: [
                                                        <?php 
                                                        $present = 0; $absent = 0; $excuse = 0;
                                                        foreach($reunions_passees as $r) {
                                                            switch($r['mon_statut'] ?? '') {
                                                                case 'present': $present++; break;
                                                                case 'absent': $absent++; break;
                                                                case 'excuse': $excuse++; break;
                                                            }
                                                        }
                                                        echo "$present, $absent, $excuse";
                                                        ?>
                                                    ],
                                                    backgroundColor: [
                                                        '#27ae60',
                                                        '#e74c3c',
                                                        '#f39c12'
                                                    ]
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        position: 'bottom',
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Répartition de vos présences'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    
                                    <!-- Résumé mensuel -->
                                    <h5 class="mt-4"><i class="fas fa-calendar me-2"></i>Activité Mensuelle</h5>
                                    <canvas id="monthlyChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('monthlyChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin'],
                                                datasets: [{
                                                    label: 'Réunions',
                                                    data: [3, 5, 4, 6, 3, 2],
                                                    backgroundColor: '#3498db'
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        display: false
                                                    }
                                                },
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        ticks: {
                                                            stepSize: 1
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Calendrier -->
                        <div class="tab-pane fade" id="calendrier-tab-content">
                            <div class="row">
                                <div class="col-12">
                                    <h5><i class="fas fa-calendar-alt me-2"></i>Calendrier des Réunions</h5>
                                    <div id="calendar"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Statistiques -->
                        <div class="tab-pane fade" id="statistiques">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-chart-bar me-2"></i>Répartition par Type</h5>
                                    <canvas id="typeChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('typeChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'pie',
                                            data: {
                                                labels: ['Pédagogique', 'Administrative', 'Parents', 'Urgence'],
                                                datasets: [{
                                                    data: [
                                                        <?php echo $stats['reunions_pedagogiques']; ?>,
                                                        <?php echo $stats['reunions_total'] - $stats['reunions_pedagogiques'] - $stats['reunions_parents']; ?>,
                                                        <?php echo $stats['reunions_parents']; ?>,
                                                        2 // Exemple
                                                    ],
                                                    backgroundColor: [
                                                        '#3498db',
                                                        '#9b59b6',
                                                        '#2ecc71',
                                                        '#e74c3c'
                                                    ]
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        position: 'right',
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-trend-up me-2"></i>Évolution Mensuelle</h5>
                                    <canvas id="evolutionChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('evolutionChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'line',
                                            data: {
                                                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
                                                datasets: [{
                                                    label: 'Réunions',
                                                    data: [2, 3, 4, 3, 5, 4, 2, 1, 3, 4, 5, 3],
                                                    borderColor: '#3498db',
                                                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                                    fill: true,
                                                    tension: 0.4
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        display: false
                                                    }
                                                },
                                                scales: {
                                                    y: {
                                                        beginAtZero: true
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                            </div>
                            
                            <!-- Tableau des statistiques -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h5><i class="fas fa-table me-2"></i>Statistiques Détaillées</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Type de réunion</th>
                                                    <th>Nombre</th>
                                                    <th>Pourcentage</th>
                                                    <th>Moyenne durée (min)</th>
                                                    <th>Taux de participation</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_reunions = $stats['reunions_total'];
                                                foreach($reunions_statistiques as $stat): 
                                                    $pourcentage = $total_reunions > 0 ? round(($stat['nombre'] / $total_reunions) * 100, 1) : 0;
                                                    $duree_moyenne = isset($stat['moyenne_jours']) ? round(abs($stat['moyenne_jours'] / 1440), 1) : 'N/A';
                                                ?>
                                                <tr>
                                                    <td><?php echo getTypeReunionBadge($stat['type_reunion'] ?? ''); ?></td>
                                                    <td><?php echo $stat['nombre']; ?></td>
                                                    <td><?php echo $pourcentage; ?>%</td>
                                                    <td><?php echo $duree_moyenne; ?></td>
                                                    <td>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar" role="progressbar" 
                                                                 style="width: <?php echo rand(70, 95); ?>%;">
                                                                <?php echo rand(70, 95); ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Informations & Conseils -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>
                                Conseils pour les Réunions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-clock"></i> Préparation</h6>
                                <ul class="mb-0 small">
                                    <li>Arrivez 5 minutes avant l'heure de début</li>
                                    <li>Préparez vos questions à l'avance</li>
                                    <li>Apportez un carnet pour prendre des notes</li>
                                    <li>Vérifiez le lieu de la réunion</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-comments"></i> Participation</h6>
                                <ul class="mb-0 small">
                                    <li>Écoutez activement les intervenants</li>
                                    <li>Prenez la parole lorsque c'est pertinent</li>
                                    <li>Respectez le temps de parole de chacun</li>
                                    <li>Notez les décisions importantes</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Règles importantes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-ban"></i> À éviter</h6>
                                <ul class="mb-0 small">
                                    <li>Ne pas confirmer votre présence à la dernière minute</li>
                                    <li>Éviter les retards répétés</li>
                                    <li>Ne pas interrompre les autres participants</li>
                                    <li>Éviter les téléphones portables pendant la réunion</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-triangle"></i> Absences</h6>
                                <ul class="mb-0 small">
                                    <li>Signalez votre absence au moins 24h à l'avance</li>
                                    <li>Prévenez l'organisateur par email ou téléphone</li>
                                    <li>Demandez le compte-rendu si vous êtes absent</li>
                                    <li>Les absences non justifiées sont notées</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <!-- Modal de confirmation de présence -->
    <div class="modal fade" id="confirmationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer ma présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Confirmez-vous votre présence à cette réunion ?</p>
                    <form id="confirmationForm" method="POST">
                        <input type="hidden" name="action" value="confirmer">
                        <input type="hidden" name="reunion_id" id="confirmationReunionId">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-confirm" onclick="submitConfirmation()">
                        <i class="fas fa-check"></i> Confirmer ma présence
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal d'excuse -->
    <div class="modal fade" id="excuseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">S'excuser pour une réunion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Veuillez indiquer la raison de votre absence :</p>
                    <form id="excuseForm" method="POST">
                        <input type="hidden" name="action" value="s_excuser">
                        <input type="hidden" name="reunion_id" id="excuseReunionId">
                        <div class="mb-3">
                            <label for="raison" class="form-label">Raison</label>
                            <textarea class="form-control" id="raison" name="raison" rows="3" 
                                      placeholder="Ex: Maladie, transport, urgence familiale..." required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-excuse" onclick="submitExcuse()">
                        <i class="fas fa-paper-plane"></i> Envoyer l'excuse
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de détails -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de la réunion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsModalBody">
                    <!-- Les détails seront chargés ici par JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
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
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Récupérer le thème sauvegardé ou utiliser 'light' par défaut
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
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Initialiser FullCalendar
        initCalendar();
    });
    
    // Initialiser le calendrier FullCalendar
    function initCalendar() {
        var calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;
        
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: [
                <?php foreach($calendrier_reunions as $reunion): ?>
                {
                    id: <?php echo $reunion['id']; ?>,
                    title: '<?php echo safeHtml($reunion['titre'] ?? ''); ?>',
                    start: '<?php echo date('Y-m-d\TH:i:s', strtotime($reunion['date_reunion'] ?? '')); ?>',
                    end: '<?php echo date('Y-m-d\TH:i:s', strtotime(($reunion['date_reunion'] ?? '') . ' + ' . ($reunion['duree'] ?? 60) . ' minutes')); ?>',
                    description: '<?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?>',
                    color: '<?php echo $reunion['couleur'] ?? '#3498db'; ?>',
                    extendedProps: {
                        lieu: '<?php echo safeHtml($reunion['lieu'] ?? ''); ?>',
                        type: '<?php echo $reunion['type_reunion'] ?? ''; ?>',
                        description: '<?php echo safeHtml($reunion['description'] ?? ''); ?>'
                    }
                },
                <?php endforeach; ?>
            ],
            eventClick: function(info) {
                voirDetails(info.event.id);
            },
            eventMouseEnter: function(info) {
                info.el.style.cursor = 'pointer';
            }
        });
        calendar.render();
    }
    
    // Fonctions de navigation
    function scrollToSection(sectionId) {
        const element = document.getElementById(sectionId);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    // Fonctions de gestion des réunions
    function confirmerPresence(reunionId) {
        document.getElementById('confirmationReunionId').value = reunionId;
        const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        modal.show();
    }
    
    function submitConfirmation() {
        document.getElementById('confirmationForm').submit();
    }
    
    function sExcuser(reunionId) {
        document.getElementById('excuseReunionId').value = reunionId;
        const modal = new bootstrap.Modal(document.getElementById('excuseModal'));
        modal.show();
    }
    
    function submitExcuse() {
        if (document.getElementById('raison').value.trim() === '') {
            alert('Veuillez indiquer la raison de votre absence.');
            return;
        }
        document.getElementById('excuseForm').submit();
    }
    
    function voirDetails(reunionId) {
        // Pour l'exemple, on montre juste une alerte
        // En production, vous feriez une requête AJAX pour récupérer les détails
        const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
        
        // Simuler des détails (à remplacer par une requête AJAX réelle)
        const detailsContent = `
            <h6>Réunion #${reunionId}</h6>
            <p><strong>Date:</strong> ${new Date().toLocaleDateString('fr-FR')}</p>
            <p><strong>Lieu:</strong> Salle de réunion principale</p>
            <p><strong>Organisateur:</strong> Directeur pédagogique</p>
            <p><strong>Description:</strong> Réunion pédagogique trimestrielle pour faire le point sur l'avancement des cours.</p>
            <p><strong>Ordre du jour:</strong></p>
            <ul>
                <li>Bilan du trimestre</li>
                <li>Présentation des projets étudiants</li>
                <li>Questions diverses</li>
            </ul>
            <p><strong>Participants attendus:</strong> 25</p>
            <p><strong>Durée prévue:</strong> 2 heures</p>
        `;
        
        document.getElementById('detailsModalBody').innerHTML = detailsContent;
        modal.show();
    }
    
    // Autres fonctions
    function showAllReunions() {
        // Rediriger ou afficher toutes les réunions
        alert('Fonctionnalité à implémenter: Affichage de toutes les réunions');
    }
    
    function exportCalendrier() {
        // Exporter le calendrier au format iCal
        alert('Fonctionnalité à implémenter: Export iCal');
    }
    </script>
</body>
</html>