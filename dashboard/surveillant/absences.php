<?php
// dashboard/surveillant/absences.php

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

$pageTitle = "Gestion des Absences";

// Récupérer les absences
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

$query = "
    SELECT 
        e.id as etudiant_id,
        e.matricule,
        e.nom,
        e.prenom,
        c.nom as classe_nom,
        COUNT(p.id) as jours_absents,
        MAX(p.date_heure) as derniere_absence,
        GROUP_CONCAT(DISTINCT DATE(p.date_heure) ORDER BY p.date_heure DESC SEPARATOR ', ') as dates_absences
    FROM etudiants e
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN presences p ON e.id = p.etudiant_id 
        AND p.statut = 'absent'
        AND DATE(p.date_heure) BETWEEN :date_debut AND :date_fin
    WHERE e.site_id = :site_id 
      AND e.statut = 'actif'
    GROUP BY e.id, e.matricule, e.nom, e.prenom, c.nom
    HAVING jours_absents > 0
    ORDER BY jours_absents DESC, e.nom, e.prenom
";

$stmt = $db->prepare($query);
$stmt->execute([
    ':site_id' => $site_id,
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
]);
$absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats_query = "
    SELECT 
        COUNT(DISTINCT e.id) as total_absents,
        SUM(CASE WHEN a.jours_absents >= 3 THEN 1 ELSE 0 END) as absences_prolongees
    FROM (
        SELECT 
            e.id,
            COUNT(p.id) as jours_absents
        FROM etudiants e
        LEFT JOIN presences p ON e.id = p.etudiant_id 
            AND p.statut = 'absent'
            AND DATE(p.date_heure) BETWEEN :date_debut2 AND :date_fin2
        WHERE e.site_id = :site_id2
        GROUP BY e.id
        HAVING jours_absents > 0
    ) a
    LEFT JOIN etudiants e ON a.id = e.id
";

$stmt = $db->prepare($stats_query);
$stmt->execute([
    ':site_id2' => $site_id,
    ':date_debut2' => $date_debut,
    ':date_fin2' => $date_fin
]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    .absence-card {
        border-left: 4px solid #dc3545;
    }
    .absence-prolongee {
        border-left: 4px solid #ffc107;
        background-color: #fff3cd;
    }
    .table-absences th {
        background-color: #f8d7da;
    }
    .badge-absence {
        background-color: #dc3545;
    }
    .badge-prolongee {
        background-color: #ffc107;
    }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-times me-2"></i>
                Gestion des Absences
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presences.php">
                            <i class="fas fa-calendar-check me-1"></i> Présences
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="fas fa-user-times text-danger me-2"></i>Absences</h2>
                        <p class="text-muted">Suivi des absences et justifications</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Période du</label>
                        <input type="date" class="form-control" name="date_debut" 
                               value="<?php echo htmlspecialchars($date_debut); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">au</label>
                        <input type="date" class="form-control" name="date_fin" 
                               value="<?php echo htmlspecialchars($date_fin); ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-danger me-2">
                            <i class="fas fa-filter me-1"></i> Filtrer
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="location.href='absences.php'">
                            <i class="fas fa-redo me-1"></i> Réinitialiser
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Étudiants Absents</h6>
                                <h3 class="mb-0"><?php echo $stats['total_absents'] ?? 0; ?></h3>
                            </div>
                            <i class="fas fa-user-times fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Absences Prolongées</h6>
                                <h3 class="mb-0"><?php echo $stats['absences_prolongees'] ?? 0; ?></h3>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Période</h6>
                                <h6 class="mb-0">
                                    <?php echo date('d/m/Y', strtotime($date_debut)); ?> - 
                                    <?php echo date('d/m/Y', strtotime($date_fin)); ?>
                                </h6>
                            </div>
                            <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Liste des absences -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    Liste des Absences
                </h5>
                <div>
                    <button class="btn btn-sm btn-success" onclick="sendAbsenceNotifications()">
                        <i class="fas fa-bell me-1"></i> Notifier les parents
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if(empty($absences)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Aucune absence enregistrée sur cette période.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-absences">
                        <thead>
                            <tr>
                                <th>Étudiant</th>
                                <th>Matricule</th>
                                <th>Classe</th>
                                <th>Jours d'absence</th>
                                <th>Dates</th>
                                <th>Dernière absence</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($absences as $absence): ?>
                            <?php 
                            $is_prolongee = $absence['jours_absents'] >= 3;
                            $card_class = $is_prolongee ? 'absence-prolongee' : 'absence-card';
                            ?>
                            <tr class="<?php echo $card_class; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($absence['nom'] . ' ' . $absence['prenom']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($absence['matricule']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($absence['classe_nom']); ?></td>
                                <td>
                                    <span class="badge <?php echo $is_prolongee ? 'badge-prolongee' : 'badge-absence'; ?>">
                                        <?php echo $absence['jours_absents']; ?> jour(s)
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo $absence['dates_absences']; ?></small>
                                </td>
                                <td>
                                    <?php echo $absence['derniere_absence'] ? 
                                        date('d/m/Y', strtotime($absence['derniere_absence'])) : 'N/A'; ?>
                                </td>
                                <td>
                                    <?php if($is_prolongee): ?>
                                    <span class="badge bg-warning">
                                        <i class="fas fa-exclamation-triangle me-1"></i> Prolongée
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Simple</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" 
                                                onclick="viewStudentAbsences(<?php echo $absence['etudiant_id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-success" 
                                                onclick="justifyAbsence(<?php echo $absence['etudiant_id']; ?>)">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" 
                                                onclick="contactParent(<?php echo $absence['etudiant_id']; ?>)">
                                            <i class="fas fa-phone"></i>
                                        </button>
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
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    function viewStudentAbsences(studentId) {
        window.location.href = 'etudiant_absences.php?id=' + studentId;
    }
    
    function justifyAbsence(studentId) {
        Swal.fire({
            title: 'Justifier l\'absence',
            input: 'textarea',
            inputLabel: 'Motif de justification',
            inputPlaceholder: 'Entrez le motif de l\'absence...',
            showCancelButton: true,
            confirmButtonText: 'Justifier',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax/justify_absence.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'student_id=' + studentId + '&motif=' + encodeURIComponent(result.value)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Succès !', data.message, 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Erreur !', data.message, 'error');
                    }
                });
            }
        });
    }
    
    function contactParent(studentId) {
        Swal.fire({
            title: 'Contacter le parent',
            html: `
                <div class="text-start">
                    <p>Cette fonctionnalité enverra un SMS/Email au parent/tuteur.</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="smsCheck">
                        <label class="form-check-label" for="smsCheck">
                            Envoyer un SMS
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="emailCheck">
                        <label class="form-check-label" for="emailCheck">
                            Envoyer un Email
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Envoyer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                // TODO: Implémenter l'envoi de notification
                Swal.fire('Information', 'Notification envoyée au parent', 'info');
            }
        });
    }
    
    function sendAbsenceNotifications() {
        Swal.fire({
            title: 'Notifier tous les parents',
            text: 'Envoyer des notifications pour toutes les absences de cette période ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Oui, notifier',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                // TODO: Implémenter l'envoi groupé
                Swal.fire('En cours...', 'Notifications en cours d\'envoi', 'info');
            }
        });
    }
    </script>
</body>
</html>