<?php
// dashboard/dac/presences.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $site_id = $_SESSION['site_id'] ?? null;
    
    $pageTitle = "Gestion des Présences";
    
    $date = $_GET['date'] ?? date('Y-m-d');
    $classe_id = $_GET['classe_id'] ?? null;
    $action = $_GET['action'] ?? 'list';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['mark_presence'])) {
            $etudiant_id = $_POST['etudiant_id'];
            $statut = $_POST['statut'];
            $matiere_id = $_POST['matiere_id'];
            $motif = $_POST['motif'] ?? null;
            
            $query = "INSERT INTO presences (etudiant_id, site_id, type_presence, statut, motif_absence, matiere_id, surveillant_id) 
                     VALUES (?, ?, 'entree_classe', ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$etudiant_id, $site_id, $statut, $motif, $matiere_id, $_SESSION['user_id']]);
            
            $message = "Présence enregistrée avec succès";
            $message_type = "success";
        }
    }
    
    // Récupérer les classes
    $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
              FROM classes c
              JOIN filieres f ON c.filiere_id = f.id
              JOIN niveaux n ON c.niveau_id = n.id
              WHERE c.site_id = ?
              ORDER BY f.nom, n.ordre";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les matières
    $query = "SELECT * FROM matieres WHERE site_id = ? ORDER BY nom";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les présences pour la date sélectionnée
    $query = "SELECT p.*, e.matricule, e.nom, e.prenom, m.nom as matiere_nom, 
                     CONCAT(u.nom, ' ', u.prenom) as surveillant_nom
              FROM presences p
              JOIN etudiants e ON p.etudiant_id = e.id
              LEFT JOIN matieres m ON p.matiere_id = m.id
              LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
              WHERE p.site_id = ? AND DATE(p.date_heure) = ?
              ORDER BY p.date_heure DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id, $date]);
    $presences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiques des présences
    $query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
              FROM presences
              WHERE site_id = ? AND DATE(date_heure) = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id, $date]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les étudiants d'une classe si sélectionnée
    $etudiants_classe = [];
    if ($classe_id) {
        $query = "SELECT e.* FROM etudiants e 
                  WHERE e.classe_id = ? AND e.statut = 'actif'
                  ORDER BY e.nom, e.prenom";
        $stmt = $db->prepare($query);
        $stmt->execute([$classe_id]);
        $etudiants_classe = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $error = "Erreur: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI DAC</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
    .presence-card {
        border-left: 4px solid;
        margin-bottom: 10px;
    }
    
    .presence-present { border-left-color: #28a745; }
    .presence-absent { border-left-color: #dc3545; }
    .presence-retard { border-left-color: #ffc107; }
    .presence-justifie { border-left-color: #17a2b8; }
    
    .presence-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-weight: 500;
    }
    
    .badge-present { background-color: #d4edda; color: #155724; }
    .badge-absent { background-color: #f8d7da; color: #721c24; }
    .badge-retard { background-color: #fff3cd; color: #856404; }
    .badge-justifie { background-color: #d1ecf1; color: #0c5460; }
    
    .stats-card {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 15px;
    }
    
    .stats-total { background-color: #e9ecef; }
    .stats-present { background-color: #d4edda; }
    .stats-absent { background-color: #f8d7da; }
    .stats-retard { background-color: #fff3cd; }
    
    .timetable {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 10px;
        margin-top: 20px;
    }
    
    .day-column {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
    }
    
    .time-slot {
        padding: 8px;
        margin: 5px 0;
        border-radius: 3px;
        font-size: 12px;
    }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-calendar-check"></i> Gestion des Présences
                    </h1>
                </div>
                
                <?php if(isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if(isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <!-- Filtres et Statistiques -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card stats-total">
                            <h4><?php echo $stats['total'] ?? 0; ?></h4>
                            <p class="mb-0">Total Présences</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card stats-present">
                            <h4><?php echo $stats['presents'] ?? 0; ?></h4>
                            <p class="mb-0">Présents</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card stats-absent">
                            <h4><?php echo $stats['absents'] ?? 0; ?></h4>
                            <p class="mb-0">Absents</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card stats-retard">
                            <h4><?php echo $stats['retards'] ?? 0; ?></h4>
                            <p class="mb-0">Retards</p>
                        </div>
                    </div>
                </div>
                
                <!-- Contrôles -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" 
                                       class="form-control" id="datePicker">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Classe</label>
                                <select name="classe_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">Toutes les classes</option>
                                    <?php foreach($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>" 
                                            <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($classe['filiere_nom'] . ' - ' . $classe['niveau_libelle']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Heure de début</label>
                                <input type="time" name="heure_debut" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Heure de fin</label>
                                <input type="time" name="heure_fin" class="form-control">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Filtrer
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-3">
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#markPresenceModal">
                                <i class="fas fa-plus"></i> Marquer une présence
                            </button>
                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#importPresenceModal">
                                <i class="fas fa-file-import"></i> Importer
                            </button>
                            <a href="export_presence.php?date=<?php echo $date; ?>" class="btn btn-info">
                                <i class="fas fa-file-export"></i> Exporter
                            </a>
                            <button class="btn btn-danger" onclick="generateReport()">
                                <i class="fas fa-chart-bar"></i> Rapport
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Liste des présences -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Présences du <?php echo date('d/m/Y', strtotime($date)); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($presences)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucune présence enregistrée pour cette date
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Étudiant</th>
                                        <th>Heure</th>
                                        <th>Matière</th>
                                        <th>Type</th>
                                        <th>Statut</th>
                                        <th>Surveillant</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($presences as $presence): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($presence['prenom'] . ' ' . $presence['nom']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($presence['matricule']); ?></small>
                                        </td>
                                        <td><?php echo date('H:i', strtotime($presence['date_heure'])); ?></td>
                                        <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? 'Entrée/Sortie'); ?></td>
                                        <td><?php echo htmlspecialchars($presence['type_presence']); ?></td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-' . $presence['statut'];
                                            echo '<span class="presence-badge ' . $badge_class . '">' . ucfirst($presence['statut']) . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($presence['surveillant_nom'] ?? 'Système'); ?></td>
                                        <td>
                                            <button onclick="editPresence(<?php echo $presence['id']; ?>)" 
                                                    class="btn btn-sm btn-warning" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deletePresence(<?php echo $presence['id']; ?>)" 
                                                    class="btn btn-sm btn-danger" title="Supprimer">
                                                <i class="fas fa-trash"></i>
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
                
                <!-- Emploi du temps -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Emploi du temps du jour</h5>
                    </div>
                    <div class="card-body">
                        <div class="timetable">
                            <?php 
                            $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                            $today_day = date('l');
                            
                            foreach($jours as $jour): 
                                $is_today = ($jour == $today_day);
                            ?>
                            <div class="day-column <?php echo $is_today ? 'bg-info text-white' : ''; ?>">
                                <strong><?php echo $jour; ?></strong>
                                
                                <?php 
                                // Simuler des cours pour l'exemple
                                $cours_exemple = [
                                    ['heure' => '08:00-10:00', 'matiere' => 'Mathématiques', 'prof' => 'Prof. Dupont'],
                                    ['heure' => '10:15-12:15', 'matiere' => 'Informatique', 'prof' => 'Prof. Martin'],
                                    ['heure' => '14:00-16:00', 'matiere' => 'Anglais', 'prof' => 'Prof. Durand'],
                                ];
                                
                                foreach($cours_exemple as $cours):
                                ?>
                                <div class="time-slot bg-white border">
                                    <small><strong><?php echo $cours['heure']; ?></strong></small><br>
                                    <small><?php echo $cours['matiere']; ?></small><br>
                                    <small class="text-muted"><?php echo $cours['prof']; ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal Marquer Présence -->
    <div class="modal fade" id="markPresenceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Marquer une présence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Étudiant</label>
                            <select name="etudiant_id" class="form-select" required>
                                <option value="">Sélectionner un étudiant</option>
                                <?php foreach($etudiants_classe as $etudiant): ?>
                                <option value="<?php echo $etudiant['id']; ?>">
                                    <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Matière</label>
                            <select name="matiere_id" class="form-select">
                                <option value="">Sélectionner une matière</option>
                                <?php foreach($matieres as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>">
                                    <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select" required>
                                <option value="present">Présent</option>
                                <option value="absent">Absent</option>
                                <option value="retard">En retard</option>
                                <option value="justifie">Absence justifiée</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Motif (si absent)</label>
                            <textarea name="motif" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="mark_presence" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Import -->
    <div class="modal fade" id="importPresenceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Importer des présences</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Format accepté: CSV (Matricule, Date, Heure, Statut)
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fichier CSV</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Classe</label>
                            <select name="classe_id" class="form-select">
                                <option value="">Toutes les classes</option>
                                <?php foreach($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>">
                                    <?php echo htmlspecialchars($classe['filiere_nom'] . ' - ' . $classe['niveau_libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="import_csv" class="btn btn-primary">Importer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    // Initialiser le date picker
    flatpickr("#datePicker", {
        dateFormat: "Y-m-d",
        locale: "fr"
    });
    
    function editPresence(id) {
        // Implémenter l'édition
        alert('Édition de la présence ID: ' + id);
        // Redirection ou modal d'édition
    }
    
    function deletePresence(id) {
        if (confirm('Voulez-vous vraiment supprimer cette présence ?')) {
            // Envoyer une requête de suppression
            fetch('delete_presence.php?id=' + id, { method: 'DELETE' })
                .then(response => {
                    if (response.ok) {
                        location.reload();
                    }
                });
        }
    }
    
    function generateReport() {
        const url = `rapports_academiques.php?type=presence&date=${encodeURIComponent('<?php echo $date; ?>')}`;
        window.open(url, '_blank');
    }
    
    // Auto-refresh toutes les 30 secondes
    setTimeout(() => {
        location.reload();
    }, 30000);
    </script>
</body>
</html>