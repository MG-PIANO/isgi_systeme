<?php
// dashboard/surveillant/salles.php

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

// Vérifier le rôle (Surveillant Général = rôle 6)
if ($_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/dashboard/' . $_SESSION['role_name'] . '/dashboard.php');
    exit();
}

// Inclure la configuration
require_once ROOT_PATH . '/config/database.php';

// Initialiser la connexion
$db = Database::getInstance()->getConnection();

// Définir le titre de la page
$pageTitle = "Surveillant Général - Gestion des Salles";

// Récupérer l'ID du site du surveillant
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

// Fonctions utilitaires
function formatDateFr($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date == '0000-00-00 00:00:00') return 'Non renseigné';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Date invalide';
    return date($format, $timestamp);
}

function getStatutBadge($statut) {
    switch ($statut) {
        case 'disponible':
            return '<span class="badge bg-success">Disponible</span>';
        case 'occupee':
            return '<span class="badge bg-danger">Occupée</span>';
        case 'maintenance':
            return '<span class="badge bg-warning">Maintenance</span>';
        case 'reservee':
            return '<span class="badge bg-info">Réservée</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

function getTypeSalleBadge($type) {
    switch ($type) {
        case 'classe':
            return '<span class="badge bg-primary">Salle de classe</span>';
        case 'amphi':
            return '<span class="badge bg-secondary">Amphithéâtre</span>';
        case 'labo':
            return '<span class="badge bg-info">Laboratoire</span>';
        case 'bureau':
            return '<span class="badge bg-success">Bureau</span>';
        case 'salle_examen':
            return '<span class="badge bg-warning">Salle d\'examen</span>';
        case 'autre':
            return '<span class="badge bg-dark">Autre</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
    }
}

// Initialiser les variables
$salles = [];
$salles_statistiques = [
    'total' => 0,
    'disponibles' => 0,
    'occupees' => 0,
    'capacite_totale' => 0
];
$occupations_actuelles = [];
$salles_par_type = [];
$salles_reservations = [];

// Date et heure actuelles
$maintenant = date('Y-m-d H:i:s');
$aujourdhui = date('Y-m-d');

try {
    // 1. Récupérer toutes les salles du site
    $query = "SELECT * FROM salles WHERE site_id = :site_id ORDER BY nom";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $salles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Statistiques des salles
    if (!empty($salles)) {
        $salles_statistiques['total'] = count($salles);
        $capacite_totale = 0;
        
        foreach ($salles as $salle) {
            $capacite_totale += $salle['capacite'];
            
            if ($salle['statut'] == 'disponible') {
                $salles_statistiques['disponibles']++;
            } elseif ($salle['statut'] == 'occupee') {
                $salles_statistiques['occupees']++;
            }
        }
        
        $salles_statistiques['capacite_totale'] = $capacite_totale;
    }

    // 3. Vérifier les occupations actuelles via l'emploi du temps
    $query = "SELECT 
                edt.*,
                c.nom as classe_nom,
                m.nom as matiere_nom,
                CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              LEFT JOIN enseignants e ON edt.enseignant_id = e.id
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              WHERE edt.site_id = :site_id 
                AND edt.jour_semaine = :jour_semaine
                AND :heure_actuelle BETWEEN edt.heure_debut AND edt.heure_fin
              ORDER BY edt.salle, edt.heure_debut";
    
    $jour_semaine_fr = date('l');
    $jours_fr_en = [
        'Monday' => 'Lundi',
        'Tuesday' => 'Mardi',
        'Wednesday' => 'Mercredi',
        'Thursday' => 'Jeudi',
        'Friday' => 'Vendredi',
        'Saturday' => 'Samedi',
        'Sunday' => 'Dimanche'
    ];
    $jour_semaine = $jours_fr_en[$jour_semaine_fr] ?? 'Lundi';
    $heure_actuelle = date('H:i:s');
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':site_id' => $site_id,
        ':jour_semaine' => $jour_semaine,
        ':heure_actuelle' => $heure_actuelle
    ]);
    $occupations_actuelles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Récupérer les salles par type
    $query = "SELECT 
                type_salle,
                COUNT(*) as nombre,
                SUM(capacite) as capacite_totale
              FROM salles 
              WHERE site_id = :site_id
              GROUP BY type_salle
              ORDER BY nombre DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $salles_par_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Vérifier les réservations pour aujourd'hui
    $query = "SELECT 
                r.*,
                s.nom as salle_nom,
                CONCAT(u.nom, ' ', u.prenom) as reserve_par_nom,
                c.nom as classe_nom
              FROM reservations_salles r
              LEFT JOIN salles s ON r.salle_id = s.id
              LEFT JOIN utilisateurs u ON r.reserve_par = u.id
              LEFT JOIN classes c ON r.classe_id = c.id
              WHERE s.site_id = :site_id 
                AND DATE(r.date_debut) = :aujourdhui
                AND r.statut = 'confirmee'
              ORDER BY r.date_debut";
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id, ':aujourdhui' => $aujourdhui]);
    $salles_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
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
    
    /* Boutons */
    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
        background-color: #1a252f;
        border-color: #1a252f;
    }
    
    /* Salle card */
    .salle-card {
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .salle-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .salle-icon {
        font-size: 2rem;
        margin-bottom: 10px;
    }
    
    .salle-capacite {
        font-size: 0.9rem;
        color: var(--text-muted);
    }
    
    /* Calendar */
    .fc {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc-toolbar-title {
        color: var(--text-color) !important;
    }
    
    .fc-col-header-cell-cushion {
        color: var(--text-color) !important;
    }
    
    .fc-daygrid-day-number {
        color: var(--text-color) !important;
    }
    
    .fc-daygrid-day.fc-day-today {
        background-color: rgba(52, 152, 219, 0.1) !important;
    }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h5 class="mt-2 mb-1">SURVEILLANT</h5>
                <div class="user-role">Surveillant Général</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Surveillant'); ?></p>
                <small>Gestion des Salles</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Présences</div>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Toutes les Présences</span>
                    </a>
                    <a href="scanner_qr.php" class="nav-link">
                        <i class="fas fa-qrcode"></i>
                        <span>Scanner QR Code</span>
                    </a>
                    <a href="generer_qr.php" class="nav-link">
                        <i class="fas fa-barcode"></i>
                        <span>Générer QR Code</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste Étudiants</span>
                    </a>
                    <a href="classe_presence.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Par Classe</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Salles & Horaires</div>
                    <a href="salles.php" class="nav-link active">
                        <i class="fas fa-door-open"></i>
                        <span>Salles de Classe</span>
                    </a>
                    <a href="emploi_du_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Rapports</div>
                    <a href="rapports_presence.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports de Présence</span>
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
                            <i class="fas fa-door-open me-2"></i>
                            Gestion des Salles de Classe
                        </h2>
                        <p class="text-muted mb-0">
                            <i class="fas fa-building"></i> 
                            Site: <?php echo $_SESSION['site_name'] ?? 'Non spécifié'; ?> - 
                            <i class="fas fa-calendar-day"></i> 
                            <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="window.location.href='#reserver'">
                            <i class="fas fa-calendar-plus"></i> Réserver une salle
                        </button>
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques des Salles -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-door-closed"></i>
                        </div>
                        <div class="stat-value"><?php echo $salles_statistiques['total']; ?></div>
                        <div class="stat-label">Salles Total</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $salles_statistiques['disponibles']; ?></div>
                        <div class="stat-label">Salles Disponibles</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $salles_statistiques['occupees']; ?></div>
                        <div class="stat-label">Salles Occupées</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo $salles_statistiques['capacite_totale']; ?></div>
                        <div class="stat-label">Capacité Totale</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Salles Actuellement Occupées -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2"></i>
                                Occupations Actuelles (<?php echo date('H:i'); ?>)
                            </h5>
                            <span class="badge bg-primary">
                                <?php echo $jour_semaine; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if(empty($occupations_actuelles)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> 
                                Aucune salle n'est actuellement occupée selon l'emploi du temps.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Salle</th>
                                            <th>Classe</th>
                                            <th>Matière</th>
                                            <th>Enseignant</th>
                                            <th>Horaire</th>
                                            <th>Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($occupations_actuelles as $occupation): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($occupation['salle'] ?? 'Non spécifié'); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($occupation['classe_nom'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($occupation['matiere_nom'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($occupation['enseignant_nom'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php echo date('H:i', strtotime($occupation['heure_debut'])); ?> - 
                                                <?php echo date('H:i', strtotime($occupation['heure_fin'])); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">Cours régulier</span>
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
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Répartition par Type
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($salles_par_type)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune salle enregistrée.
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($salles_par_type as $type): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($type['type_salle']); ?></strong>
                                        <br>
                                        <small class="text-muted">Capacité: <?php echo $type['capacite_totale']; ?> places</small>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">
                                        <?php echo $type['nombre']; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Liste des Salles -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Salles
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="searchSalle" placeholder="Rechercher une salle...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterType">
                                <option value="">Tous les types</option>
                                <option value="classe">Salle de classe</option>
                                <option value="amphi">Amphithéâtre</option>
                                <option value="labo">Laboratoire</option>
                                <option value="bureau">Bureau</option>
                                <option value="salle_examen">Salle d'examen</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterStatut">
                                <option value="">Tous les statuts</option>
                                <option value="disponible">Disponible</option>
                                <option value="occupee">Occupée</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="reservee">Réservée</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                                <i class="fas fa-times"></i> Réinitialiser
                            </button>
                        </div>
                    </div>
                    
                    <div class="row" id="sallesGrid">
                        <?php if(empty($salles)): ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune salle enregistrée dans ce site.
                            </div>
                        </div>
                        <?php else: ?>
                        <?php foreach($salles as $salle): ?>
                        <div class="col-md-4 mb-3 salle-item" 
                             data-type="<?php echo htmlspecialchars($salle['type_salle']); ?>"
                             data-statut="<?php echo htmlspecialchars($salle['statut']); ?>"
                             data-nom="<?php echo htmlspecialchars(strtolower($salle['nom'])); ?>">
                            <div class="card salle-card h-100" onclick="voirSalleDetail(<?php echo $salle['id']; ?>)">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <i class="fas fa-door-open me-2"></i>
                                                <?php echo htmlspecialchars($salle['nom']); ?>
                                            </h5>
                                            <p class="card-text text-muted mb-0">
                                                <small><?php echo htmlspecialchars($salle['batiment'] ?? 'Non spécifié'); ?></small>
                                            </p>
                                        </div>
                                        <div>
                                            <?php echo getStatutBadge($salle['statut']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <?php echo getTypeSalleBadge($salle['type_salle']); ?>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="salle-icon text-primary">
                                                    <i class="fas fa-users"></i>
                                                </div>
                                                <div class="salle-capacite">
                                                    <strong><?php echo $salle['capacite']; ?></strong> places
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="salle-icon text-info">
                                                    <i class="fas fa-ruler-combined"></i>
                                                </div>
                                                <div class="salle-capacite">
                                                    <strong><?php echo $salle['superficie'] ?? '?'; ?></strong> m²
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            <?php echo htmlspecialchars($salle['description'] ?? 'Aucune description'); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <div class="d-flex justify-content-between">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="event.stopPropagation(); voirOccupation('<?php echo htmlspecialchars($salle['nom']); ?>')">
                                            <i class="fas fa-calendar-alt"></i> Horaire
                                        </button>
                                        <button class="btn btn-sm btn-outline-success" 
                                                onclick="event.stopPropagation(); reserverSalle(<?php echo $salle['id']; ?>)">
                                            <i class="fas fa-calendar-plus"></i> Réserver
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Réservations pour Aujourd'hui -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-day me-2"></i>
                        Réservations pour Aujourd'hui (<?php echo date('d/m/Y'); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($salles_reservations)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucune réservation pour aujourd'hui.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Salle</th>
                                    <th>Réservé par</th>
                                    <th>Classe/Événement</th>
                                    <th>Horaire</th>
                                    <th>Motif</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($salles_reservations as $reservation): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($reservation['salle_nom']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($reservation['reserve_par_nom']); ?></td>
                                    <td>
                                        <?php if($reservation['classe_nom']): ?>
                                        <?php echo htmlspecialchars($reservation['classe_nom']); ?>
                                        <?php else: ?>
                                        <?php echo htmlspecialchars($reservation['evenement'] ?? 'Événement'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatDateFr($reservation['date_debut'], 'H:i'); ?> - 
                                        <?php echo formatDateFr($reservation['date_fin'], 'H:i'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($reservation['motif'] ?? '-'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info" 
                                                onclick="voirReservation(<?php echo $reservation['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning" 
                                                onclick="annulerReservation(<?php echo $reservation['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Section 5: Formulaire de Réservation -->
            <div class="card mb-4" id="reserver">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-plus me-2"></i>
                        Réserver une Salle
                    </h5>
                </div>
                <div class="card-body">
                    <form id="reservationForm">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Salle</label>
                                <select class="form-select" id="salle_id" required>
                                    <option value="">Sélectionner une salle</option>
                                    <?php foreach($salles as $salle): ?>
                                    <?php if($salle['statut'] == 'disponible'): ?>
                                    <option value="<?php echo $salle['id']; ?>">
                                        <?php echo htmlspecialchars($salle['nom']); ?> 
                                        (<?php echo $salle['capacite']; ?> places)
                                    </option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" id="reservation_date" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Heure de début</label>
                                <input type="time" class="form-control" id="heure_debut" required>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Heure de fin</label>
                                <input type="time" class="form-control" id="heure_fin" required>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Type de réservation</label>
                                <select class="form-select" id="type_reservation">
                                    <option value="cours">Cours</option>
                                    <option value="reunion">Réunion</option>
                                    <option value="examen">Examen</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Classe (optionnel)</label>
                                <select class="form-select" id="classe_id">
                                    <option value="">Sélectionner une classe</option>
                                    <?php 
                                    // Récupérer les classes du site
                                    try {
                                        $query = "SELECT c.* FROM classes c 
                                                  WHERE c.site_id = :site_id 
                                                  AND c.annee_academique_id IN (
                                                    SELECT id FROM annees_academiques WHERE statut = 'active'
                                                  )
                                                  ORDER BY c.nom";
                                        $stmt = $db->prepare($query);
                                        $stmt->execute([':site_id' => $site_id]);
                                        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach($classes as $classe):
                                    ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo htmlspecialchars($classe['nom']); ?>
                                    </option>
                                    <?php 
                                        endforeach;
                                    } catch (Exception $e) {
                                        // Ne rien afficher en cas d'erreur
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Motif/Description</label>
                                <textarea class="form-control" id="motif" rows="3" 
                                          placeholder="Description de la réservation..." required></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Enregistrer la réservation
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetReservationForm()">
                                    <i class="fas fa-times me-2"></i>Annuler
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Section 6: Calendrier des Salles -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Calendrier des Réservations
                    </h5>
                </div>
                <div class="card-body">
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour détails de salle -->
    <div class="modal fade" id="salleDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de la Salle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="salleDetailContent">
                    <!-- Contenu chargé dynamiquement -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour occupation -->
    <div class="modal fade" id="occupationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Occupation de la Salle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="occupationContent">
                    <!-- Contenu chargé dynamiquement -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
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
        
        // Initialiser le calendrier
        initializeCalendar();
        
        // Initialiser les filtres
        initializeFilters();
        
        // Initialiser le formulaire de réservation
        initializeReservationForm();
    });
    
    // Initialiser le calendrier FullCalendar
    function initializeCalendar() {
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;
        
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: 'ajax/get_reservations.php?site_id=<?php echo $site_id; ?>',
            eventClick: function(info) {
                voirReservation(info.event.id);
            },
            eventColor: '#3498db',
            eventTextColor: '#ffffff',
            height: 500,
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5, 6], // Lundi à Samedi
                startTime: '08:00',
                endTime: '20:00'
            }
        });
        
        calendar.render();
    }
    
    // Initialiser les filtres de recherche
    function initializeFilters() {
        const searchInput = document.getElementById('searchSalle');
        const filterType = document.getElementById('filterType');
        const filterStatut = document.getElementById('filterStatut');
        const salleItems = document.querySelectorAll('.salle-item');
        
        function filterSalles() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedType = filterType.value;
            const selectedStatut = filterStatut.value;
            
            salleItems.forEach(item => {
                const type = item.getAttribute('data-type');
                const statut = item.getAttribute('data-statut');
                const nom = item.getAttribute('data-nom');
                
                const matchesSearch = searchTerm === '' || 
                    nom.includes(searchTerm);
                
                const matchesType = selectedType === '' || 
                    type === selectedType;
                
                const matchesStatut = selectedStatut === '' || 
                    statut === selectedStatut;
                
                if (matchesSearch && matchesType && matchesStatut) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        searchInput.addEventListener('input', filterSalles);
        filterType.addEventListener('change', filterSalles);
        filterStatut.addEventListener('change', filterSalles);
    }
    
    // Réinitialiser les filtres
    function resetFilters() {
        document.getElementById('searchSalle').value = '';
        document.getElementById('filterType').value = '';
        document.getElementById('filterStatut').value = '';
        
        const salleItems = document.querySelectorAll('.salle-item');
        salleItems.forEach(item => {
            item.style.display = 'block';
        });
    }
    
    // Voir les détails d'une salle
    function voirSalleDetail(salleId) {
        fetch('ajax/get_salle_detail.php?id=' + salleId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('salleDetailContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('salleDetailModal'));
                modal.show();
            })
            .catch(error => {
                alert('Erreur de chargement des détails');
                console.error('Erreur:', error);
            });
    }
    
    // Voir l'occupation d'une salle
    function voirOccupation(salleNom) {
        fetch('ajax/get_salle_occupation.php?nom=' + encodeURIComponent(salleNom) + '&site_id=<?php echo $site_id; ?>')
            .then(response => response.text())
            .then(html => {
                document.getElementById('occupationContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('occupationModal'));
                modal.show();
            })
            .catch(error => {
                alert('Erreur de chargement de l\'occupation');
                console.error('Erreur:', error);
            });
    }
    
    // Réserver une salle
    function reserverSalle(salleId) {
        document.getElementById('salle_id').value = salleId;
        document.getElementById('reservationForm').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('reservation_date').focus();
    }
    
    // Initialiser le formulaire de réservation
    function initializeReservationForm() {
        const form = document.getElementById('reservationForm');
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const salleId = document.getElementById('salle_id').value;
            const date = document.getElementById('reservation_date').value;
            const heureDebut = document.getElementById('heure_debut').value;
            const heureFin = document.getElementById('heure_fin').value;
            const type = document.getElementById('type_reservation').value;
            const classeId = document.getElementById('classe_id').value;
            const motif = document.getElementById('motif').value;
            
            if (!salleId || !date || !heureDebut || !heureFin || !motif) {
                alert('Veuillez remplir tous les champs obligatoires');
                return;
            }
            
            if (heureFin <= heureDebut) {
                alert('L\'heure de fin doit être après l\'heure de début');
                return;
            }
            
            const formData = new FormData();
            formData.append('salle_id', salleId);
            formData.append('date', date);
            formData.append('heure_debut', heureDebut);
            formData.append('heure_fin', heureFin);
            formData.append('type_reservation', type);
            formData.append('classe_id', classeId);
            formData.append('motif', motif);
            formData.append('reserve_par', <?php echo $surveillant_id; ?>);
            formData.append('site_id', <?php echo $site_id; ?>);
            
            fetch('ajax/reserver_salle.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Réservation enregistrée avec succès !');
                    resetReservationForm();
                    location.reload();
                } else {
                    alert('Erreur: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erreur de connexion');
                console.error('Erreur:', error);
            });
        });
        
        // Définir la date minimale à aujourd'hui
        document.getElementById('reservation_date').min = new Date().toISOString().split('T')[0];
    }
    
    // Réinitialiser le formulaire de réservation
    function resetReservationForm() {
        document.getElementById('reservationForm').reset();
    }
    
    // Voir une réservation
    function voirReservation(reservationId) {
        fetch('ajax/get_reservation_detail.php?id=' + reservationId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('occupationContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('occupationModal'));
                modal.show();
            })
            .catch(error => {
                alert('Erreur de chargement des détails');
                console.error('Erreur:', error);
            });
    }
    
    // Annuler une réservation
    function annulerReservation(reservationId) {
        if (confirm('Voulez-vous vraiment annuler cette réservation ?')) {
            fetch('ajax/annuler_reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${reservationId}&annule_par=<?php echo $surveillant_id; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Réservation annulée avec succès !');
                    location.reload();
                } else {
                    alert('Erreur: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erreur de connexion');
                console.error('Erreur:', error);
            });
        }
    }
    
    // Auto-refresh toutes les 30 secondes pour les occupations
    setInterval(function() {
        // Recharger les occupations actuelles
        const now = new Date();
        const heureActuelle = now.toTimeString().split(' ')[0];
        console.log('Vérification des occupations à ' + heureActuelle);
        
        // Vous pourriez ajouter ici une requête AJAX pour mettre à jour
        // les occupations sans recharger toute la page
    }, 30000);
    </script>
</body>
</html>