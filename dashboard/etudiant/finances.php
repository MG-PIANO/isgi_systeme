<?php
// dashboard/etudiant/finances.php

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
    $pageTitle = "Situation Financière";
    
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
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'soldee':
            case 'payee':
            case 'valide':
                return '<span class="badge bg-success">Payé</span>';
            case 'partiel':
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            case 'annule':
            case 'annulee':
                return '<span class="badge bg-secondary">Annulé</span>';
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
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    
    // Initialiser toutes les variables
    $stats = array(
        'total_dettes' => 0,
        'total_paye' => 0,
        'total_impaye' => 0,
        'factures_en_attente' => 0,
        'factures_en_retard' => 0,
        'prochaine_echeance' => '',
        'montant_prochaine_echeance' => 0,
        'taux_paiement' => 0
    );
    
    $info_etudiant = array();
    $dettes_detail = array();
    $factures_en_cours = array();
    $historique_paiements = array();
    $echeances_a_venir = array();
    $historique_dettes = array();
    $modes_paiement = array();
    $plans_paiement = array();
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
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $annee_academique_id = 1; // À adapter selon votre logique
        
        // Récupérer les statistiques financières
        // Total des dettes
        $result = executeSingleQuery($db,
            "SELECT COALESCE(SUM(montant_restant), 0) as total_dettes,
                    COALESCE(SUM(montant_paye), 0) as total_paye
             FROM dettes 
             WHERE etudiant_id = ? 
             AND statut IN ('en_cours', 'en_retard')",
            [$etudiant_id]);
        
        $stats['total_dettes'] = isset($result['total_dettes']) ? floatval($result['total_dettes']) : 0;
        $stats['total_paye'] = isset($result['total_paye']) ? floatval($result['total_paye']) : 0;
        $stats['total_impaye'] = $stats['total_dettes'] - $stats['total_paye'];
        
        // Factures en attente
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM factures 
             WHERE etudiant_id = ? 
             AND statut IN ('en_attente', 'partiel')",
            [$etudiant_id]);
        $stats['factures_en_attente'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Factures en retard
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM factures 
             WHERE etudiant_id = ? 
             AND statut = 'en_retard' 
             AND date_echeance < CURDATE()",
            [$etudiant_id]);
        $stats['factures_en_retard'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Prochaine échéance
        $result = executeSingleQuery($db,
            "SELECT MIN(date_echeance) as prochaine_date,
                    SUM(montant_restant) as montant
             FROM factures 
             WHERE etudiant_id = ? 
             AND statut IN ('en_attente', 'partiel')
             AND date_echeance >= CURDATE()",
            [$etudiant_id]);
        
        $stats['prochaine_echeance'] = isset($result['prochaine_date']) ? $result['prochaine_date'] : '';
        $stats['montant_prochaine_echeance'] = isset($result['montant']) ? floatval($result['montant']) : 0;
        
        // Taux de paiement
        $result = executeSingleQuery($db,
            "SELECT COALESCE(SUM(montant_net), 0) as total_factures,
                    COALESCE(SUM(montant_paye), 0) as total_paye_factures
             FROM factures 
             WHERE etudiant_id = ?",
            [$etudiant_id]);
        
        $total_factures = isset($result['total_factures']) ? floatval($result['total_factures']) : 0;
        $total_paye_factures = isset($result['total_paye_factures']) ? floatval($result['total_paye_factures']) : 0;
        
        if ($total_factures > 0) {
            $stats['taux_paiement'] = round(($total_paye_factures / $total_factures) * 100, 1);
        }
        
        // Récupérer les dettes détaillées
        $dettes_detail = executeQuery($db,
            "SELECT d.*, aa.libelle as annee_academique,
                    CONCAT(tf.nom, ' - ', aa.libelle) as description_dette
             FROM dettes d
             JOIN annees_academiques aa ON d.annee_academique_id = aa.id
             LEFT JOIN types_frais tf ON d.type_dette = tf.nom
             WHERE d.etudiant_id = ? 
             AND d.statut IN ('en_cours', 'en_retard')
             ORDER BY d.date_limite ASC",
            [$etudiant_id]);
        
        // Récupérer les factures en cours
        $factures_en_cours = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais, aa.libelle as annee_academique,
                    DATEDIFF(f.date_echeance, CURDATE()) as jours_restants
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             JOIN annees_academiques aa ON f.annee_academique_id = aa.id
             WHERE f.etudiant_id = ? 
             AND f.statut IN ('en_attente', 'partiel', 'en_retard')
             ORDER BY f.date_echeance ASC, f.statut DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer l'historique des paiements
        $historique_paiements = executeQuery($db,
            "SELECT p.*, tf.nom as type_frais, aa.libelle as annee_academique,
                    CONCAT(u.nom, ' ', u.prenom) as caissier_nom
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             JOIN annees_academiques aa ON p.annee_academique_id = aa.id
             LEFT JOIN utilisateurs u ON p.caissier_id = u.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'valide'
             ORDER BY p.date_paiement DESC
             LIMIT 15",
            [$etudiant_id]);
        
        // Récupérer les échéances à venir
        $echeances_a_venir = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais,
                    DATEDIFF(f.date_echeance, CURDATE()) as jours_restants
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             WHERE f.etudiant_id = ? 
             AND f.statut IN ('en_attente', 'partiel')
             AND f.date_echeance >= CURDATE()
             ORDER BY f.date_echeance ASC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer l'historique des dettes
        $historique_dettes = executeQuery($db,
            "SELECT d.*, aa.libelle as annee_academique,
                    CONCAT(tf.nom, ' - ', aa.libelle) as description_dette
             FROM dettes d
             JOIN annees_academiques aa ON d.annee_academique_id = aa.id
             LEFT JOIN types_frais tf ON d.type_dette = tf.nom
             WHERE d.etudiant_id = ? 
             ORDER BY d.date_creation DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les modes de paiement disponibles
        $modes_paiement = executeQuery($db,
            "SELECT * FROM modes_paiement WHERE active = 1 ORDER BY ordre ASC");
        
        // Récupérer les plans de paiement
        $plans_paiement = executeQuery($db,
            "SELECT pp.*, d.type_dette, d.montant_du as montant_total_dette
             FROM plans_paiement pp
             JOIN dettes d ON pp.dette_id = d.id
             WHERE d.etudiant_id = ? 
             AND pp.statut = 'actif'
             ORDER BY pp.date_debut ASC",
            [$etudiant_id]);
            
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
    <title><?php echo safeHtml($pageTitle); ?> - Dashboard Étudiant</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
    
    /* Sidebar - Même style que le dashboard principal */
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
        padding: 12px;
        font-weight: 600;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px;
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
        border: 1px solid var(--border-color);
        border-bottom: none;
        margin-right: 5px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
        height: 10px;
        border-radius: 5px;
        margin: 5px 0;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
        border-radius: 5px;
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
        border-left: 4px solid;
    }
    
    .alert-info {
        border-left-color: var(--info-color);
        background-color: rgba(23, 162, 184, 0.1);
    }
    
    .alert-success {
        border-left-color: var(--success-color);
        background-color: rgba(39, 174, 96, 0.1);
    }
    
    .alert-warning {
        border-left-color: var(--warning-color);
        background-color: rgba(243, 156, 18, 0.1);
    }
    
    .alert-danger {
        border-left-color: var(--accent-color);
        background-color: rgba(231, 76, 60, 0.1);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
        font-weight: 600;
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    /* Boutons d'action */
    .action-btn {
        padding: 8px 15px;
        border-radius: 6px;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
    
    /* Widgets financiers */
    .finance-widget {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        border: 1px solid var(--border-color);
    }
    
    .finance-widget.alert {
        border-left-width: 5px;
    }
    
    /* Timeline des paiements */
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--border-color);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -23px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--primary-color);
    }
    
    .timeline-item.success::before {
        background-color: var(--success-color);
    }
    
    .timeline-item.warning::before {
        background-color: var(--warning-color);
    }
    
    .timeline-item.danger::before {
        background-color: var(--accent-color);
    }
    
    /* Montants */
    .amount {
        font-weight: 600;
        font-size: 1.1em;
    }
    
    .amount.positive {
        color: var(--success-color);
    }
    
    .amount.negative {
        color: var(--accent-color);
    }
    
    /* Section d'impression */
    .print-section {
        display: none;
    }
    
    @media print {
        .sidebar, .no-print {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .print-section {
            display: block;
        }
        
        .card {
            box-shadow: none !important;
            border: 1px solid #000 !important;
        }
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique au dashboard principal) -->
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
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances</div>
                    <a href="finances.php" class="nav-link active">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Situation Financière</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Dettes</span>
                    </a>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-credit-card"></i>
                        <span>Paiements</span>
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
                            <i class="fas fa-money-bill-wave me-2"></i>
                            Situation Financière
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                            Matricule: <?php echo safeHtml($info_etudiant['matricule']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section d'impression (visible uniquement à l'impression) -->
            <div class="print-section mb-4">
                <h4>Situation Financière - <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></h4>
                <p>Date: <?php echo date('d/m/Y H:i'); ?></p>
            </div>
            
            <!-- Section 1: Vue d'ensemble financière -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['total_dettes']); ?></div>
                        <div class="stat-label">Total des Dettes</div>
                        <div class="stat-change">
                            <?php if($stats['total_impaye'] > 0): ?>
                            <span class="negative"><i class="fas fa-exclamation-triangle"></i> Reste: <?php echo formatMoney($stats['total_impaye']); ?></span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> À jour</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['total_paye']); ?></div>
                        <div class="stat-label">Total Payé</div>
                        <div class="stat-change">
                            <span class="<?php echo $stats['taux_paiement'] >= 80 ? 'positive' : 'warning'; ?>">
                                <i class="fas fa-percentage"></i> <?php echo $stats['taux_paiement']; ?>% de paiement
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['factures_en_retard']; ?></div>
                        <div class="stat-label">Factures en Retard</div>
                        <div class="stat-change">
                            <?php if($stats['factures_en_retard'] > 0): ?>
                            <span class="negative"><i class="fas fa-clock"></i> À régler d'urgence</span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check"></i> Aucun retard</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-value">
                            <?php if(!empty($stats['prochaine_echeance'])): ?>
                            <?php echo formatDateFr($stats['prochaine_echeance'], 'd/m'); ?>
                            <?php else: ?>
                            Aucune
                            <?php endif; ?>
                        </div>
                        <div class="stat-label">Prochaine Échéance</div>
                        <div class="stat-change">
                            <?php if(!empty($stats['prochaine_echeance'])): ?>
                            <span class="warning"><i class="fas fa-money-bill"></i> <?php echo formatMoney($stats['montant_prochaine_echeance']); ?></span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check"></i> Aucune échéance</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alertes importantes -->
            <?php if($stats['total_impaye'] > 0 || $stats['factures_en_retard'] > 0): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="finance-widget alert alert-danger">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                                <strong>Attention!</strong> 
                                <?php if($stats['factures_en_retard'] > 0): ?>
                                Vous avez <?php echo $stats['factures_en_retard']; ?> facture(s) en retard.
                                <?php endif; ?>
                                <?php if($stats['total_impaye'] > 0): ?>
                                Montant total impayé: <strong><?php echo formatMoney($stats['total_impaye']); ?></strong>
                                <?php endif; ?>
                            </div>
                            <a href="factures.php" class="action-btn btn btn-danger">
                                <i class="fas fa-arrow-right"></i> Régler maintenant
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section 2: Graphiques et répartition -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Répartition des Dettes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="dettesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Évolution des Paiements
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="paiementsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="financesTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="factures-tab" data-bs-toggle="tab" data-bs-target="#factures" type="button">
                                <i class="fas fa-file-invoice me-2"></i>Factures en Cours
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="paiements-tab" data-bs-toggle="tab" data-bs-target="#paiements" type="button">
                                <i class="fas fa-history me-2"></i>Historique Paiements
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="echeances-tab" data-bs-toggle="tab" data-bs-target="#echeances" type="button">
                                <i class="fas fa-calendar-day me-2"></i>Échéances à Venir
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="dettes-tab" data-bs-toggle="tab" data-bs-target="#dettes" type="button">
                                <i class="fas fa-exclamation-triangle me-2"></i>Détail des Dettes
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="financesTabsContent">
                        <!-- Tab 1: Factures en Cours -->
                        <div class="tab-pane fade show active" id="factures">
                            <?php if(empty($factures_en_cours)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune facture en cours
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>N° Facture</th>
                                            <th>Type</th>
                                            <th>Montant Total</th>
                                            <th>Payé</th>
                                            <th>Restant</th>
                                            <th>Échéance</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($factures_en_cours as $facture): 
                                            $jours_restants = isset($facture['jours_restants']) ? intval($facture['jours_restants']) : 0;
                                            $statut_class = '';
                                            if ($facture['statut'] == 'en_retard') $statut_class = 'danger';
                                            elseif ($facture['statut'] == 'partiel') $statut_class = 'warning';
                                            else $statut_class = 'info';
                                        ?>
                                        <tr>
                                            <td><strong><?php echo safeHtml($facture['numero_facture'] ?? ''); ?></strong></td>
                                            <td><?php echo safeHtml($facture['type_frais'] ?? ''); ?></td>
                                            <td class="amount"><?php echo formatMoney($facture['montant_total'] ?? 0); ?></td>
                                            <td class="amount positive"><?php echo formatMoney($facture['montant_paye'] ?? 0); ?></td>
                                            <td class="amount negative"><?php echo formatMoney($facture['montant_restant'] ?? 0); ?></td>
                                            <td>
                                                <?php echo formatDateFr($facture['date_echeance'] ?? ''); ?>
                                                <?php if($jours_restants >= 0): ?>
                                                <br><small class="text-<?php echo $jours_restants <= 3 ? 'danger' : ($jours_restants <= 7 ? 'warning' : 'muted'); ?>">
                                                    <?php echo $jours_restants; ?> jour(s)
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatutBadge($facture['statut'] ?? ''); ?></td>
                                            <td>
                                                <?php if($facture['montant_restant'] > 0): ?>
                                                <button class="btn btn-sm btn-primary" onclick="payerFacture(<?php echo $facture['id']; ?>)">
                                                    <i class="fas fa-credit-card"></i> Payer
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Résumé des factures -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="finance-widget alert alert-info">
                                        <h6><i class="fas fa-info-circle me-2"></i> Informations importantes</h6>
                                        <ul class="mb-0 small">
                                            <li>Les factures en retard peuvent entraîner des sanctions</li>
                                            <li>Le paiement partiel doit être complété avant l'échéance</li>
                                            <li>Conservez vos reçus de paiement</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="finance-widget">
                                        <h6><i class="fas fa-percentage me-2"></i> Taux de paiement</h6>
                                        <div class="progress">
                                            <div class="progress-bar bg-<?php echo $stats['taux_paiement'] >= 80 ? 'success' : ($stats['taux_paiement'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $stats['taux_paiement']; ?>%">
                                                <?php echo $stats['taux_paiement']; ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted">Progression de vos paiements</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Historique des Paiements -->
                        <div class="tab-pane fade" id="paiements">
                            <?php if(empty($historique_paiements)): ?>
                            <div class="alert alert-info">
                                Aucun historique de paiement disponible
                            </div>
                            <?php else: ?>
                            <div class="timeline">
                                <?php foreach($historique_paiements as $paiement): 
                                    $statut_class = 'success'; // Tous les paiements affichés sont validés
                                ?>
                                <div class="timeline-item <?php echo $statut_class; ?>">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo safeHtml($paiement['type_frais'] ?? ''); ?></h6>
                                                    <p class="mb-1 small">
                                                        Référence: <strong><?php echo safeHtml($paiement['reference'] ?? ''); ?></strong><br>
                                                        Mode: <?php echo safeHtml($paiement['mode_paiement'] ?? ''); ?>
                                                        <?php if(!empty($paiement['numero_transaction'])): ?>
                                                        - N°: <?php echo safeHtml($paiement['numero_transaction']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <div class="amount positive"><?php echo formatMoney($paiement['montant'] ?? 0); ?></div>
                                                    <small class="text-muted"><?php echo formatDateFr($paiement['date_paiement'] ?? '', 'd/m/Y'); ?></small>
                                                    <?php if(!empty($paiement['caissier_nom'])): ?>
                                                    <br><small>Par: <?php echo safeHtml($paiement['caissier_nom']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if(!empty($paiement['commentaires'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted"><?php echo safeHtml($paiement['commentaires']); ?></small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Statistiques des paiements -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="finance-widget">
                                        <h6><i class="fas fa-chart-bar me-2"></i> Modes de paiement utilisés</h6>
                                        <canvas id="modesPaiementChart" height="150"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="finance-widget">
                                        <h6><i class="fas fa-download me-2"></i> Téléchargements</h6>
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-outline-primary" onclick="genererReleve()">
                                                <i class="fas fa-file-pdf"></i> Relevé de paiement
                                            </button>
                                            <button class="btn btn-outline-success" onclick="genererAttestation()">
                                                <i class="fas fa-file-certificate"></i> Attestation de règlement
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Échéances à Venir -->
                        <div class="tab-pane fade" id="echeances">
                            <?php if(empty($echeances_a_venir)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune échéance à venir
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Montant</th>
                                            <th>Jours restants</th>
                                            <th>Priorité</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($echeances_a_venir as $echeance): 
                                            $jours_restants = isset($echeance['jours_restants']) ? intval($echeance['jours_restants']) : 0;
                                            $priorite = '';
                                            $priorite_class = '';
                                            if ($jours_restants <= 3) {
                                                $priorite = 'Haute';
                                                $priorite_class = 'danger';
                                            } elseif ($jours_restants <= 7) {
                                                $priorite = 'Moyenne';
                                                $priorite_class = 'warning';
                                            } else {
                                                $priorite = 'Basse';
                                                $priorite_class = 'info';
                                            }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo formatDateFr($echeance['date_echeance'] ?? ''); ?></strong></td>
                                            <td><?php echo safeHtml($echeance['type_frais'] ?? ''); ?></td>
                                            <td class="amount negative"><?php echo formatMoney($echeance['montant_restant'] ?? 0); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $priorite_class; ?>">
                                                    <?php echo $jours_restants; ?> jour(s)
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $priorite_class; ?>">
                                                    <?php echo $priorite; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="programmerPaiement(<?php echo $echeance['id']; ?>)">
                                                    <i class="fas fa-calendar-plus"></i> Programmer
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="payerMaintenant(<?php echo $echeance['id']; ?>)">
                                                    <i class="fas fa-credit-card"></i> Payer
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Calendrier des échéances -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="finance-widget">
                                        <h6><i class="fas fa-calendar-alt me-2"></i> Calendrier des échéances</h6>
                                        <div id="calendrierEcheances" style="height: 300px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Détail des Dettes -->
                        <div class="tab-pane fade" id="dettes">
                            <?php if(empty($dettes_detail)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune dette enregistrée
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Année Académique</th>
                                            <th>Montant dû</th>
                                            <th>Payé</th>
                                            <th>Restant</th>
                                            <th>Date limite</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($dettes_detail as $dette): 
                                            $pourcentage_paye = $dette['montant_du'] > 0 ? ($dette['montant_paye'] / $dette['montant_du']) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo safeHtml($dette['description_dette'] ?? $dette['type_dette'] ?? ''); ?></td>
                                            <td><?php echo safeHtml($dette['annee_academique'] ?? ''); ?></td>
                                            <td class="amount"><?php echo formatMoney($dette['montant_du'] ?? 0); ?></td>
                                            <td class="amount positive"><?php echo formatMoney($dette['montant_paye'] ?? 0); ?></td>
                                            <td class="amount negative"><?php echo formatMoney($dette['montant_restant'] ?? 0); ?></td>
                                            <td><?php echo formatDateFr($dette['date_limite'] ?? ''); ?></td>
                                            <td><?php echo getStatutBadge($dette['statut'] ?? ''); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="voirDetailsDette(<?php echo $dette['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-success" onclick="proposerPlanPaiement(<?php echo $dette['id']; ?>)">
                                                        <i class="fas fa-calendar-check"></i>
                                                    </button>
                                                    <button class="btn btn-outline-danger" onclick="contesterDette(<?php echo $dette['id']; ?>)">
                                                        <i class="fas fa-question-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Plans de paiement -->
                            <?php if(!empty($plans_paiement)): ?>
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h5><i class="fas fa-calendar-check me-2"></i> Plans de Paiement Actifs</h5>
                                    <div class="row">
                                        <?php foreach($plans_paiement as $plan): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card">
                                                <div class="card-body">
                                                    <h6 class="card-title">Plan #<?php echo $plan['id']; ?></h6>
                                                    <p class="card-text small">
                                                        <strong>Montant total:</strong> <?php echo formatMoney($plan['montant_total'] ?? 0); ?><br>
                                                        <strong>Nombre de tranches:</strong> <?php echo $plan['nombre_tranches'] ?? 0; ?><br>
                                                        <strong>Tranche mensuelle:</strong> <?php echo formatMoney($plan['montant_tranche'] ?? 0); ?><br>
                                                        <strong>Début:</strong> <?php echo formatDateFr($plan['date_debut'] ?? ''); ?><br>
                                                        <strong>Période:</strong> <?php echo ucfirst($plan['periode'] ?? ''); ?>
                                                    </p>
                                                    <div class="progress mb-2">
                                                        <div class="progress-bar" role="progressbar" 
                                                             style="width: <?php echo rand(30, 80); ?>%">
                                                            <?php echo rand(30, 80); ?>%
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">Progression du plan</small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Résumé et recommandations -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>
                                Recommandations
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <?php if($stats['factures_en_retard'] > 0): ?>
                                <div class="list-group-item list-group-item-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong>Réglez immédiatement</strong> vos factures en retard pour éviter les sanctions
                                </div>
                                <?php endif; ?>
                                
                                <?php if(!empty($stats['prochaine_echeance']) && strtotime($stats['prochaine_echeance']) - time() < 7*24*60*60): ?>
                                <div class="list-group-item list-group-item-warning">
                                    <i class="fas fa-clock me-2"></i>
                                    <strong>Échéance proche:</strong> Prévoyez le paiement avant le <?php echo formatDateFr($stats['prochaine_echeance']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if($stats['taux_paiement'] < 50): ?>
                                <div class="list-group-item list-group-item-info">
                                    <i class="fas fa-percentage me-2"></i>
                                    <strong>Taux de paiement bas:</strong> Considérez un plan de paiement pour étaler vos frais
                                </div>
                                <?php endif; ?>
                                
                                <?php if(empty($stats['prochaine_echeance']) && $stats['total_impaye'] == 0): ?>
                                <div class="list-group-item list-group-item-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>Félicitations!</strong> Votre situation financière est à jour
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
                                <i class="fas fa-question-circle me-2"></i>
                                Aide & Support
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-phone-alt"></i> Contacts</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Service financier:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Email:</strong> finances@isgi.cg</li>
                                    <li><strong>Horaires:</strong> 8h00 - 16h00 (Lun-Ven)</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-info-circle"></i> Informations importantes</h6>
                                <ul class="mb-0 small">
                                    <li>Les retards de paiement peuvent entraîner la suspension des cours</li>
                                    <li>Conservez tous vos reçus de paiement</li>
                                    <li>Les plans de paiement doivent être approuvés à l'avance</li>
                                </ul>
                            </div>
                        </div>
                    </div>
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
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
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
        
        // Graphique des dettes
        const ctxDettes = document.getElementById('dettesChart').getContext('2d');
        new Chart(ctxDettes, {
            type: 'doughnut',
            data: {
                labels: ['Scolarité', 'Inscription', 'Examens', 'Autres frais'],
                datasets: [{
                    data: [70, 15, 10, 5],
                    backgroundColor: [
                        '#3498db',
                        '#2ecc71',
                        '#e74c3c',
                        '#f39c12'
                    ],
                    borderWidth: 1
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
                        text: 'Répartition des dettes par type'
                    }
                }
            }
        });
        
        // Graphique des paiements
        const ctxPaiements = document.getElementById('paiementsChart').getContext('2d');
        new Chart(ctxPaiements, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                datasets: [{
                    label: 'Montant payé (FCFA)',
                    data: [150000, 120000, 180000, 200000, 220000, 190000, 210000, 230000, 240000, 220000, 250000, 260000],
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
                        display: true,
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Évolution des paiements mensuels'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
                            }
                        }
                    }
                }
            }
        });
        
        // Graphique des modes de paiement
        const ctxModes = document.getElementById('modesPaiementChart').getContext('2d');
        new Chart(ctxModes, {
            type: 'pie',
            data: {
                labels: ['Espèces', 'Mobile Money', 'Virement', 'Chèque'],
                datasets: [{
                    data: [40, 35, 20, 5],
                    backgroundColor: [
                        '#2ecc71',
                        '#3498db',
                        '#9b59b6',
                        '#f39c12'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    });
    
    // Fonctions pour les actions
    function payerFacture(factureId) {
        alert('Paiement de la facture #' + factureId + '\nRedirection vers le module de paiement...');
        // window.location.href = 'payer.php?id=' + factureId;
    }
    
    function programmerPaiement(echeanceId) {
        alert('Programmation du paiement pour l\'échéance #' + echeanceId);
        // window.location.href = 'programmer.php?id=' + echeanceId;
    }
    
    function payerMaintenant(echeanceId) {
        if(confirm('Voulez-vous procéder au paiement immédiat ?')) {
            payerFacture(echeanceId);
        }
    }
    
    function voirDetailsDette(detteId) {
        alert('Affichage des détails de la dette #' + detteId);
        // window.location.href = 'dette_details.php?id=' + detteId;
    }
    
    function proposerPlanPaiement(detteId) {
        alert('Proposition de plan de paiement pour la dette #' + detteId);
        // window.location.href = 'plan_paiement.php?id=' + detteId;
    }
    
    function contesterDette(detteId) {
        const motif = prompt('Veuillez indiquer le motif de contestation:');
        if(motif) {
            alert('Contestation envoyée pour la dette #' + detteId + '\nMotif: ' + motif);
            // Envoyer la contestation via AJAX
        }
    }
    
    function genererReleve() {
        alert('Génération du relevé de paiement en cours...\nLe fichier PDF sera téléchargé.');
        // window.location.href = 'generer_releve.php';
    }
    
    function genererAttestation() {
        alert('Génération de l\'attestation de règlement en cours...\nLe fichier PDF sera téléchargé.');
        // window.location.href = 'generer_attestation.php';
    }
    
    // Calendrier des échéances
    function initCalendrierEcheances() {
        const echeances = [
            { title: 'Scolarité - Janvier', start: new Date().toISOString().split('T')[0], color: '#3498db' },
            { title: 'Inscription annuelle', start: new Date(Date.now() + 5*24*60*60*1000).toISOString().split('T')[0], color: '#2ecc71' },
            { title: 'Frais d\'examens', start: new Date(Date.now() + 10*24*60*60*1000).toISOString().split('T')[0], color: '#e74c3c' },
            { title: 'Scolarité - Février', start: new Date(Date.now() + 30*24*60*60*1000).toISOString().split('T')[0], color: '#3498db' }
        ];
        
        const calendrierEl = document.getElementById('calendrierEcheances');
        if (calendrierEl) {
            // Simple affichage des dates
            let html = '<div class="list-group">';
            echeances.forEach(echeance => {
                const date = new Date(echeance.start);
                const joursRestants = Math.ceil((date - new Date()) / (1000 * 60 * 60 * 24));
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge" style="background-color: ${echeance.color}">&nbsp;&nbsp;</span>
                                <strong>${echeance.title}</strong>
                            </div>
                            <div>
                                <span class="badge bg-${joursRestants <= 7 ? 'warning' : 'info'}">
                                    ${date.toLocaleDateString('fr-FR')} (J-${joursRestants})
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            calendrierEl.innerHTML = html;
        }
    }
    
    // Initialiser le calendrier
    initCalendrierEcheances();
    </script>
</body>
</html>