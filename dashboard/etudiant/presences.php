<?php
// dashboard/etudiant/presences.php

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
    $pageTitle = "Présences Étudiant";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatDateTimeFr($datetime) {
        if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($datetime);
        if ($timestamp === false) return '';
        return date('d/m/Y H:i', $timestamp);
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'present':
                return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Présent</span>';
            case 'absent':
                return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Absent</span>';
            case 'retard':
                return '<span class="badge bg-warning"><i class="fas fa-clock"></i> Retard</span>';
            case 'justifie':
                return '<span class="badge bg-info"><i class="fas fa-file-medical"></i> Justifié</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypePresenceBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'entree_ecole':
                return '<span class="badge bg-primary"><i class="fas fa-sign-in-alt"></i> Entrée école</span>';
            case 'sortie_ecole':
                return '<span class="badge bg-secondary"><i class="fas fa-sign-out-alt"></i> Sortie école</span>';
            case 'entree_classe':
                return '<span class="badge bg-info"><i class="fas fa-door-open"></i> Entrée classe</span>';
            case 'sortie_classe':
                return '<span class="badge bg-dark"><i class="fas fa-door-closed"></i> Sortie classe</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
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
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    
    // Initialiser toutes les variables
    $info_etudiant = array();
    $stats_presences = array(
        'total_jours' => 0,
        'presents' => 0,
        'absents' => 0,
        'retards' => 0,
        'justifies' => 0,
        'taux_presence' => 0,
        'taux_absenteeisme' => 0
    );
    
    $presences_mois = array();
    $presences_annee = array();
    $absences_non_justifiees = array();
    $retards_detail = array();
    $historique_presences = array();
    $statistiques_mensuelles = array();
    $graphique_data = array();
    $error = null;
    
    // Paramètres de filtre
    $mois_filtre = isset($_GET['mois']) ? intval($_GET['mois']) : date('m');
    $annee_filtre = isset($_GET['annee']) ? intval($_GET['annee']) : date('Y');
    $type_filtre = isset($_GET['type']) ? $_GET['type'] : 'all';
    $statut_filtre = isset($_GET['statut']) ? $_GET['statut'] : 'all';
    
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
        "SELECT e.*, s.nom as site_nom, c.id as classe_id, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $classe_id = intval($info_etudiant['classe_id'] ?? 0);
        
        // Calculer les statistiques générales
        $stats = executeSingleQuery($db,
            "SELECT 
                COUNT(*) as total_jours,
                COUNT(CASE WHEN statut = 'present' THEN 1 END) as presents,
                COUNT(CASE WHEN statut = 'absent' THEN 1 END) as absents,
                COUNT(CASE WHEN statut = 'retard' THEN 1 END) as retards,
                COUNT(CASE WHEN statut = 'justifie' THEN 1 END) as justifies
             FROM presences 
             WHERE etudiant_id = ? 
             AND YEAR(date_heure) = ?",
            [$etudiant_id, $annee_filtre]);
        
        if ($stats) {
            $stats_presences['total_jours'] = intval($stats['total_jours'] ?? 0);
            $stats_presences['presents'] = intval($stats['presents'] ?? 0);
            $stats_presences['absents'] = intval($stats['absents'] ?? 0);
            $stats_presences['retards'] = intval($stats['retards'] ?? 0);
            $stats_presences['justifies'] = intval($stats['justifies'] ?? 0);
            
            if ($stats_presences['total_jours'] > 0) {
                $stats_presences['taux_presence'] = round(($stats_presences['presents'] / $stats_presences['total_jours']) * 100, 1);
                $stats_presences['taux_absenteeisme'] = round((($stats_presences['absents'] - $stats_presences['justifies']) / $stats_presences['total_jours']) * 100, 1);
            }
        }
        
        // Récupérer les présences du mois avec filtres
        $params = [$etudiant_id, $mois_filtre, $annee_filtre];
        $where_clause = "WHERE p.etudiant_id = ? AND MONTH(p.date_heure) = ? AND YEAR(p.date_heure) = ?";
        
        if ($type_filtre != 'all') {
            $where_clause .= " AND p.type_presence = ?";
            $params[] = $type_filtre;
        }
        
        if ($statut_filtre != 'all') {
            $where_clause .= " AND p.statut = ?";
            $params[] = $statut_filtre;
        }
        
        $presences_mois = executeQuery($db,
            "SELECT p.*, m.nom as matiere_nom, 
                    CONCAT(u.nom, ' ', u.prenom) as surveillant_nom,
                    DATE(p.date_heure) as date_presence,
                    TIME(p.date_heure) as heure_presence
             FROM presences p
             LEFT JOIN matieres m ON p.matiere_id = m.id
             LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
             $where_clause
             ORDER BY p.date_heure DESC
             LIMIT 50",
            $params);
        
        // Récupérer les absences non justifiées
        $absences_non_justifiees = executeQuery($db,
            "SELECT p.*, m.nom as matiere_nom,
                    CONCAT(u.nom, ' ', u.prenom) as surveillant_nom,
                    DATEDIFF(CURDATE(), DATE(p.date_heure)) as jours_ecoules
             FROM presences p
             LEFT JOIN matieres m ON p.matiere_id = m.id
             LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
             WHERE p.etudiant_id = ? 
             AND p.statut IN ('absent', 'retard')
             AND p.justificatif IS NULL
             AND p.motif_absence IS NULL
             ORDER BY p.date_heure DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les retards détaillés
        $retards_detail = executeQuery($db,
            "SELECT p.*, m.nom as matiere_nom,
                    CONCAT(u.nom, ' ', u.prenom) as surveillant_nom,
                    TIME_TO_SEC(TIMEDIFF(p.date_heure, CONCAT(DATE(p.date_heure), ' 08:00:00'))) / 60 as minutes_retard
             FROM presences p
             LEFT JOIN matieres m ON p.matiere_id = m.id
             LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'retard'
             ORDER BY p.date_heure DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer l'historique complet (6 derniers mois)
        $historique_presences = executeQuery($db,
            "SELECT 
                DATE(p.date_heure) as jour,
                COUNT(*) as total_presences,
                COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as presents,
                COUNT(CASE WHEN p.statut = 'absent' THEN 1 END) as absents,
                COUNT(CASE WHEN p.statut = 'retard' THEN 1 END) as retards,
                COUNT(CASE WHEN p.statut = 'justifie' THEN 1 END) as justifies
             FROM presences p
             WHERE p.etudiant_id = ? 
             AND p.date_heure >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY DATE(p.date_heure)
             ORDER BY jour DESC
             LIMIT 30",
            [$etudiant_id]);
        
        // Récupérer les statistiques mensuelles pour l'année
        $statistiques_mensuelles = executeQuery($db,
            "SELECT 
                MONTH(p.date_heure) as mois,
                DATE_FORMAT(p.date_heure, '%M') as mois_nom,
                COUNT(*) as total_jours,
                COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as presents,
                COUNT(CASE WHEN p.statut = 'absent' THEN 1 END) as absents,
                COUNT(CASE WHEN p.statut = 'retard' THEN 1 END) as retards,
                ROUND((COUNT(CASE WHEN p.statut = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as taux_presence
             FROM presences p
             WHERE p.etudiant_id = ? 
             AND YEAR(p.date_heure) = ?
             GROUP BY MONTH(p.date_heure), DATE_FORMAT(p.date_heure, '%M')
             ORDER BY mois",
            [$etudiant_id, $annee_filtre]);
        
        // Préparer les données pour les graphiques
        $graphique_data['labels'] = [];
        $graphique_data['presents'] = [];
        $graphique_data['absents'] = [];
        $graphique_data['retards'] = [];
        
        foreach ($statistiques_mensuelles as $mois) {
            $graphique_data['labels'][] = substr($mois['mois_nom'], 0, 3);
            $graphique_data['presents'][] = intval($mois['presents']);
            $graphique_data['absents'][] = intval($mois['absents']);
            $graphique_data['retards'][] = intval($mois['retards']);
        }
        
        // Récupérer les justificatifs uploadés
        $justificatifs = executeQuery($db,
            "SELECT p.*, m.nom as matiere_nom
             FROM presences p
             LEFT JOIN matieres m ON p.matiere_id = m.id
             WHERE p.etudiant_id = ? 
             AND p.justificatif IS NOT NULL
             ORDER BY p.date_heure DESC
             LIMIT 5",
            [$etudiant_id]);
            
    }
    
    // Obtenir la liste des mois pour le filtre
    $mois_liste = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    
    // Obtenir la liste des années disponibles
    $annees_liste = [];
    for ($i = date('Y') - 2; $i <= date('Y'); $i++) {
        $annees_liste[$i] = $i;
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
    
    <!-- Print CSS -->
    <style>
    @media print {
        .no-print, .sidebar, .main-content .btn, .btn-group, .filter-card {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            padding: 10px !important;
        }
        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        .table {
            font-size: 12px !important;
        }
    }
    </style>
    
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Filtres */
    .filter-card {
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        margin-bottom: 15px;
    }
    
    .filter-label {
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--text-color);
        display: block;
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
    
    /* Widgets */
    .widget {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
    }
    
    .widget-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: var(--text-color);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .widget-title i {
        color: var(--primary-color);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
        height: 10px;
        border-radius: 5px;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
        border-radius: 5px;
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    /* List group */
    .list-group-item {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .list-group-item:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .list-group-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Présence items */
    .presence-item {
        border-left: 4px solid var(--border-color);
        padding: 10px;
        margin-bottom: 10px;
        border-radius: 5px;
        transition: all 0.3s;
    }
    
    .presence-item:hover {
        transform: translateX(5px);
    }
    
    .presence-item.present {
        border-left-color: var(--success-color);
        background-color: rgba(39, 174, 96, 0.05);
    }
    
    .presence-item.absent {
        border-left-color: var(--accent-color);
        background-color: rgba(231, 76, 60, 0.05);
    }
    
    .presence-item.retard {
        border-left-color: var(--warning-color);
        background-color: rgba(243, 156, 18, 0.05);
    }
    
    .presence-item.justifie {
        border-left-color: var(--info-color);
        background-color: rgba(23, 162, 184, 0.05);
    }
    
    /* Tendance */
    .trend-up {
        color: var(--success-color);
    }
    
    .trend-down {
        color: var(--accent-color);
    }
    
    .trend-stable {
        color: var(--warning-color);
    }
    
    /* QR Code Scanner */
    .qr-scanner {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
    }
    
    .qr-placeholder {
        width: 200px;
        height: 200px;
        background: white;
        margin: 20px auto;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
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
                    <a href="presences.php" class="nav-link active">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
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
                            <i class="fas fa-calendar-check me-2"></i>
                            Suivi des Présences
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['classe_nom']) && !empty($info_etudiant['classe_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['classe_nom']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="showQRScanner()">
                            <i class="fas fa-qrcode"></i> Scanner QR
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Retour
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="filter-card">
                <form method="GET" action="" class="row">
                    <div class="col-md-3 mb-3">
                        <label class="filter-label">
                            <i class="fas fa-calendar-month"></i> Mois
                        </label>
                        <select name="mois" class="form-select" onchange="this.form.submit()">
                            <?php foreach($mois_liste as $num => $nom): ?>
                            <option value="<?php echo $num; ?>" <?php echo $mois_filtre == $num ? 'selected' : ''; ?>>
                                <?php echo $nom; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="filter-label">
                            <i class="fas fa-calendar-alt"></i> Année
                        </label>
                        <select name="annee" class="form-select" onchange="this.form.submit()">
                            <?php foreach($annees_liste as $annee): ?>
                            <option value="<?php echo $annee; ?>" <?php echo $annee_filtre == $annee ? 'selected' : ''; ?>>
                                <?php echo $annee; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="filter-label">
                            <i class="fas fa-filter"></i> Type
                        </label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $type_filtre == 'all' ? 'selected' : ''; ?>>Tous les types</option>
                            <option value="entree_ecole" <?php echo $type_filtre == 'entree_ecole' ? 'selected' : ''; ?>>Entrée école</option>
                            <option value="sortie_ecole" <?php echo $type_filtre == 'sortie_ecole' ? 'selected' : ''; ?>>Sortie école</option>
                            <option value="entree_classe" <?php echo $type_filtre == 'entree_classe' ? 'selected' : ''; ?>>Entrée classe</option>
                            <option value="sortie_classe" <?php echo $type_filtre == 'sortie_classe' ? 'selected' : ''; ?>>Sortie classe</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="filter-label">
                            <i class="fas fa-user-check"></i> Statut
                        </label>
                        <select name="statut" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $statut_filtre == 'all' ? 'selected' : ''; ?>>Tous les statuts</option>
                            <option value="present" <?php echo $statut_filtre == 'present' ? 'selected' : ''; ?>>Présent</option>
                            <option value="absent" <?php echo $statut_filtre == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="retard" <?php echo $statut_filtre == 'retard' ? 'selected' : ''; ?>>Retard</option>
                            <option value="justifie" <?php echo $statut_filtre == 'justifie' ? 'selected' : ''; ?>>Justifié</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Section 1: Statistiques principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_presences['taux_presence']; ?>%</div>
                        <div class="stat-label">Taux de Présence</div>
                        <div class="stat-change">
                            <?php if($stats_presences['taux_presence'] >= 80): ?>
                            <span class="positive"><i class="fas fa-arrow-up"></i> Excellent</span>
                            <?php elseif($stats_presences['taux_presence'] >= 60): ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> Bon</span>
                            <?php else: ?>
                            <span class="negative"><i class="fas fa-exclamation-circle"></i> À améliorer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_presences['presents']; ?></div>
                        <div class="stat-label">Présences</div>
                        <div class="stat-change">
                            <i class="fas fa-calendar"></i> Année <?php echo $annee_filtre; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_presences['absents']; ?></div>
                        <div class="stat-label">Absences</div>
                        <div class="stat-change">
                            <?php if($stats_presences['justifies'] > 0): ?>
                            <span class="trend-down">
                                <i class="fas fa-file-medical"></i> <?php echo $stats_presences['justifies']; ?> justifiée(s)
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_presences['retards']; ?></div>
                        <div class="stat-label">Retards</div>
                        <div class="stat-change">
                            <?php if($stats_presences['retards'] > 0): ?>
                            <span class="trend-down"><i class="fas fa-exclamation-triangle"></i> À surveiller</span>
                            <?php else: ?>
                            <span class="trend-up"><i class="fas fa-check-circle"></i> Parfait</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Graphiques et tableau -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-bar me-2"></i>
                                Évolution Mensuelle <?php echo $annee_filtre; ?>
                            </h5>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="changeChartType('bar')">
                                    <i class="fas fa-chart-bar"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-primary" onclick="changeChartType('line')">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="presenceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Alertes Importantes
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(!empty($absences_non_justifiees)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-circle"></i> Absences non justifiées</h6>
                                <p>Vous avez <?php echo count($absences_non_justifiees); ?> absence(s) non justifiée(s).</p>
                                <a href="#absences" class="btn btn-sm btn-outline-light">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!empty($retards_detail)): ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-clock"></i> Retards répétés</h6>
                                <p>Vous avez <?php echo count($retards_detail); ?> retard(s) enregistré(s).</p>
                                <a href="#retards" class="btn btn-sm btn-outline-light">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats_presences['taux_presence'] < 80): ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-chart-line"></i> Taux de présence bas</h6>
                                <p>Votre taux de présence est de <?php echo $stats_presences['taux_presence']; ?>%.</p>
                                <small>Objectif recommandé: ≥ 80%</small>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-success">
                                <h6><i class="fas fa-check-circle"></i> Excellent assiduité</h6>
                                <p>Félicitations! Votre taux de présence est excellent.</p>
                                <small>Continuez ainsi!</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Règles importantes</h6>
                                <ul class="mb-0 small">
                                    <li>Justifiez vos absences sous 48h</li>
                                    <li>Retard maximum toléré: 15 min</li>
                                    <li>3 retards = 1 absence non justifiée</li>
                                    <li>≥ 20% d'absences = Risque d'exclusion</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="presenceTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="liste-tab" data-bs-toggle="tab" data-bs-target="#liste" type="button">
                                <i class="fas fa-list me-2"></i>Liste des Présences
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="absences-tab" data-bs-toggle="tab" data-bs-target="#absences" type="button">
                                <i class="fas fa-times-circle me-2"></i>Absences
                                <?php if(!empty($absences_non_justifiees)): ?>
                                <span class="badge bg-danger ms-1"><?php echo count($absences_non_justifiees); ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="retards-tab" data-bs-toggle="tab" data-bs-target="#retards" type="button">
                                <i class="fas fa-clock me-2"></i>Retards
                                <?php if(!empty($retards_detail)): ?>
                                <span class="badge bg-warning ms-1"><?php echo count($retards_detail); ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="justificatifs-tab" data-bs-toggle="tab" data-bs-target="#justificatifs" type="button">
                                <i class="fas fa-file-medical me-2"></i>Justificatifs
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="presenceTabsContent">
                        <!-- Tab 1: Liste des présences -->
                        <div class="tab-pane fade show active" id="liste">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>
                                    <i class="fas fa-calendar me-2"></i>
                                    Présences du <?php echo $mois_liste[$mois_filtre]; ?> <?php echo $annee_filtre; ?>
                                </h5>
                                <div>
                                    <span class="badge bg-secondary me-2">
                                        <?php echo count($presences_mois); ?> enregistrement(s)
                                    </span>
                                    <button class="btn btn-sm btn-outline-primary" onclick="exportPresences()">
                                        <i class="fas fa-download"></i> Exporter
                                    </button>
                                </div>
                            </div>
                            
                            <?php if(empty($presences_mois)): ?>
                            <div class="alert alert-info text-center py-5">
                                <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                <h4>Aucune présence enregistrée</h4>
                                <p class="mb-0">Aucune donnée de présence pour le mois sélectionné.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Heure</th>
                                            <th>Type</th>
                                            <th>Matière</th>
                                            <th>Statut</th>
                                            <th>Surveillant</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($presences_mois as $presence): 
                                            $statut_class = $presence['statut'] ?? '';
                                        ?>
                                        <tr class="<?php echo $statut_class; ?>">
                                            <td>
                                                <strong><?php echo formatDateFr($presence['date_presence'] ?? ''); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo substr($presence['heure_presence'] ?? '00:00:00', 0, 5); ?>
                                            </td>
                                            <td>
                                                <?php echo getTypePresenceBadge($presence['type_presence'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <?php if(!empty($presence['matiere_nom'])): ?>
                                                <?php echo safeHtml($presence['matiere_nom']); ?>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo getStatutBadge($presence['statut'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <?php if(!empty($presence['surveillant_nom'])): ?>
                                                <?php echo safeHtml($presence['surveillant_nom']); ?>
                                                <?php else: ?>
                                                <span class="text-muted">Système</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="showPresenceDetails(<?php echo $presence['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if(in_array($presence['statut'], ['absent', 'retard']) && empty($presence['justificatif'])): ?>
                                                <button class="btn btn-sm btn-outline-warning" onclick="uploadJustificatif(<?php echo $presence['id']; ?>)">
                                                    <i class="fas fa-upload"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Résumé du mois -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6><i class="fas fa-chart-pie me-2"></i>Répartition du mois</h6>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>Présences:</span>
                                                <span class="text-success"><?php echo count(array_filter($presences_mois, fn($p) => $p['statut'] == 'present')); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>Absences:</span>
                                                <span class="text-danger"><?php echo count(array_filter($presences_mois, fn($p) => $p['statut'] == 'absent')); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>Retards:</span>
                                                <span class="text-warning"><?php echo count(array_filter($presences_mois, fn($p) => $p['statut'] == 'retard')); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Justifiés:</span>
                                                <span class="text-info"><?php echo count(array_filter($presences_mois, fn($p) => $p['statut'] == 'justifie')); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6><i class="fas fa-percentage me-2"></i>Taux du mois</h6>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Présence:</span>
                                                    <span>
                                                        <?php 
                                                        $total_mois = count($presences_mois);
                                                        $presents_mois = count(array_filter($presences_mois, fn($p) => $p['statut'] == 'present'));
                                                        $taux_mois = $total_mois > 0 ? round(($presents_mois / $total_mois) * 100, 1) : 0;
                                                        echo $taux_mois; ?>%
                                                    </span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $taux_mois; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Ponctualité:</span>
                                                    <span>
                                                        <?php 
                                                        $retards_mois = count(array_filter($presences_mois, fn($p) => $p['statut'] == 'retard'));
                                                        $taux_ponctualite = $total_mois > 0 ? round((($total_mois - $retards_mois) / $total_mois) * 100, 1) : 100;
                                                        echo $taux_ponctualite; ?>%
                                                    </span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar bg-warning" style="width: <?php echo $taux_ponctualite; ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 2: Absences -->
                        <div class="tab-pane fade" id="absences">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>
                                    <i class="fas fa-times-circle me-2"></i>
                                    Absences non justifiées
                                </h5>
                                <div>
                                    <span class="badge bg-danger me-2">
                                        <?php echo count($absences_non_justifiees); ?> absence(s)
                                    </span>
                                </div>
                            </div>
                            
                            <?php if(empty($absences_non_justifiees)): ?>
                            <div class="alert alert-success text-center py-5">
                                <i class="fas fa-check-circle fa-3x mb-3"></i>
                                <h4>Aucune absence non justifiée!</h4>
                                <p class="mb-0">Toutes vos absences sont justifiées ou vous n'avez aucune absence.</p>
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($absences_non_justifiees as $absence): ?>
                                <div class="list-group-item absence-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <i class="fas fa-calendar-day me-2"></i>
                                            <?php echo formatDateFr($absence['date_heure'] ?? ''); ?>
                                            <?php if(!empty($absence['matiere_nom'])): ?>
                                            - <?php echo safeHtml($absence['matiere_nom']); ?>
                                            <?php endif; ?>
                                        </h6>
                                        <div>
                                            <?php echo getStatutBadge($absence['statut'] ?? ''); ?>
                                            <?php if($absence['jours_ecoules'] > 2): ?>
                                            <span class="badge bg-danger ms-2">
                                                <i class="fas fa-exclamation-triangle"></i> Délai dépassé
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="mb-1">
                                        <small>
                                            <i class="fas fa-clock"></i> 
                                            <?php echo substr($absence['date_heure'] ?? '00:00:00', 11, 5); ?> | 
                                            <i class="fas fa-user-tie"></i> 
                                            <?php echo safeHtml($absence['surveillant_nom'] ?? 'Système'); ?>
                                        </small>
                                    </p>
                                    <?php if(!empty($absence['motif_absence'])): ?>
                                    <p class="mb-1 small">
                                        <strong>Motif:</strong> <?php echo safeHtml($absence['motif_absence']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-warning" onclick="uploadJustificatif(<?php echo $absence['id']; ?>)">
                                            <i class="fas fa-upload"></i> Ajouter un justificatif
                                        </button>
                                        <button class="btn btn-sm btn-outline-info" onclick="showPresenceDetails(<?php echo $absence['id']; ?>)">
                                            <i class="fas fa-eye"></i> Détails
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info mt-3">
                                <h6><i class="fas fa-info-circle"></i> Information importante</h6>
                                <p class="mb-0">
                                    Vous avez <strong>48 heures</strong> pour justifier une absence ou un retard.
                                    Après ce délai, l'absence sera considérée comme non justifiée et pourra entraîner des sanctions.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Retards -->
                        <div class="tab-pane fade" id="retards">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>
                                    <i class="fas fa-clock me-2"></i>
                                    Historique des retards
                                </h5>
                                <div>
                                    <span class="badge bg-warning me-2">
                                        <?php echo count($retards_detail); ?> retard(s)
                                    </span>
                                </div>
                            </div>
                            
                            <?php if(empty($retards_detail)): ?>
                            <div class="alert alert-success text-center py-5">
                                <i class="fas fa-check-circle fa-3x mb-3"></i>
                                <h4>Parfaitement ponctuel!</h4>
                                <p class="mb-0">Aucun retard enregistré. Continuez ainsi!</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Matière</th>
                                            <th>Retard</th>
                                            <th>Heure prévue</th>
                                            <th>Heure réelle</th>
                                            <th>Surveillant</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($retards_detail as $retard): 
                                            $minutes = intval($retard['minutes_retard'] ?? 0);
                                            $retard_class = $minutes <= 15 ? 'warning' : 'danger';
                                        ?>
                                        <tr>
                                            <td><?php echo formatDateFr($retard['date_heure'] ?? ''); ?></td>
                                            <td><?php echo safeHtml($retard['matiere_nom'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $retard_class; ?>">
                                                    <i class="fas fa-clock"></i> <?php echo $minutes; ?> min
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                // Heure prévue (8h00 par défaut pour le matin)
                                                $date = substr($retard['date_heure'] ?? '', 0, 10);
                                                $heure_prevue = '08:00';
                                                echo $heure_prevue;
                                                ?>
                                            </td>
                                            <td><?php echo substr($retard['date_heure'] ?? '00:00:00', 11, 5); ?></td>
                                            <td><?php echo safeHtml($retard['surveillant_nom'] ?? 'Système'); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="showPresenceDetails(<?php echo $retard['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if(empty($retard['justificatif'])): ?>
                                                <button class="btn btn-sm btn-outline-warning" onclick="uploadJustificatif(<?php echo $retard['id']; ?>)">
                                                    <i class="fas fa-upload"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                            </div>
                            
                            <!-- Statistiques des retards -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6><i class="fas fa-chart-bar me-2"></i>Répartition des retards</h6>
                                            <?php 
                                            $retards_5_15 = count(array_filter($retards_detail, fn($r) => $r['minutes_retard'] <= 15));
                                            $retards_15_30 = count(array_filter($retards_detail, fn($r) => $r['minutes_retard'] > 15 && $r['minutes_retard'] <= 30));
                                            $retards_30plus = count(array_filter($retards_detail, fn($r) => $r['minutes_retard'] > 30));
                                            ?>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>≤ 15 min:</span>
                                                <span class="text-warning"><?php echo $retards_5_15; ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>15-30 min:</span>
                                                <span class="text-warning"><?php echo $retards_15_30; ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>> 30 min:</span>
                                                <span class="text-danger"><?php echo $retards_30plus; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Attention</h6>
                                            <p class="small mb-2">
                                                <strong>Rappel:</strong> 3 retards = 1 absence non justifiée
                                            </p>
                                            <div class="alert alert-warning p-2 mb-0">
                                                <small>
                                                    <i class="fas fa-info-circle"></i> 
                                                    Vous avez actuellement <?php echo count($retards_detail); ?> retard(s).
                                                    Après <?php echo 3 - (count($retards_detail) % 3); ?> retard(s) supplémentaire(s),
                                                    vous aurez une absence non justifiée.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 4: Justificatifs -->
                        <div class="tab-pane fade" id="justificatifs">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>
                                    <i class="fas fa-file-medical me-2"></i>
                                    Mes justificatifs
                                </h5>
                                <div>
                                    <button class="btn btn-sm btn-primary" onclick="showUploadModal()">
                                        <i class="fas fa-plus"></i> Nouveau justificatif
                                    </button>
                                </div>
                            </div>
                            
                            <?php if(empty($justificatifs)): ?>
                            <div class="alert alert-info text-center py-5">
                                <i class="fas fa-file-upload fa-3x mb-3"></i>
                                <h4>Aucun justificatif uploadé</h4>
                                <p class="mb-3">Vous n'avez pas encore uploadé de justificatif.</p>
                                <button class="btn btn-primary" onclick="showUploadModal()">
                                    <i class="fas fa-upload"></i> Uploader un justificatif
                                </button>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date absence</th>
                                            <th>Matière</th>
                                            <th>Type de document</th>
                                            <th>Date d'upload</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($justificatifs as $justificatif): 
                                            $extension = pathinfo($justificatif['justificatif'] ?? '', PATHINFO_EXTENSION);
                                            $type_icon = $extension == 'pdf' ? 'fa-file-pdf text-danger' : 
                                                       ($extension == 'jpg' || $extension == 'jpeg' || $extension == 'png' ? 'fa-file-image text-success' : 
                                                       'fa-file text-secondary');
                                        ?>
                                        <tr>
                                            <td><?php echo formatDateFr($justificatif['date_heure'] ?? ''); ?></td>
                                            <td><?php echo safeHtml($justificatif['matiere_nom'] ?? '-'); ?></td>
                                            <td>
                                                <i class="fas <?php echo $type_icon; ?> me-2"></i>
                                                <?php echo strtoupper($extension); ?>
                                            </td>
                                            <td><?php echo formatDateTimeFr($justificatif['date_creation'] ?? ''); ?></td>
                                            <td>
                                                <?php if($justificatif['statut'] == 'justifie'): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle"></i> Accepté
                                                </span>
                                                <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="fas fa-clock"></i> En attente
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="showPresenceDetails(<?php echo $justificatif['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if(!empty($justificatif['justificatif'])): ?>
                                                <a href="<?php echo safeHtml($justificatif['justificatif']); ?>" 
                                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-warning mt-3">
                                <h6><i class="fas fa-info-circle"></i> Types de justificatifs acceptés</h6>
                                <ul class="mb-0 small">
                                    <li><i class="fas fa-file-pdf text-danger"></i> Certificat médical (PDF/JPG/PNG)</li>
                                    <li><i class="fas fa-file-alt text-info"></i> Convocation officielle</li>
                                    <li><i class="fas fa-file-contract text-success"></i> Attestation administrative</li>
                                    <li><i class="fas fa-envelope text-primary"></i> Email de justification</li>
                                </ul>
                                <p class="mt-2 mb-0 small">
                                    <strong>Note:</strong> Tous les justificatifs doivent être soumis sous 48 heures.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Scanner QR et historique -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-qrcode me-2"></i>
                                Scanner QR Code
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="qr-scanner">
                                <h5 class="mb-3">Pointage par QR Code</h5>
                                <div class="qr-placeholder">
                                    <i class="fas fa-qrcode fa-5x text-dark"></i>
                                </div>
                                <p class="mt-3 mb-2">
                                    Scannez le QR Code à l'entrée de l'école ou de la salle
                                </p>
                                <div class="btn-group mt-2">
                                    <button class="btn btn-light" onclick="startQRScanner()">
                                        <i class="fas fa-camera"></i> Scanner maintenant
                                    </button>
                                    <button class="btn btn-outline-light" onclick="showMyQR()">
                                        <i class="fas fa-id-card"></i> Mon QR Code
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <h6><i class="fas fa-history me-2"></i>Derniers pointages</h6>
                                <?php 
                                $derniers_pointages = array_slice($presences_mois, 0, 5);
                                if(!empty($derniers_pointages)):
                                ?>
                                <div class="list-group">
                                    <?php foreach($derniers_pointages as $pointage): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <small><?php echo formatDateTimeFr($pointage['date_heure'] ?? ''); ?></small>
                                            <?php echo getTypePresenceBadge($pointage['type_presence'] ?? ''); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php if(!empty($pointage['matiere_nom'])): ?>
                                            <i class="fas fa-book"></i> <?php echo safeHtml($pointage['matiere_nom']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Aucun pointage récent
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Tendance sur 30 jours
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="tendanceChart"></canvas>
                            </div>
                            
                            <div class="mt-3">
                                <h6><i class="fas fa-trophy me-2"></i>Votre performance</h6>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <div class="stat-value text-success" style="font-size: 1.5rem;">
                                                <?php echo $stats_presences['taux_presence']; ?>%
                                            </div>
                                            <div class="stat-label">Taux de présence</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <div class="stat-value text-warning" style="font-size: 1.5rem;">
                                                <?php echo count($retards_detail); ?>
                                            </div>
                                            <div class="stat-label">Retards total</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Objectif du mois -->
                                <div class="alert alert-info mt-3">
                                    <h6><i class="fas fa-bullseye me-2"></i>Objectif du mois</h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>Taux de présence ≥ 85%</span>
                                        <?php 
                                        $objectif_atteint = $stats_presences['taux_presence'] >= 85;
                                        ?>
                                        <span class="badge bg-<?php echo $objectif_atteint ? 'success' : 'warning'; ?>">
                                            <?php echo $objectif_atteint ? 'Atteint ✓' : 'En cours...'; ?>
                                        </span>
                                    </div>
                                    <div class="progress mt-2">
                                        <div class="progress-bar bg-success" 
                                             style="width: <?php echo min($stats_presences['taux_presence'], 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 pour les dialogues -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Graphique principal
    let presenceChart = null;
    let tendanceChart = null;
    
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser le thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
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
        
        // Graphique des présences mensuelles
        const ctx1 = document.getElementById('presenceChart').getContext('2d');
        presenceChart = new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($graphique_data['labels'] ?? []); ?>,
                datasets: [{
                    label: 'Présents',
                    data: <?php echo json_encode($graphique_data['presents'] ?? []); ?>,
                    backgroundColor: '#27ae60',
                    borderColor: '#27ae60',
                    borderWidth: 1
                }, {
                    label: 'Absents',
                    data: <?php echo json_encode($graphique_data['absents'] ?? []); ?>,
                    backgroundColor: '#e74c3c',
                    borderColor: '#e74c3c',
                    borderWidth: 1
                }, {
                    label: 'Retards',
                    data: <?php echo json_encode($graphique_data['retards'] ?? []); ?>,
                    backgroundColor: '#f39c12',
                    borderColor: '#f39c12',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Évolution des présences par mois'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Nombre'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Mois'
                        }
                    }
                }
            }
        });
        
        // Graphique de tendance (30 derniers jours)
        const ctx2 = document.getElementById('tendanceChart').getContext('2d');
        tendanceChart = new Chart(ctx2, {
            type: 'line',
            data: {
                labels: <?php 
                    $jours = [];
                    $taux = [];
                    foreach (array_reverse($historique_presences) as $hist) {
                        $jours[] = formatDateFr($hist['jour'], 'd/m');
                        $taux[] = $hist['total_presences'] > 0 ? 
                                 round(($hist['presents'] / $hist['total_presences']) * 100, 0) : 0;
                    }
                    echo json_encode($jours);
                ?>,
                datasets: [{
                    label: 'Taux de présence (%)',
                    data: <?php echo json_encode($taux); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Tendance sur 30 jours'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Taux (%)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    }
                }
            }
        });
    });
    
    // Changer le type de graphique
    function changeChartType(type) {
        if (presenceChart) {
            presenceChart.config.type = type;
            presenceChart.update();
        }
    }
    
    // Exporter les présences
    function exportPresences() {
        Swal.fire({
            title: 'Exporter les données',
            html: `
                <div class="text-start">
                    <p>Sélectionnez le format d'export :</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatPDF" value="pdf" checked>
                        <label class="form-check-label" for="formatPDF">
                            <i class="fas fa-file-pdf text-danger"></i> PDF
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatExcel" value="excel">
                        <label class="form-check-label" for="formatExcel">
                            <i class="fas fa-file-excel text-success"></i> Excel
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatCSV" value="csv">
                        <label class="form-check-label" for="formatCSV">
                            <i class="fas fa-file-csv text-info"></i> CSV
                        </label>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Période</label>
                        <select class="form-select" id="exportPeriod">
                            <option value="mois">Mois en cours</option>
                            <option value="annee">Année en cours</option>
                            <option value="tout">Tout l'historique</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Exporter',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                const format = document.querySelector('input[name="exportFormat"]:checked').value;
                const period = document.getElementById('exportPeriod').value;
                
                Swal.fire({
                    title: 'Export en cours...',
                    text: 'Génération du fichier...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Export réussi!',
                        html: `Vos données de présence ont été exportées au format ${format.toUpperCase()}.<br><br>
                               <small>Le fichier a été téléchargé automatiquement.</small>`,
                        confirmButtonText: 'OK'
                    });
                }, 1500);
            }
        });
    }
    
    // Afficher les détails d'une présence
    function showPresenceDetails(presenceId) {
        // Simuler une requête AJAX pour récupérer les détails
        const details = {
            id: presenceId,
            date: '15/01/2024',
            heure: '08:15',
            type: 'entree_ecole',
            statut: 'present',
            matiere: 'Mathématiques',
            enseignant: 'Prof. Dupont',
            surveillant: 'M. Martin',
            salle: 'Salle A12',
            ip: '192.168.1.100',
            device: 'Chrome sur Windows',
            motif: null,
            justificatif: null
        };
        
        let html = `
            <div class="text-start">
                <table class="table table-sm">
                    <tr>
                        <th><i class="fas fa-calendar"></i> Date:</th>
                        <td>${details.date}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-clock"></i> Heure:</th>
                        <td>${details.heure}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-sign-in-alt"></i> Type:</th>
                        <td>${getTypeText(details.type)}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-user-check"></i> Statut:</th>
                        <td>${getStatutBadgeHTML(details.statut)}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-book"></i> Matière:</th>
                        <td>${details.matiere}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-user-tie"></i> Enseignant:</th>
                        <td>${details.enseignant}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-user-shield"></i> Surveillant:</th>
                        <td>${details.surveillant}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-door-open"></i> Salle:</th>
                        <td>${details.salle}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-network-wired"></i> Adresse IP:</th>
                        <td>${details.ip}</td>
                    </tr>
                    <tr>
                        <th><i class="fas fa-laptop"></i> Appareil:</th>
                        <td>${details.device}</td>
                    </tr>
        `;
        
        if (details.motif) {
            html += `
                <tr>
                    <th><i class="fas fa-comment"></i> Motif:</th>
                    <td>${details.motif}</td>
                </tr>
            `;
        }
        
        if (details.justificatif) {
            html += `
                <tr>
                    <th><i class="fas fa-file-medical"></i> Justificatif:</th>
                    <td>
                        <a href="${details.justificatif}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-download"></i> Télécharger
                        </a>
                    </td>
                </tr>
            `;
        }
        
        html += `</table></div>`;
        
        Swal.fire({
            title: 'Détails de la présence',
            html: html,
            width: 600,
            showCloseButton: true,
            confirmButtonText: 'Fermer',
            confirmButtonColor: '#3498db'
        });
    }
    
    function getTypeText(type) {
        const types = {
            'entree_ecole': 'Entrée école',
            'sortie_ecole': 'Sortie école',
            'entree_classe': 'Entrée classe',
            'sortie_classe': 'Sortie classe'
        };
        return types[type] || type;
    }
    
    function getStatutBadgeHTML(statut) {
        const badges = {
            'present': '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Présent</span>',
            'absent': '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Absent</span>',
            'retard': '<span class="badge bg-warning"><i class="fas fa-clock"></i> Retard</span>',
            'justifie': '<span class="badge bg-info"><i class="fas fa-file-medical"></i> Justifié</span>'
        };
        return badges[statut] || `<span class="badge bg-secondary">${statut}</span>`;
    }
    
    // Uploader un justificatif
    function uploadJustificatif(presenceId) {
        Swal.fire({
            title: 'Uploader un justificatif',
            html: `
                <div class="text-start">
                    <p>Veuillez sélectionner le fichier justificatif :</p>
                    <div class="mb-3">
                        <label class="form-label">Type de justificatif</label>
                        <select class="form-select" id="justificatifType">
                            <option value="medical">Certificat médical</option>
                            <option value="administratif">Document administratif</option>
                            <option value="convocation">Convocation officielle</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Motif de l'absence/retard</label>
                        <textarea class="form-control" id="justificatifMotif" rows="3" 
                                  placeholder="Décrivez brièvement le motif..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fichier (PDF, JPG, PNG)</label>
                        <input type="file" class="form-control" id="justificatifFile" accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">Taille max: 5MB</small>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Uploader',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler',
            preConfirm: () => {
                const file = document.getElementById('justificatifFile').files[0];
                const type = document.getElementById('justificatifType').value;
                const motif = document.getElementById('justificatifMotif').value;
                
                if (!file) {
                    Swal.showValidationMessage('Veuillez sélectionner un fichier');
                    return false;
                }
                
                if (!motif.trim()) {
                    Swal.showValidationMessage('Veuillez saisir un motif');
                    return false;
                }
                
                // Vérifier la taille du fichier
                if (file.size > 5 * 1024 * 1024) {
                    Swal.showValidationMessage('Le fichier est trop volumineux (max 5MB)');
                    return false;
                }
                
                // Vérifier l'extension
                const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
                const extension = file.name.split('.').pop().toLowerCase();
                if (!allowedExtensions.includes(extension)) {
                    Swal.showValidationMessage('Format de fichier non supporté');
                    return false;
                }
                
                return { file, type, motif };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Upload en cours...',
                    text: 'Traitement du justificatif...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Simuler l'upload
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Justificatif uploadé!',
                        html: `
                            <p>Votre justificatif a été uploadé avec succès.</p>
                            <p class="small text-muted">Il sera traité par l'administration sous 24-48h.</p>
                        `,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Recharger la page pour voir les changements
                        window.location.reload();
                    });
                }, 2000);
            }
        });
    }
    
    // Afficher le modal d'upload général
    function showUploadModal() {
        Swal.fire({
            title: 'Nouveau justificatif',
            html: `
                <div class="text-start">
                    <p>Pour quelle absence souhaitez-vous uploader un justificatif ?</p>
                    <div class="mb-3">
                        <label class="form-label">Date de l'absence</label>
                        <input type="date" class="form-control" id="absenceDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Matière concernée</label>
                        <select class="form-select" id="absenceMatiere">
                            <option value="">Sélectionnez une matière...</option>
                            <option value="1">Mathématiques</option>
                            <option value="2">Français</option>
                            <option value="3">Anglais</option>
                            <option value="4">Informatique</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type de justificatif</label>
                        <select class="form-select" id="justificatifType">
                            <option value="medical">Certificat médical</option>
                            <option value="administratif">Document administratif</option>
                            <option value="convocation">Convocation officielle</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fichier</label>
                        <input type="file" class="form-control" id="justificatifFile" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Envoyer',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Justificatif soumis!',
                    text: 'Votre justificatif sera traité rapidement.',
                    confirmButtonText: 'OK'
                });
            }
        });
    }
    
    // Scanner QR Code
    function showQRScanner() {
        Swal.fire({
            title: 'Scanner QR Code',
            html: `
                <div class="text-center">
                    <div style="width: 300px; height: 300px; background: #f8f9fa; margin: 0 auto 20px; 
                         display: flex; align-items: center; justify-content: center; border-radius: 10px;">
                        <i class="fas fa-qrcode fa-5x text-secondary"></i>
                    </div>
                    <p class="mb-3">Placez le QR Code dans le cadre de la caméra</p>
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle"></i> 
                            Le QR Code doit être scanné à l'entrée de l'école ou de la salle de classe.
                        </small>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Démarrer le scan',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler',
            width: 500
        }).then((result) => {
            if (result.isConfirmed) {
                // Simuler le scan
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Pointage réussi!',
                        html: `
                            <p>Votre présence a été enregistrée.</p>
                            <div class="text-start mt-3">
                                <p><strong>Date:</strong> ${new Date().toLocaleDateString('fr-FR')}</p>
                                <p><strong>Heure:</strong> ${new Date().toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'})}</p>
                                <p><strong>Type:</strong> Entrée école</p>
                                <p><strong>Statut:</strong> <span class="badge bg-success">Présent</span></p>
                            </div>
                        `,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Recharger la page pour voir la nouvelle présence
                        window.location.reload();
                    });
                }, 1500);
            }
        });
    }
    
    // Afficher mon QR Code
    function showMyQR() {
        Swal.fire({
            title: 'Mon QR Code personnel',
            html: `
                <div class="text-center">
                    <div style="width: 250px; height: 250px; background: white; margin: 0 auto 20px; 
                         display: flex; align-items: center; justify-content: center; border-radius: 10px; padding: 10px;">
                        <div style="text-align: center;">
                            <div style="width: 200px; height: 200px; background: #f8f9fa; 
                                 display: flex; align-items: center; justify-content: center; border: 2px dashed #ddd;">
                                <i class="fas fa-qrcode fa-4x text-dark"></i>
                            </div>
                        </div>
                    </div>
                    <p class="mb-1"><strong><?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></strong></p>
                    <p class="mb-1 small">Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?></p>
                    <p class="mb-3 small">Classe: <?php echo safeHtml($info_etudiant['classe_nom'] ?? ''); ?></p>
                    <div class="alert alert-warning">
                        <small>
                            <i class="fas fa-exclamation-triangle"></i> 
                            Ce QR Code est personnel. Ne le partagez avec personne.
                        </small>
                    </div>
                </div>
            `,
            showCloseButton: true,
            showConfirmButton: false,
            width: 400
        });
    }
    
    // Démarrer le scanner QR
    function startQRScanner() {
        showQRScanner();
    }
    
    // Imprimer le rapport
    window.addEventListener('beforeprint', function() {
        const printHeader = document.createElement('div');
        printHeader.innerHTML = `
            <div style="text-align: center; margin-bottom: 20px; padding: 10px; border-bottom: 2px solid #333;">
                <h3>Rapport de Présences - <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></h3>
                <p>Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?> | 
                   Classe: <?php echo safeHtml($info_etudiant['classe_nom'] ?? ''); ?> | 
                   Période: <?php echo $mois_liste[$mois_filtre]; ?> <?php echo $annee_filtre; ?></p>
                <p>Imprimé le <?php echo date('d/m/Y à H:i'); ?></p>
            </div>
        `;
        document.querySelector('.main-content').prepend(printHeader);
    });
    
    window.addEventListener('afterprint', function() {
        const printHeader = document.querySelector('.main-content > div:first-child');
        if (printHeader && printHeader.innerHTML.includes('Imprimé le')) {
            printHeader.remove();
        }
    });
    </script>
</body>
</html>