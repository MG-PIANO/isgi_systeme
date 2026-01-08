<?php
// dashboard/etudiant/annonces.php

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
    $pageTitle = "Annonces & Communications";
    
    // Fonctions utilitaires
    function formatDateFr($date, $format = 'd/m/Y à H:i') {
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
    
    function getTypeIcon($type) {
        switch ($type) {
            case 'urgence':
                return '<i class="fas fa-exclamation-triangle text-danger"></i>';
            case 'annonce':
                return '<i class="fas fa-bullhorn text-primary"></i>';
            case 'academique':
                return '<i class="fas fa-graduation-cap text-info"></i>';
            case 'examen':
                return '<i class="fas fa-clipboard-list text-warning"></i>';
            case 'finance':
                return '<i class="fas fa-money-bill-wave text-success"></i>';
            default:
                return '<i class="fas fa-info-circle text-secondary"></i>';
        }
    }
    
    function getTypeBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'urgence':
                return '<span class="badge bg-danger"><i class="fas fa-exclamation-circle"></i> Urgence</span>';
            case 'annonce':
                return '<span class="badge bg-primary"><i class="fas fa-bullhorn"></i> Annonce</span>';
            case 'academique':
                return '<span class="badge bg-info"><i class="fas fa-graduation-cap"></i> Académique</span>';
            case 'examen':
                return '<span class="badge bg-warning"><i class="fas fa-clipboard-list"></i> Examen</span>';
            case 'finance':
                return '<span class="badge bg-success"><i class="fas fa-money-bill-wave"></i> Finance</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
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
    
    // Récupérer l'ID de l'étudiant et le site
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    $site_id = SessionManager::getSiteId();
    
    // Initialiser les variables
    $annonces = [];
    $annonces_urgentes = [];
    $calendrier_events = [];
    $reunions = [];
    $categories = [];
    $error = null;
    
    // Récupérer les informations de l'étudiant pour les filtres
    $info_etudiant = [];
    if ($etudiant_id) {
        $query = "SELECT e.*, s.nom as site_nom, c.id as classe_id, 
                         c.nom as classe_nom, f.id as filiere_id, 
                         f.nom as filiere_nom, n.id as niveau_id, 
                         n.libelle as niveau_libelle
                  FROM etudiants e
                  JOIN sites s ON e.site_id = s.id
                  LEFT JOIN classes c ON e.classe_id = c.id
                  LEFT JOIN filieres f ON c.filiere_id = f.id
                  LEFT JOIN niveaux n ON c.niveau_id = n.id
                  WHERE e.id = ?";
        
        try {
            $stmt = $db->prepare($query);
            $stmt->execute([$etudiant_id]);
            $info_etudiant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Erreur récupération infos étudiant: " . $e->getMessage());
        }
    }
    
    // Traitement des filtres
    $filter_type = $_GET['type'] ?? 'all';
    $filter_categorie = $_GET['categorie'] ?? 'all';
    $filter_date = $_GET['date'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    // Construire la requête des annonces avec une approche plus simple
    $query_params = [];
    $query_conditions = [];
    
    // Base de la requête - version simplifiée sans colonne calculée dans le ORDER BY
    $annonces_query = "SELECT * FROM (
        -- Annonces globales (notifications)
        SELECT 
            'notification' as source_table,
            id,
            'global' as scope,
            titre,
            message,
            type,
            CASE 
                WHEN type = 'urgence' THEN 'haute'
                WHEN type IN ('error', 'warning') THEN 'moyenne'
                ELSE 'basse'
            END as priorite,
            'notification' as categorie,
            date_notification as date_publication,
            NULL as date_expiration,
            NULL as auteur_id,
            NULL as site_id,
            NULL as filiere_id,
            NULL as niveau_id,
            NULL as classe_id,
            NULL as piece_jointe,
            CASE 
                WHEN type = 'urgence' THEN 3
                WHEN type IN ('error', 'warning') THEN 2
                ELSE 1
            END as priorite_order
        FROM notifications
        WHERE type IN ('info', 'warning', 'success', 'error', 'paiement', 'note', 'presence', 'annonce')
        AND date_notification >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        
        UNION ALL
        
        -- Annonces du calendrier académique
        SELECT 
            'calendrier' as source_table,
            id,
            'site' as scope,
            CONCAT('Calendrier - Semestre ', semestre, ' (', type_rentree, ')') as titre,
            CONCAT(
                COALESCE(observations, 'Calendrier académique publié'), 
                '\\n\\nDates importantes:\\n',
                '- Cours: ', DATE_FORMAT(date_debut_cours, '%d/%m/%Y'), ' au ', DATE_FORMAT(date_fin_cours, '%d/%m/%Y'), 
                '\\n- Examens: ', COALESCE(DATE_FORMAT(date_debut_examens, '%d/%m/%Y'), 'À définir'), 
                ' au ', COALESCE(DATE_FORMAT(date_fin_examens, '%d/%m/%Y'), 'À définir')
            ) as message,
            CASE 
                WHEN statut = 'planifie' THEN 'academique'
                WHEN statut = 'en_cours' THEN 'annonce'
                ELSE 'info'
            END as type,
            'moyenne' as priorite,
            'calendrier' as categorie,
            date_creation as date_publication,
            date_fin_cours as date_expiration,
            cree_par as auteur_id,
            site_id,
            NULL as filiere_id,
            NULL as niveau_id,
            NULL as classe_id,
            NULL as piece_jointe,
            2 as priorite_order  -- moyenne priorité
        FROM calendrier_academique
        WHERE publie = 1
        AND date_creation >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        
        UNION ALL
        
        -- Examens programmés
        SELECT 
            'examen' as source_table,
            ce.id,
            'classe' as scope,
            CONCAT('Examen: ', m.nom) as titre,
            CONCAT(
                'Type: ', te.nom, 
                '\\nDate: ', DATE_FORMAT(ce.date_examen, '%d/%m/%Y'),
                '\\nHeure: ', TIME_FORMAT(ce.heure_debut, '%H:%i'), ' - ', TIME_FORMAT(ce.heure_fin, '%H:%i'),
                '\\nSalle: ', COALESCE(ce.salle, 'À définir'),
                '\\n\\n', COALESCE(ce.consignes, '')
            ) as message,
            'examen' as type,
            CASE 
                WHEN DATEDIFF(ce.date_examen, CURDATE()) <= 3 THEN 'haute'
                ELSE 'moyenne'
            END as priorite,
            'examen' as categorie,
            ce.date_creation as date_publication,
            ce.date_examen as date_expiration,
            ce.cree_par as auteur_id,
            s.id as site_id,
            c.filiere_id,
            c.niveau_id,
            ce.classe_id,
            ce.sujet_examen as piece_jointe,
            CASE 
                WHEN DATEDIFF(ce.date_examen, CURDATE()) <= 3 THEN 3
                ELSE 2
            END as priorite_order
        FROM calendrier_examens ce
        JOIN matieres m ON ce.matiere_id = m.id
        JOIN types_examens te ON ce.type_examen_id = te.id
        JOIN classes c ON ce.classe_id = c.id
        JOIN sites s ON c.site_id = s.id
        WHERE ce.statut = 'planifie'
        AND ce.date_examen >= CURDATE()
        AND ce.publie_etudiants = 1
        AND ce.date_creation >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        
        UNION ALL
        
        -- Réunions
        SELECT 
            'reunion' as source_table,
            r.id,
            CASE 
                WHEN r.type_reunion = 'parent' THEN 'classe'
                ELSE 'site'
            END as scope,
            r.titre,
            CONCAT(
                'Type: ', r.type_reunion,
                '\\n\\n', COALESCE(r.description, ''),
                '\\n\\nLieu: ', COALESCE(r.lieu, 'À définir')
            ) as message,
            CASE 
                WHEN r.type_reunion = 'urgence' THEN 'urgence'
                ELSE 'annonce'
            END as type,
            CASE 
                WHEN r.type_reunion = 'urgence' THEN 'haute'
                ELSE 'moyenne'
            END as priorite,
            'reunion' as categorie,
            r.date_creation as date_publication,
            r.date_reunion as date_expiration,
            r.organisateur_id as auteur_id,
            r.site_id,
            NULL as filiere_id,
            NULL as niveau_id,
            NULL as classe_id,
            NULL as piece_jointe,
            CASE 
                WHEN r.type_reunion = 'urgence' THEN 3
                ELSE 2
            END as priorite_order
        FROM reunions r
        WHERE r.statut = 'planifiee'
        AND r.date_reunion >= CURDATE()
        AND r.date_creation >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ) a
    LEFT JOIN utilisateurs u ON a.auteur_id = u.id
    LEFT JOIN sites s ON a.site_id = s.id
    WHERE 1=1";
    
    // Filtres
    if ($filter_type != 'all') {
        $query_conditions[] = "a.type = ?";
        $query_params[] = $filter_type;
    }
    
    if ($filter_categorie != 'all') {
        $query_conditions[] = "a.categorie = ?";
        $query_params[] = $filter_categorie;
    }
    
    if ($filter_date != 'all') {
        switch ($filter_date) {
            case 'today':
                $query_conditions[] = "DATE(a.date_publication) = CURDATE()";
                break;
            case 'week':
                $query_conditions[] = "a.date_publication >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $query_conditions[] = "a.date_publication >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
        }
    }
    
    if (!empty($search)) {
        $query_conditions[] = "(a.titre LIKE ? OR a.message LIKE ?)";
        $query_params[] = "%$search%";
        $query_params[] = "%$search%";
    }
    
    // Filtre par site de l'étudiant
    if ($site_id) {
        $query_conditions[] = "(a.scope = 'global' OR a.site_id = ?)";
        $query_params[] = $site_id;
    }
    
    // Filtre par classe/filière si l'étudiant est dans une classe
    if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
        $class_condition = "(a.scope IN ('global', 'site') OR 
                            (a.scope = 'classe' AND a.classe_id = ?) OR
                            (a.scope = 'classe' AND a.filiere_id = ?) OR
                            (a.scope = 'classe' AND a.niveau_id = ?))";
        $query_conditions[] = $class_condition;
        $query_params[] = $info_etudiant['classe_id'];
        $query_params[] = $info_etudiant['filiere_id'] ?? null;
        $query_params[] = $info_etudiant['niveau_id'] ?? null;
    } else {
        $query_conditions[] = "a.scope IN ('global', 'site')";
    }
    
    // Ajouter les conditions
    if (!empty($query_conditions)) {
        $annonces_query .= " AND " . implode(" AND ", $query_conditions);
    }
    
    // Ordre de tri - utiliser la colonne priorite_order qui est maintenant définie dans chaque SELECT
    $annonces_query .= " ORDER BY a.priorite_order DESC, a.date_publication DESC";
    
    try {
        $stmt = $db->prepare($annonces_query);
        $stmt->execute($query_params);
        $annonces = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Séparer les annonces urgentes
        $annonces_urgentes = array_filter($annonces, function($annonce) {
            return ($annonce['priorite'] ?? '') == 'haute';
        });
        
    } catch (Exception $e) {
        $error = "Erreur lors de la récupération des annonces: " . $e->getMessage();
        error_log("Erreur SQL: " . $e->getMessage());
        error_log("Requête: " . $annonces_query);
    }
    
    // Récupérer les catégories distinctes pour le filtre (version simplifiée)
    $categories = ['notification', 'calendrier', 'examen', 'reunion'];
    
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Annonces */
    .annonce-item {
        border-left: 4px solid var(--primary-color);
        margin-bottom: 20px;
        padding: 15px;
        background: var(--card-bg);
        border-radius: 5px;
        border: 1px solid var(--border-color);
    }
    
    .annonce-urgence {
        border-left-color: var(--accent-color);
        background-color: rgba(231, 76, 60, 0.05);
    }
    
    .annonce-important {
        border-left-color: var(--warning-color);
        background-color: rgba(243, 156, 18, 0.05);
    }
    
    .annonce-info {
        border-left-color: var(--info-color);
        background-color: rgba(23, 162, 184, 0.05);
    }
    
    .annonce-date {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    .annonce-auteur {
        font-size: 0.9rem;
        color: var(--text-muted);
    }
    
    .annonce-message {
        white-space: pre-line;
        line-height: 1.6;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
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
    
    /* Alertes urgentes */
    .urgent-alert {
        animation: pulse 2s infinite;
        border: 2px solid var(--accent-color);
    }
    
    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(231, 76, 60, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
        }
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
                    <a href="annonces.php" class="nav-link active">
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
                            <i class="fas fa-bullhorn me-2"></i>
                            Annonces & Communications
                        </h2>
                        <p class="text-muted mb-0">
                            Consultez toutes les annonces importantes de l'administration
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> Retour Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Filtres de recherche -->
            <div class="filter-card">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Rechercher</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="search" name="search" 
                                   placeholder="Mot-clé, titre..." value="<?php echo safeHtml($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="type" class="form-label">Type d'annonce</label>
                        <select class="form-select" id="type" name="type" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Tous les types</option>
                            <option value="urgence" <?php echo $filter_type == 'urgence' ? 'selected' : ''; ?>>Urgence</option>
                            <option value="annonce" <?php echo $filter_type == 'annonce' ? 'selected' : ''; ?>>Annonce</option>
                            <option value="academique" <?php echo $filter_type == 'academique' ? 'selected' : ''; ?>>Académique</option>
                            <option value="examen" <?php echo $filter_type == 'examen' ? 'selected' : ''; ?>>Examen</option>
                            <option value="finance" <?php echo $filter_type == 'finance' ? 'selected' : ''; ?>>Finance</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="categorie" class="form-label">Catégorie</label>
                        <select class="form-select" id="categorie" name="categorie" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_categorie == 'all' ? 'selected' : ''; ?>>Toutes catégories</option>
                            <?php foreach($categories as $categorie): ?>
                            <option value="<?php echo safeHtml($categorie); ?>" 
                                    <?php echo $filter_categorie == $categorie ? 'selected' : ''; ?>>
                                <?php echo ucfirst(safeHtml($categorie)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="date" class="form-label">Période</label>
                        <select class="form-select" id="date" name="date" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_date == 'all' ? 'selected' : ''; ?>>Toute période</option>
                            <option value="today" <?php echo $filter_date == 'today' ? 'selected' : ''; ?>>Aujourd'hui</option>
                            <option value="week" <?php echo $filter_date == 'week' ? 'selected' : ''; ?>>Cette semaine</option>
                            <option value="month" <?php echo $filter_date == 'month' ? 'selected' : ''; ?>>Ce mois</option>
                        </select>
                    </div>
                </form>
                
                <div class="mt-3">
                    <a href="annonces.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-times"></i> Réinitialiser les filtres
                    </a>
                    <span class="ms-3 text-muted">
                        <?php echo count($annonces); ?> annonce(s) trouvée(s)
                    </span>
                </div>
            </div>
            
            <!-- Section des annonces urgentes -->
            <?php if(!empty($annonces_urgentes)): ?>
            <div class="card mb-4 border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Annonces Urgentes
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach($annonces_urgentes as $annonce): ?>
                    <div class="annonce-item urgent-alert">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="mb-1">
                                    <?php echo getTypeIcon($annonce['type'] ?? ''); ?>
                                    <?php echo safeHtml($annonce['titre'] ?? ''); ?>
                                </h5>
                                <div class="annonce-date">
                                    <i class="far fa-clock"></i> 
                                    <?php echo formatDateFr($annonce['date_publication'] ?? ''); ?>
                                    <?php if(!empty($annonce['date_expiration'])): ?>
                                     • Valable jusqu'au <?php echo formatDateFr($annonce['date_expiration'], 'd/m/Y'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <?php echo getTypeBadge($annonce['type'] ?? ''); ?>
                                <?php if(!empty($annonce['priorite']) && $annonce['priorite'] == 'haute'): ?>
                                <span class="badge bg-danger ms-1"><i class="fas fa-exclamation"></i> Urgent</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="annonce-auteur mb-3">
                            <i class="fas fa-user"></i> 
                            <?php if(!empty($annonce['auteur_nom'])): ?>
                            Publié par: <?php echo safeHtml($annonce['auteur_nom'] . ' ' . $annonce['auteur_prenom']); ?>
                            <?php else: ?>
                            Administration ISGI
                            <?php endif; ?>
                            
                            <?php if(!empty($annonce['site_nom'])): ?>
                            • <i class="fas fa-school"></i> Site: <?php echo safeHtml($annonce['site_nom']); ?>
                            <?php endif; ?>
                            
                            <?php if(!empty($annonce['scope']) && $annonce['scope'] != 'global'): ?>
                            • <span class="badge bg-info"><?php echo ucfirst(safeHtml($annonce['scope'])); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="annonce-message mb-3">
                            <?php echo nl2br(safeHtml($annonce['message'] ?? '')); ?>
                        </div>
                        
                        <?php if(!empty($annonce['piece_jointe'])): ?>
                        <div class="mt-3">
                            <a href="<?php echo safeHtml($annonce['piece_jointe']); ?>" 
                               class="btn btn-sm btn-outline-primary" target="_blank">
                                <i class="fas fa-paperclip"></i> Pièce jointe
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Toutes les annonces -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-newspaper me-2"></i>
                        Toutes les Annonces
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($annonces)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-newspaper fa-4x text-muted mb-3"></i>
                        <h4>Aucune annonce disponible</h4>
                        <p class="text-muted">
                            Aucune annonce ne correspond à vos critères de recherche.
                            <?php if($filter_type != 'all' || $filter_categorie != 'all' || $filter_date != 'all' || !empty($search)): ?>
                            <br>
                            <a href="annonces.php" class="btn btn-sm btn-primary mt-2">
                                Voir toutes les annonces
                            </a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach($annonces as $annonce): 
                            // Déterminer la classe CSS selon le type
                            $annonce_class = 'annonce-item';
                            switch($annonce['type'] ?? '') {
                                case 'urgence':
                                    $annonce_class .= ' annonce-urgence';
                                    break;
                                case 'examen':
                                case 'finance':
                                    $annonce_class .= ' annonce-important';
                                    break;
                                default:
                                    $annonce_class .= ' annonce-info';
                            }
                        ?>
                        <div class="col-md-6 mb-4">
                            <div class="<?php echo $annonce_class; ?> h-100">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="mb-1">
                                            <?php echo getTypeIcon($annonce['type'] ?? ''); ?>
                                            <?php echo safeHtml($annonce['titre'] ?? ''); ?>
                                        </h5>
                                        <div class="annonce-date">
                                            <i class="far fa-clock"></i> 
                                            <?php echo formatDateFr($annonce['date_publication'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php echo getTypeBadge($annonce['type'] ?? ''); ?>
                                        <?php if(!empty($annonce['priorite']) && $annonce['priorite'] == 'haute'): ?>
                                        <span class="badge bg-danger ms-1"><i class="fas fa-exclamation"></i> Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="annonce-auteur mb-3">
                                    <i class="fas fa-user"></i> 
                                    <?php if(!empty($annonce['auteur_nom'])): ?>
                                    Publié par: <?php echo safeHtml($annonce['auteur_nom'] . ' ' . $annonce['auteur_prenom']); ?>
                                    <?php else: ?>
                                    Administration ISGI
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($annonce['site_nom'])): ?>
                                    • <i class="fas fa-school"></i> Site: <?php echo safeHtml($annonce['site_nom']); ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="annonce-message mb-3">
                                    <?php 
                                    $message = safeHtml($annonce['message'] ?? '');
                                    if (strlen($message) > 200) {
                                        echo nl2br(substr($message, 0, 200) . '...');
                                        echo '<br><button class="btn btn-sm btn-link p-0 mt-1" onclick="toggleMessage(this)">Voir plus</button>';
                                        echo '<div class="full-message" style="display: none;">' . nl2br($message) . '</div>';
                                    } else {
                                        echo nl2br($message);
                                    }
                                    ?>
                                </div>
                                
                                <?php if(!empty($annonce['date_expiration'])): ?>
                                <div class="alert alert-warning py-2 mb-3">
                                    <small>
                                        <i class="fas fa-calendar-alt"></i> 
                                        <strong>Date limite:</strong> 
                                        <?php echo formatDateFr($annonce['date_expiration']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(!empty($annonce['piece_jointe'])): ?>
                                <div class="mt-3">
                                    <a href="<?php echo safeHtml($annonce['piece_jointe']); ?>" 
                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="fas fa-paperclip"></i> Pièce jointe
                                    </a>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-3 text-end">
                                    <small class="text-muted">
                                        Catégorie: 
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(safeHtml($annonce['categorie'] ?? 'générale')); ?>
                                        </span>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination (simplifiée) -->
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="text-muted">
                            Affichage de <?php echo count($annonces); ?> annonce(s)
                        </div>
                        <nav aria-label="Navigation des annonces">
                            <ul class="pagination mb-0">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" tabindex="-1">Précédent</a>
                                </li>
                                <li class="page-item active">
                                    <a class="page-link" href="#">1</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="#">2</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="#">3</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="#">Suivant</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Section info -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                À propos des annonces
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-bell"></i> Types d'annonces</h6>
                                <ul class="mb-0">
                                    <li><span class="badge bg-danger">Urgence</span> - Information critique nécessitant une action immédiate</li>
                                    <li><span class="badge bg-warning">Examen</span> - Informations relatives aux examens et évaluations</li>
                                    <li><span class="badge bg-info">Académique</span> - Actualités académiques et pédagogiques</li>
                                    <li><span class="badge bg-success">Finance</span> - Informations financières et paiements</li>
                                    <li><span class="badge bg-primary">Annonce</span> - Communications générales</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-question-circle me-2"></i>
                                Questions fréquentes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="accordion" id="faqAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                            Comment être notifié des nouvelles annonces?
                                        </button>
                                    </h2>
                                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Les annonces importantes apparaissent sur votre tableau de bord. 
                                            Consultez régulièrement cette page pour les mises à jour.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                            Que faire si je ne reçois pas certaines annonces?
                                        </button>
                                    </h2>
                                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Contactez le service académique de votre site si vous pensez manquer 
                                            des informations importantes concernant votre filière ou classe.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                            Les annonces sont-elles conservées?
                                        </button>
                                    </h2>
                                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Oui, les annonces sont conservées pendant 6 mois. 
                                            Vous pouvez utiliser les filtres pour retrouver d'anciennes annonces.
                                        </div>
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
    
    // Fonction pour afficher/masquer le message complet
    function toggleMessage(button) {
        const messageDiv = button.nextElementSibling;
        if (messageDiv.style.display === 'none') {
            messageDiv.style.display = 'block';
            button.textContent = 'Voir moins';
        } else {
            messageDiv.style.display = 'none';
            button.textContent = 'Voir plus';
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
        
        // Initialiser les accordions Bootstrap
        const accordionEls = document.querySelectorAll('.accordion-button');
        accordionEls.forEach(el => {
            el.addEventListener('click', function() {
                const target = document.querySelector(this.getAttribute('data-bs-target'));
                if (target) {
                    new bootstrap.Collapse(target);
                }
            });
        });
    });
    
    // Fonction pour marquer une annonce comme lue
    function markAsRead(annonceId) {
        // Ici, vous pourriez envoyer une requête AJAX au serveur
        // pour marquer l'annonce comme lue dans la base de données
        console.log('Annonce marquée comme lue:', annonceId);
    }
    </script>
</body>
</html>