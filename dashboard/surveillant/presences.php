<?php
// dashboard/surveillant/presences.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

require_once ROOT_PATH . '/config/database.php';
$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

$pageTitle = "Toutes les Présences";

// Fonctions utilitaires
function formatDateFr($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date == '0000-00-00 00:00:00') return 'N/A';
    return date($format, strtotime($date));
}

function getStatutBadge($statut) {
    $badges = [
        'present' => 'success',
        'absent' => 'danger',
        'retard' => 'warning',
        'justifie' => 'info',
        'en_attente' => 'secondary'
    ];
    $text = ucfirst($statut);
    $color = $badges[$statut] ?? 'secondary';
    return "<span class='badge bg-$color'>$text</span>";
}

function getTypePresenceBadge($type) {
    $badges = [
        'entree_ecole' => ['Entrée École', 'primary'],
        'sortie_ecole' => ['Sortie École', 'secondary'],
        'entree_classe' => ['Entrée Classe', 'info'],
        'sortie_classe' => ['Sortie Classe', 'warning']
    ];
    list($text, $color) = $badges[$type] ?? ['Autre', 'dark'];
    return "<span class='badge bg-$color'>$text</span>";
}

// Récupérer les filtres
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$classe_id = $_GET['classe_id'] ?? '';
$type_presence = $_GET['type_presence'] ?? '';
$statut = $_GET['statut'] ?? '';
$search = $_GET['search'] ?? '';

// Construire la requête avec filtres
$params = [':site_id' => $site_id];
$where = "WHERE p.site_id = :site_id";
$join = "LEFT JOIN";

if (!empty($date_debut) && !empty($date_fin)) {
    $where .= " AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin";
    $params[':date_debut'] = $date_debut;
    $params[':date_fin'] = $date_fin;
}

if (!empty($classe_id)) {
    $where .= " AND e.classe_id = :classe_id";
    $params[':classe_id'] = $classe_id;
}

if (!empty($type_presence)) {
    $where .= " AND p.type_presence = :type_presence";
    $params[':type_presence'] = $type_presence;
}

if (!empty($statut)) {
    $where .= " AND p.statut = :statut";
    $params[':statut'] = $statut;
}

if (!empty($search)) {
    $where .= " AND (e.matricule LIKE :search OR e.nom LIKE :search OR e.prenom LIKE :search)";
    $params[':search'] = "%$search%";
}

// Récupérer les classes pour le filtre
$query_classes = "SELECT id, nom FROM classes WHERE site_id = :site_id ORDER BY nom";
$stmt_classes = $db->prepare($query_classes);
$stmt_classes->execute([':site_id' => $site_id]);
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les présences avec pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Compter le total
$count_query = "SELECT COUNT(*) as total FROM presences p $join etudiants e ON p.etudiant_id = e.id $where";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Récupérer les données
$query = "
    SELECT 
        p.*,
        e.matricule,
        e.nom,
        e.prenom,
        c.nom as classe_nom,
        m.nom as matiere_nom,
        u.nom as surveillant_nom,
        u.prenom as surveillant_prenom
    FROM presences p
    LEFT JOIN etudiants e ON p.etudiant_id = e.id
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN matieres m ON p.matiere_id = m.id
    LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
    $where
    ORDER BY p.date_heure DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
$params[':limit'] = $limit;
$params[':offset'] = $offset;
$stmt->execute($params);
$presences = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN p.statut = 'present' THEN 1 ELSE 0 END) as presents,
        SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) as absents,
        SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) as retards,
        SUM(CASE WHEN p.statut = 'justifie' THEN 1 ELSE 0 END) as justifies
    FROM presences p
    $join etudiants e ON p.etudiant_id = e.id
    $where
";
$stmt_stats = $db->prepare($stats_query);
unset($params[':limit']);
unset($params[':offset']);
$stmt_stats->execute($params);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --success-color: #27ae60;
        --danger-color: #e74c3c;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
    }
    
    .stat-card {
        border-radius: 10px;
        transition: transform 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
    }
    
    .filter-card {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 10px;
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }
    
    .badge-present { background-color: var(--success-color); }
    .badge-absent { background-color: var(--danger-color); }
    .badge-retard { background-color: var(--warning-color); }
    .badge-justifie { background-color: var(--info-color); }
    
    .type-entree_ecole { background-color: var(--primary-color); }
    .type-sortie_ecole { background-color: #6c757d; }
    .type-entree_classe { background-color: var(--info-color); }
    .type-sortie_classe { background-color: #ffc107; }
    
    .pagination .page-link {
        color: var(--primary-color);
    }
    
    .pagination .page-item.active .page-link {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-shield me-2"></i>
                Surveillant ISGI
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="presences.php">
                            <i class="fas fa-calendar-check"></i> Présences
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="scanner_qr.php">
                            <i class="fas fa-qrcode"></i> Scanner
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="absences.php">
                            <i class="fas fa-user-times"></i> Absences
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rapports_presence.php">
                            <i class="fas fa-chart-bar"></i> Rapports
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Surveillant'); ?>
                    </span>
                    <a href="../../auth/logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-4">
        <!-- En-tête -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="fas fa-calendar-check text-primary me-2"></i>Toutes les Présences</h2>
                        <p class="text-muted mb-0">Gestion complète des présences des étudiants</p>
                    </div>
                    <div>
                        <button class="btn btn-success" onclick="window.location.href='scanner_qr.php'">
                            <i class="fas fa-qrcode me-1"></i> Scanner QR
                        </button>
                        <button class="btn btn-primary" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-1"></i> Exporter
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtres -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form id="filterForm" method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date début</label>
                        <input type="date" class="form-control" name="date_debut" 
                               value="<?php echo htmlspecialchars($date_debut); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date fin</label>
                        <input type="date" class="form-control" name="date_fin" 
                               value="<?php echo htmlspecialchars($date_fin); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Classe</label>
                        <select class="form-select" name="classe_id">
                            <option value="">Toutes</option>
                            <?php foreach($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" 
                                <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type_presence">
                            <option value="">Tous</option>
                            <option value="entree_ecole" <?php echo $type_presence == 'entree_ecole' ? 'selected' : ''; ?>>Entrée École</option>
                            <option value="sortie_ecole" <?php echo $type_presence == 'sortie_ecole' ? 'selected' : ''; ?>>Sortie École</option>
                            <option value="entree_classe" <?php echo $type_presence == 'entree_classe' ? 'selected' : ''; ?>>Entrée Classe</option>
                            <option value="sortie_classe" <?php echo $type_presence == 'sortie_classe' ? 'selected' : ''; ?>>Sortie Classe</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Statut</label>
                        <select class="form-select" name="statut">
                            <option value="">Tous</option>
                            <option value="present" <?php echo $statut == 'present' ? 'selected' : ''; ?>>Présent</option>
                            <option value="absent" <?php echo $statut == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="retard" <?php echo $statut == 'retard' ? 'selected' : ''; ?>>Retard</option>
                            <option value="justifie" <?php echo $statut == 'justifie' ? 'selected' : ''; ?>>Justifié</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Recherche</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Matricule, nom..." value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-8 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i> Filtrer
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo me-1"></i> Réinitialiser
                        </button>
                        <button type="button" class="btn btn-info" onclick="showStatsModal()">
                            <i class="fas fa-chart-bar me-1"></i> Statistiques
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Total Présences</h6>
                                <h3 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h3>
                            </div>
                            <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Présents</h6>
                                <h3 class="mb-0"><?php echo number_format($stats['presents'] ?? 0); ?></h3>
                            </div>
                            <i class="fas fa-check-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Absents</h6>
                                <h3 class="mb-0"><?php echo number_format($stats['absents'] ?? 0); ?></h3>
                            </div>
                            <i class="fas fa-times-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Retards</h6>
                                <h3 class="mb-0"><?php echo number_format($stats['retards'] ?? 0); ?></h3>
                            </div>
                            <i class="fas fa-clock fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau des présences -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    Liste des Présences (<?php echo number_format($total_records); ?>)
                </h5>
                <div class="d-flex gap-2">
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" 
                                data-bs-toggle="dropdown">
                            <i class="fas fa-cog"></i> Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="exportToExcel()">
                                <i class="fas fa-file-excel text-success me-2"></i>Exporter Excel
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportToPDF()">
                                <i class="fas fa-file-pdf text-danger me-2"></i>Exporter PDF
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="printTable()">
                                <i class="fas fa-print me-2"></i>Imprimer
                            </a></li>
                        </ul>
                    </div>
                    <button class="btn btn-sm btn-outline-info" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if(empty($presences)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Aucune présence trouvée avec les filtres sélectionnés.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped" id="presencesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Étudiant</th>
                                <th>Matricule</th>
                                <th>Classe</th>
                                <th>Type</th>
                                <th>Date/Heure</th>
                                <th>Statut</th>
                                <th>Matière</th>
                                <th>Surveillant</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($presences as $index => $presence): ?>
                            <tr>
                                <td><?php echo ($page - 1) * $limit + $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($presence['matricule']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($presence['classe_nom'] ?? 'N/A'); ?></td>
                                <td><?php echo getTypePresenceBadge($presence['type_presence']); ?></td>
                                <td>
                                    <i class="fas fa-calendar me-1 text-muted"></i>
                                    <?php echo formatDateFr($presence['date_heure']); ?>
                                </td>
                                <td><?php echo getStatutBadge($presence['statut']); ?></td>
                                <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? '-'); ?></td>
                                <td>
                                    <?php if($presence['surveillant_nom']): ?>
                                    <span class="badge bg-info">
                                        <?php echo htmlspecialchars($presence['surveillant_nom'] . ' ' . $presence['surveillant_prenom']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Auto</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" 
                                                onclick="viewPresence(<?php echo $presence['id']; ?>)"
                                                title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" 
                                                onclick="editPresence(<?php echo $presence['id']; ?>)"
                                                title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" 
                                                onclick="deletePresence(<?php echo $presence['id']; ?>)"
                                                title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php elseif($i == $page - 3 || $i == $page + 3): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Statistiques -->
    <div class="modal fade" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar me-2"></i>Statistiques Détail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="statsContent">
                    <!-- Les statistiques seront chargées en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Détails Présence -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Détails de la Présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <!-- Les détails seront chargés en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modification Présence -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier la Présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editContent">
                    <!-- Le formulaire sera chargé en AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
    // Initialiser les datepickers
    flatpickr("input[type=date]", {
        dateFormat: "Y-m-d",
        locale: "fr"
    });
    
    // Afficher le modal des statistiques
    function showStatsModal() {
        fetch('ajax/get_presence_stats.php?' + new URLSearchParams({
            date_debut: '<?php echo $date_debut; ?>',
            date_fin: '<?php echo $date_fin; ?>',
            classe_id: '<?php echo $classe_id; ?>'
        }))
        .then(response => response.text())
        .then(html => {
            document.getElementById('statsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('statsModal')).show();
        })
        .catch(error => {
            console.error('Erreur:', error);
            Swal.fire('Erreur', 'Impossible de charger les statistiques', 'error');
        });
    }
    
    // Voir les détails d'une présence
    function viewPresence(presenceId) {
        fetch('ajax/get_presence_detail.php?id=' + presenceId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('detailContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('detailModal')).show();
        });
    }
    
    // Modifier une présence
    function editPresence(presenceId) {
        fetch('ajax/get_presence_edit.php?id=' + presenceId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('editContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        });
    }
    
    // Supprimer une présence
    function deletePresence(presenceId) {
        Swal.fire({
            title: 'Êtes-vous sûr ?',
            text: "Cette action est irréversible !",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Oui, supprimer !',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax/delete_presence.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'id=' + presenceId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Supprimé !', data.message, 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Erreur !', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Erreur', 'Une erreur est survenue', 'error');
                    console.error('Erreur:', error);
                });
            }
        });
    }
    
    // Exporter vers Excel
    function exportToExcel() {
        const table = document.getElementById('presencesTable');
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Présences");
        
        // Nom du fichier avec date
        const date = new Date().toISOString().split('T')[0];
        const filename = `presences_${date}.xlsx`;
        
        XLSX.writeFile(wb, filename);
    }
    
    // Exporter vers PDF (version simple)
    function exportToPDF() {
        window.print();
    }
    
    // Imprimer le tableau
    function printTable() {
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Liste des Présences</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Liste des Présences</h2>
                    <p>Date d'export: ${new Date().toLocaleDateString()}</p>
                </div>
                ${document.getElementById('presencesTable').outerHTML}
                <div class="footer">
                    <p>Généré par ISGI - Surveillance des Présences</p>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
    
    // Réinitialiser les filtres
    function resetFilters() {
        window.location.href = 'presences.php';
    }
    
    // Effacer la recherche
    function clearSearch() {
        document.querySelector('input[name="search"]').value = '';
        document.getElementById('filterForm').submit();
    }
    
    // Rafraîchir les données
    function refreshData() {
        location.reload();
    }
    
    // Auto-refresh toutes les 5 minutes
    setInterval(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('detailModal'));
        if (!modal || !modal._isShown) {
            // Si aucun modal n'est ouvert, on rafraîchit
            refreshData();
        }
    }, 300000); // 5 minutes
    </script>
</body>
</html>