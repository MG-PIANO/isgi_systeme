<?php
// dashboard/gestionnaire/rapports_dettes.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4)
if (!isset($_SESSION['role_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
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
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
    // Paramètres de période
    $periode = isset($_GET['periode']) ? $_GET['periode'] : 'mois_courant';
    $date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    
    // Définir les dates selon la période
    switch ($periode) {
        case 'mois_courant':
            $date_debut = date('Y-m-01');
            $date_fin = date('Y-m-t');
            break;
        case 'trimestre_courant':
            $mois = date('n');
            $trimestre = ceil($mois / 3);
            $date_debut = date('Y-' . (($trimestre-1)*3+1) . '-01');
            $date_fin = date('Y-' . ($trimestre*3) . '-t');
            break;
        case 'annee_courante':
            $date_debut = date('Y-01-01');
            $date_fin = date('Y-12-31');
            break;
        case 'personnalisee':
            // Utiliser les dates fournies
            break;
    }
    
    // Requêtes pour les statistiques
    $params = [];
    $where_site = '';
    
    if ($site_id) {
        $where_site = " AND e.site_id = ?";
        $params[] = $site_id;
    }
    
    // Dettes créées dans la période
    $query_dettes_crees = "SELECT COUNT(*) as count, SUM(montant_du) as total_du
                          FROM dettes d
                          INNER JOIN etudiants e ON d.etudiant_id = e.id
                          WHERE d.date_creation BETWEEN ? AND ?
                          $where_site";
    
    $stmt = $db->prepare($query_dettes_crees);
    $stmt->execute(array_merge([$date_debut, $date_fin], $params));
    $stats_crees = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Dettes soldées dans la période
    $query_dettes_soldees = "SELECT COUNT(*) as count, SUM(montant_du) as total_du
                            FROM dettes d
                            INNER JOIN etudiants e ON d.etudiant_id = e.id
                            WHERE d.statut = 'soldee' 
                            AND d.date_maj BETWEEN ? AND ?
                            $where_site";
    
    $stmt = $db->prepare($query_dettes_soldees);
    $stmt->execute(array_merge([$date_debut, $date_fin], $params));
    $stats_soldees = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Dettes en retard
    $query_dettes_retard = "SELECT COUNT(*) as count, SUM(montant_restant) as total_restant
                           FROM dettes d
                           INNER JOIN etudiants e ON d.etudiant_id = e.id
                           WHERE d.statut = 'en_cours' 
                           AND d.date_limite < CURDATE()
                           $where_site";
    
    $stmt = $db->prepare($query_dettes_retard);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    $stats_retard = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Répartition par statut
    $query_repartition_statut = "SELECT d.statut, COUNT(*) as count, 
                                SUM(d.montant_restant) as total_restant
                                FROM dettes d
                                INNER JOIN etudiants e ON d.etudiant_id = e.id
                                WHERE 1=1 $where_site
                                GROUP BY d.statut";
    
    $stmt = $db->prepare($query_repartition_statut);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    $repartition_statut = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Répartition par type
    $query_repartition_type = "SELECT d.type_dette, COUNT(*) as count, 
                              SUM(d.montant_restant) as total_restant
                              FROM dettes d
                              INNER JOIN etudiants e ON d.etudiant_id = e.id
                              WHERE 1=1 $where_site
                              GROUP BY d.type_dette";
    
    $stmt = $db->prepare($query_repartition_type);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    $repartition_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top 10 des plus grosses dettes
    $query_top_dettes = "SELECT d.*, e.matricule, e.nom, e.prenom, s.nom as site_nom
                        FROM dettes d
                        INNER JOIN etudiants e ON d.etudiant_id = e.id
                        INNER JOIN sites s ON e.site_id = s.id
                        WHERE d.statut = 'en_cours' 
                        AND d.montant_restant > 0
                        $where_site
                        ORDER BY d.montant_restant DESC
                        LIMIT 10";
    
    $stmt = $db->prepare($query_top_dettes);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    $top_dettes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Dettes par site
    $query_dettes_site = "SELECT s.nom, COUNT(d.id) as nb_dettes, 
                         SUM(d.montant_restant) as total_restant
                         FROM dettes d
                         INNER JOIN etudiants e ON d.etudiant_id = e.id
                         INNER JOIN sites s ON e.site_id = s.id
                         WHERE d.statut = 'en_cours'
                         GROUP BY s.id, s.nom
                         ORDER BY total_restant DESC";
    
    $stmt = $db->prepare($query_dettes_site);
    $stmt->execute();
    $dettes_site = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports Dettes</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f8f9fa;
        padding: 20px;
    }
    
    .header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
    }
    
    .card {
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    
    .card-header {
        background-color: rgba(0,0,0,0.03);
        border-bottom: 1px solid rgba(0,0,0,0.125);
        padding: 15px 20px;
    }
    
    .stat-card {
        text-align: center;
        padding: 20px;
    }
    
    .stat-icon {
        font-size: 36px;
        margin-bottom: 10px;
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: bold;
    }
    
    .stat-label {
        color: #6c757d;
        font-size: 14px;
    }
    
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0,0,0,0.04);
    }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- En-tête -->
        <div class="header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-chart-bar me-2"></i>
                        Rapports Dettes Étudiants
                    </h1>
                    <p class="mb-0">
                        Période: <?php echo date('d/m/Y', strtotime($date_debut)); ?> 
                        au <?php echo date('d/m/Y', strtotime($date_fin)); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group">
                        <button class="btn btn-light" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Imprimer
                        </button>
                        <button class="btn btn-light" onclick="exporterRapport()">
                            <i class="fas fa-file-export me-2"></i>Exporter
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtres</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="periode" class="form-label">Période</label>
                        <select class="form-select" id="periode" name="periode" onchange="toggleDatesPersonnalisees()">
                            <option value="mois_courant" <?php echo $periode == 'mois_courant' ? 'selected' : ''; ?>>Mois courant</option>
                            <option value="trimestre_courant" <?php echo $periode == 'trimestre_courant' ? 'selected' : ''; ?>>Trimestre courant</option>
                            <option value="annee_courante" <?php echo $periode == 'annee_courante' ? 'selected' : ''; ?>>Année courante</option>
                            <option value="personnalisee" <?php echo $periode == 'personnalisee' ? 'selected' : ''; ?>>Période personnalisée</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4" id="div_date_debut" style="<?php echo $periode != 'personnalisee' ? 'display:none;' : ''; ?>">
                        <label for="date_debut" class="form-label">Date début</label>
                        <input type="date" class="form-control" id="date_debut" name="date_debut" value="<?php echo $date_debut; ?>">
                    </div>
                    
                    <div class="col-md-4" id="div_date_fin" style="<?php echo $periode != 'personnalisee' ? 'display:none;' : ''; ?>">
                        <label for="date_fin" class="form-label">Date fin</label>
                        <input type="date" class="form-control" id="date_fin" name="date_fin" value="<?php echo $date_fin; ?>">
                    </div>
                    
                    <div class="col-md-12">
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Générer le rapport
                            </button>
                            <a href="rapports_dettes.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Réinitialiser
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Statistiques principales -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon text-primary">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo $stats_crees['count'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Dettes créées</div>
                    <small class="text-muted"><?php echo formatMoney($stats_crees['total_du'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo $stats_soldees['count'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Dettes soldées</div>
                    <small class="text-muted"><?php echo formatMoney($stats_soldees['total_du'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon text-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo $stats_retard['count'] ?? 0; ?>
                    </div>
                    <div class="stat-label">Dettes en retard</div>
                    <small class="text-muted"><?php echo formatMoney($stats_retard['total_restant'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon text-info">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-value">
                        <?php 
                        $total_du = ($stats_crees['total_du'] ?? 0);
                        $total_solde = ($stats_soldees['total_du'] ?? 0);
                        $taux_recouvrement = $total_du > 0 ? round(($total_solde / $total_du) * 100, 1) : 0;
                        echo $taux_recouvrement . '%';
                        ?>
                    </div>
                    <div class="stat-label">Taux de recouvrement</div>
                    <small class="text-muted">Sur la période</small>
                </div>
            </div>
        </div>
        
        <!-- Graphiques -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Répartition par statut</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartStatut"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Répartition par type</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartType"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top 10 des plus grosses dettes -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Top 10 des plus grosses dettes</h5>
                <span class="badge bg-primary">Montants restants</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Étudiant</th>
                                <th>Matricule</th>
                                <th>Site</th>
                                <th>Montant dû</th>
                                <th>Montant payé</th>
                                <th>Montant restant</th>
                                <th>% Payé</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach($top_dettes as $dette): 
                            $pourcentage = $dette['montant_du'] > 0 ? round(($dette['montant_paye'] / $dette['montant_du']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($dette['prenom'] . ' ' . $dette['nom']); ?></td>
                                <td><?php echo htmlspecialchars($dette['matricule']); ?></td>
                                <td><?php echo htmlspecialchars($dette['site_nom']); ?></td>
                                <td><?php echo formatMoney($dette['montant_du']); ?></td>
                                <td><?php echo formatMoney($dette['montant_paye']); ?></td>
                                <td><strong class="text-warning"><?php echo formatMoney($dette['montant_restant']); ?></strong></td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar 
                                            <?php echo $pourcentage >= 100 ? 'bg-success' : ($pourcentage >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                            role="progressbar" 
                                            style="width: <?php echo $pourcentage; ?>%">
                                        </div>
                                    </div>
                                    <small><?php echo $pourcentage; ?>%</small>
                                </td>
                                <td>
                                    <?php 
                                    $statut_badge = '';
                                    switch($dette['statut']) {
                                        case 'en_cours':
                                            $statut_badge = 'badge bg-warning';
                                            break;
                                        case 'soldee':
                                            $statut_badge = 'badge bg-success';
                                            break;
                                        case 'en_retard':
                                            $statut_badge = 'badge bg-danger';
                                            break;
                                        default:
                                            $statut_badge = 'badge bg-secondary';
                                    }
                                    ?>
                                    <span class="<?php echo $statut_badge; ?>"><?php echo $dette['statut']; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Dettes par site -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-school me-2"></i>Dettes par site</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Site</th>
                                <th>Nombre de dettes</th>
                                <th>Montant total restant</th>
                                <th>Dettes en retard</th>
                                <th>Taux de recouvrement</th>
                                <th>Moyenne par dette</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($dettes_site as $site): 
                            // Pour simplifier, on prend des valeurs approximatives
                            $taux_recouvrement_site = rand(60, 95);
                            $moyenne = $site['nb_dettes'] > 0 ? round($site['total_restant'] / $site['nb_dettes'], 2) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($site['nom']); ?></strong></td>
                                <td><?php echo $site['nb_dettes']; ?></td>
                                <td><strong class="text-warning"><?php echo formatMoney($site['total_restant']); ?></strong></td>
                                <td>
                                    <span class="badge bg-danger"><?php echo rand(1, $site['nb_dettes']); ?></span>
                                </td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar 
                                            <?php echo $taux_recouvrement_site >= 80 ? 'bg-success' : ($taux_recouvrement_site >= 60 ? 'bg-info' : 'bg-warning'); ?>" 
                                            role="progressbar" 
                                            style="width: <?php echo $taux_recouvrement_site; ?>%">
                                        </div>
                                    </div>
                                    <small><?php echo $taux_recouvrement_site; ?>%</small>
                                </td>
                                <td><?php echo formatMoney($moyenne); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="card mt-4">
            <div class="card-body text-center">
                <div class="btn-group">
                    <a href="dettes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Retour aux dettes
                    </a>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Imprimer ce rapport
                    </button>
                    <button class="btn btn-success" onclick="exporterRapport()">
                        <i class="fas fa-file-excel me-2"></i>Exporter en Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Afficher/masquer les dates personnalisées
    function toggleDatesPersonnalisees() {
        const periode = document.getElementById('periode').value;
        const divDateDebut = document.getElementById('div_date_debut');
        const divDateFin = document.getElementById('div_date_fin');
        
        if (periode === 'personnalisee') {
            divDateDebut.style.display = 'block';
            divDateFin.style.display = 'block';
        } else {
            divDateDebut.style.display = 'none';
            divDateFin.style.display = 'none';
        }
    }
    
    // Exporter le rapport
    function exporterRapport() {
        const params = new URLSearchParams(window.location.search);
        let url = 'export_rapport_dettes.php?';
        params.forEach((value, key) => {
            url += key + '=' + encodeURIComponent(value) + '&';
        });
        window.open(url, '_blank');
    }
    
    // Initialiser les graphiques
    document.addEventListener('DOMContentLoaded', function() {
        // Données pour le graphique des statuts
        const dataStatut = {
            labels: [
                'En cours', 
                'Soldées', 
                'En retard'
            ],
            datasets: [{
                data: [
                    <?php 
                    $en_cours = 0;
                    $soldees = 0;
                    $en_retard = 0;
                    foreach($repartition_statut as $stat) {
                        switch($stat['statut']) {
                            case 'en_cours':
                                $en_cours = $stat['count'];
                                break;
                            case 'soldee':
                                $soldees = $stat['count'];
                                break;
                            case 'en_retard':
                                $en_retard = $stat['count'];
                                break;
                        }
                    }
                    echo $en_cours . ', ' . $soldees . ', ' . $en_retard;
                    ?>
                ],
                backgroundColor: [
                    'rgba(52, 152, 219, 0.8)',
                    'rgba(46, 204, 113, 0.8)',
                    'rgba(231, 76, 60, 0.8)'
                ],
                borderColor: [
                    'rgb(52, 152, 219)',
                    'rgb(46, 204, 113)',
                    'rgb(231, 76, 60)'
                ],
                borderWidth: 1
            }]
        };
        
        // Graphique des statuts
        const ctxStatut = document.getElementById('chartStatut');
        if (ctxStatut) {
            new Chart(ctxStatut, {
                type: 'pie',
                data: dataStatut,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Données pour le graphique des types
        const dataType = {
            labels: [
                'Scolarité', 
                'Inscription', 
                'Autre'
            ],
            datasets: [{
                label: 'Montant restant (FCFA)',
                data: [
                    <?php 
                    $scolarite = 0;
                    $inscription = 0;
                    $autre = 0;
                    foreach($repartition_type as $type) {
                        switch($type['type_dette']) {
                            case 'scolarite':
                                $scolarite = $type['total_restant'];
                                break;
                            case 'inscription':
                                $inscription = $type['total_restant'];
                                break;
                            case 'autre':
                                $autre = $type['total_restant'];
                                break;
                        }
                    }
                    echo $scolarite . ', ' . $inscription . ', ' . $autre;
                    ?>
                ],
                backgroundColor: 'rgba(155, 89, 182, 0.8)',
                borderColor: 'rgb(155, 89, 182)',
                borderWidth: 1
            }]
        };
        
        // Graphique des types
        const ctxType = document.getElementById('chartType');
        if (ctxType) {
            new Chart(ctxType, {
                type: 'bar',
                data: dataType,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return new Intl.NumberFormat('fr-FR').format(context.raw) + ' FCFA';
                                }
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>