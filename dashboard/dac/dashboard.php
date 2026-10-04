<?php
// dashboard/dac/dashboard.php

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
function formatMoney($amount) {
    if (empty($amount) || $amount == 0) return '0 FCFA';
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function formatDateFr($date) {
    if (empty($date) || $date == '0000-00-00') return '';
    return date('d/m/Y', strtotime($date));
}

function formatDateTimeFr($datetime) {
    if (empty($datetime)) return '';
    return date('d/m/Y H:i', strtotime($datetime));
}

function getStatutBadge($statut) {
    $badges = [
        'actif' => 'success',
        'valide' => 'success', 
        'present' => 'success',
        'admis' => 'success',
        'en_attente' => 'warning',
        'en_cours' => 'warning',
        'planifie' => 'warning',
        'brouillon' => 'secondary',
        'annule' => 'danger',
        'rejete' => 'danger',
        'absent' => 'danger',
        'termine' => 'info',
        'validee' => 'info',
        'publie' => 'primary'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst($statut) . '</span>';
}

// Fonction pour vérifier si une colonne existe dans une table
function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM $table LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// 4. RÉCUPÉRATION DES DONNÉES
// ============================================
$stats = [
    'total_etudiants' => 0,
    'total_professeurs' => 0,
    'total_classes' => 0,
    'taux_presence' => 0,
    'examens_a_venir' => 0,
    'notes_attente' => 0,
    'reunions_planifiees' => 0,
    'total_inscriptions' => 0
];

$etudiants_recent = [];
$presence_today = [];
$examens_a_venir = [];
$reunions_a_venir = [];
$notes_attente = [];
$error = null;

try {
    $site_id = $_SESSION['site_id'] ?? 1;
    
    // Récupérer le nom du site
    $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    $_SESSION['site_name'] = $site['nom'] ?? 'ISGI';
    
    // 1. STATISTIQUES DE BASE
    // Étudiants actifs - Vérifier si la table a site_id
    $etudiants_query = "SELECT COUNT(*) as total FROM etudiants WHERE statut = 'actif'";
    if (columnExists($db, 'etudiants', 'site_id')) {
        $etudiants_query .= " AND site_id = ?";
        $stmt = $db->prepare($etudiants_query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($etudiants_query);
        $stmt->execute();
    }
    $stats['total_etudiants'] = $stmt->fetchColumn();
    
    // Professeurs actifs - Vérifier si la table a site_id
    $enseignants_query = "SELECT COUNT(*) as total FROM enseignants WHERE statut = 'actif'";
    if (columnExists($db, 'enseignants', 'site_id')) {
        $enseignants_query .= " AND site_id = ?";
        $stmt = $db->prepare($enseignants_query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($enseignants_query);
        $stmt->execute();
    }
    $stats['total_professeurs'] = $stmt->fetchColumn();
    
    // Classes - Cette table a site_id
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM classes WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $stats['total_classes'] = $stmt->fetchColumn();
    
    // Notes en attente - Vérifier si la table a site_id
    $bulletins_query = "SELECT COUNT(*) as total FROM bulletins WHERE statut = 'brouillon'";
    if (columnExists($db, 'bulletins', 'site_id')) {
        $bulletins_query .= " AND site_id = ?";
        $stmt = $db->prepare($bulletins_query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($bulletins_query);
        $stmt->execute();
    }
    $stats['notes_attente'] = $stmt->fetchColumn();
    
    // Présence aujourd'hui - Cette table a site_id
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT COUNT(DISTINCT etudiant_id) as presents FROM presences 
                         WHERE site_id = ? AND DATE(date_heure) = ? AND statut = 'present'");
    $stmt->execute([$site_id, $today]);
    $presents = $stmt->fetchColumn();
    
    if ($stats['total_etudiants'] > 0) {
        $stats['taux_presence'] = round(($presents / $stats['total_etudiants']) * 100, 1);
    }
    
    // Examens à venir (7 jours) - Vérifier les relations
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $examens_query = "SELECT COUNT(*) as total FROM calendrier_examens ce";
    
    // Vérifier si on peut joindre par site_id
    if (columnExists($db, 'classes', 'site_id')) {
        $examens_query .= " JOIN classes c ON ce.classe_id = c.id
                          WHERE c.site_id = ? AND ce.date_examen BETWEEN ? AND ? AND ce.statut = 'planifie'";
        $stmt = $db->prepare($examens_query);
        $stmt->execute([$site_id, $today, $nextWeek]);
    } else {
        $examens_query = "SELECT COUNT(*) as total FROM calendrier_examens 
                         WHERE date_examen BETWEEN ? AND ? AND statut = 'planifie'";
        $stmt = $db->prepare($examens_query);
        $stmt->execute([$today, $nextWeek]);
    }
    $stats['examens_a_venir'] = $stmt->fetchColumn();
    
    // Réunions planifiées - Vérifier si la table a site_id
    $reunions_query = "SELECT COUNT(*) as total FROM reunions 
                      WHERE date_reunion >= ? AND statut = 'planifiee'";
    if (columnExists($db, 'reunions', 'site_id')) {
        $reunions_query = "SELECT COUNT(*) as total FROM reunions 
                          WHERE site_id = ? AND date_reunion >= ? AND statut = 'planifiee'";
        $stmt = $db->prepare($reunions_query);
        $stmt->execute([$site_id, $today]);
    } else {
        $stmt = $db->prepare($reunions_query);
        $stmt->execute([$today]);
    }
    $stats['reunions_planifiees'] = $stmt->fetchColumn();
    
    // Inscriptions année en cours - Vérifier si la table a site_id
    $current_year = date('Y');
    $inscriptions_query = "SELECT COUNT(*) as total FROM inscriptions i
                          JOIN annees_academiques aa ON i.annee_academique_id = aa.id
                          WHERE aa.libelle LIKE ?";
    
    if (columnExists($db, 'inscriptions', 'site_id')) {
        $inscriptions_query = "SELECT COUNT(*) as total FROM inscriptions i
                              JOIN annees_academiques aa ON i.annee_academique_id = aa.id
                              WHERE i.site_id = ? AND aa.libelle LIKE ?";
        $stmt = $db->prepare($inscriptions_query);
        $stmt->execute([$site_id, "%$current_year%"]);
    } else {
        $stmt = $db->prepare($inscriptions_query);
        $stmt->execute(["%$current_year%"]);
    }
    $stats['total_inscriptions'] = $stmt->fetchColumn();
    
    // 2. DONNÉES DÉTAILLÉES
    // Étudiants récents
    $etudiants_recent_query = "SELECT e.*, f.nom as filiere_nom 
                              FROM etudiants e
                              LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                              LEFT JOIN filieres f ON i.filiere_id = f.id
                              WHERE e.statut = 'actif'";
    
    if (columnExists($db, 'etudiants', 'site_id')) {
        $etudiants_recent_query .= " AND e.site_id = ?";
        $etudiants_recent_query .= " ORDER BY e.date_inscription DESC LIMIT 5";
        $stmt = $db->prepare($etudiants_recent_query);
        $stmt->execute([$site_id]);
    } else {
        $etudiants_recent_query .= " ORDER BY e.date_inscription DESC LIMIT 5";
        $stmt = $db->prepare($etudiants_recent_query);
        $stmt->execute();
    }
    $etudiants_recent = $stmt->fetchAll();
    
    // Présences du jour - Cette table a site_id
    $stmt = $db->prepare("SELECT p.*, e.matricule, e.nom, e.prenom, m.nom as matiere_nom,
                         CASE p.type_presence
                             WHEN 'entree_ecole' THEN 'Entrée école'
                             WHEN 'sortie_ecole' THEN 'Sortie école'
                             WHEN 'entree_classe' THEN 'Entrée classe'
                             WHEN 'sortie_classe' THEN 'Sortie classe'
                             ELSE p.type_presence
                         END as type_presence_libelle
                         FROM presences p
                         JOIN etudiants e ON p.etudiant_id = e.id
                         LEFT JOIN matieres m ON p.matiere_id = m.id
                         WHERE p.site_id = ? AND DATE(p.date_heure) = ?
                         ORDER BY p.date_heure DESC 
                         LIMIT 8");
    $stmt->execute([$site_id, $today]);
    $presence_today = $stmt->fetchAll();
    
    // Examens à venir - Vérifier les relations
    $examens_det_query = "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom, te.nom as type_examen
                         FROM calendrier_examens ce
                         JOIN matieres m ON ce.matiere_id = m.id
                         JOIN classes c ON ce.classe_id = c.id
                         JOIN types_examens te ON ce.type_examen_id = te.id
                         WHERE ce.date_examen >= ? AND ce.statut = 'planifie'";
    
    if (columnExists($db, 'classes', 'site_id')) {
        $examens_det_query = "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom, te.nom as type_examen
                             FROM calendrier_examens ce
                             JOIN matieres m ON ce.matiere_id = m.id
                             JOIN classes c ON ce.classe_id = c.id
                             JOIN types_examens te ON ce.type_examen_id = te.id
                             WHERE c.site_id = ? AND ce.date_examen >= ? AND ce.statut = 'planifie'
                             ORDER BY ce.date_examen, ce.heure_debut 
                             LIMIT 5";
        $stmt = $db->prepare($examens_det_query);
        $stmt->execute([$site_id, $today]);
    } else {
        $examens_det_query .= " ORDER BY ce.date_examen, ce.heure_debut LIMIT 5";
        $stmt = $db->prepare($examens_det_query);
        $stmt->execute([$today]);
    }
    $examens_a_venir = $stmt->fetchAll();
    
    // Réunions à venir - Vérifier si la table a site_id
    $reunions_det_query = "SELECT r.*, CONCAT(u.nom, ' ', u.prenom) as organisateur_nom
                          FROM reunions r
                          JOIN utilisateurs u ON r.organisateur_id = u.id
                          WHERE r.date_reunion >= NOW()
                          ORDER BY r.date_reunion 
                          LIMIT 5";
    
    if (columnExists($db, 'reunions', 'site_id')) {
        $reunions_det_query = "SELECT r.*, CONCAT(u.nom, ' ', u.prenom) as organisateur_nom
                              FROM reunions r
                              JOIN utilisateurs u ON r.organisateur_id = u.id
                              WHERE r.site_id = ? AND r.date_reunion >= NOW()
                              ORDER BY r.date_reunion 
                              LIMIT 5";
        $stmt = $db->prepare($reunions_det_query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($reunions_det_query);
        $stmt->execute();
    }
    $reunions_a_venir = $stmt->fetchAll();
    
    // Notes en attente - Vérifier si la table a site_id
    $notes_query = "SELECT b.*, e.matricule, e.nom, e.prenom, aa.libelle as annee_libelle
                   FROM bulletins b
                   JOIN etudiants e ON b.etudiant_id = e.id
                   JOIN annees_academiques aa ON b.annee_academique_id = aa.id
                   WHERE b.statut = 'brouillon'";
    
    if (columnExists($db, 'bulletins', 'site_id')) {
        $notes_query .= " AND b.site_id = ?";
        $notes_query .= " ORDER BY b.date_creation DESC LIMIT 5";
        $stmt = $db->prepare($notes_query);
        $stmt->execute([$site_id]);
    } else {
        $notes_query .= " ORDER BY b.date_creation DESC LIMIT 5";
        $stmt = $db->prepare($notes_query);
        $stmt->execute();
    }
    $notes_attente = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur dashboard DAC: " . $e->getMessage());
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
    <title>DAC - Tableau de Bord | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js (optionnel) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        /* ========== TABLES ========== */
        /* ========== TABLES ========== */
.table {
    margin-bottom: 0;
    color: #333; /* Ajoutez cette ligne pour forcer la couleur du texte */
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
    color: #333; /* Ajoutez cette ligne */
}

.table tbody tr:hover {
    background-color: rgba(23, 162, 184, 0.05);
}

/* Assurez-vous que le texte des onglets est visible */
.nav-tabs .nav-link {
    color: #333; /* Texte noir pour les onglets */
}

.nav-tabs .nav-link.active {
    color: var(--info-color); /* Couleur active */
    background-color: #fff;
    border-color: #dee2e6 #dee2e6 #fff;
}

/* Améliorer la visibilité du texte dans les cartes de contenu */
.content-card {
    color: #333; /* Ajoutez cette ligne */
}
        
        /* ========== BADGES PERSONNALISÉS ========== */
        .badge-presence {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .presence-present {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .presence-absent {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .presence-retard {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .presence-justifie {
            background-color: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
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
        
        /* ========== CARTE ÉTUDIANT ========== */
        .student-card-preview {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 25px;
            color: white;
            text-align: center;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .student-card-preview::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .student-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 4px solid white;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            position: relative;
            z-index: 1;
        }
        
        .student-avatar i {
            font-size: 40px;
            color: #667eea;
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
        /* FIX: Correction de la visibilité du texte */
#mainContent,
#academicTabs,
.tab-content,
.table,
.card-body {
    color: #333 !important;
}

.table td,
.table th {
    color: #333 !important;
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
                    <a href="dashboard.php" class="nav-link active">
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
                        <?php if($stats['reunions_planifiees'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['reunions_planifiees']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Assignation des classes</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de notes</span>
                        <?php if($stats['notes_attente'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['notes_attente']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="assignation_matieres.php
" class="nav-link">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Assignation des matières</span>
                    </a>
                    
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="messagerie.php" class="nav-link">
                        <i class="fas fa-envelope"></i>
                        <span>Messagerie</span>
                        <?php if($statistiques['non_lus'] > 0): ?>
                        <span class="nav-badge"><?php echo $statistiques['non_lus']; ?></span>
                        <?php endif; ?>
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
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Tableau de Bord - Directeur des Affaires Académiques
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
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- ========== STATISTIQUES PRINCIPALES ========== -->
            <div class="row fade-in">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon text-info">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_etudiants']; ?></div>
                        <div class="stat-label">Étudiants Actifs</div>
                        <a href="etudiants.php" class="btn btn-sm btn-outline-info mt-2">
                            <i class="fas fa-list me-1"></i> Voir la liste
                        </a>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon text-warning">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_professeurs']; ?></div>
                        <div class="stat-label">Professeurs Actifs</div>
                        <a href="#" class="btn btn-sm btn-outline-warning mt-2">
                            <i class="fas fa-eye me-1"></i> Consulter
                        </a>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon text-success">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['taux_presence']; ?>%</div>
                        <div class="stat-label">Taux de Présence Aujourd'hui</div>
                        <a href="presences.php" class="btn btn-sm btn-outline-success mt-2">
                            <i class="fas fa-chart-bar me-1"></i> Détails
                        </a>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon text-primary">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['examens_a_venir']; ?></div>
                        <div class="stat-label">Examens à Venir (7 jours)</div>
                        <a href="calendrier_examens.php" class="btn btn-sm btn-outline-primary mt-2">
                            <i class="fas fa-calendar-alt me-1"></i> Calendrier
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- ========== CONTENU PRINCIPAL ========== -->
            <div class="row fade-in">
                <!-- Colonne gauche (2/3) -->
                <div class="col-lg-8">
                    <!-- Onglets pour différentes sections -->
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line me-2"></i> Vue d'ensemble académique</h5>
                        </div>
                        <div class="card-body">
                            <ul class="nav nav-tabs" id="academicTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="presences-tab" data-bs-toggle="tab" data-bs-target="#presences" type="button">
                                        <i class="fas fa-calendar-check me-1"></i> Présences
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="etudiants-tab" data-bs-toggle="tab" data-bs-target="#etudiants" type="button">
                                        <i class="fas fa-user-graduate me-1"></i> Nouveaux étudiants
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="examens-tab" data-bs-toggle="tab" data-bs-target="#examens" type="button">
                                        <i class="fas fa-clipboard-check me-1"></i> Examens à venir
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button">
                                        <i class="fas fa-file-alt me-1"></i> Notes en attente
                                        <?php if($stats['notes_attente'] > 0): ?>
                                        <span class="badge bg-danger ms-1"><?php echo $stats['notes_attente']; ?></span>
                                        <?php endif; ?>
                                    </button>
                                </li>
                            </ul>
                            
                            <div class="tab-content mt-3" id="academicTabsContent">
                                <!-- Tab 1: Présences -->
                                <div class="tab-pane fade show active" id="presences">
                                    <?php if(empty($presence_today)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i> Aucune présence enregistrée aujourd'hui
                                    </div>
                                    <?php else: ?>
                                    <div class="table-container">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Matière</th>
                                                    <th>Type</th>
                                                    <th>Heure</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($presence_today as $presence): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($presence['prenom'] . ' ' . $presence['nom']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($presence['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? 'Entrée/Sortie'); ?></td>
                                                    <td><?php echo htmlspecialchars($presence['type_presence_libelle'] ?? $presence['type_presence']); ?></td>
                                                    <td><?php echo date('H:i', strtotime($presence['date_heure'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = 'presence-' . $presence['statut'];
                                                        echo '<span class="badge-presence ' . $badge_class . '">' . ucfirst($presence['statut']) . '</span>';
                                                        ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="presences.php" class="btn btn-info btn-action">
                                            <i class="fas fa-calendar-alt me-2"></i>Voir toutes les présences
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Tab 2: Étudiants -->
                                <div class="tab-pane fade" id="etudiants">
                                    <?php if(empty($etudiants_recent)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun nouvel étudiant récemment
                                    </div>
                                    <?php else: ?>
                                    <div class="table-container">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matricule</th>
                                                    <th>Nom & Prénom</th>
                                                    <th>Date Naissance</th>
                                                    <th>Date Inscription</th>
                                                    <th>Filière</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($etudiants_recent as $etudiant): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($etudiant['matricule']); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                                    </td>
                                                    <td><?php echo formatDateFr($etudiant['date_naissance']); ?></td>
                                                    <td><?php echo formatDateFr($etudiant['date_inscription']); ?></td>
                                                    <td><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'Non attribué'); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="etudiants.php" class="btn btn-info btn-action">
                                            <i class="fas fa-users me-2"></i>Voir tous les étudiants
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Tab 3: Examens -->
                                <div class="tab-pane fade" id="examens">
                                    <?php if(empty($examens_a_venir)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun examen programmé dans les 7 prochains jours
                                    </div>
                                    <?php else: ?>
                                    <div class="table-container">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Heure</th>
                                                    <th>Matière</th>
                                                    <th>Classe</th>
                                                    <th>Type</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($examens_a_venir as $examen): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo formatDateFr($examen['date_examen']); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php 
                                                            $jours_restants = floor((strtotime($examen['date_examen']) - time()) / (60*60*24));
                                                            if($jours_restants == 0) echo "Aujourd'hui";
                                                            elseif($jours_restants == 1) echo "Demain";
                                                            else echo "Dans $jours_restants jours";
                                                            ?>
                                                        </small>
                                                    </td>
                                                    <td><?php echo date('H:i', strtotime($examen['heure_debut'])); ?></td>
                                                    <td><?php echo htmlspecialchars($examen['matiere_nom']); ?></td>
                                                    <td><?php echo htmlspecialchars($examen['classe_nom']); ?></td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo htmlspecialchars($examen['type_examen']); ?></span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="calendrier_examens.php" class="btn btn-warning btn-action">
                                            <i class="fas fa-calendar-alt me-2"></i>Voir le calendrier complet
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Tab 4: Notes -->
                                <div class="tab-pane fade" id="notes">
                                    <?php if(empty($notes_attente)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Tous les bulletins sont validés
                                    </div>
                                    <?php else: ?>
                                    <div class="table-container">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Année Académique</th>
                                                    <th>Date Édition</th>
                                                    <th>Moyenne</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($notes_attente as $bulletin): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($bulletin['prenom'] . ' ' . $bulletin['nom']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($bulletin['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($bulletin['annee_libelle']); ?></td>
                                                    <td><?php echo formatDateFr($bulletin['date_edition']); ?></td>
                                                    <td>
                                                        <?php if($bulletin['moyenne_generale']): ?>
                                                        <span class="badge bg-<?php 
                                                            $moyenne = $bulletin['moyenne_generale'];
                                                            if($moyenne >= 10) echo 'success';
                                                            elseif($moyenne >= 8) echo 'warning';
                                                            else echo 'danger';
                                                        ?>">
                                                            <?php echo number_format($bulletin['moyenne_generale'], 2); ?>/20
                                                        </span>
                                                        <?php else: ?>
                                                        <span class="badge bg-secondary">Non calculée</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="bulletins.php?action=validate&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-sm btn-success" title="Valider">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <a href="bulletins.php?action=view&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-sm btn-info" title="Voir">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="bulletins.php" class="btn btn-success btn-action">
                                            <i class="fas fa-file-certificate me-2"></i>Gestion des bulletins
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calendrier académique -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-calendar me-2"></i> Calendrier académique</h5>
                            <a href="calendrier_academique.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-plus me-1"></i> Créer
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                // Dates importantes (exemple)
                                $important_dates = [
                                    ['date' => date('Y-m-15'), 'title' => 'Début DST Semestre 1', 'type' => 'exam'],
                                    ['date' => date('Y-m-30'), 'title' => 'Fin des cours Semestre 1', 'type' => 'course'],
                                    ['date' => date('Y-m-10', strtotime('+1 month')), 'title' => 'Début examens', 'type' => 'exam'],
                                    ['date' => date('Y-m-20', strtotime('+1 month')), 'title' => 'Publication notes', 'type' => 'result']
                                ];
                                ?>
                                <?php foreach($important_dates as $event): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center p-3 border rounded">
                                        <div class="text-center me-3">
                                            <div class="bg-<?php echo $event['type'] == 'exam' ? 'danger' : 'info'; ?> text-white rounded p-2" style="min-width: 70px;">
                                                <div class="fw-bold"><?php echo date('d', strtotime($event['date'])); ?></div>
                                                <div class="small"><?php echo date('M', strtotime($event['date'])); ?></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo $event['title']; ?></div>
                                            <div class="text-muted small"><?php echo formatDateFr($event['date']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Colonne droite (1/3) -->
                <div class="col-lg-4">
                    <!-- Carte Étudiant -->
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-id-card me-2"></i> Carte Étudiant</h5>
                        </div>
                        <div class="card-body">
                            <div class="student-card-preview">
                                <div class="student-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h5 class="mb-2">Jean DUPONT</h5>
                                <p class="mb-1">ISGI-2025-00123</p>
                                <p class="mb-3">BTS 1 - Comptabilité</p>
                                <div class="bg-white text-dark rounded p-2 d-inline-block">
                                    <small><i class="fas fa-calendar me-1"></i> Validité: 2025-2026</small>
                                </div>
                            </div>
                            <div class="d-grid gap-2 mt-3">
                                <a href="cartes_etudiant.php" class="btn btn-info btn-action">
                                    <i class="fas fa-print me-2"></i>Générer des cartes
                                </a>
                                <a href="cartes_etudiant.php?action=batch" class="btn btn-outline-info btn-action">
                                    <i class="fas fa-batch me-2"></i>Génération par lot
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Réunions à venir -->
                    <div class="content-card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-users me-2"></i> Réunions à venir</h5>
                            <a href="reunions.php?action=create" class="btn btn-sm btn-outline-success">
                                <i class="fas fa-plus me-1"></i> Nouvelle
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if(empty($reunions_a_venir)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune réunion planifiée
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($reunions_a_venir as $reunion): ?>
                                <div class="list-group-item border-0 mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($reunion['titre']); ?></h6>
                                            <p class="mb-1 small">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo formatDateTimeFr($reunion['date_reunion']); ?>
                                            </p>
                                            <p class="mb-0 small text-muted">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars($reunion['organisateur_nom']); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <span class="badge bg-<?php 
                                            switch($reunion['type_reunion']) {
                                                case 'pedagogique': echo 'info'; break;
                                                case 'administrative': echo 'primary'; break;
                                                case 'parent': echo 'success'; break;
                                                case 'urgence': echo 'danger'; break;
                                                default: echo 'secondary';
                                            }
                                            ?>">
                                                <?php echo ucfirst($reunion['type_reunion']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-center mt-3">
                                <a href="reunions.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-list me-1"></i>Toutes les réunions
                                </a>
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
                                <a href="rapports_academiques.php" class="btn btn-success btn-action">
                                    <i class="fas fa-chart-bar me-2"></i>Générer Rapport
                                </a>
                                <a href="export_data.php" class="btn btn-warning btn-action">
                                    <i class="fas fa-download me-2"></i>Exporter Données
                                </a>
                                <a href="calendrier_academique.php?action=create" class="btn btn-primary btn-action">
                                    <i class="fas fa-calendar-plus me-2"></i>Créer Calendrier
                                </a>
                                <a href="reunions.php?action=create" class="btn btn-info btn-action">
                                    <i class="fas fa-users me-2"></i>Planifier Réunion
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
                    Version 1.0 | 
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
        
        // Initialiser les onglets Bootstrap
        const tabTriggers = document.querySelectorAll('#academicTabs button[data-bs-toggle="tab"]');
        tabTriggers.forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const tab = new bootstrap.Tab(this);
                tab.show();
            });
        });
        
        // Actualiser automatiquement toutes les 10 minutes
        setTimeout(() => {
            location.reload();
        }, 10 * 60 * 1000);
        
        // Ajouter des tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        
        // Gérer les alertes
        const alertList = document.querySelectorAll('.alert');
        alertList.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
    
    // ========== FONCTIONS SPÉCIFIQUES ==========
    
    // Générer un rapport
    function generateReport() {
        if (confirmAction('Voulez-vous générer un rapport académique ?')) {
            window.location.href = 'rapports_academiques.php?action=generate';
        }
    }
    
    // Exporter les données
    function exportData(format) {
        if (confirmAction('Voulez-vous exporter les données ?')) {
            window.location.href = 'export_data.php?format=' + format;
        }
    }
    
    // Valider tous les bulletins
    function validateAllBulletins() {
        if (confirmAction('Voulez-vous valider tous les bulletins en attente ?')) {
            window.location.href = 'bulletins.php?action=validate_all';
        }
    }
    </script>
</body>
</html>