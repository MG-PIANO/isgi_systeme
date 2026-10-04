<?php
// dashboard/surveillant/emploi_du_temps_complet.php

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
$pageTitle = "Surveillant Général - Emploi du Temps Complet";

// Récupérer l'ID du site du surveillant
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

// Fonction pour formater la date
function formatDateFr($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date == '0000-00-00 00:00:00') return 'Non renseigné';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Date invalide';
    return date($format, $timestamp);
}

// Fonction pour obtenir le jour de la semaine
function getJourSemaine($date) {
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $timestamp = strtotime($date);
    return $jours[date('w', $timestamp)];
}

// Date d'aujourd'hui
$aujourdhui = date('Y-m-d');
$jour_semaine_aujourdhui = getJourSemaine($aujourdhui);

// Gérer les paramètres de filtrage
$filtre_classe = isset($_GET['classe']) ? intval($_GET['classe']) : 0;
$filtre_enseignant = isset($_GET['enseignant']) ? intval($_GET['enseignant']) : 0;
$filtre_jour = isset($_GET['jour']) ? $_GET['jour'] : '';
$filtre_matiere = isset($_GET['matiere']) ? intval($_GET['matiere']) : 0;
$filtre_salle = isset($_GET['salle']) ? $_GET['salle'] : '';

// Variables pour stocker les données
$emploi_du_temps = [];
$classes = [];
$enseignants = [];
$matieres = [];
$salles = [];
$statistiques = [
    'total_cours' => 0,
    'cours_aujourdhui' => 0,
    'classes_actives' => 0,
    'enseignants_actifs' => 0
];

try {
    // 1. Récupérer l'emploi du temps avec filtres
    $query = "SELECT 
                edt.*,
                c.id as classe_id,
                c.nom as classe_nom,
                f.nom as filiere_nom,
                n.libelle as niveau_libelle,
                m.id as matiere_id,
                m.nom as matiere_nom,
                m.code as matiere_code,
                e.id as enseignant_id,
                CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                e.matricule as enseignant_matricule,
                edt.salle
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    
    $params = [':site_id' => $site_id];
    
    // Appliquer les filtres
    if ($filtre_classe > 0) {
        $query .= " AND edt.classe_id = :classe_id";
        $params[':classe_id'] = $filtre_classe;
    }
    
    if ($filtre_enseignant > 0) {
        $query .= " AND edt.enseignant_id = :enseignant_id";
        $params[':enseignant_id'] = $filtre_enseignant;
    }
    
    if ($filtre_matiere > 0) {
        $query .= " AND edt.matiere_id = :matiere_id";
        $params[':matiere_id'] = $filtre_matiere;
    }
    
    if (!empty($filtre_jour)) {
        $query .= " AND edt.jour_semaine = :jour_semaine";
        $params[':jour_semaine'] = $filtre_jour;
    }
    
    if (!empty($filtre_salle)) {
        $query .= " AND edt.salle LIKE :salle";
        $params[':salle'] = '%' . $filtre_salle . '%';
    }
    
    $query .= " ORDER BY 
                CASE edt.jour_semaine 
                    WHEN 'Lundi' THEN 1
                    WHEN 'Mardi' THEN 2
                    WHEN 'Mercredi' THEN 3
                    WHEN 'Jeudi' THEN 4
                    WHEN 'Vendredi' THEN 5
                    WHEN 'Samedi' THEN 6
                    ELSE 7
                END, 
                edt.heure_debut, 
                c.nom";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $emploi_du_temps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Récupérer les statistiques
    // Total des cours
    $query = "SELECT COUNT(*) as total FROM emploi_du_temps 
              WHERE site_id = :site_id 
                AND annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $statistiques['total_cours'] = $result['total'] ?? 0;
    
    // Cours aujourd'hui
    $query = "SELECT COUNT(*) as total FROM emploi_du_temps 
              WHERE site_id = :site_id 
                AND jour_semaine = :jour_semaine
                AND annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':jour_semaine' => $jour_semaine_aujourdhui
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $statistiques['cours_aujourdhui'] = $result['total'] ?? 0;
    
    // Classes actives
    $query = "SELECT COUNT(DISTINCT classe_id) as total FROM emploi_du_temps 
              WHERE site_id = :site_id 
                AND annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $statistiques['classes_actives'] = $result['total'] ?? 0;
    
    // 3. Récupérer les classes pour le filtre
    $query = "SELECT DISTINCT 
                c.id,
                c.nom,
                f.nom as filiere_nom,
                n.libelle as niveau_libelle,
                COUNT(edt.id) as nombre_cours
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY c.id, c.nom, f.nom, n.libelle
              ORDER BY c.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Récupérer les enseignants pour le filtre
    $query = "SELECT DISTINCT 
                e.id,
                CONCAT(u.nom, ' ', u.prenom) as nom_complet,
                e.matricule,
                COUNT(edt.id) as nombre_cours
              FROM emploi_du_temps edt
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.enseignant_id IS NOT NULL
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY e.id, u.nom, u.prenom, e.matricule
              ORDER BY u.nom, u.prenom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $statistiques['enseignants_actifs'] = count($enseignants);
    
    // 5. Récupérer les matières pour le filtre
    $query = "SELECT DISTINCT 
                m.id,
                m.nom,
                m.code,
                COUNT(edt.id) as nombre_cours
              FROM emploi_du_temps edt
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY m.id, m.nom, m.code
              ORDER BY m.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Récupérer les salles utilisées
    $query = "SELECT DISTINCT 
                salle,
                COUNT(*) as nombre_cours
              FROM emploi_du_temps 
              WHERE site_id = :site_id 
                AND salle IS NOT NULL 
                AND salle != ''
                AND annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY salle
              ORDER BY salle";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $salles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur emploi_du_temps_complet: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
    
    /* Sidebar pour desktop */
    @media (min-width: 992px) {
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transform: translateX(0);
            transition: transform 0.3s ease-in-out;
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out;
        }
        
        .mobile-header {
            display: none;
        }
    }
    
    /* Sidebar pour mobile */
    @media (max-width: 991px) {
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 1050;
            overflow-y: auto;
        }
        
        .sidebar.show {
            transform: translateX(0);
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            display: none;
        }
        
        .sidebar-overlay.show {
            display: block;
        }
        
        .main-content {
            width: 100%;
            padding: 15px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out;
        }
        
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--sidebar-bg);
            color: white;
            padding: 15px;
            margin: -15px -15px 15px -15px;
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        
        .mobile-header h1 {
            font-size: 1.5rem;
            margin: 0;
        }
        
        .mobile-header-buttons {
            display: flex;
            gap: 10px;
        }
    }
    
    /* Sidebar commun */
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
    
    /* Bouton hamburger */
    .hamburger-btn {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        padding: 5px;
        cursor: pointer;
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
    
    /* Stat cards responsive */
    .stat-card {
        text-align: center;
        padding: 15px;
        height: 100%;
    }
    
    .stat-icon {
        font-size: 2rem;
        margin-bottom: 10px;
    }
    
    .stat-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.8rem;
    }
    
    /* Tableaux responsive */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table {
        color: var(--text-color);
        min-width: 650px;
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 12px;
        white-space: nowrap;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px;
        color: var(--text-color);
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Boutons responsive */
    .btn-group {
        flex-wrap: wrap;
        gap: 5px;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
    }
    
    /* Filtres responsive */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    /* Badges */
    .badge-jour {
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 500;
    }
    
    .badge-lundi { background-color: #3498db; color: white; }
    .badge-mardi { background-color: #2ecc71; color: white; }
    .badge-mercredi { background-color: #9b59b6; color: white; }
    .badge-jeudi { background-color: #f39c12; color: white; }
    .badge-vendredi { background-color: #e74c3c; color: white; }
    .badge-samedi { background-color: #34495e; color: white; }
    
    .badge-salle {
        background-color: var(--info-color);
        color: white;
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 4px;
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
    
    /* Améliorations pour très petits écrans */
    @media (max-width: 576px) {
        .content-header {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .content-header .btn-group {
            margin-top: 10px;
            width: 100%;
        }
        
        .content-header .btn-group .btn {
            flex: 1;
        }
        
        .stat-card {
            padding: 10px;
        }
        
        .stat-icon {
            font-size: 1.5rem;
        }
        
        .stat-value {
            font-size: 1.2rem;
        }
        
        .card-header h5 {
            font-size: 1rem;
        }
        
        .filter-card .col-md-3 {
            margin-bottom: 10px;
        }
    }
    
    /* Scrollbar personnalisée */
    .sidebar::-webkit-scrollbar {
        width: 5px;
    }
    
    .sidebar::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 10px;
    }
    
    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.5);
    }
    </style>
</head>
<body>
    <!-- Overlay pour mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="app-container">
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
                <small>Emploi du Temps Complet</small>
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
                    <div class="nav-section-title">Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste Étudiants</span>
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
                    <a href="emploi_du_temps_complet.php" class="nav-link active">
                        <i class="fas fa-calendar-week"></i>
                        <span>Emploi du Temps Complet</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Rapports</div>
                    <a href="rapports_presence.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports de Présence</span>
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
        <div class="main-content" id="mainContent">
            <!-- En-tête mobile -->
            <div class="mobile-header d-lg-none">
                <button class="hamburger-btn" id="hamburgerBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <h1>
                    <i class="fas fa-calendar-week me-2"></i>
                    Emploi du Temps Complet
                </h1>
                <div class="mobile-header-buttons">
                    <button class="btn btn-sm btn-light" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            
            <!-- En-tête desktop -->
            <div class="content-header mb-4 d-none d-lg-block">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-calendar-week me-2"></i>
                            Emploi du Temps Complet
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-building"></i> 
                            Site: <?php echo htmlspecialchars($_SESSION['site_name'] ?? 'Non spécifié'); ?> - 
                            <i class="fas fa-calendar-day"></i> 
                            <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="imprimerEmploi()">
                            <i class="fas fa-print d-none d-md-inline"></i> 
                            <span class="d-inline d-md-none"><i class="fas fa-print"></i></span>
                            <span class="d-none d-md-inline">Imprimer</span>
                        </button>
                        <button class="btn btn-success" onclick="exporterExcel()">
                            <i class="fas fa-file-excel d-none d-md-inline"></i> 
                            <span class="d-inline d-md-none"><i class="fas fa-file-excel"></i></span>
                            <span class="d-none d-md-inline">Excel</span>
                        </button>
                        <button class="btn btn-secondary" onclick="window.location.href='emploi_du_temps.php'">
                            <i class="fas fa-arrow-left d-none d-md-inline"></i> 
                            <span class="d-inline d-md-none"><i class="fas fa-arrow-left"></i></span>
                            <span class="d-none d-md-inline">Retour</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques -->
            <div class="row mb-4">
                <div class="col-6 col-md-3 mb-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['total_cours']; ?></div>
                        <div class="stat-label">Cours Programmes</div>
                    </div>
                </div>
                
                <div class="col-6 col-md-3 mb-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['cours_aujourdhui']; ?></div>
                        <div class="stat-label">Cours Aujourd'hui</div>
                    </div>
                </div>
                
                <div class="col-6 col-md-3 mb-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['classes_actives']; ?></div>
                        <div class="stat-label">Classes Actives</div>
                    </div>
                </div>
                
                <div class="col-6 col-md-3 mb-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['enseignants_actifs']; ?></div>
                        <div class="stat-label">Enseignants Actifs</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Filtres -->
            <div class="filter-card">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>
                    Filtres
                </h5>
                <form method="GET" action="" class="row g-2">
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Jour de la semaine</label>
                        <select class="form-select" name="jour">
                            <option value="">Tous les jours</option>
                            <option value="Lundi" <?php echo $filtre_jour == 'Lundi' ? 'selected' : ''; ?>>Lundi</option>
                            <option value="Mardi" <?php echo $filtre_jour == 'Mardi' ? 'selected' : ''; ?>>Mardi</option>
                            <option value="Mercredi" <?php echo $filtre_jour == 'Mercredi' ? 'selected' : ''; ?>>Mercredi</option>
                            <option value="Jeudi" <?php echo $filtre_jour == 'Jeudi' ? 'selected' : ''; ?>>Jeudi</option>
                            <option value="Vendredi" <?php echo $filtre_jour == 'Vendredi' ? 'selected' : ''; ?>>Vendredi</option>
                            <option value="Samedi" <?php echo $filtre_jour == 'Samedi' ? 'selected' : ''; ?>>Samedi</option>
                        </select>
                    </div>
                    
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Classe</label>
                        <select class="form-select" name="classe">
                            <option value="">Toutes les classes</option>
                            <?php foreach($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $filtre_classe == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom']); ?> 
                                (<?php echo $classe['nombre_cours']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Enseignant</label>
                        <select class="form-select" name="enseignant">
                            <option value="">Tous les enseignants</option>
                            <?php foreach($enseignants as $enseignant): ?>
                            <option value="<?php echo $enseignant['id']; ?>" <?php echo $filtre_enseignant == $enseignant['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($enseignant['nom_complet']); ?>
                                (<?php echo $enseignant['nombre_cours']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Matière</label>
                        <select class="form-select" name="matiere">
                            <option value="">Toutes les matières</option>
                            <?php foreach($matieres as $matiere): ?>
                            <option value="<?php echo $matiere['id']; ?>" <?php echo $filtre_matiere == $matiere['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($matiere['nom']); ?>
                                (<?php echo $matiere['nombre_cours']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Salle</label>
                        <select class="form-select" name="salle">
                            <option value="">Toutes les salles</option>
                            <?php foreach($salles as $salle): ?>
                            <option value="<?php echo htmlspecialchars($salle['salle']); ?>" <?php echo $filtre_salle == $salle['salle'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($salle['salle']); ?>
                                (<?php echo $salle['nombre_cours']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-12 col-md-6 col-lg-4 d-flex align-items-end">
                        <div class="w-100">
                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                <i class="fas fa-search me-1"></i>Filtrer
                            </button>
                            <a href="emploi_du_temps_complet.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-times me-1"></i>Réinitialiser
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Section 3: Emploi du Temps Complet -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Emploi du Temps Complet
                        <?php if($filtre_jour): ?>
                        <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($filtre_jour); ?></span>
                        <?php endif; ?>
                    </h5>
                    <span class="badge bg-primary">
                        <?php echo count($emploi_du_temps); ?> cours
                    </span>
                </div>
                <div class="card-body">
                    <?php if(empty($emploi_du_temps)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Aucun cours programmé avec les filtres actuels.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Jour</th>
                                    <th>Horaire</th>
                                    <th>Classe</th>
                                    <th>Matière</th>
                                    <th>Enseignant</th>
                                    <th>Salle</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Grouper par jour pour un meilleur affichage
                                $cours_par_jour = [];
                                foreach($emploi_du_temps as $cours) {
                                    $jour = $cours['jour_semaine'];
                                    if (!isset($cours_par_jour[$jour])) {
                                        $cours_par_jour[$jour] = [];
                                    }
                                    $cours_par_jour[$jour][] = $cours;
                                }
                                
                                // Trier les jours dans l'ordre
                                $jours_ordre = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                                
                                foreach($jours_ordre as $jour):
                                    if (isset($cours_par_jour[$jour])):
                                        // Trier les cours de ce jour par heure
                                        usort($cours_par_jour[$jour], function($a, $b) {
                                            return strcmp($a['heure_debut'], $b['heure_debut']);
                                        });
                                        
                                        foreach($cours_par_jour[$jour] as $cours): 
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge-jour badge-<?php echo strtolower($jour); ?>">
                                            <?php echo $jour; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo date('H:i', strtotime($cours['heure_debut'])); ?></strong> - 
                                        <?php echo date('H:i', strtotime($cours['heure_fin'])); ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($cours['classe_nom']); ?></div>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($cours['filiere_nom'] ?? ''); ?> - 
                                            <?php echo htmlspecialchars($cours['niveau_libelle'] ?? ''); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($cours['matiere_nom']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($cours['matiere_code']); ?></small>
                                    </td>
                                    <td>
                                        <?php if($cours['enseignant_nom']): ?>
                                        <div><?php echo htmlspecialchars($cours['enseignant_nom']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($cours['enseignant_matricule'] ?? ''); ?></small>
                                        <?php else: ?>
                                        <span class="text-muted">Non assigné</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($cours['salle']): ?>
                                        <span class="badge-salle"><?php echo htmlspecialchars($cours['salle']); ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">Non spécifié</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="voirDetailsCours(<?php echo $cours['id']; ?>)" 
                                                    title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-success" 
                                                    onclick="verifierPresences(<?php echo $cours['classe_id']; ?>, <?php echo $cours['matiere_id']; ?>)" 
                                                    title="Vérifier présences">
                                                <i class="fas fa-clipboard-check"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                        endforeach;
                                    endif;
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistiques par jour -->
            <?php if(isset($cours_par_jour) && !empty($cours_par_jour)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i>
                        Répartition par jour
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        $jours_ordre = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                        $total_cours = $statistiques['total_cours'];
                        ?>
                        
                        <?php foreach($jours_ordre as $jour): ?>
                        <?php 
                        $nombre_cours = isset($cours_par_jour[$jour]) ? count($cours_par_jour[$jour]) : 0;
                        $pourcentage = $total_cours > 0 ? ($nombre_cours / $total_cours) * 100 : 0;
                        ?>
                        <div class="col-4 col-md-2 mb-3">
                            <div class="text-center">
                                <div class="mb-2">
                                    <span class="badge-jour badge-<?php echo strtolower($jour); ?>">
                                        <?php echo $jour; ?>
                                    </span>
                                </div>
                                <div class="stat-value"><?php echo $nombre_cours; ?></div>
                                <div class="stat-label mb-2">cours</div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" 
                                         style="width: <?php echo $pourcentage; ?>%;"
                                         role="progressbar"
                                         aria-valuenow="<?php echo $pourcentage; ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted mt-1"><?php echo round($pourcentage, 1); ?>%</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Pied de page -->
            <div class="text-center text-muted mt-4">
                <small>
                    <i class="fas fa-clock me-1"></i>
                    Dernière mise à jour : <?php echo date('d/m/Y H:i:s'); ?>
                </small>
            </div>
        </div>
    </div>
    
    <!-- Modal pour détails du cours -->
    <div class="modal fade" id="coursDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails du Cours</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="coursDetailContent">
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
    
    <script>
    // Variables globales pour le menu mobile
    let sidebarOpen = false;
    
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
    
    // Fonction pour ouvrir/fermer le sidebar mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.getElementById('mainContent');
        
        if (!sidebarOpen) {
            // Ouvrir le sidebar
            sidebar.classList.add('show');
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden'; // Empêcher le scroll
            sidebarOpen = true;
        } else {
            // Fermer le sidebar
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = ''; // Réactiver le scroll
            sidebarOpen = false;
        }
    }
    
    // Fermer le sidebar lors du clic sur l'overlay
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        toggleSidebar();
    });
    
    // Fermer le sidebar lors du clic sur un lien (pour mobile)
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                toggleSidebar();
            }
        });
    });
    
    // Gérer le redimensionnement de la fenêtre
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (window.innerWidth >= 992) {
            // Desktop: toujours afficher le sidebar
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
            sidebarOpen = false;
        } else {
            // Mobile: s'assurer que le sidebar est fermé par défaut
            if (sidebarOpen) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
                sidebarOpen = false;
            }
        }
    });
    
    // Initialiser le thème et les événements
    document.addEventListener('DOMContentLoaded', function() {
        // Récupérer le thème sauvegardé ou utiliser 'light' par défaut
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Mettre à jour le bouton de thème
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Initialiser le bouton hamburger
        document.getElementById('hamburgerBtn').addEventListener('click', toggleSidebar);
        
        // Ajouter un listener pour les touches ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebarOpen) {
                toggleSidebar();
            }
        });
    });
    
    // Voir les détails d'un cours
    function voirDetailsCours(coursId) {
        // Pour l'instant, affichons une alerte
        alert('Détails du cours ID: ' + coursId + '\nCette fonctionnalité sera implémentée bientôt.');
        
        // Version future avec AJAX:
        /*
        fetch('ajax/get_cours_detail.php?id=' + coursId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('coursDetailContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('coursDetailModal'));
                modal.show();
            })
            .catch(error => {
                alert('Erreur de chargement des détails');
                console.error('Erreur:', error);
            });
        */
    }
    
    // Vérifier les présences pour un cours
    function verifierPresences(classeId, matiereId) {
        window.location.href = 'classe_presence.php?classe_id=' + classeId + '&matiere_id=' + matiereId;
    }
    
    // Imprimer l'emploi du temps
    function imprimerEmploi() {
        const filtreClasse = <?php echo json_encode($filtre_classe); ?>;
        const filtreEnseignant = <?php echo json_encode($filtre_enseignant); ?>;
        const filtreJour = <?php echo json_encode($filtre_jour); ?>;
        const filtreMatiere = <?php echo json_encode($filtre_matiere); ?>;
        const filtreSalle = <?php echo json_encode($filtre_salle); ?>;
        
        let url = 'ajax/imprimer_emploi_complet.php?site_id=<?php echo $site_id; ?>';
        
        if (filtreClasse > 0) url += '&classe=' + filtreClasse;
        if (filtreEnseignant > 0) url += '&enseignant=' + filtreEnseignant;
        if (filtreMatiere > 0) url += '&matiere=' + filtreMatiere;
        if (filtreJour) url += '&jour=' + encodeURIComponent(filtreJour);
        if (filtreSalle) url += '&salle=' + encodeURIComponent(filtreSalle);
        
        window.open(url, '_blank');
    }
    
    // Exporter en Excel
    function exporterExcel() {
        const filtreClasse = <?php echo json_encode($filtre_classe); ?>;
        const filtreEnseignant = <?php echo json_encode($filtre_enseignant); ?>;
        const filtreJour = <?php echo json_encode($filtre_jour); ?>;
        const filtreMatiere = <?php echo json_encode($filtre_matiere); ?>;
        const filtreSalle = <?php echo json_encode($filtre_salle); ?>;
        
        let url = 'ajax/exporter_emploi_complet.php?site_id=<?php echo $site_id; ?>&format=excel';
        
        if (filtreClasse > 0) url += '&classe=' + filtreClasse;
        if (filtreEnseignant > 0) url += '&enseignant=' + filtreEnseignant;
        if (filtreMatiere > 0) url += '&matiere=' + filtreMatiere;
        if (filtreJour) url += '&jour=' + encodeURIComponent(filtreJour);
        if (filtreSalle) url += '&salle=' + encodeURIComponent(filtreSalle);
        
        window.location.href = url;
    }
    
    // Recherche rapide dans la page
    function rechercherDansPage() {
        const terme = prompt('Rechercher (classe, matière, enseignant, salle):');
        if (terme) {
            const lignes = document.querySelectorAll('.table tbody tr');
            let trouves = 0;
            
            lignes.forEach(ligne => {
                const texte = ligne.textContent.toLowerCase();
                if (texte.includes(terme.toLowerCase())) {
                    ligne.style.backgroundColor = '#fff3cd';
                    ligne.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    trouves++;
                } else {
                    ligne.style.backgroundColor = '';
                }
            });
            
            if (trouves > 0) {
                alert(trouves + ' résultat(s) trouvé(s)');
            } else {
                alert('Aucun résultat trouvé');
            }
        }
    }
    
    // Rafraîchir la page
    function rafraichirPage() {
        location.reload();
    }
    
    // Touche F5 pour rafraîchir
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F5') {
            e.preventDefault();
            rafraichirPage();
        }
    });
    </script>
</body>
</html>