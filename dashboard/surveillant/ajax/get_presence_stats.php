<?php
// dashboard/surveillant/ajax/get_presence_stats.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    exit('Accès non autorisé');
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];

// Récupérer les paramètres
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$classe_id = $_GET['classe_id'] ?? '';

// Statistiques par type
$query = "
    SELECT 
        type_presence,
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
        SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
        SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards
    FROM presences 
    WHERE site_id = :site_id 
      AND DATE(date_heure) BETWEEN :date_debut AND :date_fin
    " . ($classe_id ? " AND etudiant_id IN (SELECT id FROM etudiants WHERE classe_id = :classe_id)" : "") . "
    GROUP BY type_presence
    ORDER BY total DESC
";

$params = [
    ':site_id' => $site_id,
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
];
if ($classe_id) $params[':classe_id'] = $classe_id;

$stmt = $db->prepare($query);
$stmt->execute($params);
$stats_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques par classe
$query = "
    SELECT 
        c.nom as classe_nom,
        COUNT(p.id) as total_presences,
        COUNT(DISTINCT e.id) as nb_etudiants,
        ROUND(COUNT(p.id) * 100.0 / (COUNT(DISTINCT e.id) * DATEDIFF(:date_fin, :date_debut) + 1), 1) as taux_presence
    FROM classes c
    LEFT JOIN etudiants e ON c.id = e.classe_id
    LEFT JOIN presences p ON e.id = p.etudiant_id 
        AND DATE(p.date_heure) BETWEEN :date_debut2 AND :date_fin2
    WHERE c.site_id = :site_id
    GROUP BY c.id
    ORDER BY taux_presence DESC
";

$params[':date_debut2'] = $date_debut;
$params[':date_fin2'] = $date_fin;
$stmt = $db->prepare($query);
$stmt->execute($params);
$stats_classe = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Évolution quotidienne
$query = "
    SELECT 
        DATE(date_heure) as date_jour,
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents
    FROM presences 
    WHERE site_id = :site_id 
      AND DATE(date_heure) BETWEEN :date_debut3 AND :date_fin3
    GROUP BY DATE(date_heure)
    ORDER BY date_jour
";

$params[':date_debut3'] = $date_debut;
$params[':date_fin3'] = $date_fin;
$stmt = $db->prepare($query);
$stmt->execute($params);
$stats_evolution = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-md-6">
        <h5><i class="fas fa-chart-pie me-2"></i>Répartition par Type</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Présents</th>
                        <th>Absents</th>
                        <th>Retards</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($stats_type as $stat): ?>
                    <tr>
                        <td>
                            <?php 
                            $types = [
                                'entree_ecole' => 'Entrée École',
                                'sortie_ecole' => 'Sortie École',
                                'entree_classe' => 'Entrée Classe',
                                'sortie_classe' => 'Sortie Classe'
                            ];
                            echo $types[$stat['type_presence']] ?? $stat['type_presence'];
                            ?>
                        </td>
                        <td><?php echo $stat['total']; ?></td>
                        <td class="text-success"><?php echo $stat['presents']; ?></td>
                        <td class="text-danger"><?php echo $stat['absents']; ?></td>
                        <td class="text-warning"><?php echo $stat['retards']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="col-md-6">
        <h5><i class="fas fa-chart-bar me-2"></i>Taux de Présence par Classe</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Classe</th>
                        <th>Étudiants</th>
                        <th>Présences</th>
                        <th>Taux</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($stats_classe as $stat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stat['classe_nom']); ?></td>
                        <td><?php echo $stat['nb_etudiants']; ?></td>
                        <td><?php echo $stat['total_presences']; ?></td>
                        <td>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar <?php echo $stat['taux_presence'] > 80 ? 'bg-success' : ($stat['taux_presence'] > 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                                     style="width: <?php echo min(100, $stat['taux_presence']); ?>%">
                                    <?php echo $stat['taux_presence']; ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <h5><i class="fas fa-chart-line me-2"></i>Évolution Quotidienne</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Présents</th>
                        <th>Taux</th>
                        <th>Tendance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $previous_rate = null;
                    foreach($stats_evolution as $stat): 
                        $rate = $stat['total'] > 0 ? round(($stat['presents'] / $stat['total']) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><?php echo date('d/m', strtotime($stat['date_jour'])); ?></td>
                        <td><?php echo $stat['total']; ?></td>
                        <td><?php echo $stat['presents']; ?></td>
                        <td>
                            <span class="badge <?php echo $rate > 80 ? 'bg-success' : ($rate > 60 ? 'bg-warning' : 'bg-danger'); ?>">
                                <?php echo $rate; ?>%
                            </span>
                        </td>
                        <td>
                            <?php if($previous_rate !== null): ?>
                                <?php if($rate > $previous_rate): ?>
                                    <i class="fas fa-arrow-up text-success"></i>
                                <?php elseif($rate < $previous_rate): ?>
                                    <i class="fas fa-arrow-down text-danger"></i>
                                <?php else: ?>
                                    <i class="fas fa-equals text-muted"></i>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php 
                        $previous_rate = $rate;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>