<?php
// dashboard/surveillant/emploi_du_temps.php

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
$pageTitle = "Surveillant Général - Emploi du Temps";

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

function getJourSemaine($date) {
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $timestamp = strtotime($date);
    return $jours[date('w', $timestamp)];
}

// Initialiser les variables
$emploi_du_temps = [];
$classes = [];
$matieres = [];
$enseignants = [];
$salles = [];
$statistiques = [
    'total_cours' => 0,
    'cours_aujourdhui' => 0,
    'classes_actives' => 0
];
$cours_aujourdhui = [];
$cours_semaine = [];

// Date d'aujourd'hui
$aujourdhui = date('Y-m-d');
$jour_semaine_aujourdhui = getJourSemaine($aujourdhui);
$debut_semaine = date('Y-m-d', strtotime('monday this week'));
$fin_semaine = date('Y-m-d', strtotime('sunday this week'));

// Gérer les paramètres de filtrage
$filtre_classe = isset($_GET['classe']) ? intval($_GET['classe']) : 0;
$filtre_enseignant = isset($_GET['enseignant']) ? intval($_GET['enseignant']) : 0;
$filtre_jour = isset($_GET['jour']) ? $_GET['jour'] : '';

try {
    // 1. Récupérer l'emploi du temps
    $query = "SELECT 
                edt.*,
                c.nom as classe_nom,
                m.nom as matiere_nom,
                m.code as matiere_code,
                CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                e.matricule as enseignant_matricule
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    
    $params = [':site_id' => $site_id];
    
    if ($filtre_classe > 0) {
        $query .= " AND edt.classe_id = :classe_id";
        $params[':classe_id'] = $filtre_classe;
    }
    
    if ($filtre_enseignant > 0) {
        $query .= " AND edt.enseignant_id = :enseignant_id";
        $params[':enseignant_id'] = $filtre_enseignant;
    }
    
    if (!empty($filtre_jour)) {
        $query .= " AND edt.jour_semaine = :jour_semaine";
        $params[':jour_semaine'] = $filtre_jour;
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
    
    // 3. Récupérer les cours d'aujourd'hui
    $query = "SELECT 
                edt.*,
                c.nom as classe_nom,
                m.nom as matiere_nom,
                CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.jour_semaine = :jour_semaine
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              ORDER BY edt.heure_debut";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':jour_semaine' => $jour_semaine_aujourdhui
    ]);
    $cours_aujourdhui = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Récupérer les cours de la semaine
    $query = "SELECT 
                edt.jour_semaine,
                COUNT(*) as nombre_cours,
                GROUP_CONCAT(DISTINCT c.nom SEPARATOR ', ') as classes
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              GROUP BY edt.jour_semaine
              ORDER BY 
                CASE edt.jour_semaine 
                    WHEN 'Lundi' THEN 1
                    WHEN 'Mardi' THEN 2
                    WHEN 'Mercredi' THEN 3
                    WHEN 'Jeudi' THEN 4
                    WHEN 'Vendredi' THEN 5
                    WHEN 'Samedi' THEN 6
                    ELSE 7
                END";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $cours_semaine = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Récupérer les classes pour le filtre
    $query = "SELECT DISTINCT 
                c.id,
                c.nom,
                f.nom as filiere_nom,
                n.libelle as niveau_libelle
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              ORDER BY c.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Récupérer les enseignants pour le filtre
    $query = "SELECT DISTINCT 
                e.id,
                CONCAT(u.nom, ' ', u.prenom) as nom_complet,
                e.matricule
              FROM emploi_du_temps edt
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.enseignant_id IS NOT NULL
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              ORDER BY u.nom, u.prenom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Récupérer les matières
    $query = "SELECT DISTINCT 
                m.id,
                m.nom,
                m.code
              FROM emploi_du_temps edt
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              WHERE edt.site_id = :site_id 
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              ORDER BY m.nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 8. Récupérer les salles utilisées
    $query = "SELECT DISTINCT salle 
              FROM emploi_du_temps 
              WHERE site_id = :site_id 
                AND salle IS NOT NULL 
                AND salle != ''
                AND annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )
              ORDER BY salle";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $salles = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
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
    
    /* Emploi du temps spécifique */
    .cours-card {
        border-left: 4px solid var(--primary-color);
        margin-bottom: 10px;
        transition: all 0.3s;
    }
    
    .cours-card:hover {
        transform: translateX(5px);
        box-shadow: 0 3px 10px rgba(0,0,0,0.1);
    }
    
    .cours-heure {
        font-weight: bold;
        color: var(--primary-color);
    }
    
    .cours-salle {
        color: var(--success-color);
        font-weight: 500;
    }
    
    .jour-table {
        background: var(--card-bg);
        border-radius: 8px;
        overflow: hidden;
    }
    
    .jour-header {
        background-color: var(--primary-color);
        color: white;
        padding: 15px;
        font-weight: bold;
        text-align: center;
    }
    
    .cours-list {
        padding: 15px;
        max-height: 400px;
        overflow-y: auto;
    }
    
    /* Calendar */
    .fc {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc-toolbar-title {
        color: var(--text-color) !important;
    }
    
    .fc-col-header-cell-cushion {
        color: var(--text-color) !important;
    }
    
    .fc-daygrid-day-number {
        color: var(--text-color) !important;
    }
    
    .fc-daygrid-day.fc-day-today {
        background-color: rgba(52, 152, 219, 0.1) !important;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    /* Timeline */
    .timeline {
        position: relative;
        padding: 20px 0;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 50%;
        top: 0;
        bottom: 0;
        width: 2px;
        background: var(--primary-color);
        transform: translateX(-50%);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 30px;
    }
    
    .timeline-item:nth-child(odd) {
        padding-right: calc(50% + 20px);
        text-align: right;
    }
    
    .timeline-item:nth-child(even) {
        padding-left: calc(50% + 20px);
        text-align: left;
    }
    
    .timeline-dot {
        position: absolute;
        top: 0;
        width: 20px;
        height: 20px;
        background: var(--primary-color);
        border-radius: 50%;
        left: 50%;
        transform: translateX(-50%);
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
                <small>Emploi du Temps</small>
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
                    <a href="emploi_du_temps.php" class="nav-link active">
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
                            <i class="fas fa-calendar-alt me-2"></i>
                            Emploi du Temps
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-building"></i> 
                            Site: <?php echo $_SESSION['site_name'] ?? 'Non spécifié'; ?> - 
                            <i class="fas fa-calendar-day"></i> 
                            Semaine du <?php echo date('d/m/Y', strtotime('monday this week')); ?> au <?php echo date('d/m/Y', strtotime('sunday this week')); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="imprimerEmploi()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="exporterEmploi()">
                            <i class="fas fa-file-export"></i> Exporter
                        </button>
                        <button class="btn btn-secondary" onclick="location.reload()">
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
            
            <!-- Section 1: Statistiques -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['total_cours']; ?></div>
                        <div class="stat-label">Cours Programmes</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['cours_aujourdhui']; ?></div>
                        <div class="stat-label">Cours Aujourd'hui</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo $statistiques['classes_actives']; ?></div>
                        <div class="stat-label">Classes Actives</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo count($enseignants); ?></div>
                        <div class="stat-label">Enseignants</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Filtres -->
            <div class="filter-card">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>
                    Filtres
                </h5>
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Classe</label>
                        <select class="form-select" name="classe">
                            <option value="">Toutes les classes</option>
                            <?php foreach($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $filtre_classe == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom']); ?> 
                                (<?php echo htmlspecialchars($classe['niveau_libelle']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Enseignant</label>
                        <select class="form-select" name="enseignant">
                            <option value="">Tous les enseignants</option>
                            <?php foreach($enseignants as $enseignant): ?>
                            <option value="<?php echo $enseignant['id']; ?>" <?php echo $filtre_enseignant == $enseignant['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($enseignant['nom_complet']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Jour</label>
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
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Filtrer
                        </button>
                        <a href="emploi_du_temps.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Section 3: Cours d'Aujourd'hui -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar-day me-2"></i>
                                Cours d'Aujourd'hui (<?php echo $jour_semaine_aujourdhui . ' ' . date('d/m/Y'); ?>)
                            </h5>
                            <span class="badge bg-primary">
                                <?php echo count($cours_aujourdhui); ?> cours
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if(empty($cours_aujourdhui)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                Aucun cours programmé pour aujourd'hui.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php 
                                $cours_par_heure = [];
                                foreach($cours_aujourdhui as $cours) {
                                    $heure = date('H:i', strtotime($cours['heure_debut']));
                                    $cours_par_heure[$heure][] = $cours;
                                }
                                ksort($cours_par_heure);
                                ?>
                                
                                <?php foreach($cours_par_heure as $heure => $cours_list): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="cours-card card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="cours-heure mb-0">
                                                    <i class="fas fa-clock me-2"></i>
                                                    <?php echo $heure; ?> - 
                                                    <?php echo date('H:i', strtotime($cours_list[0]['heure_fin'])); ?>
                                                </h6>
                                                <span class="cours-salle">
                                                    <i class="fas fa-door-open"></i>
                                                    <?php echo htmlspecialchars($cours_list[0]['salle'] ?? 'Non spécifié'); ?>
                                                </span>
                                            </div>
                                            
                                            <?php foreach($cours_list as $cours): ?>
                                            <div class="mb-2">
                                                <strong><?php echo htmlspecialchars($cours['classe_nom']); ?></strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-book me-1"></i>
                                                    <?php echo htmlspecialchars($cours['matiere_nom']); ?>
                                                </div>
                                                <div class="text-muted small">
                                                    <i class="fas fa-user-tie me-1"></i>
                                                    <?php echo htmlspecialchars($cours['enseignant_nom'] ?? 'Non assigné'); ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="mt-3">
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirPresencesClasse(<?php echo $cours_list[0]['classe_id']; ?>, '<?php echo $cours_list[0]['matiere_id']; ?>')">
                                                    <i class="fas fa-clipboard-check"></i> Vérifier présence
                                                </button>
                                            </div>
                                        </div>
                                    </div>
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
                                <i class="fas fa-chart-bar me-2"></i>
                                Répartition Hebdomadaire
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <?php 
                                $jours_ordre = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                                $cours_semaine_assoc = [];
                                foreach($cours_semaine as $cs) {
                                    $cours_semaine_assoc[$cs['jour_semaine']] = $cs;
                                }
                                ?>
                                
                                <?php foreach($jours_ordre as $jour): ?>
                                <?php $cours = $cours_semaine_assoc[$jour] ?? null; ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $jour; ?></strong>
                                        <?php if($cours): ?>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo $cours['classes']; ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">
                                        <?php echo $cours ? $cours['nombre_cours'] : 0; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mt-3">
                                <div class="progress" style="height: 20px;">
                                    <?php 
                                    $total_cours_semaine = array_sum(array_column($cours_semaine, 'nombre_cours'));
                                    $max_cours = $total_cours_semaine > 0 ? max(array_column($cours_semaine, 'nombre_cours')) : 0;
                                    
                                    foreach($jours_ordre as $jour):
                                        $nb_cours = $cours_semaine_assoc[$jour]['nombre_cours'] ?? 0;
                                        $pourcentage = $max_cours > 0 ? ($nb_cours / $max_cours) * 100 : 0;
                                    ?>
                                    <div class="progress-bar" style="width: <?php echo $pourcentage; ?>%;" 
                                         title="<?php echo $jour; ?>: <?php echo $nb_cours; ?> cours">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted d-block mt-2 text-center">
                                    Distribution des cours sur la semaine
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Emploi du Temps Complet -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-week me-2"></i>
                        Emploi du Temps Complet
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($emploi_du_temps)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Aucun cours programmé avec les filtres actuels.
                    </div>
                    <?php else: ?>
                    <!-- Onglets par jour -->
                    <ul class="nav nav-tabs mb-3" id="jourTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="lundi-tab" data-bs-toggle="tab" data-bs-target="#lundi">
                                Lundi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="mardi-tab" data-bs-toggle="tab" data-bs-target="#mardi">
                                Mardi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="mercredi-tab" data-bs-toggle="tab" data-bs-target="#mercredi">
                                Mercredi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="jeudi-tab" data-bs-toggle="tab" data-bs-target="#jeudi">
                                Jeudi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="vendredi-tab" data-bs-toggle="tab" data-bs-target="#vendredi">
                                Vendredi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="samedi-tab" data-bs-toggle="tab" data-bs-target="#samedi">
                                Samedi
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="jourTabsContent">
                        <?php 
                        $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                        foreach($jours as $index => $jour):
                            $cours_du_jour = array_filter($emploi_du_temps, function($c) use ($jour) {
                                return $c['jour_semaine'] == $jour;
                            });
                        ?>
                        <div class="tab-pane fade <?php echo $index == 0 ? 'show active' : ''; ?>" id="<?php echo strtolower($jour); ?>">
                            <?php if(empty($cours_du_jour)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                Aucun cours programmé le <?php echo $jour; ?>.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
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
                                        // Trier par heure
                                        usort($cours_du_jour, function($a, $b) {
                                            return strtotime($a['heure_debut']) - strtotime($b['heure_debut']);
                                        });
                                        
                                        foreach($cours_du_jour as $cours): 
                                        ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php echo date('H:i', strtotime($cours['heure_debut'])); ?> - 
                                                    <?php echo date('H:i', strtotime($cours['heure_fin'])); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cours['classe_nom']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($cours['matiere_nom']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($cours['matiere_code']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($cours['enseignant_nom'] ?? 'Non assigné'); ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($cours['salle'] ?? 'Non spécifié'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirDetailsCours(<?php echo $cours['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-success" 
                                                        onclick="voirPresencesCours(<?php echo $cours['id']; ?>)">
                                                    <i class="fas fa-clipboard-check"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Section 5: Calendrier des Cours -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Calendrier des Cours
                    </h5>
                </div>
                <div class="card-body">
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour détails de cours -->
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
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js"></script>
    
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
        
        // Initialiser le calendrier
        initializeCalendar();
        
        // Initialiser les onglets
        initializeTabs();
    });
    
    // Initialiser le calendrier FullCalendar
    function initializeCalendar() {
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;
        
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: 'ajax/get_cours_calendar.php?site_id=<?php echo $site_id; ?>',
            eventClick: function(info) {
                voirDetailsCours(info.event.id);
            },
            eventColor: '#3498db',
            eventTextColor: '#ffffff',
            height: 500,
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5, 6], // Lundi à Samedi
                startTime: '08:00',
                endTime: '20:00'
            }
        });
        
        calendar.render();
    }
    
    // Initialiser les onglets
    function initializeTabs() {
        // Activer l'onglet du jour actuel
        const aujourdhui = '<?php echo strtolower($jour_semaine_aujourdhui); ?>';
        const tabAujourdhui = document.getElementById(aujourdhui + '-tab');
        if (tabAujourdhui) {
            tabAujourdhui.click();
        }
    }
    
    // Voir les détails d'un cours
    function voirDetailsCours(coursId) {
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
    }
    
    // Vérifier les présences pour un cours
    function voirPresencesCours(coursId) {
        window.location.href = 'classe_presence.php?cours_id=' + coursId;
    }
    
    // Vérifier les présences pour une classe et une matière
    function voirPresencesClasse(classeId, matiereId) {
        window.location.href = 'classe_presence.php?classe_id=' + classeId + '&matiere_id=' + matiereId;
    }
    
    // Imprimer l'emploi du temps
    function imprimerEmploi() {
        const filtreClasse = <?php echo $filtre_classe; ?>;
        const filtreEnseignant = <?php echo $filtre_enseignant; ?>;
        const filtreJour = '<?php echo $filtre_jour; ?>';
        
        let url = 'ajax/imprimer_emploi.php?site_id=<?php echo $site_id; ?>';
        
        if (filtreClasse > 0) url += '&classe=' + filtreClasse;
        if (filtreEnseignant > 0) url += '&enseignant=' + filtreEnseignant;
        if (filtreJour) url += '&jour=' + encodeURIComponent(filtreJour);
        
        window.open(url, '_blank');
    }
    
    // Exporter l'emploi du temps
    function exporterEmploi() {
        const filtreClasse = <?php echo $filtre_classe; ?>;
        const filtreEnseignant = <?php echo $filtre_enseignant; ?>;
        const filtreJour = '<?php echo $filtre_jour; ?>';
        
        let url = 'ajax/exporter_emploi.php?site_id=<?php echo $site_id; ?>&format=excel';
        
        if (filtreClasse > 0) url += '&classe=' + filtreClasse;
        if (filtreEnseignant > 0) url += '&enseignant=' + filtreEnseignant;
        if (filtreJour) url += '&jour=' + encodeURIComponent(filtreJour);
        
        window.location.href = url;
    }
    
    // Auto-refresh pour vérifier les changements
    setInterval(function() {
        // Vous pourriez ajouter ici une vérification périodique
        // pour les changements dans l'emploi du temps
        console.log('Vérification des mises à jour de l\'emploi du temps');
    }, 60000); // Toutes les minutes
    </script>
</body>
</html>