<?php
// dashboard/admin_principal/cours_en_ligne.php

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

// Vérifier le rôle (admin principal uniquement)
if ($_SESSION['role_id'] != 1) { // ID 1 = admin principal
    header('Location: ' . ROOT_PATH . '/dashboard/index.php');
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
    $pageTitle = "Cours en Ligne - Administrateur Principal";
    
    // Récupérer l'action
    $action = $_GET['action'] ?? 'list';
    $id = $_GET['id'] ?? 0;
    $success = $_GET['success'] ?? '';
    $error = $_GET['error'] ?? '';
    
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
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'termine':
                return '<span class="badge bg-info">Terminé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function formatDuree($minutes) {
        $heures = floor($minutes / 60);
        $minutes = $minutes % 60;
        return ($heures > 0 ? $heures . 'h ' : '') . ($minutes > 0 ? $minutes . 'min' : '');
    }
    
    function getEnseignantNom($enseignant_id, $db) {
        if (!$enseignant_id) return 'Non assigné';
        
        $query = "SELECT u.nom, u.prenom 
                  FROM utilisateurs u 
                  INNER JOIN enseignants e ON u.id = e.utilisateur_id 
                  WHERE e.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$enseignant_id]);
        $enseignant = $stmt->fetch();
        
        return $enseignant ? htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']) : 'Inconnu';
    }
    
    function getMatiereNom($matiere_id, $db) {
        if (!$matiere_id) return 'Général';
        
        $query = "SELECT m.nom, f.nom as filiere_nom, s.nom as site_nom
                  FROM matieres m
                  LEFT JOIN filieres f ON m.filiere_id = f.id
                  LEFT JOIN sites s ON m.site_id = s.id
                  WHERE m.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$matiere_id]);
        $matiere = $stmt->fetch();
        
        if (!$matiere) return 'Inconnue';
        
        return htmlspecialchars($matiere['nom'] . ' (' . $matiere['filiere_nom'] . ' - ' . $matiere['site_nom'] . ')');
    }
    
    function getCoursDetails($id, $db) {
        $query = "SELECT c.*, m.nom as matiere_nom, s.nom as site_nom, s.id as site_id
                  FROM cours_en_ligne c 
                  LEFT JOIN matieres m ON c.matiere_id = m.id 
                  LEFT JOIN sites s ON c.site_id = s.id 
                  WHERE c.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    function getParticipants($cours_id, $db) {
        $query = "SELECT cp.*, e.matricule, e.nom, e.prenom, es.nom as site_nom
                  FROM cours_participants cp
                  JOIN etudiants e ON cp.etudiant_id = e.id
                  JOIN sites es ON e.site_id = es.id
                  WHERE cp.cours_id = ?
                  ORDER BY cp.date_inscription DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$cours_id]);
        return $stmt->fetchAll();
    }
    
    function getCoursByStatus($status, $db) {
        $query = "SELECT c.*, m.nom as matiere_nom, s.nom as site_nom
                  FROM cours_en_ligne c 
                  LEFT JOIN matieres m ON c.matiere_id = m.id 
                  LEFT JOIN sites s ON c.site_id = s.id
                  WHERE c.statut = ?
                  ORDER BY c.date_cours";
        $stmt = $db->prepare($query);
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }
    
    // Compter les demandes en attente
    $demandeCount = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM demande_inscriptions WHERE statut = 'en_attente'");
        $stmt->execute();
        $result = $stmt->fetch();
        $demandeCount = $result['count'];
    } catch (Exception $e) {
        error_log("Error counting demands: " . $e->getMessage());
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
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
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
    
    /* Cartes de cours */
    .card-cours {
        transition: transform 0.2s;
        border-left: 4px solid var(--secondary-color);
        height: 100%;
    }
    
    .card-cours:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .live-badge {
        background: var(--accent-color);
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
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
    
    /* Calendrier */
    .calendar-container {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    /* Boutons spécifiques */
    .btn-replay {
        background: var(--info-color);
        border-color: var(--info-color);
        color: white;
    }
    
    .btn-replay:hover {
        background: #138496;
        border-color: #117a8b;
        color: white;
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
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
                <h5 class="mt-2 mb-1">ISGI ADMIN</h5>
                <div class="user-role">Administrateur Principal</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></p>
                <small>Gestion Cours en Ligne</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Global</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Multi-Sites</div>
                    <a href="sites.php" class="nav-link">
                        <i class="fas fa-building"></i>
                        <span>Tous les Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Tous les Utilisateurs</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes d'Inscription</span>
                        <?php if ($demandeCount > 0): ?>
                        <span class="nav-badge"><?php echo $demandeCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique Global</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Tous les Professeurs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Pédagogie & Ressources</div>
                    <a href="cours_en_ligne.php" class="nav-link active">
                        <i class="fas fa-laptop"></i>
                        <span>Cours en Ligne</span>
                    </a>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Bibliothèque</span>
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
                            <i class="fas fa-chalkboard-teacher me-2"></i>
                            Cours en Ligne - Vue Multi-Sites
                        </h2>
                        <p class="text-muted mb-0">Gestion des cours en ligne pour tous les sites</p>
                    </div>
                    <div class="btn-group">
                        <a href="cours_en_ligne.php?action=planifier" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Planifier un cours
                        </a>
                        <a href="cours_en_ligne.php?action=calendar" class="btn btn-success">
                            <i class="fas fa-calendar"></i> Calendrier
                        </a>
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php 
            // Afficher les messages de succès/erreur depuis les paramètres GET
            if($success): 
                $success_messages = [
                    '1' => 'Cours planifié avec succès!',
                    '2' => 'Cours modifié avec succès!',
                    '3' => 'Cours supprimé avec succès!',
                    '4' => 'Participants ajoutés avec succès!'
                ];
            ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_messages[$success] ?? 'Opération réussie!'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Contenu principal selon l'action -->
            <?php if($action == 'list'): ?>
            <!-- Dashboard principal -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card text-primary">
                        <div class="stat-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <?php
                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE statut = 'en_cours'");
                        $stmt->execute();
                        $count = $stmt->fetch();
                        ?>
                        <div class="stat-value"><?php echo $count['total']; ?></div>
                        <div class="stat-label">Cours en direct</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-success">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <?php
                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE statut = 'planifie'");
                        $stmt->execute();
                        $count = $stmt->fetch();
                        ?>
                        <div class="stat-value"><?php echo $count['total']; ?></div>
                        <div class="stat-label">Cours planifiés</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-info">
                        <div class="stat-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <?php
                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE statut = 'termine' AND url_replay IS NOT NULL");
                        $stmt->execute();
                        $count = $stmt->fetch();
                        ?>
                        <div class="stat-value"><?php echo $count['total']; ?></div>
                        <div class="stat-label">Replays disponibles</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-warning">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <?php
                        $stmt = $db->prepare("SELECT COUNT(DISTINCT etudiant_id) as total FROM cours_participants");
                        $stmt->execute();
                        $count = $stmt->fetch();
                        ?>
                        <div class="stat-value"><?php echo $count['total']; ?></div>
                        <div class="stat-label">Participants totaux</div>
                    </div>
                </div>
            </div>

            <!-- Onglets -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="coursTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="live-tab" data-bs-toggle="tab" data-bs-target="#live" type="button">
                                <i class="fas fa-video"></i> En direct
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="planifies-tab" data-bs-toggle="tab" data-bs-target="#planifies" type="button">
                                <i class="fas fa-calendar"></i> Planifiés
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="replay-tab" data-bs-toggle="tab" data-bs-target="#replay" type="button">
                                <i class="fas fa-history"></i> Replays
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tous-tab" data-bs-toggle="tab" data-bs-target="#tous" type="button">
                                <i class="fas fa-list"></i> Tous les cours
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="coursTabsContent">
                        <!-- Tab 1: Cours en direct -->
                        <div class="tab-pane fade show active" id="live">
                            <div class="row">
                                <?php
                                $cours_live = getCoursByStatus('en_cours', $db);
                                if (empty($cours_live)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun cours en direct pour le moment.
                                    </div>
                                </div>
                                <?php else: ?>
                                <?php foreach($cours_live as $cour): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card card-cours border-danger">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($cour['titre']); ?></h5>
                                                <span class="badge bg-danger live-badge">EN DIRECT</span>
                                            </div>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-book text-primary"></i> 
                                                <?php echo htmlspecialchars($cour['matiere_nom'] ?? 'Général'); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-user-tie text-secondary"></i> 
                                                <?php echo getEnseignantNom($cour['enseignant_id'], $db); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-clock text-warning"></i> 
                                                <?php echo date('d/m/Y H:i', strtotime($cour['date_cours'])); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-building text-success"></i> 
                                                <?php echo htmlspecialchars($cour['site_nom']); ?>
                                            </p>
                                            
                                            <div class="d-flex justify-content-between mt-3">
                                                <?php if($cour['url_live']): ?>
                                                <a href="<?php echo $cour['url_live']; ?>" class="btn btn-danger" target="_blank">
                                                    <i class="fas fa-play"></i> Rejoindre
                                                </a>
                                                <?php endif; ?>
                                                
                                                <a href="cours_en_ligne.php?action=view&id=<?php echo $cour['id']; ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Détails
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab 2: Cours planifiés -->
                        <div class="tab-pane fade" id="planifies">
                            <div class="row">
                                <?php
                                $cours_planifies = getCoursByStatus('planifie', $db);
                                if (empty($cours_planifies)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun cours planifié.
                                    </div>
                                </div>
                                <?php else: ?>
                                <?php foreach($cours_planifies as $cour): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card card-cours border-warning">
                                        <div class="card-body">
                                            <h5 class="card-title"><?php echo htmlspecialchars($cour['titre']); ?></h5>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-book text-primary"></i> 
                                                <?php echo htmlspecialchars($cour['matiere_nom'] ?? 'Général'); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-user-tie text-secondary"></i> 
                                                <?php echo getEnseignantNom($cour['enseignant_id'], $db); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-clock text-warning"></i> 
                                                <?php echo date('d/m/Y H:i', strtotime($cour['date_cours'])); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-hourglass-half text-info"></i> 
                                                <?php echo formatDuree($cour['duree_minutes']); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-building text-success"></i> 
                                                <?php echo htmlspecialchars($cour['site_nom']); ?>
                                            </p>
                                            
                                            <div class="d-flex justify-content-between mt-3">
                                                <a href="cours_en_ligne.php?action=edit&id=<?php echo $cour['id']; ?>" class="btn btn-outline-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Modifier
                                                </a>
                                                <a href="cours_en_ligne.php?action=view&id=<?php echo $cour['id']; ?>" class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-eye"></i> Détails
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab 3: Replays -->
                        <div class="tab-pane fade" id="replay">
                            <div class="row">
                                <?php
                                $query = "SELECT c.*, m.nom as matiere_nom, s.nom as site_nom
                                          FROM cours_en_ligne c 
                                          LEFT JOIN matieres m ON c.matiere_id = m.id 
                                          LEFT JOIN sites s ON c.site_id = s.id
                                          WHERE c.statut = 'termine' AND c.url_replay IS NOT NULL
                                          ORDER BY c.date_cours DESC";
                                $stmt = $db->prepare($query);
                                $stmt->execute();
                                $cours_replay = $stmt->fetchAll();
                                
                                if (empty($cours_replay)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun replay disponible.
                                    </div>
                                </div>
                                <?php else: ?>
                                <?php foreach($cours_replay as $cour): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card card-cours border-info">
                                        <div class="card-body">
                                            <h5 class="card-title"><?php echo htmlspecialchars($cour['titre']); ?></h5>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-book text-primary"></i> 
                                                <?php echo htmlspecialchars($cour['matiere_nom'] ?? 'Général'); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-user-tie text-secondary"></i> 
                                                <?php echo getEnseignantNom($cour['enseignant_id'], $db); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-calendar text-success"></i> 
                                                <?php echo date('d/m/Y', strtotime($cour['date_cours'])); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-hourglass-half text-info"></i> 
                                                <?php echo formatDuree($cour['duree_minutes']); ?>
                                            </p>
                                            
                                            <p class="card-text">
                                                <i class="fas fa-building text-success"></i> 
                                                <?php echo htmlspecialchars($cour['site_nom']); ?>
                                            </p>
                                            
                                            <div class="d-flex justify-content-between mt-3">
                                                <?php if($cour['url_replay']): ?>
                                                <a href="<?php echo $cour['url_replay']; ?>" class="btn btn-replay" target="_blank">
                                                    <i class="fas fa-play-circle"></i> Voir replay
                                                </a>
                                                <?php endif; ?>
                                                
                                                <a href="cours_en_ligne.php?action=view&id=<?php echo $cour['id']; ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Détails
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab 4: Tous les cours -->
                        <div class="tab-pane fade" id="tous">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Titre</th>
                                            <th>Matière</th>
                                            <th>Enseignant</th>
                                            <th>Site</th>
                                            <th>Date</th>
                                            <th>Durée</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $query = "SELECT c.*, m.nom as matiere_nom, s.nom as site_nom
                                                  FROM cours_en_ligne c 
                                                  LEFT JOIN matieres m ON c.matiere_id = m.id 
                                                  LEFT JOIN sites s ON c.site_id = s.id
                                                  ORDER BY c.date_cours DESC";
                                        $stmt = $db->prepare($query);
                                        $stmt->execute();
                                        $tous_cours = $stmt->fetchAll();
                                        
                                        if (empty($tous_cours)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center">
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle"></i> Aucun cours disponible.
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach($tous_cours as $cour): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cour['titre']); ?></td>
                                            <td><?php echo htmlspecialchars($cour['matiere_nom'] ?? 'Général'); ?></td>
                                            <td><?php echo getEnseignantNom($cour['enseignant_id'], $db); ?></td>
                                            <td><?php echo htmlspecialchars($cour['site_nom']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($cour['date_cours'])); ?></td>
                                            <td><?php echo formatDuree($cour['duree_minutes']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch($cour['statut']) {
                                                        case 'en_cours': echo 'danger'; break;
                                                        case 'planifie': echo 'warning'; break;
                                                        case 'termine': echo 'success'; break;
                                                        case 'annule': echo 'secondary'; break;
                                                        default: echo 'light';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($cour['statut']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="cours_en_ligne.php?action=view&id=<?php echo $cour['id']; ?>" class="btn btn-outline-primary" title="Voir">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if($cour['statut'] == 'planifie'): ?>
                                                    <a href="cours_en_ligne.php?action=edit&id=<?php echo $cour['id']; ?>" class="btn btn-outline-warning" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <?php if($cour['statut'] == 'termine' && $cour['url_replay']): ?>
                                                    <a href="<?php echo $cour['url_replay']; ?>" class="btn btn-outline-info" target="_blank" title="Replay">
                                                        <i class="fas fa-history"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($action == 'planifier' || $action == 'edit'): ?>
            <!-- Formulaire de planification/édition -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas <?php echo $action == 'planifier' ? 'fa-plus-circle' : 'fa-edit'; ?>"></i>
                        <?php echo $action == 'planifier' ? 'Planifier un nouveau cours' : 'Modifier le cours'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php 
                    // Afficher les messages d'erreur/succès de session
                    if(isset($_SESSION['error'])): 
                    ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); endif; ?>
                    
                    <?php if(isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); endif; ?>
                    
                    <form method="POST" action="cours_en_ligne_action.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="<?php echo $action == 'planifier' ? 'create' : 'update'; ?>">
                        <?php if($action == 'edit'): ?>
                        <?php $cours = getCoursDetails($id, $db); ?>
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Titre du cours *</label>
                                    <input type="text" class="form-control" name="titre" required
                                        value="<?php 
                                        if($action == 'edit') {
                                            echo htmlspecialchars($cours['titre'] ?? '');
                                        }
                                        ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Type de cours *</label>
                                    <select class="form-select" name="type_cours" required>
                                        <option value="cours" <?php if($action == 'edit' && ($cours['type_cours'] ?? '') == 'cours') echo 'selected'; ?>>Cours</option>
                                        <option value="td" <?php if($action == 'edit' && ($cours['type_cours'] ?? '') == 'td') echo 'selected'; ?>>TD</option>
                                        <option value="tp" <?php if($action == 'edit' && ($cours['type_cours'] ?? '') == 'tp') echo 'selected'; ?>>TP</option>
                                        <option value="conference" <?php if($action == 'edit' && ($cours['type_cours'] ?? '') == 'conference') echo 'selected'; ?>>Conférence</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Site *</label>
                                    <select class="form-select" name="site_id" required>
                                        <option value="">Sélectionner un site</option>
                                        <?php
                                        $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY nom")->fetchAll();
                                        foreach($sites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>"
                                            <?php if($action == 'edit' && ($cours['site_id'] ?? 0) == $site['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($site['nom'] . ' (' . $site['ville'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Matière</label>
                                    <select class="form-select" name="matiere_id">
                                        <option value="">Sélectionner une matière</option>
                                        <?php
                                        $matieres = $db->query("SELECT m.*, f.nom as filiere_nom, s.nom as site_nom FROM matieres m LEFT JOIN filieres f ON m.filiere_id = f.id LEFT JOIN sites s ON m.site_id = s.id ORDER BY m.nom")->fetchAll();
                                        foreach($matieres as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>"
                                            <?php if($action == 'edit' && ($cours['matiere_id'] ?? 0) == $matiere['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($matiere['nom'] . ' - ' . $matiere['filiere_nom'] . ' (' . $matiere['site_nom'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Enseignant</label>
                                    <select class="form-select" name="enseignant_id">
                                        <option value="">Sélectionner un enseignant</option>
                                        <?php
                                        $enseignants = $db->query("
                                            SELECT e.id, u.nom, u.prenom, s.nom as site_nom 
                                            FROM enseignants e 
                                            JOIN utilisateurs u ON e.utilisateur_id = u.id 
                                            JOIN sites s ON e.site_id = s.id
                                            WHERE e.statut = 'actif'
                                            ORDER BY u.nom, u.prenom
                                        ")->fetchAll();
                                        foreach($enseignants as $enseignant): ?>
                                        <option value="<?php echo $enseignant['id']; ?>"
                                            <?php if($action == 'edit' && ($cours['enseignant_id'] ?? 0) == $enseignant['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom'] . ' (' . $enseignant['site_nom'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Date et heure *</label>
                                    <input type="datetime-local" class="form-control" name="date_cours" required
                                        value="<?php 
                                        if($action == 'edit' && isset($cours['date_cours'])) {
                                            echo date('Y-m-d\TH:i', strtotime($cours['date_cours']));
                                        } else {
                                            echo date('Y-m-d\TH:i', strtotime('+1 day'));
                                        }
                                        ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Durée (minutes) *</label>
                                    <input type="number" class="form-control" name="duree_minutes" value="60" min="15" max="480" required
                                        value="<?php if($action == 'edit') echo htmlspecialchars($cours['duree_minutes'] ?? 60); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Participants maximum</label>
                                    <input type="number" class="form-control" name="max_participants" value="100" min="1" max="500"
                                        value="<?php if($action == 'edit') echo htmlspecialchars($cours['max_participants'] ?? 100); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">URL de la salle virtuelle</label>
                                    <input type="url" class="form-control" name="url_live" placeholder="https://zoom.us/j/..."
                                        value="<?php if($action == 'edit') echo htmlspecialchars($cours['url_live'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Mot de passe (optionnel)</label>
                                    <input type="text" class="form-control" name="mot_de_passe"
                                        value="<?php if($action == 'edit') echo htmlspecialchars($cours['mot_de_passe'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="4"><?php 
                                if($action == 'edit') echo htmlspecialchars($cours['description'] ?? '');
                            ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Support de cours (PDF)</label>
                            <input type="file" class="form-control" name="presentation_pdf" accept=".pdf">
                            <?php if($action == 'edit' && !empty($cours['presentation_pdf'])): ?>
                            <small class="text-muted">Fichier actuel: <?php echo basename($cours['presentation_pdf']); ?></small>
                            <?php endif; ?>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="enregistrement_auto" id="enregistrement_auto"
                                        <?php if($action == 'edit' && ($cours['enregistrement_auto'] ?? 0) == 1) echo 'checked'; ?>>
                                    <label class="form-check-label" for="enregistrement_auto">
                                        Enregistrer automatiquement le cours
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">URL de l'enregistrement (replay)</label>
                                    <input type="url" class="form-control" name="url_replay" placeholder="URL du replay après le cours"
                                        value="<?php if($action == 'edit') echo htmlspecialchars($cours['url_replay'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <?php if($action == 'edit'): ?>
                        <div class="mb-3">
                            <label class="form-label">Statut</label>
                            <select class="form-select" name="statut">
                                <option value="planifie" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'planifie') echo 'selected'; ?>>Planifié</option>
                                <option value="en_cours" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'en_cours') echo 'selected'; ?>>En cours</option>
                                <option value="termine" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'termine') echo 'selected'; ?>>Terminé</option>
                                <option value="annule" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'annule') echo 'selected'; ?>>Annulé</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="cours_en_ligne.php" class="btn btn-secondary me-2">Annuler</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> 
                                <?php echo $action == 'planifier' ? 'Planifier le cours' : 'Mettre à jour'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($action == 'view'): ?>
            <!-- Détails d'un cours -->
            <?php $cours = getCoursDetails($id, $db); ?>
            <?php if($cours): ?>
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?php echo htmlspecialchars($cours['titre']); ?></h5>
                        <div class="btn-group">
                            <a href="cours_en_ligne.php?action=edit&id=<?php echo $id; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit"></i> Modifier
                            </a>
                            <button class="btn btn-danger btn-sm" onclick="if(confirm('Êtes-vous sûr de vouloir supprimer ce cours ?')) location.href='cours_en_ligne_action.php?action=delete&id=<?php echo $id; ?>'">
                                <i class="fas fa-trash"></i> Supprimer
                            </button>
                            <a href="cours_en_ligne.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Retour
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-4">
                                <h6>Description</h6>
                                <p><?php echo nl2br(htmlspecialchars($cours['description'] ?? 'Pas de description')); ?></p>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6><i class="fas fa-calendar"></i> Date</h6>
                                            <p class="mb-0"><?php echo date('d/m/Y', strtotime($cours['date_cours'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6><i class="fas fa-clock"></i> Heure</h6>
                                            <p class="mb-0"><?php echo date('H:i', strtotime($cours['date_cours'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6><i class="fas fa-hourglass-half"></i> Durée</h6>
                                            <p class="mb-0"><?php echo formatDuree($cours['duree_minutes']); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card text-center">
                                        <div class="card-body">
                                            <h6><i class="fas fa-users"></i> Participants</h6>
                                            <p class="mb-0">
                                                <?php 
                                                $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_participants WHERE cours_id = ?");
                                                $stmt->execute([$id]);
                                                $count = $stmt->fetch();
                                                echo $count['total'] . '/' . $cours['max_participants'];
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if($cours['url_live'] && $cours['statut'] == 'en_cours'): ?>
                            <div class="mb-4">
                                <h6><i class="fas fa-video text-danger"></i> Cours en direct</h6>
                                <a href="<?php echo $cours['url_live']; ?>" class="btn btn-danger btn-lg" target="_blank">
                                    <i class="fas fa-play"></i> Rejoindre le cours maintenant
                                </a>
                                <?php if($cours['mot_de_passe']): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-key"></i> Mot de passe: <?php echo $cours['mot_de_passe']; ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if($cours['url_replay'] && $cours['statut'] == 'termine'): ?>
                            <div class="mb-4">
                                <h6><i class="fas fa-history text-info"></i> Replay disponible</h6>
                                <a href="<?php echo $cours['url_replay']; ?>" class="btn btn-info btn-lg" target="_blank">
                                    <i class="fas fa-play-circle"></i> Visionner l'enregistrement
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if($cours['presentation_pdf']): ?>
                            <div class="mb-4">
                                <h6><i class="fas fa-file-pdf text-danger"></i> Support de cours</h6>
                                <a href="<?php echo ROOT_PATH . '/uploads/cours/' . $cours['presentation_pdf']; ?>" class="btn btn-outline-danger" target="_blank">
                                    <i class="fas fa-download"></i> Télécharger le PDF
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Informations du cours</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <h6>Statut</h6>
                                        <span class="badge bg-<?php 
                                            switch($cours['statut']) {
                                                case 'en_cours': echo 'danger'; break;
                                                case 'planifie': echo 'warning'; break;
                                                case 'termine': echo 'success'; break;
                                                case 'annule': echo 'secondary'; break;
                                                default: echo 'light';
                                            }
                                        ?> fs-6 p-2">
                                            <?php echo ucfirst($cours['statut']); ?>
                                        </span>
                                    </div>

                                    <div class="mb-3">
                                        <h6>Site</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-building text-success"></i> 
                                            <?php echo htmlspecialchars($cours['site_nom']); ?>
                                        </p>
                                    </div>

                                    <div class="mb-3">
                                        <h6>Matière</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-book text-primary"></i> 
                                            <?php echo htmlspecialchars($cours['matiere_nom'] ?? 'Général'); ?>
                                        </p>
                                    </div>

                                    <div class="mb-3">
                                        <h6>Enseignant</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-user-tie text-secondary"></i> 
                                            <?php echo getEnseignantNom($cours['enseignant_id'], $db); ?>
                                        </p>
                                    </div>

                                    <div class="mb-3">
                                        <h6>Type de cours</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-chalkboard"></i> 
                                            <?php echo ucfirst($cours['type_cours']); ?>
                                        </p>
                                    </div>

                                    <div class="mb-3">
                                        <h6>Enregistrement automatique</h6>
                                        <p class="mb-0">
                                            <?php if($cours['enregistrement_auto']): ?>
                                            <i class="fas fa-check-circle text-success"></i> Activé
                                            <?php else: ?>
                                            <i class="fas fa-times-circle text-danger"></i> Désactivé
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Gestion des participants -->
                            <div class="card mt-3">
                                <div class="card-header">
                                    <h6 class="mb-0">Participants (<?php 
                                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_participants WHERE cours_id = ?");
                                        $stmt->execute([$id]);
                                        $count = $stmt->fetch();
                                        echo $count['total'];
                                    ?>)</h6>
                                </div>
                                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                    <?php $participants = getParticipants($id, $db); ?>
                                    <?php if(empty($participants)): ?>
                                    <p class="text-muted text-center mb-0">Aucun participant</p>
                                    <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach($participants as $participant): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($participant['nom'] . ' ' . $participant['prenom']); ?></strong><br>
                                                <small class="text-muted"><?php echo $participant['matricule']; ?></small><br>
                                                <small class="text-muted"><?php echo $participant['site_nom']; ?></small>
                                            </div>
                                            <span class="badge bg-<?php 
                                                switch($participant['statut']) {
                                                    case 'present': echo 'success'; break;
                                                    case 'absent': echo 'danger'; break;
                                                    case 'inscrit': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($participant['statut']); ?>
                                            </span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> Cours non trouvé.
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if($action == 'calendar'): ?>
            <!-- Calendrier des cours -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar"></i> Calendrier des cours - Tous les sites</h5>
                </div>
                <div class="card-body">
                    <div id="calendar" class="calendar-container"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js"></script>
    
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
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        <?php if($action == 'calendar'): ?>
        // Initialiser le calendrier FullCalendar
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: 'cours_en_ligne_action.php?action=get_calendar_events',
                eventClick: function(info) {
                    window.location.href = 'cours_en_ligne.php?action=view&id=' + info.event.id;
                },
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    meridiem: false
                }
            });
            calendar.render();
        }
        <?php endif; ?>
    });
    </script>
</body>
</html>