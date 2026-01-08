<?php
// dashboard/etudiant/examens.php

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
    $pageTitle = "Examens & Concours";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'planifie':
            case 'en_cours':
                return '<span class="badge bg-warning">Planifié</span>';
            case 'termine':
                return '<span class="badge bg-success">Terminé</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'reporte':
                return '<span class="badge bg-info">Reporté</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeEvaluationBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'ecrit':
                return '<span class="badge bg-primary">Écrit</span>';
            case 'oral':
                return '<span class="badge bg-info">Oral</span>';
            case 'pratique':
                return '<span class="badge bg-warning">Pratique</span>';
            case 'projet':
                return '<span class="badge bg-success">Projet</span>';
            case 'tp':
                return '<span class="badge bg-secondary">TP</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
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
    $examens_prochains = array();
    $examens_termines = array();
    $rattrapages = array();
    $concours = array();
    $notes_examens = array();
    $calendrier_examens = array();
    $salles_examens = array();
    $statistiques = array();
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
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $site_id = intval($info_etudiant['site_id'] ?? 0);
        $classe_id = intval($info_etudiant['classe_id'] ?? 0);
        
        // Récupérer les examens prochains (30 prochains jours)
        if ($classe_id > 0) {
            $examens_prochains = executeQuery($db,
                "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code, 
                        te.nom as type_examen, te.pourcentage,
                        CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                        s.nom as salle_nom, s.capacite,
                        DATEDIFF(ce.date_examen, CURDATE()) as jours_restants,
                        CASE 
                            WHEN ce.date_examen < CURDATE() THEN 'passe'
                            WHEN DATEDIFF(ce.date_examen, CURDATE()) <= 3 THEN 'proche'
                            WHEN DATEDIFF(ce.date_examen, CURDATE()) <= 7 THEN 'semaine'
                            ELSE 'avenir'
                        END as urgence
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                 LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                 LEFT JOIN salles s ON ce.salle = s.nom AND s.site_id = ?
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen >= CURDATE()
                 AND ce.date_examen <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 AND ce.statut IN ('planifie', 'en_cours')
                 ORDER BY ce.date_examen ASC, ce.heure_debut ASC
                 LIMIT 20",
                [$site_id, $classe_id]);
        }
        
        // Récupérer les examens terminés (30 derniers jours)
        if ($classe_id > 0) {
            $examens_termines = executeQuery($db,
                "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code, 
                        te.nom as type_examen, te.pourcentage,
                        CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                        n.note as note_etudiant, n.statut as statut_note,
                        DATEDIFF(CURDATE(), ce.date_examen) as jours_passes
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                 LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                 LEFT JOIN notes n ON ce.matiere_id = n.matiere_id 
                     AND n.etudiant_id = ? 
                     AND n.type_examen_id = ce.type_examen_id
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen < CURDATE()
                 AND ce.date_examen >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 AND ce.statut IN ('termine', 'annule', 'reporte')
                 ORDER BY ce.date_examen DESC
                 LIMIT 15",
                [$etudiant_id, $classe_id]);
        }
        
        // Récupérer les rattrapages
        if ($classe_id > 0) {
            $rattrapages = executeQuery($db,
                "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                        te.nom as type_examen, ce.date_rattrapage,
                        DATEDIFF(ce.date_rattrapage, CURDATE()) as jours_restants,
                        ce.motif_annulation
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 WHERE ce.classe_id = ? 
                 AND ce.date_rattrapage IS NOT NULL
                 AND ce.date_rattrapage >= CURDATE()
                 AND ce.statut IN ('annule', 'reporte')
                 ORDER BY ce.date_rattrapage ASC
                 LIMIT 10",
                [$classe_id]);
        }
        
        // Récupérer les concours et compétitions
        $concours = executeQuery($db,
            "SELECT 'concours_national' as type, 'Concours National d\'Excellence' as nom,
                    '2026-03-15' as date_concours, 'Niveau National' as niveau,
                    50 as places, 'Inscription ouverte' as statut
             UNION
             SELECT 'competition_projet' as type, 'Compétition de Projets Innovants' as nom,
                    '2026-04-20' as date_concours, 'Régional' as niveau,
                    30 as places, 'Inscriptions bientôt' as statut
             UNION
             SELECT 'olympiade_informatique' as type, 'Olympiade d\'Informatique' as nom,
                    '2026-05-10' as date_concours, 'International' as niveau,
                    20 as places, 'Pré-inscription' as statut
             ORDER BY date_concours ASC");
        
        // Récupérer les notes des examens
        $notes_examens = executeQuery($db,
            "SELECT n.*, m.nom as matiere_nom, m.code as matiere_code,
                    m.coefficient, te.nom as type_examen, te.pourcentage,
                    aa.libelle as annee_academique, s.numero as semestre_numero,
                    CASE 
                        WHEN n.note >= 16 THEN 'excellent'
                        WHEN n.note >= 12 THEN 'bon'
                        WHEN n.note >= 10 THEN 'moyen'
                        ELSE 'insuffisant'
                    END as appreciation
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN types_examens te ON n.type_examen_id = te.id
             JOIN annees_academiques aa ON n.annee_academique_id = aa.id
             JOIN semestres s ON n.semestre_id = s.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'valide'
             ORDER BY n.date_evaluation DESC
             LIMIT 15",
            [$etudiant_id]);
        
        // Récupérer le calendrier des examens (vue mensuelle)
        $calendrier_examens = executeQuery($db,
            "SELECT ce.date_examen, COUNT(*) as nb_examens,
                    GROUP_CONCAT(CONCAT(m.code, ': ', TIME(ce.heure_debut)) SEPARATOR '; ') as details
             FROM calendrier_examens ce
             JOIN matieres m ON ce.matiere_id = m.id
             WHERE ce.classe_id = ? 
             AND ce.date_examen >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
             AND ce.date_examen <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)
             AND ce.statut IN ('planifie', 'en_cours')
             GROUP BY ce.date_examen
             ORDER BY ce.date_examen
             LIMIT 30",
            [$classe_id]);
        
        // Récupérer les salles d'examen disponibles
        $salles_examens = executeQuery($db,
            "SELECT s.*, 
                    CASE 
                        WHEN s.type_salle = 'classe' THEN 'Salle de classe'
                        WHEN s.type_salle = 'amphi' THEN 'Amphithéâtre'
                        WHEN s.type_salle = 'salle_examen' THEN 'Salle d\'examen'
                        ELSE 'Autre'
                    END as type_salle_libelle,
                    (SELECT COUNT(*) FROM calendrier_examens ce 
                     WHERE ce.salle = s.nom 
                     AND ce.date_examen = CURDATE() 
                     AND ce.statut IN ('planifie', 'en_cours')) as examens_aujourdhui
             FROM salles s
             WHERE s.site_id = ? 
             AND s.statut = 'disponible'
             AND s.type_salle IN ('classe', 'amphi', 'salle_examen')
             ORDER BY s.nom
             LIMIT 10",
            [$site_id]);
        
        // Récupérer les statistiques des examens
        $statistiques = executeSingleQuery($db,
            "SELECT 
                (SELECT COUNT(*) FROM calendrier_examens ce 
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen >= CURDATE()
                 AND ce.statut IN ('planifie', 'en_cours')) as examens_a_venir,
                
                (SELECT COUNT(*) FROM calendrier_examens ce 
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen < CURDATE()
                 AND ce.statut = 'termine') as examens_passes,
                
                (SELECT COUNT(*) FROM calendrier_examens ce 
                 WHERE ce.classe_id = ? 
                 AND ce.date_rattrapage IS NOT NULL
                 AND ce.date_rattrapage >= CURDATE()) as rattrapages_a_venir,
                
                (SELECT AVG(n.note) FROM notes n 
                 JOIN calendrier_examens ce ON n.matiere_id = ce.matiere_id 
                 AND n.type_examen_id = ce.type_examen_id
                 WHERE ce.classe_id = ? 
                 AND n.etudiant_id = ? 
                 AND n.statut = 'valide') as moyenne_examens",
            [$classe_id, $classe_id, $classe_id, $classe_id, $etudiant_id]);
        
        if (!$statistiques) {
            $statistiques = array(
                'examens_a_venir' => 0,
                'examens_passes' => 0,
                'rattrapages_a_venir' => 0,
                'moyenne_examens' => 0
            );
        }
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
    
    <style>
    /* Réutilisation du même CSS que dashboard.php */
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
    
    /* Sidebar - Même que dashboard.php */
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
        background-color: var(--primary-color);
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
    
    /* Tabs */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-info {
        background-color: rgba(23, 162, 184, 0.1);
        border-left: 4px solid var(--info-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
    }
    
    /* Exam card */
    .exam-card {
        border-left: 4px solid var(--primary-color);
        margin-bottom: 15px;
        transition: all 0.3s;
    }
    
    .exam-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .exam-card.urgence-proche {
        border-left-color: var(--accent-color);
    }
    
    .exam-card.urgence-semaine {
        border-left-color: var(--warning-color);
    }
    
    /* Note badges */
    .note-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .note-excellent {
        background-color: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .note-good {
        background-color: rgba(52, 152, 219, 0.2);
        color: var(--secondary-color);
    }
    
    .note-average {
        background-color: rgba(243, 156, 18, 0.2);
        color: var(--warning-color);
    }
    
    .note-poor {
        background-color: rgba(231, 76, 60, 0.2);
        color: var(--accent-color);
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
                    <a href="examens.php" class="nav-link active">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens & Concours</span>
                    </a>
                    <a href="resultats.php" class="nav-link">
                        <i class="fas fa-poll"></i>
                        <span>Résultats</span>
                    </a>
                    <a href="rattrapages.php" class="nav-link">
                        <i class="fas fa-redo-alt"></i>
                        <span>Rattrapages</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Liens Utiles</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                    <a href="documents.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Mes Documents</span>
                    </a>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="parametres.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Paramètres</span>
                    </a>
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
                            <i class="fas fa-clipboard-list me-2"></i>
                            Examens & Concours
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['classe_nom']) && !empty($info_etudiant['classe_nom'])): ?>
                            Classe: <?php echo safeHtml($info_etudiant['classe_nom']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="printPage()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques des examens -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['examens_a_venir'] ?? 0); ?></div>
                        <div class="stat-label">Examens à Venir</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['examens_passes'] ?? 0); ?></div>
                        <div class="stat-label">Examens Passés</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-redo-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['rattrapages_a_venir'] ?? 0); ?></div>
                        <div class="stat-label">Rattrapages</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(floatval($statistiques['moyenne_examens'] ?? 0), 2); ?>/20</div>
                        <div class="stat-label">Moyenne Examens</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="examTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="prochains-tab" data-bs-toggle="tab" data-bs-target="#prochains" type="button">
                                <i class="fas fa-calendar-alt me-2"></i>Examens à Venir
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="passes-tab" data-bs-toggle="tab" data-bs-target="#passes" type="button">
                                <i class="fas fa-history me-2"></i>Examens Passés
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button">
                                <i class="fas fa-chart-line me-2"></i>Mes Notes
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="concours-tab" data-bs-toggle="tab" data-bs-target="#concours" type="button">
                                <i class="fas fa-trophy me-2"></i>Concours
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="examTabsContent">
                        <!-- Tab 1: Examens à venir -->
                        <div class="tab-pane fade show active" id="prochains">
                            <?php if(empty($examens_prochains)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucun examen à venir dans les 30 prochains jours.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($examens_prochains as $examen): 
                                    $urgence_class = '';
                                    switch($examen['urgence'] ?? '') {
                                        case 'passe': $urgence_class = 'text-muted'; break;
                                        case 'proche': $urgence_class = 'urgence-proche'; break;
                                        case 'semaine': $urgence_class = 'urgence-semaine'; break;
                                        default: $urgence_class = '';
                                    }
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card exam-card <?php echo $urgence_class; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h5 class="card-title mb-0">
                                                    <?php echo safeHtml($examen['matiere_nom'] ?? ''); ?>
                                                    <small class="text-muted">(<?php echo safeHtml($examen['matiere_code'] ?? ''); ?>)</small>
                                                </h5>
                                                <div>
                                                    <?php echo getTypeEvaluationBadge($examen['type_evaluation'] ?? ''); ?>
                                                    <?php if(intval($examen['jours_restants'] ?? 0) <= 3): ?>
                                                    <span class="badge bg-danger ms-1">J-<?php echo intval($examen['jours_restants']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Date</small>
                                                    <strong><?php echo formatDateFr($examen['date_examen'] ?? ''); ?></strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Heure</small>
                                                    <strong><?php echo substr($examen['heure_debut'] ?? '00:00:00', 0, 5); ?> - <?php echo substr($examen['heure_fin'] ?? '00:00:00', 0, 5); ?></strong>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Type d'examen</small>
                                                    <strong><?php echo safeHtml($examen['type_examen'] ?? ''); ?></strong>
                                                    <small class="text-muted">(<?php echo number_format(floatval($examen['pourcentage'] ?? 0), 1); ?>%)</small>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Salle</small>
                                                    <strong><?php echo safeHtml($examen['salle'] ?? 'Non définie'); ?></strong>
                                                    <?php if(isset($examen['capacite']) && $examen['capacite'] > 0): ?>
                                                    <small class="text-muted">(<?php echo intval($examen['capacite']); ?> places)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if(!empty($examen['enseignant_nom'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted d-block">Enseignant</small>
                                                <strong><?php echo safeHtml($examen['enseignant_nom']); ?></strong>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($examen['consignes'])): ?>
                                            <div class="alert alert-warning py-2 mb-2">
                                                <small><strong><i class="fas fa-exclamation-circle"></i> Consignes:</strong> 
                                                <?php echo safeHtml(substr($examen['consignes'], 0, 100)); ?>
                                                <?php if(strlen($examen['consignes']) > 100): ?>...<?php endif; ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($examen['documents_autorises'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted d-block"><i class="fas fa-file-alt"></i> Documents autorisés</small>
                                                <small><?php echo safeHtml($examen['documents_autorises']); ?></small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($examen['materiel_requis'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted d-block"><i class="fas fa-tools"></i> Matériel requis</small>
                                                <small><?php echo safeHtml($examen['materiel_requis']); ?></small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex justify-content-between mt-3">
                                                <small class="text-muted">
                                                    Coefficient: <?php echo number_format(floatval($examen['coefficient'] ?? 1.00), 2); ?>
                                                </small>
                                                <div>
                                                    <span class="badge bg-<?php echo ($examen['publie_etudiants'] ?? 0) ? 'success' : 'warning'; ?>">
                                                        <?php echo ($examen['publie_etudiants'] ?? 0) ? 'Publié' : 'Non publié'; ?>
                                                    </span>
                                                    <?php echo getStatutBadge($examen['statut'] ?? ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Rattrapages à venir -->
                            <?php if(!empty($rattrapages)): ?>
                            <h5 class="mt-4 mb-3">
                                <i class="fas fa-redo-alt me-2 text-warning"></i>
                                Rattrapages Programmes
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Matière</th>
                                            <th>Date du rattrapage</th>
                                            <th>Date initiale</th>
                                            <th>Motif</th>
                                            <th>Jours restants</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($rattrapages as $rattrapage): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?></strong><br>
                                                <small><?php echo safeHtml($rattrapage['type_examen'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo formatDateFr($rattrapage['date_rattrapage'] ?? ''); ?></strong>
                                            </td>
                                            <td><?php echo formatDateFr($rattrapage['date_examen'] ?? ''); ?></td>
                                            <td>
                                                <small><?php echo safeHtml(substr($rattrapage['motif_annulation'] ?? 'Non spécifié', 0, 50)); ?>
                                                <?php if(strlen($rattrapage['motif_annulation'] ?? '') > 50): ?>...<?php endif; ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo (intval($rattrapage['jours_restants'] ?? 0) <= 3 ? 'danger' : 'warning'); ?>">
                                                    J-<?php echo intval($rattrapage['jours_restants'] ?? 0); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 2: Examens passés -->
                        <div class="tab-pane fade" id="passes">
                            <?php if(empty($examens_termines)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun examen passé dans les 30 derniers jours.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Matière</th>
                                            <th>Type</th>
                                            <th>Pourcentage</th>
                                            <th>Votre note</th>
                                            <th>Statut note</th>
                                            <th>Enseignant</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($examens_termines as $examen): 
                                            $note_class = '';
                                            $note_value = floatval($examen['note_etudiant'] ?? 0);
                                            if ($note_value >= 16) $note_class = 'note-excellent';
                                            elseif ($note_value >= 12) $note_class = 'note-good';
                                            elseif ($note_value >= 10) $note_class = 'note-average';
                                            elseif ($note_value > 0) $note_class = 'note-poor';
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo formatDateFr($examen['date_examen'] ?? ''); ?><br>
                                                <small class="text-muted">Il y a <?php echo intval($examen['jours_passes'] ?? 0); ?> jours</small>
                                            </td>
                                            <td>
                                                <strong><?php echo safeHtml($examen['matiere_nom'] ?? ''); ?></strong><br>
                                                <small><?php echo safeHtml($examen['matiere_code'] ?? ''); ?></small>
                                            </td>
                                            <td><?php echo safeHtml($examen['type_examen'] ?? ''); ?></td>
                                            <td><?php echo number_format(floatval($examen['pourcentage'] ?? 0), 1); ?>%</td>
                                            <td>
                                                <?php if($note_value > 0): ?>
                                                <span class="note-badge <?php echo $note_class; ?>">
                                                    <?php echo number_format($note_value, 2); ?>/20
                                                </span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">Non noté</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if(!empty($examen['statut_note'])): ?>
                                                <span class="badge bg-<?php echo ($examen['statut_note'] == 'valide' ? 'success' : 'warning'); ?>">
                                                    <?php echo ucfirst($examen['statut_note']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if(!empty($examen['enseignant_nom'])): ?>
                                                <small><?php echo safeHtml($examen['enseignant_nom']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatutBadge($examen['statut'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Statistiques des examens passés -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-chart-pie me-2"></i>
                                                Répartition des types d'examens
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="typeExamChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-calendar me-2"></i>
                                                Calendrier des examens
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="calendarMini"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Mes notes -->
                        <div class="tab-pane fade" id="notes">
                            <?php if(empty($notes_examens)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune note d'examen disponible.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Matière</th>
                                                    <th>Type examen</th>
                                                    <th>Note</th>
                                                    <th>Coeff</th>
                                                    <th>Année</th>
                                                    <th>Semestre</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($notes_examens as $note): 
                                                    $note_class = '';
                                                    $note_value = floatval($note['note'] ?? 0);
                                                    if ($note_value >= 16) $note_class = 'note-excellent';
                                                    elseif ($note_value >= 12) $note_class = 'note-good';
                                                    elseif ($note_value >= 10) $note_class = 'note-average';
                                                    else $note_class = 'note-poor';
                                                ?>
                                                <tr>
                                                    <td><?php echo formatDateFr($note['date_evaluation'] ?? ''); ?></td>
                                                    <td>
                                                        <strong><?php echo safeHtml($note['matiere_nom'] ?? ''); ?></strong><br>
                                                        <small><?php echo safeHtml($note['matiere_code'] ?? ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo safeHtml($note['type_examen'] ?? ''); ?><br>
                                                        <small class="text-muted"><?php echo number_format(floatval($note['pourcentage'] ?? 0), 1); ?>%</small>
                                                    </td>
                                                    <td>
                                                        <span class="note-badge <?php echo $note_class; ?>">
                                                            <?php echo number_format($note_value, 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td><?php echo number_format(floatval($note['coefficient'] ?? 1.00), 2); ?></td>
                                                    <td><?php echo safeHtml($note['annee_academique'] ?? ''); ?></td>
                                                    <td>S<?php echo safeHtml($note['semestre_numero'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-chart-bar me-2"></i>
                                                Statistiques des notes
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <?php 
                                            $notes_values = array_column($notes_examens, 'note');
                                            $moyenne = count($notes_values) > 0 ? array_sum($notes_values) / count($notes_values) : 0;
                                            $max_note = count($notes_values) > 0 ? max($notes_values) : 0;
                                            $min_note = count($notes_values) > 0 ? min($notes_values) : 0;
                                            $notes_sup_10 = count(array_filter($notes_values, function($n) { return $n >= 10; }));
                                            $total_notes = count($notes_values);
                                            ?>
                                            <div class="mb-3">
                                                <small class="text-muted d-block">Moyenne générale</small>
                                                <h3 class="mb-0 <?php echo $moyenne >= 10 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo number_format($moyenne, 2); ?>/20
                                                </h3>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Meilleure note</small>
                                                    <h5 class="mb-0 text-success"><?php echo number_format($max_note, 2); ?>/20</h5>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Plus basse note</small>
                                                    <h5 class="mb-0 text-danger"><?php echo number_format($min_note, 2); ?>/20</h5>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted d-block">Taux de réussite</small>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-success" 
                                                         style="width: <?php echo $total_notes > 0 ? ($notes_sup_10/$total_notes*100) : 0; ?>%">
                                                        <?php echo $total_notes > 0 ? round($notes_sup_10/$total_notes*100, 1) : 0; ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo $notes_sup_10; ?> notes ≥ 10/20 sur <?php echo $total_notes; ?>
                                                </small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted d-block">Répartition des notes</small>
                                                <canvas id="notesDistributionChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 4: Concours -->
                        <div class="tab-pane fade" id="concours">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5 class="mb-3">
                                        <i class="fas fa-trophy me-2 text-warning"></i>
                                        Concours et Compétitions
                                    </h5>
                                    
                                    <?php if(empty($concours)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun concours programmé pour le moment.
                                    </div>
                                    <?php else: ?>
                                    <div class="row">
                                        <?php foreach($concours as $concours_item): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h5 class="card-title mb-0"><?php echo safeHtml($concours_item['nom'] ?? ''); ?></h5>
                                                        <span class="badge bg-<?php echo ($concours_item['statut'] ?? '') == 'Inscription ouverte' ? 'success' : 'warning'; ?>">
                                                            <?php echo safeHtml($concours_item['statut'] ?? ''); ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <small class="text-muted d-block">Date du concours</small>
                                                        <strong><?php echo formatDateFr($concours_item['date_concours'] ?? ''); ?></strong>
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Niveau</small>
                                                            <span class="badge bg-info"><?php echo safeHtml($concours_item['niveau'] ?? ''); ?></span>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Places</small>
                                                            <strong><?php echo intval($concours_item['places'] ?? 0); ?></strong>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mt-3">
                                                        <button class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-info-circle"></i> Plus d'infos
                                                        </button>
                                                        <?php if(($concours_item['statut'] ?? '') == 'Inscription ouverte'): ?>
                                                        <button class="btn btn-sm btn-success ms-2">
                                                            <i class="fas fa-edit"></i> S'inscrire
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
                                
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-door-open me-2"></i>
                                                Salles d'examen
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if(empty($salles_examens)): ?>
                                            <div class="alert alert-info">
                                                Aucune information sur les salles disponibles
                                            </div>
                                            <?php else: ?>
                                            <div class="list-group">
                                                <?php foreach($salles_examens as $salle): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex w-100 justify-content-between">
                                                        <h6 class="mb-1"><?php echo safeHtml($salle['nom'] ?? ''); ?></h6>
                                                        <span class="badge bg-success">Disponible</span>
                                                    </div>
                                                    <p class="mb-1 small">
                                                        <i class="fas fa-users"></i> <?php echo intval($salle['capacite'] ?? 0); ?> places<br>
                                                        <i class="fas fa-building"></i> <?php echo safeHtml($salle['type_salle_libelle'] ?? ''); ?>
                                                    </p>
                                                    <?php if(intval($salle['examens_aujourdhui'] ?? 0) > 0): ?>
                                                    <small class="text-warning">
                                                        <i class="fas fa-clipboard-list"></i> 
                                                        <?php echo intval($salle['examens_aujourdhui']); ?> examen(s) aujourd'hui
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-lightbulb me-2"></i>
                                                Conseils pour les examens
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-info">
                                                <h6><i class="fas fa-clock"></i> Préparation</h6>
                                                <ul class="mb-0 small">
                                                    <li>Commencez à réviser au moins 2 semaines à l'avance</li>
                                                    <li>Faites des fiches de révision</li>
                                                    <li>Entraînez-vous avec les annales</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="alert alert-warning">
                                                <h6><i class="fas fa-clipboard-check"></i> Jour de l'examen</h6>
                                                <ul class="mb-0 small">
                                                    <li>Arrivez 30 minutes à l'avance</li>
                                                    <li>Vérifiez votre matériel autorisé</li>
                                                    <li>Ayez votre carte d'étudiant</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Actions Rapides -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='calendrier.php'">
                                        <i class="fas fa-calendar"></i>
                                        <div class="title">Calendrier</div>
                                        <div class="description">Voir calendrier complet</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='resultats.php'">
                                        <i class="fas fa-poll"></i>
                                        <div class="title">Résultats</div>
                                        <div class="description">Consulter résultats</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='rattrapages.php'">
                                        <i class="fas fa-redo-alt"></i>
                                        <div class="title">Rattrapages</div>
                                        <div class="description">Voir rattrapages</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='documents.php'">
                                        <i class="fas fa-file-pdf"></i>
                                        <div class="title">Sujets</div>
                                        <div class="description">Sujets d'annales</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='professeurs.php'">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <div class="title">Questions</div>
                                        <div class="description">Poser questions</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.print()">
                                        <i class="fas fa-print"></i>
                                        <div class="title">Imprimer</div>
                                        <div class="description">Planification</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Alertes importantes -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Alertes & Rappels Importants
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php 
                            // Vérifier les examens dans moins de 3 jours
                            $examens_urgents = array_filter($examens_prochains, function($examen) {
                                return intval($examen['jours_restants'] ?? 0) <= 3;
                            });
                            ?>
                            
                            <?php if(count($examens_urgents) > 0): ?>
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-circle"></i> Examens urgents (dans moins de 3 jours)</h6>
                                <ul class="mb-0">
                                    <?php foreach($examens_urgents as $examen): ?>
                                    <li>
                                        <strong><?php echo safeHtml($examen['matiere_nom'] ?? ''); ?></strong> - 
                                        <?php echo formatDateFr($examen['date_examen'] ?? ''); ?> à 
                                        <?php echo substr($examen['heure_debut'] ?? '00:00:00', 0, 5); ?> - 
                                        Salle: <?php echo safeHtml($examen['salle'] ?? 'Non définie'); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!empty($rattrapages)): ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-redo-alt"></i> Rattrapages programmés</h6>
                                <ul class="mb-0">
                                    <?php foreach($rattrapages as $rattrapage): ?>
                                    <li>
                                        <strong><?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?></strong> - 
                                        Rattrapage le <?php echo formatDateFr($rattrapage['date_rattrapage'] ?? ''); ?> 
                                        (initialement le <?php echo formatDateFr($rattrapage['date_examen'] ?? ''); ?>)
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Informations importantes</h6>
                                <ul class="mb-0">
                                    <li><strong>Absence à un examen:</strong> Un justificatif médical est obligatoire pour pouvoir passer un rattrapage</li>
                                    <li><strong>Matériel oublié:</strong> En cas d'oubli de matériel requis, vous risquez l'annulation de votre copie</li>
                                    <li><strong>Retard:</strong> Au-delà de 30 minutes de retard, l'accès à la salle d'examen peut vous être refusé</li>
                                    <li><strong>Résultats:</strong> Les résultats sont généralement publiés dans les 72 heures après l'examen</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    
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
            const icon = button.querySelector('i');
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
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
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Graphique des types d'examens
        const typeExamCtx = document.getElementById('typeExamChart');
        if (typeExamCtx) {
            new Chart(typeExamCtx, {
                type: 'doughnut',
                data: {
                    labels: ['DST', 'Devoir de Recherche', 'Session', 'Oral', 'Pratique'],
                    datasets: [{
                        data: [30, 20, 40, 5, 5],
                        backgroundColor: [
                            '#3498db',
                            '#2ecc71',
                            '#e74c3c',
                            '#f39c12',
                            '#9b59b6'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });
        }
        
        // Graphique de distribution des notes
        const notesDistCtx = document.getElementById('notesDistributionChart');
        if (notesDistCtx) {
            new Chart(notesDistCtx, {
                type: 'bar',
                data: {
                    labels: ['0-8', '8-10', '10-12', '12-14', '14-16', '16-20'],
                    datasets: [{
                        label: 'Nombre de notes',
                        data: [2, 3, 5, 4, 3, 2],
                        backgroundColor: [
                            '#e74c3c',
                            '#f39c12',
                            '#3498db',
                            '#2ecc71',
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
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
        
        // Calendrier mini
        const calendarEl = document.getElementById('calendarMini');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                headerToolbar: {
                    left: 'prev,next',
                    center: 'title',
                    right: 'today'
                },
                height: '300px',
                events: [
                    <?php 
                    foreach($calendrier_examens as $cal): 
                        $date = $cal['date_examen'] ?? '';
                        if(empty($date)) continue;
                        $nb_examens = intval($cal['nb_examens'] ?? 0);
                        $color = $nb_examens > 2 ? '#e74c3c' : ($nb_examens > 1 ? '#f39c12' : '#3498db');
                    ?>
                    {
                        title: '<?php echo $nb_examens; ?> exam.',
                        start: '<?php echo $date; ?>',
                        color: '<?php echo $color; ?>',
                        description: '<?php echo safeHtml($cal['details'] ?? ''); ?>'
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    alert('Examens le ' + info.event.start.toLocaleDateString('fr-FR') + 
                          '\n' + info.event.extendedProps.description);
                }
            });
            calendar.render();
        }
    });
    
    // Fonction d'impression
    function printPage() {
        window.print();
    }
    
    // Fonction pour exporter le calendrier
    function exportCalendar() {
        alert('Fonction d\'export du calendrier à implémenter');
    }
    </script>
</body>
</html>