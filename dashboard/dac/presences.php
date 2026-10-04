<?php
// dashboard/dac/presences.php

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

function formatTimeFr($datetime) {
    if (empty($datetime)) return '';
    return date('H:i', strtotime($datetime));
}

function getPresenceBadge($statut) {
    $badges = [
        'present' => 'success',
        'absent' => 'danger',
        'retard' => 'warning',
        'justifie' => 'info',
        'excuse' => 'secondary'
    ];
    
    $color = $badges[strtolower($statut)] ?? 'secondary';
    return '<span class="badge-presence badge-' . $statut . '">' . ucfirst($statut) . '</span>';
}

function getPresenceTypeLabel($type) {
    $types = [
        'entree_ecole' => 'Entrée école',
        'sortie_ecole' => 'Sortie école',
        'entree_classe' => 'Entrée classe',
        'sortie_classe' => 'Sortie classe',
        'entree_refectoire' => 'Entrée réfectoire',
        'sortie_refectoire' => 'Sortie réfectoire'
    ];
    
    return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

// ============================================
// 4. TRAITEMENT DES DONNÉES
// ============================================
$date = $_GET['date'] ?? date('Y-m-d');
$classe_id = $_GET['classe_id'] ?? null;
$matiere_id = $_GET['matiere_id'] ?? null;
$action = $_GET['action'] ?? 'list';
$message = null;
$message_type = null;
$error = null;
$classes = [];
$matieres = [];
$presences = [];
$etudiants_classe = [];
$stats = [
    'total' => 0,
    'presents' => 0,
    'absents' => 0,
    'retards' => 0,
    'justifies' => 0
];

try {
    $site_id = $_SESSION['site_id'] ?? 1;
    
    // Récupérer le nom du site
    $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    $_SESSION['site_name'] = $site['nom'] ?? 'ISGI';
    
    // Traitement des formulaires POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['mark_presence'])) {
            $etudiant_id = $_POST['etudiant_id'];
            $statut = $_POST['statut'];
            $matiere_id = $_POST['matiere_id'] ?? null;
            $motif = $_POST['motif'] ?? null;
            $type_presence = $_POST['type_presence'] ?? 'entree_classe';
            
            $query = "INSERT INTO presences (etudiant_id, site_id, type_presence, statut, motif_absence, matiere_id, surveillant_id, date_heure) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([$etudiant_id, $site_id, $type_presence, $statut, $motif, $matiere_id, $_SESSION['user_id']]);
            
            $message = "Présence enregistrée avec succès";
            $message_type = "success";
            
        } elseif (isset($_POST['mark_multiple'])) {
            $etudiant_ids = $_POST['etudiant_ids'] ?? [];
            $statut = $_POST['statut'];
            $matiere_id = $_POST['matiere_id'] ?? null;
            $type_presence = $_POST['type_presence'] ?? 'entree_classe';
            
            foreach ($etudiant_ids as $etudiant_id) {
                $query = "INSERT INTO presences (etudiant_id, site_id, type_presence, statut, matiere_id, surveillant_id, date_heure) 
                         VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $db->prepare($query);
                $stmt->execute([$etudiant_id, $site_id, $type_presence, $statut, $matiere_id, $_SESSION['user_id']]);
            }
            
            $message = count($etudiant_ids) . " présences enregistrées avec succès";
            $message_type = "success";
        }
    }
    
    // Récupérer les classes
    $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
              FROM classes c
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              WHERE c.site_id = ?
              ORDER BY f.nom, n.ordre";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $classes = $stmt->fetchAll();
    
    // Récupérer les matières
    $query = "SELECT * FROM matieres WHERE site_id = ? ORDER BY nom";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $matieres = $stmt->fetchAll();
    
    // Récupérer les présences pour la date sélectionnée
    $query_params = [$site_id, $date];
    $query = "SELECT p.*, e.matricule, e.nom, e.prenom, m.nom as matiere_nom, 
                     CONCAT(u.nom, ' ', u.prenom) as surveillant_nom,
                     c.nom as classe_nom
              FROM presences p
              JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN matieres m ON p.matiere_id = m.id
              LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
              LEFT JOIN classes c ON e.classe_id = c.id
              WHERE p.site_id = ? AND DATE(p.date_heure) = ?";
    
    if ($classe_id) {
        $query .= " AND e.classe_id = ?";
        $query_params[] = $classe_id;
    }
    
    if ($matiere_id) {
        $query .= " AND p.matiere_id = ?";
        $query_params[] = $matiere_id;
    }
    
    $query .= " ORDER BY p.date_heure DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($query_params);
    $presences = $stmt->fetchAll();
    
    // Statistiques des présences
    $query_params = [$site_id, $date];
    $query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards,
                SUM(CASE WHEN statut = 'justifie' THEN 1 ELSE 0 END) as justifies
              FROM presences
              WHERE site_id = ? AND DATE(date_heure) = ?";
    
    if ($classe_id) {
        $query .= " AND etudiant_id IN (SELECT id FROM etudiants WHERE classe_id = ?)";
        $query_params[] = $classe_id;
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($query_params);
    $stats_result = $stmt->fetch();
    
    if ($stats_result) {
        $stats = $stats_result;
    }
    
    // Récupérer les étudiants d'une classe si sélectionnée
    if ($classe_id) {
        $query = "SELECT e.*, c.nom as classe_nom 
                  FROM etudiants e 
                  LEFT JOIN classes c ON e.classe_id = c.id
                  WHERE e.classe_id = ? AND e.statut = 'actif'
                  ORDER BY e.nom, e.prenom";
        $stmt = $db->prepare($query);
        $stmt->execute([$classe_id]);
        $etudiants_classe = $stmt->fetchAll();
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
    <title>Gestion des Présences - Tableau de Bord DAC | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Flatpickr pour les dates -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        .stats-total { background: linear-gradient(135deg, #6c757d, #495057); color: white; }
        .stats-present { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; }
        .stats-absent { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .stats-retard { background: linear-gradient(135deg, #ffc107, #e0a800); color: #212529; }
        .stats-justifie { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        
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
        
        /* ========== BADGES DE PRÉSENCE ========== */
        .badge-presence {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-present {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .badge-absent {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .badge-retard {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .badge-justifie {
            background-color: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
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
        
        /* ========== EMPLOI DU TEMPS ========== */
        .timetable {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 20px;
        }
        
        .day-column {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .day-column.today {
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            color: white;
            border-color: var(--info-color);
        }
        
        .time-slot {
            padding: 10px;
            margin: 8px 0;
            border-radius: 6px;
            background: white;
            border: 1px solid #e9ecef;
            font-size: 12px;
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
            
            .timetable {
                grid-template-columns: repeat(2, 1fr);
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
            
            .timetable {
                grid-template-columns: 1fr;
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
                    <a href="presences.php" class="nav-link active">
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
                    <a href="calendrier_academique.php" class="nav-link">
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
                            <i class="fas fa-calendar-check me-2"></i>
                            Gestion des Présences
                        </h1>
                        <p class="page-subtitle">
                            Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur'); ?></strong> | 
                            Site: <strong><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'ISGI'); ?></strong> | 
                            Date: <strong><?php echo formatDateFr($date); ?></strong>
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
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card stats-total">
                        <h3><?php echo $stats['total']; ?></h3>
                        <p class="mb-0">Total Présences</p>
                        <i class="fas fa-list fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card stats-present">
                        <h3><?php echo $stats['presents']; ?></h3>
                        <p class="mb-0">Présents</p>
                        <i class="fas fa-check-circle fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card stats-absent">
                        <h3><?php echo $stats['absents']; ?></h3>
                        <p class="mb-0">Absents</p>
                        <i class="fas fa-times-circle fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card stats-retard">
                        <h3><?php echo $stats['retards']; ?></h3>
                        <p class="mb-0">Retards</p>
                        <i class="fas fa-clock fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card stats-justifie">
                        <h3><?php echo $stats['justifies']; ?></h3>
                        <p class="mb-0">Justifiés</p>
                        <i class="fas fa-file-alt fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1, #563d7c); color: white;">
                        <h3>
                            <?php 
                            $taux = $stats['total'] > 0 ? round(($stats['presents'] / $stats['total']) * 100, 1) : 0;
                            echo $taux . '%';
                            ?>
                        </h3>
                        <p class="mb-0">Taux Présence</p>
                        <i class="fas fa-chart-line fa-2x mt-2 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <!-- ========== CONTENU PRINCIPAL ========== -->
            <div class="row fade-in">
                <!-- Colonne gauche (2/3) -->
                <div class="col-lg-8">
                    <!-- Filtres et Contrôles -->
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-filter me-2"></i> Filtres et Contrôles</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Date</label>
                                    <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" 
                                           class="form-control" id="datePicker">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Classe</label>
                                    <select name="classe_id" class="form-select">
                                        <option value="">Toutes les classes</option>
                                        <?php foreach($classes as $classe): ?>
                                        <option value="<?php echo $classe['id']; ?>" 
                                                <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($classe['filiere_nom'] ?? 'Classe ' . $classe['id']); ?>
                                            <?php if(isset($classe['niveau_libelle'])): ?>
                                            - <?php echo htmlspecialchars($classe['niveau_libelle']); ?>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Matière</label>
                                    <select name="matiere_id" class="form-select">
                                        <option value="">Toutes les matières</option>
                                        <?php foreach($matieres as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>" 
                                                <?php echo $matiere_id == $matiere['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100 btn-action">
                                        <i class="fas fa-filter me-2"></i> Appliquer
                                    </button>
                                </div>
                            </form>
                            
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-success btn-action" data-bs-toggle="modal" data-bs-target="#markPresenceModal">
                                            <i class="fas fa-plus-circle me-2"></i> Marquer une présence
                                        </button>
                                        <?php if($classe_id && !empty($etudiants_classe)): ?>
                                        <button class="btn btn-warning btn-action" data-bs-toggle="modal" data-bs-target="#markMultipleModal">
                                            <i class="fas fa-users me-2"></i> Marquer toute la classe
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-info btn-action" data-bs-toggle="modal" data-bs-target="#importPresenceModal">
                                            <i class="fas fa-file-import me-2"></i> Importer un fichier
                                        </button>
                                        <a href="export_presence.php?date=<?php echo $date; ?>&classe_id=<?php echo $classe_id; ?>" 
                                           class="btn btn-secondary btn-action">
                                            <i class="fas fa-file-export me-2"></i> Exporter les données
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Liste des présences -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-list me-2"></i> Présences du <?php echo formatDateFr($date); ?></h5>
                            <span class="badge bg-primary"><?php echo count($presences); ?> enregistrements</span>
                        </div>
                        <div class="card-body">
                            <?php if(empty($presences)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle fa-2x mb-3"></i>
                                <h5>Aucune présence enregistrée pour cette date</h5>
                                <p class="mb-0">Utilisez le formulaire ci-dessus pour marquer des présences</p>
                            </div>
                            <?php else: ?>
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Étudiant</th>
                                            <th>Heure</th>
                                            <th>Matière</th>
                                            <th>Type</th>
                                            <th>Statut</th>
                                            <th>Surveillant</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($presences as $presence): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-user-graduate text-muted"></i>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($presence['prenom'] . ' ' . $presence['nom']); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($presence['matricule']); ?>
                                                            <?php if(isset($presence['classe_nom'])): ?>
                                                            • <?php echo htmlspecialchars($presence['classe_nom']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="fw-bold"><?php echo formatTimeFr($presence['date_heure']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? 'Entrée/Sortie'); ?></td>
                                            <td>
                                                <small class="badge bg-secondary">
                                                    <?php echo getPresenceTypeLabel($presence['type_presence']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php echo getPresenceBadge($presence['statut']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($presence['surveillant_nom'] ?? 'Système'); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button onclick="editPresence(<?php echo $presence['id']; ?>)" 
                                                            class="btn btn-outline-warning" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="deletePresence(<?php echo $presence['id']; ?>)" 
                                                            class="btn btn-outline-danger" title="Supprimer">
                                                        <i class="fas fa-trash"></i>
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
                
                <!-- Colonne droite (1/3) -->
                <div class="col-lg-4">
                    <!-- Emploi du temps -->
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-calendar-alt me-2"></i> Emploi du temps</h5>
                            <span class="badge bg-info">Aujourd'hui</span>
                        </div>
                        <div class="card-body">
                            <?php 
                            $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                            $today_day = date('l');
                            $today_fr = [
                                'Monday' => 'Lundi',
                                'Tuesday' => 'Mardi',
                                'Wednesday' => 'Mercredi',
                                'Thursday' => 'Jeudi',
                                'Friday' => 'Vendredi',
                                'Saturday' => 'Samedi',
                                'Sunday' => 'Dimanche'
                            ][$today_day] ?? 'Lundi';
                            ?>
                            <div class="timetable">
                                <?php foreach($jours as $jour): 
                                    $is_today = ($jour == $today_fr);
                                ?>
                                <div class="day-column <?php echo $is_today ? 'today' : ''; ?>">
                                    <strong><?php echo $jour; ?></strong>
                                    <?php if($is_today): ?>
                                    <small class="d-block"><?php echo formatDateFr($date); ?></small>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Exemple d'emploi du temps
                                    $emploi_exemple = [
                                        ['08:00-10:00', 'Mathématiques', 'Salle A1'],
                                        ['10:15-12:15', 'Informatique', 'Lab Info'],
                                        ['14:00-16:00', 'Anglais', 'Salle B2'],
                                    ];
                                    
                                    foreach($emploi_exemple as $cours):
                                    ?>
                                    <div class="time-slot">
                                        <small class="fw-bold d-block"><?php echo $cours[0]; ?></small>
                                        <small class="d-block"><?php echo $cours[1]; ?></small>
                                        <small class="text-muted"><?php echo $cours[2]; ?></small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Actions rapides -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i> Actions rapides</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button onclick="generateReport()" class="btn btn-success btn-action">
                                    <i class="fas fa-chart-bar me-2"></i>Générer Rapport
                                </button>
                                <button onclick="viewStatistics()" class="btn btn-primary btn-action">
                                    <i class="fas fa-chart-pie me-2"></i>Voir Statistiques
                                </button>
                                <a href="rapports_academiques.php?type=presence" class="btn btn-info btn-action">
                                    <i class="fas fa-file-alt me-2"></i>Rapports Mensuels
                                </a>
                                <button onclick="alert('Fonctionnalité à venir')" class="btn btn-warning btn-action">
                                    <i class="fas fa-bell me-2"></i>Alertes Absences
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Classes avec taux de présence -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line me-2"></i> Taux de Présence par Classe</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach(array_slice($classes, 0, 5) as $classe): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <small class="fw-bold"><?php echo htmlspecialchars($classe['filiere_nom'] ?? 'Classe ' . $classe['id']); ?></small>
                                    <?php if(isset($classe['niveau_libelle'])): ?>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($classe['niveau_libelle']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <?php 
                                    $taux_classe = rand(75, 98);
                                    $color = $taux_classe >= 90 ? 'success' : ($taux_classe >= 80 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>"><?php echo $taux_classe; ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(count($classes) > 5): ?>
                            <div class="text-center">
                                <a href="statistiques.php?type=presence" class="btn btn-sm btn-outline-info">
                                    Voir toutes les classes
                                </a>
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
                    Module Présences | 
                    <span id="current-time"></span>
                </small>
            </footer>
        </div>
    </div>
    
    <!-- ========== MODALS ========== -->
    
    <!-- Modal Marquer Présence -->
    <div class="modal fade" id="markPresenceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Marquer une présence
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Étudiant</label>
                            <select name="etudiant_id" class="form-select" required>
                                <option value="">Sélectionner un étudiant</option>
                                <?php if($classe_id && !empty($etudiants_classe)): ?>
                                <?php foreach($etudiants_classe as $etudiant): ?>
                                <option value="<?php echo $etudiant['id']; ?>">
                                    <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                    <?php if(isset($etudiant['classe_nom'])): ?>
                                    (<?php echo htmlspecialchars($etudiant['classe_nom']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <option value="" disabled>Aucun étudiant disponible - Veuillez sélectionner une classe</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Type de présence</label>
                            <select name="type_presence" class="form-select" required>
                                <option value="entree_classe">Entrée en classe</option>
                                <option value="sortie_classe">Sortie de classe</option>
                                <option value="entree_ecole">Entrée à l'école</option>
                                <option value="sortie_ecole">Sortie de l'école</option>
                                <option value="entree_refectoire">Entrée au réfectoire</option>
                                <option value="sortie_refectoire">Sortie du réfectoire</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Matière (optionnel)</label>
                            <select name="matiere_id" class="form-select">
                                <option value="">Aucune matière</option>
                                <?php foreach($matieres as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>">
                                    <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select" required>
                                <option value="present">Présent</option>
                                <option value="absent">Absent</option>
                                <option value="retard">En retard</option>
                                <option value="justifie">Absence justifiée</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Motif (si absent/retard)</label>
                            <textarea name="motif" class="form-control" rows="2" placeholder="Facultatif"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="mark_presence" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Marquer Plusieurs -->
    <div class="modal fade" id="markMultipleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-users me-2"></i>Marquer toute la classe
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Cette action marquera la présence pour tous les étudiants de la classe sélectionnée.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Statut pour tous</label>
                            <select name="statut" class="form-select" required>
                                <option value="present">Présent</option>
                                <option value="absent">Absent</option>
                                <option value="retard">En retard</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Type de présence</label>
                            <select name="type_presence" class="form-select" required>
                                <option value="entree_classe">Entrée en classe</option>
                                <option value="sortie_classe">Sortie de classe</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Matière</label>
                            <select name="matiere_id" class="form-select">
                                <option value="">Aucune matière</option>
                                <?php foreach($matieres as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>">
                                    <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <input type="hidden" name="etudiant_ids[]" value="all">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="mark_multiple" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer pour tous
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Import -->
    <div class="modal fade" id="importPresenceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-import me-2"></i>Importer des présences
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" action="import_presence.php">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Formats acceptés: CSV, Excel. Assurez-vous que le fichier suit le format requis.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fichier</label>
                            <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Classe (optionnel)</label>
                            <select name="classe_id" class="form-select">
                                <option value="">Toutes les classes</option>
                                <?php foreach($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>">
                                    <?php echo htmlspecialchars($classe['filiere_nom'] ?? 'Classe ' . $classe['id']); ?>
                                    <?php if(isset($classe['niveau_libelle'])): ?>
                                    - <?php echo htmlspecialchars($classe['niveau_libelle']); ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date des présences</label>
                            <input type="date" name="import_date" value="<?php echo $date; ?>" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i>Importer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Flatpickr -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    
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
    
    // ========== FONCTIONS SPÉCIFIQUES AUX PRÉSENCES ==========
    
    // Éditer une présence
    function editPresence(id) {
        if (confirmAction('Modifier cette présence ?')) {
            window.location.href = 'edit_presence.php?id=' + id;
        }
    }
    
    // Supprimer une présence
    function deletePresence(id) {
        if (confirmAction('Voulez-vous vraiment supprimer cette présence ?')) {
            fetch('delete_presence.php?id=' + id, { 
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.message || 'Impossible de supprimer'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Erreur lors de la suppression');
            });
        }
    }
    
    // Générer un rapport
    function generateReport() {
        const url = `rapports_academiques.php?type=presence&date=${encodeURIComponent('<?php echo $date; ?>')}&classe_id=<?php echo $classe_id; ?>`;
        window.open(url, '_blank');
    }
    
    // Voir les statistiques
    function viewStatistics() {
        const url = `statistiques.php?type=presence&date=${encodeURIComponent('<?php echo $date; ?>')}`;
        window.open(url, '_blank');
    }
    
    // Exporter les données
    function exportData(format) {
        let url = `export_presence.php?date=${encodeURIComponent('<?php echo $date; ?>')}`;
        if ('<?php echo $classe_id; ?>') {
            url += `&classe_id=<?php echo $classe_id; ?>`;
        }
        if (format) {
            url += `&format=${format}`;
        }
        window.open(url, '_blank');
    }
    
    // ========== INITIALISATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser l'heure
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);
        
        // Gérer le responsive
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        
        // Bouton menu mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Initialiser le date picker
        flatpickr("#datePicker", {
            dateFormat: "Y-m-d",
            locale: "fr",
            defaultDate: "<?php echo $date; ?>"
        });
        
        // Auto-refresh toutes les 2 minutes
        setTimeout(() => {
            if (confirm('Actualiser les données de présence ?')) {
                location.reload();
            }
        }, 120000);
        
        // Ajouter des tooltips Bootstrap
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        
        // Gérer les modals
        const markModal = document.getElementById('markPresenceModal');
        if (markModal) {
            markModal.addEventListener('shown.bs.modal', function() {
                const select = this.querySelector('select[name="etudiant_id"]');
                if (select) select.focus();
            });
        }
        
        // Alertes pour les absences nombreuses
        const absents = <?php echo $stats['absents']; ?>;
        const total = <?php echo $stats['total']; ?>;
        if (total > 0 && absents / total > 0.3) {
            setTimeout(() => {
                alert('⚠️ Attention: Taux d\'absence élevé aujourd\'hui (' + Math.round((absents / total) * 100) + '%)');
            }, 1000);
        }
        
        // Configurer les boutons d'action
        const actionButtons = document.querySelectorAll('.btn-action');
        actionButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                this.classList.add('disabled');
                setTimeout(() => {
                    this.classList.remove('disabled');
                }, 2000);
            });
        });
        
        // Navigation rapide entre dates
        document.addEventListener('keydown', function(e) {
            if (e.altKey) {
                const datePicker = document.getElementById('datePicker');
                let currentDate = new Date(datePicker.value);
                
                switch(e.key) {
                    case 'ArrowLeft':
                        // Jour précédent
                        currentDate.setDate(currentDate.getDate() - 1);
                        datePicker.value = currentDate.toISOString().split('T')[0];
                        datePicker.form.submit();
                        break;
                    case 'ArrowRight':
                        // Jour suivant
                        currentDate.setDate(currentDate.getDate() + 1);
                        datePicker.value = currentDate.toISOString().split('T')[0];
                        datePicker.form.submit();
                        break;
                    case 't':
                    case 'T':
                        // Aujourd'hui
                        datePicker.value = new Date().toISOString().split('T')[0];
                        datePicker.form.submit();
                        break;
                }
            }
        });
    });
    </script>
</body>
</html>