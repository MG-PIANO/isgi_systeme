<?php
// dashboard/admin_principal/etudiants.php

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
    $pageTitle = "Administrateur Principal - Gestion des Étudiants";
    
    // Fonctions utilitaires
    function getSexeBadge($sexe) {
        if ($sexe === 'M') {
            return '<span class="badge bg-primary">Homme</span>';
        } else {
            return '<span class="badge bg-pink">Femme</span>';
        }
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
                return '<span class="badge bg-secondary">Inactif</span>';
            case 'diplome':
                return '<span class="badge bg-info">Diplômé</span>';
            case 'abandonne':
                return '<span class="badge bg-danger">Abandonné</span>';
            default:
                return '<span class="badge bg-warning">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $etudiants = array();
    $sites = array();
    $filieres = array();
    
    // Récupérer les sites
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les filières
    $filieres = $db->query("SELECT * FROM filieres ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_recherche = isset($_GET['recherche']) ? trim($_GET['recherche']) : '';
    
    // CORRECTION : Modifier la requête pour ne pas dépendre de utilisateur_id
    // La table etudiants contient déjà nom, prenom directement
    $query = "SELECT e.*, 
              s.nom as site_nom, s.ville as site_ville,
              (SELECT COUNT(*) FROM dettes d WHERE d.etudiant_id = e.id AND d.statut = 'en_cours') as nb_dettes,
              (SELECT COUNT(*) FROM bulletins b WHERE b.etudiant_id = e.id) as nb_bulletins
              FROM etudiants e
              LEFT JOIN sites s ON e.site_id = s.id
              WHERE 1=1";
    
    $params = array();
    
    if ($filtre_site > 0) {
        $query .= " AND e.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if (!empty($filtre_statut)) {
        $query .= " AND e.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if (!empty($filtre_recherche)) {
        $query .= " AND (e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ? OR e.numero_cni LIKE ? OR e.nom_pere LIKE ? OR e.nom_mere LIKE ?)";
        $search_param = "%{$filtre_recherche}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " ORDER BY e.date_inscription DESC";
    
    // DEBUG: Afficher la requête SQL
    // echo "DEBUG Query: $query<br>";
    // echo "DEBUG Params: " . print_r($params, true) . "<br>";
    
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // DEBUG: Afficher le nombre d'étudiants trouvés et les premiers résultats
    // echo "DEBUG: Nombre d'étudiants trouvés: " . count($etudiants) . "<br>";
    // if (count($etudiants) > 0) {
    //     echo "DEBUG Premier étudiant: " . print_r($etudiants[0], true) . "<br>";
    // }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur etudiants.php: " . $e->getMessage());
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
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
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
        --pink-color: #e84393;
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
        --pink-color: #fd79a8;
    }
    
    .badge.bg-pink {
        background-color: var(--pink-color) !important;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
        min-height: 100vh;
        transition: background-color 0.3s ease, color 0.3s ease;
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
        transition: background-color 0.3s ease;
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
    
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
        color: var(--text-color);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    [data-theme="dark"] .card-header {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    .table {
        color: var(--text-color);
    }
    
    [data-theme="dark"] .table {
        --bs-table-bg: var(--card-bg);
        --bs-table-striped-bg: rgba(255, 255, 255, 0.05);
        --bs-table-hover-bg: rgba(255, 255, 255, 0.1);
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
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .student-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
    }
    
    .age-badge {
        font-size: 0.75em;
        padding: 2px 6px;
    }
    
    /* Corrections mode sombre */
    [data-theme="dark"] .text-muted {
        color: var(--text-muted) !important;
    }
    
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select {
        background-color: #2a2a2a;
        border-color: #444;
        color: var(--text-color);
    }
    
    [data-theme="dark"] .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }
    
    [data-theme="dark"] .btn-outline-primary {
        color: #86b7fe;
        border-color: #86b7fe;
    }
    
    [data-theme="dark"] .btn-outline-primary:hover {
        background-color: #86b7fe;
        color: #000;
    }
    
    [data-theme="dark"] .btn-outline-secondary {
        color: #adb5bd;
        border-color: #adb5bd;
    }
    
    [data-theme="dark"] .btn-outline-secondary:hover {
        background-color: #adb5bd;
        color: #000;
    }
    
    [data-theme="dark"] .btn-light {
        background-color: #444;
        color: var(--text-color);
        border-color: #555;
    }
    
    /* Tous les textes en mode sombre */
    [data-theme="dark"] h1,
    [data-theme="dark"] h2,
    [data-theme="dark"] h3,
    [data-theme="dark"] h4,
    [data-theme="dark"] h5,
    [data-theme="dark"] h6,
    [data-theme="dark"] p,
    [data-theme="dark"] span,
    [data-theme="dark"] div,
    [data-theme="dark"] label,
    [data-theme="dark"] small,
    [data-theme="dark"] strong {
        color: var(--text-color) !important;
    }
    
    /* Alertes en mode sombre */
    [data-theme="dark"] .alert {
        background-color: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.1);
        color: var(--text-color);
    }
    
    [data-theme="dark"] .alert-info {
        background-color: rgba(23, 162, 184, 0.2);
        border-color: rgba(23, 162, 184, 0.3);
    }
    
    [data-theme="dark"] .alert-success {
        background-color: rgba(40, 167, 69, 0.2);
        border-color: rgba(40, 167, 69, 0.3);
    }
    
    [data-theme="dark"] .alert-danger {
        background-color: rgba(220, 53, 69, 0.2);
        border-color: rgba(220, 53, 69, 0.3);
    }
    
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
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                <small>Gestion des Étudiants</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="sites.php" class="nav-link">
                        <i class="fas fa-building"></i>
                        <span>Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Utilisateurs</span>
                    </a>
                    <a href="etudiants.php" class="nav-link active">
                        <i class="fas fa-user-graduate"></i>
                        <span>Étudiants</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes</span>
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
                            <i class="fas fa-user-graduate me-2"></i>
                            Gestion des Étudiants
                        </h2>
                        <p class="text-muted mb-0">Gérez tous les étudiants de l'ISGI</p>
                    </div>
                    <div class="btn-group">
                        <a href="demandes.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Nouvelles Inscriptions
                        </a>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>
                        Filtres de Recherche
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label for="site" class="form-label">Site</label>
                            <select class="form-select" id="site" name="site">
                                <option value="0">Tous les sites</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo $filtre_site == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="actif" <?php echo $filtre_statut == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                <option value="inactif" <?php echo $filtre_statut == 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                <option value="diplome" <?php echo $filtre_statut == 'diplome' ? 'selected' : ''; ?>>Diplômé</option>
                                <option value="abandonne" <?php echo $filtre_statut == 'abandonne' ? 'selected' : ''; ?>>Abandonné</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="recherche" class="form-label">Recherche</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo htmlspecialchars($filtre_recherche); ?>" 
                                       placeholder="Matricule, Nom, Prénom, CNI...">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || !empty($filtre_recherche)): ?>
                                <a href="etudiants.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques des étudiants -->
            <div class="row mb-4">
                <?php 
                $total_actifs = 0;
                $total_diplomes = 0;
                $total_avec_dettes = 0;
                $total_hommes = 0;
                $total_femmes = 0;
                
                foreach($etudiants as $etudiant) {
                    if($etudiant['statut'] == 'actif') $total_actifs++;
                    if($etudiant['statut'] == 'diplome') $total_diplomes++;
                    if($etudiant['nb_dettes'] > 0) $total_avec_dettes++;
                    if($etudiant['sexe'] == 'M') $total_hommes++;
                    if($etudiant['sexe'] == 'F') $total_femmes++;
                }
                
                // Calculer l'âge moyen si possible
                $ages = array();
                $aujourdhui = new DateTime();
                foreach($etudiants as $etudiant) {
                    if($etudiant['date_naissance'] && $etudiant['date_naissance'] != '0000-00-00') {
                        $naissance = new DateTime($etudiant['date_naissance']);
                        $age = $naissance->diff($aujourdhui)->y;
                        $ages[] = $age;
                    }
                }
                $age_moyen = count($ages) > 0 ? round(array_sum($ages) / count($ages), 1) : 0;
                ?>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h3><?php echo count($etudiants); ?></h3>
                            <p class="text-muted mb-0">Total</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <h3><?php echo $total_actifs; ?></h3>
                            <p class="text-muted mb-0">Actifs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-info stats-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <h3><?php echo $total_diplomes; ?></h3>
                            <p class="text-muted mb-0">Diplômés</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-danger stats-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3><?php echo $total_avec_dettes; ?></h3>
                            <p class="text-muted mb-0">Dettes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-male"></i>
                            </div>
                            <h3><?php echo $total_hommes; ?></h3>
                            <p class="text-muted mb-0">Hommes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-pink stats-icon">
                                <i class="fas fa-female"></i>
                            </div>
                            <h3><?php echo $total_femmes; ?></h3>
                            <p class="text-muted mb-0">Femmes</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des étudiants -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Étudiants
                    </h5>
                    <div class="text-muted">
                        <?php echo count($etudiants); ?> étudiant(s) trouvé(s)
                        <?php if($age_moyen > 0): ?>
                        | Âge moyen: <?php echo $age_moyen; ?> ans
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($etudiants)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun étudiant trouvé avec les critères sélectionnés
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Informations Personnelles</th>
                                    <th>Contact & Site</th>
                                    <th>Statut</th>
                                    <th>Documents</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($etudiants as $etudiant): 
                                // Calculer l'âge
                                $age = 'N/A';
                                if($etudiant['date_naissance'] && $etudiant['date_naissance'] != '0000-00-00') {
                                    $naissance = new DateTime($etudiant['date_naissance']);
                                    $age = $naissance->diff(new DateTime())->y;
                                }
                                
                                // Déterminer l'email et téléphone
                                // CORRECTION : Si l'étudiant n'a pas de compte utilisateur, on peut mettre N/A
                                $email = 'N/A';
                                $telephone = 'N/A';
                                // Note: Vous pourriez vouloir ajouter un champ email dans la table etudiants plus tard
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="student-avatar me-3">
                                                <?php 
                                                $initials = '';
                                                if(!empty($etudiant['prenom']) && !empty($etudiant['nom'])) {
                                                    $initials = strtoupper(substr($etudiant['prenom'], 0, 1) . substr($etudiant['nom'], 0, 1));
                                                } else {
                                                    $initials = '??';
                                                }
                                                echo $initials;
                                                ?>
                                            </div>
                                            <div>
                                                <strong>
                                                    <?php 
                                                    if(!empty($etudiant['prenom']) && !empty($etudiant['nom'])) {
                                                        echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']);
                                                    } else {
                                                        echo 'Nom inconnu';
                                                    }
                                                    ?>
                                                </strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($etudiant['matricule'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="mt-1">
                                                    <?php echo getSexeBadge($etudiant['sexe'] ?? 'M'); ?>
                                                    <?php if($age != 'N/A'): ?>
                                                    <span class="badge bg-secondary age-badge"><?php echo $age; ?> ans</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong>CNI:</strong> <?php echo htmlspecialchars($etudiant['numero_cni'] ?? 'Non renseigné'); ?></div>
                                            <div><strong>Né(e) le:</strong> <?php echo $etudiant['date_naissance'] ? date('d/m/Y', strtotime($etudiant['date_naissance'])) : 'N/A'; ?></div>
                                            <div><strong>À:</strong> <?php echo htmlspecialchars($etudiant['lieu_naissance'] ?? ''); ?></div>
                                            <div><strong>Nationalité:</strong> <?php echo htmlspecialchars($etudiant['nationalite'] ?? 'Congolaise'); ?></div>
                                            <?php if(!empty($etudiant['nom_pere'])): ?>
                                            <div><strong>Père:</strong> <?php echo htmlspecialchars($etudiant['nom_pere']); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($etudiant['nom_mere'])): ?>
                                            <div><strong>Mère:</strong> <?php echo htmlspecialchars($etudiant['nom_mere']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><i class="fas fa-envelope text-muted me-2"></i><?php echo htmlspecialchars($email); ?></div>
                                            <div><i class="fas fa-phone text-muted me-2"></i><?php echo htmlspecialchars($etudiant['telephone_parent'] ?? $telephone); ?></div>
                                            <?php if(!empty($etudiant['telephone_tuteur'])): ?>
                                            <div><i class="fas fa-phone text-muted me-2"></i>Tuteur: <?php echo htmlspecialchars($etudiant['telephone_tuteur']); ?></div>
                                            <?php endif; ?>
                                            <div class="mt-2">
                                                <i class="fas fa-building text-muted me-2"></i>
                                                <strong><?php echo htmlspecialchars($etudiant['site_nom'] ?? 'N/A'); ?></strong>
                                                <div class="text-muted"><?php echo htmlspecialchars($etudiant['site_ville'] ?? ''); ?></div>
                                            </div>
                                            <div class="mt-2">
                                                <i class="fas fa-calendar text-muted me-2"></i>
                                                Inscrit le: <?php echo $etudiant['date_inscription'] ? date('d/m/Y', strtotime($etudiant['date_inscription'])) : 'N/A'; ?>
                                            </div>
                                            <?php if(!empty($etudiant['adresse'])): ?>
                                            <div class="mt-2">
                                                <i class="fas fa-home text-muted me-2"></i>
                                                <small><?php echo htmlspecialchars($etudiant['adresse']); ?></small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($etudiant['nb_dettes'] > 0): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle"></i> <?php echo $etudiant['nb_dettes']; ?> dette(s)
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($etudiant['statut']); ?>
                                        <?php if($etudiant['classe_id']): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">Classe: <?php echo $etudiant['classe_id']; ?></small>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if($etudiant['nb_bulletins'] > 0): ?>
                                            <span class="badge bg-info me-1">
                                                <i class="fas fa-file-alt"></i> <?php echo $etudiant['nb_bulletins']; ?>
                                            </span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-primary btn-sm" title="Voir bulletins" onclick="voirBulletins(<?php echo $etudiant['id']; ?>)">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-success btn-sm" title="Générer attestation" onclick="genererAttestation(<?php echo $etudiant['id']; ?>)">
                                                <i class="fas fa-certificate"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" title="Voir détails" onclick="voirDetails(<?php echo $etudiant['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning" title="Modifier" onclick="modifierEtudiant(<?php echo $etudiant['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" title="Changer statut" onclick="changerStatut(<?php echo $etudiant['id']; ?>, '<?php echo $etudiant['statut']; ?>')">
                                                <i class="fas fa-exchange-alt"></i>
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
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        // Sauvegarder dans localStorage pour persistance
        localStorage.setItem('isgi_theme', newTheme);
        
        // Mettre à jour le texte du bouton
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (newTheme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Vérifier localStorage d'abord, puis cookies, puis par défaut light
        const theme = localStorage.getItem('isgi_theme') || 
                     document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 
                     'light';
        
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Initialiser DataTable
        $('#studentsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [4, 5] }
            ]
        });
    });
    
    // Fonctions pour les actions
    function voirDetails(id) {
        alert('Voir détails de l\'étudiant ID: ' + id);
        // Rediriger vers une page de détails
        // window.location.href = 'details_etudiant.php?id=' + id;
    }
    
    function modifierEtudiant(id) {
        alert('Modifier l\'étudiant ID: ' + id);
        // Rediriger vers une page de modification
        // window.location.href = 'modifier_etudiant.php?id=' + id;
    }
    
    function changerStatut(id, statutActuel) {
        if(confirm('Changer le statut de cet étudiant ? Statut actuel: ' + statutActuel)) {
            // Envoyer une requête AJAX ou rediriger
            // window.location.href = 'changer_statut.php?id=' + id;
        }
    }
    
    function voirBulletins(id) {
        alert('Voir les bulletins de l\'étudiant ID: ' + id);
        // window.location.href = 'bulletins_etudiant.php?id=' + id;
    }
    
    function genererAttestation(id) {
        if(confirm('Générer une attestation pour cet étudiant ?')) {
            // window.location.href = 'generer_attestation.php?id=' + id;
        }
    }
    </script>
</body>
</html>