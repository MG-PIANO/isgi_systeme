<?php
// dashboard/gestionnaire_principal/dashboard.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire principal (rôle_id = 3)
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
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
    $pageTitle = "Gestionnaire Principal - Tableau de Bord";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
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
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
            case 'valide':
            case 'present':
            case 'admis':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'terminee':
                return '<span class="badge bg-info">Terminé</span>';
            case 'en_cours':
                return '<span class="badge bg-primary">En cours</span>';
            case 'soldee':
                return '<span class="badge bg-success">Soldée</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilisateur';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
    }
    
    // Initialiser toutes les variables
    $stats = array(
        'revenu_mensuel' => 0,
        'dettes_total' => 0,
        'etudiants_actifs' => 0,
        'profit_net' => 0,
        'paiements_attente' => 0,
        'taux_recouvrement' => 0
    );
    
    $etudiants_dettes = array();
    $transactions_recentes = array();
    $analyse_paiements = array();
    $frais_par_classe = array();
    $alertes_dettes = array();
    $demandeCount = 0;
    $error = null;
    
    // Récupérer les statistiques financières
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // 1. Revenu Mensuel
    $query = "SELECT SUM(montant) as total FROM paiements 
              WHERE MONTH(date_paiement) = ? AND YEAR(date_paiement) = ? 
              AND statut = 'valide'";
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = paiements.etudiant_id AND e.site_id = ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$currentMonth, $currentYear, $site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute([$currentMonth, $currentYear]);
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['revenu_mensuel'] = $result['total'] ?? 0;
    
    // 2. Dettes Étudiants
    $query = "SELECT SUM(montant_restant) as total FROM dettes WHERE statut = 'en_cours' OR statut = 'en_retard'";
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = dettes.etudiant_id AND e.site_id = ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['dettes_total'] = $result['total'] ?? 0;
    
    // 3. Étudiants Actifs
    $query = "SELECT COUNT(*) as total FROM etudiants WHERE statut = 'actif'";
    if ($site_id) {
        $query .= " AND site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['etudiants_actifs'] = $result['total'] ?? 0;
    
    // 4. Profit Net (Revenu - Dettes)
    $stats['profit_net'] = $stats['revenu_mensuel'] - $stats['dettes_total'];
    
    // 5. Paiements en attente
    $query = "SELECT COUNT(*) as total FROM paiements WHERE statut = 'en_attente'";
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = paiements.etudiant_id AND e.site_id = ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['paiements_attente'] = $result['total'] ?? 0;
    
    // 6. Taux de Recouvrement
    $query = "SELECT 
                (SELECT COUNT(*) FROM paiements WHERE statut = 'valide') as payes,
                (SELECT COUNT(*) FROM paiements WHERE statut = 'en_attente') as en_attente";
    if ($site_id) {
        $query = "SELECT 
                    (SELECT COUNT(*) FROM paiements p 
                     WHERE p.statut = 'valide' 
                     AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = p.etudiant_id AND e.site_id = ?)) as payes,
                    (SELECT COUNT(*) FROM paiements p 
                     WHERE p.statut = 'en_attente' 
                     AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = p.etudiant_id AND e.site_id = ?)) as en_attente";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id, $site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = ($result['payes'] + $result['en_attente']);
    $stats['taux_recouvrement'] = $total > 0 ? ($result['payes'] / $total * 100) : 0;
    
    // 7. Étudiants avec dettes (pour le tableau)
    $query = "SELECT e.id, e.matricule, e.nom, e.prenom, 
                     d.montant_restant, d.date_limite, d.statut,
                     (SELECT MAX(date_paiement) FROM paiements p 
                      WHERE p.etudiant_id = e.id AND p.statut = 'valide') as dernier_paiement
              FROM etudiants e
              JOIN dettes d ON e.id = d.etudiant_id
              WHERE (d.statut = 'en_cours' OR d.statut = 'en_retard') 
              AND e.statut = 'actif'";
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $query .= " ORDER BY d.montant_restant DESC LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " ORDER BY d.montant_restant DESC LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $etudiants_dettes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. Historique des transactions récentes
    $query = "SELECT p.*, e.nom, e.prenom, e.matricule 
              FROM paiements p
              JOIN etudiants e ON p.etudiant_id = e.id
              WHERE p.statut IN ('valide', 'en_attente')";
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $query .= " ORDER BY p.date_paiement DESC LIMIT 15";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " ORDER BY p.date_paiement DESC LIMIT 15";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $transactions_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 9. Analyse des paiements en ligne
    $query = "SELECT 
                mode_paiement,
                COUNT(*) as nombre,
                SUM(montant) as total
              FROM paiements 
              WHERE statut = 'valide'";
    if ($site_id) {
        $query .= " AND EXISTS (SELECT 1 FROM etudiants e WHERE e.id = paiements.etudiant_id AND e.site_id = ?)";
        $query .= " GROUP BY mode_paiement ORDER BY total DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " GROUP BY mode_paiement ORDER BY total DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $analyse_paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 10. Alertes de dettes
    $query = "SELECT e.id, e.matricule, e.nom, e.prenom, 
                     d.montant_restant, d.date_limite
              FROM etudiants e
              JOIN dettes d ON e.id = d.etudiant_id
              WHERE d.statut = 'en_retard' 
              OR (d.statut = 'en_cours' AND d.date_limite < CURDATE())";
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $query .= " ORDER BY d.date_limite ASC LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $query .= " ORDER BY d.date_limite ASC LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $alertes_dettes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    // Récupérer le nom du site si assigné
    $site_nom = '';
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log($error);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
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
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Gestion Financière</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
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
                    <a href="rapport_financier.php" class="nav-link">
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
                            <i class="fas fa-money-check-alt me-2"></i>
                            Tableau de Bord Financier
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="nouveau_paiement.php" class="btn btn-success">
                            <i class="fas fa-plus-circle"></i> Nouveau Paiement
                        </a>
                        <a href="generer_facture.php" class="btn btn-info">
                            <i class="fas fa-file-invoice"></i> Générer Facture
                        </a>
                        <button class="btn btn-warning" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques Financières -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['revenu_mensuel']); ?></div>
                        <div class="stat-label">Revenu Mensuel</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> Ce mois-ci
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['dettes_total']); ?></div>
                        <div class="stat-label">Dettes Total</div>
                        <div class="stat-change">
                            <?php echo count($etudiants_dettes); ?> étudiants
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['etudiants_actifs']; ?></div>
                        <div class="stat-label">Étudiants Actifs</div>
                        <div class="stat-change">
                            <?php echo $site_nom ? 'Site spécifique' : 'Tous sites'; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['profit_net']); ?></div>
                        <div class="stat-label">Profit Net</div>
                        <div class="stat-change <?php echo $stats['profit_net'] >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $stats['profit_net'] >= 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo $stats['profit_net'] >= 0 ? 'Positif' : 'Négatif'; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['paiements_attente']; ?></div>
                        <div class="stat-label">Paiements Attente</div>
                        <div class="stat-change">
                            À valider
                        </div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['taux_recouvrement'], 1); ?>%</div>
                        <div class="stat-label">Taux Recouvrement</div>
                        <div class="stat-change <?php echo $stats['taux_recouvrement'] >= 80 ? 'positive' : ($stats['taux_recouvrement'] >= 60 ? 'text-warning' : 'negative'); ?>">
                            <?php echo $stats['taux_recouvrement'] >= 80 ? 'Excellent' : ($stats['taux_recouvrement'] >= 60 ? 'Moyen' : 'Faible'); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Tableau d'affichage des étudiants avec dettes -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-user-graduate me-2"></i>
                                Étudiants avec Dettes
                            </h5>
                            <a href="dettes.php" class="btn btn-sm btn-primary">
                                Voir toutes les dettes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if(empty($etudiants_dettes)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucun étudiant avec dette enregistrée
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Nom</th>
                                            <th>Matricule</th>
                                            <th>Montant Dû</th>
                                            <th>Date Limite</th>
                                            <th>Dernier Paiement</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($etudiants_dettes as $etudiant): 
                                            $montant = $etudiant['montant_restant'];
                                            $classe_dette = '';
                                            if ($montant > 50000) {
                                                $classe_dette = 'dette-high';
                                            } elseif ($montant > 20000) {
                                                $classe_dette = 'dette-medium';
                                            } else {
                                                $classe_dette = 'dette-low';
                                            }
                                        ?>
                                        <tr class="<?php echo $classe_dette; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($etudiant['matricule']); ?></td>
                                            <td class="text-danger fw-bold">
                                                <?php echo formatMoney($etudiant['montant_restant']); ?>
                                            </td>
                                            <td>
                                                <?php echo $etudiant['date_limite'] ? formatDateFr($etudiant['date_limite']) : 'Non définie'; ?>
                                            </td>
                                            <td>
                                                <?php echo $etudiant['dernier_paiement'] ? formatDateFr($etudiant['dernier_paiement']) : 'Aucun'; ?>
                                            </td>
                                            <td>
                                                <?php echo getStatutBadge($etudiant['statut']); ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="nouveau_paiement.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn btn-success" title="Enregistrer paiement">
                                                        <i class="fas fa-money-bill"></i>
                                                    </a>
                                                    <a href="etudiant_details.php?id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn btn-info" title="Détails">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="contacter.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn btn-warning" title="Contacter">
                                                        <i class="fas fa-envelope"></i>
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
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Analyse des Paiements
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($analyse_paiements)): ?>
                            <div class="alert alert-info">
                                Aucune donnée de paiement disponible
                            </div>
                            <?php else: ?>
                            <div class="chart-container">
                                <canvas id="modePaiementChart"></canvas>
                            </div>
                            <div class="mt-3">
                                <h6>Répartition par mode de paiement</h6>
                                <ul class="list-group">
                                    <?php foreach($analyse_paiements as $paiement): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars($paiement['mode_paiement']); ?>
                                        <span class="badge bg-primary rounded-pill">
                                            <?php echo formatMoney($paiement['total']); ?>
                                        </span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Historique des transactions -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Historique des Transactions Récentes
                            </h5>
                            <a href="paiements.php" class="btn btn-sm btn-primary">
                                Voir toutes les transactions
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if(empty($transactions_recentes)): ?>
                            <div class="alert alert-info">
                                Aucune transaction enregistrée
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Étudiant</th>
                                            <th>Référence</th>
                                            <th>Montant</th>
                                            <th>Méthode</th>
                                            <th>Type</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($transactions_recentes as $transaction): 
                                            $statut_class = 'transaction-' . $transaction['statut'];
                                        ?>
                                        <tr class="<?php echo $statut_class; ?>">
                                            <td>
                                                <?php echo formatDateFr($transaction['date_paiement'], 'd/m/Y H:i'); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($transaction['nom'] . ' ' . $transaction['prenom']); ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($transaction['matricule']); ?></small>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($transaction['reference']); ?></code>
                                            </td>
                                            <td class="fw-bold">
                                                <?php echo formatMoney($transaction['montant']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($transaction['mode_paiement']); ?>
                                                <?php if($transaction['numero_transaction']): ?>
                                                <br><small class="text-muted">N°: <?php echo htmlspecialchars($transaction['numero_transaction']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $type_frais = 'Inconnu';
                                                if($transaction['type_frais_id']) {
                                                    // Récupérer le type de frais
                                                    $stmt = $db->prepare("SELECT nom FROM types_frais WHERE id = ?");
                                                    $stmt->execute([$transaction['type_frais_id']]);
                                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                                    $type_frais = $result['nom'] ?? 'Inconnu';
                                                }
                                                echo htmlspecialchars($type_frais);
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo getStatutBadge($transaction['statut']); ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if($transaction['statut'] == 'en_attente'): ?>
                                                    <a href="valider_paiement.php?id=<?php echo $transaction['id']; ?>" 
                                                       class="btn btn-success" title="Valider">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                    <a href="annuler_paiement.php?id=<?php echo $transaction['id']; ?>" 
                                                       class="btn btn-danger" title="Annuler">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <a href="facture.php?paiement_id=<?php echo $transaction['id']; ?>" 
                                                       class="btn btn-info" title="Voir facture">
                                                        <i class="fas fa-file-invoice"></i>
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
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Alertes et Actions -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                                Alertes de Dettes
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($alertes_dettes)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune alerte de dette critique
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($alertes_dettes as $alerte): 
                                    $jours_retard = $alerte['date_limite'] ? 
                                        floor((time() - strtotime($alerte['date_limite'])) / (60 * 60 * 24)) : 0;
                                    $badge_class = $jours_retard > 30 ? 'bg-danger' : ($jours_retard > 7 ? 'bg-warning' : 'bg-info');
                                ?>
                                <a href="dette_details.php?etudiant_id=<?php echo $alerte['id']; ?>" 
                                   class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($alerte['nom'] . ' ' . $alerte['prenom']); ?>
                                        </h6>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $jours_retard > 0 ? $jours_retard . ' jours' : 'Aujourd\'hui'; ?>
                                        </span>
                                    </div>
                                    <p class="mb-1">
                                        <strong><?php echo formatMoney($alerte['montant_restant']); ?></strong>
                                        - <?php echo htmlspecialchars($alerte['matricule']); ?>
                                    </p>
                                    <small class="text-muted">
                                        Date limite: <?php echo formatDateFr($alerte['date_limite']); ?>
                                    </small>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="mt-3">
                                <a href="contacter_tous.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-envelope"></i> Contacter tous les retardataires
                                </a>
                                <a href="generer_rapport_dettes.php" class="btn btn-info btn-sm">
                                    <i class="fas fa-file-excel"></i> Exporter rapport
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <a href="nouveau_paiement.php" class="btn btn-primary w-100">
                                        <i class="fas fa-money-bill-wave me-2"></i>
                                        Nouveau Paiement
                                    </a>
                                </div>
                                <div class="col-6 mb-3">
                                    <a href="generer_facture.php" class="btn btn-success w-100">
                                        <i class="fas fa-file-invoice me-2"></i>
                                        Générer Facture
                                    </a>
                                </div>
                                <div class="col-6 mb-3">
                                    <a href="inscription_etudiant.php" class="btn btn-info w-100">
                                        <i class="fas fa-user-plus me-2"></i>
                                        Nouvelle Inscription
                                    </a>
                                </div>
                                <div class="col-6 mb-3">
                                    <a href="reinscription.php" class="btn btn-warning w-100">
                                        <i class="fas fa-redo me-2"></i>
                                        Réinscription
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="rapport_financier.php" class="btn btn-secondary w-100">
                                        <i class="fas fa-chart-bar me-2"></i>
                                        Rapport Financier
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="calendrier.php" class="btn btn-dark w-100">
                                        <i class="fas fa-calendar me-2"></i>
                                        Calendrier
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <h6><i class="fas fa-info-circle me-2"></i> Informations Financières</h6>
                                <div class="alert alert-info">
                                    <ul class="mb-0 small">
                                        <li>Cliquez sur un étudiant pour voir ses détails comptables</li>
                                        <li>Validez les paiements en attente rapidement</li>
                                        <li>Générez des rapports pour chaque période</li>
                                        <li>Utilisez l'export Excel pour l'analyse externe</li>
                                        <li>Contactez les retardataires automatiquement</li>
                                    </ul>
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
    
    // Graphique des modes de paiement
    function initModePaiementChart() {
        const ctx = document.getElementById('modePaiementChart');
        if (!ctx) return;
        
        // Données du graphique
        const data = {
            labels: [
                <?php 
                $labels = [];
                $datas = [];
                $backgroundColors = [
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(255, 206, 86, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(153, 102, 255, 0.7)',
                    'rgba(255, 159, 64, 0.7)'
                ];
                $i = 0;
                foreach($analyse_paiements as $paiement): 
                    $labels[] = "'" . addslashes($paiement['mode_paiement']) . "'";
                    $datas[] = $paiement['total'];
                endforeach;
                echo implode(', ', $labels);
                ?>
            ],
            datasets: [{
                data: [<?php echo implode(', ', $datas); ?>],
                backgroundColor: [
                    <?php 
                    for($j = 0; $j < count($analyse_paiements); $j++): 
                        echo "'" . $backgroundColors[$j % count($backgroundColors)] . "'";
                        if($j < count($analyse_paiements) - 1) echo ', ';
                    endfor; 
                    ?>
                ],
                borderColor: [
                    <?php 
                    for($j = 0; $j < count($analyse_paiements); $j++): 
                        echo "'" . str_replace('0.7', '1', $backgroundColors[$j % count($backgroundColors)]) . "'";
                        if($j < count($analyse_paiements) - 1) echo ', ';
                    endfor; 
                    ?>
                ],
                borderWidth: 1
            }]
        };
        
        // Configuration du graphique
        const config = {
            type: 'pie',
            data: data,
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
        };
        
        new Chart(ctx, config);
    }
    
    // Initialiser le thème et le graphique
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
        
        // Initialiser le graphique si des données sont disponibles
        <?php if(!empty($analyse_paiements)): ?>
        initModePaiementChart();
        <?php endif; ?>
        
        // Auto-refresh toutes les 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000); // 5 minutes
    });
    
    // Fonction pour confirmer les actions importantes
    function confirmAction(message, url) {
        if (confirm(message)) {
            window.location.href = url;
        }
    }
    </script>
</body>
</html>