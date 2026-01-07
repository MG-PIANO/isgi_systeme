<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Vérifier l'authentification
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], [1, 2, 3, 4])) {
    header('Location: login.php');
    exit();
}

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filtres
$filters = [];
$params = [];

if (isset($_GET['etudiant']) && !empty($_GET['etudiant'])) {
    $filters[] = "e.matricule LIKE :etudiant OR e.nom LIKE :etudiant";
    $params[':etudiant'] = '%' . $_GET['etudiant'] . '%';
}

if (isset($_GET['reference']) && !empty($_GET['reference'])) {
    $filters[] = "p.reference LIKE :reference";
    $params[':reference'] = '%' . $_GET['reference'] . '%';
}

if (isset($_GET['statut']) && !empty($_GET['statut'])) {
    $filters[] = "p.statut = :statut";
    $params[':statut'] = $_GET['statut'];
}

if (isset($_GET['date_debut']) && !empty($_GET['date_debut'])) {
    $filters[] = "p.date_paiement >= :date_debut";
    $params[':date_debut'] = $_GET['date_debut'];
}

if (isset($_GET['date_fin']) && !empty($_GET['date_fin'])) {
    $filters[] = "p.date_paiement <= :date_fin";
    $params[':date_fin'] = $_GET['date_fin'];
}

// Construire la requête
$where = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

// Compter le total
$sql_count = "SELECT COUNT(*) as total 
              FROM paiements p
              JOIN etudiants e ON p.etudiant_id = e.id
              $where";

$stmt = $pdo->prepare($sql_count);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total / $limit);

// Récupérer les paiements
$sql = "SELECT 
            p.*,
            e.matricule,
            CONCAT(e.nom, ' ', e.prenom) as etudiant_nom,
            tf.nom as type_frais,
            aa.libelle as annee_academique,
            s.nom as site_nom,
            CONCAT(u.nom, ' ', u.prenom) as caissier_nom
        FROM paiements p
        JOIN etudiants e ON p.etudiant_id = e.id
        JOIN types_frais tf ON p.type_frais_id = tf.id
        JOIN annees_academiques aa ON p.annee_academique_id = aa.id
        JOIN sites s ON e.site_id = s.id
        LEFT JOIN utilisateurs u ON p.caissier_id = u.id
        $where
        ORDER BY p.date_paiement DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$sql_stats = "SELECT 
                COUNT(*) as total_paiements,
                SUM(montant) as total_montant,
                SUM(frais_transaction) as total_frais,
                SUM(montant_net) as total_net
              FROM paiements
              WHERE statut = 'valide'";
$stmt = $pdo->query($sql_stats);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Paiements - ISGI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="card-title">Total Paiements</h6>
                        <h3><?php echo number_format($total, 0, ',', ' '); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="card-title">Montant Total</h6>
                        <h3><?php echo number_format($stats['total_montant'] ?? 0, 0, ',', ' '); ?> XAF</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <h6 class="card-title">Frais de Transaction</h6>
                        <h3><?php echo number_format($stats['total_frais'] ?? 0, 0, ',', ' '); ?> XAF</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6 class="card-title">Montant Net</h6>
                        <h3><?php echo number_format($stats['total_net'] ?? 0, 0, ',', ' '); ?> XAF</h3>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-funnel"></i> Filtres de recherche</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="etudiant" 
                                   placeholder="Matricule ou nom étudiant" 
                                   value="<?php echo $_GET['etudiant'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control" name="reference" 
                                   placeholder="Référence paiement"
                                   value="<?php echo $_GET['reference'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="en_attente" <?php echo (($_GET['statut'] ?? '') == 'en_attente') ? 'selected' : ''; ?>>En attente</option>
                                <option value="valide" <?php echo (($_GET['statut'] ?? '') == 'valide') ? 'selected' : ''; ?>>Validé</option>
                                <option value="annule" <?php echo (($_GET['statut'] ?? '') == 'annule') ? 'selected' : ''; ?>>Annulé</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_debut" 
                                   value="<?php echo $_GET['date_debut'] ?? ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_fin" 
                                   value="<?php echo $_GET['date_fin'] ?? ''; ?>">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tableau des paiements -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-list-check"></i> Liste des Paiements</h5>
                <a href="paiement_form.php" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Nouveau Paiement
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="tablePaiements">
                        <thead>
                            <tr>
                                <th>Référence</th>
                                <th>Étudiant</th>
                                <th>Type de frais</th>
                                <th>Montant</th>
                                <th>Mode</th>
                                <th>Date</th>
                                <th>Caissier</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paiements as $paiement): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $paiement['reference']; ?></strong>
                                        <?php if ($paiement['numero_transaction']): ?>
                                            <br><small class="text-muted">Trans: <?php echo $paiement['numero_transaction']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $paiement['matricule']; ?><br>
                                        <small><?php echo $paiement['etudiant_nom']; ?></small>
                                    </td>
                                    <td><?php echo $paiement['type_frais']; ?></td>
                                    <td class="text-end">
                                        <strong><?php echo number_format($paiement['montant'], 0, ',', ' '); ?> XAF</strong><br>
                                        <small class="text-muted">Net: <?php echo number_format($paiement['montant_net'], 0, ',', ' '); ?> XAF</small>
                                    </td>
                                    <td>
                                        <?php echo ucfirst(str_replace('_', ' ', $paiement['mode_paiement'])); ?>
                                        <?php if ($paiement['frais_transaction'] > 0): ?>
                                            <br><small class="text-danger">Frais: <?php echo number_format($paiement['frais_transaction'], 0, ',', ' '); ?> XAF</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($paiement['date_paiement'])); ?></td>
                                    <td><?php echo $paiement['caissier_nom']; ?></td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'en_attente' => 'warning',
                                            'valide' => 'success',
                                            'annule' => 'danger',
                                            'rembourse' => 'info'
                                        ][$paiement['statut']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>">
                                            <?php echo ucfirst($paiement['statut']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" title="Voir détails" 
                                                    onclick="afficherDetails(<?php echo $paiement['id']; ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-primary" title="Imprimer reçu" 
                                                    onclick="imprimerRecu(<?php echo $paiement['id']; ?>)">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <?php if ($_SESSION['role'] <= 2 && $paiement['statut'] == 'en_attente'): ?>
                                                <button class="btn btn-success" title="Valider" 
                                                        onclick="validerPaiement(<?php echo $paiement['id']; ?>)">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button class="btn btn-danger" title="Annuler" 
                                                        onclick="annulerPaiement(<?php echo $paiement['id']; ?>)">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo isset($_GET['etudiant']) ? '&etudiant=' . $_GET['etudiant'] : ''; ?>">
                                        Précédent
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo isset($_GET['etudiant']) ? '&etudiant=' . $_GET['etudiant'] : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo isset($_GET['etudiant']) ? '&etudiant=' . $_GET['etudiant'] : ''; ?>">
                                        Suivant
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal pour les détails -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails du Paiement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Contenu chargé via AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialiser DataTable
        $(document).ready(function() {
            $('#tablePaiements').DataTable({
                pageLength: 20,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/fr-FR.json'
                }
            });
        });
        
        // Afficher les détails d'un paiement
        function afficherDetails(paiementId) {
            $.ajax({
                url: 'ajax_get_paiement_details.php',
                method: 'POST',
                data: { id: paiementId },
                success: function(response) {
                    $('#modalBody').html(response);
                    $('#detailsModal').modal('show');
                }
            });
        }
        
        // Valider un paiement
        function validerPaiement(paiementId) {
            if (confirm('Êtes-vous sûr de vouloir valider ce paiement ?')) {
                $.ajax({
                    url: 'ajax_valider_paiement.php',
                    method: 'POST',
                    data: { id: paiementId },
                    success: function(response) {
                        alert(response.message);
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            }
        }
        
        // Annuler un paiement
        function annulerPaiement(paiementId) {
            const motif = prompt('Veuillez indiquer le motif de l\'annulation :');
            if (motif) {
                $.ajax({
                    url: 'ajax_annuler_paiement.php',
                    method: 'POST',
                    data: { 
                        id: paiementId,
                        motif: motif 
                    },
                    success: function(response) {
                        alert(response.message);
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            }
        }
        
        // Imprimer un reçu
        function imprimerRecu(paiementId) {
            window.open('recu_paiement.php?id=' + paiementId, '_blank');
        }
    </script>
</body>
</html>