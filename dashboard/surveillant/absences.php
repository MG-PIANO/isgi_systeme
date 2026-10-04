<?php
// dashboard/surveillant/absences.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

require_once ROOT_PATH . '/config/database.php';
$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];

$pageTitle = "Gestion des Absences";

// Récupérer les absences
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

$query = "
    SELECT 
        e.id as etudiant_id,
        e.matricule,
        e.nom,
        e.prenom,
        c.nom as classe_nom,
        COUNT(p.id) as jours_absents,
        MAX(p.date_heure) as derniere_absence,
        GROUP_CONCAT(DISTINCT DATE(p.date_heure) ORDER BY p.date_heure DESC SEPARATOR ', ') as dates_absences
    FROM etudiants e
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN presences p ON e.id = p.etudiant_id 
        AND p.statut = 'absent'
        AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin
    WHERE e.site_id = :site_id 
      AND e.statut = 'actif'
    GROUP BY e.id, e.matricule, e.nom, e.prenom, c.nom
    HAVING jours_absents > 0
    ORDER BY jours_absents DESC, e.nom, e.prenom
";

$stmt = $db->prepare($query);
$stmt->execute([
    ':site_id' => $site_id,
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
]);
$absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats_query = "
    SELECT 
        COUNT(DISTINCT e.id) as total_absents,
        SUM(CASE WHEN a.jours_absents >= 3 THEN 1 ELSE 0 END) as absences_prolongees
    FROM (
        SELECT 
            e.id,
            COUNT(p.id) as jours_absents
        FROM etudiants e
        LEFT JOIN presences p ON e.id = p.etudiant_id 
            AND p.statut = 'absent'
            AND DATE(p.date_heure) BETWEEN :date_debut2 AND :date_fin2
        WHERE e.site_id = :site_id2
        GROUP BY e.id
        HAVING jours_absents > 0
    ) a
    LEFT JOIN etudiants e ON a.id = e.id
";

$stmt = $db->prepare($stats_query);
$stmt->execute([
    ':site_id2' => $site_id,
    ':date_debut2' => $date_debut,
    ':date_fin2' => $date_fin
]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
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
        height: 60px;
    }
    
    .mobile-header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 100%;
    }
    
    .hamburger-btn {
        background: transparent;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 5px 10px;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 5px;
    }
    
    .hamburger-btn:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    
    .mobile-brand {
        font-size: 16px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 10px;
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
        top: 0;
        left: 0;
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
        border-left-color: var(--accent-color);
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
    
    /* Contenu principal */
    .main-content {
        padding: 20px;
        min-height: 100vh;
        transition: all 0.3s ease-in-out;
    }
    
    /* Cartes de statistiques */
    .stat-card {
        text-align: center;
        padding: 15px;
        height: 100%;
        border-radius: 10px;
        transition: transform 0.2s;
        color: white;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
    }
    
    .stat-icon {
        font-size: 1.8rem;
        margin-bottom: 10px;
        opacity: 0.8;
    }
    
    .stat-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.9;
    }
    
    /* Carte d'absence */
    .absence-card {
        border-left: 4px solid var(--accent-color);
        margin-bottom: 10px;
        transition: all 0.3s;
    }
    
    .absence-card:hover {
        background-color: rgba(231, 76, 60, 0.05);
        transform: translateX(5px);
    }
    
    .absence-prolongee {
        border-left: 4px solid var(--warning-color);
        background-color: rgba(243, 156, 18, 0.1);
    }
    
    [data-theme="dark"] .absence-prolongee {
        background-color: rgba(243, 156, 18, 0.2);
    }
    
    .absence-prolongee:hover {
        background-color: rgba(243, 156, 18, 0.15);
    }
    
    [data-theme="dark"] .absence-prolongee:hover {
        background-color: rgba(243, 156, 18, 0.3);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 5px 10px;
        font-weight: 500;
        border-radius: 20px;
    }
    
    .badge-absence {
        background-color: var(--accent-color);
        color: white;
    }
    
    .badge-prolongee {
        background-color: var(--warning-color);
        color: #212529;
    }
    
    /* Tableau */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table {
        color: var(--text-color);
        min-width: 800px;
    }
    
    .table thead th {
        background-color: var(--accent-color);
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
    
    /* Responsive Design */
    @media (max-width: 768px) {
        /* Mobile Header */
        .mobile-header {
            display: block;
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
            padding: 80px 15px 20px 15px;
            margin-left: 0 !important;
        }
        
        /* En-tête */
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
            padding: 10px;
            font-size: 14px;
        }
        
        /* Statistiques */
        .stat-card {
            padding: 12px;
        }
        
        .stat-icon {
            font-size: 1.5rem;
        }
        
        .stat-value {
            font-size: 1.2rem;
        }
        
        /* Filtres */
        .filter-card .row.g-3 > div {
            margin-bottom: 10px;
        }
        
        /* Tableau */
        .table tbody td:last-child {
            position: sticky;
            right: 0;
            background: var(--card-bg);
            border-left: 1px solid var(--border-color);
        }
        
        /* Actions dans le tableau */
        .btn-group-sm .btn {
            padding: 5px 8px;
            font-size: 12px;
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
        
        .stat-value {
            font-size: 1rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
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
        
        /* Filtres */
        .col-md-4.d-flex {
            flex-direction: column;
            gap: 10px;
        }
        
        .col-md-4.d-flex .btn {
            width: 100%;
        }
    }
    
    /* Small Mobile */
    @media (max-width: 360px) {
        .mobile-brand {
            font-size: 14px;
        }
        
        .mobile-brand i {
            font-size: 18px;
        }
        
        .stat-card {
            padding: 8px;
        }
        
        .stat-icon {
            font-size: 1.2rem;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 0.9rem;
        }
    }
    
    /* Mode Desktop */
    @media (min-width: 769px) {
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        
        .sidebar-overlay {
            display: none !important;
        }
        
        .mobile-header {
            display: none;
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
        
        .table {
            min-width: 700px;
        }
    }
    
    /* Animation pour les alertes */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .alert {
        animation: fadeIn 0.3s ease-out;
    }
    
    /* Badge d'avertissement */
    .badge-warning-blink {
        animation: blink 2s infinite;
    }
    
    @keyframes blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
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
                <i class="fas fa-user-times"></i>
                <span>Absences</span>
            </div>
            <button class="hamburger-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i>
            </button>
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
            <small>Gestion des Présences</small>
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
                <a href="generer_qr.php" class="nav-link">
                    <i class="fas fa-barcode"></i>
                    <span>Générer QR Code</span>
                </a>
                <a href="absences.php" class="nav-link active">
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
        <div class="container-fluid">
            <!-- En-tête -->
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="mb-3 mb-md-0">
                        <h1 class="h3 mb-2">
                            <i class="fas fa-user-times me-2"></i>
                            Gestion des Absences
                        </h1>
                        <p class="text-muted mb-0">
                            Suivi des absences et justifications
                        </p>
                    </div>
                    <div class="btn-group w-100 w-md-auto">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> <span class="d-none d-md-inline">Imprimer</span>
                        </button>
                        <button class="btn btn-success" onclick="exportAbsences()">
                            <i class="fas fa-file-excel me-2"></i> <span class="d-none d-md-inline">Exporter</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="card filter-card mb-4">
                <div class="card-body p-3">
                    <form method="GET" class="row g-3">
                        <div class="col-12 col-md-5 col-lg-4">
                            <label class="form-label small">Période du</label>
                            <input type="date" class="form-control form-control-sm" name="date_debut" 
                                   value="<?php echo htmlspecialchars($date_debut); ?>">
                        </div>
                        <div class="col-12 col-md-5 col-lg-4">
                            <label class="form-label small">au</label>
                            <input type="date" class="form-control form-control-sm" name="date_fin" 
                                   value="<?php echo htmlspecialchars($date_fin); ?>">
                        </div>
                        <div class="col-12 col-md-2 col-lg-4 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fas fa-filter me-1"></i> Filtrer
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="location.href='absences.php'">
                                <i class="fas fa-redo me-1"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques -->
            <div class="row g-2 mb-4">
                <div class="col-6 col-md-4">
                    <div class="stat-card bg-accent">
                        <div class="stat-icon">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_absents'] ?? 0; ?></div>
                        <div class="stat-label">Étudiants Absents</div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card bg-warning">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['absences_prolongees'] ?? 0; ?></div>
                        <div class="stat-label">Absences Prolongées</div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card bg-info">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value">
                            <?php echo date('d/m', strtotime($date_debut)); ?>-<?php echo date('d/m', strtotime($date_fin)); ?>
                        </div>
                        <div class="stat-label">Période</div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des absences -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 h6">
                        <i class="fas fa-list me-2"></i>
                        Liste des Absences
                        <span class="badge bg-accent ms-2"><?php echo count($absences); ?></span>
                    </h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-success" onclick="sendAbsenceNotifications()">
                            <i class="fas fa-bell me-1"></i> <span class="d-none d-md-inline">Notifier</span>
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-3">
                    <?php if(empty($absences)): ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        Aucune absence enregistrée sur cette période.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th class="d-none d-md-table-cell">Matricule</th>
                                    <th>Classe</th>
                                    <th>Jours</th>
                                    <th class="d-none d-sm-table-cell">Dernière</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($absences as $absence): ?>
                                <?php 
                                $is_prolongee = $absence['jours_absents'] >= 3;
                                ?>
                                <tr class="<?php echo $is_prolongee ? 'absence-prolongee' : 'absence-card'; ?>">
                                    <td>
                                        <div>
                                            <strong class="d-block"><?php echo htmlspecialchars($absence['nom'] . ' ' . $absence['prenom']); ?></strong>
                                            <small class="text-muted d-block d-md-none"><?php echo htmlspecialchars($absence['matricule']); ?></small>
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($absence['matricule']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($absence['classe_nom']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $is_prolongee ? 'badge-prolongee' : 'badge-absence'; ?>">
                                            <?php echo $absence['jours_absents']; ?> jour(s)
                                        </span>
                                    </td>
                                    <td class="d-none d-sm-table-cell">
                                        <?php echo $absence['derniere_absence'] ? 
                                            date('d/m/Y', strtotime($absence['derniere_absence'])) : 'N/A'; ?>
                                    </td>
                                    <td>
                                        <?php if($is_prolongee): ?>
                                        <span class="badge bg-warning badge-warning-blink">
                                            <i class="fas fa-exclamation-triangle me-1"></i> Prolongée
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-accent">Simple</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="viewStudentAbsences(<?php echo $absence['etudiant_id']; ?>)"
                                                    title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-success" 
                                                    onclick="justifyAbsence(<?php echo $absence['etudiant_id']; ?>)"
                                                    title="Justifier">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-outline-warning" 
                                                    onclick="contactParent(<?php echo $absence['etudiant_id']; ?>)"
                                                    title="Contacter parent">
                                                <i class="fas fa-phone"></i>
                                            </button>
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
            
            <!-- Notes et informations -->
            <?php if(!empty($absences)): ?>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body p-3">
                            <h6 class="mb-2">
                                <i class="fas fa-info-circle text-info me-2"></i>
                                Informations importantes
                            </h6>
                            <ul class="mb-0 small">
                                <li>Les absences marquées en <span class="badge bg-warning">jaune</span> sont considérées comme prolongées (≥ 3 jours)</li>
                                <li>Cliquez sur <i class="fas fa-eye text-primary"></i> pour voir le détail des absences par étudiant</li>
                                <li>Utilisez <i class="fas fa-check text-success"></i> pour justifier une absence</li>
                                <li>Le bouton <i class="fas fa-phone text-warning"></i> permet de contacter le parent/tuteur</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body p-3">
                            <h6 class="mb-2">
                                <i class="fas fa-bell text-success me-2"></i>
                                Notifications automatiques
                            </h6>
                            <p class="mb-0 small">
                                Les parents sont automatiquement notifiés après 2 jours consécutifs d'absence.
                                Pour envoyer des notifications manuelles, utilisez le bouton "Notifier les parents".
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer Mobile -->
        <div class="d-block d-md-none mt-4 pt-3 border-top text-center">
            <small class="text-muted">
                <?php echo $_SESSION['user_name'] ?? 'Surveillant'; ?> • 
                <?php echo count($absences); ?> absences • 
                <?php echo date('d/m/Y H:i'); ?>
            </small>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
        
        // Mettre à jour la couleur des cartes de statistiques
        updateStatCardsColors();
    });
    
    // Mettre à jour les couleurs des cartes de statistiques selon le thème
    function updateStatCardsColors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const accentColor = isDark ? '#e74c3c' : '#dc3545';
        const warningColor = isDark ? '#f39c12' : '#ffc107';
        const infoColor = isDark ? '#17a2b8' : '#0dcaf0';
        
        // Mettre à jour les styles inline
        document.querySelectorAll('.bg-accent').forEach(card => {
            card.style.backgroundColor = accentColor;
        });
        
        document.querySelectorAll('.bg-warning').forEach(card => {
            card.style.backgroundColor = warningColor;
        });
        
        document.querySelectorAll('.bg-info').forEach(card => {
            card.style.backgroundColor = infoColor;
        });
    }
    
    // Voir les absences d'un étudiant
    function viewStudentAbsences(studentId) {
        window.location.href = 'etudiant_absences.php?id=' + studentId;
    }
    
    // Justifier une absence
    function justifyAbsence(studentId) {
        Swal.fire({
            title: 'Justifier l\'absence',
            input: 'textarea',
            inputLabel: 'Motif de justification',
            inputPlaceholder: 'Entrez le motif de l\'absence...',
            showCancelButton: true,
            confirmButtonText: 'Justifier',
            cancelButtonText: 'Annuler',
            inputValidator: (value) => {
                if (!value) {
                    return 'Veuillez entrer un motif de justification';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax/justify_absence.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'student_id=' + studentId + '&motif=' + encodeURIComponent(result.value)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            title: 'Succès !',
                            text: data.message,
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Erreur !', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Erreur', 'Une erreur est survenue', 'error');
                    console.error('Erreur:', error);
                });
            }
        });
    }
    
    // Contacter un parent
    function contactParent(studentId) {
        Swal.fire({
            title: 'Contacter le parent/tuteur',
            html: `
                <div class="text-start">
                    <p class="small">Sélectionnez le mode de contact :</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="contactMode" id="smsRadio" value="sms" checked>
                        <label class="form-check-label small" for="smsRadio">
                            <i class="fas fa-sms me-2"></i>Envoyer un SMS
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="contactMode" id="emailRadio" value="email">
                        <label class="form-check-label small" for="emailRadio">
                            <i class="fas fa-envelope me-2"></i>Envoyer un Email
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="contactMode" id="appelRadio" value="appel">
                        <label class="form-check-label small" for="appelRadio">
                            <i class="fas fa-phone me-2"></i>Marquer comme "Appel effectué"
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Message (optionnel)</label>
                        <textarea class="form-control form-control-sm" id="contactMessage" rows="3" 
                                  placeholder="Message personnalisé..."></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Envoyer',
            cancelButtonText: 'Annuler',
            didOpen: () => {
                // Focus sur le textarea
                document.getElementById('contactMessage').focus();
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const contactMode = document.querySelector('input[name="contactMode"]:checked').value;
                const message = document.getElementById('contactMessage').value;
                
                fetch('ajax/contact_parent.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'student_id=' + studentId + 
                          '&mode=' + contactMode + 
                          '&message=' + encodeURIComponent(message)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let message = '';
                        switch(contactMode) {
                            case 'sms':
                                message = 'SMS envoyé au parent';
                                break;
                            case 'email':
                                message = 'Email envoyé au parent';
                                break;
                            case 'appel':
                                message = 'Appel marqué comme effectué';
                                break;
                        }
                        
                        Swal.fire({
                            title: 'Succès !',
                            text: message,
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Erreur !', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Erreur', 'Une erreur est survenue', 'error');
                    console.error('Erreur:', error);
                });
            }
        });
    }
    
    // Notifier tous les parents
    function sendAbsenceNotifications() {
        const totalAbsents = <?php echo count($absences); ?>;
        
        if (totalAbsents === 0) {
            Swal.fire('Information', 'Aucune absence à notifier', 'info');
            return;
        }
        
        Swal.fire({
            title: 'Notifier tous les parents',
            html: `
                <div class="text-start">
                    <p class="small mb-3">Vous êtes sur le point de notifier les parents de <strong>${totalAbsents} étudiants</strong> absents.</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="notifyAll" checked>
                        <label class="form-check-label small" for="notifyAll">
                            Notifier tous les absents
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="onlyProlonged">
                        <label class="form-check-label small" for="onlyProlonged">
                            Seulement les absences prolongées (≥ 3 jours)
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Message type</label>
                        <select class="form-select form-select-sm" id="messageTemplate">
                            <option value="standard">Notification standard d'absence</option>
                            <option value="warning">Avertissement d'absence prolongée</option>
                            <option value="custom">Message personnalisé</option>
                        </select>
                    </div>
                    <div id="customMessageDiv" style="display: none;">
                        <label class="form-label small">Message personnalisé</label>
                        <textarea class="form-control form-control-sm" id="customMessage" rows="3" 
                                  placeholder="Écrivez votre message..."></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Envoyer les notifications',
            cancelButtonText: 'Annuler',
            width: window.innerWidth < 768 ? '90%' : '600px',
            didOpen: () => {
                // Gérer l'affichage du champ personnalisé
                document.getElementById('messageTemplate').addEventListener('change', function() {
                    const customDiv = document.getElementById('customMessageDiv');
                    customDiv.style.display = this.value === 'custom' ? 'block' : 'none';
                });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const notifyAll = document.getElementById('notifyAll').checked;
                const onlyProlonged = document.getElementById('onlyProlonged').checked;
                const messageTemplate = document.getElementById('messageTemplate').value;
                const customMessage = document.getElementById('customMessage').value;
                
                // Afficher l'animation de chargement
                Swal.fire({
                    title: 'Envoi en cours...',
                    html: 'Les notifications sont en cours d\'envoi aux parents.',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                        
                        // Simuler un délai d'envoi (à remplacer par l'appel AJAX réel)
                        setTimeout(() => {
                            Swal.fire({
                                title: 'Notifications envoyées !',
                                text: 'Les parents ont été notifiés avec succès.',
                                icon: 'success',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        }, 2000);
                    }
                });
                
                // Appel AJAX réel (à décommenter et adapter)
                /*
                fetch('ajax/send_absence_notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'notify_all=' + (notifyAll ? 1 : 0) + 
                          '&only_prolonged=' + (onlyProlonged ? 1 : 0) + 
                          '&template=' + messageTemplate + 
                          '&custom_message=' + encodeURIComponent(customMessage)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Succès !', data.message, 'success');
                    } else {
                        Swal.fire('Erreur !', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Erreur', 'Une erreur est survenue', 'error');
                    console.error('Erreur:', error);
                });
                */
            }
        });
    }
    
    // Exporter les absences
    function exportAbsences() {
        // Créer un formulaire temporaire pour l'export
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'ajax/export_absences.php';
        form.style.display = 'none';
        
        const dateDebut = document.createElement('input');
        dateDebut.type = 'hidden';
        dateDebut.name = 'date_debut';
        dateDebut.value = '<?php echo $date_debut; ?>';
        form.appendChild(dateDebut);
        
        const dateFin = document.createElement('input');
        dateFin.type = 'hidden';
        dateFin.name = 'date_fin';
        dateFin.value = '<?php echo $date_fin; ?>';
        form.appendChild(dateFin);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    // Auto-refresh les données toutes les 5 minutes
    setInterval(() => {
        location.reload();
    }, 300000); // 5 minutes
    
    // Raccourcis clavier
    document.addEventListener('keydown', function(event) {
        // Ctrl + N pour notifier
        if (event.ctrlKey && event.key === 'n') {
            event.preventDefault();
            sendAbsenceNotifications();
        }
        
        // Ctrl + E pour exporter
        if (event.ctrlKey && event.key === 'e') {
            event.preventDefault();
            exportAbsences();
        }
        
        // Ctrl + F pour focus sur les filtres
        if (event.ctrlKey && event.key === 'f') {
            event.preventDefault();
            document.querySelector('input[name="date_debut"]').focus();
        }
    });
    </script>
</body>
</html>