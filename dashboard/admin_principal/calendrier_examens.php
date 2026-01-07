<?php
// dashboard/admin_principal/calendrier_examens.php

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
    $pageTitle = "Gestion du Calendrier des Examens";
    
    // Fonctions utilitaires
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatTimeFr($time) {
        if (empty($time)) return '';
        return date('H:i', strtotime($time));
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
            case 'reporte':
                return '<span class="badge bg-secondary">Reporté</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Initialiser les variables
    $examens = [];
    $sites = [];
    $annees_academiques = [];
    $matieres = [];
    $classes = [];
    $enseignants = [];
    $types_examens = [];
    $filters = [];
    $error = null;
    $success = null;
    
    // Récupérer les données de filtrage
    $filters['site_id'] = isset($_GET['site_id']) ? intval($_GET['site_id']) : null;
    $filters['annee_id'] = isset($_GET['annee_id']) ? intval($_GET['annee_id']) : null;
    $filters['matiere_id'] = isset($_GET['matiere_id']) ? intval($_GET['matiere_id']) : null;
    $filters['classe_id'] = isset($_GET['classe_id']) ? intval($_GET['classe_id']) : null;
    $filters['type_examen_id'] = isset($_GET['type_examen_id']) ? intval($_GET['type_examen_id']) : null;
    $filters['statut'] = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filters['date_debut'] = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $filters['date_fin'] = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    
    // Gestion des actions
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    // Actions CRUD
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        
        try {
            switch ($action) {
                case 'add':
                    // Ajouter un examen
                    $stmt = $db->prepare("INSERT INTO calendrier_examens 
                        (calendrier_academique_id, matiere_id, classe_id, type_examen_id, 
                         enseignant_id, date_examen, heure_debut, heure_fin, duree_minutes,
                         salle, nombre_places, type_evaluation, coefficient, bareme,
                         consignes, documents_autorises, materiel_requis, statut,
                         publie_etudiants, cree_par, date_creation)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $stmt->execute([
                        $_POST['calendrier_academique_id'],
                        $_POST['matiere_id'],
                        $_POST['classe_id'],
                        $_POST['type_examen_id'],
                        $_POST['enseignant_id'] ?: null,
                        $_POST['date_examen'],
                        $_POST['heure_debut'],
                        $_POST['heure_fin'],
                        $_POST['duree_minutes'],
                        $_POST['salle'],
                        $_POST['nombre_places'],
                        $_POST['type_evaluation'],
                        $_POST['coefficient'],
                        $_POST['bareme'],
                        $_POST['consignes'],
                        $_POST['documents_autorises'],
                        $_POST['materiel_requis'],
                        $_POST['statut'],
                        isset($_POST['publie_etudiants']) ? 1 : 0,
                        $_SESSION['user_id']
                    ]);
                    
                    $success = "Examen ajouté avec succès!";
                    break;
                    
                case 'edit':
                    // Modifier un examen
                    $stmt = $db->prepare("UPDATE calendrier_examens SET
                        calendrier_academique_id = ?,
                        matiere_id = ?,
                        classe_id = ?,
                        type_examen_id = ?,
                        enseignant_id = ?,
                        date_examen = ?,
                        heure_debut = ?,
                        heure_fin = ?,
                        duree_minutes = ?,
                        salle = ?,
                        nombre_places = ?,
                        type_evaluation = ?,
                        coefficient = ?,
                        bareme = ?,
                        consignes = ?,
                        documents_autorises = ?,
                        materiel_requis = ?,
                        statut = ?,
                        publie_etudiants = ?,
                        modifie_par = ?,
                        date_modification = NOW()
                        WHERE id = ?");
                    
                    $stmt->execute([
                        $_POST['calendrier_academique_id'],
                        $_POST['matiere_id'],
                        $_POST['classe_id'],
                        $_POST['type_examen_id'],
                        $_POST['enseignant_id'] ?: null,
                        $_POST['date_examen'],
                        $_POST['heure_debut'],
                        $_POST['heure_fin'],
                        $_POST['duree_minutes'],
                        $_POST['salle'],
                        $_POST['nombre_places'],
                        $_POST['type_evaluation'],
                        $_POST['coefficient'],
                        $_POST['bareme'],
                        $_POST['consignes'],
                        $_POST['documents_autorises'],
                        $_POST['materiel_requis'],
                        $_POST['statut'],
                        isset($_POST['publie_etudiants']) ? 1 : 0,
                        $_SESSION['user_id'],
                        $_POST['id']
                    ]);
                    
                    $success = "Examen modifié avec succès!";
                    break;
                    
                case 'delete':
                    // Supprimer un examen
                    $stmt = $db->prepare("DELETE FROM calendrier_examens WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Examen supprimé avec succès!";
                    break;
                    
                case 'publish':
                    // Publier l'examen aux étudiants
                    $stmt = $db->prepare("UPDATE calendrier_examens 
                        SET publie_etudiants = 1, 
                            modifie_par = ?,
                            date_modification = NOW()
                        WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $success = "Examen publié aux étudiants!";
                    break;
                    
                case 'unpublish':
                    // Dépublier l'examen
                    $stmt = $db->prepare("UPDATE calendrier_examens 
                        SET publie_etudiants = 0,
                            modifie_par = ?,
                            date_modification = NOW()
                        WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $id]);
                    $success = "Examen dépublié!";
                    break;
            }
        } catch (Exception $e) {
            $error = "Erreur: " . $e->getMessage();
        }
    }
    
    // Récupérer les données pour les listes déroulantes
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY nom")->fetchAll();
    $types_examens = $db->query("SELECT * FROM types_examens ORDER BY ordre")->fetchAll();
    
    // Construire la requête SQL avec filtres
    $sql = "SELECT ce.*, 
                   m.nom as matiere_nom, m.code as matiere_code,
                   c.nom as classe_nom,
                   s.nom as site_nom, s.ville as site_ville,
                   aa.libelle as annee_libelle,
                   te.nom as type_examen_nom,
                   CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
            FROM calendrier_examens ce
            LEFT JOIN matieres m ON ce.matiere_id = m.id
            LEFT JOIN classes c ON ce.classe_id = c.id
            LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
            LEFT JOIN sites s ON ca.site_id = s.id
            LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
            LEFT JOIN types_examens te ON ce.type_examen_id = te.id
            LEFT JOIN enseignants e ON ce.enseignant_id = e.id
            LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
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
    
    if ($filters['matiere_id']) {
        $sql .= " AND ce.matiere_id = ?";
        $params[] = $filters['matiere_id'];
    }
    
    if ($filters['classe_id']) {
        $sql .= " AND ce.classe_id = ?";
        $params[] = $filters['classe_id'];
    }
    
    if ($filters['type_examen_id']) {
        $sql .= " AND ce.type_examen_id = ?";
        $params[] = $filters['type_examen_id'];
    }
    
    if ($filters['statut']) {
        $sql .= " AND ce.statut = ?";
        $params[] = $filters['statut'];
    }
    
    if ($filters['date_debut']) {
        $sql .= " AND ce.date_examen >= ?";
        $params[] = $filters['date_debut'];
    }
    
    if ($filters['date_fin']) {
        $sql .= " AND ce.date_examen <= ?";
        $params[] = $filters['date_fin'];
    }
    
    $sql .= " ORDER BY ce.date_examen DESC, ce.heure_debut DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $examens = $stmt->fetchAll();
    
    // Récupérer les années académiques pour le filtre sélectionné
    if ($filters['site_id']) {
        $annees_academiques = $db->prepare("SELECT * FROM annees_academiques 
                                          WHERE site_id = ? ORDER BY libelle DESC");
        $annees_academiques->execute([$filters['site_id']]);
        $annees_academiques = $annees_academiques->fetchAll();
    }
    
    // Récupérer les matières pour le filtre sélectionné
    if ($filters['site_id']) {
        $matieres = $db->prepare("SELECT m.* FROM matieres m 
                                WHERE m.site_id = ? ORDER BY m.nom");
        $matieres->execute([$filters['site_id']]);
        $matieres = $matieres->fetchAll();
    }
    
    // Récupérer les classes pour le filtre sélectionné
    if ($filters['site_id']) {
        $classes = $db->prepare("SELECT c.* FROM classes c 
                               WHERE c.site_id = ? ORDER BY c.nom");
        $classes->execute([$filters['site_id']]);
        $classes = $classes->fetchAll();
    }
    
    // Récupérer les enseignants pour le filtre sélectionné
    if ($filters['site_id']) {
        $enseignants = $db->prepare("SELECT e.*, CONCAT(u.nom, ' ', u.prenom) as nom_complet 
                                   FROM enseignants e 
                                   JOIN utilisateurs u ON e.utilisateur_id = u.id 
                                   WHERE e.site_id = ? AND e.statut = 'actif' 
                                   ORDER BY u.nom, u.prenom");
        $enseignants->execute([$filters['site_id']]);
        $enseignants = $enseignants->fetchAll();
    }
    
    // Récupérer les calendriers académiques pour le modal
    $calendriers_academiques = [];
    if ($filters['site_id'] && $filters['annee_id']) {
        $calendriers_academiques = $db->prepare("SELECT * FROM calendrier_academique 
                                               WHERE site_id = ? AND annee_academique_id = ?
                                               ORDER BY semestre");
        $calendriers_academiques->execute([$filters['site_id'], $filters['annee_id']]);
        $calendriers_academiques = $calendriers_academiques->fetchAll();
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
    
    <!-- Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
    
    .badge-planifie { background-color: #ffc107; color: #000; }
    .badge-en_cours { background-color: #17a2b8; color: #fff; }
    .badge-termine { background-color: #28a745; color: #fff; }
    .badge-annule { background-color: #dc3545; color: #fff; }
    .badge-reporte { background-color: #6c757d; color: #fff; }
    
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
    
    .exam-card {
        border-left: 4px solid #3498db;
        margin-bottom: 10px;
    }
    
    .exam-card.dst { border-left-color: #3498db; }
    .exam-card.recherche { border-left-color: #27ae60; }
    .exam-card.session { border-left-color: #e74c3c; }
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
                            <i class="fas fa-calendar-alt me-2"></i>
                            Calendrier des Examens
                        </h2>
                        <p class="text-muted mb-0">Gestion des examens et évaluations</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExamenModal">
                            <i class="fas fa-plus"></i> Nouvel Examen
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
                        <div class="col-md-3">
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
                        <div class="col-md-3">
                            <label class="form-label">Année Académique</label>
                            <select name="annee_id" class="form-select" onchange="this.form.submit()">
                                <option value="">Toutes les années</option>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"
                                    <?php echo $filters['annee_id'] == $annee['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Matière</label>
                            <select name="matiere_id" class="form-select">
                                <option value="">Toutes les matières</option>
                                <?php foreach($matieres as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>"
                                    <?php echo $filters['matiere_id'] == $matiere['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Classe</label>
                            <select name="classe_id" class="form-select">
                                <option value="">Toutes les classes</option>
                                <?php foreach($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"
                                    <?php echo $filters['classe_id'] == $classe['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($classe['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label class="form-label">Type d'examen</label>
                            <select name="type_examen_id" class="form-select">
                                <option value="">Tous les types</option>
                                <?php foreach($types_examens as $type): ?>
                                <option value="<?php echo $type['id']; ?>"
                                    <?php echo $filters['type_examen_id'] == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select">
                                <option value="">Tous les statuts</option>
                                <option value="planifie" <?php echo $filters['statut'] == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                <option value="en_cours" <?php echo $filters['statut'] == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                <option value="termine" <?php echo $filters['statut'] == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                <option value="annule" <?php echo $filters['statut'] == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                <option value="reporte" <?php echo $filters['statut'] == 'reporte' ? 'selected' : ''; ?>>Reporté</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Date début</label>
                            <input type="date" name="date_debut" class="form-control" 
                                   value="<?php echo htmlspecialchars($filters['date_debut']); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="date_fin" class="form-control" 
                                   value="<?php echo htmlspecialchars($filters['date_fin']); ?>">
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Appliquer les filtres
                            </button>
                            <a href="calendrier_examens.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Réinitialiser
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Vue Calendrier et Liste -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="viewTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="list-tab" data-bs-toggle="tab" data-bs-target="#list-view">
                                <i class="fas fa-list me-2"></i>Vue Liste
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendar-view">
                                <i class="fas fa-calendar me-2"></i>Vue Calendrier
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats-view">
                                <i class="fas fa-chart-bar me-2"></i>Statistiques
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
                                            <th>Date/Heure</th>
                                            <th>Matière</th>
                                            <th>Classe</th>
                                            <th>Type</th>
                                            <th>Salle</th>
                                            <th>Enseignant</th>
                                            <th>Statut</th>
                                            <th>Publié</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($examens)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center">
                                                <div class="alert alert-info">
                                                    Aucun examen trouvé avec les filtres sélectionnés
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach($examens as $examen): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo formatDateFr($examen['date_examen']); ?></strong><br>
                                                <small><?php echo formatTimeFr($examen['heure_debut']); ?> - <?php echo formatTimeFr($examen['heure_fin']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($examen['matiere_code'] ?? ''); ?><br>
                                                <small><?php echo htmlspecialchars($examen['matiere_nom'] ?? ''); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($examen['classe_nom'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($examen['type_examen_nom'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($examen['salle'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($examen['enseignant_nom'] ?? 'Non assigné'); ?></td>
                                            <td><?php echo getStatutBadge($examen['statut']); ?></td>
                                            <td>
                                                <?php if($examen['publie_etudiants']): ?>
                                                <span class="badge bg-success">Oui</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning">Non</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewExamenModal"
                                                            onclick="viewExamen(<?php echo $examen['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editExamenModal"
                                                            onclick="editExamen(<?php echo $examen['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if(!$examen['publie_etudiants']): ?>
                                                    <a href="?action=publish&id=<?php echo $examen['id']; ?>" 
                                                       class="btn btn-success"
                                                       onclick="return confirm('Publier cet examen aux étudiants?')">
                                                        <i class="fas fa-share"></i>
                                                    </a>
                                                    <?php else: ?>
                                                    <a href="?action=unpublish&id=<?php echo $examen['id']; ?>" 
                                                       class="btn btn-secondary"
                                                       onclick="return confirm('Dépublier cet examen?')">
                                                        <i class="fas fa-ban"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <a href="?action=delete&id=<?php echo $examen['id']; ?>" 
                                                       class="btn btn-danger"
                                                       onclick="return confirm('Supprimer cet examen? Cette action est irréversible.')">
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
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Vue calendrier - En développement
                                <p class="mb-0 mt-2">Cette fonctionnalité affichera les examens sous forme de calendrier mensuel.</p>
                            </div>
                        </div>
                        
                        <!-- Vue Statistiques -->
                        <div class="tab-pane fade" id="stats-view">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5>Répartition par type</h5>
                                    <div class="alert alert-info">
                                        Graphique des types d'examens - En développement
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h5>Répartition par statut</h5>
                                    <div class="alert alert-info">
                                        Graphique des statuts - En développement
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Informations -->
            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle"></i> Instructions</h5>
                <ul class="mb-0">
                    <li>Sélectionnez d'abord un site pour filtrer les données</li>
                    <li>Utilisez les filtres pour affiner votre recherche</li>
                    <li>Cliquez sur "Nouvel Examen" pour ajouter un examen</li>
                    <li>Les examens publiés sont visibles par les étudiants</li>
                    <li>Les examens annulés ou reportés doivent être justifiés</li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Modal Ajout Examen -->
    <div class="modal fade" id="addExamenModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Ajouter un Examen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <?php if(!$filters['site_id']): ?>
                        <div class="alert alert-warning">
                            Veuillez d'abord sélectionner un site dans les filtres
                        </div>
                        <?php elseif(!$filters['annee_id']): ?>
                        <div class="alert alert-warning">
                            Veuillez d'abord sélectionner une année académique
                        </div>
                        <?php else: ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Calendrier Académique *</label>
                                <select name="calendrier_academique_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach($calendriers_academiques as $cal): ?>
                                    <option value="<?php echo $cal['id']; ?>">
                                        Semestre <?php echo $cal['semestre']; ?> - <?php echo $cal['type_rentree']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Matière *</label>
                                <select name="matiere_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach($matieres as $matiere): ?>
                                    <option value="<?php echo $matiere['id']; ?>">
                                        <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Classe *</label>
                                <select name="classe_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo htmlspecialchars($classe['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Type d'examen *</label>
                                <select name="type_examen_id" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach($types_examens as $type): ?>
                                    <option value="<?php echo $type['id']; ?>">
                                        <?php echo htmlspecialchars($type['nom']); ?> (<?php echo $type['pourcentage']; ?>%)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Enseignant</label>
                                <select name="enseignant_id" class="form-select">
                                    <option value="">Non assigné</option>
                                    <?php foreach($enseignants as $ens): ?>
                                    <option value="<?php echo $ens['id']; ?>">
                                        <?php echo htmlspecialchars($ens['nom_complet']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date de l'examen *</label>
                                <input type="date" name="date_examen" class="form-control" required 
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Heure début *</label>
                                <input type="time" name="heure_debut" class="form-control" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Heure fin *</label>
                                <input type="time" name="heure_fin" class="form-control" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Durée (minutes)</label>
                                <input type="number" name="duree_minutes" class="form-control" value="120">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Salle</label>
                                <input type="text" name="salle" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Nombre de places</label>
                                <input type="number" name="nombre_places" class="form-control">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Type d'évaluation</label>
                                <select name="type_evaluation" class="form-select">
                                    <option value="ecrit">Écrit</option>
                                    <option value="oral">Oral</option>
                                    <option value="pratique">Pratique</option>
                                    <option value="projet">Projet</option>
                                    <option value="tp">TP</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Coefficient</label>
                                <input type="number" name="coefficient" class="form-control" step="0.01" value="1.00">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Barème (sur)</label>
                                <input type="number" name="bareme" class="form-control" step="0.01" value="20.00">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Consignes</label>
                                <textarea name="consignes" class="form-control" rows="3"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Documents autorisés</label>
                                <textarea name="documents_autorises" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Matériel requis</label>
                                <textarea name="materiel_requis" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Statut</label>
                                <select name="statut" class="form-select" required>
                                    <option value="planifie">Planifié</option>
                                    <option value="en_cours">En cours</option>
                                    <option value="termine">Terminé</option>
                                    <option value="annule">Annulé</option>
                                    <option value="reporte">Reporté</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input type="checkbox" name="publie_etudiants" class="form-check-input" id="publie">
                                    <label class="form-check-label" for="publie">
                                        Publier aux étudiants
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <?php if($filters['site_id'] && $filters['annee_id']): ?>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    // Initialiser les datepickers
    flatpickr("input[type=date]", {
        dateFormat: "Y-m-d",
        locale: "fr"
    });
    
    flatpickr("input[type=time]", {
        enableTime: true,
        noCalendar: true,
        dateFormat: "H:i",
        time_24hr: true
    });
    
    // Fonction pour afficher les détails d'un examen
    async function viewExamen(id) {
        try {
            // Dans un vrai système, vous auriez un fichier API
            // Pour l'instant, on utilise une simulation
            alert('Détails de l\'examen ID: ' + id + '\n\nCette fonctionnalité nécessite un fichier API.');
            
        } catch (error) {
            alert('Erreur lors du chargement des données');
        }
    }
    
    // Fonction pour éditer un examen
    async function editExamen(id) {
        try {
            alert('Édition de l\'examen ID: ' + id + '\n\nCette fonctionnalité nécessite un fichier API.');
        } catch (error) {
            alert('Erreur lors du chargement des données');
        }
    }
    
    // Auto-submit du formulaire de filtres pour certaines sélections
    document.addEventListener('DOMContentLoaded', function() {
        const autoSubmitSelects = document.querySelectorAll('select[onchange*="submit"]');
        autoSubmitSelects.forEach(select => {
            select.addEventListener('change', function() {
                // Soumettre le formulaire parent
                let form = this.closest('form');
                if (form) {
                    form.submit();
                }
            });
        });
    });
    </script>
</body>
</html>