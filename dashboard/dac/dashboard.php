<?php
// dashboard/dac/dashboard.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle DAC (ID 5 dans la table roles)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "DAC - Directeur des Affaires Académiques";
    
    // Récupérer l'ID du site de l'utilisateur
    $site_id = $_SESSION['site_id'] ?? null;
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
            case 'valide':
            case 'present':
            case 'admis':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
            case 'en_attente':
            case 'en_cours':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'termine':
            case 'validee':
                return '<span class="badge bg-info">Terminé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilisateur';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
    }
    
    // Initialiser les variables
    $stats = array(
        'total_etudiants' => 0,
        'total_professeurs' => 0,
        'total_classes' => 0,
        'total_matieres' => 0,
        'taux_presence' => 0,
        'examens_a_venir' => 0,
        'notes_attente' => 0,
        'reunions_planifiees' => 0
    );
    
    $etudiants_recent = array();
    $presence_today = array();
    $calendrier_academique = array();
    $examens_a_venir = array();
    $reunions_a_venir = array();
    $notes_attente = array();
    $classes = array();
    $error = null;
    
    // Récupérer les statistiques pour le site
    if ($site_id) {
        // Nombre total d'étudiants
        $query = "SELECT COUNT(*) as total FROM etudiants WHERE site_id = :site_id AND statut = 'actif'";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_etudiants'] = $result['total'] ?? 0;
        
        // Nombre total de professeurs
        $query = "SELECT COUNT(*) as total FROM enseignants WHERE site_id = :site_id AND statut = 'actif'";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_professeurs'] = $result['total'] ?? 0;
        
        // Nombre total de classes
        $query = "SELECT COUNT(*) as total FROM classes WHERE site_id = :site_id";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_classes'] = $result['total'] ?? 0;
        
        // Nombre total de matières
        $query = "SELECT COUNT(*) as total FROM matieres WHERE site_id = :site_id";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_matieres'] = $result['total'] ?? 0;
        
        // Taux de présence aujourd'hui
        $today = date('Y-m-d');
        $query = "SELECT COUNT(DISTINCT etudiant_id) as presents FROM presences 
                 WHERE site_id = :site_id AND DATE(date_heure) = :today AND statut = 'present'";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id, 'today' => $today]);
        $presents = $stmt->fetch(PDO::FETCH_ASSOC);
        $presents_count = $presents['presents'] ?? 0;
        
        if ($stats['total_etudiants'] > 0) {
            $stats['taux_presence'] = round(($presents_count / $stats['total_etudiants']) * 100, 1);
        }
        
        // Examens à venir (7 prochains jours)
        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $query = "SELECT COUNT(*) as total FROM calendrier_examens ce
                 JOIN classes c ON ce.classe_id = c.id
                 WHERE c.site_id = :site_id AND ce.date_examen BETWEEN :today AND :nextWeek 
                 AND ce.statut = 'planifie'";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id, 'today' => $today, 'nextWeek' => $nextWeek]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['examens_a_venir'] = $result['total'] ?? 0;
        
        // Notes en attente de validation
        $query = "SELECT COUNT(*) as total FROM bulletins 
                 WHERE statut = 'brouillon' AND site_id = :site_id";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['notes_attente'] = $result['total'] ?? 0;
        
        // Réunions planifiées
        $query = "SELECT COUNT(*) as total FROM reunions 
                 WHERE site_id = :site_id AND statut = 'planifiee'";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['reunions_planifiees'] = $result['total'] ?? 0;
        
        // Récupérer les étudiants récents (5 derniers)
        $query = "SELECT e.*, s.nom as site_nom 
                 FROM etudiants e 
                 JOIN sites s ON e.site_id = s.id
                 WHERE e.site_id = :site_id 
                 ORDER BY e.date_inscription DESC 
                 LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $etudiants_recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les présences du jour
        $query = "SELECT p.*, e.matricule, e.nom, e.prenom, m.nom as matiere_nom
                 FROM presences p
                 JOIN etudiants e ON p.etudiant_id = e.id
                 LEFT JOIN matieres m ON p.matiere_id = m.id
                 WHERE p.site_id = :site_id AND DATE(p.date_heure) = :today
                 ORDER BY p.date_heure DESC 
                 LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id, 'today' => $today]);
        $presence_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer le calendrier académique actuel
        $query = "SELECT ca.*, s.nom as site_nom, aa.libelle as annee_libelle
                 FROM calendrier_academique ca
                 JOIN sites s ON ca.site_id = s.id
                 JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                 WHERE ca.site_id = :site_id AND ca.statut IN ('planifie', 'en_cours')
                 ORDER BY ca.date_debut_cours DESC 
                 LIMIT 3";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $calendrier_academique = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les examens à venir
        $query = "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom, te.nom as type_examen
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN classes c ON ce.classe_id = c.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 WHERE c.site_id = :site_id AND ce.date_examen >= :today 
                 AND ce.statut = 'planifie'
                 ORDER BY ce.date_examen, ce.heure_debut 
                 LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id, 'today' => $today]);
        $examens_a_venir = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les réunions à venir
        $query = "SELECT r.*, CONCAT(u.nom, ' ', u.prenom) as organisateur_nom
                 FROM reunions r
                 JOIN utilisateurs u ON r.organisateur_id = u.id
                 WHERE r.site_id = :site_id AND r.date_reunion >= NOW()
                 ORDER BY r.date_reunion 
                 LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $reunions_a_venir = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les bulletins en attente
        $query = "SELECT b.*, e.matricule, e.nom, e.prenom, aa.libelle as annee_libelle
                 FROM bulletins b
                 JOIN etudiants e ON b.etudiant_id = e.id
                 JOIN annees_academiques aa ON b.annee_academique_id = aa.id
                 WHERE b.statut = 'brouillon' AND b.site_id = :site_id
                 ORDER BY b.date_creation DESC 
                 LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $notes_attente = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les classes
        $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle
                 FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN niveaux n ON c.niveau_id = n.id
                 WHERE c.site_id = :site_id
                 ORDER BY f.nom, n.ordre";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Récupérer les statistiques pour les graphiques (à ajouter dans la partie try/catch)
$today = date('Y-m-d');
$last_7_days = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    
    $query = "SELECT COUNT(*) as total_presences,
                     SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents
              FROM presences 
              WHERE site_id = ? AND DATE(date_heure) = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id, $date]);
    $day_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $last_7_days[] = [
        'date' => $date,
        'label' => date('d/m', strtotime($date)),
        'total' => $day_stats['total_presences'] ?? 0,
        'presents' => $day_stats['presents'] ?? 0
    ];
}

// Répartition par filière
$query = "SELECT f.nom as filiere, COUNT(e.id) as count
          FROM etudiants e
          JOIN inscriptions i ON e.id = i.etudiant_id
          JOIN filieres f ON i.filiere_id = f.id
          WHERE e.site_id = ? AND e.statut = 'actif'
          GROUP BY f.nom
          ORDER BY count DESC
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute([$site_id]);
$filiere_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Performance académique (moyennes)
$query = "SELECT 
            CASE 
                WHEN b.moyenne_generale >= 16 THEN '16-20'
                WHEN b.moyenne_generale >= 14 THEN '14-15.99'
                WHEN b.moyenne_generale >= 12 THEN '12-13.99'
                WHEN b.moyenne_generale >= 10 THEN '10-11.99'
                ELSE '0-9.99'
            END as range_moyenne,
            COUNT(*) as count
          FROM bulletins b
          JOIN etudiants e ON b.etudiant_id = e.id
          WHERE e.site_id = ? AND b.statut = 'valide'
          GROUP BY range_moyenne
          ORDER BY range_moyenne DESC";
$stmt = $db->prepare($query);
$stmt->execute([$site_id]);
$performance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    <!-- Chart.js pour les graphiques -->
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
        background-color: var(--info-color);
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
        background-color: var(--info-color);
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
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
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
    
    /* Carte étudiant */
    .student-card {
        border-left: 4px solid var(--info-color);
    }
    
    .student-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--info-color);
    }
    
    /* Badge présence */
    .badge-presence {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .presence-present { background-color: #d4edda; color: #155724; }
    .presence-absent { background-color: #f8d7da; color: #721c24; }
    .presence-retard { background-color: #fff3cd; color: #856404; }
    .presence-justifie { background-color: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Gestion Académique</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard DAC</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Académique</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Gestion des Étudiants</span>
                    </a>
                    <a href="cartes_etudiant.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Cartes Étudiant</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Gestion des Présences</span>
                    </a>
                    <a href="salles.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Salles de Classe</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Calendrier & Examens</div>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier Académique</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier d'Examens</span>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions Pédagogiques</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Évaluations & Notes</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des Notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de Notes</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Organisation Examens</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Statistiques</div>
                    <a href="rapports_academiques.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports Académiques</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques</span>
                    </a>
                    <a href="export_data.php" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Export des Données</span>
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
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Tableau de Bord - Directeur des Affaires Académiques
                        </h2>
                        <p class="text-muted mb-0">Gestion académique et pédagogique du site</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-primary" onclick="genererRapport()">
                            <i class="fas fa-file-pdf"></i> Rapport
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
                <div class="col-md-3 col-sm-6">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_etudiants']; ?></div>
                        <div class="stat-label">Étudiants Actifs</div>
                        <a href="etudiants.php" class="btn btn-sm btn-outline-info mt-2">Voir liste</a>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_professeurs']; ?></div>
                        <div class="stat-label">Professeurs</div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['taux_presence']; ?>%</div>
                        <div class="stat-label">Présence Aujourd'hui</div>
                        <a href="presences.php" class="btn btn-sm btn-outline-success mt-2">Détails</a>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['examens_a_venir']; ?></div>
                        <div class="stat-label">Examens (7 jours)</div>
                        <a href="calendrier_examens.php" class="btn btn-sm btn-outline-primary mt-2">Calendrier</a>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Contenu Principal avec Onglets -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <ul class="nav nav-tabs card-header-tabs" id="mainTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="presences-tab" data-bs-toggle="tab" data-bs-target="#presences" type="button">
                                        <i class="fas fa-calendar-check me-2"></i>Présences du Jour
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="etudiants-tab" data-bs-toggle="tab" data-bs-target="#etudiants" type="button">
                                        <i class="fas fa-user-graduate me-2"></i>Nouveaux Étudiants
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="examens-tab" data-bs-toggle="tab" data-bs-target="#examens" type="button">
                                        <i class="fas fa-clipboard-check me-2"></i>Examens à Venir
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button">
                                        <i class="fas fa-file-alt me-2"></i>Notes en Attente
                                        <?php if($stats['notes_attente'] > 0): ?>
                                        <span class="badge bg-danger ms-1"><?php echo $stats['notes_attente']; ?></span>
                                        <?php endif; ?>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="mainTabsContent">
                                <!-- Tab 1: Présences -->
                                <div class="tab-pane fade show active" id="presences">
                                    <?php if(empty($presence_today)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune présence enregistrée aujourd'hui
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Matière</th>
                                                    <th>Heure</th>
                                                    <th>Statut</th>
                                                    <th>Surveillant</th>
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
                                                    <td><?php echo date('H:i', strtotime($presence['date_heure'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = 'presence-' . $presence['statut'];
                                                        echo '<span class="badge-presence ' . $badge_class . '">' . ucfirst($presence['statut']) . '</span>';
                                                        ?>
                                                    </td>
                                                    <td><?php echo $presence['surveillant_id'] ? 'ID:' . $presence['surveillant_id'] : 'Système'; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="presences.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-info">
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
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matricule</th>
                                                    <th>Nom & Prénom</th>
                                                    <th>Date Naissance</th>
                                                    <th>Date Inscription</th>
                                                    <th>Actions</th>
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
                                                    <td>
                                                        <a href="cartes_etudiant.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="Générer carte">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <a href="etudiants.php?action=view&id=<?php echo $etudiant['id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" title="Voir détails">
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
                                        <a href="etudiants.php" class="btn btn-info">
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
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Heure</th>
                                                    <th>Matière</th>
                                                    <th>Classe</th>
                                                    <th>Type</th>
                                                    <th>Salle</th>
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
                                                    <td><?php echo htmlspecialchars($examen['salle'] ?? 'À définir'); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="calendrier_examens.php" class="btn btn-warning">
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
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Année Académique</th>
                                                    <th>Semestre</th>
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
                                                    <td>
                                                        <span class="badge bg-info">Semestre <?php 
                                                        $semestre_id = $bulletin['semestre_id'] ?? 1;
                                                        echo $semestre_id; ?></span>
                                                    </td>
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
                                                        <a href="bulletins.php?action=edit&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-sm btn-warning" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-center mt-3">
                                        <a href="bulletins.php" class="btn btn-success">
                                            <i class="fas fa-file-certificate me-2"></i>Gestion des bulletins
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section Calendrier Académique -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar me-2"></i>
                                Calendrier Académique
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendrier_academique)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> Aucun calendrier académique actif
                                <a href="calendrier_academique.php?action=create" class="btn btn-sm btn-outline-warning ms-3">
                                    <i class="fas fa-plus"></i> Créer un calendrier
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Période</th>
                                            <th>Dates Cours</th>
                                            <th>Examens</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($calendrier_academique as $cal): ?>
                                        <tr>
                                            <td>
                                                <strong>Semestre <?php echo htmlspecialchars($cal['semestre']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($cal['type_rentree']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo formatDateFr($cal['date_debut_cours']); ?> - 
                                                <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                            </td>
                                            <td>
                                                <?php if($cal['date_debut_examens']): ?>
                                                <?php echo formatDateFr($cal['date_debut_examens']); ?> - 
                                                <?php echo formatDateFr($cal['date_fin_examens']); ?>
                                                <?php else: ?>
                                                <em class="text-muted">Non défini</em>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatutBadge($cal['statut']); ?></td>
                                            <td>
                                                <a href="calendrier_academique.php?action=view&id=<?php echo $cal['id']; ?>" 
                                                   class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="calendrier_academique.php?action=edit&id=<?php echo $cal['id']; ?>" 
                                                   class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-edit"></i>
                                                </a>
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
                
                <!-- Sidebar droite -->
                <div class="col-lg-4">
                    <!-- Carte Étudiant (Prévisualisation) -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-id-card me-2"></i>
                                Carte Étudiant
                            </h5>
                        </div>
                        <div class="card-body text-center">
                            <div class="student-card p-4 mb-3">
                                <div class="row">
                                    <div class="col-4">
                                        <div class="student-avatar-placeholder bg-info d-flex align-items-center justify-content-center rounded-circle mx-auto" 
                                             style="width: 80px; height: 80px;">
                                            <i class="fas fa-user text-white fa-2x"></i>
                                        </div>
                                    </div>
                                    <div class="col-8 text-start">
                                        <h5 class="mb-1">Exemple Étudiant</h5>
                                        <p class="text-muted mb-1">ISGI-2025-00001</p>
                                        <p class="mb-1"><small>BTS 1 - Comptabilité</small></p>
                                        <span class="badge bg-success">Actif</span>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-12">
                                        <p class="mb-1"><small><i class="fas fa-calendar-alt me-1"></i> Validité: 2025-2026</small></p>
                                        <p class="mb-0"><small><i class="fas fa-qrcode me-1"></i> QR Code de vérification</small></p>
                                    </div>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <a href="cartes_etudiant.php" class="btn btn-info">
                                    <i class="fas fa-print me-2"></i>Générer des cartes
                                </a>
                                <a href="cartes_etudiant.php?action=batch" class="btn btn-outline-info">
                                    <i class="fas fa-batch me-2"></i>Génération par lot
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Réunions à venir -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-users me-2"></i>
                                Réunions à Venir
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($reunions_a_venir)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune réunion planifiée
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($reunions_a_venir as $reunion): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($reunion['titre']); ?></h6>
                                        <small class="text-muted">
                                            <?php 
                                            $jours_restants = floor((strtotime($reunion['date_reunion']) - time()) / (60*60*24));
                                            if($jours_restants == 0) echo "Aujourd'hui";
                                            elseif($jours_restants == 1) echo "Demain";
                                            else echo "Dans $jours_restants jours";
                                            ?>
                                        </small>
                                    </div>
                                    <p class="mb-1">
                                        <small>
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($reunion['date_reunion'])); ?>
                                        </small>
                                    </p>
                                    <p class="mb-1">
                                        <small>
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($reunion['organisateur_nom']); ?>
                                        </small>
                                    </p>
                                    <div class="mt-2">
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
                                        <?php if($reunion['statut'] == 'planifiee'): ?>
                                        <a href="reunions.php?action=approve&id=<?php echo $reunion['id']; ?>" 
                                           class="btn btn-sm btn-success float-end">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-center mt-3">
                                <a href="reunions.php?action=create" class="btn btn-outline-primary">
                                    <i class="fas fa-plus me-2"></i>Créer une réunion
                                </a>
                                <a href="reunions.php" class="btn btn-outline-info">
                                    <i class="fas fa-list me-2"></i>Toutes les réunions
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Salles de classe -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chalkboard-teacher me-2"></i>
                                Salles de Classe
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($classes)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune classe créée
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($classes as $classe): ?>
                                <div class="col-6 mb-3">
                                    <div class="card border">
                                        <div class="card-body text-center p-3">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($classe['nom'] ?? 'Classe'); ?></h6>
                                            <p class="text-muted mb-1">
                                                <small><?php echo htmlspecialchars($classe['filiere_nom']); ?></small>
                                            </p>
                                            <p class="mb-1">
                                                <span class="badge bg-info"><?php echo htmlspecialchars($classe['niveau_libelle']); ?></span>
                                            </p>
                                            <p class="mb-0">
                                                <small>
                                                    <i class="fas fa-users"></i> 
                                                    Max: <?php echo $classe['effectif_max'] ?? 50; ?>
                                                </small>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-center mt-2">
                                <a href="salles.php" class="btn btn-outline-primary">
                                    <i class="fas fa-door-open me-2"></i>Gestion des salles
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Actions Rapides -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="rapports_academiques.php" class="btn btn-success">
                                    <i class="fas fa-chart-bar me-2"></i>Générer Rapport
                                </a>
                                <a href="export_data.php" class="btn btn-warning">
                                    <i class="fas fa-download me-2"></i>Exporter Données
                                </a>
                                <a href="calendrier_academique.php?action=create" class="btn btn-primary">
                                    <i class="fas fa-calendar-plus me-2"></i>Créer Calendrier
                                </a>
                                <a href="reunions.php?action=create" class="btn btn-info">
                                    <i class="fas fa-users me-2"></i>Planifier Réunion
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section Rapports -->
            <!-- Remplacer la section "Rapports Statistiques" existante par : -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-chart-pie me-2"></i>
            Rapports Statistiques
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Présence sur 7 jours</h6>
                        <div class="chart-container">
                            <canvas id="presenceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Répartition par Filière</h6>
                        <div class="chart-container">
                            <canvas id="filiereChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Performance Académique</h6>
                        <div class="chart-container">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Top 5 des meilleurs étudiants</h6>
                        <?php
                        $query = "SELECT e.matricule, e.prenom, e.nom, b.moyenne_generale
                                  FROM bulletins b
                                  JOIN etudiants e ON b.etudiant_id = e.id
                                  WHERE e.site_id = ? AND b.statut = 'valide'
                                  ORDER BY b.moyenne_generale DESC
                                  LIMIT 5";
                        $stmt = $db->prepare($query);
                        $stmt->execute([$site_id]);
                        $top_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <div class="list-group">
                            <?php foreach($top_students as $student): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($student['prenom'] . ' ' . $student['nom']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['matricule']); ?></small>
                                </div>
                                <span class="badge bg-success">
                                    <?php echo number_format($student['moyenne_generale'], 2); ?>/20
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Matières les plus difficiles</h6>
                        <?php
                        $query = "SELECT m.nom, AVG(n.note) as moyenne_matiere
                                  FROM notes n
                                  JOIN matieres m ON n.matiere_id = m.id
                                  WHERE m.site_id = ?
                                  GROUP BY m.nom
                                  ORDER BY moyenne_matiere ASC
                                  LIMIT 5";
                        $stmt = $db->prepare($query);
                        $stmt->execute([$site_id]);
                        $difficult_matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <div class="list-group">
                            <?php foreach($difficult_matieres as $matiere): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($matiere['nom']); ?></span>
                                <span class="badge bg-warning">
                                    <?php echo number_format($matiere['moyenne_matiere'] ?? 0, 2); ?>/20
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <a href="rapports_academiques.php" class="btn btn-primary">
                <i class="fas fa-file-pdf me-2"></i>Générer Rapport Complet
            </a>
            <a href="statistiques.php" class="btn btn-info">
                <i class="fas fa-chart-line me-2"></i>Voir Statistiques Détaillées
            </a>
            <button onclick="exportCharts()" class="btn btn-success">
                <i class="fas fa-download me-2"></i>Exporter Graphiques
            </button>
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
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Fonction pour générer un rapport
    function genererRapport() {
        alert('Génération du rapport académique en cours...');
        // Redirection vers la page de génération de rapport
        window.location.href = 'rapports_academiques.php?action=generate&type=academic';
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Actualiser les données toutes les 5 minutes
        setTimeout(() => {
            location.reload();
        }, 5 * 60 * 1000);
    });
    // Graphiques Chart.js
document.addEventListener('DOMContentLoaded', function() {
    // Graphique 1: Présence sur 7 jours
    const ctx1 = document.getElementById('presenceChart');
    if (ctx1) {
        const presenceChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($last_7_days, 'label')); ?>,
                datasets: [{
                    label: 'Présences',
                    data: <?php echo json_encode(array_column($last_7_days, 'presents')); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Présences sur 7 jours'
                    }
                }
            }
        });
    }
    
    // Graphique 2: Répartition par filière
    const ctx2 = document.getElementById('filiereChart');
    if (ctx2) {
        const filiereChart = new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($filiere_stats, 'filiere')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($filiere_stats, 'count')); ?>,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Répartition par filière'
                    }
                }
            }
        });
    }
    
    // Graphique 3: Performance académique
    const ctx3 = document.getElementById('performanceChart');
    if (ctx3) {
        const performanceChart = new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($performance_stats, 'range_moyenne')); ?>,
                datasets: [{
                    label: 'Nombre d\'étudiants',
                    data: <?php echo json_encode(array_column($performance_stats, 'count')); ?>,
                    backgroundColor: '#17a2b8'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Distribution des moyennes'
                    }
                }
            }
        });
    }
});
    </script>
</body>
</html>