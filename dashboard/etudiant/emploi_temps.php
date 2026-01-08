<?php
// dashboard/etudiant/emploi_temps.php

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

// Vérifier que l'utilisateur est bien un étudiant
if ($_SESSION['role_id'] != 8) { // 8 = Étudiant
    header('Location: ' . ROOT_PATH . '/dashboard/access_denied.php');
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
    $pageTitle = "Emploi du Temps Étudiant";
    
    // Fonctions utilitaires avec validation
    function formatTime($time) {
        if (empty($time) || $time == '00:00:00') return '';
        return substr($time, 0, 5);
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getJourIndex($jour) {
        $jours = ['Lundi' => 1, 'Mardi' => 2, 'Mercredi' => 3, 'Jeudi' => 4, 'Vendredi' => 5, 'Samedi' => 6];
        return $jours[$jour] ?? 0;
    }
    
    // Fonction sécurisée pour afficher du texte
    function safeHtml($text) {
        if ($text === null || $text === '') {
            return '';
        }
        return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Étudiant';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? intval($_SESSION['role_id']) : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? intval($_SESSION['site_id']) : null;
        }
        
        public static function getUserId() {
            return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        }
        
        public static function getEtudiantId() {
            return isset($_SESSION['etudiant_id']) ? intval($_SESSION['etudiant_id']) : null;
        }
    }
    
    // Récupérer l'ID de l'étudiant
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    
    // Initialiser toutes les variables
    $info_etudiant = array();
    $emploi_du_temps = array();
    $emploi_semaine = array();
    $sessions_cours = array();
    $cours_prochains = array();
    $salles_disponibles = array();
    $error = null;
    
    // Fonction pour exécuter les requêtes en toute sécurité
    function executeQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            return array();
        }
    }
    
    function executeSingleQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: array();
        } catch (Exception $e) {
            error_log("Single query error: " . $e->getMessage());
            return array();
        }
    }
    
    // Récupérer les informations de l'étudiant
    $info_etudiant = executeSingleQuery($db, 
        "SELECT e.*, s.nom as site_nom, c.id as classe_id, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['classe_id'])) {
        $classe_id = intval($info_etudiant['classe_id']);
        $site_id = intval($info_etudiant['site_id']);
        
        // Récupérer l'année académique active
        $annee_active = executeSingleQuery($db,
            "SELECT id, libelle 
             FROM annees_academiques 
             WHERE site_id = ? AND statut = 'active' 
             ORDER BY date_debut DESC LIMIT 1",
            [$site_id]);
        
        $annee_academique_id = $annee_active['id'] ?? 0;
        
        // Récupérer l'emploi du temps complet
        if ($annee_academique_id) {
            $emploi_du_temps = executeQuery($db,
                "SELECT edt.*, m.nom as matiere_nom, m.coefficient, m.credit,
                        CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                        s.nom as salle_nom
                 FROM emploi_du_temps edt
                 JOIN matieres m ON edt.matiere_id = m.id
                 JOIN enseignants e ON edt.enseignant_id = e.id
                 JOIN utilisateurs u ON e.utilisateur_id = u.id
                 LEFT JOIN salles s ON edt.salle = s.id OR edt.salle = s.nom
                 WHERE edt.classe_id = ? 
                 AND edt.annee_academique_id = ?
                 AND edt.site_id = ?
                 ORDER BY FIELD(edt.jour_semaine, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'), 
                          edt.heure_debut",
                [$classe_id, $annee_academique_id, $site_id]);
            
            // Organiser par jour de la semaine
            $jours = ['Lundi' => [], 'Mardi' => [], 'Mercredi' => [], 'Jeudi' => [], 'Vendredi' => [], 'Samedi' => []];
            foreach ($emploi_du_temps as $cours) {
                $jour = $cours['jour_semaine'] ?? 'Lundi';
                $jours[$jour][] = $cours;
            }
            $emploi_semaine = $jours;
            
            // Récupérer les sessions de cours de la semaine (pour le calendrier)
            $date_debut_semaine = date('Y-m-d', strtotime('monday this week'));
            $date_fin_semaine = date('Y-m-d', strtotime('sunday this week'));
            
            $sessions_cours = executeQuery($db,
                "SELECT eds.*, edt.matiere_id, edt.enseignant_id, edt.salle,
                        m.nom as matiere_nom, CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
                 FROM emploi_du_temps_sessions eds
                 JOIN emploi_du_temps edt ON eds.emploi_du_temps_id = edt.id
                 JOIN matieres m ON edt.matiere_id = m.id
                 JOIN enseignants e ON edt.enseignant_id = e.id
                 JOIN utilisateurs u ON e.utilisateur_id = u.id
                 WHERE eds.date_session BETWEEN ? AND ?
                 AND edt.classe_id = ?
                 ORDER BY eds.date_session, edt.heure_debut",
                [$date_debut_semaine, $date_fin_semaine, $classe_id]);
            
            // Récupérer les cours prochains (7 prochains jours)
            $date_aujourdhui = date('Y-m-d');
            $date_7jours = date('Y-m-d', strtotime('+7 days'));
            
            $cours_prochains = executeQuery($db,
                "SELECT eds.*, edt.matiere_id, edt.enseignant_id, edt.salle, edt.heure_debut, edt.heure_fin,
                        m.nom as matiere_nom, CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                        eds.date_session as date_cours,
                        DATEDIFF(eds.date_session, CURDATE()) as jours_avant
                 FROM emploi_du_temps_sessions eds
                 JOIN emploi_du_temps edt ON eds.emploi_du_temps_id = edt.id
                 JOIN matieres m ON edt.matiere_id = m.id
                 JOIN enseignants e ON edt.enseignant_id = e.id
                 JOIN utilisateurs u ON e.utilisateur_id = u.id
                 WHERE eds.date_session >= ?
                 AND eds.date_session <= ?
                 AND edt.classe_id = ?
                 AND eds.statut = 'prevu'
                 ORDER BY eds.date_session, edt.heure_debut
                 LIMIT 10",
                [$date_aujourdhui, $date_7jours, $classe_id]);
            
            // Récupérer les salles disponibles
            $salles_disponibles = executeQuery($db,
                "SELECT s.*, 
                        CASE 
                            WHEN s.type_salle = 'classe' THEN 'Salle de classe'
                            WHEN s.type_salle = 'amphi' THEN 'Amphithéâtre'
                            WHEN s.type_salle = 'labo' THEN 'Laboratoire'
                            WHEN s.type_salle = 'bureau' THEN 'Bureau'
                            WHEN s.type_salle = 'salle_examen' THEN 'Salle d\'examen'
                            ELSE 'Autre'
                        END as type_salle_libelle
                 FROM salles s
                 WHERE s.site_id = ? 
                 AND s.statut = 'disponible'
                 ORDER BY s.nom
                 LIMIT 10",
                [$site_id]);
        }
    }
    
    // Vérifier si un filtre de semaine a été appliqué
    $semaine_selectionnee = isset($_GET['semaine']) ? intval($_GET['semaine']) : 0;
    if ($semaine_selectionnee != 0) {
        $date_debut_semaine = date('Y-m-d', strtotime('monday this week + ' . $semaine_selectionnee . ' weeks'));
        $date_fin_semaine = date('Y-m-d', strtotime('sunday this week + ' . $semaine_selectionnee . ' weeks'));
    } else {
        $date_debut_semaine = date('Y-m-d', strtotime('monday this week'));
        $date_fin_semaine = date('Y-m-d', strtotime('sunday this week'));
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . safeHtml($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safeHtml($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    
    <!-- Print CSS -->
    <style>
    @media print {
        .no-print, .sidebar, .main-content .btn, .btn-group {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            padding: 10px !important;
        }
        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        .table {
            font-size: 12px !important;
        }
    }
    </style>
    
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
    
    /* Tableau emploi du temps */
    .emploi-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .emploi-table th {
        background-color: var(--primary-color);
        color: white;
        padding: 12px;
        text-align: center;
        font-weight: 600;
        border: 1px solid var(--border-color);
    }
    
    .emploi-table td {
        padding: 10px;
        border: 1px solid var(--border-color);
        vertical-align: top;
        min-height: 100px;
    }
    
    .creneau-heure {
        background-color: rgba(0, 0, 0, 0.05);
        text-align: center;
        font-weight: 600;
        width: 100px;
    }
    
    .cours-item {
        background-color: rgba(52, 152, 219, 0.1);
        border-left: 4px solid var(--secondary-color);
        padding: 10px;
        margin-bottom: 5px;
        border-radius: 5px;
        transition: all 0.3s;
    }
    
    .cours-item:hover {
        background-color: rgba(52, 152, 219, 0.2);
        transform: translateX(3px);
    }
    
    .cours-matiere {
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .cours-details {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    .cours-details i {
        width: 16px;
        margin-right: 5px;
    }
    
    .creneau-libre {
        background-color: rgba(39, 174, 96, 0.1);
        text-align: center;
        padding: 20px;
        color: var(--success-color);
        font-style: italic;
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Boutons navigation semaine */
    .week-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .week-display {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--text-color);
        min-width: 200px;
        text-align: center;
    }
    
    /* Calendrier */
    .fc {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc-theme-standard .fc-scrollgrid {
        border-color: var(--border-color);
    }
    
    .fc-theme-standard td, .fc-theme-standard th {
        border-color: var(--border-color);
    }
    
    .fc .fc-toolbar-title {
        color: var(--text-color);
    }
    
    .fc .fc-button-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .fc .fc-button-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    /* Filtres */
    .filter-card {
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        margin-bottom: 15px;
    }
    
    .filter-label {
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--text-color);
        display: block;
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
        
        .emploi-table {
            font-size: 0.85rem;
        }
        
        .creneau-heure {
            width: 60px;
            padding: 5px;
        }
        
        .cours-item {
            padding: 5px;
        }
    }
    
    /* En-têtes */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .content-header h2 {
        color: var(--text-color);
    }
    
    /* Formulaires */
    .form-control, .form-select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .form-control:focus, .form-select:focus {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
    }
    
    /* List group */
    .list-group-item {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .list-group-item:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .list-group-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
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
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
    }
    
    /* Widgets */
    .widget {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
    }
    
    .widget-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: var(--text-color);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .widget-title i {
        color: var(--primary-color);
    }
    
    /* Cartes cours */
    .cours-card {
        border-left: 4px solid var(--secondary-color);
        margin-bottom: 10px;
    }
    
    .cours-card.today {
        border-left-color: var(--success-color);
        background-color: rgba(39, 174, 96, 0.05);
    }
    
    .cours-card.passed {
        border-left-color: var(--text-muted);
        opacity: 0.7;
    }
    
    /* Heures */
    .time-slot {
        display: inline-block;
        background: var(--primary-color);
        color: white;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 0.85rem;
        margin-right: 5px;
    }
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
                <h5 class="mt-2 mb-1">ISGI</h5>
                <div class="user-role">Étudiant</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo safeHtml(SessionManager::getUserName()); ?></p>
                <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                <small>Matricule: <?php echo safeHtml($info_etudiant['matricule']); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link active">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Ressources</div>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                    </a>
                    <a href="salles.php" class="nav-link">
                        <i class="fas fa-door-open"></i>
                        <span>Salles</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Mes Professeurs</span>
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
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['classe_nom']) && !empty($info_etudiant['classe_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['classe_nom']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="exportEmploi()">
                            <i class="fas fa-download"></i> Exporter
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Retour
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Navigation par semaine -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="week-nav">
                        <a href="?semaine=<?php echo $semaine_selectionnee - 1; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-chevron-left"></i> Semaine précédente
                        </a>
                        
                        <div class="week-display">
                            <i class="fas fa-calendar-week"></i>
                            Semaine du <?php echo formatDateFr($date_debut_semaine); ?> au <?php echo formatDateFr($date_fin_semaine); ?>
                            <?php if($semaine_selectionnee == 0): ?>
                            <span class="badge bg-success ms-2">Cette semaine</span>
                            <?php elseif($semaine_selectionnee == 1): ?>
                            <span class="badge bg-info ms-2">Semaine prochaine</span>
                            <?php elseif($semaine_selectionnee == -1): ?>
                            <span class="badge bg-secondary ms-2">Semaine dernière</span>
                            <?php else: ?>
                            <span class="badge bg-warning ms-2">
                                <?php echo $semaine_selectionnee > 0 ? 'Dans ' . $semaine_selectionnee . ' semaines' : 'Il y a ' . abs($semaine_selectionnee) . ' semaines'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <a href="?semaine=<?php echo $semaine_selectionnee + 1; ?>" class="btn btn-outline-primary">
                            Semaine suivante <i class="fas fa-chevron-right"></i>
                        </a>
                        
                        <a href="?semaine=0" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Aujourd'hui
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Section 1: Vue hebdomadaire -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-table me-2"></i>
                                Vue Hebdomadaire
                            </h5>
                            <div>
                                <span class="badge bg-secondary me-2">
                                    <i class="fas fa-book"></i> <?php echo count($emploi_du_temps); ?> cours
                                </span>
                                <button class="btn btn-sm btn-outline-primary" onclick="toggleView('week')">
                                    <i class="fas fa-exchange-alt"></i> Changer vue
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if(empty($emploi_du_temps)): ?>
                            <div class="alert alert-info text-center py-5">
                                <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                <h4>Aucun cours planifié</h4>
                                <p class="mb-0">Votre emploi du temps n'a pas encore été configuré.</p>
                                <p>Contactez l'administration pour plus d'informations.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="emploi-table">
                                    <thead>
                                        <tr>
                                            <th width="100">Heure</th>
                                            <th>Lundi<br><small><?php echo formatDateFr($date_debut_semaine); ?></small></th>
                                            <th>Mardi<br><small><?php echo formatDateFr(date('Y-m-d', strtotime($date_debut_semaine . ' +1 day'))); ?></small></th>
                                            <th>Mercredi<br><small><?php echo formatDateFr(date('Y-m-d', strtotime($date_debut_semaine . ' +2 days'))); ?></small></th>
                                            <th>Jeudi<br><small><?php echo formatDateFr(date('Y-m-d', strtotime($date_debut_semaine . ' +3 days'))); ?></small></th>
                                            <th>Vendredi<br><small><?php echo formatDateFr(date('Y-m-d', strtotime($date_debut_semaine . ' +4 days'))); ?></small></th>
                                            <th>Samedi<br><small><?php echo formatDateFr(date('Y-m-d', strtotime($date_debut_semaine . ' +5 days'))); ?></small></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Définir les créneaux horaires
                                        $creneaux = [
                                            '08:00' => '08:00 - 09:30',
                                            '09:30' => '09:30 - 11:00',
                                            '11:00' => '11:00 - 12:30',
                                            '14:00' => '14:00 - 15:30',
                                            '15:30' => '15:30 - 17:00',
                                            '17:00' => '17:00 - 18:30'
                                        ];
                                        
                                        foreach ($creneaux as $heure_debut => $plage_horaire): 
                                            list($h_debut, $m_debut) = explode(':', $heure_debut);
                                            $h_fin = $h_debut + 1;
                                            $m_fin = $m_debut + 30;
                                            if ($m_fin >= 60) {
                                                $h_fin += 1;
                                                $m_fin -= 60;
                                            }
                                            $heure_fin = sprintf('%02d:%02d', $h_fin, $m_fin);
                                        ?>
                                        <tr>
                                            <td class="creneau-heure">
                                                <?php echo $plage_horaire; ?>
                                            </td>
                                            
                                            <?php foreach (['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'] as $jour): ?>
                                            <td>
                                                <?php 
                                                $cours_du_creneau = array_filter($emploi_semaine[$jour] ?? [], function($cours) use ($heure_debut, $heure_fin) {
                                                    $cours_debut = $cours['heure_debut'] ?? '00:00:00';
                                                    $cours_fin = $cours['heure_fin'] ?? '00:00:00';
                                                    return ($cours_debut >= $heure_debut . ':00' && $cours_debut < $heure_fin . ':00') ||
                                                           ($cours_fin > $heure_debut . ':00' && $cours_fin <= $heure_fin . ':00');
                                                });
                                                
                                                if (!empty($cours_du_creneau)): 
                                                    foreach ($cours_du_creneau as $cours): 
                                                        // Vérifier si c'est aujourd'hui
                                                        $is_today = false;
                                                        if ($jour == date('l', strtotime($date_debut_semaine)) && 
                                                            $semaine_selectionnee == 0) {
                                                            $is_today = true;
                                                        }
                                                ?>
                                                <div class="cours-item <?php echo $is_today ? 'today' : ''; ?>">
                                                    <div class="cours-matiere">
                                                        <i class="fas fa-book text-primary"></i>
                                                        <?php echo safeHtml($cours['matiere_nom'] ?? ''); ?>
                                                    </div>
                                                    <div class="cours-details">
                                                        <div>
                                                            <i class="fas fa-user-tie text-info"></i>
                                                            <?php echo safeHtml($cours['enseignant_nom'] ?? ''); ?>
                                                        </div>
                                                        <div>
                                                            <i class="fas fa-clock text-warning"></i>
                                                            <?php echo formatTime($cours['heure_debut'] ?? ''); ?> - <?php echo formatTime($cours['heure_fin'] ?? ''); ?>
                                                        </div>
                                                        <div>
                                                            <i class="fas fa-door-open text-success"></i>
                                                            <?php echo safeHtml($cours['salle'] ?? 'Salle non définie'); ?>
                                                        </div>
                                                        <div>
                                                            <i class="fas fa-star text-danger"></i>
                                                            Coeff: <?php echo safeHtml($cours['coefficient'] ?? '1'); ?> | 
                                                            Crédit: <?php echo safeHtml($cours['credit'] ?? '3'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php else: ?>
                                                <div class="creneau-libre">
                                                    <i class="fas fa-coffee"></i>
                                                    <br>
                                                    <small>Libre</small>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
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
            
            <!-- Section 2: Vue calendrier et prochains cours -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar me-2"></i>
                                Calendrier des Cours
                            </h5>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var calendarEl = document.getElementById('calendar');
                                var calendar = new FullCalendar.Calendar(calendarEl, {
                                    initialView: 'dayGridMonth',
                                    locale: 'fr',
                                    headerToolbar: {
                                        left: 'prev,next today',
                                        center: 'title',
                                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                                    },
                                    events: [
                                        <?php 
                                        $couleurs = ['#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c'];
                                        $couleur_index = 0;
                                        
                                        if (!empty($sessions_cours)):
                                            foreach ($sessions_cours as $session): 
                                                $couleur = $couleurs[$couleur_index % count($couleurs)];
                                                $couleur_index++;
                                                
                                                $statut_badge = '';
                                                switch ($session['statut'] ?? 'prevu') {
                                                    case 'realise':
                                                        $couleur = '#2ecc71'; // Vert
                                                        break;
                                                    case 'annule':
                                                        $couleur = '#e74c3c'; // Rouge
                                                        break;
                                                    case 'reporte':
                                                        $couleur = '#f39c12'; // Orange
                                                        break;
                                                }
                                        ?>
                                        {
                                            title: '<?php echo safeHtml($session['matiere_nom'] ?? ''); ?>',
                                            start: '<?php echo $session['date_session'] ?? ''; ?>T<?php echo $session['heure_debut'] ?? '08:00:00'; ?>',
                                            end: '<?php echo $session['date_session'] ?? ''; ?>T<?php echo $session['heure_fin'] ?? '10:00:00'; ?>',
                                            description: '<?php echo safeHtml($session['enseignant_nom'] ?? ''); ?>',
                                            salle: '<?php echo safeHtml($session['salle'] ?? ''); ?>',
                                            color: '<?php echo $couleur; ?>',
                                            extendedProps: {
                                                enseignant: '<?php echo safeHtml($session['enseignant_nom'] ?? ''); ?>',
                                                salle: '<?php echo safeHtml($session['salle'] ?? ''); ?>',
                                                statut: '<?php echo $session['statut'] ?? 'prevu'; ?>'
                                            }
                                        },
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($cours_prochains)): ?>
                                        <?php foreach ($cours_prochains as $cours): ?>
                                        {
                                            title: '<?php echo safeHtml($cours['matiere_nom'] ?? ''); ?>',
                                            start: '<?php echo $cours['date_cours'] ?? ''; ?>T<?php echo $cours['heure_debut'] ?? '08:00:00'; ?>',
                                            end: '<?php echo $cours['date_cours'] ?? ''; ?>T<?php echo $cours['heure_fin'] ?? '10:00:00'; ?>',
                                            description: '<?php echo safeHtml($cours['enseignant_nom'] ?? ''); ?>',
                                            color: '#3498db',
                                            extendedProps: {
                                                enseignant: '<?php echo safeHtml($cours['enseignant_nom'] ?? ''); ?>',
                                                salle: '<?php echo safeHtml($cours['salle'] ?? ''); ?>'
                                            }
                                        },
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    ],
                                    eventClick: function(info) {
                                        const event = info.event;
                                        const statut = event.extendedProps.statut || 'prévu';
                                        const statutBadge = statut === 'realise' ? '<span class="badge bg-success">Réalisé</span>' : 
                                                            statut === 'annule' ? '<span class="badge bg-danger">Annulé</span>' : 
                                                            statut === 'reporte' ? '<span class="badge bg-warning">Reporté</span>' : 
                                                            '<span class="badge bg-info">Prévu</span>';
                                        
                                        Swal.fire({
                                            title: event.title,
                                            html: `
                                                <div class="text-start">
                                                    <p><strong><i class="fas fa-user-tie"></i> Enseignant:</strong> ${event.extendedProps.enseignant}</p>
                                                    <p><strong><i class="fas fa-door-open"></i> Salle:</strong> ${event.extendedProps.salle || 'Non définie'}</p>
                                                    <p><strong><i class="fas fa-clock"></i> Horaire:</strong> ${event.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} - ${event.end.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                                    <p><strong><i class="fas fa-calendar"></i> Date:</strong> ${event.start.toLocaleDateString('fr-FR')}</p>
                                                    <p><strong>Statut:</strong> ${statutBadge}</p>
                                                </div>
                                            `,
                                            icon: 'info',
                                            confirmButtonText: 'OK',
                                            confirmButtonColor: '#3498db'
                                        });
                                    }
                                });
                                calendar.render();
                            });
                            </script>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-forward me-2"></i>
                                Prochains Cours
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($cours_prochains)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun cours programmé pour les 7 prochains jours.
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($cours_prochains as $cours): 
                                    $is_today = ($cours['date_cours'] ?? '') == date('Y-m-d');
                                    $is_tomorrow = ($cours['date_cours'] ?? '') == date('Y-m-d', strtotime('+1 day'));
                                ?>
                                <div class="list-group-item <?php echo $is_today ? 'today' : ($is_tomorrow ? 'border-warning' : ''); ?>">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo safeHtml($cours['matiere_nom'] ?? ''); ?></h6>
                                        <span class="badge bg-<?php echo $is_today ? 'success' : ($is_tomorrow ? 'warning' : 'info'); ?>">
                                            <?php if($is_today): ?>
                                            Aujourd'hui
                                            <?php elseif($is_tomorrow): ?>
                                            Demain
                                            <?php else: ?>
                                            J-<?php echo intval($cours['jours_avant'] ?? 0); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <p class="mb-1">
                                        <small><i class="fas fa-user-tie"></i> <?php echo safeHtml($cours['enseignant_nom'] ?? ''); ?></small><br>
                                        <small><i class="fas fa-clock"></i> <?php echo formatTime($cours['heure_debut'] ?? ''); ?> - <?php echo formatTime($cours['heure_fin'] ?? ''); ?></small><br>
                                        <small><i class="fas fa-door-open"></i> <?php echo safeHtml($cours['salle'] ?? 'Salle non définie'); ?></small><br>
                                        <small><i class="fas fa-calendar"></i> <?php echo formatDateFr($cours['date_cours'] ?? ''); ?></small>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <h6><i class="fas fa-door-open me-2"></i>Salles Disponibles</h6>
                                <?php if(empty($salles_disponibles)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Aucune information sur les salles disponibles.
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach($salles_disponibles as $salle): ?>
                                    <div class="col-6 mb-2">
                                        <div class="card h-100">
                                            <div class="card-body p-2">
                                                <h6 class="card-title mb-1" style="font-size: 0.9rem;"><?php echo safeHtml($salle['nom'] ?? ''); ?></h6>
                                                <p class="card-text mb-1 small">
                                                    <i class="fas fa-users"></i> <?php echo intval($salle['capacite'] ?? 0); ?><br>
                                                    <i class="fas fa-building"></i> <?php echo safeHtml($salle['type_salle_libelle'] ?? ''); ?>
                                                </p>
                                                <span class="badge bg-success">Disponible</span>
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
            </div>
            
            <!-- Section 3: Statistiques et informations -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Statistiques Hebdomadaires
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="stat-value text-primary">
                                            <?php 
                                            $total_cours = count($emploi_du_temps);
                                            echo $total_cours; 
                                            ?>
                                        </div>
                                        <div class="stat-label">Total Cours/Semaine</div>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <?php 
                                        $heures_total = 0;
                                        foreach ($emploi_du_temps as $cours) {
                                            $debut = strtotime($cours['heure_debut'] ?? '00:00:00');
                                            $fin = strtotime($cours['heure_fin'] ?? '00:00:00');
                                            $heures_total += ($fin - $debut) / 3600;
                                        }
                                        ?>
                                        <div class="stat-value text-success">
                                            <?php echo number_format($heures_total, 1); ?>h
                                        </div>
                                        <div class="stat-label">Heures Total/Semaine</div>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="stat-value text-info">
                                            <?php 
                                            $matieres_uniques = array_unique(array_column($emploi_du_temps, 'matiere_id'));
                                            echo count($matieres_uniques);
                                            ?>
                                        </div>
                                        <div class="stat-label">Matières Différentes</div>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="stat-value text-warning">
                                            <?php 
                                            $enseignants_uniques = array_unique(array_column($emploi_du_temps, 'enseignant_id'));
                                            echo count($enseignants_uniques);
                                            ?>
                                        </div>
                                        <div class="stat-label">Enseignants</div>
                                    </div>
                                </div>
                            </div>
                            
                            <canvas id="emploiChart"></canvas>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const ctx = document.getElementById('emploiChart').getContext('2d');
                                new Chart(ctx, {
                                    type: 'bar',
                                    data: {
                                        labels: ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'],
                                        datasets: [{
                                            label: 'Nombre de cours',
                                            data: [
                                                <?php echo count($emploi_semaine['Lundi'] ?? []); ?>,
                                                <?php echo count($emploi_semaine['Mardi'] ?? []); ?>,
                                                <?php echo count($emploi_semaine['Mercredi'] ?? []); ?>,
                                                <?php echo count($emploi_semaine['Jeudi'] ?? []); ?>,
                                                <?php echo count($emploi_semaine['Vendredi'] ?? []); ?>,
                                                <?php echo count($emploi_semaine['Samedi'] ?? []); ?>
                                            ],
                                            backgroundColor: [
                                                '#3498db',
                                                '#2ecc71',
                                                '#e74c3c',
                                                '#f39c12',
                                                '#9b59b6',
                                                '#1abc9c'
                                            ]
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        plugins: {
                                            legend: {
                                                display: false
                                            },
                                            title: {
                                                display: true,
                                                text: 'Répartition des cours par jour'
                                            }
                                        }
                                    }
                                });
                            });
                            </script>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Importantes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-clock"></i> Horaires Généraux</h6>
                                <ul class="mb-0">
                                    <li><strong>Cours du matin:</strong> 8h00 - 12h30</li>
                                    <li><strong>Cours de l'après-midi:</strong> 14h00 - 18h30</li>
                                    <li><strong>Pause déjeuner:</strong> 12h30 - 14h00</li>
                                    <li><strong>Journée complète:</strong> 8h00 - 18h30</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Règles importantes</h6>
                                <ul class="mb-0">
                                    <li>Présence obligatoire à tous les cours</li>
                                    <li>Retard maximum toléré: 15 minutes</li>
                                    <li>Justification des absences obligatoire sous 48h</li>
                                    <li>Tenue correcte exigée dans l'enceinte de l'école</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-phone-alt"></i> Contacts urgents</h6>
                                <ul class="mb-0">
                                    <li><strong>Service académique:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Surveillant général:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Urgences:</strong> +242 XX XX XX XX</li>
                                </ul>
                            </div>
                            
                            <div class="mt-3">
                                <button class="btn btn-outline-primary w-100" onclick="syncCalendar()">
                                    <i class="fas fa-sync-alt"></i> Synchroniser avec mon calendrier
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 pour les dialogues -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
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
    
    // Fonction pour exporter l'emploi du temps
    function exportEmploi() {
        Swal.fire({
            title: 'Exporter l\'emploi du temps',
            html: `
                <div class="text-start">
                    <p>Sélectionnez le format d'export :</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatPDF" value="pdf" checked>
                        <label class="form-check-label" for="formatPDF">
                            <i class="fas fa-file-pdf text-danger"></i> PDF (pour impression)
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatExcel" value="excel">
                        <label class="form-check-label" for="formatExcel">
                            <i class="fas fa-file-excel text-success"></i> Excel
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatICal" value="ical">
                        <label class="form-check-label" for="formatICal">
                            <i class="fas fa-calendar-alt text-primary"></i> iCal (pour calendrier)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="exportFormat" id="formatCSV" value="csv">
                        <label class="form-check-label" for="formatCSV">
                            <i class="fas fa-file-csv text-info"></i> CSV
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Exporter',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                const format = document.querySelector('input[name="exportFormat"]:checked').value;
                
                Swal.fire({
                    title: 'Export en cours...',
                    text: 'Préparation du fichier...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Simuler l'export (dans un cas réel, appeler une API)
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Export réussi!',
                        html: `Votre emploi du temps a été exporté au format ${format.toUpperCase()}.<br><br>
                               <small>Le fichier a été téléchargé automatiquement.</small>`,
                        confirmButtonText: 'OK'
                    });
                    
                    // Dans un cas réel, rediriger vers l'URL d'export
                    // window.location.href = `export.php?format=${format}&semaine=<?php echo $semaine_selectionnee; ?>`;
                }, 1500);
            }
        });
    }
    
    // Fonction pour synchroniser avec le calendrier
    function syncCalendar() {
        Swal.fire({
            title: 'Synchroniser avec mon calendrier',
            html: `
                <div class="text-start">
                    <p>Sélectionnez votre application de calendrier :</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="calendarApp" id="appGoogle" value="google" checked>
                        <label class="form-check-label" for="appGoogle">
                            <i class="fab fa-google text-danger"></i> Google Calendar
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="calendarApp" id="appOutlook" value="outlook">
                        <label class="form-check-label" for="appOutlook">
                            <i class="fab fa-microsoft text-primary"></i> Microsoft Outlook
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="calendarApp" id="appApple" value="apple">
                        <label class="form-check-label" for="appApple">
                            <i class="fab fa-apple text-dark"></i> Apple Calendar
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="calendarApp" id="appOther" value="ical">
                        <label class="form-check-label" for="appOther">
                            <i class="fas fa-calendar-alt text-info"></i> Autre (fichier iCal)
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Synchroniser',
            confirmButtonColor: '#3498db',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                const app = document.querySelector('input[name="calendarApp"]:checked').value;
                
                Swal.fire({
                    title: 'Synchronisation en cours...',
                    text: 'Ajout des événements à votre calendrier...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Simuler la synchronisation
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Synchronisation réussie!',
                        html: `Votre emploi du temps a été synchronisé avec ${app === 'google' ? 'Google Calendar' : app === 'outlook' ? 'Microsoft Outlook' : app === 'apple' ? 'Apple Calendar' : 'votre calendrier'}.<br><br>
                               <small>Les cours seront mis à jour automatiquement en cas de modification.</small>`,
                        confirmButtonText: 'OK'
                    });
                }, 2000);
            }
        });
    }
    
    // Fonction pour basculer entre les vues
    function toggleView(viewType) {
        if (viewType === 'week') {
            // Basculer entre vue tableau et vue liste
            const table = document.querySelector('.emploi-table');
            if (table) {
                table.classList.toggle('table-view');
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
        
        // Ajouter des événements pour les cartes cours
        document.querySelectorAll('.cours-item').forEach(item => {
            item.addEventListener('click', function() {
                const matiere = this.querySelector('.cours-matiere').textContent.trim();
                const details = this.querySelectorAll('.cours-details div');
                
                let html = `<strong>${matiere}</strong><br><br>`;
                details.forEach(detail => {
                    html += `<div>${detail.innerHTML}</div>`;
                });
                
                Swal.fire({
                    title: 'Détails du cours',
                    html: html,
                    icon: 'info',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#3498db'
                });
            });
        });
    });
    
    // Fonction pour imprimer l'emploi du temps
    window.addEventListener('beforeprint', function() {
        // Ajouter des informations supplémentaires avant l'impression
        const printHeader = document.createElement('div');
        printHeader.innerHTML = `
            <div style="text-align: center; margin-bottom: 20px; padding: 10px; border-bottom: 2px solid #333;">
                <h3>Emploi du Temps - <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></h3>
                <p>Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?> | 
                   Classe: <?php echo safeHtml($info_etudiant['classe_nom'] ?? ''); ?> | 
                   Semaine du <?php echo formatDateFr($date_debut_semaine); ?> au <?php echo formatDateFr($date_fin_semaine); ?></p>
                <p>Imprimé le <?php echo date('d/m/Y à H:i'); ?></p>
            </div>
        `;
        document.querySelector('.main-content').prepend(printHeader);
    });
    
    window.addEventListener('afterprint', function() {
        // Nettoyer après l'impression
        const printHeader = document.querySelector('.main-content > div:first-child');
        if (printHeader && printHeader.innerHTML.includes('Imprimé le')) {
            printHeader.remove();
        }
    });
    </script>
</body>
</html>