<?php
// dashboard/cours_en_ligne.php
define('ROOT_PATH', dirname(dirname(__FILE__)));
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

include_once ROOT_PATH . '/config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = "Gestion des Cours en Ligne";
$site_id = $_SESSION['site_id'] ?? 1; // Valeur par défaut

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Fonctions utilitaires
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
    
    $query = "SELECT nom FROM matieres WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$matiere_id]);
    $matiere = $stmt->fetch();
    
    return $matiere ? htmlspecialchars($matiere['nom']) : 'Inconnue';
}

function getCoursDetails($id, $db) {
    $query = "SELECT c.*, m.nom as matiere_nom, s.nom as site_nom 
              FROM cours_en_ligne c 
              LEFT JOIN matieres m ON c.matiere_id = m.id 
              LEFT JOIN sites s ON c.site_id = s.id 
              WHERE c.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getParticipants($cours_id, $db) {
    $query = "SELECT cp.*, e.matricule, e.nom, e.prenom 
              FROM cours_participants cp
              JOIN etudiants e ON cp.etudiant_id = e.id
              WHERE cp.cours_id = ?
              ORDER BY cp.date_inscription DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([$cours_id]);
    return $stmt->fetchAll();
}

function getCoursByStatus($status, $site_id, $db) {
    $query = "SELECT c.*, m.nom as matiere_nom 
              FROM cours_en_ligne c 
              LEFT JOIN matieres m ON c.matiere_id = m.id 
              WHERE c.site_id = ? AND c.statut = ?
              ORDER BY c.date_cours";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id, $status]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
    }
    
    .card-cours {
        transition: transform 0.2s;
        border-left: 4px solid var(--secondary-color);
        height: 100%;
    }
    .card-cours:hover {
        transform: translateY(-2px);
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
    .calendar-container {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .tab-content {
        background: white;
        border: 1px solid #dee2e6;
        border-top: none;
        padding: 20px;
        border-radius: 0 0 10px 10px;
    }
    .nav-tabs .nav-link {
        border-radius: 10px 10px 0 0;
        margin-right: 5px;
    }
    .nav-tabs .nav-link.active {
        background: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    .btn-replay {
        background: var(--info-color);
        border-color: var(--info-color);
    }
    .btn-replay:hover {
        background: #138496;
        border-color: #117a8b;
    }
    .stat-card {
        text-align: center;
        padding: 20px;
        border-radius: 10px;
        background: white;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .stat-label {
        color: #6c757d;
        font-size: 0.9rem;
    }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- En-tête -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">
                <i class="fas fa-chalkboard-teacher"></i> Cours en Ligne
            </h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="btn-group me-2">
                    <button class="btn btn-primary" onclick="location.href='cours_en_ligne.php?action=planifier'">
                        <i class="fas fa-plus"></i> Planifier un cours
                    </button>
                    <button class="btn btn-success" onclick="location.href='cours_en_ligne.php?action=calendar'">
                        <i class="fas fa-calendar"></i> Calendrier
                    </button>
                </div>
            </div>
        </div>

        <!-- Messages d'alerte -->
        <?php if($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i>
            <?php 
            switch($success) {
                case '1': echo 'Cours planifié avec succès!'; break;
                case '2': echo 'Cours modifié avec succès!'; break;
                case '3': echo 'Cours supprimé avec succès!'; break;
                case '4': echo 'Enregistrement sauvegardé!'; break;
                default: echo 'Opération réussie!';
            }
            ?>
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
                <div class="stat-card text-primary">
                    <div class="stat-icon">
                        <i class="fas fa-video"></i>
                    </div>
                    <?php
                    $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE site_id = ? AND statut = 'en_cours'");
                    $stmt->execute([$site_id]);
                    $count = $stmt->fetch();
                    ?>
                    <div class="stat-value"><?php echo $count['total']; ?></div>
                    <div class="stat-label">Cours en direct</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-success">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <?php
                    $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE site_id = ? AND statut = 'planifie'");
                    $stmt->execute([$site_id]);
                    $count = $stmt->fetch();
                    ?>
                    <div class="stat-value"><?php echo $count['total']; ?></div>
                    <div class="stat-label">Cours planifiés</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-info">
                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <?php
                    $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_en_ligne WHERE site_id = ? AND statut = 'termine' AND url_replay IS NOT NULL");
                    $stmt->execute([$site_id]);
                    $count = $stmt->fetch();
                    ?>
                    <div class="stat-value"><?php echo $count['total']; ?></div>
                    <div class="stat-label">Replays disponibles</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-warning">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <?php
                    $stmt = $db->prepare("SELECT COUNT(DISTINCT etudiant_id) as total FROM cours_participants cp JOIN cours_en_ligne c ON cp.cours_id = c.id WHERE c.site_id = ?");
                    $stmt->execute([$site_id]);
                    $count = $stmt->fetch();
                    ?>
                    <div class="stat-value"><?php echo $count['total']; ?></div>
                    <div class="stat-label">Étudiants participants</div>
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <ul class="nav nav-tabs" id="coursTabs" role="tablist">
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

        <div class="tab-content" id="coursTabsContent">
            <!-- Tab 1: Cours en direct -->
            <div class="tab-pane fade show active" id="live" role="tabpanel">
                <div class="row mt-3">
                    <?php
                    $cours_live = getCoursByStatus('en_cours', $site_id, $db);
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
            <div class="tab-pane fade" id="planifies" role="tabpanel">
                <div class="row mt-3">
                    <?php
                    $cours_planifies = getCoursByStatus('planifie', $site_id, $db);
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
            <div class="tab-pane fade" id="replay" role="tabpanel">
                <div class="row mt-3">
                    <?php
                    $query = "SELECT c.*, m.nom as matiere_nom 
                              FROM cours_en_ligne c 
                              LEFT JOIN matieres m ON c.matiere_id = m.id 
                              WHERE c.site_id = ? AND c.statut = 'termine' AND c.url_replay IS NOT NULL
                              ORDER BY c.date_cours DESC";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$site_id]);
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
            <div class="tab-pane fade" id="tous" role="tabpanel">
                <div class="row mt-3">
                    <?php
                    $query = "SELECT c.*, m.nom as matiere_nom 
                              FROM cours_en_ligne c 
                              LEFT JOIN matieres m ON c.matiere_id = m.id 
                              WHERE c.site_id = ? 
                              ORDER BY c.date_cours DESC";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$site_id]);
                    $tous_cours = $stmt->fetchAll();
                    
                    if (empty($tous_cours)): ?>
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun cours disponible.
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Titre</th>
                                    <th>Matière</th>
                                    <th>Enseignant</th>
                                    <th>Date</th>
                                    <th>Durée</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tous_cours as $cour): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cour['titre']); ?></td>
                                    <td><?php echo htmlspecialchars($cour['matiere_nom'] ?? 'Général'); ?></td>
                                    <td><?php echo getEnseignantNom($cour['enseignant_id'], $db); ?></td>
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
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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
                <form method="POST" action="cours_en_ligne_action.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $action == 'planifier' ? 'create' : 'update'; ?>">
                    <?php if($action == 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Titre du cours *</label>
                                <input type="text" class="form-control" name="titre" required
                                    value="<?php 
                                    if($action == 'edit') {
                                        $cours = getCoursDetails($id, $db);
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
                                <label class="form-label">Matière</label>
                                <select class="form-select" name="matiere_id">
                                    <option value="">Sélectionner une matière</option>
                                    <?php
                                    $matieres = $db->query("SELECT * FROM matieres ORDER BY nom")->fetchAll();
                                    foreach($matieres as $matiere): ?>
                                    <option value="<?php echo $matiere['id']; ?>"
                                        <?php if($action == 'edit' && ($cours['matiere_id'] ?? 0) == $matiere['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($matiere['nom'] . ' (' . ($matiere['code'] ?? '') . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Enseignant</label>
                                <select class="form-select" name="enseignant_id">
                                    <option value="">Sélectionner un enseignant</option>
                                    <?php
                                    $enseignants = $db->query("
                                        SELECT e.id, u.nom, u.prenom 
                                        FROM enseignants e 
                                        JOIN utilisateurs u ON e.utilisateur_id = u.id 
                                        WHERE e.statut = 'actif'
                                        ORDER BY u.nom, u.prenom
                                    ")->fetchAll();
                                    foreach($enseignants as $enseignant): ?>
                                    <option value="<?php echo $enseignant['id']; ?>"
                                        <?php if($action == 'edit' && ($cours['enseignant_id'] ?? 0) == $enseignant['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date et heure *</label>
                                <input type="datetime-local" class="form-control" name="date_cours" required
                                    value="<?php 
                                    if($action == 'edit' && isset($cours['date_cours'])) {
                                        echo date('Y-m-d\TH:i', strtotime($cours['date_cours']));
                                    }
                                    ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Durée (minutes) *</label>
                                <input type="number" class="form-control" name="duree_minutes" value="60" min="15" max="480" required
                                    value="<?php if($action == 'edit') echo htmlspecialchars($cours['duree_minutes'] ?? 60); ?>">
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

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Participants maximum</label>
                                <input type="number" class="form-control" name="max_participants" value="100" min="1" max="500"
                                    value="<?php if($action == 'edit') echo htmlspecialchars($cours['max_participants'] ?? 100); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Statut</label>
                                <select class="form-select" name="statut" <?php echo $action == 'planifier' ? 'disabled' : ''; ?>>
                                    <option value="planifie" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'planifie') echo 'selected'; ?>>Planifié</option>
                                    <option value="en_cours" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'en_cours') echo 'selected'; ?>>En cours</option>
                                    <option value="termine" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'termine') echo 'selected'; ?>>Terminé</option>
                                    <option value="annule" <?php if($action == 'edit' && ($cours['statut'] ?? '') == 'annule') echo 'selected'; ?>>Annulé</option>
                                </select>
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
                        <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $id; ?>)">
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
                            <p><?php echo nl2br(htmlspecialchars($cours['description'])); ?></p>
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
                            <a href="<?php echo $cours['presentation_pdf']; ?>" class="btn btn-outline-danger" target="_blank">
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
                                    <h6>Matière</h6>
                                    <p class="mb-0">
                                        <i class="fas fa-book text-primary"></i> 
                                        <?php echo htmlspecialchars($cours['matiere_nom']); ?>
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
                                    <h6>Site</h6>
                                    <p class="mb-0">
                                        <i class="fas fa-building text-success"></i> 
                                        <?php echo htmlspecialchars($cours['site_nom']); ?>
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

                                <hr>

                                <h6>Partager ce cours</h6>
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control" id="shareUrl" 
                                           value="<?php echo "https://" . $_SERVER['HTTP_HOST'] . ROOT_PATH . "/cours_en_ligne.php?action=view&id=" . $id; ?>" 
                                           readonly>
                                    <button class="btn btn-outline-secondary" onclick="copyShareUrl()">
                                        <i class="fas fa-copy"></i>
                                    </button>
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
                                            <small class="text-muted"><?php echo $participant['matricule']; ?></small>
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
                            <div class="card-footer">
                                <button class="btn btn-outline-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#addParticipantModal">
                                    <i class="fas fa-user-plus"></i> Ajouter des participants
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal pour ajouter des participants -->
        <div class="modal fade" id="addParticipantModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Ajouter des participants</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addParticipantForm">
                            <input type="hidden" name="cours_id" value="<?php echo $id; ?>">
                            <div class="mb-3">
                                <label class="form-label">Sélectionner des étudiants</label>
                                <select class="form-select" name="etudiant_ids[]" multiple size="10">
                                    <?php
                                    $etudiants = $db->query("SELECT id, matricule, nom, prenom FROM etudiants WHERE statut = 'actif' AND site_id = $site_id ORDER BY nom")->fetchAll();
                                    foreach($etudiants as $etudiant): 
                                        // Vérifier si déjà inscrit
                                        $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_participants WHERE cours_id = ? AND etudiant_id = ?");
                                        $stmt->execute([$id, $etudiant['id']]);
                                        $already = $stmt->fetch();
                                        if ($already['total'] == 0):
                                    ?>
                                    <option value="<?php echo $etudiant['id']; ?>">
                                        <?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom'] . ' (' . $etudiant['matricule'] . ')'); ?>
                                    </option>
                                    <?php endif; endforeach; ?>
                                </select>
                                <small class="text-muted">Maintenez Ctrl pour sélectionner plusieurs étudiants</small>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" onclick="addParticipants()">Ajouter</button>
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
                <h5 class="mb-0"><i class="fas fa-calendar"></i> Calendrier des cours</h5>
            </div>
            <div class="card-body">
                <div id="calendar" class="calendar-container"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($action == 'replay'): ?>
        <!-- Gestion des replays -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Gestion des replays</h5>
                    <button class="btn btn-primary" onclick="location.href='cours_en_ligne.php?action=upload_replay'">
                        <i class="fas fa-upload"></i> Uploader un replay
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Interface pour uploader et gérer les replays -->
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modals et scripts -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer la suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Êtes-vous sûr de vouloir supprimer ce cours ? Cette action est irréversible.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Supprimer</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js"></script>
    <script>
    // Initialiser les onglets
    const triggerTabList = document.querySelectorAll('#coursTabs button')
    triggerTabList.forEach(triggerEl => {
        const tabTrigger = new bootstrap.Tab(triggerEl)
        triggerEl.addEventListener('click', event => {
            event.preventDefault()
            tabTrigger.show()
        })
    })

    // Fonction pour confirmer la suppression
    let coursToDelete = null;
    
    function confirmDelete(id) {
        coursToDelete = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (coursToDelete) {
            window.location.href = 'cours_en_ligne_action.php?action=delete&id=' + coursToDelete;
        }
    });

    // Copier l'URL de partage
    function copyShareUrl() {
        const input = document.getElementById('shareUrl');
        input.select();
        input.setSelectionRange(0, 99999);
        document.execCommand('copy');
        alert('URL copiée dans le presse-papier !');
    }

    // Ajouter des participants
    function addParticipants() {
        const form = document.getElementById('addParticipantForm');
        const formData = new FormData(form);
        
        fetch('cours_en_ligne_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Participants ajoutés avec succès !');
                location.reload();
            } else {
                alert('Erreur: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue');
        });
    }

    <?php if($action == 'calendar'): ?>
    // Initialiser le calendrier FullCalendar
    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('calendar');
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
    });
    <?php endif; ?>

    // Filtrage en temps réel
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchCours');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const cours = document.querySelectorAll('#coursList .col-md-4');
                
                cours.forEach(card => {
                    const title = card.querySelector('.card-title').textContent.toLowerCase();
                    const description = card.querySelector('.card-text').textContent.toLowerCase();
                    
                    if (title.includes(searchTerm) || description.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    });
    </script>
</body>
</html>