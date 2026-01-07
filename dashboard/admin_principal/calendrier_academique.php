<?php
// dashboard/admin_principal/calendrier_academique.php

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

// Vérifier le rôle (Administrateur Principal)
if ($_SESSION['role_id'] != 1) {
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
    $pageTitle = "Gestion du Calendrier Académique";
    
    // Fonctions utilitaires
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'planifie':
                return '<span class="badge bg-warning">Planifié</span>';
            case 'en_cours':
                return '<span class="badge bg-info">En cours</span>';
            case 'termine':
                return '<span class="badge bg-success">Terminé</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Initialiser les variables
    $calendriers = [];
    $sites = [];
    $all_annees = [];
    $annees_by_site = [];
    $filters = [];
    $error = null;
    $success = null;
    $calendrier_details = null;
    
    // Récupérer les données de filtrage
    $filters['site_id'] = isset($_GET['site_id']) ? intval($_GET['site_id']) : null;
    $filters['annee_id'] = isset($_GET['annee_id']) ? intval($_GET['annee_id']) : null;
    $filters['semestre'] = isset($_GET['semestre']) ? $_GET['semestre'] : '';
    $filters['type_rentree'] = isset($_GET['type_rentree']) ? $_GET['type_rentree'] : '';
    $filters['statut'] = isset($_GET['statut']) ? $_GET['statut'] : '';
    
    // Construire la chaîne de paramètres pour les URLs
    $filter_params = http_build_query($filters);
    
    // Traitement des actions GET
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($action && $id > 0) {
        try {
            switch ($action) {
                case 'view':
                    // Voir les détails d'un calendrier
                    $stmt = $db->prepare("SELECT 
                        ca.*,
                        s.nom as site_nom,
                        s.ville as site_ville,
                        aa.libelle as annee_libelle
                    FROM calendrier_academique ca
                    LEFT JOIN sites s ON ca.site_id = s.id
                    LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                    WHERE ca.id = ?");
                    $stmt->execute([$id]);
                    $calendrier_details = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;
                    
                case 'publish':
                    // Publier le calendrier
                    $stmt = $db->prepare("UPDATE calendrier_academique 
                        SET publie = 1, 
                            modifie_par = ?,
                            date_modification = NOW()
                        WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $success = "Calendrier publié avec succès!";
                    // Rediriger pour éviter la resoumission
                    header("Location: calendrier_academique.php?$filter_params&success=" . urlencode($success));
                    exit();
                    break;
                    
                case 'unpublish':
                    // Dépublier le calendrier
                    $stmt = $db->prepare("UPDATE calendrier_academique 
                        SET publie = 0,
                            modifie_par = ?,
                            date_modification = NOW()
                        WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $success = "Calendrier dépublié avec succès!";
                    header("Location: calendrier_academique.php?$filter_params&success=" . urlencode($success));
                    exit();
                    break;
                    
                case 'delete':
                    // Supprimer un calendrier académique
                    $stmt = $db->prepare("DELETE FROM calendrier_academique WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Calendrier académique supprimé avec succès!";
                    header("Location: calendrier_academique.php?$filter_params&success=" . urlencode($success));
                    exit();
                    break;
            }
        } catch (Exception $e) {
            $error = "Erreur: " . $e->getMessage();
        }
    }
    
    // Vérifier si un message de succès est passé par URL
    if (isset($_GET['success'])) {
        $success = urldecode($_GET['success']);
    }
    
    // Actions CRUD POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        try {
            switch ($action) {
                case 'add':
                    // Validation des dates
                    $errors = [];
                    
                    // Vérifier que la date de début des cours est avant la date de fin
                    if (strtotime($_POST['date_debut_cours']) >= strtotime($_POST['date_fin_cours'])) {
                        $errors[] = "La date de début des cours doit être avant la date de fin.";
                    }
                    
                    // Validation des dates DST si renseignées
                    if (!empty($_POST['date_debut_dst']) && !empty($_POST['date_fin_dst'])) {
                        if (strtotime($_POST['date_debut_dst']) >= strtotime($_POST['date_fin_dst'])) {
                            $errors[] = "La date de début DST doit être avant la date de fin DST.";
                        }
                    }
                    
                    // Validation des dates de stage si renseignées
                    if (!empty($_POST['date_debut_stage']) && !empty($_POST['date_fin_stage'])) {
                        if (strtotime($_POST['date_debut_stage']) >= strtotime($_POST['date_fin_stage'])) {
                            $errors[] = "La date de début du stage doit être avant la date de fin.";
                        }
                        if (strtotime($_POST['date_debut_stage']) <= strtotime($_POST['date_fin_cours'])) {
                            $errors[] = "Le stage doit commencer après la fin des cours.";
                        }
                    }
                    
                    // S'il y a des erreurs, les afficher
                    if (!empty($errors)) {
                        $error = "Erreurs de validation:<br>" . implode("<br>", $errors);
                    } else {
                        // Ajouter un calendrier académique
                        $stmt = $db->prepare("INSERT INTO calendrier_academique 
                            (site_id, annee_academique_id, semestre, type_rentree,
                             date_debut_cours, date_fin_cours, date_debut_dst, date_fin_dst,
                             date_debut_recherche, date_fin_recherche, date_debut_conge_etude,
                             date_fin_conge_etude, date_debut_examens, date_fin_examens,
                             date_reprise_cours, date_debut_stage, date_fin_stage,
                             statut, observations, publie, cree_par, date_creation)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        
                        $stmt->execute([
                            $_POST['site_id'],
                            $_POST['annee_academique_id'],
                            $_POST['semestre'],
                            $_POST['type_rentree'],
                            $_POST['date_debut_cours'],
                            $_POST['date_fin_cours'],
                            !empty($_POST['date_debut_dst']) ? $_POST['date_debut_dst'] : null,
                            !empty($_POST['date_fin_dst']) ? $_POST['date_fin_dst'] : null,
                            !empty($_POST['date_debut_recherche']) ? $_POST['date_debut_recherche'] : null,
                            !empty($_POST['date_fin_recherche']) ? $_POST['date_fin_recherche'] : null,
                            !empty($_POST['date_debut_conge_etude']) ? $_POST['date_debut_conge_etude'] : null,
                            !empty($_POST['date_fin_conge_etude']) ? $_POST['date_fin_conge_etude'] : null,
                            !empty($_POST['date_debut_examens']) ? $_POST['date_debut_examens'] : null,
                            !empty($_POST['date_fin_examens']) ? $_POST['date_fin_examens'] : null,
                            !empty($_POST['date_reprise_cours']) ? $_POST['date_reprise_cours'] : null,
                            !empty($_POST['date_debut_stage']) ? $_POST['date_debut_stage'] : null,
                            !empty($_POST['date_fin_stage']) ? $_POST['date_fin_stage'] : null,
                            $_POST['statut'],
                            $_POST['observations'],
                            isset($_POST['publie']) ? 1 : 0,
                            $_SESSION['user_id']
                        ]);
                        
                        $success = "Calendrier académique ajouté avec succès!";
                        // Rediriger pour éviter la resoumission
                        header("Location: calendrier_academique.php?$filter_params&success=" . urlencode($success));
                        exit();
                    }
                    break;
                    
                case 'edit':
                    // Modifier un calendrier académique
                    $stmt = $db->prepare("UPDATE calendrier_academique SET
                        date_debut_cours = ?,
                        date_fin_cours = ?,
                        date_debut_dst = ?,
                        date_fin_dst = ?,
                        date_debut_recherche = ?,
                        date_fin_recherche = ?,
                        date_debut_conge_etude = ?,
                        date_fin_conge_etude = ?,
                        date_debut_examens = ?,
                        date_fin_examens = ?,
                        date_reprise_cours = ?,
                        date_debut_stage = ?,
                        date_fin_stage = ?,
                        statut = ?,
                        observations = ?,
                        publie = ?,
                        modifie_par = ?,
                        date_modification = NOW()
                        WHERE id = ?");
                    
                    $stmt->execute([
                        $_POST['date_debut_cours'],
                        $_POST['date_fin_cours'],
                        !empty($_POST['date_debut_dst']) ? $_POST['date_debut_dst'] : null,
                        !empty($_POST['date_fin_dst']) ? $_POST['date_fin_dst'] : null,
                        !empty($_POST['date_debut_recherche']) ? $_POST['date_debut_recherche'] : null,
                        !empty($_POST['date_fin_recherche']) ? $_POST['date_fin_recherche'] : null,
                        !empty($_POST['date_debut_conge_etude']) ? $_POST['date_debut_conge_etude'] : null,
                        !empty($_POST['date_fin_conge_etude']) ? $_POST['date_fin_conge_etude'] : null,
                        !empty($_POST['date_debut_examens']) ? $_POST['date_debut_examens'] : null,
                        !empty($_POST['date_fin_examens']) ? $_POST['date_fin_examens'] : null,
                        !empty($_POST['date_reprise_cours']) ? $_POST['date_reprise_cours'] : null,
                        !empty($_POST['date_debut_stage']) ? $_POST['date_debut_stage'] : null,
                        !empty($_POST['date_fin_stage']) ? $_POST['date_fin_stage'] : null,
                        $_POST['statut'],
                        $_POST['observations'],
                        isset($_POST['publie']) ? 1 : 0,
                        $_SESSION['user_id'],
                        $id
                    ]);
                    
                    $success = "Calendrier académique modifié avec succès!";
                    header("Location: calendrier_academique.php?$filter_params&success=" . urlencode($success));
                    exit();
                    break;
            }
        } catch (Exception $e) {
            $error = "Erreur: " . $e->getMessage();
        }
    }
    
    // Récupérer les données pour les listes déroulantes
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY nom")->fetchAll();
    
    // Récupérer TOUTES les années académiques
    $all_annees = $db->query("SELECT 
        aa.id, 
        aa.libelle, 
        aa.site_id,
        s.nom as site_nom
        FROM annees_academiques aa
        LEFT JOIN sites s ON aa.site_id = s.id
        WHERE aa.statut IN ('active', 'planifiee')
        ORDER BY s.nom, aa.libelle DESC")->fetchAll();
    
    // Grouper les années par site pour le formulaire
    foreach ($all_annees as $annee) {
        $annees_by_site[$annee['site_nom']][] = $annee;
    }
    
    // Construire la requête SQL avec filtres
    $sql = "SELECT ca.*, 
                   s.nom as site_nom, s.ville as site_ville,
                   aa.libelle as annee_libelle
            FROM calendrier_academique ca
            LEFT JOIN sites s ON ca.site_id = s.id
            LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
            WHERE 1=1";
    
    $params = [];
    
    if ($filters['site_id']) {
        $sql .= " AND ca.site_id = ?";
        $params[] = $filters['site_id'];
    }
    
    if ($filters['annee_id']) {
        $sql .= " AND ca.annee_academique_id = ?";
        $params[] = $filters['annee_id'];
    }
    
    if ($filters['semestre']) {
        $sql .= " AND ca.semestre = ?";
        $params[] = $filters['semestre'];
    }
    
    if ($filters['type_rentree']) {
        $sql .= " AND ca.type_rentree = ?";
        $params[] = $filters['type_rentree'];
    }
    
    if ($filters['statut']) {
        $sql .= " AND ca.statut = ?";
        $params[] = $filters['statut'];
    }
    
    $sql .= " ORDER BY ca.date_debut_cours DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $calendriers = $stmt->fetchAll();
    
    // Récupérer les années académiques pour le filtre sélectionné
    if ($filters['site_id']) {
        $annees_academiques = $db->prepare("SELECT * FROM annees_academiques 
                                          WHERE site_id = ? ORDER BY libelle DESC");
        $annees_academiques->execute([$filters['site_id']]);
        $annees_academiques = $annees_academiques->fetchAll();
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI Admin</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FullCalendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    
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
    
    /* Sidebar styles restaurés */
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
        width: calc(100% - 250px);
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
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 70px;
            overflow-x: hidden;
        }
        
        .sidebar-header, 
        .user-info, 
        .nav-section-title, 
        .nav-link span,
        .btn-outline-light span {
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
            width: calc(100% - 70px);
        }
    }
    
    /* En-têtes */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .content-header h2 {
        color: var(--text-color);
    }
    
    .content-header .text-muted {
        color: var(--text-muted);
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
    
    #calendar {
        background-color: var(--card-bg);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
    }
    
    .fc .fc-toolbar {
        color: var(--text-color);
    }
    
    .fc .fc-toolbar-title {
        color: var(--text-color);
    }
    
    .fc .fc-col-header-cell {
        background-color: var(--primary-color);
        color: white;
    }
    
    .fc .fc-daygrid-day {
        background-color: var(--card-bg);
        color: var(--text-color);
    }
    
    .fc .fc-daygrid-day.fc-day-today {
        background-color: rgba(52, 152, 219, 0.1);
    }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Inclure le sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Contenu Principal -->
        <div class="main-content">
            <!-- En-tête -->
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-calendar me-2"></i>
                            Calendrier Académique
                        </h2>
                        <p class="text-muted mb-0">Gestion des périodes académiques et des événements</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCalendrierModal">
                            <i class="fas fa-plus"></i> Nouveau Calendrier
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtres</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Site</label>
                            <select name="site_id" class="form-select" onchange="this.form.submit()">
                                <option value="">Tous les sites</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" 
                                    <?php echo $filters['site_id'] == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if($filters['site_id']): ?>
                        <div class="col-md-4">
                            <label class="form-label">Année Académique</label>
                            <select name="annee_id" class="form-select" onchange="this.form.submit()">
                                <option value="">Toutes les années</option>
                                <?php if(isset($annees_academiques)): ?>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"
                                    <?php echo $filters['annee_id'] == $annee['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label class="form-label">Semestre</label>
                            <select name="semestre" class="form-select">
                                <option value="">Tous</option>
                                <option value="1" <?php echo $filters['semestre'] == '1' ? 'selected' : ''; ?>>Semestre 1</option>
                                <option value="2" <?php echo $filters['semestre'] == '2' ? 'selected' : ''; ?>>Semestre 2</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Type de rentrée</label>
                            <select name="type_rentree" class="form-select">
                                <option value="">Tous</option>
                                <option value="Octobre" <?php echo $filters['type_rentree'] == 'Octobre' ? 'selected' : ''; ?>>Octobre</option>
                                <option value="Janvier" <?php echo $filters['type_rentree'] == 'Janvier' ? 'selected' : ''; ?>>Janvier</option>
                                <option value="Avril" <?php echo $filters['type_rentree'] == 'Avril' ? 'selected' : ''; ?>>Avril</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select">
                                <option value="">Tous</option>
                                <option value="planifie" <?php echo $filters['statut'] == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                <option value="en_cours" <?php echo $filters['statut'] == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                <option value="termine" <?php echo $filters['statut'] == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                <option value="annule" <?php echo $filters['statut'] == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Appliquer les filtres
                            </button>
                            <a href="calendrier_academique.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Réinitialiser
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Vue Liste -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="viewTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="list-tab" data-bs-toggle="tab" data-bs-target="#list-view">
                                <i class="fas fa-list me-2"></i>Vue Liste
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendar-view">
                                <i class="fas fa-calendar-alt me-2"></i>Vue Calendrier
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <!-- Vue Liste -->
                        <div class="tab-pane fade show active" id="list-view">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Site/Année</th>
                                            <th>Période</th>
                                            <th>Dates Cours</th>
                                            <th>Dates Examens</th>
                                            <th>Statut</th>
                                            <th>Publié</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($calendriers)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center">
                                                <div class="alert alert-info">
                                                    Aucun calendrier trouvé avec les filtres sélectionnés
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach($calendriers as $cal): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cal['site_nom']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($cal['annee_libelle']); ?></small>
                                            </td>
                                            <td>
                                                Semestre <?php echo $cal['semestre']; ?><br>
                                                <small><?php echo htmlspecialchars($cal['type_rentree']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo formatDateFr($cal['date_debut_cours']); ?> <br>
                                                <small>au</small> <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                            </td>
                                            <td>
                                                <?php if($cal['date_debut_examens'] && $cal['date_fin_examens']): ?>
                                                <?php echo formatDateFr($cal['date_debut_examens']); ?> <br>
                                                <small>au</small> <?php echo formatDateFr($cal['date_fin_examens']); ?>
                                                <?php else: ?>
                                                <span class="text-muted">Non défini</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatutBadge($cal['statut']); ?></td>
                                            <td>
                                                <?php if($cal['publie']): ?>
                                                <span class="badge bg-success">Oui</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning">Non</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <!-- Bouton Voir -->
                                                    <a href="?action=view&id=<?php echo $cal['id']; ?>&<?php echo $filter_params; ?>" 
                                                       class="btn btn-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <!-- Bouton Modifier -->
                                                    <button type="button" class="btn btn-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editModal<?php echo $cal['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <!-- Bouton Publier/Dépublier -->
                                                    <?php if(!$cal['publie']): ?>
                                                    <a href="?action=publish&id=<?php echo $cal['id']; ?>&<?php echo $filter_params; ?>" 
                                                       class="btn btn-success"
                                                       onclick="return confirm('Publier ce calendrier?')">
                                                        <i class="fas fa-share"></i>
                                                    </a>
                                                    <?php else: ?>
                                                    <a href="?action=unpublish&id=<?php echo $cal['id']; ?>&<?php echo $filter_params; ?>" 
                                                       class="btn btn-secondary"
                                                       onclick="return confirm('Dépublier ce calendrier?')">
                                                        <i class="fas fa-ban"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Bouton Supprimer -->
                                                    <a href="?action=delete&id=<?php echo $cal['id']; ?>&<?php echo $filter_params; ?>" 
                                                       class="btn btn-danger"
                                                       onclick="return confirm('Supprimer ce calendrier? Cette action est irréversible.')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Vue Calendrier -->
                        <div class="tab-pane fade" id="calendar-view">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary">
                                <i class="fas fa-calendar-check fa-2x"></i>
                            </div>
                            <h3 class="mt-2"><?php echo count($calendriers); ?></h3>
                            <p class="text-muted">Calendriers actifs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success">
                                <i class="fas fa-share-square fa-2x"></i>
                            </div>
                            <?php
                            $published = array_filter($calendriers, function($cal) {
                                return $cal['publie'] == 1;
                            });
                            ?>
                            <h3 class="mt-2"><?php echo count($published); ?></h3>
                            <p class="text-muted">Calendriers publiés</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-info">
                                <i class="fas fa-chart-line fa-2x"></i>
                            </div>
                            <?php
                            $current = array_filter($calendriers, function($cal) {
                                return $cal['statut'] == 'en_cours';
                            });
                            ?>
                            <h3 class="mt-2"><?php echo count($current); ?></h3>
                            <p class="text-muted">Périodes en cours</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Ajout Calendrier - FORMULAIRE COMPLET -->
    <div class="modal fade" id="addCalendrierModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Ajouter un Calendrier Académique</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Site *</label>
                                <select name="site_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach($sites as $site): ?>
                                    <option value="<?php echo $site['id']; ?>">
                                        <?php echo htmlspecialchars($site['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Année Académique *</label>
                                <select name="annee_academique_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php if(!empty($annees_by_site)): ?>
                                        <?php foreach($annees_by_site as $site_nom => $annees): ?>
                                        <optgroup label="<?php echo htmlspecialchars($site_nom); ?>">
                                            <?php foreach($annees as $annee): ?>
                                            <option value="<?php echo $annee['id']; ?>">
                                                <?php echo htmlspecialchars($annee['libelle']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">Aucune année académique disponible</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Semestre *</label>
                                <select name="semestre" class="form-select" required>
                                    <option value="1">Semestre 1</option>
                                    <option value="2">Semestre 2</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Type de rentrée *</label>
                                <select name="type_rentree" class="form-select" required>
                                    <option value="Octobre">Octobre</option>
                                    <option value="Janvier">Janvier</option>
                                    <option value="Avril">Avril</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début des cours *</label>
                                <input type="date" name="date_debut_cours" class="form-control" required 
                                       value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin des cours *</label>
                                <input type="date" name="date_fin_cours" class="form-control" required 
                                       value="<?php echo date('Y-m-d', strtotime('+5 months')); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début DST (optionnel)</label>
                                <input type="date" name="date_debut_dst" class="form-control">
                                <small class="text-muted">Doit être pendant la période de cours</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin DST (optionnel)</label>
                                <input type="date" name="date_fin_dst" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début Devoir Recherche (optionnel)</label>
                                <input type="date" name="date_debut_recherche" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin Devoir Recherche (optionnel)</label>
                                <input type="date" name="date_fin_recherche" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début congé d'étude (optionnel)</label>
                                <input type="date" name="date_debut_conge_etude" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin congé d'étude (optionnel)</label>
                                <input type="date" name="date_fin_conge_etude" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début examens (optionnel)</label>
                                <input type="date" name="date_debut_examens" class="form-control">
                                <small class="text-muted">Doit être après la fin des cours</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin examens (optionnel)</label>
                                <input type="date" name="date_fin_examens" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date reprise cours S2 (optionnel)</label>
                                <input type="date" name="date_reprise_cours" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début stage (optionnel)</label>
                                <input type="date" name="date_debut_stage" class="form-control">
                                <small class="text-muted">Doit être APRÈS la fin des cours</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin stage (optionnel)</label>
                                <input type="date" name="date_fin_stage" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Statut *</label>
                                <select name="statut" class="form-select" required>
                                    <option value="planifie">Planifié</option>
                                    <option value="en_cours">En cours</option>
                                    <option value="termine">Terminé</option>
                                    <option value="annule">Annulé</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="publie" class="form-check-input" id="publie">
                                    <label class="form-check-label" for="publie">
                                        Publier le calendrier (visible aux étudiants)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Observations</label>
                                <textarea name="observations" class="form-control" rows="3" 
                                          placeholder="Notes ou informations supplémentaires..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Voir Détails -->
    <?php if(isset($_GET['action']) && $_GET['action'] == 'view' && $calendrier_details): ?>
    <div class="modal fade show" id="viewModal" tabindex="-1" style="display: block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Détails du Calendrier</h5>
                    <a href="calendrier_academique.php?<?php echo $filter_params; ?>" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informations Générales</h6>
                            <p><strong>Site:</strong> <?php echo htmlspecialchars($calendrier_details['site_nom']); ?> 
                            <small>(<?php echo htmlspecialchars($calendrier_details['site_ville']); ?>)</small></p>
                            <p><strong>Année académique:</strong> <?php echo htmlspecialchars($calendrier_details['annee_libelle']); ?></p>
                            <p><strong>Semestre:</strong> <?php echo $calendrier_details['semestre']; ?></p>
                            <p><strong>Type de rentrée:</strong> <?php echo htmlspecialchars($calendrier_details['type_rentree']); ?></p>
                            <p><strong>Statut:</strong> <?php echo getStatutBadge($calendrier_details['statut']); ?></p>
                            <p><strong>Publié:</strong> 
                                <?php if($calendrier_details['publie']): ?>
                                <span class="badge bg-success">Oui</span>
                                <?php else: ?>
                                <span class="badge bg-warning">Non</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6>Dates Principales</h6>
                            <p><strong>Cours:</strong> <?php echo formatDateFr($calendrier_details['date_debut_cours']); ?> au <?php echo formatDateFr($calendrier_details['date_fin_cours']); ?></p>
                            <?php if($calendrier_details['date_debut_examens'] && $calendrier_details['date_fin_examens']): ?>
                            <p><strong>Examens:</strong> <?php echo formatDateFr($calendrier_details['date_debut_examens']); ?> au <?php echo formatDateFr($calendrier_details['date_fin_examens']); ?></p>
                            <?php endif; ?>
                            <?php if($calendrier_details['date_debut_stage'] && $calendrier_details['date_fin_stage']): ?>
                            <p><strong>Stage:</strong> <?php echo formatDateFr($calendrier_details['date_debut_stage']); ?> au <?php echo formatDateFr($calendrier_details['date_fin_stage']); ?></p>
                            <?php endif; ?>
                            <?php if($calendrier_details['date_debut_dst'] && $calendrier_details['date_fin_dst']): ?>
                            <p><strong>DST:</strong> <?php echo formatDateFr($calendrier_details['date_debut_dst']); ?> au <?php echo formatDateFr($calendrier_details['date_fin_dst']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if($calendrier_details['observations']): ?>
                    <div class="mt-3">
                        <h6>Observations</h6>
                        <div class="alert alert-info">
                            <?php echo nl2br(htmlspecialchars($calendrier_details['observations'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <h6>Toutes les Dates</h6>
                        <ul class="list-group">
                            <?php if($calendrier_details['date_debut_recherche'] && $calendrier_details['date_fin_recherche']): ?>
                            <li class="list-group-item">
                                <strong>Devoir de Recherche:</strong><br>
                                <span class="text-muted">
                                    <?php echo formatDateFr($calendrier_details['date_debut_recherche']); ?> 
                                    au <?php echo formatDateFr($calendrier_details['date_fin_recherche']); ?>
                                </span>
                            </li>
                            <?php endif; ?>
                            
                            <?php if($calendrier_details['date_debut_conge_etude'] && $calendrier_details['date_fin_conge_etude']): ?>
                            <li class="list-group-item">
                                <strong>Congé d'étude:</strong><br>
                                <span class="text-muted">
                                    <?php echo formatDateFr($calendrier_details['date_debut_conge_etude']); ?> 
                                    au <?php echo formatDateFr($calendrier_details['date_fin_conge_etude']); ?>
                                </span>
                            </li>
                            <?php endif; ?>
                            
                            <?php if($calendrier_details['date_reprise_cours']): ?>
                            <li class="list-group-item">
                                <strong>Reprise des cours (S2):</strong><br>
                                <span class="text-muted">
                                    <?php echo formatDateFr($calendrier_details['date_reprise_cours']); ?>
                                </span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="calendrier_academique.php?<?php echo $filter_params; ?>" class="btn btn-secondary">Fermer</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Modals de Modification (complets) -->
    <?php foreach($calendriers as $cal): ?>
    <div class="modal fade" id="editModal<?php echo $cal['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Modifier le Calendrier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo $cal['id']; ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Site</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($cal['site_nom']); ?>" readonly>
                                <input type="hidden" name="site_id" value="<?php echo $cal['site_id']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Année Académique</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($cal['annee_libelle']); ?>" readonly>
                                <input type="hidden" name="annee_academique_id" value="<?php echo $cal['annee_academique_id']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Semestre *</label>
                                <select name="semestre" class="form-select" required>
                                    <option value="1" <?php echo $cal['semestre'] == '1' ? 'selected' : ''; ?>>Semestre 1</option>
                                    <option value="2" <?php echo $cal['semestre'] == '2' ? 'selected' : ''; ?>>Semestre 2</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Type de rentrée *</label>
                                <select name="type_rentree" class="form-select" required>
                                    <option value="Octobre" <?php echo $cal['type_rentree'] == 'Octobre' ? 'selected' : ''; ?>>Octobre</option>
                                    <option value="Janvier" <?php echo $cal['type_rentree'] == 'Janvier' ? 'selected' : ''; ?>>Janvier</option>
                                    <option value="Avril" <?php echo $cal['type_rentree'] == 'Avril' ? 'selected' : ''; ?>>Avril</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début des cours *</label>
                                <input type="date" name="date_debut_cours" class="form-control" required 
                                       value="<?php echo $cal['date_debut_cours']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin des cours *</label>
                                <input type="date" name="date_fin_cours" class="form-control" required 
                                       value="<?php echo $cal['date_fin_cours']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début DST</label>
                                <input type="date" name="date_debut_dst" class="form-control" 
                                       value="<?php echo $cal['date_debut_dst']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin DST</label>
                                <input type="date" name="date_fin_dst" class="form-control" 
                                       value="<?php echo $cal['date_fin_dst']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début Devoir Recherche</label>
                                <input type="date" name="date_debut_recherche" class="form-control" 
                                       value="<?php echo $cal['date_debut_recherche']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin Devoir Recherche</label>
                                <input type="date" name="date_fin_recherche" class="form-control" 
                                       value="<?php echo $cal['date_fin_recherche']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début congé d'étude</label>
                                <input type="date" name="date_debut_conge_etude" class="form-control" 
                                       value="<?php echo $cal['date_debut_conge_etude']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin congé d'étude</label>
                                <input type="date" name="date_fin_conge_etude" class="form-control" 
                                       value="<?php echo $cal['date_fin_conge_etude']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début examens</label>
                                <input type="date" name="date_debut_examens" class="form-control" 
                                       value="<?php echo $cal['date_debut_examens']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin examens</label>
                                <input type="date" name="date_fin_examens" class="form-control" 
                                       value="<?php echo $cal['date_fin_examens']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date reprise cours S2</label>
                                <input type="date" name="date_reprise_cours" class="form-control" 
                                       value="<?php echo $cal['date_reprise_cours']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date début stage</label>
                                <input type="date" name="date_debut_stage" class="form-control" 
                                       value="<?php echo $cal['date_debut_stage']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date fin stage</label>
                                <input type="date" name="date_fin_stage" class="form-control" 
                                       value="<?php echo $cal['date_fin_stage']; ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Statut *</label>
                                <select name="statut" class="form-select" required>
                                    <option value="planifie" <?php echo $cal['statut'] == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                    <option value="en_cours" <?php echo $cal['statut'] == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                    <option value="termine" <?php echo $cal['statut'] == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                    <option value="annule" <?php echo $cal['statut'] == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="publie" class="form-check-input" id="publie<?php echo $cal['id']; ?>"
                                        <?php echo $cal['publie'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="publie<?php echo $cal['id']; ?>">
                                        Publier le calendrier
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Observations</label>
                                <textarea name="observations" class="form-control" rows="3"><?php echo htmlspecialchars($cal['observations']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Si un paramètre 'view' est présent, on affiche le modal
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'view') {
            // Créer et afficher le modal de vue
            const modal = new bootstrap.Modal(document.getElementById('viewModal'));
            modal.show();
            
            // Quand on ferme le modal, on retire le paramètre de l'URL
            document.getElementById('viewModal').addEventListener('hidden.bs.modal', function() {
                window.location.href = 'calendrier_academique.php?<?php echo $filter_params; ?>';
            });
        }
        
        // Validation des formulaires
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Validation des dates
                const date_debut_cours = this.querySelector('input[name="date_debut_cours"]');
                const date_fin_cours = this.querySelector('input[name="date_fin_cours"]');
                
                if (date_debut_cours && date_fin_cours) {
                    if (new Date(date_debut_cours.value) >= new Date(date_fin_cours.value)) {
                        e.preventDefault();
                        alert('La date de début des cours doit être avant la date de fin.');
                        return false;
                    }
                }
                
                const date_debut_stage = this.querySelector('input[name="date_debut_stage"]');
                const date_fin_stage = this.querySelector('input[name="date_fin_stage"]');
                
                if (date_debut_stage && date_fin_stage && date_debut_stage.value && date_fin_stage.value) {
                    if (new Date(date_debut_stage.value) >= new Date(date_fin_stage.value)) {
                        e.preventDefault();
                        alert('La date de début du stage doit être avant la date de fin.');
                        return false;
                    }
                    
                    if (date_fin_cours && new Date(date_debut_stage.value) <= new Date(date_fin_cours.value)) {
                        e.preventDefault();
                        alert('Le stage doit commencer APRÈS la fin des cours.');
                        return false;
                    }
                }
                
                const date_debut_examens = this.querySelector('input[name="date_debut_examens"]');
                const date_fin_examens = this.querySelector('input[name="date_fin_examens"]');
                
                if (date_debut_examens && date_fin_examens && date_debut_examens.value && date_fin_examens.value) {
                    if (new Date(date_debut_examens.value) >= new Date(date_fin_examens.value)) {
                        e.preventDefault();
                        alert('La date de début des examens doit être avant la date de fin.');
                        return false;
                    }
                }
                
                return true;
            });
        });
        
        // Initialiser FullCalendar
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                events: [
                    // Exemples d'événements (à remplacer par des données dynamiques)
                    {
                        title: 'Rentrée académique',
                        start: '2025-10-01',
                        backgroundColor: '#1976d2',
                        borderColor: '#1976d2'
                    },
                    {
                        title: 'Session d\'examens',
                        start: '2025-12-15',
                        end: '2025-12-22',
                        backgroundColor: '#d32f2f',
                        borderColor: '#d32f2f'
                    },
                    {
                        title: 'Stage professionnel',
                        start: '2026-03-10',
                        end: '2026-06-10',
                        backgroundColor: '#fbc02d',
                        borderColor: '#fbc02d',
                        textColor: '#000'
                    }
                ],
                eventClick: function(info) {
                    alert('Événement: ' + info.event.title + '\n' +
                          'Début: ' + info.event.start.toLocaleDateString('fr-FR') + 
                          (info.event.end ? '\nFin: ' + info.event.end.toLocaleDateString('fr-FR') : ''));
                },
                buttonText: {
                    today: 'Aujourd\'hui',
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste'
                }
            });
            calendar.render();
        }
    });
    </script>
</body>
</html>