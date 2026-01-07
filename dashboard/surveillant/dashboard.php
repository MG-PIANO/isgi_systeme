<?php
// dashboard/surveillant/dashboard.php

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
$pageTitle = "Surveillant Général - Gestion des Présences";

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
        case 'en_attente':
            return '<span class="badge bg-secondary">En attente</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

function getTypePresenceBadge($type) {
    switch ($type) {
        case 'entree_ecole':
            return '<span class="badge bg-primary">Entrée École</span>';
        case 'sortie_ecole':
            return '<span class="badge bg-secondary">Sortie École</span>';
        case 'entree_classe':
            return '<span class="badge bg-info">Entrée Classe</span>';
        case 'sortie_classe':
            return '<span class="badge bg-warning">Sortie Classe</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
    }
}

// Initialiser les variables
$stats = [
    'total_present' => 0,
    'total_absent' => 0,
    'total_retard' => 0,
    'entrees_aujourdhui' => 0,
    'sorties_aujourdhui' => 0,
    'total_etudiants' => 0
];

$presences_aujourdhui = [];
$presences_semaine = [];
$liste_etudiants = [];
$presences_recentes = [];
$classes_actives = [];

// Date d'aujourd'hui
$aujourdhui = date('Y-m-d');
$debut_semaine = date('Y-m-d', strtotime('monday this week'));

try {
    // 1. Statistiques globales pour aujourd'hui
    // Total étudiants actifs
    $query = "SELECT COUNT(*) as total FROM etudiants 
              WHERE site_id = :site_id AND statut = 'actif'";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_etudiants'] = $result['total'] ?? 0;

    // Présences aujourd'hui
    $query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) = :aujourdhui
                AND type_presence IN ('entree_ecole', 'entree_classe')";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':aujourdhui' => $aujourdhui
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_present'] = $result['presents'] ?? 0;
    $stats['total_absent'] = $result['absents'] ?? 0;
    $stats['total_retard'] = $result['retards'] ?? 0;

    // Entrées aujourd'hui
    $query = "SELECT COUNT(*) as total FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) = :aujourdhui
                AND type_presence = 'entree_ecole'";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':aujourdhui' => $aujourdhui]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['entrees_aujourdhui'] = $result['total'] ?? 0;

    // Sorties aujourd'hui
    $query = "SELECT COUNT(*) as total FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) = :aujourdhui
                AND type_presence = 'sortie_ecole'";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':aujourdhui' => $aujourdhui]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['sorties_aujourdhui'] = $result['total'] ?? 0;

    // 2. Liste des présences aujourd'hui
    $query = "SELECT 
                p.*,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                m.nom as matiere_nom
              FROM presences p
              LEFT JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN matieres m ON p.matiere_id = m.id
              WHERE p.site_id = :site_id 
                AND DATE(p.date_heure) = :aujourdhui
              ORDER BY p.date_heure DESC 
              LIMIT 50";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':aujourdhui' => $aujourdhui]);
    $presences_aujourdhui = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Statistiques de la semaine
    $query = "SELECT 
                DATE(date_heure) as date_jour,
                COUNT(*) as total_presences,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences 
              WHERE site_id = :site_id 
                AND DATE(date_heure) >= :debut_semaine
                AND type_presence IN ('entree_ecole', 'entree_classe')
              GROUP BY DATE(date_heure)
              ORDER BY date_jour ASC";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':debut_semaine' => $debut_semaine
    ]);
    $presences_semaine = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Liste des étudiants avec leurs dernières présences
    $query = "SELECT 
                e.id,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                (SELECT p.statut 
                 FROM presences p 
                 WHERE p.etudiant_id = e.id 
                   AND DATE(p.date_heure) = :aujourdhui 
                   AND p.type_presence IN ('entree_ecole', 'entree_classe')
                 ORDER BY p.date_heure DESC 
                 LIMIT 1) as statut_aujourdhui,
                (SELECT p.date_heure 
                 FROM presences p 
                 WHERE p.etudiant_id = e.id 
                   AND p.type_presence = 'entree_ecole'
                 ORDER BY p.date_heure DESC 
                 LIMIT 1) as derniere_entree
              FROM etudiants e
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE e.site_id = :site_id 
                AND e.statut = 'actif'
              ORDER BY e.nom, e.prenom
              LIMIT 100";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':aujourdhui' => $aujourdhui]);
    $liste_etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Présences récentes (dernières 2 heures)
    $deux_heures = date('Y-m-d H:i:s', strtotime('-2 hours'));
    $query = "SELECT 
                p.*,
                e.matricule,
                e.nom,
                e.prenom,
                c.nom as classe_nom,
                m.nom as matiere_nom
              FROM presences p
              LEFT JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN matieres m ON p.matiere_id = m.id
              WHERE p.site_id = :site_id 
                AND p.date_heure >= :deux_heures
              ORDER BY p.date_heure DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':deux_heures' => $deux_heures]);
    $presences_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Classes actives
    $query = "SELECT 
                c.*,
                COUNT(e.id) as effectif
              FROM classes c
              LEFT JOIN etudiants e ON c.id = e.classe_id AND e.statut = 'actif'
              WHERE c.site_id = :site_id
                AND c.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY c.id
              ORDER BY c.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $classes_actives = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    .btn-qr {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
    }
    
    .btn-qr:hover {
        background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
        color: white;
    }
    
    /* Graphique */
    .chart-container {
        position: relative;
        height: 250px;
        width: 100%;
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
                <small>Gestion des Présences</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
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
                    <a href="statistiques.php" class="nav-link">
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
                            <i class="fas fa-user-shield me-2"></i>
                            Tableau de Bord - Surveillant Général
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-calendar-day"></i> 
                            <?php echo date('d/m/Y'); ?> - 
                            <i class="fas fa-clock"></i> 
                            <?php echo date('H:i'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="window.location.href='scanner_qr.php'">
                            <i class="fas fa-qrcode"></i> Scanner QR Code
                        </button>
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
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
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_etudiants']; ?></div>
                        <div class="stat-label">Étudiants Actifs</div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_present']; ?></div>
                        <div class="stat-label">Présents</div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_absent']; ?></div>
                        <div class="stat-label">Absents</div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_retard']; ?></div>
                        <div class="stat-label">Retards</div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['entrees_aujourdhui']; ?></div>
                        <div class="stat-label">Entrées</div>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="card stat-card">
                        <div class="text-secondary stat-icon">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['sorties_aujourdhui']; ?></div>
                        <div class="stat-label">Sorties</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Présences Récentes et Graphique -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Présences Récentes (2 dernières heures)
                            </h5>
                            <a href="presences.php" class="btn btn-sm btn-outline-primary">
                                Voir toutes
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if(empty($presences_recentes)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune présence enregistrée dans les 2 dernières heures.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Étudiant</th>
                                            <th>Matricule</th>
                                            <th>Type</th>
                                            <th>Heure</th>
                                            <th>Statut</th>
                                            <th>Classe</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($presences_recentes as $presence): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($presence['matricule']); ?></td>
                                            <td><?php echo getTypePresenceBadge($presence['type_presence']); ?></td>
                                            <td><?php echo formatDateFr($presence['date_heure'], 'H:i'); ?></td>
                                            <td><?php echo getStatutBadge($presence['statut']); ?></td>
                                            <td><?php echo htmlspecialchars($presence['classe_nom'] ?? 'N/A'); ?></td>
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
                                <i class="fas fa-chart-line me-2"></i>
                                Statistiques de la Semaine
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="presenceChart"></canvas>
                            </div>
                            <div class="mt-3 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    Évolution des présences sur 7 jours
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="dashboardTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="presences-tab" data-bs-toggle="tab" data-bs-target="#presences" type="button">
                                <i class="fas fa-calendar-day me-2"></i>Présences Aujourd'hui
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="etudiants-tab" data-bs-toggle="tab" data-bs-target="#etudiants" type="button">
                                <i class="fas fa-users me-2"></i>Liste Étudiants
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="classes-tab" data-bs-toggle="tab" data-bs-target="#classes" type="button">
                                <i class="fas fa-door-open me-2"></i>Salles de Classe
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboardTabsContent">
                        <!-- Tab 1: Présences Aujourd'hui -->
                        <div class="tab-pane fade show active" id="presences">
                            <?php if(empty($presences_aujourdhui)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune présence enregistrée aujourd'hui.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Étudiant</th>
                                            <th>Matricule</th>
                                            <th>Type</th>
                                            <th>Date/Heure</th>
                                            <th>Statut</th>
                                            <th>Classe</th>
                                            <th>Matière</th>
                                            <th>Surveillant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($presences_aujourdhui as $presence): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($presence['matricule']); ?></td>
                                            <td><?php echo getTypePresenceBadge($presence['type_presence']); ?></td>
                                            <td><?php echo formatDateFr($presence['date_heure']); ?></td>
                                            <td><?php echo getStatutBadge($presence['statut']); ?></td>
                                            <td><?php echo htmlspecialchars($presence['classe_nom'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo $presence['surveillant_id'] ? 'Scanné' : 'Auto'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 2: Liste Étudiants -->
                        <div class="tab-pane fade" id="etudiants">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <input type="text" class="form-control" id="searchStudent" placeholder="Rechercher un étudiant...">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="filterClasse">
                                        <option value="">Toutes les classes</option>
                                        <?php foreach($classes_actives as $classe): ?>
                                        <option value="<?php echo $classe['id']; ?>">
                                            <?php echo htmlspecialchars($classe['nom']); ?> (<?php echo $classe['effectif']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="filterStatut">
                                        <option value="">Tous statuts</option>
                                        <option value="present">Présent</option>
                                        <option value="absent">Absent</option>
                                        <option value="retard">En retard</option>
                                        <option value="justifie">Justifié</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover" id="studentTable">
                                    <thead>
                                        <tr>
                                            <th>Matricule</th>
                                            <th>Étudiant</th>
                                            <th>Classe</th>
                                            <th>Statut Aujourd'hui</th>
                                            <th>Dernière Entrée</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($liste_etudiants as $etudiant): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($etudiant['matricule']); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($etudiant['classe_nom'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php echo $etudiant['statut_aujourdhui'] ? 
                                                    getStatutBadge($etudiant['statut_aujourdhui']) : 
                                                    '<span class="badge bg-secondary">Non enregistré</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $etudiant['derniere_entree'] ? 
                                                    formatDateFr($etudiant['derniere_entree'], 'd/m H:i') : 
                                                    'Jamais'; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="window.location.href='etudiant_detail.php?id=<?php echo $etudiant['id']; ?>'">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-success" 
                                                        onclick="markAttendance(<?php echo $etudiant['id']; ?>, 'present')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        onclick="markAttendance(<?php echo $etudiant['id']; ?>, 'absent')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Salles de Classe -->
                        <div class="tab-pane fade" id="classes">
                            <div class="row">
                                <?php foreach($classes_actives as $classe): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="fas fa-door-open me-2"></i>
                                                <?php echo htmlspecialchars($classe['nom']); ?>
                                            </h5>
                                            <p class="card-text">
                                                <i class="fas fa-users me-2"></i>
                                                Effectif: <strong><?php echo $classe['effectif']; ?> étudiants</strong>
                                            </p>
                                            <div class="d-flex justify-content-between">
                                                <a href="classe_detail.php?id=<?php echo $classe['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Détails
                                                </a>
                                                <a href="classe_presence.php?classe_id=<?php echo $classe['id']; ?>" 
                                                   class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-clipboard-check"></i> Présence
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Actions Rapides et Info -->
            <div class="row">
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
                                <button class="btn btn-qr" onclick="window.location.href='scanner_qr.php'">
                                    <i class="fas fa-qrcode me-2"></i>Scanner QR Code
                                </button>
                                <button class="btn btn-success" onclick="window.location.href='generer_qr.php'">
                                    <i class="fas fa-barcode me-2"></i>Générer QR Code
                                </button>
                                <button class="btn btn-info" onclick="window.location.href='absences.php'">
                                    <i class="fas fa-user-times me-2"></i>Gérer Absences
                                </button>
                                <button class="btn btn-warning" onclick="window.location.href='retards.php'">
                                    <i class="fas fa-clock me-2"></i>Voir Retards
                                </button>
                                <button class="btn btn-primary" onclick="window.location.href='rapports_presence.php'">
                                    <i class="fas fa-chart-bar me-2"></i>Générer Rapport
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations et Alertes
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Alertes absences prolongées -->
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Alertes de Présence</h6>
                                <div id="alertsList">
                                    <!-- Les alertes seront chargées en AJAX -->
                                </div>
                            </div>
                            
                            <!-- Statistiques du jour -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6><i class="fas fa-calendar-day"></i> Aujourd'hui</h6>
                                            <div class="d-flex justify-content-between">
                                                <span>Présents:</span>
                                                <strong class="text-success"><?php echo $stats['total_present']; ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Absents:</span>
                                                <strong class="text-danger"><?php echo $stats['total_absent']; ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Retards:</span>
                                                <strong class="text-warning"><?php echo $stats['total_retard']; ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6><i class="fas fa-chart-pie"></i> Taux de Présence</h6>
                                            <?php if($stats['total_etudiants'] > 0): ?>
                                            <?php 
                                            $taux_presence = ($stats['total_present'] / $stats['total_etudiants']) * 100;
                                            $taux_absence = ($stats['total_absent'] / $stats['total_etudiants']) * 100;
                                            ?>
                                            <div class="progress mb-2" style="height: 20px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $taux_presence; ?>%">
                                                    <?php echo number_format($taux_presence, 1); ?>%
                                                </div>
                                            </div>
                                            <small>Présence: <?php echo number_format($taux_presence, 1); ?>%</small>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">Aucun étudiant actif</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
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
        
        // Initialiser le graphique
        initializeChart();
        
        // Charger les alertes
        loadAlerts();
        
        // Initialiser la recherche
        initializeSearch();
    });
    
    // Initialiser le graphique de présence
    function initializeChart() {
        <?php if(!empty($presences_semaine)): ?>
        const ctx = document.getElementById('presenceChart').getContext('2d');
        const dates = <?php echo json_encode(array_column($presences_semaine, 'date_jour')); ?>;
        const presents = <?php echo json_encode(array_column($presences_semaine, 'presents')); ?>;
        const absents = <?php echo json_encode(array_column($presences_semaine, 'absents')); ?>;
        const retards = <?php echo json_encode(array_column($presences_semaine, 'retards')); ?>;
        
        // Formater les dates
        const formattedDates = dates.map(date => {
            const d = new Date(date);
            return `${d.getDate()}/${d.getMonth() + 1}`;
        });
        
        new Chart(ctx, {
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
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
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
                            text: 'Jours de la semaine'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    }
    
    // Charger les alertes en AJAX
    function loadAlerts() {
        fetch('ajax/get_alerts.php')
            .then(response => response.json())
            .then(data => {
                const alertsList = document.getElementById('alertsList');
                if (data.length > 0) {
                    let html = '<ul class="mb-0">';
                    data.forEach(alert => {
                        html += `<li>${alert.message}</li>`;
                    });
                    html += '</ul>';
                    alertsList.innerHTML = html;
                } else {
                    alertsList.innerHTML = '<p class="mb-0">Aucune alerte pour le moment.</p>';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                document.getElementById('alertsList').innerHTML = 
                    '<p class="text-danger">Erreur de chargement des alertes</p>';
            });
    }
    
    // Marquer la présence d'un étudiant
    function markAttendance(studentId, status) {
        if (confirm(`Marquer cet étudiant comme ${status} ?`)) {
            fetch('ajax/mark_attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `student_id=${studentId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Présence enregistrée avec succès !');
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
    
    // Initialiser la recherche et filtres
    function initializeSearch() {
        const searchInput = document.getElementById('searchStudent');
        const filterClasse = document.getElementById('filterClasse');
        const filterStatut = document.getElementById('filterStatut');
        const tableRows = document.querySelectorAll('#studentTable tbody tr');
        
        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedClasse = filterClasse.value;
            const selectedStatut = filterStatut.value;
            
            tableRows.forEach(row => {
                const matricule = row.cells[0].textContent.toLowerCase();
                const nom = row.cells[1].textContent.toLowerCase();
                const classe = row.cells[2].textContent;
                const statutBadge = row.cells[3].querySelector('.badge');
                const statut = statutBadge ? statutBadge.textContent.toLowerCase().trim() : '';
                
                const matchesSearch = searchTerm === '' || 
                    matricule.includes(searchTerm) || 
                    nom.includes(searchTerm);
                
                const matchesClasse = selectedClasse === '' || 
                    classe.includes(selectedClasse);
                
                const matchesStatut = selectedStatut === '' || 
                    statut.includes(selectedStatut);
                
                if (matchesSearch && matchesClasse && matchesStatut) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        searchInput.addEventListener('input', filterTable);
        filterClasse.addEventListener('change', filterTable);
        filterStatut.addEventListener('change', filterTable);
    }
    
    // Auto-refresh toutes les 60 secondes
    setInterval(function() {
        loadAlerts();
    }, 60000);
    </script>
</body>
</html>