<?php
// dashboard/surveillant/rapports_presence.php

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
$pageTitle = "Surveillant Général - Rapports de Présence";

// Récupérer l'ID du site du surveillant
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

// Fonctions utilitaires
function formatDateFr($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date == '0000-00-00 00:00:00') return 'Non renseigné';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Date invalide';
    return date($format, $timestamp);
}

function getStatutBadge($statut) {
    switch ($statut) {
        case 'present':
            return '<span class="badge bg-success">Présent</span>';
        case 'absent':
            return '<span class="badge bg-danger">Absent</span>';
        case 'retard':
            return '<span class="badge bg-warning">En retard</span>';
        case 'justifie':
            return '<span class="badge bg-info">Justifié</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

// Récupérer les paramètres de filtrage
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'semaine';
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
$classe_id = isset($_GET['classe_id']) ? intval($_GET['classe_id']) : 0;
$statut = isset($_GET['statut']) ? $_GET['statut'] : '';
$type_presence = isset($_GET['type_presence']) ? $_GET['type_presence'] : '';

// Définir les dates par défaut selon la période
switch ($periode) {
    case 'aujourdhui':
        $date_debut = date('Y-m-d');
        $date_fin = date('Y-m-d');
        break;
    case 'semaine':
        $date_debut = date('Y-m-d', strtotime('monday this week'));
        $date_fin = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'mois':
        $date_debut = date('Y-m-01');
        $date_fin = date('Y-m-t');
        break;
    case 'semestre':
        // Premier semestre : octobre à février
        $mois = date('n');
        if ($mois >= 10 || $mois <= 2) {
            $date_debut = date('Y-10-01');
            $date_fin = date('Y-02-28');
            if ($mois <= 2) {
                $date_debut = date('Y-10-01', strtotime('-1 year'));
                $date_fin = date('Y-02-28');
            }
        } else {
            // Second semestre : mars à juillet
            $date_debut = date('Y-03-01');
            $date_fin = date('Y-07-31');
        }
        break;
}

// Initialiser les variables
$rapports = [];
$statistiques = [
    'total_presences' => 0,
    'total_presents' => 0,
    'total_absents' => 0,
    'total_retards' => 0,
    'total_justifies' => 0,
    'taux_presence' => 0
];
$presences_par_jour = [];
$presences_par_classe = [];
$presences_par_heure = [];
$classes = [];
$evolution_semaine = [];

try {
    // 1. Récupérer les classes du site
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

    // 2. Construire la requête pour les rapports
    $query = "SELECT 
                p.*,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                m.nom as matiere_nom,
                CONCAT(u.nom, ' ', u.prenom) as surveillant_nom
              FROM presences p
              LEFT JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN matieres m ON p.matiere_id = m.id
              LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
              WHERE p.site_id = :site_id";
    
    $params = [':site_id' => $site_id];
    
    // Ajouter les filtres
    if (!empty($date_debut) && !empty($date_fin)) {
        $query .= " AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin";
        $params[':date_debut'] = $date_debut;
        $params[':date_fin'] = $date_fin;
    }
    
    if ($classe_id > 0) {
        $query .= " AND e.classe_id = :classe_id";
        $params[':classe_id'] = $classe_id;
    }
    
    if (!empty($statut)) {
        $query .= " AND p.statut = :statut";
        $params[':statut'] = $statut;
    }
    
    if (!empty($type_presence)) {
        $query .= " AND p.type_presence = :type_presence";
        $params[':type_presence'] = $type_presence;
    }
    
    $query .= " ORDER BY p.date_heure DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rapports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Calculer les statistiques
    if (!empty($rapports)) {
        $statistiques['total_presences'] = count($rapports);
        
        foreach ($rapports as $rapport) {
            switch ($rapport['statut']) {
                case 'present':
                    $statistiques['total_presents']++;
                    break;
                case 'absent':
                    $statistiques['total_absents']++;
                    break;
                case 'retard':
                    $statistiques['total_retards']++;
                    break;
                case 'justifie':
                    $statistiques['total_justifies']++;
                    break;
            }
        }
        
        if ($statistiques['total_presences'] > 0) {
            $statistiques['taux_presence'] = ($statistiques['total_presents'] / $statistiques['total_presences']) * 100;
        }
    }
    
    // 4. Statistiques par jour (7 derniers jours)
    $date_7jours = date('Y-m-d', strtotime('-7 days'));
    $query = "SELECT 
                DATE(date_heure) as date_jour,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) >= :date_7jours
              GROUP BY DATE(date_heure)
              ORDER BY date_jour ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':date_7jours' => $date_7jours]);
    $presences_par_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Statistiques par classe
    $query = "SELECT 
                c.nom as classe_nom,
                COUNT(p.id) as total_presences,
                SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
                COUNT(DISTINCT p.etudiant_id) as etudiants_uniques
              FROM presences p
              LEFT JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE p.site_id = :site_id";
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $query .= " AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin";
    }
    
    $query .= " GROUP BY c.id
                HAVING total_presences > 0
                ORDER BY total_presences DESC
                LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt_params = [':site_id' => $site_id];
    if (!empty($date_debut) && !empty($date_fin)) {
        $stmt_params[':date_debut'] = $date_debut;
        $stmt_params[':date_fin'] = $date_fin;
    }
    $stmt->execute($stmt_params);
    $presences_par_classe = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Statistiques par heure de la journée
    $query = "SELECT 
                HOUR(date_heure) as heure,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN type_presence = 'entree_ecole' THEN 1 ELSE 0 END) as entrees,
                SUM(CASE WHEN type_presence = 'sortie_ecole' THEN 1 ELSE 0 END) as sorties
              FROM presences 
              WHERE site_id = :site_id";
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $query .= " AND DATE(date_heure) BETWEEN :date_debut AND :date_fin";
    }
    
    $query .= " GROUP BY HOUR(date_heure)
                ORDER BY heure";
    
    $stmt = $db->prepare($query);
    $stmt->execute($stmt_params);
    $presences_par_heure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Évolution sur la semaine
    $debut_semaine = date('Y-m-d', strtotime('monday this week'));
    $fin_semaine = date('Y-m-d', strtotime('sunday this week'));
    
    $query = "SELECT 
                DATE(date_heure) as date_jour,
                DAYNAME(date_heure) as jour_nom,
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) BETWEEN :debut_semaine AND :fin_semaine
              GROUP BY DATE(date_heure)
              ORDER BY date_jour";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':debut_semaine' => $debut_semaine,
        ':fin_semaine' => $fin_semaine
    ]);
    $evolution_semaine = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    
    <!-- DataTables -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
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
        background: var(--info-color);
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
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
    
    /* Boutons */
    .btn-export {
        background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
        color: white;
        border: none;
    }
    
    .btn-export:hover {
        background: linear-gradient(135deg, #219653 0%, #1e8749 100%);
        color: white;
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    /* KPI Cards */
    .kpi-card {
        border-left: 4px solid;
        padding: 15px;
    }
    
    .kpi-present { border-color: var(--success-color); }
    .kpi-absent { border-color: var(--accent-color); }
    .kpi-retard { border-color: var(--warning-color); }
    .kpi-justifie { border-color: var(--info-color); }
    
    /* DataTable personnalisation */
    .dataTables_wrapper {
        color: var(--text-color);
    }
    
    .dataTables_filter input {
        background-color: var(--card-bg);
        color: var(--text-color);
        border: 1px solid var(--border-color);
    }
    
    .dataTables_length select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border: 1px solid var(--border-color);
    }
    
    .dataTables_paginate .paginate_button {
        background-color: var(--card-bg) !important;
        color: var(--text-color) !important;
        border: 1px solid var(--border-color) !important;
    }
    
    .dataTables_paginate .paginate_button:hover {
        background-color: var(--primary-color) !important;
        color: white !important;
    }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h5 class="mt-2 mb-1">SURVEILLANT</h5>
                <div class="user-role">Surveillant Général</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Surveillant'); ?></p>
                <small>Rapports de Présence</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
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
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Rapports</div>
                    <a href="rapports_presence.php" class="nav-link active">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports de Présence</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques</span>
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
                            <i class="fas fa-chart-bar me-2"></i>
                            Rapports de Présence
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-building"></i> 
                            Site: <?php echo $_SESSION['site_name'] ?? 'Non spécifié'; ?> - 
                            Période: <?php echo $date_debut ? date('d/m/Y', strtotime($date_debut)) . ' au ' . date('d/m/Y', strtotime($date_fin)) : 'Non spécifiée'; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-export" onclick="exporterRapport()">
                            <i class="fas fa-file-export"></i> Exporter
                        </button>
                        <button class="btn btn-primary" onclick="imprimerRapport()">
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
            
            <!-- Section 1: Filtres -->
            <div class="filter-card">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>
                    Filtres
                </h5>
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Période</label>
                        <select class="form-select" name="periode" onchange="this.form.submit()">
                            <option value="aujourdhui" <?php echo $periode == 'aujourdhui' ? 'selected' : ''; ?>>Aujourd'hui</option>
                            <option value="semaine" <?php echo $periode == 'semaine' ? 'selected' : ''; ?>>Cette semaine</option>
                            <option value="mois" <?php echo $periode == 'mois' ? 'selected' : ''; ?>>Ce mois</option>
                            <option value="semestre" <?php echo $periode == 'semestre' ? 'selected' : ''; ?>>Ce semestre</option>
                            <option value="personnalise" <?php echo $periode == 'personnalise' ? 'selected' : ''; ?>>Personnalisée</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Classe</label>
                        <select class="form-select" name="classe_id">
                            <option value="">Toutes les classes</option>
                            <?php foreach($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Statut</label>
                        <select class="form-select" name="statut">
                            <option value="">Tous statuts</option>
                            <option value="present" <?php echo $statut == 'present' ? 'selected' : ''; ?>>Présent</option>
                            <option value="absent" <?php echo $statut == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="retard" <?php echo $statut == 'retard' ? 'selected' : ''; ?>>Retard</option>
                            <option value="justifie" <?php echo $statut == 'justifie' ? 'selected' : ''; ?>>Justifié</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type_presence">
                            <option value="">Tous types</option>
                            <option value="entree_ecole" <?php echo $type_presence == 'entree_ecole' ? 'selected' : ''; ?>>Entrée école</option>
                            <option value="sortie_ecole" <?php echo $type_presence == 'sortie_ecole' ? 'selected' : ''; ?>>Sortie école</option>
                            <option value="entree_classe" <?php echo $type_presence == 'entree_classe' ? 'selected' : ''; ?>>Entrée classe</option>
                            <option value="sortie_classe" <?php echo $type_presence == 'sortie_classe' ? 'selected' : ''; ?>>Sortie classe</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Appliquer
                        </button>
                        <a href="rapports_presence.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Section 2: Statistiques Principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card kpi-card kpi-present">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $statistiques['total_presents']; ?></h3>
                                    <p class="text-muted mb-0">Présents</p>
                                </div>
                                <div class="stat-icon text-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <?php echo $statistiques['total_presences'] > 0 ? 
                                        number_format(($statistiques['total_presents'] / $statistiques['total_presences']) * 100, 1) : 0; ?>%
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card kpi-card kpi-absent">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $statistiques['total_absents']; ?></h3>
                                    <p class="text-muted mb-0">Absents</p>
                                </div>
                                <div class="stat-icon text-danger">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <?php echo $statistiques['total_presences'] > 0 ? 
                                        number_format(($statistiques['total_absents'] / $statistiques['total_presences']) * 100, 1) : 0; ?>%
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card kpi-card kpi-retard">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $statistiques['total_retards']; ?></h3>
                                    <p class="text-muted mb-0">Retards</p>
                                </div>
                                <div class="stat-icon text-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <?php echo $statistiques['total_presences'] > 0 ? 
                                        number_format(($statistiques['total_retards'] / $statistiques['total_presences']) * 100, 1) : 0; ?>%
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card kpi-card kpi-justifie">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?php echo $statistiques['total_justifies']; ?></h3>
                                    <p class="text-muted mb-0">Justifiés</p>
                                </div>
                                <div class="stat-icon text-info">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <?php echo $statistiques['total_presences'] > 0 ? 
                                        number_format(($statistiques['total_justifies'] / $statistiques['total_presences']) * 100, 1) : 0; ?>%
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Graphiques -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Évolution des Présences (7 derniers jours)
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="evolutionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Répartition par Statut
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statutChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Top Classes -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-trophy me-2"></i>
                                Top 10 des Classes par Présences
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="classesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2"></i>
                                Présences par Heure de la Journée
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="heureChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Tableau des Rapports -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>
                        Détails des Présences
                        <span class="badge bg-primary ms-2">
                            <?php echo count($rapports); ?> enregistrements
                        </span>
                    </h5>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="exporterExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="exporterPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($rapports)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Aucune présence enregistrée avec les filtres actuels.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="rapportsTable">
                            <thead>
                                <tr>
                                    <th>Date/Heure</th>
                                    <th>Étudiant</th>
                                    <th>Matricule</th>
                                    <th>Classe</th>
                                    <th>Type</th>
                                    <th>Statut</th>
                                    <th>Matière</th>
                                    <th>Surveillant</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rapports as $rapport): ?>
                                <tr>
                                    <td><?php echo formatDateFr($rapport['date_heure']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($rapport['nom'] . ' ' . $rapport['prenom']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($rapport['matricule']); ?></td>
                                    <td><?php echo htmlspecialchars($rapport['classe_nom'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $type_badge = '';
                                        switch($rapport['type_presence']) {
                                            case 'entree_ecole':
                                                $type_badge = '<span class="badge bg-primary">Entrée École</span>';
                                                break;
                                            case 'sortie_ecole':
                                                $type_badge = '<span class="badge bg-secondary">Sortie École</span>';
                                                break;
                                            case 'entree_classe':
                                                $type_badge = '<span class="badge bg-info">Entrée Classe</span>';
                                                break;
                                            case 'sortie_classe':
                                                $type_badge = '<span class="badge bg-warning">Sortie Classe</span>';
                                                break;
                                            default:
                                                $type_badge = '<span class="badge bg-secondary">' . htmlspecialchars($rapport['type_presence']) . '</span>';
                                        }
                                        echo $type_badge;
                                        ?>
                                    </td>
                                    <td><?php echo getStatutBadge($rapport['statut']); ?></td>
                                    <td><?php echo htmlspecialchars($rapport['matiere_nom'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($rapport['surveillant_nom'] ?? 'Auto'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="voirDetailPresence(<?php echo $rapport['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" 
                                                onclick="modifierPresence(<?php echo $rapport['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
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
    
    <!-- Modal pour détails de présence -->
    <div class="modal fade" id="presenceDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de la Présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="presenceDetailContent">
                    <!-- Contenu chargé dynamiquement -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
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
        
        // Initialiser les graphiques
        initializeCharts();
        
        // Initialiser DataTable
        initializeDataTable();
    });
    
    // Initialiser les graphiques
    function initializeCharts() {
        // Graphique d'évolution sur 7 jours
        const evolutionCtx = document.getElementById('evolutionChart');
        if (evolutionCtx) {
            const dates = <?php echo json_encode(array_column($presences_par_jour, 'date_jour')); ?>;
            const presents = <?php echo json_encode(array_column($presences_par_jour, 'presents')); ?>;
            const absents = <?php echo json_encode(array_column($presences_par_jour, 'absents')); ?>;
            const retards = <?php echo json_encode(array_column($presences_par_jour, 'retards')); ?>;
            
            const formattedDates = dates.map(date => {
                const d = new Date(date);
                return `${d.getDate()}/${d.getMonth() + 1}`;
            });
            
            new Chart(evolutionCtx, {
                type: 'line',
                data: {
                    labels: formattedDates,
                    datasets: [
                        {
                            label: 'Présents',
                            data: presents,
                            borderColor: '#27ae60',
                            backgroundColor: 'rgba(39, 174, 96, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Absents',
                            data: absents,
                            borderColor: '#e74c3c',
                            backgroundColor: 'rgba(231, 76, 60, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Retards',
                            data: retards,
                            borderColor: '#f39c12',
                            backgroundColor: 'rgba(243, 156, 18, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'étudiants'
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique de répartition par statut
        const statutCtx = document.getElementById('statutChart');
        if (statutCtx) {
            const data = [
                <?php echo $statistiques['total_presents']; ?>,
                <?php echo $statistiques['total_absents']; ?>,
                <?php echo $statistiques['total_retards']; ?>,
                <?php echo $statistiques['total_justifies']; ?>
            ];
            
            new Chart(statutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Présents', 'Absents', 'Retards', 'Justifiés'],
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            '#27ae60',
                            '#e74c3c',
                            '#f39c12',
                            '#3498db'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const value = context.raw;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${context.label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique des classes
        const classesCtx = document.getElementById('classesChart');
        if (classesCtx && <?php echo !empty($presences_par_classe) ? 'true' : 'false'; ?>) {
            const classes = <?php echo json_encode(array_column($presences_par_classe, 'classe_nom')); ?>;
            const presences = <?php echo json_encode(array_column($presences_par_classe, 'total_presences')); ?>;
            
            new Chart(classesCtx, {
                type: 'bar',
                data: {
                    labels: classes,
                    datasets: [{
                        label: 'Nombre de présences',
                        data: presences,
                        backgroundColor: 'rgba(52, 152, 219, 0.8)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre de présences'
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique par heure
        const heureCtx = document.getElementById('heureChart');
        if (heureCtx && <?php echo !empty($presences_par_heure) ? 'true' : 'false'; ?>) {
            const heures = <?php echo json_encode(array_map(function($h) {
                return $h['heure'] . 'h';
            }, $presences_par_heure)); ?>;
            const entrees = <?php echo json_encode(array_column($presences_par_heure, 'entrees')); ?>;
            const sorties = <?php echo json_encode(array_column($presences_par_heure, 'sorties')); ?>;
            
            new Chart(heureCtx, {
                type: 'line',
                data: {
                    labels: heures,
                    datasets: [
                        {
                            label: 'Entrées',
                            data: entrees,
                            borderColor: '#27ae60',
                            backgroundColor: 'rgba(39, 174, 96, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Sorties',
                            data: sorties,
                            borderColor: '#e74c3c',
                            backgroundColor: 'rgba(231, 76, 60, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'étudiants'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Heure de la journée'
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Initialiser DataTable
    function initializeDataTable() {
        const table = document.getElementById('rapportsTable');
        if (table) {
            $(table).DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
                },
                pageLength: 25,
                order: [[0, 'desc']],
                dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
                columnDefs: [
                    { orderable: false, targets: [8] }
                ]
            });
        }
    }
    
    // Voir les détails d'une présence
    function voirDetailPresence(presenceId) {
        fetch('ajax/get_presence_detail.php?id=' + presenceId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('presenceDetailContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('presenceDetailModal'));
                modal.show();
            })
            .catch(error => {
                alert('Erreur de chargement des détails');
                console.error('Erreur:', error);
            });
    }
    
    // Modifier une présence
    function modifierPresence(presenceId) {
        if (confirm('Voulez-vous modifier cette présence ?')) {
            const nouveauStatut = prompt('Nouveau statut (present/absent/retard/justifie):', 'present');
            if (nouveauStatut && ['present', 'absent', 'retard', 'justifie'].includes(nouveauStatut)) {
                fetch('ajax/modifier_presence.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${presenceId}&statut=${nouveauStatut}&modifie_par=<?php echo $surveillant_id; ?>`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Présence modifiée avec succès !');
                        location.reload();
                    } else {
                        alert('Erreur: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Erreur de connexion');
                    console.error('Erreur:', error);
                });
            }
        }
    }
    
    // Exporter le rapport
    function exporterRapport() {
        const params = new URLSearchParams(window.location.search);
        window.open('ajax/exporter_rapport.php?' + params.toString(), '_blank');
    }
    
    // Imprimer le rapport
    function imprimerRapport() {
        window.print();
    }
    
    // Exporter en Excel
    function exporterExcel() {
        const params = new URLSearchParams(window.location.search);
        params.append('format', 'excel');
        window.location.href = 'ajax/exporter_rapport.php?' + params.toString();
    }
    
    // Exporter en PDF
    function exporterPDF() {
        const params = new URLSearchParams(window.location.search);
        params.append('format', 'pdf');
        window.open('ajax/exporter_rapport.php?' + params.toString(), '_blank');
    }
    </script>
</body>
</html>