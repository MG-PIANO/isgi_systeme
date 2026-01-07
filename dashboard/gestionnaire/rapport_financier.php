<?php
// dashboard/gestionnaire_principal/rapport_financier.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    $db = Database::getInstance()->getConnection();
    $pageTitle = "Rapports Financiers";
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
    // Récupérer les paramètres de filtre
    $mois = isset($_GET['mois']) ? $_GET['mois'] : date('m');
    $annee = isset($_GET['annee']) ? $_GET['annee'] : date('Y');
    $type_rapport = isset($_GET['type']) ? $_GET['type'] : 'mensuel';
    
    // Récupérer le nom du site
    $site_nom = '';
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
    // Compter les demandes en attente
    $query = "SELECT COUNT(*) as count FROM demande_inscriptions WHERE statut = 'en_attente'";
    if ($site_id) {
        $query .= " AND site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $demandeCount = $result['count'] ?? 0;
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    // Récupérer les statistiques générales
    $query = "SELECT 
                COUNT(DISTINCT p.etudiant_id) as etudiants_actifs,
                COUNT(p.id) as total_transactions,
                SUM(p.montant) as revenu_total,
                AVG(p.montant) as moyenne_paiement,
                MIN(p.montant) as paiement_min,
                MAX(p.montant) as paiement_max
              FROM paiements p
              WHERE p.statut = 'valide'
              AND YEAR(p.date_paiement) = ?
              AND MONTH(p.date_paiement) = ?";
    
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = p.etudiant_id AND e.site_id = ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$annee, $mois, $site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute([$annee, $mois]);
    }
    
    $stats_generales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les revenus par type de frais
    $query = "SELECT 
                tf.nom as type_frais,
                COUNT(p.id) as nombre_paiements,
                SUM(p.montant) as total_revenu,
                AVG(p.montant) as moyenne,
                MIN(p.date_paiement) as premier_paiement,
                MAX(p.date_paiement) as dernier_paiement
              FROM paiements p
              JOIN types_frais tf ON p.type_frais_id = tf.id
              WHERE p.statut = 'valide'
              AND YEAR(p.date_paiement) = ?
              AND MONTH(p.date_paiement) = ?";
    
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = p.etudiant_id AND e.site_id = ?)";
        $query .= " GROUP BY tf.id, tf.nom ORDER BY total_revenu DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$annee, $mois, $site_id]);
    } else {
        $query .= " GROUP BY tf.id, tf.nom ORDER BY total_revenu DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$annee, $mois]);
    }
    
    $revenus_par_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les revenus par site (si admin principal)
    $revenus_par_site = [];
    if (!$site_id) {
        $query = "SELECT 
                    s.nom as site,
                    COUNT(p.id) as nombre_paiements,
                    SUM(p.montant) as total_revenu,
                    COUNT(DISTINCT p.etudiant_id) as etudiants_payants
                  FROM paiements p
                  JOIN etudiants e ON p.etudiant_id = e.id
                  JOIN sites s ON e.site_id = s.id
                  WHERE p.statut = 'valide'
                  AND YEAR(p.date_paiement) = ?
                  AND MONTH(p.date_paiement) = ?
                  GROUP BY s.id, s.nom
                  ORDER BY total_revenu DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$annee, $mois]);
        $revenus_par_site = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Récupérer l'évolution mensuelle
    $query = "SELECT 
                DATE_FORMAT(p.date_paiement, '%Y-%m') as mois,
                COUNT(p.id) as nombre_paiements,
                SUM(p.montant) as total_revenu,
                COUNT(DISTINCT p.etudiant_id) as etudiants_uniques
              FROM paiements p
              WHERE p.statut = 'valide'";
    
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = p.etudiant_id AND e.site_id = ?)";
        $query .= " GROUP BY DATE_FORMAT(p.date_paiement, '%Y-%m')
                  ORDER BY DATE_FORMAT(p.date_paiement, '%Y-%m') DESC
                  LIMIT 12";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " GROUP BY DATE_FORMAT(p.date_paiement, '%Y-%m')
                  ORDER BY DATE_FORMAT(p.date_paiement, '%Y-%m') DESC
                  LIMIT 12";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    
    $evolution_mensuelle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les paiements en attente
    $query = "SELECT 
                p.*,
                e.nom,
                e.prenom,
                e.matricule,
                tf.nom as type_frais
              FROM paiements p
              JOIN etudiants e ON p.etudiant_id = e.id
              JOIN types_frais tf ON p.type_frais_id = tf.id
              WHERE p.statut = 'en_attente'";
    
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $query .= " ORDER BY p.date_paiement DESC LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " ORDER BY p.date_paiement DESC LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    
    $paiements_en_attente = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI Finances</title>
    
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
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
    }
    
    .progress-bar {
        background-color: var(--primary-color);
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
    
    /* Styles spécifiques pour gestionnaire */
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .action-buttons .btn {
        flex: 1;
        min-width: 150px;
    }
    
    .dette-high {
        background-color: rgba(231, 76, 60, 0.1) !important;
    }
    
    .dette-medium {
        background-color: rgba(243, 156, 18, 0.1) !important;
    }
    
    .dette-low {
        background-color: rgba(52, 152, 219, 0.1) !important;
    }
    
    .transaction-en_attente {
        background-color: rgba(243, 156, 18, 0.1) !important;
    }
    
    .transaction-valide {
        background-color: rgba(39, 174, 96, 0.1) !important;
    }
    
    .transaction-annule {
        background-color: rgba(231, 76, 60, 0.1) !important;
    }
    
    /* Styles spécifiques pour les rapports */
    .report-section {
        margin-bottom: 30px;
    }
    
    .filter-card {
        background: var(--card-bg);
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        border: 1px solid var(--border-color);
    }
    
    .kpi-card {
        text-align: center;
        padding: 20px;
        background: var(--card-bg);
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        border: 1px solid var(--border-color);
    }
    
    .kpi-value {
        font-size: 2rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    
    .kpi-label {
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    
    .insight-card {
        border-left: 4px solid var(--secondary-color);
        padding: 15px;
        margin-bottom: 15px;
        background: var(--card-bg);
        border-radius: 5px;
        border: 1px solid var(--border-color);
    }
    
    .insight-icon {
        font-size: 2rem;
        margin-right: 10px;
        color: var(--secondary-color);
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI FINANCES</h5>
                <div class="user-role">Gestionnaire Principal</div>
                <?php if($site_nom): ?>
                <small><?php echo htmlspecialchars($site_nom); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></p>
                <small>Gestion Financière</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Financier</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="inscriptions.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Inscriptions</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Demandes</span>
                        <?php if ($demandeCount > 0): ?>
                        <span class="nav-badge"><?php echo $demandeCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Paiements & Dettes</div>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Dettes Étudiantes</span>
                    </a>
                    <a href="paiements_en_ligne.php" class="nav-link">
                        <i class="fas fa-globe"></i>
                        <span>Paiements en Ligne</span>
                    </a>
                    <a href="paiements_presentiel.php" class="nav-link">
                        <i class="fas fa-store"></i>
                        <span>Paiements Présentiel</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Financière</div>
                    <a href="tarifs.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        <span>Tarifs & Frais</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice"></i>
                        <span>Factures & Reçus</span>
                    </a>
                    <a href="caisse.php" class="nav-link">
                        <i class="fas fa-cash-register"></i>
                        <span>Gestion de Caisse</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Analyses</div>
                    <a href="rapport_financier.php" class="nav-link active">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports Financiers</span>
                    </a>
                    <a href="analytique_financiere.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Analytique Financière</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Administration</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="notifications.php" class="nav-link">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                    </a>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier</span>
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
                            <i class="fas fa-chart-bar text-primary me-2"></i>
                            Rapports Financiers
                        </h2>
                        <p class="text-muted mb-0">
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                            - <?php echo date('F Y', strtotime($annee . '-' . $mois . '-01')); ?>
                        </p>
                    </div>
                    <div class="action-buttons">
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> Retour
                        </a>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-info" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <a href="generer_rapport_dettes.php" class="btn btn-warning">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="filter-card">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Type de rapport</label>
                                <select name="type" class="form-select">
                                    <option value="mensuel" <?php echo $type_rapport == 'mensuel' ? 'selected' : ''; ?>>Mensuel</option>
                                    <option value="trimestriel" <?php echo $type_rapport == 'trimestriel' ? 'selected' : ''; ?>>Trimestriel</option>
                                    <option value="annuel" <?php echo $type_rapport == 'annuel' ? 'selected' : ''; ?>>Annuel</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Mois</label>
                                <select name="mois" class="form-select">
                                    <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>" 
                                            <?php echo $mois == sprintf('%02d', $i) ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Année</label>
                                <select name="annee" class="form-select">
                                    <?php for($i = date('Y'); $i >= 2020; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $annee == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Appliquer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger mt-3">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Statistiques Générales -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats_generales['revenu_total'] ?? 0); ?></div>
                        <div class="stat-label">Revenu Total</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_generales['total_transactions'] ?? 0; ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats_generales['etudiants_actifs'] ?? 0; ?></div>
                        <div class="stat-label">Étudiants Actifs</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats_generales['moyenne_paiement'] ?? 0); ?></div>
                        <div class="stat-label">Moyenne/Paiement</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 1: Revenus par Type de Frais -->
            <div class="row mt-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                Répartition des Revenus par Type de Frais
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($revenus_par_type)): ?>
                            <div class="alert alert-info">
                                Aucune donnée disponible pour cette période
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Type de Frais</th>
                                            <th>Nombre</th>
                                            <th>Total Revenu</th>
                                            <th>Moyenne</th>
                                            <th>Premier</th>
                                            <th>Dernier</th>
                                            <th>%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_general = array_sum(array_column($revenus_par_type, 'total_revenu'));
                                        foreach($revenus_par_type as $revenu): 
                                            $pourcentage = $total_general > 0 ? ($revenu['total_revenu'] / $total_general * 100) : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($revenu['type_frais']); ?></strong></td>
                                            <td><?php echo $revenu['nombre_paiements']; ?></td>
                                            <td class="text-success fw-bold"><?php echo formatMoney($revenu['total_revenu']); ?></td>
                                            <td><?php echo formatMoney($revenu['moyenne']); ?></td>
                                            <td><?php echo formatDateFr($revenu['premier_paiement']); ?></td>
                                            <td><?php echo formatDateFr($revenu['dernier_paiement']); ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?php echo $pourcentage; ?>%">
                                                        <?php echo number_format($pourcentage, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-primary">
                                            <td><strong>TOTAL</strong></td>
                                            <td><strong><?php echo array_sum(array_column($revenus_par_type, 'nombre_paiements')); ?></strong></td>
                                            <td class="text-success fw-bold"><?php echo formatMoney($total_general); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td>100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Graphique de Répartition
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="typeFraisChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Évolution Mensuelle -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Évolution des Revenus (12 derniers mois)
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="evolutionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Revenus par Site (si admin principal) -->
            <?php if(!$site_id && !empty($revenus_par_site)): ?>
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-building me-2"></i>
                                Performance par Site
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Site</th>
                                            <th>Étudiants Payants</th>
                                            <th>Transactions</th>
                                            <th>Revenu Total</th>
                                            <th>Moyenne/Étudiant</th>
                                            <th>Taux de Conversion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($revenus_par_site as $site): 
                                            $taux_conversion = $site['etudiants_payants'] > 0 ? 
                                                ($site['nombre_paiements'] / $site['etudiants_payants'] * 100) : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($site['site']); ?></strong></td>
                                            <td><?php echo $site['etudiants_payants']; ?></td>
                                            <td><?php echo $site['nombre_paiements']; ?></td>
                                            <td class="text-success fw-bold"><?php echo formatMoney($site['total_revenu']); ?></td>
                                            <td><?php echo formatMoney($site['total_revenu'] / max($site['etudiants_payants'], 1)); ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-info" role="progressbar" 
                                                         style="width: <?php echo min($taux_conversion, 100); ?>%">
                                                        <?php echo number_format($taux_conversion, 1); ?>%
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
            <?php endif; ?>
            
            <!-- Section 4: Paiements en Attente -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2 text-warning"></i>
                                Paiements en Attente de Validation
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($paiements_en_attente)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucun paiement en attente
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Étudiant</th>
                                            <th>Type de Frais</th>
                                            <th>Montant</th>
                                            <th>Méthode</th>
                                            <th>Référence</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($paiements_en_attente as $paiement): ?>
                                        <tr class="table-warning">
                                            <td><?php echo formatDateFr($paiement['date_paiement']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($paiement['nom'] . ' ' . $paiement['prenom']); ?>
                                                <br><small><?php echo htmlspecialchars($paiement['matricule']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($paiement['type_frais']); ?></td>
                                            <td class="fw-bold"><?php echo formatMoney($paiement['montant']); ?></td>
                                            <td><?php echo htmlspecialchars($paiement['mode_paiement']); ?></td>
                                            <td><code><?php echo htmlspecialchars($paiement['reference']); ?></code></td>
                                            <td>
                                                <a href="valider_paiement.php?id=<?php echo $paiement['id']; ?>" 
                                                   class="btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="annuler_paiement.php?id=<?php echo $paiement['id']; ?>" 
                                                   class="btn btn-danger btn-sm">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Section 5: Résumé et Recommandations -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>
                            Résumé et Analyse
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-chart-line me-2 text-primary"></i> Tendances</h6>
                                <ul class="list-group">
                                    <li class="list-group-item">
                                        <strong>Revenu Moyen Mensuel:</strong> 
                                        <?php 
                                        $revenus_moyen = array_sum(array_column($evolution_mensuelle, 'total_revenu')) / max(count($evolution_mensuelle), 1);
                                        echo formatMoney($revenus_moyen);
                                        ?>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Croissance:</strong> 
                                        <?php 
                                        if(count($evolution_mensuelle) > 1) {
                                            $premier = $evolution_mensuelle[count($evolution_mensuelle)-1]['total_revenu'];
                                            $dernier = $evolution_mensuelle[0]['total_revenu'];
                                            $croissance = $premier > 0 ? (($dernier - $premier) / $premier * 100) : 0;
                                            echo number_format($croissance, 1) . '%';
                                        } else {
                                            echo 'Données insuffisantes';
                                        }
                                        ?>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Fidélité Étudiants:</strong> 
                                        <?php 
                                        $taux_fidelite = $stats_generales['etudiants_actifs'] > 0 ? 
                                            ($stats_generales['total_transactions'] / $stats_generales['etudiants_actifs']) : 0;
                                        echo number_format($taux_fidelite, 2) . ' transactions/étudiant';
                                        ?>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-lightbulb me-2 text-warning"></i> Recommandations</h6>
                                <div class="alert alert-info">
                                    <?php
                                    $recommandations = [];
                                    
                                    if(($paiements_en_attente ?? 0) > 5) {
                                        $recommandations[] = "Valider rapidement les paiements en attente";
                                    }
                                    
                                    if(($stats_generales['moyenne_paiement'] ?? 0) < 20000) {
                                        $recommandations[] = "Envisager des forfaits groupés pour augmenter la valeur moyenne";
                                    }
                                    
                                    if(empty($revenus_par_type) || count($revenus_par_type) < 2) {
                                        $recommandations[] = "Diversifier les types de frais pour augmenter les revenus";
                                    }
                                    
                                    if(empty($recommandations)) {
                                        echo "Performance financière stable. Continuez les bonnes pratiques.";
                                    } else {
                                        echo "<ul class='mb-0'>";
                                        foreach($recommandations as $rec) {
                                            echo "<li>$rec</li>";
                                        }
                                        echo "</ul>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
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
    
    // Graphique des types de frais
    const typeFraisCtx = document.getElementById('typeFraisChart');
    if (typeFraisCtx) {
        const typeFraisChart = new Chart(typeFraisCtx, {
            type: 'pie',
            data: {
                labels: [
                    <?php 
                    $labels = [];
                    foreach($revenus_par_type as $revenu) {
                        $labels[] = "'" . addslashes($revenu['type_frais']) . "'";
                    }
                    echo implode(', ', $labels);
                    ?>
                ],
                datasets: [{
                    data: [
                        <?php 
                        $data = [];
                        foreach($revenus_par_type as $revenu) {
                            $data[] = $revenu['total_revenu'];
                        }
                        echo implode(', ', $data);
                        ?>
                    ],
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                        '#9966FF', '#FF9F40', '#FF6384', '#36A2EB'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                label += new Intl.NumberFormat('fr-FR', { 
                                    style: 'currency', 
                                    currency: 'XAF',
                                    minimumFractionDigits: 0 
                                }).format(value);
                                label += ' (' + percentage + '%)';
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Graphique d'évolution
    const evolutionCtx = document.getElementById('evolutionChart');
    if (evolutionCtx) {
        const evolutionChart = new Chart(evolutionCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php 
                    $labels = [];
                    $revenus = [];
                    foreach(array_reverse($evolution_mensuelle) as $mois) {
                        $labels[] = "'" . $mois['mois'] . "'";
                        $revenus[] = $mois['total_revenu'];
                    }
                    echo implode(', ', $labels);
                    ?>
                ],
                datasets: [{
                    label: 'Revenus (FCFA)',
                    data: [<?php echo implode(', ', $revenus); ?>],
                    borderColor: '#36A2EB',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    borderWidth: 2,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Évolution des Revenus Mensuels'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += new Intl.NumberFormat('fr-FR', { 
                                    style: 'currency', 
                                    currency: 'XAF',
                                    minimumFractionDigits: 0 
                                }).format(context.raw);
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('fr-FR', { 
                                    style: 'currency', 
                                    currency: 'XAF',
                                    minimumFractionDigits: 0 
                                }).format(value);
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Export vers Excel
    function exportToExcel() {
        const table = document.querySelector('.table');
        if (!table) return;
        
        const html = table.outerHTML;
        const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'rapport_financier_<?php echo $mois . '_' . $annee; ?>.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
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
        
        // Auto-refresh toutes les 10 minutes
        setTimeout(() => {
            location.reload();
        }, 600000);
    });
    </script>
</body>
</html>