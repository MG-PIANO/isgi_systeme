<?php
// dashboard/dac/calendrier_academique.php

// ============================================
// 1. INITIALISATION
// ============================================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Vérifier que l'utilisateur est connecté et est un DAC (role_id = 5)
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 5) {
    $root_path = dirname(dirname(dirname(__DIR__)));
    header("Location: $root_path/auth/login.php");
    exit();
}

// ============================================
// 2. CONFIGURATION BASE DE DONNÉES
// ============================================
// Essayer plusieurs chemins pour trouver le fichier de configuration
$database_found = false;
$db = null;

// Chemins possibles pour le fichier database.php
$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/config/database.php',
    dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php',
    '../../../../config/database.php',
    '../../../config/database.php',
    '../config/database.php',
    'config/database.php'
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $database_found = true;
        break;
    }
}

// Si le fichier n'est pas trouvé, créer une connexion directe
if (!$database_found) {
    try {
        $db = new PDO(
            'mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4',
            'root',
            'admin1234',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (PDOException $e) {
        die("<div style='padding:20px;background:#f8d7da;color:#721c24;border-radius:5px;'>
            <h3>Erreur de connexion à la base de données</h3>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
            <p>Vérifiez votre configuration dans config/database.php</p>
        </div>");
    }
} else {
    // Utiliser la classe Database si elle existe
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
    }
}

// ============================================
// 3. FONCTIONS UTILITAIRES
// ============================================
function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }
    
    try {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        return date($format, $timestamp);
    } catch (Exception $e) {
        return '';
    }
}

function getStatutBadge($statut) {
    $badges = [
        'planifie' => 'warning',
        'en_cours' => 'info',
        'termine' => 'success',
        'annule' => 'danger'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    $libelles = [
        'planifie' => 'Planifié',
        'en_cours' => 'En cours',
        'termine' => 'Terminé',
        'annule' => 'Annulé'
    ];
    
    $libelle = $libelles[$statut] ?? ucfirst($statut);
    return '<span class="badge bg-' . $color . '">' . $libelle . '</span>';
}

// ============================================
// 4. TRAITEMENT DES DONNÉES
// ============================================
$pageTitle = "Calendrier Académique";
$action = $_GET['action'] ?? 'list';
$calendrier_id = $_GET['id'] ?? null;
$annee_id = $_GET['annee_id'] ?? null;
$message = null;
$message_type = null;
$error = null;
$calendriers = [];
$annees = [];
$calendrier = null;
$site_nom = 'ISGI';

try {
    $site_id = $_SESSION['site_id'] ?? 1;
    
    // Récupérer le nom du site
    $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    $_SESSION['site_name'] = $site['nom'] ?? 'ISGI';
    $site_nom = $_SESSION['site_name'];
    
    // Traitement des formulaires POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['ajouter_calendrier'])) {
            // Ajouter un nouveau calendrier
            $query = "INSERT INTO calendrier_academique 
                     (site_id, annee_academique_id, semestre, type_rentree, 
                      date_debut_cours, date_fin_cours, date_debut_dst, date_fin_dst,
                      date_debut_recherche, date_fin_recherche, date_debut_conge_etude,
                      date_fin_conge_etude, date_debut_examens, date_fin_examens,
                      date_reprise_cours, date_debut_stage, date_fin_stage,
                      statut, observations, publie, cree_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                $site_id,
                $_POST['annee_academique_id'],
                $_POST['semestre'],
                $_POST['type_rentree'],
                $_POST['date_debut_cours'],
                $_POST['date_fin_cours'],
                $_POST['date_debut_dst'] ?: null,
                $_POST['date_fin_dst'] ?: null,
                $_POST['date_debut_recherche'] ?: null,
                $_POST['date_fin_recherche'] ?: null,
                $_POST['date_debut_conge_etude'] ?: null,
                $_POST['date_fin_conge_etude'] ?: null,
                $_POST['date_debut_examens'] ?: null,
                $_POST['date_fin_examens'] ?: null,
                $_POST['date_reprise_cours'] ?: null,
                $_POST['date_debut_stage'] ?: null,
                $_POST['date_fin_stage'] ?: null,
                $_POST['statut'],
                $_POST['observations'] ?: null,
                isset($_POST['publie']) ? 1 : 0,
                $_SESSION['user_id']
            ]);
            
            if ($result) {
                $message = "Calendrier académique ajouté avec succès";
                $message_type = "success";
            } else {
                $error = "Erreur lors de l'ajout du calendrier";
            }
            
        } elseif (isset($_POST['modifier_calendrier'])) {
            // Modifier un calendrier existant
            $query = "UPDATE calendrier_academique 
                     SET annee_academique_id = ?,
                         semestre = ?,
                         type_rentree = ?,
                         date_debut_cours = ?,
                         date_fin_cours = ?,
                         date_debut_dst = ?,
                         date_fin_dst = ?,
                         date_debut_recherche = ?,
                         date_fin_recherche = ?,
                         date_debut_conge_etude = ?,
                         date_fin_conge_etude = ?,
                         date_debut_examens = ?,
                         date_fin_examens = ?,
                         date_reprise_cours = ?,
                         date_debut_stage = ?,
                         date_fin_stage = ?,
                         statut = ?,
                         observations = ?,
                         publie = ?,
                         modifie_par = ?,
                         date_modification = NOW()
                     WHERE id = ? AND site_id = ?";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                $_POST['annee_academique_id'],
                $_POST['semestre'],
                $_POST['type_rentree'],
                $_POST['date_debut_cours'],
                $_POST['date_fin_cours'],
                $_POST['date_debut_dst'] ?: null,
                $_POST['date_fin_dst'] ?: null,
                $_POST['date_debut_recherche'] ?: null,
                $_POST['date_fin_recherche'] ?: null,
                $_POST['date_debut_conge_etude'] ?: null,
                $_POST['date_fin_conge_etude'] ?: null,
                $_POST['date_debut_examens'] ?: null,
                $_POST['date_fin_examens'] ?: null,
                $_POST['date_reprise_cours'] ?: null,
                $_POST['date_debut_stage'] ?: null,
                $_POST['date_fin_stage'] ?: null,
                $_POST['statut'],
                $_POST['observations'] ?: null,
                isset($_POST['publie']) ? 1 : 0,
                $_SESSION['user_id'],
                $_POST['calendrier_id'],
                $site_id
            ]);
            
            if ($result) {
                $message = "Calendrier académique modifié avec succès";
                $message_type = "success";
            } else {
                $error = "Erreur lors de la modification du calendrier";
            }
            
        } elseif (isset($_POST['supprimer_calendrier'])) {
            // Supprimer un calendrier
            $query = "DELETE FROM calendrier_academique WHERE id = ? AND site_id = ?";
            $stmt = $db->prepare($query);
            $result = $stmt->execute([$_POST['calendrier_id'], $site_id]);
            
            if ($result) {
                $message = "Calendrier académique supprimé avec succès";
                $message_type = "success";
            } else {
                $error = "Erreur lors de la suppression du calendrier";
            }
        }
    }
    
    // Récupérer les années académiques
    $query = "SELECT * FROM annees_academiques WHERE site_id = ? ORDER BY date_debut DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $annees = $stmt->fetchAll();
    
    // Récupérer les calendriers académiques
    $query_params = [$site_id];
    $query = "SELECT ca.*, aa.libelle as annee_libelle,
                     CONCAT(u.nom, ' ', u.prenom) as createur_nom
              FROM calendrier_academique ca
              JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
              LEFT JOIN utilisateurs u ON ca.cree_par = u.id
              WHERE ca.site_id = ?";
    
    if ($annee_id) {
        $query .= " AND ca.annee_academique_id = ?";
        $query_params[] = $annee_id;
    }
    
    $query .= " ORDER BY ca.date_debut_cours DESC, ca.semestre";
    
    $stmt = $db->prepare($query);
    $stmt->execute($query_params);
    $calendriers = $stmt->fetchAll();
    
    // Récupérer un calendrier spécifique pour modification
    if ($calendrier_id && $action == 'edit') {
        $query = "SELECT ca.*, aa.libelle as annee_libelle
                 FROM calendrier_academique ca
                 JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                 WHERE ca.id = ? AND ca.site_id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$calendrier_id, $site_id]);
        $calendrier = $stmt->fetch();
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// ============================================
// 5. AFFICHAGE DE LA PAGE
// ============================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendrier Académique - Tableau de Bord DAC | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FullCalendar CSS -->
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    
    <style>
        /* ========== VARIABLES CSS ========== */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --info-color: #17a2b8;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 250px;
            --sidebar-collapsed: 70px;
        }
        
        /* ========== STYLES GÉNÉRAUX ========== */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* ========== LAYOUT PRINCIPAL ========== */
        .app-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }
        
        /* ========== SIDEBAR ========== */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, #1a252f 100%);
            color: white;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: rgba(0,0,0,0.2);
        }
        
        .sidebar-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .sidebar-logo i {
            font-size: 24px;
        }
        
        .user-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(0,0,0,0.15);
            margin: 15px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-info p {
            margin: 0;
            font-weight: 500;
        }
        
        .user-role {
            display: inline-block;
            padding: 5px 15px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        /* ========== NAVIGATION ========== */
        .sidebar-nav {
            padding: 20px 15px;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 12px;
            padding: 0 15px;
            font-weight: 600;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--info-color);
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(90deg, rgba(23, 162, 184, 0.2), transparent);
            color: white;
            border-left-color: var(--info-color);
            font-weight: 500;
        }
        
        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 16px;
            margin-right: 12px;
            opacity: 0.9;
        }
        
        .nav-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        /* ========== CONTENU PRINCIPAL ========== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 25px;
            transition: all 0.3s ease;
        }
        
        /* ========== EN-TÊTE ========== */
        .content-header {
            background: white;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        .page-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 14px;
        }
        
        /* ========== CARTES DE CONTENU ========== */
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .card-header {
            background: linear-gradient(90deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #dee2e6;
            padding: 18px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header h5 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .card-header h5 i {
            margin-right: 10px;
            color: var(--info-color);
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* ========== CARTES DE STATISTIQUES ========== */
        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            color: white;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stats-total { background: linear-gradient(135deg, var(--primary-color), #1a252f); }
        .stats-planifie { background: linear-gradient(135deg, var(--warning-color), #e0a800); }
        .stats-en-cours { background: linear-gradient(135deg, var(--info-color), #138496); }
        .stats-termine { background: linear-gradient(135deg, var(--success-color), #1e7e34); }
        
        /* ========== TABLEAU ========== */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background: linear-gradient(90deg, var(--info-color), #0d8abc);
            color: white;
            border: none;
            padding: 15px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        .table tbody td {
            padding: 15px 20px;
            vertical-align: middle;
            border-color: #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: rgba(23, 162, 184, 0.05);
        }
        
        /* ========== FULLCALENDAR ========== */
        .fc {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .fc-toolbar-title {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .fc-button-primary {
            background-color: var(--info-color) !important;
            border-color: var(--info-color) !important;
        }
        
        .fc-button-primary:hover {
            background-color: #0d8abc !important;
            border-color: #0d8abc !important;
        }
        
        .fc-button-primary:disabled {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
        }
        
        .fc-event {
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        
        .fc-event:hover {
            opacity: 0.9;
        }
        
        .event-cours { background-color: var(--info-color); }
        .event-dst { background-color: var(--warning-color); color: #212529; }
        .event-recherche { background-color: var(--secondary-color); }
        .event-conge { background-color: var(--success-color); }
        .event-examen { background-color: var(--danger-color); }
        .event-stage { background-color: var(--primary-color); }
        
        /* ========== TIMELINE ========== */
        .timeline-item {
            border-left: 3px solid var(--info-color);
            padding-left: 15px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--info-color);
        }
        
        /* ========== BOUTONS ========== */
        .btn-action {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-action i {
            margin-right: 8px;
        }
        
        /* ========== RESPONSIVE ========== */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed);
            }
            
            .sidebar-header h5,
            .user-info,
            .nav-section-title,
            .nav-link span,
            .nav-badge {
                display: none !important;
            }
            
            .sidebar-logo {
                width: 40px;
                height: 40px;
            }
            
            .sidebar-logo i {
                font-size: 18px;
            }
            
            .nav-link {
                justify-content: center;
                padding: 15px;
                margin-bottom: 10px;
            }
            
            .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed);
                padding: 15px;
            }
            
            .content-header {
                padding: 20px;
            }
            
            .fc-toolbar {
                flex-direction: column;
            }
            
            .fc-toolbar-chunk {
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .table thead th,
            .table tbody td {
                padding: 12px 15px;
            }
        }
        
        @media (max-width: 576px) {
            .sidebar {
                display: none;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
        }
        
        /* ========== UTILITAIRES ========== */
        .text-info { color: var(--info-color) !important; }
        .bg-info { background-color: var(--info-color) !important; }
        .text-primary { color: var(--primary-color) !important; }
        .bg-primary { background-color: var(--primary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }
        
        /* ========== ANIMATIONS ========== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* ========== BOUTON MOBILE ========== */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: var(--info-color);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
        }
        
        /* ========== SCROLLBAR PERSONNALISÉE ========== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--info-color);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #0d8abc;
        }
    </style>
</head>
<body>
    <!-- Bouton menu mobile -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="app-container">
        <!-- ========== SIDEBAR (intégré) ========== -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur DAC'); ?></p>
                <small><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'Site ISGI'); ?></small>
            </div>
            
            <div class="sidebar-nav">
                <!-- Tableau de bord -->
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                </div>
                
                <!-- Gestion des étudiants -->
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste des étudiants</span>
                    </a>
                    <a href="cartes_etudiant.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Cartes étudiant</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Gestion présence</span>
                    </a>
                    <a href="salles.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Salles de classe</span>
                    </a>
                </div>
                
                <!-- Calendrier & Examens -->
                <div class="nav-section">
                    <div class="nav-section-title">Calendrier & Examens</div>
                    <a href="calendrier_academique.php" class="nav-link active">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier académique</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier examens</span>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions pédagogiques</span>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Gestion des matières</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de notes</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Organisation examens</span>
                    </a>
                </div>
                
                <!-- Rapports & Statistiques -->
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Statistiques</div>
                    <a href="rapports_academiques.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports académiques</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques détaillées</span>
                    </a>
                    <a href="export_data.php" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Export des données</span>
                    </a>
                </div>
                
                <!-- Compte -->
                <div class="nav-section">
                    <div class="nav-section-title">Compte</div>
                    <a href="<?php echo dirname(dirname(dirname(__DIR__))) . '/auth/logout.php'; ?>" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- ========== CONTENU PRINCIPAL ========== -->
        <div class="main-content" id="mainContent">
            <!-- En-tête -->
            <div class="content-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-calendar-alt me-2"></i>
                            Calendrier Académique
                        </h1>
                        <p class="page-subtitle">
                            Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur'); ?></strong> | 
                            Site: <strong><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'ISGI'); ?></strong> | 
                            <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success btn-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- ========== STATISTIQUES ========== -->
            <div class="row fade-in">
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card stats-total">
                        <h3><?php echo count($calendriers); ?></h3>
                        <p class="mb-0">Total Calendriers</p>
                        <i class="fas fa-calendar-alt fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card stats-planifie">
                        <h3><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'planifie')); ?></h3>
                        <p class="mb-0">Planifiés</p>
                        <i class="fas fa-clock fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card stats-en-cours">
                        <h3><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'en_cours')); ?></h3>
                        <p class="mb-0">En cours</p>
                        <i class="fas fa-play-circle fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card stats-termine">
                        <h3><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'termine')); ?></h3>
                        <p class="mb-0">Terminés</p>
                        <i class="fas fa-check-circle fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <!-- ========== FILTRES ========== -->
            <div class="content-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-filter me-2"></i> Filtres et Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <form method="get" class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Année académique</label>
                                    <select name="annee_id" class="form-select" onchange="this.form.submit()">
                                        <option value="">Toutes les années</option>
                                        <?php foreach($annees as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($annee['libelle']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <a href="calendrier_academique.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-times me-1"></i> Réinitialiser
                                    </a>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button class="btn btn-success btn-action" data-bs-toggle="modal" data-bs-target="#addCalendrierModal">
                                    <i class="fas fa-plus-circle me-2"></i> Nouveau calendrier
                                </button>
                                <a href="calendrier_examens.php" class="btn btn-info btn-action">
                                    <i class="fas fa-calendar-check me-2"></i> Calendrier examens
                                </a>
                                <button onclick="exportCalendrier()" class="btn btn-warning btn-action">
                                    <i class="fas fa-download me-2"></i> Exporter
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ========== ONGLETS ========== -->
            <ul class="nav nav-tabs mb-4" id="calendrierTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="liste-tab" data-bs-toggle="tab" data-bs-target="#liste" type="button">
                        <i class="fas fa-table me-2"></i> Liste
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="calendrier-tab" data-bs-toggle="tab" data-bs-target="#calendrier" type="button">
                        <i class="fas fa-calendar me-2"></i> Vue Calendrier
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button">
                        <i class="fas fa-stream me-2"></i> Timeline
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="calendrierTabContent">
                <!-- Tab 1: Liste des calendriers -->
                <div class="tab-pane fade show active" id="liste" role="tabpanel">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-list me-2"></i> Liste des Calendriers Académiques</h5>
                            <span class="badge bg-primary"><?php echo count($calendriers); ?> calendrier(s)</span>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendriers)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle fa-2x mb-3"></i>
                                <h5>Aucun calendrier académique trouvé</h5>
                                <p class="mb-0">Cliquez sur "Nouveau calendrier" pour créer le premier calendrier</p>
                            </div>
                            <?php else: ?>
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Année</th>
                                            <th>Semestre</th>
                                            <th>Type rentrée</th>
                                            <th>Période cours</th>
                                            <th>Événements</th>
                                            <th>Statut</th>
                                            <th>Publié</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($calendriers as $cal): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cal['annee_libelle']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo formatDateFr($cal['date_debut_cours']) . ' - ' . formatDateFr($cal['date_fin_cours']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">Semestre <?php echo $cal['semestre']; ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($cal['type_rentree']); ?></td>
                                            <td>
                                                <small>
                                                    Cours: <?php echo formatDateFr($cal['date_debut_cours']) . ' → ' . formatDateFr($cal['date_fin_cours']); ?>
                                                    <br>
                                                    <?php if($cal['date_debut_examens']): ?>
                                                    Examens: <?php echo formatDateFr($cal['date_debut_examens']) . ' → ' . formatDateFr($cal['date_fin_examens']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php 
                                                $events_count = 0;
                                                if($cal['date_debut_dst']) $events_count++;
                                                if($cal['date_debut_recherche']) $events_count++;
                                                if($cal['date_debut_examens']) $events_count++;
                                                if($cal['date_debut_stage']) $events_count++;
                                                ?>
                                                <span class="badge bg-secondary"><?php echo $events_count; ?> événement(s)</span>
                                            </td>
                                            <td><?php echo getStatutBadge($cal['statut']); ?></td>
                                            <td>
                                                <?php if($cal['publie'] == 1): ?>
                                                <span class="badge bg-success"><i class="fas fa-check"></i> Oui</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary"><i class="fas fa-times"></i> Non</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="calendrier_academique.php?action=edit&id=<?php echo $cal['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="calendrier_academique.php?action=view&id=<?php echo $cal['id']; ?>" 
                                                       class="btn btn-outline-info" title="Voir détails">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $cal['id']; ?>"
                                                            title="Supprimer">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Modal de suppression -->
                                        <div class="modal fade" id="deleteModal<?php echo $cal['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <input type="hidden" name="supprimer_calendrier" value="1">
                                                        <input type="hidden" name="calendrier_id" value="<?php echo $cal['id']; ?>">
                                                        
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                                                                Confirmer la suppression
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="alert alert-danger">
                                                                <i class="fas fa-exclamation-triangle"></i>
                                                                <strong>Attention !</strong> Cette action est irréversible.
                                                            </div>
                                                            <p>Êtes-vous sûr de vouloir supprimer le calendrier :</p>
                                                            <p class="fw-bold"><?php echo htmlspecialchars($cal['annee_libelle']); ?> - Semestre <?php echo $cal['semestre']; ?></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                            <button type="submit" class="btn btn-danger">
                                                                <i class="fas fa-trash me-1"></i> Confirmer
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 2: Vue calendrier -->
                <div class="tab-pane fade" id="calendrier" role="tabpanel">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-calendar-alt me-2"></i> Vue Calendrier</h5>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 3: Timeline -->
                <div class="tab-pane fade" id="timeline" role="tabpanel">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-stream me-2"></i> Timeline des Événements</h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendriers)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun événement à afficher.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($calendriers as $cal): ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <?php echo htmlspecialchars($cal['annee_libelle']); ?> - 
                                                Semestre <?php echo $cal['semestre']; ?>
                                                <span class="float-end"><?php echo getStatutBadge($cal['statut']); ?></span>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-chalkboard-teacher text-info"></i> Période de Cours</h6>
                                                <p class="mb-1">
                                                    <?php echo formatDateFr($cal['date_debut_cours']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                                </p>
                                                <small class="text-muted"><?php echo $cal['type_rentree']; ?> rentrée</small>
                                            </div>
                                            
                                            <?php if($cal['date_debut_dst']): ?>
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-file-alt text-warning"></i> DST</h6>
                                                <p class="mb-0">
                                                    <?php echo formatDateFr($cal['date_debut_dst']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_dst']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($cal['date_debut_recherche']): ?>
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-search text-secondary"></i> Devoir de Recherche</h6>
                                                <p class="mb-0">
                                                    <?php echo formatDateFr($cal['date_debut_recherche']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_recherche']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($cal['date_debut_conge_etude']): ?>
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-umbrella-beach text-success"></i> Congé d'Étude</h6>
                                                <p class="mb-0">
                                                    <?php echo formatDateFr($cal['date_debut_conge_etude']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_conge_etude']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($cal['date_debut_examens']): ?>
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-graduation-cap text-danger"></i> Examens de Fin de Semestre</h6>
                                                <p class="mb-0">
                                                    <?php echo formatDateFr($cal['date_debut_examens']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_examens']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($cal['date_debut_stage']): ?>
                                            <div class="timeline-item">
                                                <h6><i class="fas fa-briefcase text-primary"></i> Stage Professionnel</h6>
                                                <p class="mb-0">
                                                    <?php echo formatDateFr($cal['date_debut_stage']); ?> → 
                                                    <?php echo formatDateFr($cal['date_fin_stage']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($cal['observations']): ?>
                                            <div class="alert alert-light mt-3">
                                                <small>
                                                    <strong><i class="fas fa-sticky-note"></i> Observations:</strong><br>
                                                    <?php echo nl2br(htmlspecialchars($cal['observations'])); ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ========== PIED DE PAGE ========== -->
            <footer class="mt-5 pt-3 border-top text-center text-muted">
                <small>
                    &copy; <?php echo date('Y'); ?> ISGI - Système de Gestion Académique | 
                    Module Calendrier Académique | 
                    <span id="current-time"></span>
                </small>
            </footer>
        </div>
    </div>
    
    <!-- ========== MODALS ========== -->
    
    <!-- Modal d'ajout/modification -->
    <div class="modal fade" id="addCalendrierModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="calendrierForm">
                    <input type="hidden" name="<?php echo $calendrier ? 'modifier_calendrier' : 'ajouter_calendrier'; ?>" value="1">
                    <?php if($calendrier): ?>
                    <input type="hidden" name="calendrier_id" value="<?php echo $calendrier['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-plus me-2"></i> 
                            <?php echo $calendrier ? 'Modifier' : 'Nouveau'; ?> Calendrier Académique
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Année Académique *</label>
                                <select name="annee_academique_id" class="form-select" required>
                                    <option value="">Sélectionner une année</option>
                                    <?php foreach($annees as $annee): ?>
                                    <option value="<?php echo $annee['id']; ?>" 
                                        <?php echo ($calendrier && $calendrier['annee_academique_id'] == $annee['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($annee['libelle']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Semestre *</label>
                                <select name="semestre" class="form-select" required>
                                    <option value="1" <?php echo ($calendrier && $calendrier['semestre'] == '1') ? 'selected' : ''; ?>>Semestre 1</option>
                                    <option value="2" <?php echo ($calendrier && $calendrier['semestre'] == '2') ? 'selected' : ''; ?>>Semestre 2</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Type de Rentrée *</label>
                                <select name="type_rentree" class="form-select" required>
                                    <option value="Octobre" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Octobre') ? 'selected' : ''; ?>>Octobre</option>
                                    <option value="Janvier" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Janvier') ? 'selected' : ''; ?>>Janvier</option>
                                    <option value="Avril" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Avril') ? 'selected' : ''; ?>>Avril</option>
                                </select>
                            </div>
                        </div>
                        
                        <hr>
                        <h6><i class="fas fa-calendar-day"></i> Périodes Principales</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début des Cours *</label>
                                <input type="date" name="date_debut_cours" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_cours'] : ''; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin des Cours *</label>
                                <input type="date" name="date_fin_cours" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_cours'] : ''; ?>" required>
                            </div>
                        </div>
                        
                        <hr>
                        <h6><i class="fas fa-calendar-check"></i> Événements Spécifiques</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début DST</label>
                                <input type="date" name="date_debut_dst" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_dst'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin DST</label>
                                <input type="date" name="date_fin_dst" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_dst'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début Devoir Recherche</label>
                                <input type="date" name="date_debut_recherche" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_recherche'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin Devoir Recherche</label>
                                <input type="date" name="date_fin_recherche" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_recherche'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début Congé d'Étude</label>
                                <input type="date" name="date_debut_conge_etude" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_conge_etude'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin Congé d'Étude</label>
                                <input type="date" name="date_fin_conge_etude" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_conge_etude'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début Examens</label>
                                <input type="date" name="date_debut_examens" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_examens'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin Examens</label>
                                <input type="date" name="date_fin_examens" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_examens'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reprise des Cours (S2)</label>
                                <input type="date" name="date_reprise_cours" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_reprise_cours'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Début Stage</label>
                                <input type="date" name="date_debut_stage" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_debut_stage'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fin Stage</label>
                                <input type="date" name="date_fin_stage" class="form-control" 
                                       value="<?php echo $calendrier ? $calendrier['date_fin_stage'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Statut *</label>
                                <select name="statut" class="form-select" required>
                                    <option value="planifie" <?php echo ($calendrier && $calendrier['statut'] == 'planifie') ? 'selected' : ''; ?>>Planifié</option>
                                    <option value="en_cours" <?php echo ($calendrier && $calendrier['statut'] == 'en_cours') ? 'selected' : ''; ?>>En cours</option>
                                    <option value="termine" <?php echo ($calendrier && $calendrier['statut'] == 'termine') ? 'selected' : ''; ?>>Terminé</option>
                                    <option value="annule" <?php echo ($calendrier && $calendrier['statut'] == 'annule') ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observations</label>
                            <textarea name="observations" class="form-control" rows="3" 
                                      placeholder="Notes ou remarques supplémentaires..."><?php echo $calendrier ? htmlspecialchars($calendrier['observations']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input type="checkbox" name="publie" class="form-check-input" id="publieCheck" 
                                   <?php echo ($calendrier && $calendrier['publie'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="publieCheck">
                                Publier aux étudiants (rendre visible)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i> 
                            <?php echo $calendrier ? 'Modifier' : 'Enregistrer'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- FullCalendar -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js'></script>
    
    <script>
    // ========== FONCTIONS UTILITAIRES ==========
    
    // Mettre à jour l'heure actuelle
    function updateCurrentTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('fr-FR', { 
            hour: '2-digit', 
            minute: '2-digit',
            second: '2-digit'
        });
        document.getElementById('current-time').textContent = timeString;
    }
    
    // Toggle sidebar sur mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (window.innerWidth <= 576) {
            if (sidebar.style.display === 'block') {
                sidebar.style.display = 'none';
                mainContent.style.marginLeft = '0';
            } else {
                sidebar.style.display = 'block';
                sidebar.style.width = '250px';
                sidebar.style.position = 'fixed';
                sidebar.style.zIndex = '1000';
                mainContent.style.marginLeft = '0';
            }
        }
    }
    
    // Gérer le responsive du sidebar
    function handleSidebarResponsive() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 576) {
            // Mobile: sidebar caché par défaut
            sidebar.style.display = 'none';
            mainContent.style.marginLeft = '0';
            mobileBtn.style.display = 'block';
        } else if (window.innerWidth <= 992) {
            // Tablet: sidebar réduit
            sidebar.style.display = 'block';
            sidebar.style.width = '70px';
            mainContent.style.marginLeft = '70px';
            mobileBtn.style.display = 'none';
        } else {
            // Desktop: sidebar complet
            sidebar.style.display = 'block';
            sidebar.style.width = '250px';
            mainContent.style.marginLeft = '250px';
            mobileBtn.style.display = 'none';
        }
    }
    
    // Confirmation pour les actions importantes
    function confirmAction(message) {
        return confirm(message || 'Êtes-vous sûr de vouloir effectuer cette action ?');
    }
    
    // ========== FONCTIONS SPÉCIFIQUES AU CALENDRIER ==========
    
    // Exporter le calendrier
    function exportCalendrier() {
        const format = prompt('Choisir le format d\'export:\n1. PDF\n2. Excel\n3. ICS', '1');
        if (format === '1') {
            window.open('export_calendrier.php?format=pdf&annee_id=<?php echo $annee_id; ?>', '_blank');
        } else if (format === '2') {
            window.open('export_calendrier.php?format=excel&annee_id=<?php echo $annee_id; ?>', '_blank');
        } else if (format === '3') {
            window.open('export_calendrier.php?format=ics&annee_id=<?php echo $annee_id; ?>', '_blank');
        }
    }
    
    // ========== FULLCALENDAR ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser l'heure
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);
        
        // Gérer le responsive
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        
        // Bouton menu mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Initialiser FullCalendar
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                locale: 'fr',
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                events: [
                    <?php foreach($calendriers as $cal): ?>
                    {
                        title: '<?php echo htmlspecialchars($cal["annee_libelle"]) . " - S" . $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_cours"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_cours"] . ' +1 day')); ?>',
                        className: 'event-cours',
                        extendedProps: {
                            type: 'cours',
                            description: 'Période de cours - <?php echo $cal["type_rentree"]; ?> rentrée'
                        }
                    },
                    <?php if($cal['date_debut_dst'] && $cal['date_fin_dst']): ?>
                    {
                        title: 'DST - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_dst"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_dst"] . ' +1 day')); ?>',
                        className: 'event-dst',
                        extendedProps: {
                            type: 'dst',
                            description: 'Devoir Sur Table'
                        }
                    },
                    <?php endif; ?>
                    <?php if($cal['date_debut_recherche'] && $cal['date_fin_recherche']): ?>
                    {
                        title: 'Recherche - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_recherche"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_recherche"] . ' +1 day')); ?>',
                        className: 'event-recherche',
                        extendedProps: {
                            type: 'recherche',
                            description: 'Devoir de Recherche'
                        }
                    },
                    <?php endif; ?>
                    <?php if($cal['date_debut_conge_etude'] && $cal['date_fin_conge_etude']): ?>
                    {
                        title: 'Congé Étude - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_conge_etude"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_conge_etude"] . ' +1 day')); ?>',
                        className: 'event-conge',
                        extendedProps: {
                            type: 'conge',
                            description: 'Congé d\'Étude'
                        }
                    },
                    <?php endif; ?>
                    <?php if($cal['date_debut_examens'] && $cal['date_fin_examens']): ?>
                    {
                        title: 'Examens - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_examens"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_examens"] . ' +1 day')); ?>',
                        className: 'event-examen',
                        extendedProps: {
                            type: 'examen',
                            description: 'Examens de Fin de Semestre'
                        }
                    },
                    <?php endif; ?>
                    <?php if($cal['date_debut_stage'] && $cal['date_fin_stage']): ?>
                    {
                        title: 'Stage - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_stage"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_stage"] . ' +1 day')); ?>',
                        className: 'event-stage',
                        extendedProps: {
                            type: 'stage',
                            description: 'Stage Professionnel'
                        }
                    },
                    <?php endif; ?>
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    const event = info.event;
                    const details = `
                        <div class="alert alert-info">
                            <h6>${event.title}</h6>
                            <p class="mb-1"><strong>Type:</strong> ${event.extendedProps.description}</p>
                            <p class="mb-1"><strong>Date:</strong> ${event.start.toLocaleDateString('fr-FR')} 
                                ${event.end ? ' → ' + new Date(event.end.getTime() - 86400000).toLocaleDateString('fr-FR') : ''}
                            </p>
                            ${event.extendedProps.type === 'cours' ? 
                                '<p class="mb-0"><strong>Durée:</strong> ' + 
                                Math.round((event.end - event.start) / (1000 * 60 * 60 * 24)) + ' jours</p>' : ''}
                        </div>
                    `;
                    
                    // Créer un modal pour afficher les détails
                    const modalHTML = `
                        <div class="modal fade" id="eventModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Détails de l'événement</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        ${details}
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Ajouter le modal au DOM
                    const modalContainer = document.createElement('div');
                    modalContainer.innerHTML = modalHTML;
                    document.body.appendChild(modalContainer.firstChild);
                    
                    // Afficher le modal
                    const modal = new bootstrap.Modal(document.getElementById('eventModal'));
                    modal.show();
                    
                    // Nettoyer après fermeture
                    document.getElementById('eventModal').addEventListener('hidden.bs.modal', function() {
                        this.remove();
                    });
                },
                eventDidMount: function(info) {
                    // Info bulle au survol
                    info.el.title = info.event.extendedProps.description;
                }
            });
            
            calendar.render();
        }
        
        // Ouvrir automatiquement le modal en mode édition
        <?php if($calendrier && $action == 'edit'): ?>
        const editModal = new bootstrap.Modal(document.getElementById('addCalendrierModal'));
        editModal.show();
        <?php endif; ?>
        
        // Validation du formulaire
        document.getElementById('calendrierForm')?.addEventListener('submit', function(e) {
            const dateDebut = document.querySelector('input[name="date_debut_cours"]');
            const dateFin = document.querySelector('input[name="date_fin_cours"]');
            
            if (dateDebut.value && dateFin.value) {
                const debut = new Date(dateDebut.value);
                const fin = new Date(dateFin.value);
                
                if (fin < debut) {
                    e.preventDefault();
                    alert('La date de fin des cours doit être postérieure à la date de début.');
                    dateFin.focus();
                }
            }
        });
        
        // Auto-suppression des alertes
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-light)');
            alerts.forEach(alert => {
                if (alert.classList.contains('show')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
        
        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            // Ctrl + N pour nouveau calendrier
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                if (!document.querySelector('#addCalendrierModal.show')) {
                    const modal = new bootstrap.Modal(document.getElementById('addCalendrierModal'));
                    modal.show();
                }
            }
            
            // Échap pour fermer les modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    bootstrap.Modal.getInstance(modal).hide();
                });
            }
        });
        
        // Configurer les onglets Bootstrap
        const tabTriggers = document.querySelectorAll('#calendrierTab button[data-bs-toggle="tab"]');
        tabTriggers.forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const tab = new bootstrap.Tab(this);
                tab.show();
            });
        });
    });
    </script>
</body>
</html>