<?php
session_start();

// Définir les chemins absolus
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('ASSETS_PATH', ROOT_PATH . '/assets');

// Inclure la configuration de la base de données
require_once '../config/database.php';

// Créer une classe SessionManager simplifiée pour commencer
class SessionManager {
    
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public static function getRoleId() {
        return $_SESSION['role_id'] ?? null;
    }
    
    public static function getSiteId() {
        return $_SESSION['site_id'] ?? null;
    }
    
    public static function getUserName() {
        return $_SESSION['user_name'] ?? null;
    }
    
    public static function login($userId, $roleId, $siteId = null, $userName = null) {
        $_SESSION['user_id'] = $userId;
        $_SESSION['role_id'] = $roleId;
        $_SESSION['site_id'] = $siteId;
        $_SESSION['user_name'] = $userName;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
    }
    
    public static function logout() {
        session_unset();
        session_destroy();
        session_start(); // Redémarrer pour les messages flash
    }
}

// Vérifier si l'utilisateur est connecté
if (!SessionManager::isLoggedIn() && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header('Location: ../auth/login.php');
    exit();
}

// Vérifier l'expiration de session (24h)
if (SessionManager::isLoggedIn()) {
    $timeout = 24 * 3600; // 24 heures
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        SessionManager::logout();
        header('Location: ../auth/login.php?expired=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}

// Déterminer le thème
$theme = isset($_COOKIE['isgi_theme']) ? $_COOKIE['isgi_theme'] : 'light';

// Récupérer le nom de la page pour le titre
$pageTitle = $pageTitle ?? 'ISGI - Tableau de Bord';

// Récupérer la connexion à la base de données
$db = Database::getInstance()->getConnection();
?>

<!DOCTYPE html>
<html lang="fr" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FullCalendar -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.11.0/main.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- jQuery (pour certains plugins) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
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
            --sidebar-bg: #2c3e50;
            --sidebar-text: #ffffff;
            --border-color: #dee2e6;
            --shadow-color: rgba(0, 0, 0, 0.1);
        }
        
        [data-theme="dark"] {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --info-color: #5bc0de;
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --sidebar-bg: #1a1a1a;
            --sidebar-text: #ffffff;
            --border-color: #333333;
            --shadow-color: rgba(0, 0, 0, 0.3);
        }
        
        * {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Layout principal */
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
            z-index: 1000;
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
        
        .sidebar-logo i {
            font-size: 24px;
        }
        
        .user-info {
            text-align: center;
            margin-bottom: 20px;
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
        
        /* Header du contenu */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        /* Cartes */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.03);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Tableaux */
        .table-container {
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px var(--shadow-color);
        }
        
        .table {
            color: var(--text-color);
            margin: 0;
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
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Formulaires */
        .form-control, .form-select {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        /* Boutons */
        .btn {
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn i {
            margin-right: 5px;
        }
        
        /* Badges */
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        /* Alertes */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
        }
        
        /* Mode responsive */
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
        
        /* Scrollbar personnalisée */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }
        
        ::-webkit-scurllbar-thumb {
            background: var(--secondary-color);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- La sidebar sera incluse dans chaque dashboard spécifique -->
        <!-- Le contenu principal commence ici -->