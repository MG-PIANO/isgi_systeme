<?php
// dashboard/etudiant/cours.php

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
    $pageTitle = "Cours Actifs - Étudiant";
    
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
    
    function getStatutCours($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'en_cours':
                return '<span class="badge bg-success">En cours</span>';
            case 'termine':
                return '<span class="badge bg-secondary">Terminé</span>';
            case 'planifie':
                return '<span class="badge bg-warning">Planifié</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            default:
                return '<span class="badge bg-info">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getCoefficientClass($coeff) {
        $coeff = floatval($coeff);
        if ($coeff >= 4) return 'text-danger fw-bold';
        if ($coeff >= 3) return 'text-warning fw-bold';
        if ($coeff >= 2) return 'text-primary fw-bold';
        return 'text-secondary';
    }
    
    function getVolumeHoraireClass($volume) {
        $volume = intval($volume);
        if ($volume >= 60) return 'bg-danger text-white';
        if ($volume >= 45) return 'bg-warning text-dark';
        if ($volume >= 30) return 'bg-info text-white';
        return 'bg-light text-dark';
    }
    
    function getNoteClass($note) {
        $note = floatval($note);
        if ($note >= 16) return 'note-excellent';
        if ($note >= 12) return 'note-good';
        if ($note >= 10) return 'note-average';
        return 'note-poor';
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
    $user_id = SessionManager::getUserId();
    $etudiant_id = SessionManager::getEtudiantId();
    
    // Initialiser les variables
    $info_etudiant = array();
    $cours_actifs = array();
    $enseignants = array();
    $semestres = array();
    $notes_par_matiere = array();
    $presence_par_matiere = array();
    $cours_detail = array();
    $notes_cours = array();
    $presence_cours = array();
    $error = null;
    $semestre_selectionne = isset($_GET['semestre']) ? intval($_GET['semestre']) : null;
    $search_term = isset($_GET['search']) ? safeHtml($_GET['search']) : '';
    $filtre_type = isset($_GET['type']) ? safeHtml($_GET['type']) : '';
    $cours_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $onglet_actif = isset($_GET['onglet']) ? safeHtml($_GET['onglet']) : 'liste';
    
    // Fonction pour exécuter les requêtes
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
                f.id as filiere_id, f.nom as filiere_nom, 
                n.id as niveau_id, n.libelle as niveau_libelle, n.cycle as cycle_niveau
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $classe_id = isset($info_etudiant['classe_id']) ? intval($info_etudiant['classe_id']) : null;
        $filiere_id = isset($info_etudiant['filiere_id']) ? intval($info_etudiant['filiere_id']) : null;
        $niveau_id = isset($info_etudiant['niveau_id']) ? intval($info_etudiant['niveau_id']) : null;
        $site_id = isset($info_etudiant['site_id']) ? intval($info_etudiant['site_id']) : null;
        
        // Récupérer les semestres de l'année académique en cours
        $annee_active = executeSingleQuery($db,
            "SELECT id FROM annees_academiques 
             WHERE site_id = ? AND statut = 'active' 
             ORDER BY date_debut DESC LIMIT 1",
            [$site_id]);
        
        $annee_academique_id = $annee_active['id'] ?? null;
        
        if ($annee_academique_id) {
            $semestres = executeQuery($db,
                "SELECT * FROM semestres 
                 WHERE annee_academique_id = ? 
                 ORDER BY numero",
                [$annee_academique_id]);
            
            // Si pas de semestre sélectionné, prendre le premier
            if (!$semestre_selectionne && count($semestres) > 0) {
                $semestre_selectionne = $semestres[0]['id'];
            }
        }
        
        // Si un ID de cours est spécifié, charger les détails
        if ($cours_id) {
            $cours_detail = executeSingleQuery($db,
                "SELECT m.*, 
                        f.nom as filiere_nom,
                        n.libelle as niveau_libelle,
                        s.numero as semestre_numero,
                        s.date_debut as semestre_debut,
                        s.date_fin as semestre_fin,
                        CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                        u.email as enseignant_email,
                        u.telephone as enseignant_telephone,
                        e.matricule as enseignant_matricule,
                        e.grade as enseignant_grade,
                        e.specialite as enseignant_specialite,
                        e.date_embauche as enseignant_embauche
                 FROM matieres m
                 LEFT JOIN filieres f ON m.filiere_id = f.id
                 LEFT JOIN niveaux n ON m.niveau_id = n.id
                 LEFT JOIN semestres s ON m.semestre_id = s.id
                 LEFT JOIN enseignants e ON m.enseignant_id = e.id
                 LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                 WHERE m.id = ? AND m.site_id = ?",
                [$cours_id, $site_id]);
            
            if ($cours_detail) {
                // Récupérer les notes pour ce cours
                $notes_cours = executeQuery($db,
                    "SELECT n.*, te.nom as type_examen, 
                            CONCAT(u.nom, ' ', u.prenom) as evaluateur_nom
                     FROM notes n
                     JOIN types_examens te ON n.type_examen_id = te.id
                     LEFT JOIN utilisateurs u ON n.evaluateur_id = u.id
                     WHERE n.etudiant_id = ? AND n.matiere_id = ?
                     AND n.statut = 'valide'
                     ORDER BY n.date_evaluation DESC",
                    [$etudiant_id, $cours_id]);
                
                // Récupérer les présences pour ce cours
                $presence_cours = executeQuery($db,
                    "SELECT p.*, CONCAT(u.nom, ' ', u.prenom) as surveillant_nom
                     FROM presences p
                     LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
                     WHERE p.etudiant_id = ? AND p.matiere_id = ?
                     ORDER BY p.date_heure DESC
                     LIMIT 20",
                    [$etudiant_id, $cours_id]);
                
                // Calculer la moyenne du cours
                $moyenne_cours = executeSingleQuery($db,
                    "SELECT AVG(note) as moyenne, COUNT(*) as total_notes
                     FROM notes
                     WHERE etudiant_id = ? AND matiere_id = ?
                     AND statut = 'valide'",
                    [$etudiant_id, $cours_id]);
                
                // Calculer les présences
                $stats_presence = executeSingleQuery($db,
                    "SELECT 
                        COUNT(CASE WHEN statut = 'present' THEN 1 END) as presents,
                        COUNT(CASE WHEN statut = 'absent' THEN 1 END) as absents,
                        COUNT(CASE WHEN statut = 'retard' THEN 1 END) as retards,
                        COUNT(CASE WHEN statut = 'justifie' THEN 1 END) as justifie,
                        COUNT(*) as total
                     FROM presences
                     WHERE etudiant_id = ? AND matiere_id = ?",
                    [$etudiant_id, $cours_id]);
            }
        }
        
        // Construire la requête pour les cours actifs
        $query_conditions = [];
        $query_params = [];
        
        if ($classe_id) {
            // Si l'étudiant a une classe, on filtre par filière et niveau de la classe
            $query_conditions[] = "m.filiere_id = ?";
            $query_params[] = $filiere_id;
            
            $query_conditions[] = "m.niveau_id = ?";
            $query_params[] = $niveau_id;
        }
        
        // Filtrer par semestre si spécifié
        if ($semestre_selectionne) {
            $query_conditions[] = "m.semestre_id = ?";
            $query_params[] = $semestre_selectionne;
        }
        
        // Filtre par site
        $query_conditions[] = "m.site_id = ?";
        $query_params[] = $site_id;
        
        // Filtre de recherche
        if ($search_term) {
            $query_conditions[] = "(m.nom LIKE ? OR m.code LIKE ? OR f.nom LIKE ?)";
            $search_param = "%" . $search_term . "%";
            $query_params[] = $search_param;
            $query_params[] = $search_param;
            $query_params[] = $search_param;
        }
        
        // Construire la requête complète
        $query_base = "
            SELECT DISTINCT m.*, 
                   f.nom as filiere_nom,
                   n.libelle as niveau_libelle,
                   s.numero as semestre_numero,
                   CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                   e.matricule as enseignant_matricule,
                   e.grade as enseignant_grade,
                   e.specialite as enseignant_specialite
            FROM matieres m
            LEFT JOIN filieres f ON m.filiere_id = f.id
            LEFT JOIN niveaux n ON m.niveau_id = n.id
            LEFT JOIN semestres s ON m.semestre_id = s.id
            LEFT JOIN enseignants e ON m.enseignant_id = e.id
            LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
        ";
        
        if (!empty($query_conditions)) {
            $query_base .= " WHERE " . implode(" AND ", $query_conditions);
        }
        
        // Ajouter le tri
        switch ($filtre_type) {
            case 'nom_asc':
                $query_base .= " ORDER BY m.nom ASC";
                break;
            case 'nom_desc':
                $query_base .= " ORDER BY m.nom DESC";
                break;
            case 'coeff_desc':
                $query_base .= " ORDER BY m.coefficient DESC";
                break;
            case 'coeff_asc':
                $query_base .= " ORDER BY m.coefficient ASC";
                break;
            default:
                $query_base .= " ORDER BY m.semestre_id, m.code, m.nom";
        }
        
        // Exécuter la requête
        $cours_actifs = executeQuery($db, $query_base, $query_params);
        
        // Récupérer les notes par matière pour cet étudiant
        if ($etudiant_id && count($cours_actifs) > 0) {
            $matiere_ids = array_column($cours_actifs, 'id');
            $placeholders = str_repeat('?,', count($matiere_ids) - 1) . '?';
            
            $notes_query = "
                SELECT n.matiere_id, 
                       AVG(n.note) as moyenne_matiere,
                       COUNT(n.id) as nombre_notes,
                       MAX(n.date_evaluation) as derniere_evaluation
                FROM notes n
                WHERE n.etudiant_id = ? 
                AND n.matiere_id IN ($placeholders)
                AND n.statut = 'valide'
                GROUP BY n.matiere_id
            ";
            
            $notes_params = array_merge([$etudiant_id], $matiere_ids);
            $notes_result = executeQuery($db, $notes_query, $notes_params);
            
            foreach ($notes_result as $note) {
                $notes_par_matiere[$note['matiere_id']] = $note;
            }
            
            // Récupérer les présences par matière
            $presence_query = "
                SELECT p.matiere_id,
                       COUNT(CASE WHEN p.statut = 'present' THEN 1 END) as presents,
                       COUNT(*) as total_sessions
                FROM presences p
                WHERE p.etudiant_id = ? 
                AND p.matiere_id IN ($placeholders)
                AND p.matiere_id IS NOT NULL
                GROUP BY p.matiere_id
            ";
            
            $presence_result = executeQuery($db, $presence_query, $notes_params);
            
            foreach ($presence_result as $presence) {
                $presence_par_matiere[$presence['matiere_id']] = $presence;
            }
        }
        
        // Récupérer tous les enseignants du site pour le filtre
        $enseignants = executeQuery($db,
            "SELECT e.id, CONCAT(u.nom, ' ', u.prenom) as nom_complet, e.matricule
             FROM enseignants e
             JOIN utilisateurs u ON e.utilisateur_id = u.id
             WHERE e.site_id = ? AND e.statut = 'actif'
             ORDER BY u.nom, u.prenom",
            [$site_id]);
            
    } else {
        $error = "Informations étudiant non trouvées. Veuillez contacter l'administration.";
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
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Chart.js -->
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
    
    /* Sidebar (identique au dashboard) */
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
    
    /* Onglets */
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
    .badge-coefficient {
        font-size: 0.8rem;
        padding: 4px 8px;
    }
    
    /* Progress bars */
    .progress {
        height: 8px;
        margin-top: 5px;
    }
    
    .note-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 15px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .note-excellent { background-color: #d4edda; color: #155724; }
    .note-good { background-color: #d1ecf1; color: #0c5460; }
    .note-average { background-color: #fff3cd; color: #856404; }
    .note-poor { background-color: #f8d7da; color: #721c24; }
    
    /* Filtres */
    .filter-section {
        background: var(--card-bg);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
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
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    /* Détail cours */
    .materie-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    
    .info-card {
        background: var(--card-bg);
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 15px;
    }
    
    .info-label {
        font-size: 0.85rem;
        color: var(--text-muted);
        margin-bottom: 5px;
    }
    
    .info-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--primary-color);
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
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique au dashboard) -->
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
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations Personnelles</span>
                    </a>
                    <a href="carte_etudiante.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Carte Étudiante</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
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
                    <a href="cours.php" class="nav-link active">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances</div>
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Situation Financière</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures & Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Dettes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Ressources</div>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                    </a>
                    <a href="documents.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Mes Documents</span>
                    </a>
                    <a href="annonces.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>Annonces</span>
                    </a>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier Académique</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Examens</div>
                    <a href="examens.php" class="nav-link">
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
                    <div class="nav-section-title">Communication</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="messagerie.php" class="nav-link">
                        <i class="fas fa-envelope"></i>
                        <span>Messagerie</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Mes Professeurs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
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
                            <i class="fas fa-book me-2"></i>
                            Cours Actifs
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
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Exporter
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
            
            <!-- Onglets de navigation -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="coursTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $onglet_actif == 'liste' ? 'active' : ''; ?>" 
                                    id="liste-tab" onclick="window.location.href='cours.php?onglet=liste<?php echo $semestre_selectionne ? '&semestre=' . $semestre_selectionne : ''; ?>'">
                                <i class="fas fa-list me-2"></i> Liste des Cours
                            </button>
                        </li>
                        <?php if($cours_id && $cours_detail): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $onglet_actif == 'detail' ? 'active' : ''; ?>" 
                                    id="detail-tab" onclick="window.location.href='cours.php?onglet=detail&id=<?php echo $cours_id; ?>'">
                                <i class="fas fa-info-circle me-2"></i> Détail du Cours
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $onglet_actif == 'stats' ? 'active' : ''; ?>" 
                                    id="stats-tab" onclick="window.location.href='cours.php?onglet=stats<?php echo $semestre_selectionne ? '&semestre=' . $semestre_selectionne : ''; ?>'">
                                <i class="fas fa-chart-bar me-2"></i> Statistiques
                            </button>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body">
                    <!-- Onglet 1: Liste des cours -->
                    <div class="tab-pane fade <?php echo $onglet_actif == 'liste' ? 'show active' : ''; ?>" id="liste">
                        <!-- Filtres -->
                        <div class="filter-section">
                            <form method="GET" action="" class="row g-3">
                                <input type="hidden" name="onglet" value="liste">
                                <div class="col-md-3">
                                    <label for="semestre" class="form-label">Semestre</label>
                                    <select class="form-select" id="semestre" name="semestre">
                                        <option value="">Tous les semestres</option>
                                        <?php foreach($semestres as $semestre): ?>
                                        <option value="<?php echo $semestre['id']; ?>" 
                                            <?php echo $semestre_selectionne == $semestre['id'] ? 'selected' : ''; ?>>
                                            Semestre <?php echo $semestre['numero']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="search" class="form-label">Recherche</label>
                                    <input type="text" class="form-control" id="search" name="search" 
                                           placeholder="Nom du cours, code..." value="<?php echo $search_term; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="type" class="form-label">Tri par</label>
                                    <select class="form-select" id="type" name="type">
                                        <option value="">Trier par...</option>
                                        <option value="nom_asc" <?php echo $filtre_type == 'nom_asc' ? 'selected' : ''; ?>>Nom (A-Z)</option>
                                        <option value="nom_desc" <?php echo $filtre_type == 'nom_desc' ? 'selected' : ''; ?>>Nom (Z-A)</option>
                                        <option value="coeff_desc" <?php echo $filtre_type == 'coeff_desc' ? 'selected' : ''; ?>>Coefficient (décroissant)</option>
                                        <option value="coeff_asc" <?php echo $filtre_type == 'coeff_asc' ? 'selected' : ''; ?>>Coefficient (croissant)</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter"></i> Filtrer
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Statistiques rapides -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card stat-card">
                                    <div class="text-primary stat-icon">
                                        <i class="fas fa-book-open"></i>
                                    </div>
                                    <div class="stat-value"><?php echo count($cours_actifs); ?></div>
                                    <div class="stat-label">Cours Actifs</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card">
                                    <div class="text-success stat-icon">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <div class="stat-value">
                                        <?php 
                                        $enseignants_uniques = array_unique(array_column($cours_actifs, 'enseignant_id'));
                                        echo count(array_filter($enseignants_uniques));
                                        ?>
                                    </div>
                                    <div class="stat-label">Enseignants</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card">
                                    <div class="text-warning stat-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-value">
                                        <?php echo array_sum(array_column($cours_actifs, 'volume_horaire')); ?>h
                                    </div>
                                    <div class="stat-label">Volume horaire</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card">
                                    <div class="text-info stat-icon">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="stat-value">
                                        <?php 
                                        $total_coeff = array_sum(array_column($cours_actifs, 'coefficient'));
                                        echo number_format($total_coeff, 1);
                                        ?>
                                    </div>
                                    <div class="stat-label">Total coefficients</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tableau des cours -->
                        <?php if(empty($cours_actifs)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun cours trouvé pour votre filière et niveau.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="coursTable">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Matière</th>
                                        <th>Semestre</th>
                                        <th>Coeff</th>
                                        <th>Volume H</th>
                                        <th>Enseignant</th>
                                        <th>Moyenne</th>
                                        <th>Présence</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($cours_actifs as $cours): 
                                        $note_matiere = $notes_par_matiere[$cours['id']] ?? null;
                                        $presence_matiere = $presence_par_matiere[$cours['id']] ?? null;
                                        $moyenne = $note_matiere['moyenne_matiere'] ?? null;
                                        
                                        // Déterminer la classe de la note
                                        $note_class = '';
                                        if ($moyenne !== null) {
                                            $note_class = getNoteClass($moyenne);
                                        }
                                        
                                        // Taux de présence
                                        $taux_presence = null;
                                        if ($presence_matiere && $presence_matiere['total_sessions'] > 0) {
                                            $taux_presence = ($presence_matiere['presents'] / $presence_matiere['total_sessions']) * 100;
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold"><?php echo safeHtml($cours['code']); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo safeHtml($cours['nom']); ?></div>
                                            <small class="text-muted"><?php echo safeHtml($cours['filiere_nom'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">S<?php echo $cours['semestre_numero'] ?? 'N/A'; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-coefficient <?php echo getCoefficientClass($cours['coefficient']); ?>">
                                                <?php echo $cours['coefficient']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo $cours['volume_horaire']; ?>h
                                            </span>
                                        </td>
                                        <td>
                                            <?php if(!empty($cours['enseignant_nom'])): ?>
                                            <div>
                                                <i class="fas fa-chalkboard-teacher text-primary me-1"></i>
                                                <?php echo safeHtml($cours['enseignant_nom']); ?>
                                            </div>
                                            <small class="text-muted"><?php echo safeHtml($cours['enseignant_grade'] ?? ''); ?></small>
                                            <?php else: ?>
                                            <span class="text-muted">À assigner</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($moyenne !== null): ?>
                                            <div class="d-flex align-items-center">
                                                <span class="note-badge <?php echo $note_class; ?> me-2">
                                                    <?php echo number_format($moyenne, 2); ?>
                                                </span>
                                                <small class="text-muted">
                                                    (<?php echo $note_matiere['nombre_notes'] ?? 0; ?>)
                                                </small>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($taux_presence !== null): ?>
                                            <div class="d-flex align-items-center">
                                                <div class="me-2" style="width: 60px;">
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar <?php echo $taux_presence >= 80 ? 'bg-success' : ($taux_presence >= 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo $taux_presence; ?>%">
                                                        </div>
                                                    </div>
                                                </div>
                                                <small><?php echo round($taux_presence, 0); ?>%</small>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="cours.php?onglet=detail&id=<?php echo $cours['id']; ?>" 
                                                   class="btn btn-outline-primary" title="Détails">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="notes.php?matiere=<?php echo $cours['id']; ?>" 
                                                   class="btn btn-outline-success" title="Notes">
                                                    <i class="fas fa-chart-line"></i>
                                                </a>
                                                <a href="presences.php?matiere=<?php echo $cours['id']; ?>" 
                                                   class="btn btn-outline-info" title="Présences">
                                                    <i class="fas fa-calendar-check"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Onglet 2: Détail du cours -->
                    <?php if($cours_id && $cours_detail): ?>
                    <div class="tab-pane fade <?php echo $onglet_actif == 'detail' ? 'show active' : ''; ?>" id="detail">
                        <!-- En-tête du cours -->
                        <div class="materie-header">
                            <div class="row">
                                <div class="col-md-8">
                                    <h1 class="materie-title"><?php echo safeHtml($cours_detail['nom']); ?></h1>
                                    <p class="materie-code mb-0">
                                        <i class="fas fa-hashtag"></i> <?php echo safeHtml($cours_detail['code']); ?> 
                                        | <i class="fas fa-graduation-cap"></i> <?php echo safeHtml($cours_detail['filiere_nom']); ?>
                                        | <i class="fas fa-calendar"></i> Semestre <?php echo $cours_detail['semestre_numero']; ?>
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <?php if($moyenne_cours && $moyenne_cours['moyenne']): ?>
                                    <div class="display-4 fw-bold">
                                        <?php echo number_format($moyenne_cours['moyenne'], 2); ?>/20
                                    </div>
                                    <small class="note-badge <?php echo getNoteClass($moyenne_cours['moyenne']); ?>">
                                        <?php echo $moyenne_cours['total_notes']; ?> notes
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Informations générales -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-label">Coefficient</div>
                                    <div class="info-value"><?php echo $cours_detail['coefficient']; ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-label">Crédits</div>
                                    <div class="info-value"><?php echo $cours_detail['credit']; ?> crédits</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-label">Volume Horaire</div>
                                    <div class="info-value"><?php echo $cours_detail['volume_horaire']; ?> heures</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Enseignant -->
                        <?php if(!empty($cours_detail['enseignant_nom'])): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i> Enseignant</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 text-center">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" 
                                             style="width: 80px; height: 80px; margin: 0 auto 15px; font-size: 2rem;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="col-md-9">
                                        <h5><?php echo safeHtml($cours_detail['enseignant_nom']); ?></h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1">
                                                    <i class="fas fa-id-card text-muted me-2"></i>
                                                    <strong>Matricule:</strong> <?php echo safeHtml($cours_detail['enseignant_matricule']); ?>
                                                </p>
                                                <p class="mb-1">
                                                    <i class="fas fa-award text-muted me-2"></i>
                                                    <strong>Grade:</strong> <?php echo safeHtml($cours_detail['enseignant_grade']); ?>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1">
                                                    <i class="fas fa-envelope text-muted me-2"></i>
                                                    <strong>Email:</strong> <?php echo safeHtml($cours_detail['enseignant_email']); ?>
                                                </p>
                                                <p class="mb-1">
                                                    <i class="fas fa-phone text-muted me-2"></i>
                                                    <strong>Téléphone:</strong> <?php echo safeHtml($cours_detail['enseignant_telephone']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <?php if(!empty($cours_detail['enseignant_specialite'])): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-info"><?php echo safeHtml($cours_detail['enseignant_specialite']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Notes -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i> Mes Notes</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if(empty($notes_cours)): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Aucune note disponible pour ce cours.
                                        </div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Type</th>
                                                        <th>Note</th>
                                                        <th>Coeff</th>
                                                        <th>Date</th>
                                                        <th>Évaluateur</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($notes_cours as $note): ?>
                                                    <tr>
                                                        <td><?php echo safeHtml($note['type_examen']); ?></td>
                                                        <td>
                                                            <span class="note-badge <?php echo getNoteClass($note['note']); ?>">
                                                                <?php echo number_format($note['note'], 2); ?>/20
                                                            </span>
                                                        </td>
                                                        <td><?php echo $note['coefficient_note']; ?></td>
                                                        <td><?php echo formatDateFr($note['date_evaluation']); ?></td>
                                                        <td><?php echo safeHtml($note['evaluateur_nom'] ?? 'N/A'); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Présences -->
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i> Mes Présences</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if(empty($presence_cours)): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Aucune donnée de présence pour ce cours.
                                        </div>
                                        <?php else: ?>
                                        <?php if($stats_presence): ?>
                                        <div class="row mb-3">
                                            <div class="col-md-3 text-center">
                                                <div class="display-6 fw-bold text-success">
                                                    <?php echo $stats_presence['presents']; ?>
                                                </div>
                                                <small>Présents</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <div class="display-6 fw-bold text-danger">
                                                    <?php echo $stats_presence['absents']; ?>
                                                </div>
                                                <small>Absents</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <div class="display-6 fw-bold text-warning">
                                                    <?php echo $stats_presence['retards']; ?>
                                                </div>
                                                <small>Retards</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <div class="display-6 fw-bold text-info">
                                                    <?php echo $stats_presence['justifie']; ?>
                                                </div>
                                                <small>Justifiés</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Statut</th>
                                                        <th>Surveillant</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($presence_cours as $presence): ?>
                                                    <tr>
                                                        <td><?php echo formatDateFr($presence['date_heure'], 'd/m H:i'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $presence['statut'] == 'present' ? 'success' : ($presence['statut'] == 'absent' ? 'danger' : 'warning'); ?>">
                                                                <?php echo ucfirst($presence['statut']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo safeHtml($presence['surveillant_nom'] ?? 'N/A'); ?></td>
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
                        
                        <!-- Semestre -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-calendar me-2"></i> Période du Semestre</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p>
                                            <i class="fas fa-play-circle text-success me-2"></i>
                                            <strong>Début:</strong> <?php echo formatDateFr($cours_detail['semestre_debut']); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p>
                                            <i class="fas fa-stop-circle text-danger me-2"></i>
                                            <strong>Fin:</strong> <?php echo formatDateFr($cours_detail['semestre_fin']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php 
                                $jours_restants = 0;
                                if (!empty($cours_detail['semestre_fin'])) {
                                    $fin = strtotime($cours_detail['semestre_fin']);
                                    $maintenant = time();
                                    $jours_restants = ceil(($fin - $maintenant) / (60 * 60 * 24));
                                }
                                ?>
                                <?php if($jours_restants > 0): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-clock"></i> 
                                    Il reste <strong><?php echo $jours_restants; ?> jours</strong> jusqu'à la fin du semestre.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Onglet 3: Statistiques -->
                    <div class="tab-pane fade <?php echo $onglet_actif == 'stats' ? 'show active' : ''; ?>" id="stats">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i> Répartition par coefficient</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="coefficientChart" height="250"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Volume horaire par cours</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="volumeChart" height="250"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Statistiques détaillées -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-percentage me-2"></i> Taux de présence global</h5>
                                    </div>
                                    <div class="card-body text-center">
                                        <?php 
                                        $total_presence = 0;
                                        $total_sessions = 0;
                                        foreach($presence_par_matiere as $presence) {
                                            $total_presence += $presence['presents'];
                                            $total_sessions += $presence['total_sessions'];
                                        }
                                        $taux_global = $total_sessions > 0 ? ($total_presence / $total_sessions) * 100 : 0;
                                        ?>
                                        <div class="display-3 fw-bold <?php echo $taux_global >= 80 ? 'text-success' : ($taux_global >= 60 ? 'text-warning' : 'text-danger'); ?>">
                                            <?php echo round($taux_global, 1); ?>%
                                        </div>
                                        <p class="text-muted"><?php echo $total_sessions; ?> sessions</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-star me-2"></i> Moyenne générale</h5>
                                    </div>
                                    <div class="card-body text-center">
                                        <?php 
                                        $total_notes = 0;
                                        $somme_notes = 0;
                                        foreach($notes_par_matiere as $note) {
                                            $somme_notes += $note['moyenne_matiere'] * ($note['nombre_notes'] ?? 1);
                                            $total_notes += ($note['nombre_notes'] ?? 1);
                                        }
                                        $moyenne_globale = $total_notes > 0 ? $somme_notes / $total_notes : 0;
                                        ?>
                                        <div class="display-3 fw-bold <?php echo getNoteClass($moyenne_globale); ?>">
                                            <?php echo number_format($moyenne_globale, 2); ?>
                                        </div>
                                        <p class="text-muted">sur <?php echo $total_notes; ?> notes</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i> Charge de travail</h5>
                                    </div>
                                    <div class="card-body text-center">
                                        <div class="display-3 fw-bold text-info">
                                            <?php echo array_sum(array_column($cours_actifs, 'volume_horaire')); ?>h
                                        </div>
                                        <p class="text-muted">par semestre</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
    // Initialiser DataTable
    $(document).ready(function() {
        $('#coursTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json"
            },
            "pageLength": 10,
            "order": [[0, 'asc']]
        });
        
        // Graphique des coefficients
        const coeffCtx = document.getElementById('coefficientChart');
        if (coeffCtx) {
            new Chart(coeffCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: [
                        'Coeff 1-2',
                        'Coeff 3-4', 
                        'Coeff 5+'
                    ],
                    datasets: [{
                        data: [
                            <?php echo count(array_filter($cours_actifs, fn($c) => $c['coefficient'] <= 2)); ?>,
                            <?php echo count(array_filter($cours_actifs, fn($c) => $c['coefficient'] > 2 && $c['coefficient'] <= 4)); ?>,
                            <?php echo count(array_filter($cours_actifs, fn($c) => $c['coefficient'] > 4)); ?>
                        ],
                        backgroundColor: [
                            '#3498db',
                            '#2ecc71',
                            '#e74c3c'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.raw + ' cours';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique des volumes horaires
        const volumeCtx = document.getElementById('volumeChart');
        if (volumeCtx) {
            const coursLabels = <?php echo json_encode(array_map(fn($c) => substr($c['nom'], 0, 15) . '...', $cours_actifs)); ?>;
            const coursVolumes = <?php echo json_encode(array_column($cours_actifs, 'volume_horaire')); ?>;
            
            new Chart(volumeCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: coursLabels,
                    datasets: [{
                        label: 'Heures',
                        data: coursVolumes,
                        backgroundColor: '#3498db',
                        borderColor: '#2980b9',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Heures'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    });
    
    // Exporter en Excel
    function exportToExcel() {
        const table = document.getElementById('coursTable');
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Cours Actifs");
        
        // Nom du fichier
        const fileName = `cours_actifs_<?php echo safeHtml($info_etudiant['matricule'] ?? 'etudiant'); ?>_<?php echo date('Y-m-d'); ?>.xlsx`;
        
        XLSX.writeFile(wb, fileName);
    }
    </script>
</body>
</html>