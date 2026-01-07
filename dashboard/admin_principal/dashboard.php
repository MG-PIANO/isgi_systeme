<?php
// dashboard/admin_principal/dashboard.php

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
    $pageTitle = "Administrateur Principal - Vue Globale";
    
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
        'total_sites' => 0,
        'total_etudiants' => 0,
        'total_professeurs' => 0,
        'recettes_mois' => 0,
        'recettes_mois_precedent' => 0,
        'evolution_recettes' => 0,
        'total_cours_actifs' => 0,
        'total_dettes' => 0,
        'paiements_attente' => 0,
        'taux_recouvrement' => 0
    );
    
    $sites = array();
    $repartition_filieres = array();
    $evenements_avenir = array();
    $calendrier_academique = array();
    $cours_en_ligne = array();
    $notifications = array();
    $evolution_inscriptions = array();
    $nouveaux_etudiants = array();
    $performance_academique = array();
    $dettes_detail = array();
    $demandes_reunion = array();
    $demandeCount = 0;
    $error = null;
    
    // Fonction pour exécuter les requêtes en toute sécurité
    function executeQuery($db, $query) {
        try {
            $result = $db->query($query);
            if ($result) {
                return $result->fetchAll(PDO::FETCH_ASSOC);
            }
            return array();
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            return array();
        }
    }
    
    function executeSingleQuery($db, $query) {
        try {
            $result = $db->query($query);
            if ($result) {
                return $result->fetch(PDO::FETCH_ASSOC);
            }
            return array('total' => 0);
        } catch (Exception $e) {
            error_log("Single query error: " . $e->getMessage());
            return array('total' => 0);
        }
    }
    
    // Récupérer les statistiques de base
    $result = executeSingleQuery($db, "SELECT COUNT(*) as total FROM sites WHERE statut = 'actif'");
    $stats['total_sites'] = isset($result['total']) ? $result['total'] : 0;
    
    $result = executeSingleQuery($db, "SELECT COUNT(*) as total FROM etudiants WHERE statut = 'actif'");
    $stats['total_etudiants'] = isset($result['total']) ? $result['total'] : 0;
    
    $result = executeSingleQuery($db, "SELECT COUNT(*) as total FROM enseignants WHERE statut = 'actif'");
    $stats['total_professeurs'] = isset($result['total']) ? $result['total'] : 0;
    
    // Récupérer le total des matières actives (remplace "cours")
    $result = executeSingleQuery($db, "SELECT COUNT(*) as total FROM matieres m 
        JOIN filieres f ON m.filiere_id = f.id
        JOIN sites s ON m.site_id = s.id
        WHERE s.statut = 'actif'");
    $stats['total_cours_actifs'] = isset($result['total']) ? $result['total'] : 0;
    
    // Récupérer les recettes du mois en cours
    $currentMonthStart = date('Y-m-01');
    $currentMonthEnd = date('Y-m-t');
    $result = executeSingleQuery($db, "SELECT COALESCE(SUM(montant), 0) as total FROM paiements 
        WHERE statut = 'valide' 
        AND DATE(date_paiement) BETWEEN '$currentMonthStart' AND '$currentMonthEnd'");
    $stats['recettes_mois'] = isset($result['total']) ? $result['total'] : 0;
    
    // Récupérer les recettes du mois précédent
    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
    $result = executeSingleQuery($db, "SELECT COALESCE(SUM(montant), 0) as total FROM paiements 
        WHERE statut = 'valide' 
        AND DATE(date_paiement) BETWEEN '$lastMonthStart' AND '$lastMonthEnd'");
    $stats['recettes_mois_precedent'] = isset($result['total']) ? $result['total'] : 0;
    
    // Calculer l'évolution des recettes
    if ($stats['recettes_mois_precedent'] > 0) {
        $stats['evolution_recettes'] = (($stats['recettes_mois'] - $stats['recettes_mois_precedent']) / $stats['recettes_mois_precedent']) * 100;
    } else {
        $stats['evolution_recettes'] = $stats['recettes_mois'] > 0 ? 100 : 0;
    }
    
    // Récupérer le total des dettes (somme de montant_restant)
    $result = executeSingleQuery($db, "SELECT COALESCE(SUM(montant_restant), 0) as total FROM dettes 
        WHERE statut IN ('en_cours', 'en_retard')");
    $stats['total_dettes'] = isset($result['total']) ? $result['total'] : 0;
    
    // Récupérer le nombre de paiements en attente
    $result = executeSingleQuery($db, "SELECT COUNT(*) as total FROM paiements WHERE statut = 'en_attente'");
    $stats['paiements_attente'] = isset($result['total']) ? $result['total'] : 0;
    
    // Récupérer le taux de recouvrement (paiements validés / total attendu)
    $result = executeSingleQuery($db, "SELECT 
        COALESCE(SUM(CASE WHEN statut = 'valide' THEN montant ELSE 0 END), 0) as total_valide,
        COALESCE(SUM(montant), 0) as total_attendu
        FROM paiements");
    
    $total_valide = isset($result['total_valide']) ? $result['total_valide'] : 0;
    $total_attendu = isset($result['total_attendu']) ? $result['total_attendu'] : 0;
    
    if ($total_attendu > 0) {
        $stats['taux_recouvrement'] = ($total_valide / $total_attendu) * 100;
    } else {
        $stats['taux_recouvrement'] = 0;
    }
    
    // Récupérer les données des sites
    $sites = executeQuery($db, "SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville");
    
    // Récupérer les détails des dettes
    $dettes_detail = executeQuery($db, "SELECT d.*, e.matricule, e.nom, e.prenom 
        FROM dettes d
        JOIN etudiants e ON d.etudiant_id = e.id
        WHERE d.statut IN ('en_cours', 'en_retard')
        ORDER BY d.montant_restant DESC
        LIMIT 10");
    
    // Récupérer les nouveaux étudiants du mois
    $currentMonth = date('m');
    $currentYear = date('Y');
    $nouveaux_etudiants = executeQuery($db, "SELECT e.*, s.nom as site_nom 
        FROM etudiants e
        JOIN sites s ON e.site_id = s.id
        WHERE MONTH(e.date_inscription) = $currentMonth 
        AND YEAR(e.date_inscription) = $currentYear
        AND e.statut = 'actif'
        ORDER BY e.date_inscription DESC
        LIMIT 5");
    
    // Récupérer la répartition par filière
    $repartition_filieres = executeQuery($db, "SELECT 
        f.nom as filiere,
        COUNT(e.id) as nb_etudiants
        FROM etudiants e
        JOIN classes c ON e.classe_id = c.id
        JOIN filieres f ON c.filiere_id = f.id
        WHERE e.statut = 'actif'
        GROUP BY f.nom
        ORDER BY nb_etudiants DESC
        LIMIT 10");
    
    // Récupérer les événements à venir (calendrier académique)
    $evenements_avenir = executeQuery($db, "SELECT ca.*, s.nom as site_nom, aa.libelle as annee_libelle
        FROM calendrier_academique ca
        JOIN sites s ON ca.site_id = s.id
        JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
        WHERE ca.statut = 'planifie' 
        AND ca.date_debut_cours >= CURDATE()
        ORDER BY ca.date_debut_cours ASC
        LIMIT 5");
    
    // Récupérer les cours/matières récentes
    $cours_en_ligne = executeQuery($db, "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
        FROM matieres m
        JOIN filieres f ON m.filiere_id = f.id
        JOIN niveaux n ON m.niveau_id = n.id
        JOIN sites s ON m.site_id = s.id
        ORDER BY m.id DESC
        LIMIT 5");
    
    // Récupérer les examens à venir
    $calendrier_academique = executeQuery($db, "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom, s.nom as site_nom
        FROM calendrier_examens ce
        JOIN matieres m ON ce.matiere_id = m.id
        JOIN classes c ON ce.classe_id = c.id
        JOIN sites s ON m.site_id = s.id
        WHERE ce.statut = 'planifie' 
        AND ce.date_examen >= CURDATE()
        ORDER BY ce.date_examen ASC
        LIMIT 5");
    
    // Récupérer l'évolution des inscriptions (par mois)
    $evolution_inscriptions = executeQuery($db, "SELECT 
        DATE_FORMAT(date_inscription, '%Y-%m') as mois,
        COUNT(*) as nb_inscriptions
        FROM etudiants
        WHERE statut = 'actif'
        AND date_inscription >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date_inscription, '%Y-%m')
        ORDER BY mois ASC");
    
    // Récupérer les demandes de réunion
    $demandes_reunion = executeQuery($db, "SELECT r.*, s.nom as site_nom, u.nom as organisateur_nom, u.prenom as organisateur_prenom
        FROM reunions r
        JOIN sites s ON r.site_id = s.id
        JOIN utilisateurs u ON r.organisateur_id = u.id
        WHERE r.statut = 'planifiee'
        ORDER BY r.date_reunion ASC
        LIMIT 5");
    
    // Récupérer les notifications récentes
    $notifications = executeQuery($db, "SELECT n.*, u.nom, u.prenom 
        FROM notifications n
        JOIN utilisateurs u ON n.utilisateur_id = u.id
        WHERE n.lue = 0
        ORDER BY n.date_notification DESC
        LIMIT 10");
    
    // Compter les demandes en attente
    $result = executeSingleQuery($db, "SELECT COUNT(*) as count FROM demande_inscriptions WHERE statut = 'en_attente'");
    $demandeCount = isset($result['count']) ? $result['count'] : 0;
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
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
                <h5 class="mt-2 mb-1">ISGI ADMIN</h5>
                <div class="user-role">Administrateur Principal</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Vue Globale Multi-Sites</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Global</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Multi-Sites</div>
                    <a href="sites.php" class="nav-link">
                        <i class="fas fa-building"></i>
                        <span>Tous les Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Tous les Utilisateurs</span>
                    </a>
                    <a href="validation_comptes.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Validation des comptes Utilisateurs</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes d'Inscription</span>
                        <?php if ($demandeCount > 0): ?>
                        <span class="nav-badge"><?php echo $demandeCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique Global</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Tous les Professeurs</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier Examens</span>
                    </a>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier Académique</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Pédagogie & Ressources</div>
                    <a href="cours_en_ligne.php" class="nav-link">
                        <i class="fas fa-laptop"></i>
                        <span>Cours en Ligne</span>
                    </a>
                    <a href="bibliotheque/bibliotheque.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Bibliothèque</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances Globales</div>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Gestion Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Gestion Dettes</span>
                    </a>
                    <a href="rapport_financier.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports Financiers</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Administration</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="rapports_statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Rapports Statistiques</span>
                    </a>
                    <a href="notifications.php" class="nav-link">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
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
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Vue Globale - Administrateur Principal
                        </h2>
                        <p class="text-muted mb-0">Tableau de bord multi-sites avec statistiques complètes</p>
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
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques Principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_sites']; ?></div>
                        <div class="stat-label">Sites Actifs</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_etudiants']; ?></div>
                        <div class="stat-label">Étudiants Totaux</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_professeurs']; ?></div>
                        <div class="stat-label">Professeurs Totaux</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_cours_actifs']; ?></div>
                        <div class="stat-label">Matières Actives</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Statistiques Financières -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['recettes_mois']); ?></div>
                        <div class="stat-label">Recettes du Mois</div>
                        <div class="stat-change <?php echo $stats['evolution_recettes'] >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $stats['evolution_recettes'] >= 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo number_format(abs($stats['evolution_recettes']), 1); ?>%
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['total_dettes']); ?></div>
                        <div class="stat-label">Total Dettes</div>
                        <div class="stat-change">
                            <?php echo count($dettes_detail); ?> étudiants concernés
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['paiements_attente']; ?></div>
                        <div class="stat-label">Paiements en Attente</div>
                        <div class="stat-change">
                            Taux recouvrement: <?php echo number_format($stats['taux_recouvrement'], 1); ?>%
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets pour différentes vues -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="dashboardTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="sites-tab" data-bs-toggle="tab" data-bs-target="#sites" type="button">
                                <i class="fas fa-building me-2"></i>Sites
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="etudiants-tab" data-bs-toggle="tab" data-bs-target="#etudiants" type="button">
                                <i class="fas fa-user-graduate me-2"></i>Étudiants
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="academique-tab" data-bs-toggle="tab" data-bs-target="#academique" type="button">
                                <i class="fas fa-graduation-cap me-2"></i>Académique
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="finances-tab" data-bs-toggle="tab" data-bs-target="#finances" type="button">
                                <i class="fas fa-chart-line me-2"></i>Finances
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboardTabsContent">
                        <!-- Tab 1: Sites -->
                        <div class="tab-pane fade show active" id="sites">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5><i class="fas fa-building me-2"></i>Statistiques par Site</h5>
                                    <?php if(empty($sites)): ?>
                                    <div class="alert alert-info">
                                        Aucun site actif trouvé
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Site</th>
                                                    <th>Ville</th>
                                                    <th>Téléphone</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($sites as $site): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($site['nom']); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($site['ville']); ?></td>
                                                    <td><?php echo htmlspecialchars($site['telephone'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="badge bg-success">Actif</span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <h5><i class="fas fa-chart-pie me-2"></i>Répartition par Site</h5>
                                    <?php if(empty($sites)): ?>
                                    <div class="alert alert-info">
                                        Aucune donnée disponible
                                    </div>
                                    <?php else: ?>
                                    <canvas id="sitesChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('sitesChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: [<?php foreach($sites as $site): ?>'<?php echo htmlspecialchars($site['nom']); ?>',<?php endforeach; ?>],
                                                datasets: [{
                                                    data: [<?php foreach($sites as $site): ?>1,<?php endforeach; ?>],
                                                    backgroundColor: [
                                                        '#3498db',
                                                        '#2ecc71',
                                                        '#e74c3c',
                                                        '#f39c12',
                                                        '#9b59b6'
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
                                    </script>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Étudiants -->
                        <div class="tab-pane fade" id="etudiants">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-user-plus me-2"></i>Nouveaux Étudiants</h5>
                                    <?php if(empty($nouveaux_etudiants)): ?>
                                    <div class="alert alert-info">
                                        Aucun nouvel étudiant ce mois-ci
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matricule</th>
                                                    <th>Nom</th>
                                                    <th>Site</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($nouveaux_etudiants as $etudiant): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($etudiant['matricule']); ?></td>
                                                    <td><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
                                                    <td><?php echo htmlspecialchars($etudiant['site_nom']); ?></td>
                                                    <td><?php echo formatDateFr($etudiant['date_inscription']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-bar me-2"></i>Évolution des Inscriptions</h5>
                                    <?php if(empty($evolution_inscriptions)): ?>
                                    <div class="alert alert-info">
                                        Aucune donnée d'évolution disponible
                                    </div>
                                    <?php else: ?>
                                    <canvas id="inscriptionsChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('inscriptionsChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'line',
                                            data: {
                                                labels: [<?php foreach($evolution_inscriptions as $inscription): ?>'<?php echo $inscription['mois']; ?>',<?php endforeach; ?>],
                                                datasets: [{
                                                    label: 'Inscriptions',
                                                    data: [<?php foreach($evolution_inscriptions as $inscription): ?><?php echo $inscription['nb_inscriptions']; ?>,<?php endforeach; ?>],
                                                    borderColor: '#3498db',
                                                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                                    tension: 0.1
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        display: true
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-sitemap me-2"></i>Répartition par Filière</h5>
                                    <?php if(empty($repartition_filieres)): ?>
                                    <div class="alert alert-info">
                                        Aucune donnée de répartition disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Filière</th>
                                                    <th>Nombre d'étudiants</th>
                                                    <th>Pourcentage</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_etudiants_filieres = 0;
                                                foreach($repartition_filieres as $filiere):
                                                    $total_etudiants_filieres += $filiere['nb_etudiants'];
                                                endforeach;
                                                foreach($repartition_filieres as $filiere):
                                                    $pourcentage = ($total_etudiants_filieres > 0) ? ($filiere['nb_etudiants'] / $total_etudiants_filieres * 100) : 0;
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($filiere['filiere']); ?></td>
                                                    <td><?php echo $filiere['nb_etudiants']; ?></td>
                                                    <td>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $pourcentage; ?>%">
                                                                <?php echo number_format($pourcentage, 1); ?>%
                                                            </div>
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
                        
                        <!-- Tab 3: Académique -->
                        <div class="tab-pane fade" id="academique">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-calendar-alt me-2"></i>Calendrier Académique</h5>
                                    <?php if(empty($calendrier_academique)): ?>
                                    <div class="alert alert-info">
                                        Aucun examen planifié
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($calendrier_academique as $examen): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($examen['matiere_nom']); ?></h6>
                                                <small><?php echo formatDateFr($examen['date_examen']); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Classe: <?php echo htmlspecialchars($examen['classe_nom']); ?></small><br>
                                                <small>Site: <?php echo htmlspecialchars($examen['site_nom']); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-book me-2"></i>Matières Récentes</h5>
                                    <?php if(empty($cours_en_ligne)): ?>
                                    <div class="alert alert-info">
                                        Aucune matière disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($cours_en_ligne as $matiere): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($matiere['nom']); ?></h6>
                                                <small><?php echo $matiere['coefficient']; ?> coeff</small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Filière: <?php echo htmlspecialchars($matiere['filiere_nom']); ?></small><br>
                                                <small>Niveau: <?php echo htmlspecialchars($matiere['niveau_libelle']); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-chart-line me-2"></i>Événements à Venir</h5>
                                    <?php if(empty($evenements_avenir)): ?>
                                    <div class="alert alert-info">
                                        Aucun événement à venir
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($evenements_avenir as $event): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">Semestre <?php echo $event['semestre']; ?> - <?php echo $event['type_rentree']; ?></h6>
                                                <small>Début: <?php echo formatDateFr($event['date_debut_cours']); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Site: <?php echo htmlspecialchars($event['site_nom']); ?></small><br>
                                                <small>Année: <?php echo htmlspecialchars($event['annee_libelle']); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar-check me-2"></i>Performance Académique</h5>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Les données de performance seront disponibles après les évaluations
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Finances -->
                        <div class="tab-pane fade" id="finances">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-invoice-dollar me-2"></i>Dettes Étudiantes</h5>
                                    <?php if(empty($dettes_detail)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune dette enregistrée
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Montant dû</th>
                                                    <th>Restant</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($dettes_detail as $dette): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($dette['nom'] . ' ' . $dette['prenom']); ?><br>
                                                        <small><?php echo htmlspecialchars($dette['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo formatMoney($dette['montant_du']); ?></td>
                                                    <td><?php echo formatMoney($dette['montant_restant']); ?></td>
                                                    <td><?php echo getStatutBadge($dette['statut']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-chart-bar me-2"></i>Statistiques Financières</h5>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-success">
                                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                                    </div>
                                                    <h5 class="mt-2"><?php echo formatMoney($stats['recettes_mois']); ?></h5>
                                                    <p class="text-muted">Recettes Mois</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-danger">
                                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                                    </div>
                                                    <h5 class="mt-2"><?php echo $stats['paiements_attente']; ?></h5>
                                                    <p class="text-muted">Paiements Attente</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-users me-2"></i>Demandes de Réunion</h5>
                                    <?php if(empty($demandes_reunion)): ?>
                                    <div class="alert alert-info">
                                        Aucune demande de réunion en attente
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($demandes_reunion as $reunion): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($reunion['titre']); ?></h6>
                                                <small><?php echo formatDateFr($reunion['date_reunion'], 'd/m/Y H:i'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Organisateur: <?php echo htmlspecialchars($reunion['organisateur_nom'] . ' ' . $reunion['organisateur_prenom']); ?></small><br>
                                                <small>Site: <?php echo htmlspecialchars($reunion['site_nom']); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Notifications et Actions Rapides -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bell me-2"></i>
                                Notifications Récentes
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($notifications)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune notification récente
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($notifications as $notification): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <?php if($notification['type'] == 'urgence'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                                            <?php elseif($notification['type'] == 'success'): ?>
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            <?php else: ?>
                                            <i class="fas fa-info-circle text-info me-2"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($notification['titre']); ?>
                                        </h6>
                                        <small><?php echo formatDateFr($notification['date_notification'], 'd/m/Y H:i'); ?></small>
                                    </div>
                                    <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <small>De: <?php echo htmlspecialchars($notification['nom'] . ' ' . $notification['prenom']); ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="demandes.php" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Voir Demandes
                                    <?php if ($demandeCount > 0): ?>
                                    <span class="badge bg-danger ms-2"><?php echo $demandeCount; ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="reunions.php?action=create" class="btn btn-success">
                                    <i class="fas fa-calendar-plus me-2"></i>Créer Réunion
                                </a>
                                <a href="bibliotheque.php?action=add" class="btn btn-info">
                                    <i class="fas fa-book-medical me-2"></i>Ajouter Livre
                                </a>
                                <a href="rapports_statistiques.php" class="btn btn-warning">
                                    <i class="fas fa-chart-pie me-2"></i>Générer Rapport
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Système
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-lightbulb"></i> Fonctionnalités Administrateur</h6>
                                <ul class="mb-0 small">
                                    <li>Gestion complète de tous les sites</li>
                                    <li>Supervision de tous les utilisateurs</li>
                                    <li>Validation des inscriptions globales</li>
                                    <li>Consultation des statistiques multi-sites</li>
                                    <li>Gestion des calendriers académiques</li>
                                    <li>Rapports financiers détaillés</li>
                                </ul>
                            </div>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Données en temps réel</h6>
                                <p class="mb-0">Les statistiques sont actualisées à chaque chargement de la page.</p>
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
    });
    </script>
</body>
</html>