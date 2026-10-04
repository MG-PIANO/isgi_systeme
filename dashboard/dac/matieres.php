<?php
/**
 * Lier les étudiants aux classes - ISGI Congo (Version corrigée)
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// 1. VÉRIFICATION CONNEXION ET RÔLE DAC
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 5) {
    $root_path = dirname(dirname(dirname(__DIR__)));
    header("Location: $root_path/auth/login.php");
    exit();
}

// Connexion à la base de données
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4",
        "root",
        "admin1234",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer le nom du site
$site_id = $_SESSION['site_id'] ?? 1;
$stmt = $pdo->prepare("SELECT nom FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
$_SESSION['site_name'] = $site['nom'] ?? 'ISGI';

// Fonction pour récupérer les étudiants sans classe
function getEtudiantsSansClasse($pdo, $site_id) {
    $sql = "SELECT e.*, s.nom as site_nom 
            FROM etudiants e
            LEFT JOIN sites s ON e.site_id = s.id
            WHERE e.classe_id IS NULL AND e.statut = 'actif'";
    
    // Filtrer par site si l'utilisateur n'est pas administrateur
    if ($_SESSION['role_id'] != 1) { // 1 = admin
        $sql .= " AND e.site_id = ?";
        $sql .= " ORDER BY e.nom, e.prenom";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
    } else {
        $sql .= " ORDER BY e.nom, e.prenom";
        return $pdo->query($sql)->fetchAll();
    }
}

// Fonction pour récupérer les étudiants avec classe
function getEtudiantsAvecClasse($pdo, $site_id) {
    $sql = "SELECT e.*, c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            LEFT JOIN filieres f ON c.filiere_id = f.id
            LEFT JOIN niveaux n ON c.niveau_id = n.id
            LEFT JOIN sites s ON e.site_id = s.id
            WHERE e.statut = 'actif'";
    
    // Filtrer par site si l'utilisateur n'est pas administrateur
    if ($_SESSION['role_id'] != 1) {
        $sql .= " AND e.site_id = ?";
        $sql .= " ORDER BY c.nom, e.nom";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
    } else {
        $sql .= " ORDER BY c.nom, e.nom";
        return $pdo->query($sql)->fetchAll();
    }
}

// Fonction pour récupérer toutes les classes
function getClasses($pdo, $site_id) {
    $sql = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM classes c
            LEFT JOIN filieres f ON c.filiere_id = f.id
            LEFT JOIN niveaux n ON c.niveau_id = n.id
            LEFT JOIN sites s ON c.site_id = s.id";
    
    // Filtrer par site si l'utilisateur n'est pas administrateur
    if ($_SESSION['role_id'] != 1) {
        $sql .= " WHERE c.site_id = ?";
        $sql .= " ORDER BY c.site_id, c.niveau_id, c.nom";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
    } else {
        $sql .= " ORDER BY c.site_id, c.niveau_id, c.nom";
        return $pdo->query($sql)->fetchAll();
    }
}

// Fonction pour assigner un étudiant à une classe
function assignerEtudiantClasse($pdo, $etudiant_id, $classe_id) {
    $sql = "UPDATE etudiants SET classe_id = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$classe_id, $etudiant_id]);
}

// Fonction pour assigner plusieurs étudiants à une classe
function assignerEtudiantsClasse($pdo, $etudiants_ids, $classe_id) {
    if (empty($etudiants_ids)) return false;
    
    $placeholders = str_repeat('?,', count($etudiants_ids) - 1) . '?';
    $sql = "UPDATE etudiants SET classe_id = ? WHERE id IN ($placeholders)";
    
    $params = array_merge([$classe_id], $etudiants_ids);
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// Fonction pour désassigner un étudiant
function desassignerEtudiant($pdo, $etudiant_id) {
    $sql = "UPDATE etudiants SET classe_id = NULL WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$etudiant_id]);
}

// Fonction pour échapper les valeurs nulles
function escape($value) {
    return $value !== null ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assigner':
                if (isset($_POST['etudiant_id']) && isset($_POST['classe_id'])) {
                    if (assignerEtudiantClasse($pdo, $_POST['etudiant_id'], $_POST['classe_id'])) {
                        $message = 'Étudiant assigné à la classe avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors de l\'assignation';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'assigner_groupe':
                if (isset($_POST['etudiants']) && isset($_POST['classe_id_groupe'])) {
                    $etudiants_ids = array_map('intval', $_POST['etudiants']);
                    if (assignerEtudiantsClasse($pdo, $etudiants_ids, $_POST['classe_id_groupe'])) {
                        $message = count($etudiants_ids) . ' étudiant(s) assigné(s) avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors de l\'assignation groupée';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'desassigner':
                if (isset($_POST['etudiant_id'])) {
                    if (desassignerEtudiant($pdo, $_POST['etudiant_id'])) {
                        $message = 'Étudiant retiré de la classe avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors du retrait';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'assignation_auto':
                // Assignation automatique basée sur le site
                $etudiants = getEtudiantsSansClasse($pdo, $site_id);
                $classes = getClasses($pdo, $site_id);
                
                $success = 0;
                foreach ($etudiants as $etudiant) {
                    // Trouver une classe du même site
                    foreach ($classes as $classe) {
                        if ($classe['site_id'] == $etudiant['site_id']) {
                            if (assignerEtudiantClasse($pdo, $etudiant['id'], $classe['id'])) {
                                $success++;
                            }
                            break;
                        }
                    }
                }
                
                if ($success > 0) {
                    $message = $success . ' étudiant(s) assigné(s) automatiquement!';
                    $message_type = 'success';
                } else {
                    $message = 'Aucun étudiant assigné automatiquement';
                    $message_type = 'warning';
                }
                break;
        }
    }
}

// Récupérer les données
$etudiants_sans_classe = getEtudiantsSansClasse($pdo, $site_id);
$etudiants_avec_classe = getEtudiantsAvecClasse($pdo, $site_id);
$classes = getClasses($pdo, $site_id);

// Statistiques
$total_etudiants = count($etudiants_sans_classe) + count($etudiants_avec_classe);
$pourcentage_sans_classe = $total_etudiants > 0 ? round((count($etudiants_sans_classe) / $total_etudiants) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignation Étudiants ↔ Classes - ISGI</title>
    
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
        
        /* ========== CARTES DE STATISTIQUES ========== */
        .stat-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: var(--info-color);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--info-color), transparent);
        }
        
        .stat-icon {
            font-size: 2.8rem;
            margin-bottom: 20px;
            display: inline-block;
            padding: 15px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }
        
        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark-color);
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 15px;
            font-weight: 500;
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
        
        /* ========== ANCIENS STYLES (conservés) ========== */
        .header { 
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .etudiant-item { 
            background: white; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px;
        }
        .etudiant-assigne { border-left: 4px solid #28a745; background-color: #f8fff8; }
        .etudiant-sans-classe { border-left: 4px solid #dc3545; background-color: #fff8f8; }
        .classe-item { 
            background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px; cursor: pointer;
            transition: all 0.3s;
        }
        .classe-item:hover { background: #e9ecef; transform: translateY(-2px); }
        .classe-selected { border: 2px solid #0d6efd; background: #e7f1ff; }
        .badge-small { font-size: 0.75em; }
        .progress { height: 10px; }
        .empty-text { color: #6c757d; font-style: italic; }
        
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
            
            .stat-value {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .card-body {
                padding: 20px;
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
                    <a href="../dac/dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                </div>
                
                <!-- Gestion des étudiants -->
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="..../dac/etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste des étudiants</span>
                    </a>
                    <a href="..../dac/cartes_etudiant.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Cartes étudiant</span>
                    </a>
                    <a href="../dac/presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Gestion présence</span>
                    </a>
                    <a href="../dac/salles.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Salles de classe</span>
                    </a>
                </div>
                
                <!-- Calendrier & Examens -->
                <div class="nav-section">
                    <div class="nav-section-title">Calendrier & Examens</div>
                    <a href="../dac/calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier académique</span>
                    </a>
                    <a href="../dac/calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier examens</span>
                    </a>
                    <a href="../dac/reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions pédagogiques</span>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="../dac/matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Assignation des matières</span>
                    </a>
                    <a href="../dac/notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des notes</span>
                    </a>
                    <a href="../dac/bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de notes</span>
                    </a>
                    <a href="../dac/matieres.php" class="nav-link active">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Assignation classes</span>
                    </a>
                </div>
                
                <!-- Rapports & Statistiques -->
                <div class="nav-section">
                    <div class="nav-section-title">Compte</div>
                    <a href="../../auth/logout.php" class="nav-link">
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
                            <i class="fas fa-clipboard-check me-2"></i>
                            Assignation Étudiants ↔ Classes
                        </h1>
                        <p class="page-subtitle">
                            Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur'); ?></strong> | 
                            Site: <strong><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'ISGI'); ?></strong> | 
                            Lier les étudiants aux classes pour la saisie des notes
                        </p>
                    </div>
                    <div class="btn-group">
                        <a href="../dashboard.php" class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left"></i> Retour Dashboard
                        </a>
                        <a href="../notes.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-file-alt"></i> Saisie des notes
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Message -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon text-primary">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $total_etudiants; ?></div>
                        <div class="stat-label">Étudiants totaux</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon text-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo count($etudiants_avec_classe); ?></div>
                        <div class="stat-label">Avec classe</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo count($etudiants_sans_classe); ?></div>
                        <div class="stat-label">Sans classe</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon text-warning">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-value"><?php echo count($classes); ?></div>
                        <div class="stat-label">Classes disponibles</div>
                    </div>
                </div>
            </div>
            
            <!-- Barre de progression -->
            <div class="content-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Étudiants assignés : <?php echo count($etudiants_avec_classe); ?> / <?php echo $total_etudiants; ?></span>
                        <span><?php echo $pourcentage_sans_classe; ?>% sans classe</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?php echo (100 - $pourcentage_sans_classe); ?>%">
                            <?php echo (100 - $pourcentage_sans_classe); ?>%
                        </div>
                        <div class="progress-bar bg-danger" style="width: <?php echo $pourcentage_sans_classe; ?>%">
                            <?php echo $pourcentage_sans_classe; ?>%
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <form method="POST" action="" class="d-inline">
                            <input type="hidden" name="action" value="assignation_auto">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-robot"></i> Assignation automatique
                            </button>
                        </form>
                        <small class="text-muted ms-2">(Assigne les étudiants aux classes du même site)</small>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Colonne gauche : Étudiants sans classe -->
                <div class="col-md-6">
                    <div class="content-card">
                        <div class="card-header bg-danger text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    Étudiants sans classe (<?php echo count($etudiants_sans_classe); ?>)
                                </h5>
                                <?php if (count($etudiants_sans_classe) > 0): ?>
                                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#modalAssignerGroupe">
                                    <i class="fas fa-check-all"></i> Assigner en groupe
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($etudiants_sans_classe)): ?>
                                <div class="alert alert-success text-center">
                                    <i class="fas fa-check-circle fs-4"></i>
                                    <h5 class="mt-3">Tous les étudiants ont une classe !</h5>
                                    <p class="mb-0">Vous pouvez retourner à la saisie des notes.</p>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="" id="formSansClasse">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th width="50">
                                                        <input type="checkbox" id="selectAllSansClasse" class="form-check-input">
                                                    </th>
                                                    <th>Matricule</th>
                                                    <th>Nom & Prénom</th>
                                                    <th>Site</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($etudiants_sans_classe as $etudiant): ?>
                                                <tr class="table-danger">
                                                    <td>
                                                        <input type="checkbox" name="etudiants[]" value="<?php echo $etudiant['id']; ?>" 
                                                               class="form-check-input etudiant-check">
                                                    </td>
                                                    <td><strong><?php echo escape($etudiant['matricule']); ?></strong></td>
                                                    <td>
                                                        <?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($etudiant['site_nom'])): ?>
                                                        <span class="badge bg-info"><?php echo escape($etudiant['site_nom']); ?></span>
                                                        <?php else: ?>
                                                        <span class="badge bg-secondary">Non spécifié</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                                data-bs-toggle="modal" data-bs-target="#modalAssigner"
                                                                data-etudiant-id="<?php echo $etudiant['id']; ?>"
                                                                data-etudiant-nom="<?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?>"
                                                                data-site-id="<?php echo $etudiant['site_id']; ?>">
                                                            <i class="fas fa-person-plus"></i> Assigner
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Classes disponibles -->
                    <div class="content-card mt-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-building"></i> Classes disponibles</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($classes)): ?>
                                <div class="alert alert-warning">
                                    Aucune classe disponible. Créez d'abord des classes.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($classes as $classe): 
                                        // Compter les étudiants dans cette classe
                                        $sql = "SELECT COUNT(*) as count FROM etudiants WHERE classe_id = ? AND statut = 'actif'";
                                        $stmt = $pdo->prepare($sql);
                                        $stmt->execute([$classe['id']]);
                                        $result = $stmt->fetch();
                                        $etudiants_count = $result['count'];
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="classe-item" 
                                             onclick="utiliserClasse(<?php echo $classe['id']; ?>, '<?php echo escape($classe['nom']); ?>')"
                                             id="classe-<?php echo $classe['id']; ?>">
                                            <h6 class="mb-1"><?php echo escape($classe['nom']); ?></h6>
                                            <div class="small mb-2">
                                                <span class="badge bg-info"><?php echo escape($classe['site_nom']); ?></span>
                                                <span class="badge bg-success"><?php echo escape($classe['filiere_nom']); ?></span>
                                                <span class="badge bg-warning"><?php echo escape($classe['niveau_libelle']); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="badge bg-<?php echo $etudiants_count > 0 ? 'primary' : 'secondary'; ?>">
                                                    <?php echo $etudiants_count; ?> étudiant(s)
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-success"
                                                        onclick="assignerClasse(<?php echo $classe['id']; ?>)">
                                                    <i class="fas fa-check"></i> Utiliser
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Colonne droite : Étudiants avec classe -->
                <div class="col-md-6">
                    <div class="content-card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-check-circle"></i> 
                                Étudiants avec classe (<?php echo count($etudiants_avec_classe); ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($etudiants_avec_classe)): ?>
                                <div class="alert alert-warning text-center">
                                    <i class="fas fa-info-circle fs-4"></i>
                                    <h5 class="mt-3">Aucun étudiant avec classe</h5>
                                    <p class="mb-0">Assignez des étudiants aux classes à gauche.</p>
                                </div>
                            <?php else: ?>
                                <!-- Filtrer par classe -->
                                <div class="mb-3">
                                    <label class="form-label">Filtrer par classe :</label>
                                    <select class="form-select" onchange="filtrerParClasse(this.value)">
                                        <option value="">Toutes les classes</option>
                                        <?php
                                        $classes_unique = [];
                                        foreach ($etudiants_avec_classe as $etudiant) {
                                            if ($etudiant['classe_nom']) {
                                                $classes_unique[$etudiant['classe_id']] = $etudiant['classe_nom'];
                                            }
                                        }
                                        foreach ($classes_unique as $id => $nom): ?>
                                            <option value="<?php echo $id; ?>"><?php echo escape($nom); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Matricule</th>
                                                <th>Nom & Prénom</th>
                                                <th>Classe</th>
                                                <th>Filière/Niveau</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="etudiantsAvecClasse">
                                            <?php foreach ($etudiants_avec_classe as $etudiant): ?>
                                            <tr class="table-success" data-classe-id="<?php echo $etudiant['classe_id']; ?>">
                                                <td><strong><?php echo escape($etudiant['matricule']); ?></strong></td>
                                                <td><?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
                                                <td>
                                                    <?php if (!empty($etudiant['classe_nom'])): ?>
                                                    <span class="badge bg-primary"><?php echo escape($etudiant['classe_nom']); ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Non spécifié</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php 
                                                        $filiere = !empty($etudiant['filiere_nom']) ? escape($etudiant['filiere_nom']) : 'Non spécifié';
                                                        $niveau = !empty($etudiant['niveau_libelle']) ? escape($etudiant['niveau_libelle']) : 'Non spécifié';
                                                        echo $filiere . ' • ' . $niveau;
                                                        ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <form method="POST" action="" class="d-inline">
                                                        <input type="hidden" name="action" value="desassigner">
                                                        <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Retirer cet étudiant de la classe ?')">
                                                            <i class="fas fa-x-circle"></i> Retirer
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Statistiques par classe -->
                    <div class="content-card mt-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Répartition par classe</h5>
                        </div>
                        <div class="card-body">
                            <?php 
                            $total_etudiants_classe = count($etudiants_avec_classe);
                            $classes_stats = [];
                            
                            // Compter les étudiants par classe
                            foreach ($etudiants_avec_classe as $etudiant) {
                                if ($etudiant['classe_id']) {
                                    if (!isset($classes_stats[$etudiant['classe_id']])) {
                                        $classes_stats[$etudiant['classe_id']] = [
                                            'nom' => $etudiant['classe_nom'] ?? 'Non spécifié',
                                            'site' => $etudiant['site_nom'] ?? 'Non spécifié',
                                            'filiere' => $etudiant['filiere_nom'] ?? 'Non spécifié',
                                            'niveau' => $etudiant['niveau_libelle'] ?? 'Non spécifié',
                                            'count' => 0
                                        ];
                                    }
                                    $classes_stats[$etudiant['classe_id']]['count']++;
                                }
                            }
                            
                            if (empty($classes_stats)):
                            ?>
                                <div class="alert alert-info text-center">
                                    Aucune statistique disponible
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Classe</th>
                                                <th>Site</th>
                                                <th>Filière/Niveau</th>
                                                <th>Étudiants</th>
                                                <th>Pourcentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($classes_stats as $classe): 
                                                $pourcentage = $total_etudiants_classe > 0 ? 
                                                    round(($classe['count'] / $total_etudiants_classe) * 100) : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?php echo escape($classe['nom']); ?></strong></td>
                                                <td><?php echo escape($classe['site']); ?></td>
                                                <td>
                                                    <?php echo escape($classe['filiere']); ?> / 
                                                    <?php echo escape($classe['niveau']); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $classe['count']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height: 8px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $pourcentage; ?>%"></div>
                                                    </div>
                                                    <small><?php echo $pourcentage; ?>%</small>
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
        </div>
    </div>
    
    <!-- Modal pour assigner un étudiant -->
    <div class="modal fade" id="modalAssigner" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="assigner">
                    <input type="hidden" name="etudiant_id" id="modalEtudiantId">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-person-plus"></i> Assigner un étudiant</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Étudiant</label>
                            <input type="text" class="form-control" id="modalEtudiantNom" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sélectionner une classe</label>
                            <select name="classe_id" class="form-select" required>
                                <option value="">-- Choisir une classe --</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo escape($classe['nom']); ?> 
                                        (<?php echo escape($classe['site_nom']); ?> - 
                                         <?php echo escape($classe['filiere_nom']); ?> - 
                                         <?php echo escape($classe['niveau_libelle']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Assigner</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour assigner en groupe -->
    <div class="modal fade" id="modalAssignerGroupe" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="assigner_groupe">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-people-group"></i> Assignation groupée</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Les étudiants sélectionnés seront assignés à la même classe.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sélectionner une classe</label>
                            <select name="classe_id_groupe" class="form-select" required>
                                <option value="">-- Choisir une classe --</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo escape($classe['nom']); ?> 
                                        (<?php echo escape($classe['site_nom']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Étudiants sélectionnés (<span id="countSelected">0</span>)</label>
                            <div id="selectedEtudiants" class="border p-2 rounded" style="max-height: 200px; overflow-y: auto;">
                                <!-- Liste des étudiants sélectionnés -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Assigner les étudiants sélectionnés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ========== FONCTIONS UTILITAIRES ==========
        
        // Sélectionner/désélectionner tous les étudiants sans classe
        document.getElementById('selectAllSansClasse').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.etudiant-check');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedEtudiants();
        });
        
        // Mettre à jour la liste des étudiants sélectionnés
        function updateSelectedEtudiants() {
            const checkboxes = document.querySelectorAll('.etudiant-check:checked');
            const container = document.getElementById('selectedEtudiants');
            const countSpan = document.getElementById('countSelected');
            
            countSpan.textContent = checkboxes.length;
            container.innerHTML = '';
            
            if (checkboxes.length === 0) {
                container.innerHTML = '<div class="empty-text">Aucun étudiant sélectionné</div>';
                return;
            }
            
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const nom = row.cells[2].textContent.trim();
                const matricule = row.cells[1].textContent.trim();
                
                const div = document.createElement('div');
                div.className = 'small mb-1';
                div.textContent = matricule + ' - ' + nom;
                container.appendChild(div);
            });
        }
        
        // Écouter les changements sur les checkboxes
        document.querySelectorAll('.etudiant-check').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedEtudiants);
        });
        
        // Modal d'assignation individuelle
        const modalAssigner = document.getElementById('modalAssigner');
        modalAssigner.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const etudiantId = button.getAttribute('data-etudiant-id');
            const etudiantNom = button.getAttribute('data-etudiant-nom');
            
            document.getElementById('modalEtudiantId').value = etudiantId;
            document.getElementById('modalEtudiantNom').value = etudiantNom;
        });
        
        // Utiliser une classe pour assignation
        function assignerClasse(classeId) {
            const modal = new bootstrap.Modal(document.getElementById('modalAssignerGroupe'));
            modal.show();
            
            // Pré-sélectionner la classe
            document.querySelector('select[name="classe_id_groupe"]').value = classeId;
        }
        
        // Surligner la classe sélectionnée
        function utiliserClasse(classeId, nomClasse) {
            // Retirer la sélection précédente
            document.querySelectorAll('.classe-item').forEach(item => {
                item.classList.remove('classe-selected');
            });
            
            // Ajouter la sélection
            const element = document.getElementById('classe-' + classeId);
            if (element) {
                element.classList.add('classe-selected');
            }
            
            // Remplir le champ dans le modal d'assignation groupée
            document.querySelector('select[name="classe_id_groupe"]').value = classeId;
        }
        
        // Filtrer les étudiants par classe
        function filtrerParClasse(classeId) {
            const rows = document.querySelectorAll('#etudiantsAvecClasse tr');
            
            rows.forEach(row => {
                if (!classeId || row.getAttribute('data-classe-id') == classeId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // ========== GESTION RESPONSIVE ==========
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
        
        // ========== INITIALISATION ==========
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser la liste des étudiants sélectionnés
            updateSelectedEtudiants();
            
            // Gérer le responsive
            handleSidebarResponsive();
            window.addEventListener('resize', handleSidebarResponsive);
            
            // Bouton menu mobile
            document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
            
            // Actualiser automatiquement toutes les 5 minutes
            setTimeout(() => {
                location.reload();
            }, 5 * 60 * 1000);
        });
    </script>
</body>
</html>