<?php
// dashboard/etudiant/dettes.php

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
    $pageTitle = "Gestion des Dettes - Étudiant";
    
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
            case 'en_cours':
                return '<span class="badge bg-warning">En cours</span>';
            case 'soldee':
                return '<span class="badge bg-success">Soldée</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeDetteBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'scolarite':
                return '<span class="badge bg-primary">Scolarité</span>';
            case 'inscription':
                return '<span class="badge bg-info">Inscription</span>';
            case 'examen':
                return '<span class="badge bg-warning">Examen</span>';
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
    $dettes_total = 0;
    $dettes_en_cours = 0;
    $dettes_en_retard = 0;
    $dettes_soldees = 0;
    $dettes_liste = array();
    $paiements_recent = array();
    $plans_paiement = array();
    $relances_recentes = array();
    $historique_remises = array();
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
        
        // Récupérer les statistiques globales des dettes
        $stats = executeSingleQuery($db,
            "SELECT 
                COUNT(*) as total_dettes,
                COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) as dettes_en_cours,
                COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as dettes_en_retard,
                COUNT(CASE WHEN statut = 'soldee' THEN 1 END) as dettes_soldees,
                COALESCE(SUM(montant_restant), 0) as montant_total_restant,
                COALESCE(SUM(montant_paye), 0) as montant_total_paye,
                COALESCE(SUM(montant_du), 0) as montant_total_du
             FROM dettes 
             WHERE etudiant_id = ?",
            [$etudiant_id]);
        
        $dettes_total = isset($stats['total_dettes']) ? intval($stats['total_dettes']) : 0;
        $dettes_en_cours = isset($stats['dettes_en_cours']) ? intval($stats['dettes_en_cours']) : 0;
        $dettes_en_retard = isset($stats['dettes_en_retard']) ? intval($stats['dettes_en_retard']) : 0;
        $dettes_soldees = isset($stats['dettes_soldees']) ? intval($stats['dettes_soldees']) : 0;
        $montant_total_restant = isset($stats['montant_total_restant']) ? floatval($stats['montant_total_restant']) : 0;
        $montant_total_paye = isset($stats['montant_total_paye']) ? floatval($stats['montant_total_paye']) : 0;
        $montant_total_du = isset($stats['montant_total_du']) ? floatval($stats['montant_total_du']) : 0;
        
        // Récupérer la liste complète des dettes
        $dettes_liste = executeQuery($db,
            "SELECT d.*, aa.libelle as annee_academique, aa.date_debut, aa.date_fin,
                    CONCAT(u.nom, ' ', u.prenom) as gestionnaire_nom,
                    DATEDIFF(d.date_limite, CURDATE()) as jours_restants
             FROM dettes d
             JOIN annees_academiques aa ON d.annee_academique_id = aa.id
             LEFT JOIN utilisateurs u ON d.gestionnaire_id = u.id
             WHERE d.etudiant_id = ?
             ORDER BY 
                 CASE d.statut 
                     WHEN 'en_retard' THEN 1
                     WHEN 'en_cours' THEN 2
                     ELSE 3 
                 END,
                 d.date_limite ASC",
            [$etudiant_id]);
        
        // Récupérer les paiements récents liés aux dettes
        $paiements_recent = executeQuery($db,
            "SELECT p.*, tf.nom as type_frais, u.nom as caissier_nom,
                    aa.libelle as annee_academique
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             JOIN annees_academiques aa ON p.annee_academique_id = aa.id
             LEFT JOIN utilisateurs u ON p.caissier_id = u.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'valide'
             ORDER BY p.date_paiement DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les plans de paiement échelonnés
        $plans_paiement = executeQuery($db,
            "SELECT pp.*, d.type_dette, d.montant_restant,
                    COUNT(ep.id) as nombre_echeances,
                    COUNT(CASE WHEN ep.statut = 'payee' THEN 1 END) as echeances_payees,
                    COUNT(CASE WHEN ep.statut = 'en_retard' THEN 1 END) as echeances_retard
             FROM plans_paiement pp
             JOIN dettes d ON pp.dette_id = d.id
             LEFT JOIN echeances_plan ep ON pp.id = ep.plan_id
             WHERE d.etudiant_id = ? 
             AND pp.statut = 'actif'
             GROUP BY pp.id
             ORDER BY pp.date_debut",
            [$etudiant_id]);
        
        // Récupérer les relances récentes
        $relances_recentes = executeQuery($db,
            "SELECT rd.*, u.nom as envoyeur_nom,
                    d.type_dette, d.montant_restant
             FROM relances_dettes rd
             JOIN dettes d ON rd.dette_id = d.id
             JOIN utilisateurs u ON rd.envoyee_par = u.id
             WHERE d.etudiant_id = ?
             ORDER BY rd.date_envoi DESC
             LIMIT 5",
            [$etudiant_id]);
        
        // Récupérer l'historique des remises/échelonnements
        $historique_remises = executeQuery($db,
            "SELECT hmd.*, u.nom as utilisateur_nom, d.type_dette
             FROM historique_modifications_dettes hmd
             JOIN dettes d ON hmd.dette_id = d.id
             JOIN utilisateurs u ON hmd.utilisateur_id = u.id
             WHERE d.etudiant_id = ? 
             AND (hmd.action LIKE '%remise%' OR hmd.action LIKE '%échelonnement%')
             ORDER BY hmd.date_modification DESC
             LIMIT 5",
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
    <title><?php echo safeHtml($pageTitle); ?></title>
    
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
    
    /* Sidebar (identique au dashboard) */
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
    
    /* Stat cards spécifiques dettes */
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
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
    }
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
    }
    
    /* Boutons */
    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
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
    
    /* Dettes spécifiques */
    .dette-urgence {
        border-left: 5px solid var(--accent-color);
    }
    
    .dette-moyenne {
        border-left: 5px solid var(--warning-color);
    }
    
    .dette-normale {
        border-left: 5px solid var(--info-color);
    }
    
    .dette-terminee {
        border-left: 5px solid var(--success-color);
    }
    
    /* Modal de paiement */
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Montants */
    .montant-important {
        font-size: 1.2em;
        font-weight: bold;
    }
    
    .montant-negatif {
        color: var(--accent-color);
    }
    
    .montant-positif {
        color: var(--success-color);
    }
    
    /* Timeline des écheances */
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
        left: -30px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--primary-color);
    }
    
    .timeline-item.payee::before {
        background-color: var(--success-color);
    }
    
    .timeline-item.retard::before {
        background-color: var(--accent-color);
    }
    
    .timeline-item.annulee::before {
        background-color: var(--text-muted);
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique au dashboard) -->
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
                    <a href="dettes.php" class="nav-link active">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Mes Dettes</span>
                    </a>
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Situation Financière</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
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
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Gestion des Dettes
                        </h2>
                        <p class="text-muted mb-0">
                            Visualisation et suivi de votre situation financière
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="factures.php" class="btn btn-success">
                            <i class="fas fa-file-invoice-dollar"></i> Payer une facture
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Aperçu global -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($montant_total_du); ?></div>
                        <div class="stat-label">Montant Total Dû</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($montant_total_restant); ?></div>
                        <div class="stat-label">Reste à Payer</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($montant_total_paye); ?></div>
                        <div class="stat-label">Déjà Payé</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-list"></i>
                        </div>
                        <div class="stat-value"><?php echo $dettes_total; ?></div>
                        <div class="stat-label">Dettes au Total</div>
                        <div class="stat-change small">
                            <?php echo $dettes_en_cours; ?> en cours, 
                            <?php echo $dettes_en_retard; ?> en retard,
                            <?php echo $dettes_soldees; ?> soldées
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Graphique de progression -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Répartition des Dettes
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="dettesChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Importantes
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if($montant_total_restant > 0): ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Dettes en cours</h6>
                                <p class="mb-1">Vous avez <?php echo formatMoney($montant_total_restant); ?> de dettes à régler.</p>
                                <?php if($dettes_en_retard > 0): ?>
                                <p class="mb-0"><strong>Attention:</strong> <?php echo $dettes_en_retard; ?> dette(s) en retard!</p>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-success">
                                <h6><i class="fas fa-check-circle"></i> Situation régularisée</h6>
                                <p class="mb-0">Toutes vos dettes sont soldées.</p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-phone-alt"></i> Contact financier</h6>
                                <p class="mb-0 small">
                                    <strong>Service financier:</strong> +242 XX XX XX XX<br>
                                    <strong>Email:</strong> finances@isgi.cg<br>
                                    <strong>Horaires:</strong> 8h00 - 16h00 (Lun-Ven)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Liste des dettes -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste de vos Dettes
                    </h5>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="filterDettes('toutes')">
                            Toutes
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="filterDettes('en_cours')">
                            En cours
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="filterDettes('en_retard')">
                            En retard
                        </button>
                        <button class="btn btn-sm btn-outline-success" onclick="filterDettes('soldee')">
                            Soldées
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($dettes_liste)): ?>
                    <div class="alert alert-success text-center">
                        <i class="fas fa-check-circle fa-2x mb-3"></i>
                        <h5>Félicitations!</h5>
                        <p class="mb-0">Vous n'avez aucune dette enregistrée.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="tableDettes">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Année Académique</th>
                                    <th>Montant Dû</th>
                                    <th>Déjà Payé</th>
                                    <th>Reste à Payer</th>
                                    <th>Date Limite</th>
                                    <th>Jours Restants</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dettes_liste as $dette): 
                                    $montant_du = floatval($dette['montant_du'] ?? 0);
                                    $montant_paye = floatval($dette['montant_paye'] ?? 0);
                                    $montant_restant = floatval($dette['montant_restant'] ?? 0);
                                    $jours_restants = intval($dette['jours_restants'] ?? 0);
                                    $statut = $dette['statut'] ?? '';
                                    $date_limite = $dette['date_limite'] ?? '';
                                    
                                    // Déterminer la classe CSS
                                    $row_class = '';
                                    if ($statut == 'en_retard') {
                                        $row_class = 'dette-urgence';
                                    } elseif ($statut == 'en_cours') {
                                        if ($jours_restants <= 7 && $jours_restants > 0) {
                                            $row_class = 'dette-urgence';
                                        } elseif ($jours_restants <= 30 && $jours_restants > 7) {
                                            $row_class = 'dette-moyenne';
                                        } else {
                                            $row_class = 'dette-normale';
                                        }
                                    } elseif ($statut == 'soldee') {
                                        $row_class = 'dette-terminee';
                                    }
                                ?>
                                <tr class="<?php echo $row_class; ?>" data-statut="<?php echo safeHtml($statut); ?>">
                                    <td>
                                        <?php echo getTypeDetteBadge($dette['type_dette'] ?? ''); ?>
                                        <?php if(!empty($dette['motif'])): ?>
                                        <br><small class="text-muted"><?php echo safeHtml(substr($dette['motif'], 0, 50)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo safeHtml($dette['annee_academique'] ?? ''); ?>
                                        <br><small class="text-muted">
                                            <?php echo formatDateFr($dette['date_debut'] ?? ''); ?> - 
                                            <?php echo formatDateFr($dette['date_fin'] ?? ''); ?>
                                        </small>
                                    </td>
                                    <td class="montant-important">
                                        <?php echo formatMoney($montant_du); ?>
                                    </td>
                                    <td class="montant-positif">
                                        <?php echo formatMoney($montant_paye); ?>
                                        <?php if($montant_du > 0): ?>
                                        <br><small class="text-muted">
                                            <?php echo round(($montant_paye / $montant_du) * 100, 1); ?>%
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="montant-negatif montant-important">
                                        <?php echo formatMoney($montant_restant); ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($date_limite) && $date_limite != '0000-00-00'): ?>
                                        <?php echo formatDateFr($date_limite); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Non définie</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($statut == 'en_retard'): ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-circle"></i> Retard
                                        </span>
                                        <?php elseif($jours_restants > 0): ?>
                                        <span class="badge bg-<?php echo $jours_restants <= 7 ? 'danger' : ($jours_restants <= 30 ? 'warning' : 'info'); ?>">
                                            J-<?php echo $jours_restants; ?>
                                        </span>
                                        <?php elseif($jours_restants == 0): ?>
                                        <span class="badge bg-warning">
                                            Aujourd'hui
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($statut); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="voirDetailsDette(<?php echo intval($dette['id']); ?>)"
                                                    title="Voir les détails">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if($montant_restant > 0 && $statut != 'soldee'): ?>
                                            <button class="btn btn-outline-success" 
                                                    onclick="proposerPaiement(<?php echo intval($dette['id']); ?>, <?php echo $montant_restant; ?>)"
                                                    title="Proposer un paiement">
                                                <i class="fas fa-hand-holding-usd"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if(!empty($dette['gestionnaire_nom'])): ?>
                                            <button class="btn btn-outline-info" 
                                                    onclick="contacterGestionnaire('<?php echo safeHtml($dette['gestionnaire_nom']); ?>')"
                                                    title="Contacter le gestionnaire">
                                                <i class="fas fa-user-tie"></i>
                                            </button>
                                            <?php endif; ?>
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
            
            <!-- Section 4: Paiements récents et plans échelonnés -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Historique des Paiements Récents
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($paiements_recent)): ?>
                            <div class="alert alert-info">
                                Aucun paiement enregistré récemment
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($paiements_recent as $paiement): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <?php echo safeHtml($paiement['type_frais'] ?? ''); ?>
                                        </h6>
                                        <strong class="text-success"><?php echo formatMoney($paiement['montant'] ?? 0); ?></strong>
                                    </div>
                                    <p class="mb-1 small">
                                        <strong>Référence:</strong> <?php echo safeHtml($paiement['reference'] ?? ''); ?><br>
                                        <strong>Mode:</strong> <?php echo safeHtml($paiement['mode_paiement'] ?? ''); ?><br>
                                        <strong>Date:</strong> <?php echo formatDateFr($paiement['date_paiement'] ?? ''); ?>
                                        <?php if(!empty($paiement['caissier_nom'])): ?>
                                        <br><strong>Caissier:</strong> <?php echo safeHtml($paiement['caissier_nom']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Plans de Paiement Échelonnés
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($plans_paiement)): ?>
                            <div class="alert alert-info">
                                Aucun plan de paiement échelonné actif
                            </div>
                            <?php else: ?>
                            <div class="timeline">
                                <?php foreach($plans_paiement as $plan): 
                                    $echeances_payees = intval($plan['echeances_payees'] ?? 0);
                                    $nombre_echeances = intval($plan['nombre_echeances'] ?? 0);
                                    $pourcentage = $nombre_echeances > 0 ? round(($echeances_payees / $nombre_echeances) * 100, 1) : 0;
                                ?>
                                <div class="timeline-item <?php echo $plan['statut'] == 'termine' ? 'payee' : ''; ?>">
                                    <h6 class="mb-1">
                                        Plan #<?php echo intval($plan['id']); ?>
                                        <span class="badge bg-<?php echo $plan['statut'] == 'actif' ? 'info' : ($plan['statut'] == 'termine' ? 'success' : 'secondary'); ?>">
                                            <?php echo safeHtml(ucfirst($plan['statut'] ?? '')); ?>
                                        </span>
                                    </h6>
                                    <p class="mb-1 small">
                                        <strong>Type:</strong> <?php echo getTypeDetteBadge($plan['type_dette'] ?? ''); ?><br>
                                        <strong>Montant total:</strong> <?php echo formatMoney($plan['montant_total'] ?? 0); ?><br>
                                        <strong>Échéances:</strong> <?php echo $nombre_echeances; ?> tranches<br>
                                        <strong>Progression:</strong> <?php echo $echeances_payees; ?>/<?php echo $nombre_echeances; ?> payées (<?php echo $pourcentage; ?>%)<br>
                                        <?php if(!empty($plan['date_debut'])): ?>
                                        <strong>Début:</strong> <?php echo formatDateFr($plan['date_debut']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <div class="progress mt-2">
                                        <div class="progress-bar" style="width: <?php echo $pourcentage; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Relances et historique -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bell me-2"></i>
                                Relances Récentes
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($relances_recentes)): ?>
                            <div class="alert alert-success">
                                Aucune relance récente - votre situation est à jour
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($relances_recentes as $relance): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <i class="fas fa-<?php echo $relance['type_relance'] == 'email' ? 'envelope' : ($relance['type_relance'] == 'sms' ? 'sms' : 'phone'); ?>"></i>
                                            Relance par <?php echo safeHtml($relance['type_relance'] ?? ''); ?>
                                        </h6>
                                        <small><?php echo formatDateFr($relance['date_envoi'] ?? '', 'd/m H:i'); ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <strong>Type dette:</strong> <?php echo getTypeDetteBadge($relance['type_dette'] ?? ''); ?><br>
                                        <strong>Montant restant:</strong> <?php echo formatMoney($relance['montant_restant'] ?? 0); ?>
                                    </p>
                                    <?php if(!empty($relance['message'])): ?>
                                    <div class="alert alert-warning mt-2 py-2">
                                        <small><?php echo safeHtml(substr($relance['message'], 0, 150)); ?>...</small>
                                    </div>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        Envoyé par: <?php echo safeHtml($relance['envoyeur_nom'] ?? ''); ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Historique des Négociations
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($historique_remises)): ?>
                            <div class="alert alert-info">
                                Aucune négociation enregistrée
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($historique_remises as $historique): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <i class="fas fa-file-contract"></i>
                                            <?php echo safeHtml(ucfirst($historique['action'] ?? '')); ?>
                                        </h6>
                                        <small><?php echo formatDateFr($historique['date_modification'] ?? '', 'd/m H:i'); ?></small>
                                    </div>
                                    <p class="mb-1 small">
                                        <strong>Type:</strong> <?php echo getTypeDetteBadge($historique['type_dette'] ?? ''); ?><br>
                                        <strong>Modifié par:</strong> <?php echo safeHtml($historique['utilisateur_nom'] ?? ''); ?>
                                    </p>
                                    <?php if(!empty($historique['nouvelles_valeurs'])): ?>
                                    <div class="alert alert-info mt-2 py-2">
                                        <small><strong>Nouveaux termes:</strong> <?php echo safeHtml(substr($historique['nouvelles_valeurs'], 0, 100)); ?>...</small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 6: Actions rapides -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i>
                        Actions Rapides
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-6 mb-3">
                            <button class="btn btn-primary w-100" onclick="window.location.href='factures.php'">
                                <i class="fas fa-file-invoice-dollar me-2"></i>
                                Payer une Facture
                            </button>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <button class="btn btn-success w-100" onclick="proposerPlanPaiement()">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Demander un Échelonnement
                            </button>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <button class="btn btn-warning w-100" onclick="contacterServiceFinancier()">
                                <i class="fas fa-headset me-2"></i>
                                Contacter le Service Financier
                            </button>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <button class="btn btn-info w-100" onclick="window.location.href='finances.php'">
                                <i class="fas fa-chart-line me-2"></i>
                                Voir Situation Complète
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <!-- Modal Détails Dette -->
    <div class="modal fade" id="modalDetailsDette" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de la Dette</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsDetteContent">
                    <!-- Contenu chargé dynamiquement -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary" onclick="imprimerDetails()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Proposer Paiement -->
    <div class="modal fade" id="modalProposerPaiement" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Proposer un Paiement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formPaiement">
                        <input type="hidden" id="dette_id_paiement">
                        <div class="mb-3">
                            <label class="form-label">Montant à régler</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="montant_propose" min="0" step="1000">
                                <span class="input-group-text">FCFA</span>
                            </div>
                            <div class="form-text">
                                Montant restant: <span id="montant_restant_affichage" class="fw-bold"></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mode de paiement proposé</label>
                            <select class="form-select" id="mode_paiement_propose">
                                <option value="">Sélectionnez un mode</option>
                                <option value="espece">Espèces</option>
                                <option value="virement">Virement bancaire</option>
                                <option value="mtn_momo">MTN Mobile Money</option>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="cheque">Chèque bancaire</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date de paiement proposée</label>
                            <input type="date" class="form-control" id="date_paiement_propose">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Commentaires (optionnel)</label>
                            <textarea class="form-control" id="commentaire_paiement" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" onclick="soumettrePropositionPaiement()">
                        <i class="fas fa-paper-plane"></i> Soumettre la proposition
                    </button>
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
        
        // Initialiser le graphique
        initChart();
    });
    
    // Initialiser le graphique des dettes
    function initChart() {
        const ctx = document.getElementById('dettesChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Scolarité', 'Inscription', 'Examens', 'Autres'],
                datasets: [{
                    data: [<?php 
                        $scolarite = 0; $inscription = 0; $examen = 0; $autres = 0;
                        foreach($dettes_liste as $dette) {
                            switch($dette['type_dette'] ?? '') {
                                case 'scolarite': $scolarite += floatval($dette['montant_restant'] ?? 0); break;
                                case 'inscription': $inscription += floatval($dette['montant_restant'] ?? 0); break;
                                case 'examen': $examen += floatval($dette['montant_restant'] ?? 0); break;
                                default: $autres += floatval($dette['montant_restant'] ?? 0);
                            }
                        }
                        echo "$scolarite, $inscription, $examen, $autres";
                    ?>],
                    backgroundColor: [
                        '#3498db',
                        '#2ecc71',
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
                        text: 'Répartition des dettes par type'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${formatMoney(value)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Fonction utilitaire pour formater l'argent
    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-FR', { 
            style: 'currency', 
            currency: 'XAF',
            minimumFractionDigits: 0 
        }).format(amount);
    }
    
    // Filtrer les dettes par statut
    function filterDettes(statut) {
        const rows = document.querySelectorAll('#tableDettes tbody tr');
        rows.forEach(row => {
            if (statut === 'toutes') {
                row.style.display = '';
            } else {
                const rowStatut = row.getAttribute('data-statut');
                row.style.display = rowStatut === statut ? '' : 'none';
            }
        });
    }
    
    // Voir les détails d'une dette
    async function voirDetailsDette(detteId) {
        try {
            const response = await fetch(`api/dette_details.php?id=${detteId}`);
            const data = await response.json();
            
            if (data.success) {
                const details = data.dette;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informations Générales</h6>
                            <p><strong>Type:</strong> ${getTypeBadge(details.type_dette)}</p>
                            <p><strong>Année Académique:</strong> ${details.annee_academique}</p>
                            <p><strong>Date création:</strong> ${formatDate(details.date_creation)}</p>
                            <p><strong>Date limite:</strong> ${formatDate(details.date_limite)}</p>
                            <p><strong>Gestionnaire:</strong> ${details.gestionnaire_nom || 'Non assigné'}</p>
                        </div>
                        <div class="col-md-6">
                            <h6>Montants</h6>
                            <p><strong>Montant dû:</strong> <span class="text-danger">${formatMoney(details.montant_du)}</span></p>
                            <p><strong>Déjà payé:</strong> <span class="text-success">${formatMoney(details.montant_paye)}</span></p>
                            <p><strong>Reste à payer:</strong> <span class="text-danger fw-bold">${formatMoney(details.montant_restant)}</span></p>
                            <div class="progress mt-2">
                                <div class="progress-bar bg-success" style="width: ${(details.montant_paye / details.montant_du * 100) || 0}%">
                                    ${((details.montant_paye / details.montant_du * 100) || 0).toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                if (details.motif) {
                    html += `
                        <div class="mt-3">
                            <h6>Motif</h6>
                            <p>${details.motif}</p>
                        </div>
                    `;
                }
                
                document.getElementById('detailsDetteContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('modalDetailsDette')).show();
            } else {
                alert('Erreur: ' + data.message);
            }
        } catch (error) {
            console.error('Erreur:', error);
            alert('Erreur lors du chargement des détails');
        }
    }
    
    // Proposer un paiement
    function proposerPaiement(detteId, montantRestant) {
        document.getElementById('dette_id_paiement').value = detteId;
        document.getElementById('montant_restant_affichage').textContent = formatMoney(montantRestant);
        document.getElementById('montant_propose').max = montantRestant;
        document.getElementById('montant_propose').value = montantRestant;
        
        // Définir la date minimale (aujourd'hui)
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('date_paiement_propose').min = today;
        document.getElementById('date_paiement_propose').value = today;
        
        new bootstrap.Modal(document.getElementById('modalProposerPaiement')).show();
    }
    
    // Soumettre une proposition de paiement
    async function soumettrePropositionPaiement() {
        const detteId = document.getElementById('dette_id_paiement').value;
        const montant = document.getElementById('montant_propose').value;
        const mode = document.getElementById('mode_paiement_propose').value;
        const date = document.getElementById('date_paiement_propose').value;
        const commentaire = document.getElementById('commentaire_paiement').value;
        
        if (!montant || montant <= 0) {
            alert('Veuillez saisir un montant valide');
            return;
        }
        
        if (!mode) {
            alert('Veuillez sélectionner un mode de paiement');
            return;
        }
        
        if (!date) {
            alert('Veuillez sélectionner une date');
            return;
        }
        
        try {
            const response = await fetch('api/proposer_paiement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    dette_id: detteId,
                    montant: montant,
                    mode_paiement: mode,
                    date_paiement: date,
                    commentaire: commentaire
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Proposition envoyée avec succès!');
                document.getElementById('modalProposerPaiement').querySelector('.btn-close').click();
                location.reload();
            } else {
                alert('Erreur: ' + data.message);
            }
        } catch (error) {
            console.error('Erreur:', error);
            alert('Erreur lors de l\'envoi de la proposition');
        }
    }
    
    // Contacter un gestionnaire
    function contacterGestionnaire(nomGestionnaire) {
        alert(`Contacter le gestionnaire: ${nomGestionnaire}\n\nService financier: +242 XX XX XX XX\nEmail: finances@isgi.cg`);
    }
    
    // Contacter le service financier
    function contacterServiceFinancier() {
        alert(`Service financier ISGI\n\n📞 Téléphone: +242 XX XX XX XX\n✉️ Email: finances@isgi.cg\n🕐 Horaires: 8h00 - 16h00 (Lundi-Vendredi)\n📍 Localisation: Bâtiment administratif, 1er étage`);
    }
    
    // Proposer un plan de paiement
    function proposerPlanPaiement() {
        const html = `
            <form id="formPlanPaiement">
                <div class="mb-3">
                    <label class="form-label">Sélectionner la dette à échelonner</label>
                    <select class="form-select" id="dette_plan">
                        <option value="">Choisir une dette</option>
                        <?php foreach($dettes_liste as $dette): 
                            if(($dette['statut'] ?? '') == 'en_cours' || ($dette['statut'] ?? '') == 'en_retard'): 
                                $montant_restant = floatval($dette['montant_restant'] ?? 0);
                        ?>
                        <option value="<?php echo intval($dette['id']); ?>">
                            <?php echo getTypeDetteBadge($dette['type_dette'] ?? ''); ?> - 
                            <?php echo safeHtml($dette['annee_academique'] ?? ''); ?> - 
                            Reste: <?php echo formatMoney($montant_restant); ?>
                        </option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nombre de tranches</label>
                    <select class="form-select" id="nombre_tranches">
                        <option value="2">2 tranches</option>
                        <option value="3">3 tranches</option>
                        <option value="4">4 tranches</option>
                        <option value="6">6 tranches</option>
                        <option value="12">12 tranches</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Période</label>
                    <select class="form-select" id="periode_plan">
                        <option value="mensuelle">Mensuelle</option>
                        <option value="trimestrielle">Trimestrielle</option>
                        <option value="semestrielle">Semestrielle</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date de début</label>
                    <input type="date" class="form-control" id="date_debut_plan" min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Justification (optionnel)</label>
                    <textarea class="form-control" id="justification_plan" rows="3" 
                              placeholder="Expliquez pourquoi vous avez besoin d'un échelonnement..."></textarea>
                </div>
            </form>
        `;
        
        const modal = new bootstrap.Modal(document.createElement('div'));
        modal._element.className = 'modal fade';
        modal._element.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Demander un plan de paiement échelonné</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${html}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" onclick="soumettrePlanPaiement()">
                            <i class="fas fa-paper-plane"></i> Soumettre la demande
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal._element);
        modal.show();
        
        // Nettoyer après fermeture
        modal._element.addEventListener('hidden.bs.modal', function() {
            document.body.removeChild(modal._element);
        });
    }
    
    // Soumettre un plan de paiement
    async function soumettrePlanPaiement() {
        const detteId = document.getElementById('dette_plan').value;
        const nombreTranches = document.getElementById('nombre_tranches').value;
        const periode = document.getElementById('periode_plan').value;
        const dateDebut = document.getElementById('date_debut_plan').value;
        const justification = document.getElementById('justification_plan').value;
        
        if (!detteId) {
            alert('Veuillez sélectionner une dette');
            return;
        }
        
        if (!dateDebut) {
            alert('Veuillez sélectionner une date de début');
            return;
        }
        
        try {
            const response = await fetch('api/proposer_plan_paiement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    dette_id: detteId,
                    nombre_tranches: nombreTranches,
                    periode: periode,
                    date_debut: dateDebut,
                    justification: justification
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Demande de plan de paiement envoyée avec succès!');
                document.querySelector('#modalProposerPaiement .btn-close').click();
                location.reload();
            } else {
                alert('Erreur: ' + data.message);
            }
        } catch (error) {
            console.error('Erreur:', error);
            alert('Erreur lors de l\'envoi de la demande');
        }
    }
    
    // Fonctions utilitaires pour les modales
    function getTypeBadge(type) {
        switch(type) {
            case 'scolarite': return '<span class="badge bg-primary">Scolarité</span>';
            case 'inscription': return '<span class="badge bg-info">Inscription</span>';
            case 'examen': return '<span class="badge bg-warning">Examen</span>';
            default: return '<span class="badge bg-secondary">' + type + '</span>';
        }
    }
    
    function formatDate(dateString) {
        if (!dateString || dateString === '0000-00-00') return 'Non définie';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR');
    }
    
    // Imprimer les détails
    function imprimerDetails() {
        const printContent = document.getElementById('detailsDetteContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Détails de la Dette</title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        .print-header { text-align: center; margin-bottom: 20px; }
                        .print-header h2 { color: #2c3e50; }
                        .montant { font-weight: bold; }
                        .montant-positif { color: green; }
                        .montant-negatif { color: red; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h2>ISGI - Détails de la Dette</h2>
                        <p>Étudiant: <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></p>
                        <p>Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?></p>
                        <p>Date d'impression: ${new Date().toLocaleDateString('fr-FR')}</p>
                    </div>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
    </script>
</body>
</html>