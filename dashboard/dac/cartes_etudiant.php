<?php
// dashboard/dac/cartes_etudiant.php

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

function getStatusBadge($statut) {
    switch (strtolower($statut)) {
        case 'actif':
        case 'valide':
            return '<span class="badge bg-success">Actif</span>';
        case 'inactif':
        case 'en_attente':
            return '<span class="badge bg-warning">En attente</span>';
        case 'annule':
        case 'rejete':
            return '<span class="badge bg-danger">Annulé</span>';
        case 'diplome':
            return '<span class="badge bg-primary">Diplômé</span>';
        case 'abandonne':
            return '<span class="badge bg-danger">Abandonné</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

// ============================================
// 4. TRAITEMENT DES DONNÉES
// ============================================
$pageTitle = "Gestion des Cartes Étudiant";
$action = $_GET['action'] ?? 'list';
$etudiant_id = $_GET['etudiant_id'] ?? null;
$message = null;
$message_type = null;
$error = null;
$etudiants = [];

try {
    $site_id = $_SESSION['site_id'] ?? 1;
    
    // Récupérer le nom du site
    $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    $_SESSION['site_name'] = $site['nom'] ?? 'ISGI';
    
    // Traitement des formulaires POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['generate_card'])) {
            $etudiant_id = $_POST['etudiant_id'];
            
            // Récupérer les infos de l'étudiant
            $stmt = $db->prepare("SELECT * FROM etudiants WHERE id = ?");
            $stmt->execute([$etudiant_id]);
            $etudiant = $stmt->fetch();
            
            if ($etudiant) {
                // Vérifier si la bibliothèque QR Code existe
                $root_path = dirname(dirname(dirname(__DIR__)));
                $qrcode_path = $root_path . '/vendor/phpqrcode/qrlib.php';
                
                if (file_exists($qrcode_path)) {
                    require_once $qrcode_path;
                    
                    $qr_data = "ETUDIANT:" . $etudiant['matricule'] . "|NOM:" . $etudiant['nom'] . "|PRENOM:" . $etudiant['prenom'] . "|SITE:" . $site_id;
                    $qr_filename = 'qrcode_' . $etudiant['matricule'] . '.png';
                    $qr_path = $root_path . '/uploads/qrcodes/' . $qr_filename;
                    
                    if (!file_exists(dirname($qr_path))) {
                        mkdir(dirname($qr_path), 0777, true);
                    }
                    
                    // Générer le QR code
                    QRcode::png($qr_data, $qr_path, QR_ECLEVEL_L, 10);
                    
                    // Mettre à jour l'étudiant avec le QR code
                    $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
                    $stmt->execute([$qr_data, $etudiant_id]);
                    
                    $message = "Carte générée avec succès pour " . $etudiant['prenom'] . " " . $etudiant['nom'];
                    $message_type = "success";
                } else {
                    // Utiliser un service en ligne si la bibliothèque n'existe pas
                    $qr_data = "ETUDIANT:" . $etudiant['matricule'] . "|NOM:" . $etudiant['nom'] . "|PRENOM:" . $etudiant['prenom'] . "|SITE:" . $site_id;
                    
                    $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
                    $stmt->execute([$qr_data, $etudiant_id]);
                    
                    $message = "Carte générée (sans QR code local) pour " . $etudiant['prenom'] . " " . $etudiant['nom'];
                    $message_type = "warning";
                }
            }
        }
    }
    
    // Récupérer la liste des étudiants
    $query = "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle 
              FROM etudiants e
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN inscriptions i ON e.id = i.etudiant_id
              LEFT JOIN filieres f ON i.filiere_id = f.id
              LEFT JOIN niveaux n ON i.niveau = n.code
              WHERE e.site_id = ? AND e.statut = 'actif'
              ORDER BY e.nom, e.prenom";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $etudiants = $stmt->fetchAll();
    
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
    <title>Cartes Étudiant - Tableau de Bord DAC | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
        
        /* ========== CARTE ÉTUDIANT PRÉVISUALISATION ========== */
        .card-preview {
            border: 2px solid #007bff;
            border-radius: 10px;
            padding: 20px;
            max-width: 400px;
            margin: 20px auto;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .card-preview::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .card-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .card-preview-logo {
            font-size: 24px;
            font-weight: bold;
            color: white;
        }
        
        .card-preview-year {
            color: white;
            font-weight: bold;
        }
        
        .student-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 3px solid white;
            margin: 0 auto 15px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            overflow: hidden;
        }
        
        .student-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .qr-code {
            width: 120px;
            height: 120px;
            background: white;
            padding: 5px;
            margin: 10px auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qr-code img {
            max-width: 100%;
            max-height: 100%;
        }
        
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
        
        /* ========== OPTIONS PAR LOT ========== */
        .batch-options {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
                    <a href="cartes_etudiant.php" class="nav-link active">
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
                            <i class="fas fa-id-card me-2"></i>
                            Gestion des Cartes Étudiant
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
            
            <!-- ========== CONTENU PRINCIPAL ========== -->
            <div class="row fade-in">
                <!-- Colonne gauche (Liste des étudiants) -->
                <div class="col-lg-8">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-list me-2"></i> Liste des Étudiants</h5>
                            <span class="badge bg-primary"><?php echo count($etudiants); ?> étudiants actifs</span>
                        </div>
                        <div class="card-body">
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Matricule</th>
                                            <th>Nom & Prénom</th>
                                            <th>Filière</th>
                                            <th>Niveau</th>
                                            <th>Statut Carte</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($etudiants)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <div class="alert alert-info mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>Aucun étudiant actif trouvé
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach($etudiants as $etudiant): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($etudiant['matricule']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'Non assigné'); ?></td>
                                            <td><?php echo htmlspecialchars($etudiant['niveau_libelle'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if(!empty($etudiant['qr_code_data'])): ?>
                                                <span class="badge bg-success">Générée</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning">À générer</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button onclick="previewCard(<?php echo htmlspecialchars(json_encode($etudiant)); ?>)" 
                                                            class="btn btn-info" title="Prévisualiser">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                                        <button type="submit" name="generate_card" class="btn btn-success" title="Générer la carte">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                    </form>
                                                    <?php if(!empty($etudiant['qr_code_data'])): ?>
                                                    <a href="download_card.php?id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn btn-primary" title="Télécharger">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Colonne droite (Options et prévisualisation) -->
                <div class="col-lg-4">
                    <!-- Génération par lot -->
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i> Génération par Lot</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="batch_generate.php">
                                <div class="mb-3">
                                    <label class="form-label">Sélectionner les étudiants</label>
                                    <select multiple class="form-select" name="etudiant_ids[]" size="5" style="height: 150px;">
                                        <?php foreach($etudiants as $etudiant): ?>
                                        <option value="<?php echo $etudiant['id']; ?>">
                                            <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Format d'impression</label>
                                    <select class="form-select" name="print_format">
                                        <option value="a4">A4 (6 cartes par page)</option>
                                        <option value="a4_8">A4 (8 cartes par page)</option>
                                        <option value="single">Carte individuelle</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Options supplémentaires</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_qr" id="include_qr" checked>
                                        <label class="form-check-label" for="include_qr">
                                            Inclure QR Code
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_photo" id="include_photo">
                                        <label class="form-check-label" for="include_photo">
                                            Inclure photo (si disponible)
                                        </label>
                                    </div>
                                </div>
                                
                                <button type="submit" name="generate_batch" class="btn btn-primary btn-action w-100">
                                    <i class="fas fa-bolt me-2"></i> Générer en Lot
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Prévisualisation Carte -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-eye me-2"></i> Prévisualisation Carte</h5>
                        </div>
                        <div class="card-body text-center">
                            <div id="cardPreview" class="card-preview">
                                <div class="card-preview-header">
                                    <div class="card-preview-logo">ISGI</div>
                                    <div class="card-preview-year">2025-2026</div>
                                </div>
                                
                                <div class="student-photo">
                                    <i class="fas fa-user fa-3x"></i>
                                </div>
                                
                                <h4 id="previewName" class="text-center mb-2" style="color: white;">Prénom NOM</h4>
                                <p id="previewMatricule" class="text-center mb-1" style="color: rgba(255,255,255,0.8);">Matricule: XXXXXX</p>
                                <p id="previewFiliere" class="text-center mb-3" style="color: rgba(255,255,255,0.8);">Filière - Niveau</p>
                                
                                <div class="qr-code">
                                    <img id="previewQr" src="" alt="QR Code" class="img-fluid">
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-6 text-start">
                                        <small style="color: rgba(255,255,255,0.8);">Date d'émission: <?php echo date('d/m/Y'); ?></small>
                                    </div>
                                    <div class="col-6 text-end">
                                        <small style="color: rgba(255,255,255,0.8);">Signature</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-3">
                                <button onclick="printCard()" class="btn btn-success btn-action">
                                    <i class="fas fa-print me-2"></i> Imprimer la carte
                                </button>
                                <button onclick="downloadCard()" class="btn btn-primary btn-action">
                                    <i class="fas fa-download me-2"></i> Télécharger en PDF
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Actions rapides -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-cogs me-2"></i> Actions rapides</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="etudiants.php" class="btn btn-outline-info btn-action">
                                    <i class="fas fa-user-graduate me-2"></i>Gérer les étudiants
                                </a>
                                <button onclick="generateAllCards()" class="btn btn-outline-warning btn-action">
                                    <i class="fas fa-magic me-2"></i>Générer toutes les cartes
                                </button>
                                <a href="rapports_academiques.php" class="btn btn-outline-success btn-action">
                                    <i class="fas fa-chart-bar me-2"></i>Statistiques cartes
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ========== PIED DE PAGE ========== -->
            <footer class="mt-5 pt-3 border-top text-center text-muted">
                <small>
                    &copy; <?php echo date('Y'); ?> ISGI - Système de Gestion Académique | 
                    Module Cartes Étudiant | 
                    <span id="current-time"></span>
                </small>
            </footer>
        </div>
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
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
    
    // ========== FONCTIONS SPÉCIFIQUES AUX CARTES ==========
    
    // Prévisualiser une carte étudiant
    function previewCard(etudiant) {
        document.getElementById('previewName').textContent = etudiant.prenom + ' ' + etudiant.nom;
        document.getElementById('previewMatricule').textContent = 'Matricule: ' + etudiant.matricule;
        document.getElementById('previewFiliere').textContent = (etudiant.filiere_nom || 'Non assigné') + ' - ' + (etudiant.niveau_libelle || 'N/A');
        
        // Générer un QR code de prévisualisation
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=${encodeURIComponent(etudiant.matricule)}`;
        document.getElementById('previewQr').src = qrUrl;
        
        // Scroll vers la prévisualisation
        document.getElementById('cardPreview').scrollIntoView({ behavior: 'smooth' });
    }
    
    // Imprimer la carte
    function printCard() {
        const cardElement = document.getElementById('cardPreview');
        const studentName = document.getElementById('previewName').textContent;
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Impression Carte Étudiant - ${studentName}</title>
                <style>
                    @media print {
                        @page {
                            size: A4;
                            margin: 10mm;
                        }
                        body { 
                            margin: 0;
                            padding: 0;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            min-height: 100vh;
                            background: white !important;
                        }
                    }
                    body { 
                        font-family: Arial, sans-serif; 
                        margin: 0;
                        padding: 20px;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                    }
                    .card-preview { 
                        border: 2px solid #007bff;
                        border-radius: 10px;
                        padding: 20px;
                        width: 400px;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                    }
                    .student-photo {
                        width: 100px;
                        height: 100px;
                        border-radius: 50%;
                        border: 3px solid white;
                        margin: 0 auto 15px;
                        background: #f8f9fa;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #333;
                    }
                    .qr-code {
                        width: 120px;
                        height: 120px;
                        background: white;
                        padding: 5px;
                        margin: 10px auto;
                    }
                    .qr-code img {
                        width: 100%;
                        height: 100%;
                        object-fit: contain;
                    }
                    .no-print { display: none; }
                </style>
            </head>
            <body>
                ${cardElement.outerHTML}
                <script>
                    window.onload = function() { 
                        window.print(); 
                        setTimeout(function() { window.close(); }, 1000);
                    }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // Télécharger la carte en PDF
    function downloadCard() {
        const studentName = document.getElementById('previewName').textContent;
        const matricule = document.getElementById('previewMatricule').textContent.replace('Matricule: ', '');
        
        if (confirmAction(`Télécharger la carte de ${studentName} ?`)) {
            // Créer un formulaire pour télécharger le PDF
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_card.php';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'download_card';
            input.value = 'true';
            
            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'student_name';
            nameInput.value = studentName;
            
            const matriculeInput = document.createElement('input');
            matriculeInput.type = 'hidden';
            matriculeInput.name = 'matricule';
            matriculeInput.value = matricule;
            
            form.appendChild(input);
            form.appendChild(nameInput);
            form.appendChild(matriculeInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
    }
    
    // Générer toutes les cartes
    function generateAllCards() {
        if (confirmAction('Voulez-vous générer les cartes pour tous les étudiants actifs ? Cette opération peut prendre quelques minutes.')) {
            window.location.href = 'batch_generate.php?action=generate_all';
        }
    }
    
    // Sélection multiple avec Ctrl/Cmd
    function initMultiSelect() {
        const selectElement = document.querySelector('select[name="etudiant_ids[]"]');
        if (selectElement) {
            selectElement.addEventListener('mousedown', function(e) {
                e.preventDefault();
                
                const option = e.target;
                if (option.tagName === 'OPTION') {
                    option.selected = !option.selected;
                    this.dispatchEvent(new Event('change'));
                }
            });
        }
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
        
        // Initialiser la sélection multiple
        initMultiSelect();
        
        // Prévisualisation par défaut (premier étudiant)
        <?php if(!empty($etudiants)): ?>
        setTimeout(() => {
            previewCard(<?php echo json_encode($etudiants[0]); ?>);
        }, 500);
        <?php endif; ?>
        
        // Ajouter des tooltips Bootstrap
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        
        // Auto-sélection pour le formulaire de lot
        const batchSelect = document.querySelector('select[name="etudiant_ids[]"]');
        if (batchSelect) {
            // Sélectionner les 5 premiers par défaut
            Array.from(batchSelect.options).slice(0, 5).forEach(option => {
                option.selected = true;
            });
        }
        
        // Configurer les boutons d'action
        const actionButtons = document.querySelectorAll('.btn-action');
        actionButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                this.classList.add('disabled');
                setTimeout(() => {
                    this.classList.remove('disabled');
                }, 1000);
            });
        });
    });
    </script>
</body>
</html>