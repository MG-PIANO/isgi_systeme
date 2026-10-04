<?php
// dashboard/surveillant/statistiques.php

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

// Vérifier le rôle (Surveillant Général = rôle 6)
if ($_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/dashboard/' . $_SESSION['role_name'] . '/dashboard.php');
    exit();
}

// Inclure la configuration
require_once ROOT_PATH . '/config/database.php';

// Initialiser la connexion
$db = Database::getInstance()->getConnection();

// Définir le titre de la page
$pageTitle = "Surveillant Général - Statistiques";

// Récupérer l'ID du site du surveillant
$site_id = $_SESSION['site_id'];

// Récupérer les paramètres
$annee = isset($_GET['annee']) ? $_GET['annee'] : date('Y');
$mois = isset($_GET['mois']) ? $_GET['mois'] : date('m');
$type_stat = isset($_GET['type_stat']) ? $_GET['type_stat'] : 'global';

// Initialiser les variables
$statistiques_globales = [];
$statistiques_par_classe = [];
$statistiques_par_mois = [];
$statistiques_par_jour = [];
$meilleurs_etudiants = [];
$pires_etudiants = [];
$tendance_absences = [];
$classes = [];

try {
    // 1. Récupérer les classes
    $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle
              FROM classes c
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              WHERE c.site_id = :site_id
              AND c.annee_academique_id IN (
                SELECT id FROM annees_academiques WHERE statut = 'active'
              )
              ORDER BY c.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Statistiques globales
    $query = "SELECT 
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as total_presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as total_absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as total_retards,
                SUM(CASE WHEN statut = 'justifie' THEN 1 ELSE 0 END) as total_justifies,
                COUNT(DISTINCT etudiant_id) as etudiants_uniques,
                COUNT(DISTINCT DATE(date_heure)) as jours_uniques
              FROM presences 
              WHERE site_id = :site_id
                AND YEAR(date_heure) = :annee";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $statistiques_globales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($statistiques_globales && $statistiques_globales['total_presences'] > 0) {
        $statistiques_globales['taux_presence'] = 
            ($statistiques_globales['total_presents'] / $statistiques_globales['total_presences']) * 100;
        $statistiques_globales['taux_absence'] = 
            ($statistiques_globales['total_absents'] / $statistiques_globales['total_presences']) * 100;
        $statistiques_globales['taux_retard'] = 
            ($statistiques_globales['total_retards'] / $statistiques_globales['total_presences']) * 100;
        $statistiques_globales['moyenne_presences_par_jour'] = 
            $statistiques_globales['total_presences'] / $statistiques_globales['jours_uniques'];
    }

    // 3. Statistiques par classe
    $query = "SELECT 
                c.nom as classe_nom,
                COUNT(p.id) as total_presences,
                SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as retards,
                COUNT(DISTINCT p.etudiant_id) as etudiants_uniques,
                (SELECT COUNT(*) FROM etudiants e2 WHERE e2.classe_id = c.id AND e2.statut = 'actif') as effectif_total
              FROM presences p
              LEFT JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE p.site_id = :site_id
                AND YEAR(p.date_heure) = :annee
                AND c.id IS NOT NULL
              GROUP BY c.id
              ORDER BY (presents * 1.0 / NULLIF(total_presences, 0)) DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $statistiques_par_classe = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer les pourcentages pour chaque classe
    foreach ($statistiques_par_classe as &$classe) {
        if ($classe['total_presences'] > 0) {
            $classe['taux_presence'] = ($classe['presents'] / $classe['total_presences']) * 100;
            $classe['taux_absence'] = ($classe['absents'] / $classe['total_presences']) * 100;
            $classe['taux_retard'] = ($classe['retards'] / $classe['total_presences']) * 100;
        } else {
            $classe['taux_presence'] = 0;
            $classe['taux_absence'] = 0;
            $classe['taux_retard'] = 0;
        }
    }

    // 4. Statistiques par mois
    $query = "SELECT 
                MONTH(date_heure) as mois,
                YEAR(date_heure) as annee,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards,
                COUNT(DISTINCT etudiant_id) as etudiants_uniques
              FROM presences 
              WHERE site_id = :site_id
                AND YEAR(date_heure) = :annee
              GROUP BY YEAR(date_heure), MONTH(date_heure)
              ORDER BY annee DESC, mois DESC
              LIMIT 12";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $statistiques_par_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ajouter les noms des mois
    $noms_mois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    
    foreach ($statistiques_par_mois as &$stat_mois) {
        $stat_mois['mois_nom'] = $noms_mois[$stat_mois['mois']] ?? 'Inconnu';
        if ($stat_mois['total_presences'] > 0) {
            $stat_mois['taux_presence'] = ($stat_mois['presents'] / $stat_mois['total_presences']) * 100;
        } else {
            $stat_mois['taux_presence'] = 0;
        }
    }

    // 5. Statistiques par jour de la semaine
    $query = "SELECT 
                DAYNAME(date_heure) as jour_nom,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences 
              WHERE site_id = :site_id
                AND YEAR(date_heure) = :annee
              GROUP BY DAYNAME(date_heure)
              ORDER BY 
                CASE DAYNAME(date_heure)
                    WHEN 'Monday' THEN 1
                    WHEN 'Tuesday' THEN 2
                    WHEN 'Wednesday' THEN 3
                    WHEN 'Thursday' THEN 4
                    WHEN 'Friday' THEN 5
                    WHEN 'Saturday' THEN 6
                    WHEN 'Sunday' THEN 7
                END";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $statistiques_par_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Traduire les noms des jours
    $jours_traduits = [
        'Monday' => 'Lundi',
        'Tuesday' => 'Mardi',
        'Wednesday' => 'Mercredi',
        'Thursday' => 'Jeudi',
        'Friday' => 'Vendredi',
        'Saturday' => 'Samedi',
        'Sunday' => 'Dimanche'
    ];
    
    foreach ($statistiques_par_jour as &$stat_jour) {
        $stat_jour['jour_nom_fr'] = $jours_traduits[$stat_jour['jour_nom']] ?? $stat_jour['jour_nom'];
        if ($stat_jour['total_presences'] > 0) {
            $stat_jour['taux_presence'] = ($stat_jour['presents'] / $stat_jour['total_presences']) * 100;
        } else {
            $stat_jour['taux_presence'] = 0;
        }
    }

    // 6. Meilleurs étudiants (meilleur taux de présence)
    $query = "SELECT 
                e.id,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                COUNT(p.id) as total_presences,
                SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM etudiants e
              LEFT JOIN presences p ON e.id = p.etudiant_id
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE e.site_id = :site_id
                AND e.statut = 'actif'
                AND YEAR(p.date_heure) = :annee
              GROUP BY e.id
              HAVING total_presences >= 10
              ORDER BY (presents * 1.0 / NULLIF(total_presences, 0)) DESC
              LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $meilleurs_etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer les pourcentages
    foreach ($meilleurs_etudiants as &$etudiant) {
        if ($etudiant['total_presences'] > 0) {
            $etudiant['taux_presence'] = ($etudiant['presents'] / $etudiant['total_presences']) * 100;
        } else {
            $etudiant['taux_presence'] = 0;
        }
    }

    // 7. Pires étudiants (pire taux de présence)
    $query = "SELECT 
                e.id,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                COUNT(p.id) as total_presences,
                SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM etudiants e
              LEFT JOIN presences p ON e.id = p.etudiant_id
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE e.site_id = :site_id
                AND e.statut = 'actif'
                AND YEAR(p.date_heure) = :annee
              GROUP BY e.id
              HAVING total_presences >= 5
              ORDER BY (presents * 1.0 / NULLIF(total_presences, 0)) ASC
              LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':annee' => $annee]);
    $pires_etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer les pourcentages
    foreach ($pires_etudiants as &$etudiant) {
        if ($etudiant['total_presences'] > 0) {
            $etudiant['taux_presence'] = ($etudiant['presents'] / $etudiant['total_presences']) * 100;
        } else {
            $etudiant['taux_presence'] = 0;
        }
    }

    // 8. Tendances des absences (30 derniers jours)
    $date_30jours = date('Y-m-d', strtotime('-30 days'));
    $query = "SELECT 
                DATE(date_heure) as date_jour,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'absent' AND type_presence IN ('entree_ecole', 'entree_classe') THEN 1 ELSE 0 END) as absents_entree
              FROM presences 
              WHERE site_id = :site_id
                AND DATE(date_heure) >= :date_30jours
              GROUP BY DATE(date_heure)
              ORDER BY date_jour ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':date_30jours' => $date_30jours
    ]);
    $tendance_absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    
    <!-- Chart.js -->
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
        overflow-x: hidden;
    }
    
    /* Header Mobile */
    .mobile-header {
        display: none;
        background-color: var(--sidebar-bg);
        color: white;
        padding: 10px 15px;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1050;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .mobile-header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .hamburger-btn {
        background: transparent;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 5px 10px;
    }
    
    .mobile-brand {
        font-size: 18px;
        font-weight: bold;
    }
    
    /* Sidebar */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        z-index: 1040;
        transition: transform 0.3s ease-in-out;
    }
    
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0,0,0,0.5);
        z-index: 1039;
    }
    
    .sidebar-header {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
        background-color: rgba(0,0,0,0.1);
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
        background: var(--info-color);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        margin-top: 5px;
    }
    
    /* Navigation */
    .sidebar-nav {
        padding: 15px 0;
    }
    
    .nav-section {
        margin-bottom: 15px;
    }
    
    .nav-section-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.6);
        margin-bottom: 10px;
        padding: 0 20px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: var(--sidebar-text);
        text-decoration: none;
        transition: all 0.3s;
        border-left: 3px solid transparent;
    }
    
    .nav-link:hover, .nav-link.active {
        background-color: rgba(255, 255, 255, 0.1);
        color: white;
        border-left-color: var(--secondary-color);
    }
    
    .nav-link i {
        width: 20px;
        margin-right: 12px;
        text-align: center;
        font-size: 16px;
    }
    
    .nav-link span {
        font-size: 14px;
    }
    
    .nav-badge {
        margin-left: auto;
        background: var(--accent-color);
        color: white;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 10px;
    }
    
    /* Contenu principal */
    .main-content {
        padding: 20px;
        min-height: 100vh;
        transition: margin-left 0.3s ease-in-out;
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.02);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Tableaux */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table {
        color: var(--text-color);
        min-width: 600px;
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 12px 15px;
        font-size: 14px;
        white-space: nowrap;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px 15px;
        color: var(--text-color);
        font-size: 14px;
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.03);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
        font-weight: 500;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        /* Mobile Header */
        .mobile-header {
            display: block;
            height: 60px;
        }
        
        /* Sidebar Mobile */
        .sidebar {
            transform: translateX(-100%);
            width: 280px;
            top: 60px;
            height: calc(100vh - 60px);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 0 !important;
            padding: 80px 15px 20px 15px;
        }
        
        /* Content Header */
        .content-header .d-flex {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .content-header .btn-group {
            margin-top: 15px;
            width: 100%;
        }
        
        .content-header .btn-group .btn {
            flex: 1;
        }
        
        /* KPI Cards */
        .col-md-3 {
            margin-bottom: 15px;
        }
        
        .stat-icon {
            font-size: 1.5rem !important;
        }
        
        .stat-value {
            font-size: 1.2rem !important;
        }
        
        /* Chart Container */
        .chart-container {
            height: 250px !important;
        }
        
        /* Filter Card */
        .filter-card .row.g-3 > div {
            margin-bottom: 10px;
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            grid-template-columns: 1fr !important;
            gap: 10px;
        }
    }
    
    @media (max-width: 576px) {
        /* Stats Grid */
        .row.g-2 {
            margin: -5px;
        }
        
        .row.g-2 > [class*="col-"] {
            padding: 5px;
        }
        
        /* Cards */
        .card-header, .card-body {
            padding: 15px;
        }
        
        /* Buttons */
        .btn {
            font-size: 14px;
            padding: 8px 15px;
        }
        
        /* Progress bars */
        .progress-thin {
            height: 6px;
        }
        
        /* Rang badges */
        .rang-badge {
            width: 25px;
            height: 25px;
            font-size: 12px;
        }
    }
    
    /* Mode Desktop */
    @media (min-width: 769px) {
        .main-content {
            margin-left: 250px;
        }
        
        .sidebar-overlay {
            display: none !important;
        }
    }
    
    /* Améliorations pour les écrans moyens */
    @media (min-width: 769px) and (max-width: 992px) {
        .sidebar {
            width: 200px;
        }
        
        .main-content {
            margin-left: 200px;
        }
        
        .nav-link span {
            font-size: 13px;
        }
        
        .nav-link i {
            margin-right: 8px;
            font-size: 14px;
        }
    }
    
    /* KPI Cards */
    .kpi-card {
        border-left: 4px solid;
        padding: 15px;
        margin-bottom: 15px;
        height: 100%;
    }
    
    .kpi-global { border-color: var(--primary-color); }
    .kpi-success { border-color: var(--success-color); }
    .kpi-warning { border-color: var(--warning-color); }
    .kpi-danger { border-color: var(--accent-color); }
    
    /* Progress bars */
    .progress-thin {
        height: 8px;
        margin-top: 5px;
    }
    
    /* Chart containers */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    .chart-container-sm {
        height: 250px;
    }
    
    /* Dashboard grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    /* Rang badges */
    .rang-badge {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: white;
    }
    
    .rang-1 { background: gold; }
    .rang-2 { background: silver; }
    .rang-3 { background: #cd7f32; }
    .rang-other { background: var(--primary-color); }
    
    /* Table smaller */
    .table-smaller {
        font-size: 0.9rem;
    }
    
    /* Small text for mobile */
    .small-mobile {
        font-size: 0.85rem;
    }
    </style>
</head>
<body>
    <!-- Header Mobile -->
    <div class="mobile-header">
        <div class="mobile-header-content">
            <button class="hamburger-btn" id="hamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="mobile-brand">
                <i class="fas fa-chart-pie me-2"></i>
                STATISTIQUES
            </div>
            <div>
                <button class="btn btn-sm btn-light" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Overlay pour fermer le sidebar -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-user-shield"></i>
            </div>
            <h5 class="mt-2 mb-1">SURVEILLANT</h5>
            <div class="user-role">Surveillant Général</div>
        </div>
        
        <div class="user-info">
            <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Surveillant'); ?></p>
            <small>Statistiques Avancées</small>
        </div>
        
        <div class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Tableau de Bord</div>
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tableau de Bord</span>
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Gestion Présences</div>
                <a href="presences.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span>Toutes les Présences</span>
                </a>
                <a href="scanner_qr.php" class="nav-link">
                    <i class="fas fa-qrcode"></i>
                    <span>Scanner QR Code</span>
                </a>
                <a href="generer_qr.php" class="nav-link">
                    <i class="fas fa-barcode"></i>
                    <span>Générer QR Code</span>
                </a>
                <a href="absences.php" class="nav-link">
                    <i class="fas fa-user-times"></i>
                    <span>Absences</span>
                </a>
                <a href="retards.php" class="nav-link">
                    <i class="fas fa-clock"></i>
                    <span>Retards</span>
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Étudiants</div>
                <a href="etudiants.php" class="nav-link">
                    <i class="fas fa-user-graduate"></i>
                    <span>Liste Étudiants</span>
                </a>
                <a href="rechercher_etudiant.php" class="nav-link">
                    <i class="fas fa-search"></i>
                    <span>Rechercher</span>
                </a>
                <a href="classe_presence.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Par Classe</span>
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Salles & Horaires</div>
                <a href="salles.php" class="nav-link">
                    <i class="fas fa-door-open"></i>
                    <span>Salles de Classe</span>
                </a>
                <a href="emploi_du_temps.php" class="nav-link">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Emploi du Temps</span>
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Rapports</div>
                <a href="rapports_presence.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Rapports de Présence</span>
                </a>
                <a href="statistiques.php" class="nav-link active">
                    <i class="fas fa-chart-pie"></i>
                    <span>Statistiques</span>
                </a>
                <a href="export.php" class="nav-link">
                    <i class="fas fa-file-export"></i>
                    <span>Exporter</span>
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Configuration</div>
                <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()" style="margin-left: 20px; margin-right: 20px; text-align: left; padding: 8px 15px;">
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
    <div class="main-content" id="mainContent">
        <!-- En-tête -->
        <div class="content-header mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div class="mb-3 mb-md-0">
                    <h2 class="mb-1 h4">
                        <i class="fas fa-chart-pie me-2"></i>
                        Statistiques de Présence
                    </h2>
                    <p class="text-muted mb-0 small">
                        <i class="fas fa-building"></i> 
                        Site: <?php echo $_SESSION['site_name'] ?? 'Non spécifié'; ?> - 
                        Année: <?php echo $annee; ?>
                    </p>
                </div>
                <div class="btn-group w-100 w-md-auto">
                    <button class="btn btn-success btn-sm" onclick="exporterStatistiques()">
                        <i class="fas fa-file-export"></i> <span class="d-none d-md-inline">Exporter</span>
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="imprimerStatistiques()">
                        <i class="fas fa-print"></i> <span class="d-none d-md-inline">Imprimer</span>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> <span class="d-none d-md-inline">Actualiser</span>
                    </button>
                </div>
            </div>
        </div>
        
        <?php if(isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Section 1: Filtres -->
        <div class="filter-card">
            <h5 class="mb-3 h6">
                <i class="fas fa-filter me-2"></i>
                Filtres
            </h5>
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Année</label>
                    <select class="form-select form-select-sm" name="annee" onchange="this.form.submit()">
                        <?php for($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $annee == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-12 col-md-4">
                    <label class="form-label">Type de statistiques</label>
                    <select class="form-select form-select-sm" name="type_stat" onchange="this.form.submit()">
                        <option value="global" <?php echo $type_stat == 'global' ? 'selected' : ''; ?>>Globales</option>
                        <option value="classe" <?php echo $type_stat == 'classe' ? 'selected' : ''; ?>>Par classe</option>
                        <option value="temps" <?php echo $type_stat == 'temps' ? 'selected' : ''; ?>>Dans le temps</option>
                    </select>
                </div>
                
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-sync-alt me-1"></i> <span class="d-none d-sm-inline">Actualiser</span>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Section 2: Vue selon le type de statistiques -->
        <?php if($type_stat == 'global'): ?>
        
        <!-- Statistiques Globales -->
        <div class="dashboard-grid mb-4">
            <div class="card kpi-card kpi-global">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0 stat-value"><?php echo number_format($statistiques_globales['taux_presence'] ?? 0, 1); ?>%</h3>
                            <p class="text-muted mb-0 small">Taux de présence</p>
                        </div>
                        <div class="stat-icon text-primary">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress progress-thin">
                            <div class="progress-bar bg-success" 
                                 style="width: <?php echo $statistiques_globales['taux_presence'] ?? 0; ?>%">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card kpi-card kpi-success">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0 stat-value"><?php echo $statistiques_globales['total_presents'] ?? 0; ?></h3>
                            <p class="text-muted mb-0 small">Présences validées</p>
                        </div>
                        <div class="stat-icon text-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted small-mobile">
                            <?php echo $statistiques_globales['total_presences'] ?? 0; ?> enregistrements totaux
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="card kpi-card kpi-warning">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0 stat-value"><?php echo $statistiques_globales['total_retards'] ?? 0; ?></h3>
                            <p class="text-muted mb-0 small">Retards</p>
                        </div>
                        <div class="stat-icon text-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted small-mobile">
                            <?php echo $statistiques_globales['total_presences'] > 0 ? 
                                number_format(($statistiques_globales['total_retards'] / $statistiques_globales['total_presences']) * 100, 1) : 0; ?>%
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="card kpi-card kpi-danger">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0 stat-value"><?php echo $statistiques_globales['total_absents'] ?? 0; ?></h3>
                            <p class="text-muted mb-0 small">Absences</p>
                        </div>
                        <div class="stat-icon text-danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted small-mobile">
                            <?php echo $statistiques_globales['total_presences'] > 0 ? 
                                number_format(($statistiques_globales['total_absents'] / $statistiques_globales['total_presences']) * 100, 1) : 0; ?>%
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-calendar-alt me-2"></i>
                            Présences par Mois (<?php echo $annee; ?>)
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="chart-container">
                            <canvas id="moisChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-chart-bar me-2"></i>
                            Présences par Jour de la Semaine
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="chart-container">
                            <canvas id="joursChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-trophy me-2"></i>
                            Top 10 Meilleurs Étudiants
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table class="table table-smaller mb-0">
                                <thead>
                                    <tr>
                                        <th class="d-none d-sm-table-cell">Rang</th>
                                        <th>Étudiant</th>
                                        <th class="d-none d-md-table-cell">Classe</th>
                                        <th>Taux</th>
                                        <th class="d-none d-sm-table-cell">Présences</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rang = 1; ?>
                                    <?php foreach($meilleurs_etudiants as $etudiant): ?>
                                    <tr>
                                        <td class="d-none d-sm-table-cell">
                                            <div class="rang-badge <?php echo $rang <= 3 ? 'rang-' . $rang : 'rang-other'; ?>">
                                                <?php echo $rang; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="small"><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($etudiant['matricule']); ?></small>
                                        </td>
                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($etudiant['classe_nom'] ?? 'N/A'); ?></td>
                                        <td>
                                            <strong class="text-success small">
                                                <?php echo number_format($etudiant['taux_presence'], 1); ?>%
                                            </strong>
                                            <div class="progress progress-thin">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?php echo $etudiant['taux_presence']; ?>%">
                                                </div>
                                            </div>
                                        </td>
                                        <td class="d-none d-sm-table-cell">
                                            <small><?php echo $etudiant['presents']; ?> / <?php echo $etudiant['total_presences']; ?></small>
                                        </td>
                                    </tr>
                                    <?php $rang++; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Top 10 Élèves à Suivre
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table class="table table-smaller mb-0">
                                <thead>
                                    <tr>
                                        <th class="d-none d-sm-table-cell">Rang</th>
                                        <th>Étudiant</th>
                                        <th class="d-none d-md-table-cell">Classe</th>
                                        <th>Taux</th>
                                        <th class="d-none d-sm-table-cell">Absences</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rang = 1; ?>
                                    <?php foreach($pires_etudiants as $etudiant): ?>
                                    <tr>
                                        <td class="d-none d-sm-table-cell">
                                            <div class="rang-badge <?php echo $rang <= 3 ? 'rang-' . $rang : 'rang-other'; ?>">
                                                <?php echo $rang; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="small"><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($etudiant['matricule']); ?></small>
                                        </td>
                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($etudiant['classe_nom'] ?? 'N/A'); ?></td>
                                        <td>
                                            <strong class="text-danger small">
                                                <?php echo number_format($etudiant['taux_presence'], 1); ?>%
                                            </strong>
                                            <div class="progress progress-thin">
                                                <div class="progress-bar bg-danger" 
                                                     style="width: <?php echo $etudiant['taux_presence']; ?>%">
                                                </div>
                                            </div>
                                        </td>
                                        <td class="d-none d-sm-table-cell">
                                            <small><?php echo $etudiant['absents']; ?> / <?php echo $etudiant['total_presences']; ?></small>
                                        </td>
                                    </tr>
                                    <?php $rang++; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif($type_stat == 'classe'): ?>
        
        <!-- Statistiques par Classe -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0 h6">
                    <i class="fas fa-school me-2"></i>
                    Comparatif des Classes
                </h5>
            </div>
            <div class="card-body p-3">
                <div class="chart-container" style="height: 350px;">
                    <canvas id="classesChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0 h6">
                    <i class="fas fa-table me-2"></i>
                    Détails par Classe
                </h5>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Classe</th>
                                <th class="d-none d-sm-table-cell">Effectif</th>
                                <th class="d-none d-md-table-cell">Présences</th>
                                <th>Taux</th>
                                <th class="d-none d-lg-table-cell">Absences</th>
                                <th class="d-none d-xl-table-cell">Retards</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($statistiques_par_classe as $classe): ?>
                            <tr>
                                <td>
                                    <strong class="small"><?php echo htmlspecialchars($classe['classe_nom']); ?></strong>
                                </td>
                                <td class="d-none d-sm-table-cell">
                                    <small><?php echo $classe['etudiants_uniques']; ?> / 
                                    <?php echo $classe['effectif_total'] ?? '?'; ?></small>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <small><?php echo $classe['presents']; ?></small>
                                </td>
                                <td>
                                    <strong class="<?php echo $classe['taux_presence'] >= 80 ? 'text-success' : 
                                                   ($classe['taux_presence'] >= 60 ? 'text-warning' : 'text-danger'); ?> small">
                                        <?php echo number_format($classe['taux_presence'], 1); ?>%
                                    </strong>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar 
                                            <?php echo $classe['taux_presence'] >= 80 ? 'bg-success' : 
                                                   ($classe['taux_presence'] >= 60 ? 'bg-warning' : 'bg-danger'); ?>"
                                             style="width: <?php echo $classe['taux_presence']; ?>%">
                                        </div>
                                    </div>
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    <small>
                                        <?php echo $classe['absents']; ?>
                                        <span class="text-muted">
                                            (<?php echo number_format($classe['taux_absence'], 1); ?>%)
                                        </span>
                                    </small>
                                </td>
                                <td class="d-none d-xl-table-cell">
                                    <small>
                                        <?php echo $classe['retards']; ?>
                                        <span class="text-muted">
                                            (<?php echo number_format($classe['taux_retard'], 1); ?>%)
                                        </span>
                                    </small>
                                </td>
                                <td>
                                    <?php if($classe['taux_presence'] >= 90): ?>
                                    <span class="badge bg-success small">Excellente</span>
                                    <?php elseif($classe['taux_presence'] >= 80): ?>
                                    <span class="badge bg-info small">Bonne</span>
                                    <?php elseif($classe['taux_presence'] >= 70): ?>
                                    <span class="badge bg-warning small">Moyenne</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger small">Faible</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif($type_stat == 'temps'): ?>
        
        <!-- Statistiques dans le Temps -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0 h6">
                    <i class="fas fa-chart-line me-2"></i>
                    Évolution Mensuelle (<?php echo $annee; ?>)
                </h5>
            </div>
            <div class="card-body p-3">
                <div class="chart-container" style="height: 350px;">
                    <canvas id="evolutionChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0 h6">
                    <i class="fas fa-chart-area me-2"></i>
                    Tendances des Absences (30 derniers jours)
                </h5>
            </div>
            <div class="card-body p-3">
                <div class="chart-container" style="height: 250px;">
                    <canvas id="tendanceChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-calendar me-2"></i>
                            Détails Mensuels
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table class="table table-smaller mb-0">
                                <thead>
                                    <tr>
                                        <th>Mois</th>
                                        <th class="d-none d-sm-table-cell">Présences</th>
                                        <th>Taux</th>
                                        <th class="d-none d-md-table-cell">Étudiants</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($statistiques_par_mois as $mois): ?>
                                    <tr>
                                        <td>
                                            <strong class="small"><?php echo htmlspecialchars($mois['mois_nom']); ?></strong>
                                        </td>
                                        <td class="d-none d-sm-table-cell">
                                            <small><?php echo $mois['presents']; ?> / <?php echo $mois['total_presences']; ?></small>
                                        </td>
                                        <td>
                                            <strong class="<?php echo $mois['taux_presence'] >= 80 ? 'text-success' : 
                                                           ($mois['taux_presence'] >= 60 ? 'text-warning' : 'text-danger'); ?> small">
                                                <?php echo number_format($mois['taux_presence'], 1); ?>%
                                            </strong>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <small><?php echo $mois['etudiants_uniques']; ?></small>
                                        </td>
                                        <td>
                                            <?php if($mois['taux_presence'] >= 85): ?>
                                            <span class="badge bg-success small">Très bon</span>
                                            <?php elseif($mois['taux_presence'] >= 70): ?>
                                            <span class="badge bg-info small">Bon</span>
                                            <?php elseif($mois['taux_presence'] >= 50): ?>
                                            <span class="badge bg-warning small">Moyen</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger small">Faible</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header py-3">
                        <h5 class="mb-0 h6">
                            <i class="fas fa-calendar-day me-2"></i>
                            Performance par Jour
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table class="table table-smaller mb-0">
                                <thead>
                                    <tr>
                                        <th>Jour</th>
                                        <th class="d-none d-sm-table-cell">Présences</th>
                                        <th>Taux</th>
                                        <th class="d-none d-md-table-cell">Retards</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($statistiques_par_jour as $jour): ?>
                                    <tr>
                                        <td>
                                            <strong class="small"><?php echo htmlspecialchars($jour['jour_nom_fr']); ?></strong>
                                        </td>
                                        <td class="d-none d-sm-table-cell">
                                            <small><?php echo $jour['presents']; ?> / <?php echo $jour['total_presences']; ?></small>
                                        </td>
                                        <td>
                                            <strong class="<?php echo $jour['taux_presence'] >= 80 ? 'text-success' : 
                                                           ($jour['taux_presence'] >= 60 ? 'text-warning' : 'text-danger'); ?> small">
                                                <?php echo number_format($jour['taux_presence'], 1); ?>%
                                            </strong>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <small>
                                                <?php echo $jour['retards']; ?>
                                                <span class="text-muted">
                                                    (<?php echo $jour['total_presences'] > 0 ? 
                                                        number_format(($jour['retards'] / $jour['total_presences']) * 100, 1) : 0; ?>%)
                                                </span>
                                            </small>
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
        
        <!-- Footer Mobile -->
        <div class="d-block d-md-none mt-4 pt-3 border-top text-center">
            <small class="text-muted">
                <?php echo $_SESSION['user_name'] ?? 'Surveillant'; ?> - 
                <?php echo date('d/m/Y H:i'); ?>
            </small>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Gestion du sidebar mobile
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const mainContent = document.getElementById('mainContent');
    
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
        document.body.classList.toggle('sidebar-open');
    }
    
    function closeSidebar() {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
    
    // Événements
    hamburgerBtn.addEventListener('click', toggleSidebar);
    sidebarOverlay.addEventListener('click', closeSidebar);
    
    // Fermer le sidebar en cliquant sur un lien (mobile)
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 769) {
                closeSidebar();
            }
        });
    });
    
    // Ajuster le padding du main content pour le header mobile
    function adjustContentPadding() {
        if (window.innerWidth < 769) {
            mainContent.style.paddingTop = '80px';
        } else {
            mainContent.style.paddingTop = '20px';
        }
    }
    
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        // Mettre à jour le bouton
        const buttons = document.querySelectorAll('button[onclick="toggleTheme()"]');
        buttons.forEach(button => {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        });
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Ajuster le padding
        adjustContentPadding();
        window.addEventListener('resize', adjustContentPadding);
        
        // Thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButtons = document.querySelectorAll('button[onclick="toggleTheme()"]');
        themeButtons.forEach(button => {
            if (theme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        });
        
        // Initialiser les graphiques selon le type de statistiques
        const typeStat = '<?php echo $type_stat; ?>';
        
        if (typeStat === 'global') {
            initializeGlobalCharts();
        } else if (typeStat === 'classe') {
            initializeClasseCharts();
        } else if (typeStat === 'temps') {
            initializeTempsCharts();
        }
    });
    
    // Graphiques pour les statistiques globales
    function initializeGlobalCharts() {
        // Graphique par mois
        const moisCtx = document.getElementById('moisChart');
        if (moisCtx) {
            const mois = <?php echo json_encode(array_column($statistiques_par_mois, 'mois_nom')); ?>;
            const taux = <?php echo json_encode(array_column($statistiques_par_mois, 'taux_presence')); ?>;
            
            new Chart(moisCtx, {
                type: 'bar',
                data: {
                    labels: mois.reverse(),
                    datasets: [{
                        label: 'Taux de présence (%)',
                        data: taux.reverse(),
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Taux de présence (%)'
                            },
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique par jour
        const joursCtx = document.getElementById('joursChart');
        if (joursCtx) {
            const jours = <?php echo json_encode(array_column($statistiques_par_jour, 'jour_nom_fr')); ?>;
            const presences = <?php echo json_encode(array_column($statistiques_par_jour, 'total_presences')); ?>;
            
            new Chart(joursCtx, {
                type: 'line',
                data: {
                    labels: jours,
                    datasets: [{
                        label: 'Nombre de présences',
                        data: presences,
                        borderColor: '#27ae60',
                        backgroundColor: 'rgba(39, 174, 96, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre de présences'
                            },
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Graphiques pour les statistiques par classe
    function initializeClasseCharts() {
        const classesCtx = document.getElementById('classesChart');
        if (classesCtx) {
            const classes = <?php echo json_encode(array_column($statistiques_par_classe, 'classe_nom')); ?>;
            const taux = <?php echo json_encode(array_column($statistiques_par_classe, 'taux_presence')); ?>;
            
            new Chart(classesCtx, {
                type: 'bar',
                data: {
                    labels: classes,
                    datasets: [{
                        label: 'Taux de présence (%)',
                        data: taux,
                        backgroundColor: taux.map(t => t >= 80 ? '#27ae60' : (t >= 60 ? '#f39c12' : '#e74c3c')),
                        borderColor: taux.map(t => t >= 80 ? '#27ae60' : (t >= 60 ? '#f39c12' : '#e74c3c')),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Taux de présence (%)'
                            },
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        y: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    }
    
    // Graphiques pour les statistiques dans le temps
    function initializeTempsCharts() {
        // Graphique d'évolution mensuelle
        const evolutionCtx = document.getElementById('evolutionChart');
        if (evolutionCtx) {
            const mois = <?php echo json_encode(array_map(function($m) {
                return $m['mois_nom'] . ' ' . $m['annee'];
            }, $statistiques_par_mois)); ?>;
            const presents = <?php echo json_encode(array_column($statistiques_par_mois, 'presents')); ?>;
            const absents = <?php echo json_encode(array_column($statistiques_par_mois, 'absents')); ?>;
            
            new Chart(evolutionCtx, {
                type: 'line',
                data: {
                    labels: mois,
                    datasets: [
                        {
                            label: 'Présents',
                            data: presents,
                            borderColor: '#27ae60',
                            backgroundColor: 'rgba(39, 174, 96, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2
                        },
                        {
                            label: 'Absents',
                            data: absents,
                            borderColor: '#e74c3c',
                            backgroundColor: 'rgba(231, 76, 60, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'étudiants'
                            },
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique de tendance des absences
        const tendanceCtx = document.getElementById('tendanceChart');
        if (tendanceCtx) {
            const dates = <?php echo json_encode(array_column($tendance_absences, 'date_jour')); ?>;
            const absents = <?php echo json_encode(array_column($tendance_absences, 'absents')); ?>;
            
            const formattedDates = dates.map(date => {
                const d = new Date(date);
                return `${d.getDate()}/${d.getMonth() + 1}`;
            });
            
            new Chart(tendanceCtx, {
                type: 'line',
                data: {
                    labels: formattedDates,
                    datasets: [{
                        label: 'Absences',
                        data: absents,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'absences'
                            },
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                font: {
                                    size: window.innerWidth < 768 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Redimensionner les graphiques quand la fenêtre change
    window.addEventListener('resize', function() {
        const typeStat = '<?php echo $type_stat; ?>';
        
        if (typeStat === 'global') {
            initializeGlobalCharts();
        } else if (typeStat === 'classe') {
            initializeClasseCharts();
        } else if (typeStat === 'temps') {
            initializeTempsCharts();
        }
    });
    
    // Exporter les statistiques
    function exporterStatistiques() {
        const params = new URLSearchParams(window.location.search);
        window.open('ajax/exporter_statistiques.php?' + params.toString(), '_blank');
    }
    
    // Imprimer les statistiques
    function imprimerStatistiques() {
        window.print();
    }
    </script>
</body>
</html>