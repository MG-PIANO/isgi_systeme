<?php
// dashboard/dac/calendrier_academique.php

// Vérifier si une session est déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si ROOT_PATH est déjà défini
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__DIR__)));
}

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur a le rôle DAC (ID 5)
if ($_SESSION['role_id'] != 5) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Accès Refusé</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body style='background: #f8f9fa; min-height: 100vh; display: flex; align-items: center; justify-content: center;'>
        <div class='card shadow-lg' style='width: 100%; max-width: 500px;'>
            <div class='card-header bg-danger text-white'>
                <h4 class='mb-0'><i class='fas fa-ban'></i> Accès Refusé</h4>
            </div>
            <div class='card-body text-center'>
                <div class='alert alert-warning'>
                    <h5>Accès réservé au DAC</h5>
                    <p class='mb-0'>Cette page est réservée au Directeur des Affaires Académiques.</p>
                </div>
                <a href='" . ROOT_PATH . "/dashboard/' class='btn btn-primary'>
                    <i class='fas fa-tachometer-alt'></i> Retour au Dashboard
                </a>
            </div>
        </div>
    </body>
    </html>";
    exit();
}

// Inclure la configuration de la base de données
$config_path = ROOT_PATH . '/config/database.php';
if (!file_exists($config_path)) {
    die("Erreur: Fichier de configuration introuvable: $config_path");
}

require_once $config_path;

try {
    // Obtenir la connexion
    $db = Database::getInstance()->getConnection();
    
    // Tester la connexion
    $db->query("SELECT 1");
    
} catch (Exception $e) {
    die("<div style='padding:20px;font-family:Arial,sans-serif;'>
            <h2 style='color:#c00;'>Erreur de Connexion</h2>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
            <p>Vérifiez vos paramètres de connexion dans config/database.php</p>
        </div>");
}

// Définir le titre de la page
$pageTitle = "DAC - Calendrier Académique";

// Récupérer l'ID du site de l'utilisateur
$site_id = $_SESSION['site_id'] ?? null;

// Fonctions utilitaires
function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00') return '';
    $timestamp = strtotime($date);
    if ($timestamp === false) return '';
    return date($format, $timestamp);
}

function getStatutBadge($statut) {
    $badges = [
        'planifie' => 'warning',
        'en_cours' => 'info',
        'termine' => 'success',
        'annule' => 'danger'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    $libelles = [
        'planifie' => 'Planifié',
        'en_cours' => 'En cours',
        'termine' => 'Terminé',
        'annule' => 'Annulé'
    ];
    
    $libelle = $libelles[$statut] ?? ucfirst($statut);
    return '<span class="badge bg-' . $color . '">' . $libelle . '</span>';
}

// Variables pour les actions
$action = $_GET['action'] ?? 'list';
$calendrier_id = $_GET['id'] ?? null;
$annee_id = $_GET['annee_id'] ?? null;

// Messages de succès/erreur
$success_message = null;
$error_message = null;

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['ajouter_calendrier'])) {
            // Ajouter un nouveau calendrier
            $query = "INSERT INTO calendrier_academique 
                     (site_id, annee_academique_id, semestre, type_rentree, 
                      date_debut_cours, date_fin_cours, date_debut_dst, date_fin_dst,
                      date_debut_recherche, date_fin_recherche, date_debut_conge_etude,
                      date_fin_conge_etude, date_debut_examens, date_fin_examens,
                      date_reprise_cours, date_debut_stage, date_fin_stage,
                      statut, observations, publie, cree_par)
                     VALUES (:site_id, :annee_id, :semestre, :type_rentree,
                             :date_debut_cours, :date_fin_cours, :date_debut_dst, :date_fin_dst,
                             :date_debut_recherche, :date_fin_recherche, :date_debut_conge_etude,
                             :date_fin_conge_etude, :date_debut_examens, :date_fin_examens,
                             :date_reprise_cours, :date_debut_stage, :date_fin_stage,
                             :statut, :observations, :publie, :cree_par)";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                'site_id' => $site_id,
                'annee_id' => $_POST['annee_academique_id'],
                'semestre' => $_POST['semestre'],
                'type_rentree' => $_POST['type_rentree'],
                'date_debut_cours' => $_POST['date_debut_cours'],
                'date_fin_cours' => $_POST['date_fin_cours'],
                'date_debut_dst' => $_POST['date_debut_dst'] ?: null,
                'date_fin_dst' => $_POST['date_fin_dst'] ?: null,
                'date_debut_recherche' => $_POST['date_debut_recherche'] ?: null,
                'date_fin_recherche' => $_POST['date_fin_recherche'] ?: null,
                'date_debut_conge_etude' => $_POST['date_debut_conge_etude'] ?: null,
                'date_fin_conge_etude' => $_POST['date_fin_conge_etude'] ?: null,
                'date_debut_examens' => $_POST['date_debut_examens'] ?: null,
                'date_fin_examens' => $_POST['date_fin_examens'] ?: null,
                'date_reprise_cours' => $_POST['date_reprise_cours'] ?: null,
                'date_debut_stage' => $_POST['date_debut_stage'] ?: null,
                'date_fin_stage' => $_POST['date_fin_stage'] ?: null,
                'statut' => $_POST['statut'],
                'observations' => $_POST['observations'] ?: null,
                'publie' => isset($_POST['publie']) ? 1 : 0,
                'cree_par' => $_SESSION['user_id']
            ]);
            
            if ($result) {
                $success_message = "Calendrier académique ajouté avec succès";
            } else {
                $error_message = "Erreur lors de l'ajout du calendrier";
            }
            
        } elseif (isset($_POST['modifier_calendrier'])) {
            // Modifier un calendrier existant
            $query = "UPDATE calendrier_academique 
                     SET annee_academique_id = :annee_id,
                         semestre = :semestre,
                         type_rentree = :type_rentree,
                         date_debut_cours = :date_debut_cours,
                         date_fin_cours = :date_fin_cours,
                         date_debut_dst = :date_debut_dst,
                         date_fin_dst = :date_fin_dst,
                         date_debut_recherche = :date_debut_recherche,
                         date_fin_recherche = :date_fin_recherche,
                         date_debut_conge_etude = :date_debut_conge_etude,
                         date_fin_conge_etude = :date_fin_conge_etude,
                         date_debut_examens = :date_debut_examens,
                         date_fin_examens = :date_fin_examens,
                         date_reprise_cours = :date_reprise_cours,
                         date_debut_stage = :date_debut_stage,
                         date_fin_stage = :date_fin_stage,
                         statut = :statut,
                         observations = :observations,
                         publie = :publie,
                         modifie_par = :modifie_par,
                         date_modification = NOW()
                     WHERE id = :id AND site_id = :site_id";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                'id' => $_POST['calendrier_id'],
                'site_id' => $site_id,
                'annee_id' => $_POST['annee_academique_id'],
                'semestre' => $_POST['semestre'],
                'type_rentree' => $_POST['type_rentree'],
                'date_debut_cours' => $_POST['date_debut_cours'],
                'date_fin_cours' => $_POST['date_fin_cours'],
                'date_debut_dst' => $_POST['date_debut_dst'] ?: null,
                'date_fin_dst' => $_POST['date_fin_dst'] ?: null,
                'date_debut_recherche' => $_POST['date_debut_recherche'] ?: null,
                'date_fin_recherche' => $_POST['date_fin_recherche'] ?: null,
                'date_debut_conge_etude' => $_POST['date_debut_conge_etude'] ?: null,
                'date_fin_conge_etude' => $_POST['date_fin_conge_etude'] ?: null,
                'date_debut_examens' => $_POST['date_debut_examens'] ?: null,
                'date_fin_examens' => $_POST['date_fin_examens'] ?: null,
                'date_reprise_cours' => $_POST['date_reprise_cours'] ?: null,
                'date_debut_stage' => $_POST['date_debut_stage'] ?: null,
                'date_fin_stage' => $_POST['date_fin_stage'] ?: null,
                'statut' => $_POST['statut'],
                'observations' => $_POST['observations'] ?: null,
                'publie' => isset($_POST['publie']) ? 1 : 0,
                'modifie_par' => $_SESSION['user_id']
            ]);
            
            if ($result) {
                $success_message = "Calendrier académique modifié avec succès";
            } else {
                $error_message = "Erreur lors de la modification du calendrier";
            }
            
        } elseif (isset($_POST['supprimer_calendrier'])) {
            // Supprimer un calendrier
            $query = "DELETE FROM calendrier_academique 
                     WHERE id = :id AND site_id = :site_id";
            
            $stmt = $db->prepare($query);
            $result = $stmt->execute([
                'id' => $_POST['calendrier_id'],
                'site_id' => $site_id
            ]);
            
            if ($result) {
                $success_message = "Calendrier académique supprimé avec succès";
            } else {
                $error_message = "Erreur lors de la suppression du calendrier";
            }
        }
        
    } catch (PDOException $e) {
        $error_message = "Erreur de base de données: " . $e->getMessage();
    }
}

// Récupérer les données
try {
    // Récupérer le site
    $query_site = "SELECT nom FROM sites WHERE id = :site_id";
    $stmt_site = $db->prepare($query_site);
    $stmt_site->execute(['site_id' => $site_id]);
    $site = $stmt_site->fetch(PDO::FETCH_ASSOC);
    $site_nom = $site['nom'] ?? 'Site inconnu';
    
    // Récupérer les années académiques
    $query_annees = "SELECT * FROM annees_academiques 
                    WHERE site_id = :site_id 
                    ORDER BY date_debut DESC";
    $stmt_annees = $db->prepare($query_annees);
    $stmt_annees->execute(['site_id' => $site_id]);
    $annees = $stmt_annees->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les calendriers académiques
    $where_conditions = ["ca.site_id = :site_id"];
    $params = ['site_id' => $site_id];
    
    if ($annee_id) {
        $where_conditions[] = "ca.annee_academique_id = :annee_id";
        $params['annee_id'] = $annee_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query_calendriers = "SELECT ca.*, aa.libelle as annee_libelle,
                         CONCAT(u.nom, ' ', u.prenom) as createur_nom
                         FROM calendrier_academique ca
                         JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                         LEFT JOIN utilisateurs u ON ca.cree_par = u.id
                         WHERE $where_clause
                         ORDER BY ca.date_debut_cours DESC, ca.semestre";
    
    $stmt_calendriers = $db->prepare($query_calendriers);
    $stmt_calendriers->execute($params);
    $calendriers = $stmt_calendriers->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer un calendrier spécifique pour modification
    $calendrier = null;
    if ($calendrier_id && $action == 'edit') {
        $query_calendrier = "SELECT ca.*, aa.libelle as annee_libelle
                           FROM calendrier_academique ca
                           JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                           WHERE ca.id = :id AND ca.site_id = :site_id";
        
        $stmt_calendrier = $db->prepare($query_calendrier);
        $stmt_calendrier->execute(['id' => $calendrier_id, 'site_id' => $site_id]);
        $calendrier = $stmt_calendrier->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    $error_message = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// Démarrer l'output buffering
ob_start();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- FullCalendar CSS -->
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --danger-color: #e74c3c;
        --bg-color: #f8f9fa;
        --card-bg: #ffffff;
        --text-color: #212529;
        --border-color: #dee2e6;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
    }
    
    .main-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 20px 0;
        margin-bottom: 30px;
    }
    
    .secondary-nav {
        background-color: var(--card-bg);
        border-bottom: 1px solid var(--border-color);
        padding: 10px 0;
        margin-bottom: 20px;
    }
    
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 20px;
    }
    
    .card-header {
        background-color: rgba(var(--primary-color), 0.1);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
        font-weight: 600;
    }
    
    .badge {
        padding: 6px 12px;
        font-weight: 500;
    }
    
    .table th {
        background-color: var(--info-color);
        color: white;
        border: none;
    }
    
    .fc-event {
        cursor: pointer;
    }
    
    .event-cours { background-color: var(--info-color); }
    .event-dst { background-color: var(--warning-color); }
    .event-recherche { background-color: var(--secondary-color); }
    .event-conge { background-color: var(--success-color); }
    .event-examen { background-color: var(--danger-color); }
    .event-stage { background-color: var(--primary-color); }
    
    .timeline-item {
        border-left: 3px solid var(--info-color);
        padding-left: 15px;
        margin-bottom: 20px;
        position: relative;
    }
    
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -6px;
        top: 0;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--info-color);
    }
    
    .stat-card {
        text-align: center;
        padding: 20px;
        border-radius: 10px;
        color: white;
        margin-bottom: 20px;
    }
    
    .stat-card.planifie { background-color: var(--warning-color); }
    .stat-card.en-cours { background-color: var(--info-color); }
    .stat-card.termine { background-color: var(--success-color); }
    .stat-card.total { background-color: var(--primary-color); }
    
    @media (max-width: 768px) {
        .fc-toolbar {
            flex-direction: column;
        }
        
        .fc-toolbar-chunk {
            margin-bottom: 10px;
        }
    }
    </style>
</head>
<body>
    <!-- Header principal -->
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-2">
                        <i class="fas fa-calendar-alt"></i> ISGI - Calendrier Académique
                    </h1>
                    <p class="mb-0 opacity-75">
                        Directeur des Affaires Académiques | <?php echo htmlspecialchars($site_nom); ?>
                    </p>
                </div>
                <div class="text-end">
                    <div class="badge bg-light text-dark mb-2">
                        Année: <?php echo $annee_id ? 'Filtrée' : 'Toutes'; ?>
                    </div>
                    <br>
                    <a href="<?php echo ROOT_PATH; ?>/dashboard/dac/" class="btn btn-light btn-sm">
                        <i class="fas fa-tachometer-alt"></i> Retour DAC
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Navigation secondaire -->
    <nav class="secondary-nav">
        <div class="container">
            <div class="d-flex flex-wrap gap-2">
                <a href="calendrier_academique.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list"></i> Liste calendriers
                </a>
                <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCalendrierModal">
                    <i class="fas fa-plus"></i> Nouveau calendrier
                </button>
                <a href="calendrier_examens.php" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-calendar-check"></i> Calendrier examens
                </a>
                <div class="ms-auto">
                    <form method="get" class="d-inline">
                        <select name="annee_id" class="form-select form-select-sm d-inline w-auto" onchange="this.form.submit()">
                            <option value="">Toutes les années</option>
                            <?php foreach ($annees as $annee): ?>
                            <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['libelle']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Contenu principal -->
    <main class="container">
        <!-- Messages d'alerte -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card total">
                    <h4><?php echo count($calendriers); ?></h4>
                    <p class="mb-0">Calendriers</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card planifie">
                    <h4><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'planifie')); ?></h4>
                    <p class="mb-0">Planifiés</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card en-cours">
                    <h4><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'en_cours')); ?></h4>
                    <p class="mb-0">En cours</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card termine">
                    <h4><?php echo count(array_filter($calendriers, fn($c) => $c['statut'] == 'termine')); ?></h4>
                    <p class="mb-0">Terminés</p>
                </div>
            </div>
        </div>
        
        <!-- Onglets -->
        <ul class="nav nav-tabs mb-4" id="calendrierTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="liste-tab" data-bs-toggle="tab" data-bs-target="#liste" type="button">
                    <i class="fas fa-table"></i> Liste
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="calendrier-tab" data-bs-toggle="tab" data-bs-target="#calendrier" type="button">
                    <i class="fas fa-calendar"></i> Vue Calendrier
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button">
                    <i class="fas fa-stream"></i> Timeline
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="calendrierTabContent">
            <!-- Tab 1: Liste des calendriers -->
            <div class="tab-pane fade show active" id="liste" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-day"></i> Calendriers Académiques
                        </h5>
                        <span class="badge bg-info">
                            <?php echo count($calendriers); ?> résultat(s)
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($calendriers)): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle"></i> Aucun calendrier académique trouvé.
                            <p class="mt-2">
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCalendrierModal">
                                    <i class="fas fa-plus"></i> Créer le premier calendrier
                                </button>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Année</th>
                                        <th>Semestre</th>
                                        <th>Type rentrée</th>
                                        <th>Période cours</th>
                                        <th>Événements</th>
                                        <th>Statut</th>
                                        <th>Publié</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($calendriers as $cal): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cal['annee_libelle']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo formatDateFr($cal['date_debut_cours']) . ' - ' . formatDateFr($cal['date_fin_cours']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">Semestre <?php echo $cal['semestre']; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($cal['type_rentree']); ?></td>
                                        <td>
                                            <small>
                                                Cours: <?php echo formatDateFr($cal['date_debut_cours']) . ' → ' . formatDateFr($cal['date_fin_cours']); ?>
                                                <br>
                                                <?php if ($cal['date_debut_examens']): ?>
                                                Examens: <?php echo formatDateFr($cal['date_debut_examens']) . ' → ' . formatDateFr($cal['date_fin_examens']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            $events_count = 0;
                                            if ($cal['date_debut_dst']) $events_count++;
                                            if ($cal['date_debut_recherche']) $events_count++;
                                            if ($cal['date_debut_examens']) $events_count++;
                                            if ($cal['date_debut_stage']) $events_count++;
                                            ?>
                                            <span class="badge bg-secondary"><?php echo $events_count; ?> événement(s)</span>
                                        </td>
                                        <td><?php echo getStatutBadge($cal['statut']); ?></td>
                                        <td>
                                            <?php if ($cal['publie'] == 1): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Oui</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-times"></i> Non</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="calendrier_academique.php?action=edit&id=<?php echo $cal['id']; ?>" 
                                                   class="btn btn-outline-primary" title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="calendrier_academique.php?action=view&id=<?php echo $cal['id']; ?>" 
                                                   class="btn btn-outline-info" title="Voir détails">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $cal['id']; ?>"
                                                        title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Modal de suppression -->
                                            <div class="modal fade" id="deleteModal<?php echo $cal['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="supprimer_calendrier" value="1">
                                                            <input type="hidden" name="calendrier_id" value="<?php echo $cal['id']; ?>">
                                                            
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Confirmer la suppression</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="alert alert-danger">
                                                                    <i class="fas fa-exclamation-triangle"></i>
                                                                    <strong>Attention !</strong> Cette action est irréversible.
                                                                </div>
                                                                <p>Êtes-vous sûr de vouloir supprimer le calendrier :</p>
                                                                <p><strong><?php echo htmlspecialchars($cal['annee_libelle']); ?> - Semestre <?php echo $cal['semestre']; ?></strong></p>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                                <button type="submit" class="btn btn-danger">Confirmer la suppression</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
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
            
            <!-- Tab 2: Vue calendrier -->
            <div class="tab-pane fade" id="calendrier" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt"></i> Vue Calendrier
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>
            
            <!-- Tab 3: Timeline -->
            <div class="tab-pane fade" id="timeline" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-stream"></i> Timeline des Événements
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($calendriers)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun événement à afficher.
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <?php foreach ($calendriers as $cal): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($cal['annee_libelle']); ?> - 
                                            Semestre <?php echo $cal['semestre']; ?>
                                            <span class="float-end"><?php echo getStatutBadge($cal['statut']); ?></span>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-chalkboard-teacher text-info"></i> Période de Cours</h6>
                                            <p class="mb-1">
                                                <?php echo formatDateFr($cal['date_debut_cours']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                            </p>
                                            <small class="text-muted"><?php echo $cal['type_rentree']; ?> rentrée</small>
                                        </div>
                                        
                                        <?php if ($cal['date_debut_dst']): ?>
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-file-alt text-warning"></i> DST</h6>
                                            <p class="mb-0">
                                                <?php echo formatDateFr($cal['date_debut_dst']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_dst']); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cal['date_debut_recherche']): ?>
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-search text-secondary"></i> Devoir de Recherche</h6>
                                            <p class="mb-0">
                                                <?php echo formatDateFr($cal['date_debut_recherche']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_recherche']); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cal['date_debut_conge_etude']): ?>
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-umbrella-beach text-success"></i> Congé d'Étude</h6>
                                            <p class="mb-0">
                                                <?php echo formatDateFr($cal['date_debut_conge_etude']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_conge_etude']); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cal['date_debut_examens']): ?>
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-graduation-cap text-danger"></i> Examens de Fin de Semestre</h6>
                                            <p class="mb-0">
                                                <?php echo formatDateFr($cal['date_debut_examens']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_examens']); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cal['date_debut_stage']): ?>
                                        <div class="timeline-item">
                                            <h6><i class="fas fa-briefcase text-primary"></i> Stage Professionnel</h6>
                                            <p class="mb-0">
                                                <?php echo formatDateFr($cal['date_debut_stage']); ?> → 
                                                <?php echo formatDateFr($cal['date_fin_stage']); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cal['observations']): ?>
                                        <div class="alert alert-light mt-3">
                                            <small>
                                                <strong><i class="fas fa-sticky-note"></i> Observations:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($cal['observations'])); ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
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
        
        <!-- Modal d'ajout/modification -->
        <div class="modal fade" id="addCalendrierModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" id="calendrierForm">
                        <input type="hidden" name="<?php echo $calendrier ? 'modifier_calendrier' : 'ajouter_calendrier'; ?>" value="1">
                        <?php if ($calendrier): ?>
                        <input type="hidden" name="calendrier_id" value="<?php echo $calendrier['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-calendar-plus"></i> 
                                <?php echo $calendrier ? 'Modifier' : 'Nouveau'; ?> Calendrier Académique
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Année Académique *</label>
                                    <select name="annee_academique_id" class="form-select" required>
                                        <option value="">Sélectionner une année</option>
                                        <?php foreach ($annees as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>" 
                                            <?php echo ($calendrier && $calendrier['annee_academique_id'] == $annee['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($annee['libelle']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Semestre *</label>
                                    <select name="semestre" class="form-select" required>
                                        <option value="1" <?php echo ($calendrier && $calendrier['semestre'] == '1') ? 'selected' : ''; ?>>Semestre 1</option>
                                        <option value="2" <?php echo ($calendrier && $calendrier['semestre'] == '2') ? 'selected' : ''; ?>>Semestre 2</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Type de Rentrée *</label>
                                    <select name="type_rentree" class="form-select" required>
                                        <option value="Octobre" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Octobre') ? 'selected' : ''; ?>>Octobre</option>
                                        <option value="Janvier" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Janvier') ? 'selected' : ''; ?>>Janvier</option>
                                        <option value="Avril" <?php echo ($calendrier && $calendrier['type_rentree'] == 'Avril') ? 'selected' : ''; ?>>Avril</option>
                                    </select>
                                </div>
                            </div>
                            
                            <hr>
                            <h6><i class="fas fa-calendar-day"></i> Périodes Principales</h6>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début des Cours *</label>
                                    <input type="date" name="date_debut_cours" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_cours'] : ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin des Cours *</label>
                                    <input type="date" name="date_fin_cours" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_cours'] : ''; ?>" required>
                                </div>
                            </div>
                            
                            <hr>
                            <h6><i class="fas fa-calendar-check"></i> Événements Spécifiques</h6>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début DST</label>
                                    <input type="date" name="date_debut_dst" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_dst'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin DST</label>
                                    <input type="date" name="date_fin_dst" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_dst'] : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début Devoir Recherche</label>
                                    <input type="date" name="date_debut_recherche" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_recherche'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin Devoir Recherche</label>
                                    <input type="date" name="date_fin_recherche" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_recherche'] : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début Congé d'Étude</label>
                                    <input type="date" name="date_debut_conge_etude" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_conge_etude'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin Congé d'Étude</label>
                                    <input type="date" name="date_fin_conge_etude" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_conge_etude'] : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début Examens</label>
                                    <input type="date" name="date_debut_examens" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_examens'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin Examens</label>
                                    <input type="date" name="date_fin_examens" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_examens'] : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Reprise des Cours (S2)</label>
                                    <input type="date" name="date_reprise_cours" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_reprise_cours'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Début Stage</label>
                                    <input type="date" name="date_debut_stage" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_debut_stage'] : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Fin Stage</label>
                                    <input type="date" name="date_fin_stage" class="form-control" 
                                           value="<?php echo $calendrier ? $calendrier['date_fin_stage'] : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Statut *</label>
                                    <select name="statut" class="form-select" required>
                                        <option value="planifie" <?php echo ($calendrier && $calendrier['statut'] == 'planifie') ? 'selected' : ''; ?>>Planifié</option>
                                        <option value="en_cours" <?php echo ($calendrier && $calendrier['statut'] == 'en_cours') ? 'selected' : ''; ?>>En cours</option>
                                        <option value="termine" <?php echo ($calendrier && $calendrier['statut'] == 'termine') ? 'selected' : ''; ?>>Terminé</option>
                                        <option value="annule" <?php echo ($calendrier && $calendrier['statut'] == 'annule') ? 'selected' : ''; ?>>Annulé</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Observations</label>
                                <textarea name="observations" class="form-control" rows="3" 
                                          placeholder="Notes ou remarques supplémentaires..."><?php echo $calendrier ? htmlspecialchars($calendrier['observations']) : ''; ?></textarea>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input type="checkbox" name="publie" class="form-check-input" id="publieCheck" 
                                       <?php echo ($calendrier && $calendrier['publie'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="publieCheck">
                                    Publier aux étudiants (rendre visible)
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> 
                                <?php echo $calendrier ? 'Modifier' : 'Enregistrer'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Si en mode édition, ouvrir automatiquement le modal -->
        <?php if ($calendrier && $action == 'edit'): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modal = new bootstrap.Modal(document.getElementById('addCalendrierModal'));
            modal.show();
        });
        </script>
        <?php endif; ?>
    </main>
    
    <!-- Pied de page -->
    <footer class="mt-5 pt-3 border-top text-center text-muted">
        <p class="mb-1">
            <small>
                <i class="fas fa-copyright"></i> ISGI - Gestion du Calendrier Académique
            </small>
        </p>
        <p class="mb-0">
            <small>
                Session: <?php echo date('d/m/Y H:i'); ?> | 
                Site: <?php echo htmlspecialchars($site_nom); ?>
            </small>
        </p>
    </footer>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js'></script>
    
    <script>
    // Initialiser FullCalendar
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        
        if (calendarEl) {
            var calendar = new FullCalendar.Calendar(calendarEl, {
                locale: 'fr',
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                events: [
                    <?php foreach ($calendriers as $cal): ?>
                    {
                        title: '<?php echo htmlspecialchars($cal["annee_libelle"]) . " - S" . $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_cours"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_cours"] . ' +1 day')); ?>',
                        className: 'event-cours',
                        extendedProps: {
                            type: 'cours',
                            description: 'Période de cours - <?php echo $cal["type_rentree"]; ?> rentrée'
                        }
                    },
                    <?php if ($cal['date_debut_dst'] && $cal['date_fin_dst']): ?>
                    {
                        title: 'DST - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_dst"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_dst"] . ' +1 day')); ?>',
                        className: 'event-dst',
                        extendedProps: {
                            type: 'dst',
                            description: 'Devoir Sur Table'
                        }
                    },
                    <?php endif; ?>
                    <?php if ($cal['date_debut_recherche'] && $cal['date_fin_recherche']): ?>
                    {
                        title: 'Recherche - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_recherche"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_recherche"] . ' +1 day')); ?>',
                        className: 'event-recherche',
                        extendedProps: {
                            type: 'recherche',
                            description: 'Devoir de Recherche'
                        }
                    },
                    <?php endif; ?>
                    <?php if ($cal['date_debut_conge_etude'] && $cal['date_fin_conge_etude']): ?>
                    {
                        title: 'Congé Étude - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_conge_etude"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_conge_etude"] . ' +1 day')); ?>',
                        className: 'event-conge',
                        extendedProps: {
                            type: 'conge',
                            description: 'Congé d\'Étude'
                        }
                    },
                    <?php endif; ?>
                    <?php if ($cal['date_debut_examens'] && $cal['date_fin_examens']): ?>
                    {
                        title: 'Examens - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_examens"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_examens"] . ' +1 day')); ?>',
                        className: 'event-examen',
                        extendedProps: {
                            type: 'examen',
                            description: 'Examens de Fin de Semestre'
                        }
                    },
                    <?php endif; ?>
                    <?php if ($cal['date_debut_stage'] && $cal['date_fin_stage']): ?>
                    {
                        title: 'Stage - S<?php echo $cal["semestre"]; ?>',
                        start: '<?php echo $cal["date_debut_stage"]; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($cal["date_fin_stage"] . ' +1 day')); ?>',
                        className: 'event-stage',
                        extendedProps: {
                            type: 'stage',
                            description: 'Stage Professionnel'
                        }
                    },
                    <?php endif; ?>
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    var event = info.event;
                    var details = `
                        <div class="alert alert-info">
                            <h6>${event.title}</h6>
                            <p class="mb-1"><strong>Type:</strong> ${event.extendedProps.description}</p>
                            <p class="mb-1"><strong>Date:</strong> ${event.start.toLocaleDateString('fr-FR')} 
                                ${event.end ? ' → ' + new Date(event.end.getTime() - 86400000).toLocaleDateString('fr-FR') : ''}
                            </p>
                            ${event.extendedProps.type === 'cours' ? 
                                '<p class="mb-0"><strong>Durée:</strong> ' + 
                                Math.round((event.end - event.start) / (1000 * 60 * 60 * 24)) + ' jours</p>' : ''}
                        </div>
                    `;
                    
                    // Créer un modal pour afficher les détails
                    var modalHTML = `
                        <div class="modal fade" id="eventModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Détails de l'événement</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        ${details}
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Ajouter le modal au DOM
                    var modalContainer = document.createElement('div');
                    modalContainer.innerHTML = modalHTML;
                    document.body.appendChild(modalContainer.firstChild);
                    
                    // Afficher le modal
                    var modal = new bootstrap.Modal(document.getElementById('eventModal'));
                    modal.show();
                    
                    // Nettoyer après fermeture
                    document.getElementById('eventModal').addEventListener('hidden.bs.modal', function() {
                        this.remove();
                    });
                },
                eventDidMount: function(info) {
                    // Info bulle au survol
                    info.el.title = info.event.extendedProps.description;
                }
            });
            
            calendar.render();
        }
        
        // Auto-suppression des alertes
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-light)');
            alerts.forEach(alert => {
                if (alert.classList.contains('show')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
        
        // Validation du formulaire
        document.getElementById('calendrierForm')?.addEventListener('submit', function(e) {
            const dateDebut = document.querySelector('input[name="date_debut_cours"]');
            const dateFin = document.querySelector('input[name="date_fin_cours"]');
            
            if (dateDebut.value && dateFin.value) {
                const debut = new Date(dateDebut.value);
                const fin = new Date(dateFin.value);
                
                if (fin < debut) {
                    e.preventDefault();
                    alert('La date de fin des cours doit être postérieure à la date de début.');
                    dateFin.focus();
                }
            }
        });
        
        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            // Ctrl + N pour nouveau calendrier
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                if (!document.querySelector('#addCalendrierModal.show')) {
                    const modal = new bootstrap.Modal(document.getElementById('addCalendrierModal'));
                    modal.show();
                }
            }
            
            // Échap pour fermer les modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    bootstrap.Modal.getInstance(modal).hide();
                });
            }
        });
    });
    
    // Exporter le calendrier
    function exporterCalendrier(format) {
        const url = new URL(window.location.href);
        url.searchParams.set('export', format);
        
        if (format === 'pdf') {
            window.open(url, '_blank');
        } else if (format === 'excel') {
            window.location.href = url;
        }
    }
    </script>
</body>
</html>

<?php
// Fin du output buffering
ob_end_flush();
?>