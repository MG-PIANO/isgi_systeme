<?php
// dashboard/etudiant/notes.php

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
    $pageTitle = "Notes & Moyennes";
    
    // Fonctions utilitaires
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function safeHtml($text) {
        if ($text === null || $text === '') {
            return '';
        }
        return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
    }
    
    function getNoteClass($note) {
        $note = floatval($note);
        if ($note >= 16) return 'note-excellent';
        if ($note >= 14) return 'note-tres-bien';
        if ($note >= 12) return 'note-bien';
        if ($note >= 10) return 'note-passable';
        if ($note >= 8) return 'note-insuffisant';
        return 'note-faible';
    }
    
    function getNoteIcon($note) {
        $note = floatval($note);
        if ($note >= 16) return 'fas fa-trophy';
        if ($note >= 14) return 'fas fa-star';
        if ($note >= 12) return 'fas fa-check-circle';
        if ($note >= 10) return 'fas fa-check';
        if ($note >= 8) return 'fas fa-exclamation-triangle';
        return 'fas fa-times-circle';
    }
    
    function getMention($moyenne) {
        $moyenne = floatval($moyenne);
        if ($moyenne >= 16) return 'Très Bien';
        if ($moyenne >= 14) return 'Bien';
        if ($moyenne >= 12) return 'Assez Bien';
        if ($moyenne >= 10) return 'Passable';
        return 'Ajourné';
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Étudiant';
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
    
    // Initialiser les variables
    $info_etudiant = array();
    $notes_semestre1 = array();
    $notes_semestre2 = array();
    $moyennes_semestres = array();
    $moyenne_generale = 0;
    $stats_notes = array();
    $evolution_notes = array();
    $comparaison_classe = array();
    $rattrapages = array();
    $bulletins = array();
    $error = null;
    
    // Paramètres de filtrage
    $annee_academique_id = $_GET['annee'] ?? 0;
    $semestre_id = $_GET['semestre'] ?? 0;
    
    // Fonctions pour exécuter les requêtes
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
    
    // Récupérer les informations de l'étudiant
    $info_etudiant = executeSingleQuery($db, 
        "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $classe_id = $info_etudiant['classe_id'] ?? 0;
        
        // Récupérer les années académiques disponibles
        $annees_academiques = executeQuery($db,
            "SELECT aa.* 
             FROM annees_academiques aa
             JOIN classes c ON aa.site_id = c.site_id
             WHERE c.id = ? 
             ORDER BY aa.date_debut DESC",
            [$classe_id]);
        
        // Si aucune année spécifiée, prendre l'année active
        if (!$annee_academique_id && !empty($annees_academiques)) {
            $annee_active = array_filter($annees_academiques, function($a) {
                return ($a['statut'] ?? '') == 'active';
            });
            if (!empty($annee_active)) {
                $annee_academique_id = reset($annee_active)['id'];
            } else {
                $annee_academique_id = $annees_academiques[0]['id'] ?? 0;
            }
        }
        
        // Récupérer les semestres
        $semestres = executeQuery($db,
            "SELECT * FROM semestres 
             WHERE annee_academique_id = ? 
             ORDER BY numero",
            [$annee_academique_id]);
        
        // Récupérer les notes par semestre
        if ($semestre_id) {
            // Notes pour un semestre spécifique
            $notes = executeQuery($db,
                "SELECT n.*, m.nom as matiere_nom, m.code as matiere_code,
                        m.coefficient, m.credit, te.nom as type_examen,
                        CONCAT(u.nom, ' ', u.prenom) as evaluateur_nom,
                        s.numero as semestre_numero
                 FROM notes n
                 JOIN matieres m ON n.matiere_id = m.id
                 JOIN types_examens te ON n.type_examen_id = te.id
                 LEFT JOIN utilisateurs u ON n.evaluateur_id = u.id
                 JOIN semestres s ON n.semestre_id = s.id
                 WHERE n.etudiant_id = ? 
                 AND n.semestre_id = ?
                 AND n.statut = 'valide'
                 ORDER BY m.nom, te.ordre",
                [$etudiant_id, $semestre_id]);
            
            $notes_semestre1 = $notes;
        } else {
            // Notes pour tous les semestres de l'année
            if (!empty($semestres)) {
                foreach ($semestres as $semestre) {
                    $notes = executeQuery($db,
                        "SELECT n.*, m.nom as matiere_nom, m.code as matiere_code,
                                m.coefficient, m.credit, te.nom as type_examen,
                                CONCAT(u.nom, ' ', u.prenom) as evaluateur_nom
                         FROM notes n
                         JOIN matieres m ON n.matiere_id = m.id
                         JOIN types_examens te ON n.type_examen_id = te.id
                         LEFT JOIN utilisateurs u ON n.evaluateur_id = u.id
                         WHERE n.etudiant_id = ? 
                         AND n.semestre_id = ?
                         AND n.statut = 'valide'
                         ORDER BY m.nom, te.ordre",
                        [$etudiant_id, $semestre['id']]);
                    
                    if ($semestre['numero'] == 1) {
                        $notes_semestre1 = $notes;
                    } else {
                        $notes_semestre2 = $notes;
                    }
                }
            }
        }
        
        // Calculer les moyennes par semestre
        foreach ($semestres as $semestre) {
            $notes_semestre = executeQuery($db,
                "SELECT n.note, n.coefficient_note, m.coefficient as matiere_coeff
                 FROM notes n
                 JOIN matieres m ON n.matiere_id = m.id
                 WHERE n.etudiant_id = ? 
                 AND n.semestre_id = ?
                 AND n.statut = 'valide'",
                [$etudiant_id, $semestre['id']]);
            
            $total_points = 0;
            $total_coefficients = 0;
            
            foreach ($notes_semestre as $note) {
                $note_value = floatval($note['note']);
                $coefficient = floatval($note['coefficient_note'] ?? 1) * floatval($note['matiere_coeff'] ?? 1);
                $total_points += $note_value * $coefficient;
                $total_coefficients += $coefficient;
            }
            
            $moyenne = $total_coefficients > 0 ? $total_points / $total_coefficients : 0;
            
            $moyennes_semestres[$semestre['numero']] = array(
                'moyenne' => round($moyenne, 2),
                'total_coefficients' => $total_coefficients,
                'nombre_notes' => count($notes_semestre),
                'date_debut' => $semestre['date_debut'],
                'date_fin' => $semestre['date_fin']
            );
        }
        
        // Calculer la moyenne générale (moyenne des moyennes de semestre)
        $total_moyennes = 0;
        $nombre_semestres = 0;
        
        foreach ($moyennes_semestres as $moyenne_semestre) {
            if ($moyenne_semestre['moyenne'] > 0) {
                $total_moyennes += $moyenne_semestre['moyenne'];
                $nombre_semestres++;
            }
        }
        
        $moyenne_generale = $nombre_semestres > 0 ? round($total_moyennes / $nombre_semestres, 2) : 0;
        
        // Statistiques des notes
        $stats_notes = executeSingleQuery($db,
            "SELECT 
                COUNT(*) as total_notes,
                AVG(n.note) as moyenne_notes,
                MIN(n.note) as note_min,
                MAX(n.note) as note_max,
                COUNT(CASE WHEN n.note >= 10 THEN 1 END) as notes_valides,
                COUNT(CASE WHEN n.note < 10 THEN 1 END) as notes_ajournees
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.annee_academique_id = ?
             AND n.statut = 'valide'",
            [$etudiant_id, $annee_academique_id]);
        
        // Évolution des notes par mois
        $evolution_notes = executeQuery($db,
            "SELECT DATE_FORMAT(n.date_evaluation, '%Y-%m') as mois,
                    AVG(n.note) as moyenne_mois,
                    COUNT(*) as nombre_notes
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.annee_academique_id = ?
             AND n.statut = 'valide'
             GROUP BY DATE_FORMAT(n.date_evaluation, '%Y-%m')
             ORDER BY mois",
            [$etudiant_id, $annee_academique_id]);
        
        // Comparaison avec la classe (si disponible)
        if ($classe_id) {
            $comparaison_classe = executeSingleQuery($db,
                "SELECT 
                    AVG(n.note) as moyenne_classe,
                    COUNT(DISTINCT e.id) as effectif_classe,
                    COUNT(*) as total_notes_classe
                 FROM notes n
                 JOIN etudiants e ON n.etudiant_id = e.id
                 WHERE e.classe_id = ? 
                 AND n.annee_academique_id = ?
                 AND n.statut = 'valide'",
                [$classe_id, $annee_academique_id]);
        }
        
        // Récupérer les rattrapages
        $rattrapages = executeQuery($db,
            "SELECT r.*, m.nom as matiere_nom, m.code as matiere_code,
                    s.numero as semestre_numero
             FROM notes r
             JOIN matieres m ON r.matiere_id = m.id
             JOIN semestres s ON r.semestre_id = s.id
             WHERE r.etudiant_id = ? 
             AND r.statut = 'rattrapage'
             AND r.annee_academique_id = ?
             ORDER BY r.date_evaluation DESC",
            [$etudiant_id, $annee_academique_id]);
        
        // Récupérer les bulletins disponibles
        $bulletins = executeQuery($db,
            "SELECT b.*, s.numero as semestre_numero,
                    CONCAT(u.nom, ' ', u.prenom) as editeur_nom
             FROM bulletins b
             JOIN semestres s ON b.semestre_id = s.id
             LEFT JOIN utilisateurs u ON b.edite_par = u.id
             WHERE b.etudiant_id = ? 
             AND b.annee_academique_id = ?
             AND b.statut IN ('valide', 'publie')
             ORDER BY b.semestre_id DESC",
            [$etudiant_id, $annee_academique_id]);
        
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
    <title><?php echo safeHtml($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- ApexCharts (optionnel pour plus de fonctionnalités) -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
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
    
    /* Sidebar (identique aux autres pages) */
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
    
    /* Filtres */
    .filtres-container {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
    }
    
    /* Cartes de statistiques */
    .stat-card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        border-left: 4px solid var(--primary-color);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
    }
    
    .stat-card-success {
        border-left-color: var(--success-color);
    }
    
    .stat-card-warning {
        border-left-color: var(--warning-color);
    }
    
    .stat-card-danger {
        border-left-color: var(--accent-color);
    }
    
    .stat-card-info {
        border-left-color: var(--info-color);
    }
    
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
        color: var(--primary-color);
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    
    /* Notes */
    .note-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .note-excellent {
        background-color: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .note-tres-bien {
        background-color: rgba(52, 152, 219, 0.2);
        color: var(--secondary-color);
    }
    
    .note-bien {
        background-color: rgba(155, 89, 182, 0.2);
        color: #9b59b6;
    }
    
    .note-passable {
        background-color: rgba(243, 156, 18, 0.2);
        color: var(--warning-color);
    }
    
    .note-insuffisant {
        background-color: rgba(231, 76, 60, 0.2);
        color: var(--accent-color);
    }
    
    .note-faible {
        background-color: rgba(149, 165, 166, 0.2);
        color: #95a5a6;
    }
    
    /* Tableaux */
    .notes-table th {
        background-color: var(--primary-color);
        color: white;
        border: none;
    }
    
    .notes-table td {
        vertical-align: middle;
    }
    
    /* Graphiques */
    .chart-container {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
    }
    
    /* Carte de moyenne générale */
    .moyenne-card {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 30px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .moyenne-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(30deg);
    }
    
    .moyenne-value {
        font-size: 4rem;
        font-weight: bold;
        margin: 20px 0;
        position: relative;
        z-index: 1;
    }
    
    .moyenne-mention {
        font-size: 1.5rem;
        font-weight: 500;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }
    
    /* Progress bars */
    .progress {
        height: 10px;
        background-color: var(--border-color);
        border-radius: 5px;
        overflow: hidden;
    }
    
    .progress-bar {
        background: linear-gradient(to right, var(--accent-color), var(--warning-color), var(--success-color));
    }
    
    /* Tabs */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        margin-bottom: -1px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
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
        
        .moyenne-value {
            font-size: 3rem;
        }
    }
    
    /* Imprimer */
    @media print {
        .sidebar, .no-print, .filtres-container, .btn {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .stat-card, .chart-container {
            break-inside: avoid;
        }
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
        border-radius: 10px;
    }
    
    /* Tooltips */
    .note-tooltip {
        position: relative;
        display: inline-block;
        cursor: help;
    }
    
    .note-tooltip .tooltip-text {
        visibility: hidden;
        width: 200px;
        background-color: var(--primary-color);
        color: white;
        text-align: center;
        border-radius: 6px;
        padding: 5px;
        position: absolute;
        z-index: 1;
        bottom: 125%;
        left: 50%;
        margin-left: -100px;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 0.8rem;
    }
    
    .note-tooltip:hover .tooltip-text {
        visibility: visible;
        opacity: 1;
    }
    
    /* Boutons */
    .btn-outline-light {
        color: var(--sidebar-text);
        border-color: var(--sidebar-text);
    }
    
    .btn-outline-light:hover {
        background-color: var(--sidebar-text);
        color: var(--sidebar-bg);
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
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations</span>
                    </a>
                    <a href="carte_etudiante.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Carte Étudiante</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link active">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Examens</div>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens</span>
                    </a>
                    <a href="rattrapages.php" class="nav-link">
                        <i class="fas fa-redo-alt"></i>
                        <span>Rattrapages</span>
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
                            <i class="fas fa-chart-line me-2"></i>
                            Notes & Moyennes
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['niveau_libelle']) && !empty($info_etudiant['niveau_libelle'])): ?>
                            <?php echo safeHtml($info_etudiant['niveau_libelle']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="imprimerNotes()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="exporterNotes()">
                            <i class="fas fa-file-export"></i> Exporter
                        </button>
                        <button class="btn btn-info" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="filtres-container">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="annee" class="form-label">Année académique</label>
                        <select class="form-select" id="annee" name="annee" onchange="this.form.submit()">
                            <?php if(empty($annees_academiques)): ?>
                            <option value="0">Aucune année disponible</option>
                            <?php else: ?>
                            <?php foreach($annees_academiques as $annee): ?>
                            <option value="<?php echo $annee['id']; ?>" 
                                <?php echo ($annee['id'] == $annee_academique_id) ? 'selected' : ''; ?>>
                                <?php echo safeHtml($annee['libelle']); ?> 
                                (<?php echo safeHtml($annee['type_rentree']); ?>)
                                <?php if(($annee['statut'] ?? '') == 'active'): ?>
                                - <span class="text-success">Actif</span>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="semestre" class="form-label">Semestre</label>
                        <select class="form-select" id="semestre" name="semestre" onchange="this.form.submit()">
                            <option value="0">Tous les semestres</option>
                            <?php if(!empty($semestres)): ?>
                            <?php foreach($semestres as $semestre): ?>
                            <option value="<?php echo $semestre['id']; ?>" 
                                <?php echo ($semestre['id'] == $semestre_id) ? 'selected' : ''; ?>>
                                Semestre <?php echo $semestre['numero']; ?> 
                                (<?php echo formatDateFr($semestre['date_debut']); ?> - <?php echo formatDateFr($semestre['date_fin']); ?>)
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Filtrer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Section 1: Moyenne Générale et Statistiques -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="moyenne-card">
                        <h5 class="mb-3">Moyenne Générale</h5>
                        <div class="moyenne-value">
                            <?php echo number_format($moyenne_generale, 2); ?><small>/20</small>
                        </div>
                        <div class="moyenne-mention">
                            <?php echo getMention($moyenne_generale); ?>
                        </div>
                        <div class="mt-3">
                            <small>
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('F Y'); ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-check-circle stat-icon"></i>
                                    </div>
                                    <div>
                                        <div class="stat-value">
                                            <?php echo $stats_notes['notes_valides'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Notes ≥ 10/20</div>
                                        <div class="stat-change">
                                            <?php if(($stats_notes['total_notes'] ?? 0) > 0): ?>
                                            <?php echo round((($stats_notes['notes_valides'] ?? 0) / ($stats_notes['total_notes'] ?? 1)) * 100, 1); ?>% de réussite
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="stat-card stat-card-danger">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-times-circle stat-icon"></i>
                                    </div>
                                    <div>
                                        <div class="stat-value">
                                            <?php echo $stats_notes['notes_ajournees'] ?? 0; ?>
                                        </div>
                                        <div class="stat-label">Notes < 10/20</div>
                                        <div class="stat-change">
                                            <?php if(($stats_notes['total_notes'] ?? 0) > 0): ?>
                                            <?php echo count($rattrapages); ?> rattrapage(s) à prévoir
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="stat-card stat-card-info">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-chart-bar stat-icon"></i>
                                    </div>
                                    <div>
                                        <div class="stat-value">
                                            <?php echo number_format($stats_notes['moyenne_notes'] ?? 0, 2); ?>
                                        </div>
                                        <div class="stat-label">Moyenne des notes</div>
                                        <div class="stat-change">
                                            Min: <?php echo number_format($stats_notes['note_min'] ?? 0, 1); ?> 
                                            | Max: <?php echo number_format($stats_notes['note_max'] ?? 0, 1); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="stat-card stat-card-warning">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-users stat-icon"></i>
                                    </div>
                                    <div>
                                        <div class="stat-value">
                                            <?php if(isset($comparaison_classe['moyenne_classe']) && $comparaison_classe['moyenne_classe'] > 0): ?>
                                            <?php echo number_format($comparaison_classe['moyenne_classe'], 2); ?>
                                            <?php else: ?>
                                            N/A
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Moyenne de classe</div>
                                        <div class="stat-change">
                                            <?php if(isset($comparaison_classe['effectif_classe'])): ?>
                                            <?php echo $comparaison_classe['effectif_classe']; ?> étudiant(s)
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Tabs pour les semestres -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="notesTabs" role="tablist">
                        <?php if(!empty($notes_semestre1) || !empty($moyennes_semestres[1])): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="semestre1-tab" data-bs-toggle="tab" data-bs-target="#semestre1">
                                <i class="fas fa-1 me-2"></i>Semestre 1
                                <?php if(isset($moyennes_semestres[1]['moyenne'])): ?>
                                <span class="badge bg-primary ms-2">
                                    <?php echo number_format($moyennes_semestres[1]['moyenne'], 2); ?>/20
                                </span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($notes_semestre2) || !empty($moyennes_semestres[2])): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="semestre2-tab" data-bs-toggle="tab" data-bs-target="#semestre2">
                                <i class="fas fa-2 me-2"></i>Semestre 2
                                <?php if(isset($moyennes_semestres[2]['moyenne'])): ?>
                                <span class="badge bg-primary ms-2">
                                    <?php echo number_format($moyennes_semestres[2]['moyenne'], 2); ?>/20
                                </span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($rattrapages)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="rattrapages-tab" data-bs-toggle="tab" data-bs-target="#rattrapages">
                                <i class="fas fa-redo-alt me-2"></i>Rattrapages
                                <span class="badge bg-danger ms-2"><?php echo count($rattrapages); ?></span>
                            </button>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($bulletins)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bulletins-tab" data-bs-toggle="tab" data-bs-target="#bulletins">
                                <i class="fas fa-file-alt me-2"></i>Bulletins
                                <span class="badge bg-success ms-2"><?php echo count($bulletins); ?></span>
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="notesTabsContent">
                        <!-- Tab 1: Semestre 1 -->
                        <div class="tab-pane fade show active" id="semestre1">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>Détail des notes - Semestre 1</h5>
                                <?php if(isset($moyennes_semestres[1])): ?>
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="fas fa-calculator me-2"></i>
                                    <strong>Moyenne semestrielle:</strong> 
                                    <?php echo number_format($moyennes_semestres[1]['moyenne'], 2); ?>/20 
                                    (<?php echo $moyennes_semestres[1]['nombre_notes']; ?> notes)
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if(empty($notes_semestre1)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune note disponible pour le semestre 1
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover notes-table">
                                    <thead>
                                        <tr>
                                            <th>Matière</th>
                                            <th>Type</th>
                                            <th>Note</th>
                                            <th>Coeff</th>
                                            <th>Crédits</th>
                                            <th>Date</th>
                                            <th>Évaluateur</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $grouped_notes = [];
                                        foreach($notes_semestre1 as $note) {
                                            $matiere_id = $note['matiere_id'];
                                            if(!isset($grouped_notes[$matiere_id])) {
                                                $grouped_notes[$matiere_id] = [
                                                    'matiere' => $note['matiere_nom'],
                                                    'code' => $note['matiere_code'],
                                                    'coefficient' => $note['coefficient'],
                                                    'credit' => $note['credit'],
                                                    'notes' => []
                                                ];
                                            }
                                            $grouped_notes[$matiere_id]['notes'][] = $note;
                                        }
                                        
                                        foreach($grouped_notes as $matiere): 
                                            $moyenne_matiere = 0;
                                            $total_coeff = 0;
                                            
                                            foreach($matiere['notes'] as $note_detail) {
                                                $note_value = floatval($note_detail['note']);
                                                $coeff = floatval($note_detail['coefficient_note'] ?? 1);
                                                $moyenne_matiere += $note_value * $coeff;
                                                $total_coeff += $coeff;
                                            }
                                            $moyenne_matiere = $total_coeff > 0 ? $moyenne_matiere / $total_coeff : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo safeHtml($matiere['matiere']); ?></strong><br>
                                                <small class="text-muted"><?php echo safeHtml($matiere['code']); ?></small>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <span class="badge bg-secondary mb-1"><?php echo safeHtml($note_detail['type_examen']); ?></span><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): 
                                                    $note_value = floatval($note_detail['note']);
                                                ?>
                                                <div class="note-tooltip">
                                                    <span class="note-badge <?php echo getNoteClass($note_value); ?>">
                                                        <i class="<?php echo getNoteIcon($note_value); ?> me-1"></i>
                                                        <?php echo number_format($note_value, 2); ?>
                                                    </span>
                                                    <div class="tooltip-text">
                                                        <?php echo safeHtml($note_detail['type_examen']); ?><br>
                                                        Coefficient: <?php echo floatval($note_detail['coefficient_note'] ?? 1); ?>
                                                    </div>
                                                </div><br>
                                                <?php endforeach; ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">Moyenne: <strong><?php echo number_format($moyenne_matiere, 2); ?></strong></small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($matiere['coefficient']); ?>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($matiere['credit']); ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <small><?php echo formatDateFr($note_detail['date_evaluation'], 'd/m'); ?></small><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <small><?php echo safeHtml($note_detail['evaluateur_nom'] ?? 'N/A'); ?></small><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirDetailNote(<?php echo $matiere['notes'][0]['matiere_id'] ?? 0; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if($moyenne_matiere < 10): ?>
                                                <button class="btn btn-sm btn-outline-danger mt-1"
                                                        onclick="demanderRattrapage(<?php echo $matiere['notes'][0]['matiere_id'] ?? 0; ?>)">
                                                    <i class="fas fa-redo-alt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Graphique des notes du semestre 1 -->
                            <div class="chart-container mt-4">
                                <h6>Répartition des notes - Semestre 1</h6>
                                <canvas id="chartSemestre1"></canvas>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 2: Semestre 2 -->
                        <div class="tab-pane fade" id="semestre2">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>Détail des notes - Semestre 2</h5>
                                <?php if(isset($moyennes_semestres[2])): ?>
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="fas fa-calculator me-2"></i>
                                    <strong>Moyenne semestrielle:</strong> 
                                    <?php echo number_format($moyennes_semestres[2]['moyenne'], 2); ?>/20 
                                    (<?php echo $moyennes_semestres[2]['nombre_notes']; ?> notes)
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if(empty($notes_semestre2)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune note disponible pour le semestre 2
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover notes-table">
                                    <thead>
                                        <tr>
                                            <th>Matière</th>
                                            <th>Type</th>
                                            <th>Note</th>
                                            <th>Coeff</th>
                                            <th>Crédits</th>
                                            <th>Date</th>
                                            <th>Évaluateur</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $grouped_notes = [];
                                        foreach($notes_semestre2 as $note) {
                                            $matiere_id = $note['matiere_id'];
                                            if(!isset($grouped_notes[$matiere_id])) {
                                                $grouped_notes[$matiere_id] = [
                                                    'matiere' => $note['matiere_nom'],
                                                    'code' => $note['matiere_code'],
                                                    'coefficient' => $note['coefficient'],
                                                    'credit' => $note['credit'],
                                                    'notes' => []
                                                ];
                                            }
                                            $grouped_notes[$matiere_id]['notes'][] = $note;
                                        }
                                        
                                        foreach($grouped_notes as $matiere): 
                                            $moyenne_matiere = 0;
                                            $total_coeff = 0;
                                            
                                            foreach($matiere['notes'] as $note_detail) {
                                                $note_value = floatval($note_detail['note']);
                                                $coeff = floatval($note_detail['coefficient_note'] ?? 1);
                                                $moyenne_matiere += $note_value * $coeff;
                                                $total_coeff += $coeff;
                                            }
                                            $moyenne_matiere = $total_coeff > 0 ? $moyenne_matiere / $total_coeff : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo safeHtml($matiere['matiere']); ?></strong><br>
                                                <small class="text-muted"><?php echo safeHtml($matiere['code']); ?></small>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <span class="badge bg-secondary mb-1"><?php echo safeHtml($note_detail['type_examen']); ?></span><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): 
                                                    $note_value = floatval($note_detail['note']);
                                                ?>
                                                <div class="note-tooltip">
                                                    <span class="note-badge <?php echo getNoteClass($note_value); ?>">
                                                        <i class="<?php echo getNoteIcon($note_value); ?> me-1"></i>
                                                        <?php echo number_format($note_value, 2); ?>
                                                    </span>
                                                    <div class="tooltip-text">
                                                        <?php echo safeHtml($note_detail['type_examen']); ?><br>
                                                        Coefficient: <?php echo floatval($note_detail['coefficient_note'] ?? 1); ?>
                                                    </div>
                                                </div><br>
                                                <?php endforeach; ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">Moyenne: <strong><?php echo number_format($moyenne_matiere, 2); ?></strong></small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($matiere['coefficient']); ?>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($matiere['credit']); ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <small><?php echo formatDateFr($note_detail['date_evaluation'], 'd/m'); ?></small><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php foreach($matiere['notes'] as $note_detail): ?>
                                                <small><?php echo safeHtml($note_detail['evaluateur_nom'] ?? 'N/A'); ?></small><br>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirDetailNote(<?php echo $matiere['notes'][0]['matiere_id'] ?? 0; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if($moyenne_matiere < 10): ?>
                                                <button class="btn btn-sm btn-outline-danger mt-1"
                                                        onclick="demanderRattrapage(<?php echo $matiere['notes'][0]['matiere_id'] ?? 0; ?>)">
                                                    <i class="fas fa-redo-alt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Graphique des notes du semestre 2 -->
                            <div class="chart-container mt-4">
                                <h6>Répartition des notes - Semestre 2</h6>
                                <canvas id="chartSemestre2"></canvas>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 3: Rattrapages -->
                        <div class="tab-pane fade" id="rattrapages">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>Rattrapages à passer</h5>
                                <span class="badge bg-danger"><?php echo count($rattrapages); ?> matière(s)</span>
                            </div>
                            
                            <?php if(empty($rattrapages)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Félicitations ! Aucun rattrapage nécessaire.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($rattrapages as $rattrapage): 
                                    $note_value = floatval($rattrapage['note']);
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card border-danger">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title"><?php echo safeHtml($rattrapage['matiere_nom']); ?></h6>
                                                    <p class="card-text mb-1">
                                                        <small>Code: <?php echo safeHtml($rattrapage['matiere_code']); ?></small><br>
                                                        <small>Semestre: <?php echo safeHtml($rattrapage['semestre_numero']); ?></small>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <div class="note-badge <?php echo getNoteClass($note_value); ?> mb-2">
                                                        <?php echo number_format($note_value, 2); ?>/20
                                                    </div>
                                                    <div>
                                                        <small class="text-danger">Note initiale</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <div class="progress mb-2">
                                                    <div class="progress-bar" style="width: <?php echo ($note_value / 20) * 100; ?>%"></div>
                                                </div>
                                                <small>Objectif: <strong>10/20</strong> (manque <?php echo number_format(10 - $note_value, 2); ?> points)</small>
                                            </div>
                                            <div class="mt-3">
                                                <button class="btn btn-sm btn-outline-danger me-2" 
                                                        onclick="preparerRattrapage(<?php echo $rattrapage['id']; ?>)">
                                                    <i class="fas fa-book"></i> Préparer
                                                </button>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirPlanningRattrapage(<?php echo $rattrapage['matiere_id']; ?>)">
                                                    <i class="fas fa-calendar"></i> Planning
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Conseils pour les rattrapages -->
                            <div class="alert alert-warning mt-4">
                                <h6><i class="fas fa-lightbulb me-2"></i> Conseils pour réussir vos rattrapages</h6>
                                <ul class="mb-0">
                                    <li>Revoyez les cours et exercices de la matière</li>
                                    <li>Demandez des explications supplémentaires à vos professeurs</li>
                                    <li>Entraînez-vous avec les anciens sujets d'examen</li>
                                    <li>Formez des groupes de révision avec vos camarades</li>
                                    <li>Consultez les ressources de la bibliothèque</li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 4: Bulletins -->
                        <div class="tab-pane fade" id="bulletins">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>Bulletins disponibles</h5>
                                <button class="btn btn-success" onclick="genererBulletin()">
                                    <i class="fas fa-plus"></i> Demander un bulletin
                                </button>
                            </div>
                            
                            <?php if(empty($bulletins)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun bulletin disponible pour le moment
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($bulletins as $bulletin): 
                                    $semestre = $bulletin['semestre_numero'];
                                    $moyenne = $moyennes_semestres[$semestre]['moyenne'] ?? 0;
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title">Bulletin - Semestre <?php echo $semestre; ?></h6>
                                                    <p class="card-text mb-1">
                                                        <small>Année: <?php echo safeHtml($annees_academiques[0]['libelle'] ?? ''); ?></small><br>
                                                        <small>Édité le: <?php echo formatDateFr($bulletin['date_edition']); ?></small><br>
                                                        <small>Par: <?php echo safeHtml($bulletin['editeur_nom'] ?? 'Système'); ?></small>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <div class="moyenne-value mb-2" style="font-size: 1.5rem;">
                                                        <?php echo number_format($moyenne, 2); ?>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-<?php echo ($bulletin['statut'] ?? '') == 'publie' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($bulletin['statut'] ?? ''); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <p class="mb-2">
                                                    <strong>Appréciation:</strong><br>
                                                    <?php echo safeHtml($bulletin['appreciation'] ?? 'Non disponible'); ?>
                                                </p>
                                                <p class="mb-0">
                                                    <strong>Décision:</strong> 
                                                    <span class="badge bg-<?php echo ($bulletin['decision'] ?? '') == 'admis' ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($bulletin['decision'] ?? 'Non définie'); ?>
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="mt-3">
                                                <button class="btn btn-sm btn-outline-primary me-2" 
                                                        onclick="voirBulletin(<?php echo $bulletin['id']; ?>)">
                                                    <i class="fas fa-eye"></i> Consulter
                                                </button>
                                                <button class="btn btn-sm btn-outline-success me-2" 
                                                        onclick="telechargerBulletin(<?php echo $bulletin['id']; ?>)">
                                                    <i class="fas fa-download"></i> Télécharger
                                                </button>
                                                <?php if(!empty($bulletin['qr_code'])): ?>
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="verifierBulletin(<?php echo $bulletin['id']; ?>)">
                                                    <i class="fas fa-qrcode"></i> Vérifier
                                                </button>
                                                <?php endif; ?>
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
            </div>
            
            <!-- Section 3: Graphiques et Analyses -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h6>Évolution de la moyenne mensuelle</h6>
                        <canvas id="chartEvolution"></canvas>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="chart-container">
                        <h6>Répartition des notes par mention</h6>
                        <canvas id="chartMentions"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Progression et objectifs -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-bullseye me-2"></i>
                        Progression et objectifs
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6>Progression semestrielle</h6>
                            <?php if(isset($moyennes_semestres[1]) && isset($moyennes_semestres[2])): 
                                $progression = $moyennes_semestres[2]['moyenne'] - $moyennes_semestres[1]['moyenne'];
                            ?>
                            <div class="text-center">
                                <div class="display-4 mb-2 <?php echo $progression >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $progression >= 0 ? '+' : ''; ?><?php echo number_format($progression, 2); ?>
                                </div>
                                <p class="text-muted">Évolution S1 → S2</p>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info">
                                Données insuffisantes pour calculer la progression
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4">
                            <h6>Objectif de fin d'année</h6>
                            <div class="text-center">
                                <?php 
                                $objectif = 12; // Objectif par défaut
                                $ecart = $objectif - $moyenne_generale;
                                ?>
                                <div class="display-4 mb-2">
                                    <?php echo number_format($objectif, 1); ?><small>/20</small>
                                </div>
                                <p class="text-muted">
                                    <?php if($ecart > 0): ?>
                                    <span class="text-danger">Manque <?php echo number_format($ecart, 2); ?> points</span>
                                    <?php else: ?>
                                    <span class="text-success">Objectif atteint !</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <h6>Crédits obtenus</h6>
                            <?php 
                            $credits_obtenus = 0;
                            $credits_totaux = 60; // Total typique pour une année
                            
                            // Calculer les crédits obtenus (matières ≥ 10)
                            if(!empty($notes_semestre1)) {
                                foreach($grouped_notes as $matiere) {
                                    $moyenne_matiere = 0;
                                    $total_coeff = 0;
                                    
                                    foreach($matiere['notes'] as $note_detail) {
                                        $note_value = floatval($note_detail['note']);
                                        $coeff = floatval($note_detail['coefficient_note'] ?? 1);
                                        $moyenne_matiere += $note_value * $coeff;
                                        $total_coeff += $coeff;
                                    }
                                    $moyenne_matiere = $total_coeff > 0 ? $moyenne_matiere / $total_coeff : 0;
                                    
                                    if($moyenne_matiere >= 10) {
                                        $credits_obtenus += $matiere['credit'];
                                    }
                                }
                            }
                            ?>
                            <div class="text-center">
                                <div class="display-4 mb-2">
                                    <?php echo $credits_obtenus; ?><small>/<?php echo $credits_totaux; ?></small>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo ($credits_obtenus / $credits_totaux) * 100; ?>%"></div>
                                </div>
                                <p class="text-muted mt-2"><?php echo round(($credits_obtenus / $credits_totaux) * 100, 1); ?>% des crédits</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Informations importantes -->
            <div class="row">
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i> Comment lire vos notes</h6>
                        <ul class="mb-0">
                            <li><strong>Moyenne ≥ 16:</strong> Mention Très Bien (couleur verte)</li>
                            <li><strong>Moyenne ≥ 14:</strong> Mention Bien (couleur bleue)</li>
                            <li><strong>Moyenne ≥ 12:</strong> Mention Assez Bien (couleur violette)</li>
                            <li><strong>Moyenne ≥ 10:</strong> Admis (couleur orange)</li>
                            <li><strong>Moyenne < 10:</strong> Rattrapage nécessaire (couleur rouge)</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i> Important à savoir</h6>
                        <ul class="mb-0">
                            <li>Les notes sont définitives 48h après publication</li>
                            <li>Les réclamations doivent être faites dans les 7 jours</li>
                            <li>Conservez une copie de tous vos bulletins</li>
                            <li>Consultez régulièrement vos notes pour suivre votre progression</li>
                            <li>Contactez vos professeurs en cas de difficultés</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Initialiser les graphiques
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser le thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Données pour les graphiques (exemple)
        const notesData = [12.5, 14.2, 10.8, 16.5, 8.7, 11.3, 13.9];
        const evolutionData = [10.5, 11.2, 12.8, 13.5, 12.7, 14.3, 13.9];
        const mentionsData = [2, 5, 8, 6, 3]; // <10, 10-12, 12-14, 14-16, >16
        
        // Graphique Semestre 1
        const ctx1 = document.getElementById('chartSemestre1').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: ['<10', '10-12', '12-14', '14-16', '>16'],
                datasets: [{
                    label: 'Nombre de notes',
                    data: mentionsData,
                    backgroundColor: [
                        '#e74c3c',
                        '#f39c12',
                        '#3498db',
                        '#2ecc71',
                        '#9b59b6'
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
                        text: 'Répartition des notes - Semestre 1'
                    }
                }
            }
        });
        
        // Graphique Semestre 2
        const ctx2 = document.getElementById('chartSemestre2').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: ['<10', '10-12', '12-14', '14-16', '>16'],
                datasets: [{
                    label: 'Nombre de notes',
                    data: [1, 4, 7, 5, 2],
                    backgroundColor: [
                        '#e74c3c',
                        '#f39c12',
                        '#3498db',
                        '#2ecc71',
                        '#9b59b6'
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
                        text: 'Répartition des notes - Semestre 2'
                    }
                }
            }
        });
        
        // Graphique d'évolution
        const ctxEvo = document.getElementById('chartEvolution').getContext('2d');
        new Chart(ctxEvo, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil'],
                datasets: [{
                    label: 'Moyenne mensuelle',
                    data: evolutionData,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 8,
                        max: 20
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    },
                    title: {
                        display: true,
                        text: 'Évolution de la moyenne'
                    }
                }
            }
        });
        
        // Graphique des mentions
        const ctxMentions = document.getElementById('chartMentions').getContext('2d');
        new Chart(ctxMentions, {
            type: 'pie',
            data: {
                labels: ['Ajourné', 'Passable', 'Assez Bien', 'Bien', 'Très Bien'],
                datasets: [{
                    data: mentionsData,
                    backgroundColor: [
                        '#e74c3c',
                        '#f39c12',
                        '#3498db',
                        '#2ecc71',
                        '#9b59b6'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    title: {
                        display: true,
                        text: 'Répartition par mention'
                    }
                }
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
        
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Fonctions pour les actions
    function imprimerNotes() {
        window.print();
    }
    
    function exporterNotes() {
        // Simuler l'export en CSV
        const data = [
            ['Matière', 'Note', 'Coefficient', 'Date', 'Type'],
            ['Mathématiques', '14.5', '3', '2024-01-15', 'DST'],
            ['Physique', '12.0', '4', '2024-01-20', 'Session'],
            // Ajouter les données réelles ici
        ];
        
        let csvContent = "data:text/csv;charset=utf-8,";
        data.forEach(row => {
            csvContent += row.join(",") + "\r\n";
        });
        
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "notes_etudiant.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showNotification('Export CSV démarré', 'success');
    }
    
    function voirDetailNote(matiereId) {
        // Afficher les détails d'une matière
        fetch(`detail_note.php?matiere_id=${matiereId}&etudiant_id=<?php echo $etudiant_id; ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Afficher une modal avec les détails
                    const modalHtml = `
                        <div class="modal fade" id="detailNoteModal" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">${data.matiere_nom}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Note</th>
                                                    <th>Coeff</th>
                                                    <th>Date</th>
                                                    <th>Commentaire</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${data.notes.map(note => `
                                                    <tr>
                                                        <td>${note.type}</td>
                                                        <td><span class="badge ${getNoteClass(note.note)}">${note.note}/20</span></td>
                                                        <td>${note.coefficient}</td>
                                                        <td>${note.date}</td>
                                                        <td>${note.commentaire || 'Aucun'}</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                        <div class="alert alert-info">
                                            <strong>Moyenne matière:</strong> ${data.moyenne_matiere}/20<br>
                                            <strong>Statut:</strong> ${data.statut}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Créer et afficher la modal
                    const modalContainer = document.createElement('div');
                    modalContainer.innerHTML = modalHtml;
                    document.body.appendChild(modalContainer);
                    
                    const modal = new bootstrap.Modal(document.getElementById('detailNoteModal'));
                    modal.show();
                    
                    // Nettoyer après fermeture
                    document.getElementById('detailNoteModal').addEventListener('hidden.bs.modal', function() {
                        document.body.removeChild(modalContainer);
                    });
                }
            })
            .catch(error => {
                showNotification('Erreur lors du chargement des détails', 'error');
            });
    }
    
    function demanderRattrapage(matiereId) {
        if (confirm('Souhaitez-vous demander un rattrapage pour cette matière ?')) {
            fetch('demande_rattrapage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    matiere_id: matiereId,
                    etudiant_id: <?php echo $etudiant_id; ?>,
                    annee_academique_id: <?php echo $annee_academique_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Demande de rattrapage envoyée', 'success');
                } else {
                    showNotification(data.message || 'Erreur lors de la demande', 'error');
                }
            })
            .catch(error => {
                showNotification('Erreur réseau', 'error');
            });
        }
    }
    
    function preparerRattrapage(rattrapageId) {
        // Rediriger vers la page de préparation
        window.location.href = `preparer_rattrapage.php?id=${rattrapageId}`;
    }
    
    function voirPlanningRattrapage(matiereId) {
        // Afficher le planning des rattrapages
        window.open(`planning_rattrapages.php?matiere_id=${matiereId}`, '_blank');
    }
    
    function voirBulletin(bulletinId) {
        // Afficher le bulletin
        window.open(`voir_bulletin.php?id=${bulletinId}`, '_blank');
    }
    
    function telechargerBulletin(bulletinId) {
        // Télécharger le bulletin en PDF
        window.open(`telecharger_bulletin.php?id=${bulletinId}`, '_blank');
    }
    
    function verifierBulletin(bulletinId) {
        // Vérifier le bulletin via QR Code
        window.open(`verifier_bulletin.php?id=${bulletinId}`, '_blank');
    }
    
    function genererBulletin() {
        if (confirm('Générer un nouveau bulletin de notes ?')) {
            fetch('generer_bulletin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    etudiant_id: <?php echo $etudiant_id; ?>,
                    annee_academique_id: <?php echo $annee_academique_id; ?>,
                    semestre_id: <?php echo $semestre_id ?: 'null'; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Bulletin généré avec succès', 'success');
                    // Recharger la page après 2 secondes
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showNotification(data.message || 'Erreur lors de la génération', 'error');
                }
            })
            .catch(error => {
                showNotification('Erreur réseau', 'error');
            });
        }
    }
    
    // Fonction utilitaire pour les notifications
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} position-fixed`;
        notification.style.cssText = `
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        `;
        
        const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
        
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${icon} me-2"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }
    
    // Fonction pour déterminer la classe CSS d'une note
    function getNoteClass(note) {
        if (note >= 16) return 'note-excellent';
        if (note >= 14) return 'note-tres-bien';
        if (note >= 12) return 'note-bien';
        if (note >= 10) return 'note-passable';
        if (note >= 8) return 'note-insuffisant';
        return 'note-faible';
    }
    </script>
</body>
</html>