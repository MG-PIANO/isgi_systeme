<?php
// dashboard/surveillant/presences.php

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
$surveillant_id = $_SESSION['user_id'];

$pageTitle = "Toutes les Présences";

// Fonctions utilitaires
function formatDateFr($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date == '0000-00-00 00:00:00') return 'N/A';
    return date($format, strtotime($date));
}

function getStatutBadge($statut) {
    $badges = [
        'present' => 'success',
        'absent' => 'danger',
        'retard' => 'warning',
        'justifie' => 'info',
        'en_attente' => 'secondary'
    ];
    $text = ucfirst($statut);
    $color = $badges[$statut] ?? 'secondary';
    return "<span class='badge bg-$color'>$text</span>";
}

function getTypePresenceBadge($type) {
    $badges = [
        'entree_ecole' => ['Entrée École', 'primary'],
        'sortie_ecole' => ['Sortie École', 'secondary'],
        'entree_classe' => ['Entrée Classe', 'info'],
        'sortie_classe' => ['Sortie Classe', 'warning']
    ];
    list($text, $color) = $badges[$type] ?? ['Autre', 'dark'];
    return "<span class='badge bg-$color'>$text</span>";
}

// Récupérer les filtres
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$classe_id = $_GET['classe_id'] ?? '';
$type_presence = $_GET['type_presence'] ?? '';
$statut = $_GET['statut'] ?? '';
$search = $_GET['search'] ?? '';

// Construire la requête avec filtres
$params = [':site_id' => $site_id];
$where = "WHERE p.site_id = :site_id";
$join = "LEFT JOIN";

if (!empty($date_debut) && !empty($date_fin)) {
    $where .= " AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin";
    $params[':date_debut'] = $date_debut;
    $params[':date_fin'] = $date_fin;
}

if (!empty($classe_id)) {
    $where .= " AND e.classe_id = :classe_id";
    $params[':classe_id'] = $classe_id;
}

if (!empty($type_presence)) {
    $where .= " AND p.type_presence = :type_presence";
    $params[':type_presence'] = $type_presence;
}

if (!empty($statut)) {
    $where .= " AND p.statut = :statut";
    $params[':statut'] = $statut;
}

if (!empty($search)) {
    $where .= " AND (e.matricule LIKE :search OR e.nom LIKE :search OR e.prenom LIKE :search)";
    $params[':search'] = "%$search%";
}

// Récupérer les classes pour le filtre
$query_classes = "SELECT id, nom FROM classes WHERE site_id = :site_id ORDER BY nom";
$stmt_classes = $db->prepare($query_classes);
$stmt_classes->execute([':site_id' => $site_id]);
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les présences avec pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Compter le total
$count_query = "SELECT COUNT(*) as total FROM presences p $join etudiants e ON p.etudiant_id = e.id $where";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Récupérer les données
$query = "
    SELECT 
        p.*,
        e.matricule,
        e.nom,
        e.prenom,
        c.nom as classe_nom,
        m.nom as matiere_nom,
        u.nom as surveillant_nom,
        u.prenom as surveillant_prenom
    FROM presences p
    LEFT JOIN etudiants e ON p.etudiant_id = e.id
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN matieres m ON p.matiere_id = m.id
    LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
    $where
    ORDER BY p.date_heure DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
$params[':limit'] = $limit;
$params[':offset'] = $offset;
$stmt->execute($params);
$presences = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
        SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
        SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as retards,
        SUM(CASE WHEN p.statut = 'justifie' THEN 1 ELSE 0 END) as justifies
    FROM presences p
    $join etudiants e ON p.etudiant_id = e.id
    $where
";
$stmt_stats = $db->prepare($stats_query);
unset($params[':limit']);
unset($params[':offset']);
$stmt_stats->execute($params);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
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
    
    <!-- Flatpickr pour les dates -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
    
    /* Contenu principal */
    .main-content {
        padding: 20px;
        min-height: 100vh;
        transition: all 0.3s ease-in-out;
    }
    
    /* Statistiques */
    .stat-card {
        text-align: center;
        padding: 15px;
        height: 100%;
        border-radius: 10px;
        transition: transform 0.2s;
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
        color: var(--text-muted);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        margin-bottom: 20px;
    }
    
    /* Tableau */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table {
        color: var(--text-color);
        min-width: 1000px;
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 12px 15px;
        font-size: 14px;
        white-space: nowrap;
        position: sticky;
        top: 0;
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
        padding: 5px 10px;
        font-weight: 500;
        border-radius: 20px;
    }
    
    /* Pagination */
    .pagination .page-link {
        color: var(--primary-color);
        border-color: var(--border-color);
    }
    
    .pagination .page-item.active .page-link {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }
    
    /* Alertes */
    .alert {
        border-radius: 10px;
        border: none;
        border-left: 4px solid;
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
        
        /* Pagination */
        .pagination {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .page-item {
            margin-bottom: 5px;
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
        .col-md-8.d-flex {
            flex-direction: column;
            gap: 10px;
        }
        
        .col-md-8.d-flex .btn {
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
            min-width: 800px;
        }
    }
    
    /* Animation pour les modals */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .modal-content {
        animation: fadeIn 0.3s ease-out;
    }
    
    /* Custom Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: var(--bg-color);
    }
    
    ::-webkit-scrollbar-thumb {
        background: var(--border-color);
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
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
                <i class="fas fa-calendar-check"></i>
                <span>Présences</span>
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
                <a href="presences.php" class="nav-link active">
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
                            <i class="fas fa-calendar-check me-2"></i>
                            Toutes les Présences
                        </h1>
                        <p class="text-muted mb-0">
                            Gestion complète des présences des étudiants
                        </p>
                    </div>
                    <div class="btn-group w-100 w-md-auto">
                        <button class="btn btn-success" onclick="window.location.href='scanner_qr.php'">
                            <i class="fas fa-qrcode me-2"></i> <span class="d-none d-md-inline">Scanner QR</span>
                        </button>
                        <button class="btn btn-primary" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-2"></i> <span class="d-none d-md-inline">Exporter</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="card filter-card mb-4">
                <div class="card-body p-3">
                    <form id="filterForm" method="GET" class="row g-3">
                        <div class="col-12 col-md-4 col-lg-3">
                            <label class="form-label small">Date début</label>
                            <input type="date" class="form-control form-control-sm" name="date_debut" 
                                   value="<?php echo htmlspecialchars($date_debut); ?>">
                        </div>
                        <div class="col-12 col-md-4 col-lg-3">
                            <label class="form-label small">Date fin</label>
                            <input type="date" class="form-control form-control-sm" name="date_fin" 
                                   value="<?php echo htmlspecialchars($date_fin); ?>">
                        </div>
                        <div class="col-12 col-md-4 col-lg-2">
                            <label class="form-label small">Classe</label>
                            <select class="form-select form-select-sm" name="classe_id">
                                <option value="">Toutes</option>
                                <?php foreach($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>" 
                                    <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($classe['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label small">Type</label>
                            <select class="form-select form-select-sm" name="type_presence">
                                <option value="">Tous</option>
                                <option value="entree_ecole" <?php echo $type_presence == 'entree_ecole' ? 'selected' : ''; ?>>Entrée École</option>
                                <option value="sortie_ecole" <?php echo $type_presence == 'sortie_ecole' ? 'selected' : ''; ?>>Sortie École</option>
                                <option value="entree_classe" <?php echo $type_presence == 'entree_classe' ? 'selected' : ''; ?>>Entrée Classe</option>
                                <option value="sortie_classe" <?php echo $type_presence == 'sortie_classe' ? 'selected' : ''; ?>>Sortie Classe</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label small">Statut</label>
                            <select class="form-select form-select-sm" name="statut">
                                <option value="">Tous</option>
                                <option value="present" <?php echo $statut == 'present' ? 'selected' : ''; ?>>Présent</option>
                                <option value="absent" <?php echo $statut == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="retard" <?php echo $statut == 'retard' ? 'selected' : ''; ?>>Retard</option>
                                <option value="justifie" <?php echo $statut == 'justifie' ? 'selected' : ''; ?>>Justifié</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-8 col-lg-4">
                            <label class="form-label small">Recherche</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Matricule, nom..." value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-md-4 col-lg-8 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-filter me-1"></i> Filtrer
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="resetFilters()">
                                <i class="fas fa-redo me-1"></i> Réinitialiser
                            </button>
                            <button type="button" class="btn btn-info btn-sm" onclick="showStatsModal()">
                                <i class="fas fa-chart-bar me-1"></i> Stats
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques -->
            <div class="row g-2 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card bg-primary text-white">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card bg-success text-white">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['presents'] ?? 0); ?></div>
                        <div class="stat-label">Présents</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card bg-danger text-white">
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['absents'] ?? 0); ?></div>
                        <div class="stat-label">Absents</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card bg-warning text-white">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['retards'] ?? 0); ?></div>
                        <div class="stat-label">Retards</div>
                    </div>
                </div>
            </div>
            
            <!-- Tableau des présences -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 h6">
                        <i class="fas fa-list me-2"></i>
                        Liste des Présences <span class="badge bg-secondary"><?php echo number_format($total_records); ?></span>
                    </h5>
                    <div class="d-flex gap-2">
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <i class="fas fa-cog"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel text-success me-2"></i>Excel
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="exportToPDF()">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>PDF
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" onclick="printTable()">
                                    <i class="fas fa-print me-2"></i>Imprimer
                                </a></li>
                            </ul>
                        </div>
                        <button class="btn btn-sm btn-outline-info" onclick="refreshData()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-3">
                    <?php if(empty($presences)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Aucune présence trouvée avec les filtres sélectionnés.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="presencesTable">
                            <thead>
                                <tr>
                                    <th class="d-none d-sm-table-cell">#</th>
                                    <th>Étudiant</th>
                                    <th class="d-none d-md-table-cell">Matricule</th>
                                    <th class="d-none d-sm-table-cell">Classe</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($presences as $index => $presence): ?>
                                <tr>
                                    <td class="d-none d-sm-table-cell"><?php echo ($page - 1) * $limit + $index + 1; ?></td>
                                    <td>
                                        <div>
                                            <strong class="d-block"><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></strong>
                                            <small class="text-muted d-block d-md-none"><?php echo htmlspecialchars($presence['matricule']); ?></small>
                                            <small class="text-muted d-block d-sm-none"><?php echo htmlspecialchars($presence['classe_nom'] ?? 'N/A'); ?></small>
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($presence['matricule']); ?></span>
                                    </td>
                                    <td class="d-none d-sm-table-cell"><?php echo htmlspecialchars($presence['classe_nom'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $type = $presence['type_presence'];
                                        $badgeClass = $type === 'entree_ecole' ? 'primary' : 
                                                     ($type === 'sortie_ecole' ? 'secondary' : 
                                                     ($type === 'entree_classe' ? 'info' : 'warning'));
                                        $typeText = $type === 'entree_ecole' ? 'E. École' : 
                                                   ($type === 'sortie_ecole' ? 'S. École' : 
                                                   ($type === 'entree_classe' ? 'E. Classe' : 'S. Classe'));
                                        echo "<span class='badge bg-$badgeClass'>$typeText</span>"; 
                                        ?>
                                    </td>
                                    <td>
                                        <small class="d-block"><?php echo formatDateFr($presence['date_heure'], 'd/m'); ?></small>
                                        <small class="text-muted"><?php echo formatDateFr($presence['date_heure'], 'H:i'); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $statut = $presence['statut'];
                                        $badgeClass = $statut === 'present' ? 'success' : 
                                                     ($statut === 'absent' ? 'danger' : 
                                                     ($statut === 'retard' ? 'warning' : 'secondary'));
                                        $statutText = $statut === 'present' ? 'Présent' : 
                                                     ($statut === 'absent' ? 'Absent' : 
                                                     ($statut === 'retard' ? 'Retard' : ucfirst($statut)));
                                        echo "<span class='badge bg-$badgeClass'>$statutText</span>"; 
                                        ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="viewPresence(<?php echo $presence['id']; ?>)"
                                                    title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-warning" 
                                                    onclick="editPresence(<?php echo $presence['id']; ?>)"
                                                    title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" 
                                                    onclick="deletePresence(<?php echo $presence['id']; ?>)"
                                                    title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center pagination-sm">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php 
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                </li>
                                <?php if ($start > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                        <?php echo $total_pages; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Footer Mobile -->
        <div class="d-block d-md-none mt-4 pt-3 border-top text-center">
            <small class="text-muted">
                <?php echo $_SESSION['user_name'] ?? 'Surveillant'; ?> • 
                <?php echo number_format($total_records); ?> présences • 
                <?php echo date('d/m/Y H:i'); ?>
            </small>
        </div>
    </div>
    
    <!-- Modal Statistiques -->
    <div class="modal fade" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar me-2"></i>Statistiques Détail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="statsContent">
                    <!-- Les statistiques seront chargées en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Détails Présence -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Détails de la Présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <!-- Les détails seront chargés en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modification Présence -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier la Présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editContent">
                    <!-- Le formulaire sera chargé en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
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
        
        // Initialiser les datepickers
        if (typeof flatpickr !== 'undefined') {
            flatpickr("input[type=date]", {
                dateFormat: "Y-m-d",
                locale: "fr"
            });
        }
    });
    
    // Afficher le modal des statistiques
    function showStatsModal() {
        const params = new URLSearchParams({
            date_debut: '<?php echo $date_debut; ?>',
            date_fin: '<?php echo $date_fin; ?>',
            classe_id: '<?php echo $classe_id; ?>',
            site_id: '<?php echo $site_id; ?>'
        });
        
        fetch('ajax/get_presence_stats.php?' + params)
        .then(response => response.text())
        .then(html => {
            document.getElementById('statsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('statsModal')).show();
        })
        .catch(error => {
            console.error('Erreur:', error);
            Swal.fire('Erreur', 'Impossible de charger les statistiques', 'error');
        });
    }
    
    // Voir les détails d'une présence
    function viewPresence(presenceId) {
        fetch('ajax/get_presence_detail.php?id=' + presenceId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('detailContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('detailModal')).show();
        });
    }
    
    // Modifier une présence
    function editPresence(presenceId) {
        fetch('ajax/get_presence_edit.php?id=' + presenceId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('editContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        });
    }
    
    // Supprimer une présence
    function deletePresence(presenceId) {
        Swal.fire({
            title: 'Êtes-vous sûr ?',
            text: "Cette action est irréversible !",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Oui, supprimer !',
            cancelButtonText: 'Annuler',
            backdrop: true,
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax/delete_presence.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'id=' + presenceId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            title: 'Supprimé !',
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
    
    // Exporter vers Excel
    function exportToExcel() {
        try {
            const table = document.getElementById('presencesTable');
            if (!table) {
                throw new Error('Tableau non trouvé');
            }
            
            // Créer une copie du tableau pour l'export
            const exportTable = table.cloneNode(true);
            
            // Nettoyer les boutons d'actions
            const actionCells = exportTable.querySelectorAll('td:last-child, th:last-child');
            actionCells.forEach(cell => cell.remove());
            
            const ws = XLSX.utils.table_to_sheet(exportTable);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Présences");
            
            // Nom du fichier avec date
            const date = new Date().toISOString().split('T')[0];
            const filename = `presences_${date}.xlsx`;
            
            XLSX.writeFile(wb, filename);
        } catch (error) {
            console.error('Erreur export Excel:', error);
            Swal.fire('Erreur', 'Impossible d\'exporter vers Excel', 'error');
        }
    }
    
    // Exporter vers PDF (version simple)
    function exportToPDF() {
        window.print();
    }
    
    // Imprimer le tableau
    function printTable() {
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            Swal.fire('Erreur', 'Veuillez autoriser les popups pour l\'impression', 'error');
            return;
        }
        
        printWindow.document.write(`
            <html>
            <head>
                <title>Liste des Présences - <?php echo date('d/m/Y'); ?></title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #ddd; padding-top: 20px; }
                    .badge { padding: 3px 8px; border-radius: 10px; font-size: 12px; }
                    .badge-success { background-color: #d4edda; color: #155724; }
                    .badge-danger { background-color: #f8d7da; color: #721c24; }
                    .badge-warning { background-color: #fff3cd; color: #856404; }
                    .badge-primary { background-color: #cce5ff; color: #004085; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Liste des Présences</h2>
                    <p>Période : <?php echo htmlspecialchars($date_debut); ?> au <?php echo htmlspecialchars($date_fin); ?></p>
                    <p>Date d'export : ${new Date().toLocaleDateString('fr-FR')} ${new Date().toLocaleTimeString('fr-FR')}</p>
                </div>
                ${document.getElementById('presencesTable').outerHTML.replace(/<button[^>]*>.*?<\/button>/gi, '')}
                <div class="footer">
                    <p>Généré par ISGI - Surveillance des Présences</p>
                    <p>Utilisateur : <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Surveillant'); ?></p>
                </div>
                <script>
                    window.onload = function() { window.print(); setTimeout(() => window.close(), 500); }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // Réinitialiser les filtres
    function resetFilters() {
        window.location.href = 'presences.php';
    }
    
    // Effacer la recherche
    function clearSearch() {
        document.querySelector('input[name="search"]').value = '';
        document.getElementById('filterForm').submit();
    }
    
    // Rafraîchir les données
    function refreshData() {
        location.reload();
    }
    
    // Raccourcis clavier
    document.addEventListener('keydown', function(event) {
        // Ctrl + F pour focus sur la recherche
        if (event.ctrlKey && event.key === 'f') {
            event.preventDefault();
            document.querySelector('input[name="search"]').focus();
        }
        
        // Échap pour fermer les modals
        if (event.key === 'Escape') {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) modalInstance.hide();
            });
        }
    });
    
    // Auto-refresh toutes les 5 minutes si aucun modal n'est ouvert
    setInterval(() => {
        const modals = document.querySelectorAll('.modal.show');
        if (modals.length === 0) {
            refreshData();
        }
    }, 300000); // 5 minutes
    </script>
</body>
</html>