<?php
// dashboard/surveillant/generer_qr.php

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

$pageTitle = "Générer QR Codes";

// Inclure la bibliothèque QR Code
error_reporting(E_ALL & ~E_DEPRECATED);
require_once ROOT_PATH . '/libs/phpqrcode/qrlib.php';
error_reporting(E_ALL);

// Fonction pour générer un QR code
function generateQRCode($data, $filename) {
    $path = ROOT_PATH . '/uploads/qrcodes/';
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    
    $filepath = $path . $filename;
    
    // Générer le QR code
    QRcode::png($data, $filepath, QR_ECLEVEL_H, 10, 2);
    
    // Retourner le chemin complet pour l'affichage web
    return 'http://localhost/isgi_system/uploads/qrcodes/' . $filename;
}

// Traitement du formulaire de génération
$generated_qr = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'generate_student':
                $student_id = $_POST['student_id'] ?? 0;
                $type = $_POST['qr_type'] ?? 'etudiant';
                
                // Récupérer les infos de l'étudiant
                $query = "SELECT * FROM etudiants WHERE id = :id AND site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':id' => $student_id, ':site_id' => $site_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception('Étudiant non trouvé');
                }
                
                // Générer les données du QR code
                $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                          "NOM:" . $student['nom'] . "|" .
                          "PRENOM:" . $student['prenom'] . "|" .
                          "SITE:" . $site_id . "|" .
                          "TYPE:etudiant|" .
                          "DATE:" . date('YmdHis');
                
                $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                // Mettre à jour la base de données
                $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE id = :id";
                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':qr_data' => $qr_data,
                    ':id' => $student_id
                ]);
                
                $generated_qr = [
                    'type' => 'Étudiant',
                    'name' => $student['nom'] . ' ' . $student['prenom'],
                    'matricule' => $student['matricule'],
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_' . $student['matricule'] . '.png'
                ];
                break;
                
            case 'generate_class':
                $class_id = $_POST['class_id'] ?? 0;
                
                // Récupérer les infos de la classe
                $query = "SELECT * FROM classes WHERE id = :id AND site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':id' => $class_id, ':site_id' => $site_id]);
                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$class) {
                    throw new Exception('Classe non trouvée');
                }
                
                // Récupérer les étudiants de la classe
                $query = "SELECT matricule, nom, prenom FROM etudiants WHERE classe_id = :class_id AND statut = 'actif'";
                $stmt = $db->prepare($query);
                $stmt->execute([':class_id' => $class_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Générer les QR codes pour chaque étudiant
                $qr_codes = [];
                foreach($students as $student) {
                    $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                              "NOM:" . $student['nom'] . "|" .
                              "PRENOM:" . $student['prenom'] . "|" .
                              "CLASSE:" . $class_id . "|" .
                              "SITE:" . $site_id . "|" .
                              "TYPE:etudiant|" .
                              "DATE:" . date('YmdHis');
                    
                    $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
                    $qr_path = generateQRCode($qr_data, $filename);
                    
                    $qr_codes[] = [
                        'student' => $student,
                        'qr_path' => $qr_path,
                        'qr_data' => $qr_data
                    ];
                    
                    // Mettre à jour la base
                    $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE matricule = :matricule";
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([
                        ':qr_data' => $qr_data,
                        ':matricule' => $student['matricule']
                    ]);
                }
                
                $generated_qr = [
                    'type' => 'Classe',
                    'name' => $class['nom'],
                    'qr_codes' => $qr_codes,
                    'count' => count($qr_codes)
                ];
                break;
                
            case 'generate_surveillant':
                // QR code pour le surveillant lui-même
                $qr_data = "SURVEILLANT:" . $surveillant_id . "|" .
                          "SITE:" . $site_id . "|" .
                          "TYPE:surveillant|" .
                          "DATE:" . date('YmdHis') . "|" .
                          "AUTH:" . md5($surveillant_id . $site_id . date('Ymd'));
                
                $filename = 'surveillant_' . $surveillant_id . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                $generated_qr = [
                    'type' => 'Surveillant',
                    'name' => 'QR Code Personnel',
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_Surveillant_' . date('Ymd') . '.png'
                ];
                break;
                
            case 'generate_custom':
                $custom_data = $_POST['custom_data'] ?? '';
                $custom_label = $_POST['custom_label'] ?? 'Personnalisé';
                
                if (empty($custom_data)) {
                    throw new Exception('Veuillez entrer des données pour le QR code');
                }
                
                $qr_data = "CUSTOM:" . base64_encode($custom_data) . "|" .
                          "TYPE:custom|" .
                          "DATE:" . date('YmdHis') . "|" .
                          "LABEL:" . $custom_label;
                
                $filename = 'custom_' . md5($custom_data) . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                $generated_qr = [
                    'type' => 'Personnalisé',
                    'name' => $custom_label,
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_' . $custom_label . '_' . date('Ymd') . '.png'
                ];
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Récupérer les étudiants et classes pour les formulaires
$query_students = "SELECT id, matricule, nom, prenom FROM etudiants WHERE site_id = :site_id AND statut = 'actif' ORDER BY nom, prenom";
$stmt_students = $db->prepare($query_students);
$stmt_students->execute([':site_id' => $site_id]);
$students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

$query_classes = "SELECT id, nom FROM classes WHERE site_id = :site_id ORDER BY nom";
$stmt_classes = $db->prepare($query_classes);
$stmt_classes->execute([':site_id' => $site_id]);
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);
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
    
    /* QR Cards */
    .qr-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .qr-card:hover {
        transform: translateY(-2px);
    }
    
    .qr-card .card-header {
        background-color: rgba(0,0,0,0.02);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .qr-card .card-body {
        padding: 20px;
    }
    
    /* QR Preview */
    .qr-preview {
        width: 200px;
        height: 200px;
        border: 2px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-color);
        margin: 0 auto;
        overflow: hidden;
    }
    
    .qr-preview img {
        max-width: 100%;
        max-height: 100%;
        padding: 10px;
        background: var(--card-bg);
    }
    
    /* QR Type Icons */
    .qr-type-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        margin: 0 auto 10px;
    }
    
    .type-student { background-color: rgba(52, 152, 219, 0.1); color: var(--secondary-color); }
    .type-class { background-color: rgba(39, 174, 96, 0.1); color: var(--success-color); }
    .type-surveillant { background-color: rgba(231, 76, 60, 0.1); color: var(--accent-color); }
    .type-custom { background-color: rgba(243, 156, 18, 0.1); color: var(--warning-color); }
    
    /* Tabs */
    .nav-tabs {
        border-bottom: 1px solid var(--border-color);
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        flex-wrap: nowrap;
    }
    
    .nav-tabs .nav-link {
        white-space: nowrap;
        border: none;
        border-bottom: 3px solid transparent;
        color: var(--text-muted);
        padding: 10px 15px;
        border-radius: 0;
    }
    
    .nav-tabs .nav-link.active {
        color: var(--primary-color);
        background: none;
        border-bottom-color: var(--primary-color);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 5px 10px;
        font-weight: 500;
        border-radius: 20px;
    }
    
    .badge-qr {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
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
        
        /* QR Preview */
        .qr-preview {
            width: 150px;
            height: 150px;
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
        
        /* Forms */
        .row .col-md-6 {
            margin-bottom: 15px;
        }
        
        /* Tabs */
        .nav-tabs .nav-link {
            padding: 8px 12px;
            font-size: 14px;
        }
        
        /* QR Actions */
        .qr-actions .btn {
            padding: 8px 15px;
            font-size: 14px;
        }
    }
    
    @media (max-width: 576px) {
        /* QR Preview */
        .qr-preview {
            width: 120px;
            height: 120px;
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
        
        /* Form controls */
        .form-control, .form-select {
            font-size: 14px;
            padding: 8px 12px;
        }
        
        /* QR Type Icons */
        .qr-type-icon {
            width: 40px;
            height: 40px;
            font-size: 16px;
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
        
        .qr-preview {
            width: 100px;
            height: 100px;
        }
        
        .qr-type-icon {
            width: 35px;
            height: 35px;
            font-size: 14px;
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
        
        .qr-preview {
            width: 180px;
            height: 180px;
        }
    }
    
    /* Carousel pour les QR codes de classe */
    .carousel-item img {
        max-width: 100%;
        max-height: 100%;
    }
    
    .carousel-control-prev,
    .carousel-control-next {
        width: 30px;
        height: 30px;
        background-color: rgba(0,0,0,0.5);
        border-radius: 50%;
        top: 50%;
        transform: translateY(-50%);
    }
    
    /* Animation pour le QR code généré */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    #resultCard {
        animation: fadeIn 0.5s ease-out;
    }
    
    /* Style pour les données QR */
    #qrDataText {
        font-family: 'Courier New', monospace;
        font-size: 12px;
        background: var(--bg-color);
        border-radius: 5px;
        padding: 10px;
        max-height: 100px;
        overflow-y: auto;
        word-break: break-all;
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
                <i class="fas fa-qrcode"></i>
                <span>Générer QR</span>
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
                <a href="generer_qr.php" class="nav-link active">
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
                            <i class="fas fa-barcode me-2"></i>
                            Générateur de QR Codes
                        </h1>
                        <p class="text-muted mb-0">
                            Générez des QR codes pour les étudiants, classes et plus
                        </p>
                    </div>
                    <div class="btn-group w-100 w-md-auto">
                        <button class="btn btn-success" onclick="downloadBatch()">
                            <i class="fas fa-download me-2"></i> <span class="d-none d-md-inline">Télécharger ZIP</span>
                        </button>
                        <button class="btn btn-primary" onclick="printAllQRCodes()">
                            <i class="fas fa-print me-2"></i> <span class="d-none d-md-inline">Imprimer Tous</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Alertes -->
            <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Options de Génération -->
            <div class="row">
                <!-- Onglets de navigation -->
                <div class="col-12 mb-4">
                    <div class="card qr-card">
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs" id="qrTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="studentTabBtn" data-bs-toggle="tab" 
                                            data-bs-target="#studentTabContent" type="button">
                                        <i class="fas fa-user-graduate me-2"></i>
                                        <span class="d-none d-sm-inline">Étudiant</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="classTabBtn" data-bs-toggle="tab" 
                                            data-bs-target="#classTabContent" type="button">
                                        <i class="fas fa-users me-2"></i>
                                        <span class="d-none d-sm-inline">Classe</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="surveillantTabBtn" data-bs-toggle="tab" 
                                            data-bs-target="#surveillantTabContent" type="button">
                                        <i class="fas fa-user-shield me-2"></i>
                                        <span class="d-none d-sm-inline">Surveillant</span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="customTabBtn" data-bs-toggle="tab" 
                                            data-bs-target="#customTabContent" type="button">
                                        <i class="fas fa-edit me-2"></i>
                                        <span class="d-none d-sm-inline">Personnalisé</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Contenu des onglets -->
                <div class="col-12">
                    <div class="tab-content" id="qrTabsContent">
                        <!-- Onglet Étudiant -->
                        <div class="tab-pane fade show active" id="studentTabContent" role="tabpanel">
                            <div class="card qr-card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-user-graduate me-2"></i>
                                        QR Code Étudiant
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="studentForm">
                                        <input type="hidden" name="action" value="generate_student">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sélectionner un étudiant</label>
                                                    <select class="form-select" name="student_id" required 
                                                            onchange="loadStudentInfo(this.value)">
                                                        <option value="">Choisir un étudiant...</option>
                                                        <?php foreach($students as $student): ?>
                                                        <option value="<?php echo $student['id']; ?>">
                                                            <?php echo htmlspecialchars($student['matricule'] . ' - ' . $student['nom'] . ' ' . $student['prenom']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Type de QR Code</label>
                                                    <select class="form-select" name="qr_type">
                                                        <option value="etudiant">QR Code Présence</option>
                                                        <option value="identite">QR Code Identité</option>
                                                        <option value="acces">QR Code Accès</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div id="studentInfo" class="alert alert-info" style="display: none;">
                                                    <h6>Informations de l'étudiant</h6>
                                                    <div id="studentDetails">
                                                        <!-- Rempli par JavaScript -->
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Options</label>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="auto_print" id="autoPrint">
                                                        <label class="form-check-label" for="autoPrint">
                                                            Imprimer automatiquement
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="fas fa-qrcode me-2"></i> Générer QR Code
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-lg ms-2" 
                                                    onclick="generateStudentBatch()">
                                                <i class="fas fa-bolt me-2"></i> Générer en Masse
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Onglet Classe -->
                        <div class="tab-pane fade" id="classTabContent" role="tabpanel">
                            <div class="card qr-card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-users me-2"></i>
                                        QR Codes par Classe
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="classForm">
                                        <input type="hidden" name="action" value="generate_class">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sélectionner une classe</label>
                                                    <select class="form-select" name="class_id" required 
                                                            onchange="loadClassInfo(this.value)">
                                                        <option value="">Choisir une classe...</option>
                                                        <?php foreach($classes as $class): ?>
                                                        <option value="<?php echo $class['id']; ?>">
                                                            <?php echo htmlspecialchars($class['nom']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Options de génération</label>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="include_all" id="includeAll" checked>
                                                        <label class="form-check-label" for="includeAll">
                                                            Tous les étudiants de la classe
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="only_missing" id="onlyMissing">
                                                        <label class="form-check-label" for="onlyMissing">
                                                            Seulement les étudiants sans QR code
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div id="classInfo" class="alert alert-info" style="display: none;">
                                                    <h6>Informations de la classe</h6>
                                                    <div id="classDetails">
                                                        <!-- Rempli par JavaScript -->
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Format de sortie</label>
                                                    <select class="form-select" name="output_format">
                                                        <option value="individual">QR codes individuels</option>
                                                        <option value="sheet">Feuille A4 (9 par page)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-qrcode me-2"></i> Générer pour la Classe
                                            </button>
                                            <button type="button" class="btn btn-outline-primary btn-lg ms-2" 
                                                    onclick="generateAllClasses()">
                                                <i class="fas fa-layer-group me-2"></i> Toutes les Classes
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Onglet Surveillant -->
                        <div class="tab-pane fade" id="surveillantTabContent" role="tabpanel">
                            <div class="card qr-card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-user-shield me-2"></i>
                                        QR Code Surveillant
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="surveillantForm">
                                        <input type="hidden" name="action" value="generate_surveillant">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="alert alert-warning">
                                                    <h6><i class="fas fa-info-circle me-2"></i>Information</h6>
                                                    <p class="mb-0 small">Ce QR code vous permettra d'accéder à des fonctionnalités 
                                                    spéciales et d'authentifier vos actions.</p>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Type d'accès</label>
                                                    <select class="form-select" name="access_type">
                                                        <option value="full">Accès complet</option>
                                                        <option value="presence">Présence uniquement</option>
                                                        <option value="scan">Scan uniquement</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <?php if(isset($_SESSION['user_name'])): ?>
                                                <div class="alert alert-info">
                                                    <h6>Vos informations</h6>
                                                    <p class="mb-1 small">
                                                        <strong>Nom:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                                                    </p>
                                                    <p class="mb-0 small">
                                                        <strong>Site:</strong> 
                                                        <?php 
                                                        $query = "SELECT nom FROM sites WHERE id = :site_id";
                                                        $stmt = $db->prepare($query);
                                                        $stmt->execute([':site_id' => $site_id]);
                                                        $site = $stmt->fetch(PDO::FETCH_ASSOC);
                                                        echo htmlspecialchars($site['nom'] ?? 'N/A');
                                                        ?>
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-warning btn-lg">
                                                <i class="fas fa-key me-2"></i> Générer QR Code Surveillant
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Onglet Personnalisé -->
                        <div class="tab-pane fade" id="customTabContent" role="tabpanel">
                            <div class="card qr-card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-edit me-2"></i>
                                        QR Code Personnalisé
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="customForm">
                                        <input type="hidden" name="action" value="generate_custom">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Libellé du QR code</label>
                                                    <input type="text" class="form-control" name="custom_label" 
                                                           placeholder="Ex: Salle de réunion, Matériel..." required>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Données à encoder</label>
                                                    <textarea class="form-control" name="custom_data" rows="4" 
                                                              placeholder="Entrez les données à encoder..." required></textarea>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Options avancées</label>
                                                    <div class="row g-2">
                                                        <div class="col-6">
                                                            <label class="form-label small">Couleur avant-plan</label>
                                                            <input type="color" class="form-control form-control-color" 
                                                                   name="fg_color" value="#000000" title="Couleur avant-plan">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small">Couleur arrière-plan</label>
                                                            <input type="color" class="form-control form-control-color" 
                                                                   name="bg_color" value="#ffffff" title="Couleur arrière-plan">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-info btn-lg">
                                                <i class="fas fa-magic me-2"></i> Générer QR Code Personnalisé
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Résultat de génération -->
            <?php if($generated_qr): ?>
            <div class="card qr-card mt-4" id="resultCard">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        QR Code Généré avec Succès
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-4 text-center mb-4 mb-lg-0">
                            <div class="qr-preview mb-3">
                                <?php if($generated_qr['type'] === 'Classe'): ?>
                                    <div id="classQRCarousel" class="carousel slide" data-bs-ride="carousel">
                                        <div class="carousel-inner">
                                            <?php foreach($generated_qr['qr_codes'] as $index => $qr): ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="<?php echo $qr['qr_path']; ?>" 
                                                     alt="QR Code <?php echo $qr['student']['nom']; ?>">
                                                <div class="carousel-caption d-none d-md-block">
                                                    <small><?php echo $qr['student']['nom']; ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button class="carousel-control-prev" type="button" data-bs-target="#classQRCarousel" data-bs-slide="prev">
                                            <span class="carousel-control-prev-icon"></span>
                                        </button>
                                        <button class="carousel-control-next" type="button" data-bs-target="#classQRCarousel" data-bs-slide="next">
                                            <span class="carousel-control-next-icon"></span>
                                        </button>
                                    </div>
                                    <p class="mt-2">
                                        <span class="badge bg-primary"><?php echo $generated_qr['count']; ?> QR codes</span>
                                    </p>
                                <?php else: ?>
                                    <img src="<?php echo $generated_qr['qr_path']; ?>" 
                                         alt="QR Code <?php echo $generated_qr['name']; ?>">
                                <?php endif; ?>
                            </div>
                            
                            <div class="qr-actions d-flex justify-content-center gap-2 flex-wrap">
                                <button class="btn btn-success" onclick="downloadQR('<?php echo $generated_qr['qr_path']; ?>', '<?php echo $generated_qr['download_name'] ?? 'qr_code.png'; ?>')">
                                    <i class="fas fa-download me-1"></i> Télécharger
                                </button>
                                <button class="btn btn-primary" onclick="printQR()">
                                    <i class="fas fa-print me-1"></i> Imprimer
                                </button>
                            </div>
                        </div>
                        
                        <div class="col-lg-8">
                            <h5><?php echo $generated_qr['name']; ?></h5>
                            <p class="text-muted mb-3">
                                <i class="fas fa-tag me-1"></i>
                                Type: <span class="badge-qr"><?php echo $generated_qr['type']; ?></span>
                                <?php if($generated_qr['type'] === 'Étudiant'): ?>
                                    | Matricule: <strong><?php echo $generated_qr['matricule']; ?></strong>
                                <?php endif; ?>
                            </p>
                            
                            <div class="mb-3">
                                <label class="form-label">Données encodées:</label>
                                <div id="qrDataText" class="mb-2">
                                    <?php echo htmlspecialchars($generated_qr['qr_data']); ?>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="copyQRData()">
                                    <i class="fas fa-copy me-1"></i> Copier les données
                                </button>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body p-3">
                                            <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i>Informations techniques</h6>
                                            <ul class="mb-0 small">
                                                <li>Date: <?php echo date('d/m/Y H:i:s'); ?></li>
                                                <li>Format: PNG</li>
                                                <li>Taille: 300x300 pixels</li>
                                                <li>Encodage: UTF-8</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body p-3">
                                            <h6 class="mb-2"><i class="fas fa-share-alt me-2"></i>Partage rapide</h6>
                                            <div class="btn-group w-100">
                                                <button class="btn btn-outline-primary btn-sm" onclick="shareQR('whatsapp')">
                                                    <i class="fab fa-whatsapp"></i>
                                                </button>
                                                <button class="btn btn-outline-info btn-sm" onclick="shareQR('email')">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                                <button class="btn btn-outline-dark btn-sm" onclick="shareQR('sms')">
                                                    <i class="fas fa-sms"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if($generated_qr['type'] === 'Classe'): ?>
                            <div class="mt-4">
                                <h6>Liste des QR codes générés:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Étudiant</th>
                                                <th>Matricule</th>
                                                <th>QR Code</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($generated_qr['qr_codes'] as $qr): ?>
                                            <tr>
                                                <td class="small"><?php echo htmlspecialchars($qr['student']['nom'] . ' ' . $qr['student']['prenom']); ?></td>
                                                <td><span class="badge bg-secondary small"><?php echo $qr['student']['matricule']; ?></span></td>
                                                <td><img src="<?php echo $qr['qr_path']; ?>" width="40" height="40"></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="downloadQR('<?php echo $qr['qr_path']; ?>', 'qr_<?php echo $qr['student']['matricule']; ?>.png')">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center">
                                    <button class="btn btn-primary" onclick="downloadClassZip()">
                                        <i class="fas fa-file-archive me-2"></i> Télécharger tous les QR codes (ZIP)
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
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
                Générateur QR Codes • 
                <?php echo date('d/m/Y H:i'); ?>
            </small>
        </div>
    </div>
    
    <!-- Modal de prévisualisation -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Prévisualisation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="qrPreview" class="qr-preview mx-auto mb-3" style="width: 200px; height: 200px;">
                        <!-- Prévisualisation dynamique -->
                    </div>
                </div>
            </div>
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
    });
    
    // Charger les infos de l'étudiant
    async function loadStudentInfo(studentId) {
        if (!studentId) return;
        
        try {
            const response = await fetch(`ajax/get_student_info.php?id=${studentId}`);
            const student = await response.json();
            
            const container = document.getElementById('studentInfo');
            const details = document.getElementById('studentDetails');
            
            if (student) {
                details.innerHTML = `
                    <p class="mb-1 small"><strong>Matricule:</strong> ${student.matricule}</p>
                    <p class="mb-1 small"><strong>Classe:</strong> ${student.classe || 'Non assigné'}</p>
                    <p class="mb-0 small"><strong>Téléphone:</strong> ${student.telephone || 'Non renseigné'}</p>
                `;
                container.style.display = 'block';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }
    
    // Charger les infos de la classe
    async function loadClassInfo(classId) {
        if (!classId) return;
        
        try {
            const response = await fetch(`ajax/get_class_info.php?id=${classId}`);
            const classe = await response.json();
            
            const container = document.getElementById('classInfo');
            const details = document.getElementById('classDetails');
            
            if (classe) {
                details.innerHTML = `
                    <p class="mb-1 small"><strong>Effectif:</strong> ${classe.effectif} étudiants</p>
                    <p class="mb-0 small"><strong>Avec QR code:</strong> ${classe.with_qr || 0} étudiants</p>
                `;
                container.style.display = 'block';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }
    
    // Télécharger un QR code
    function downloadQR(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // Télécharger tous les QR codes d'une classe en ZIP
    async function downloadClassZip() {
        Swal.fire({
            title: 'Préparation du ZIP',
            text: 'Création de l\'archive en cours...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        try {
            const response = await fetch('ajax/generate_class_zip.php');
            const blob = await response.blob();
            
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `qr_codes_classe_${new Date().toISOString().split('T')[0]}.zip`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            Swal.fire('Succès', 'Archive téléchargée avec succès', 'success');
        } catch (error) {
            Swal.fire('Erreur', 'Erreur lors de la création du ZIP', 'error');
            console.error('Erreur:', error);
        }
    }
    
    // Télécharger un batch de QR codes
    function downloadBatch() {
        Swal.fire({
            title: 'Génération en masse',
            input: 'select',
            inputOptions: {
                'all_students': 'Tous les étudiants',
                'all_classes': 'Toutes les classes',
                'missing_qr': 'Étudiants sans QR code'
            },
            inputPlaceholder: 'Sélectionner une option',
            showCancelButton: true,
            confirmButtonText: 'Générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `ajax/generate_batch.php?type=${result.value}`;
            }
        });
    }
    
    // Imprimer un QR code
    function printQR() {
        const printWindow = window.open('', '_blank');
        const qrCard = document.getElementById('resultCard');
        
        printWindow.document.write(`
            <html>
            <head>
                <title>QR Code - ${document.title}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .print-header { text-align: center; margin-bottom: 30px; }
                    .qr-container { text-align: center; margin: 30px 0; }
                    .qr-container img { max-width: 300px; height: auto; }
                    .info-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    .info-table td { padding: 8px; border: 1px solid #ddd; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="print-header">
                    <h2>QR Code ISGI</h2>
                    <p>Généré le ${new Date().toLocaleDateString()} à ${new Date().toLocaleTimeString()}</p>
                </div>
                ${qrCard.innerHTML}
                <div class="footer">
                    <p>© ${new Date().getFullYear()} ISGI - Institut Supérieur de Gestion et d'Informatique</p>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
    
    // Copier les données du QR code
    function copyQRData() {
        const qrData = document.getElementById('qrDataText').textContent;
        navigator.clipboard.writeText(qrData).then(() => {
            Swal.fire('Succès', 'Données copiées dans le presse-papier', 'success');
        });
    }
    
    // Partager le QR code
    function shareQR(platform) {
        const qrData = document.getElementById('qrDataText').textContent;
        
        let url = '';
        switch(platform) {
            case 'whatsapp':
                url = `https://wa.me/?text=${encodeURIComponent('Voici mon QR code ISGI: ' + qrData)}`;
                window.open(url, '_blank');
                break;
            case 'email':
                url = `mailto:?subject=QR Code ISGI&body=${encodeURIComponent('QR Code généré: ' + qrData)}`;
                window.location.href = url;
                break;
            case 'sms':
                url = `sms:?body=${encodeURIComponent('QR Code ISGI: ' + qrData)}`;
                window.location.href = url;
                break;
        }
    }
    
    // Générer en masse pour les étudiants
    function generateStudentBatch() {
        Swal.fire({
            title: 'Génération en masse',
            html: `
                <div class="text-start">
                    <p class="small">Sélectionnez les étudiants pour générer leurs QR codes:</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllStudents">
                        <label class="form-check-label small" for="selectAllStudents">
                            Tous les étudiants actifs
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="onlyWithoutQR">
                        <label class="form-check-label small" for="onlyWithoutQR">
                            Seulement ceux sans QR code
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'ajax/generate_student_batch.php';
            }
        });
    }
    
    // Générer pour toutes les classes
    function generateAllClasses() {
        Swal.fire({
            title: 'Confirmation',
            text: 'Générer des QR codes pour toutes les classes ? Cela peut prendre quelques minutes.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Oui, générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'ajax/generate_all_classes.php';
            }
        });
    }
    
    // Imprimer tous les QR codes
    function printAllQRCodes() {
        Swal.fire({
            title: 'Impression multiple',
            input: 'select',
            inputOptions: {
                'current_class': 'Classe actuelle',
                'all_students': 'Tous les étudiants',
                'missing_cards': 'Cartes manquantes'
            },
            inputPlaceholder: 'Sélectionner',
            showCancelButton: true,
            confirmButtonText: 'Imprimer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.open(`ajax/print_qrcodes.php?type=${result.value}`, '_blank');
            }
        });
    }
    
    // Validation des formulaires
    document.addEventListener('DOMContentLoaded', () => {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                this.classList.add('was-validated');
            });
        });
        
        // Initialiser le carousel si présent
        const carousel = document.getElementById('classQRCarousel');
        if (carousel) {
            new bootstrap.Carousel(carousel);
        }
    });
    </script>
</body>
</html>