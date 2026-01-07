<?php
// dashboard/gestionnaire/details_dette.php

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
    
    // Récupérer l'ID de la dette
    $dette_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($dette_id <= 0) {
        die("ID de dette invalide");
    }
    
    // Récupérer les détails de la dette
    $query = "SELECT d.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
              e.telephone, e.email, e.adresse,
              aa.libelle as annee_academique,
              s.nom as site_nom,
              CONCAT(u1.nom, ' ', u1.prenom) as gestionnaire_nom,
              CONCAT(u2.nom, ' ', u2.prenom) as createur_nom
              FROM dettes d
              INNER JOIN etudiants e ON d.etudiant_id = e.id
              INNER JOIN annees_academiques aa ON d.annee_academique_id = aa.id
              INNER JOIN sites s ON e.site_id = s.id
              LEFT JOIN utilisateurs u1 ON d.gestionnaire_id = u1.id
              LEFT JOIN utilisateurs u2 ON d.cree_par = u2.id
              WHERE d.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$dette_id]);
    $dette = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dette) {
        die("Dette non trouvée");
    }
    
    // Récupérer les paiements associés
    $query_paiements = "SELECT p.*, tf.nom as type_frais, 
                       CONCAT(u.nom, ' ', u.prenom) as caissier_nom
                       FROM paiements p
                       INNER JOIN types_frais tf ON p.type_frais_id = tf.id
                       LEFT JOIN utilisateurs u ON p.caissier_id = u.id
                       WHERE p.etudiant_id = ? AND p.annee_academique_id = ?
                       AND p.statut = 'valide'
                       ORDER BY p.date_paiement DESC";
    
    $stmt = $db->prepare($query_paiements);
    $stmt->execute([$dette['etudiant_id'], $dette['annee_academique_id']]);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer l'historique des modifications
    $query_historique = "SELECT hm.*, 
                        CONCAT(u.nom, ' ', u.prenom) as utilisateur_nom
                        FROM historique_modifications_dettes hm
                        LEFT JOIN utilisateurs u ON hm.utilisateur_id = u.id
                        WHERE hm.dette_id = ?
                        ORDER BY hm.date_modification DESC";
    
    $stmt = $db->prepare($query_historique);
    $stmt->execute([$dette_id]);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les échéances si plan de paiement
    $query_echeances = "SELECT ed.*, ppd.nombre_tranches, ppd.frequence
                       FROM echeances_dettes ed
                       LEFT JOIN plans_paiement_dettes ppd ON ed.plan_id = ppd.id
                       WHERE ed.dette_id = ? 
                       ORDER BY ed.date_echeance ASC";
    
    $stmt = $db->prepare($query_echeances);
    $stmt->execute([$dette_id]);
    $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function getStatutBadgeDette($statut) {
        switch ($statut) {
            case 'en_cours':
                return '<span class="badge bg-warning">En cours</span>';
            case 'soldee':
                return '<span class="badge bg-success">Soldée</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeDetteBadge($type) {
        switch ($type) {
            case 'scolarite':
                return '<span class="badge bg-primary">Scolarité</span>';
            case 'inscription':
                return '<span class="badge bg-info">Inscription</span>';
            case 'autre':
                return '<span class="badge bg-secondary">Autre</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    function getPourcentagePaiement($montant_du, $montant_paye) {
        if ($montant_du <= 0) return 0;
        return min(100, round(($montant_paye / $montant_du) * 100));
    }
    
    function getJoursRetard($date_limite) {
        if (!$date_limite) return 0;
        $date_limit = new DateTime($date_limite);
        $now = new DateTime();
        if ($date_limit > $now) return 0;
        $interval = $date_limit->diff($now);
        return $interval->days;
    }
    
    // Calculer les valeurs
    $pourcentage = getPourcentagePaiement($dette['montant_du'], $dette['montant_paye']);
    $jours_retard = getJoursRetard($dette['date_limite']);
    $en_retard = ($dette['statut'] == 'en_cours' && $dette['date_limite'] && strtotime($dette['date_limite']) < time());
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la Dette - <?php echo htmlspecialchars($dette['matricule']); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
    
    .info-badge {
        font-size: 0.9em;
        padding: 5px 10px;
    }
    
    .montant-du {
        color: #e74c3c;
        font-weight: bold;
    }
    
    .montant-paye {
        color: #27ae60;
        font-weight: bold;
    }
    
    .montant-restant {
        color: #f39c12;
        font-weight: bold;
    }
    
    .progress-dette {
        height: 25px;
        border-radius: 12px;
        overflow: hidden;
    }
    
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: #dee2e6;
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -24px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: #3498db;
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0,0,0,0.04);
    }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Détails de la Dette
                    </h1>
                    <h4 class="mb-0">
                        <?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?>
                    </h4>
                    <p class="mb-0">
                        <?php echo htmlspecialchars($dette['matricule']); ?> - 
                        <?php echo htmlspecialchars($dette['site_nom']); ?> - 
                        <?php echo htmlspecialchars($dette['annee_academique']); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="dettes.php" class="btn btn-light me-2">
                        <i class="fas fa-arrow-left me-2"></i>Retour
                    </a>
                    <button class="btn btn-light" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Imprimer
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Résumé -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="text-primary" style="font-size: 24px;">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3><?php echo formatMoney($dette['montant_du']); ?></h3>
                        <p class="text-muted mb-0">Montant dû</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="text-success" style="font-size: 24px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3><?php echo formatMoney($dette['montant_paye']); ?></h3>
                        <p class="text-muted mb-0">Montant payé</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="text-warning" style="font-size: 24px;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3><?php echo formatMoney($dette['montant_restant']); ?></h3>
                        <p class="text-muted mb-0">Montant restant</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Progression -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Progression du paiement</h5>
            </div>
            <div class="card-body">
                <div class="progress-dette mb-3">
                    <div class="progress-bar 
                        <?php echo $pourcentage >= 100 ? 'bg-success' : ($pourcentage >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                        role="progressbar" 
                        style="width: <?php echo $pourcentage; ?>%">
                        <?php echo $pourcentage; ?>%
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Payé:</span>
                            <span class="montant-paye"><?php echo formatMoney($dette['montant_paye']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <span class="badge bg-info"><?php echo $pourcentage; ?>%</span>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Dû:</span>
                            <span class="montant-du"><?php echo formatMoney($dette['montant_du']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Informations générales -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informations Générales</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td width="40%"><strong>Statut:</strong></td>
                                <td><?php echo getStatutBadgeDette($dette['statut']); ?>
                                    <?php if($en_retard): ?>
                                    <span class="badge bg-danger ms-2">Retard: <?php echo $jours_retard; ?> jours</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Type de dette:</strong></td>
                                <td><?php echo getTypeDetteBadge($dette['type_dette']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Date limite:</strong></td>
                                <td>
                                    <?php if($dette['date_limite']): ?>
                                        <?php echo date('d/m/Y', strtotime($dette['date_limite'])); ?>
                                        <?php if($en_retard): ?>
                                        <span class="text-danger ms-2">
                                            <i class="fas fa-exclamation-triangle"></i> Dépassée
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Non définie</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Date création:</strong></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($dette['date_creation'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Gestionnaire:</strong></td>
                                <td><?php echo htmlspecialchars($dette['gestionnaire_nom'] ?? 'Non assigné'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Créé par:</strong></td>
                                <td><?php echo htmlspecialchars($dette['createur_nom'] ?? 'Système'); ?></td>
                            </tr>
                            <?php if($dette['motif']): ?>
                            <tr>
                                <td><strong>Motif:</strong></td>
                                <td><?php echo htmlspecialchars($dette['motif']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                
                <!-- Informations étudiant -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Informations Étudiant</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td width="40%"><strong>Matricule:</strong></td>
                                <td><?php echo htmlspecialchars($dette['matricule']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Nom complet:</strong></td>
                                <td><?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Téléphone:</strong></td>
                                <td><?php echo htmlspecialchars($dette['telephone'] ?? 'Non renseigné'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td><?php echo htmlspecialchars($dette['email'] ?? 'Non renseigné'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Adresse:</strong></td>
                                <td><?php echo htmlspecialchars($dette['adresse'] ?? 'Non renseignée'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Site:</strong></td>
                                <td><?php echo htmlspecialchars($dette['site_nom']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Année académique:</strong></td>
                                <td><?php echo htmlspecialchars($dette['annee_academique']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Paiements et historique -->
            <div class="col-md-6">
                <!-- Paiements effectués -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-money-check-alt me-2"></i>Paiements Effectués</h5>
                        <span class="badge bg-primary"><?php echo count($paiements); ?> paiement(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if(empty($paiements)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun paiement enregistré pour cette dette.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Montant</th>
                                        <th>Mode</th>
                                        <th>Caissier</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($paiements as $paiement): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($paiement['date_paiement'])); ?></td>
                                        <td><?php echo htmlspecialchars($paiement['type_frais']); ?></td>
                                        <td class="montant-paye"><?php echo formatMoney($paiement['montant']); ?></td>
                                        <td><?php echo htmlspecialchars($paiement['mode_paiement']); ?></td>
                                        <td><?php echo htmlspecialchars($paiement['caissier_nom'] ?? 'Système'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Historique des modifications -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Historique des Modifications</h5>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php if(empty($historique)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune modification enregistrée.
                            </div>
                            <?php else: ?>
                            <?php foreach($historique as $modif): 
                            $anciennes = json_decode($modif['anciennes_valeurs'], true);
                            $nouvelles = json_decode($modif['nouvelles_valeurs'], true);
                            ?>
                            <div class="timeline-item">
                                <div class="card mb-2">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($modif['utilisateur_nom'] ?? 'Système'); ?></strong>
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($modif['date_modification'])); ?>
                                            </small>
                                        </div>
                                        <div class="mt-2">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($modif['action']); ?></span>
                                        </div>
                                        <?php if($anciennes && $nouvelles): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">Modifications:</small>
                                            <ul class="mb-0">
                                                <?php 
                                                $champs_afficher = ['montant_du', 'montant_paye', 'date_limite', 'statut', 'type_dette'];
                                                foreach($champs_afficher as $champ):
                                                    if(isset($anciennes[$champ]) && isset($nouvelles[$champ]) && $anciennes[$champ] != $nouvelles[$champ]):
                                                ?>
                                                <li>
                                                    <small>
                                                        <?php echo htmlspecialchars($champ); ?>: 
                                                        <?php echo htmlspecialchars($anciennes[$champ]); ?> → 
                                                        <?php echo htmlspecialchars($nouvelles[$champ]); ?>
                                                    </small>
                                                </li>
                                                <?php endif; endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Échéances (si plan de paiement) -->
        <?php if(!empty($echeances)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Échéances du Plan de Paiement</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Montant</th>
                                <th>Date échéance</th>
                                <th>Date paiement</th>
                                <th>Statut</th>
                                <th>Jours restants</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($echeances as $echeance): 
                            $now = new DateTime();
                            $date_echeance = new DateTime($echeance['date_echeance']);
                            $jours_restants = $date_echeance->diff($now)->days;
                            $en_retard_echeance = ($echeance['statut'] == 'en_attente' && $date_echeance < $now);
                            
                            $statut_badge = '';
                            switch($echeance['statut']) {
                                case 'payee':
                                    $statut_badge = 'badge bg-success';
                                    break;
                                case 'en_retard':
                                    $statut_badge = 'badge bg-danger';
                                    break;
                                case 'annulee':
                                    $statut_badge = 'badge bg-secondary';
                                    break;
                                default:
                                    $statut_badge = $en_retard_echeance ? 'badge bg-warning' : 'badge bg-info';
                            }
                            ?>
                            <tr class="<?php echo $en_retard_echeance ? 'table-warning' : ''; ?>">
                                <td><?php echo $echeance['numero_tranche']; ?></td>
                                <td><?php echo formatMoney($echeance['montant']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($echeance['date_echeance'])); ?></td>
                                <td>
                                    <?php if($echeance['date_paiement']): ?>
                                        <?php echo date('d/m/Y', strtotime($echeance['date_paiement'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $statut_badge; ?>"><?php echo $echeance['statut']; ?></span></td>
                                <td>
                                    <?php if($echeance['statut'] == 'en_attente'): ?>
                                        <?php if($jours_restants > 0 && $date_echeance > $now): ?>
                                            <span class="text-info">Dans <?php echo $jours_restants; ?> jours</span>
                                        <?php elseif($date_echeance < $now): ?>
                                            <span class="text-danger">Retard: <?php echo $jours_restants; ?> jours</span>
                                        <?php else: ?>
                                            <span class="text-warning">Aujourd'hui</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Plan de paiement:</strong> 
                    <?php echo $echeances[0]['nombre_tranches'] ?? '1'; ?> tranche(s) - 
                    Fréquence: <?php echo $echeances[0]['frequence'] ?? 'unique'; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Actions -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group">
                    <a href="dettes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Retour à la liste
                    </a>
                    <button class="btn btn-warning" onclick="window.history.back()">
                        <i class="fas fa-edit me-2"></i>Modifier cette dette
                    </button>
                    <button class="btn btn-success">
                        <i class="fas fa-money-bill-wave me-2"></i>Nouveau paiement
                    </button>
                    <button class="btn btn-info" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Imprimer cette page
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ajouter un effet de surbrillance aux montants
        const montants = document.querySelectorAll('.montant-du, .montant-paye, .montant-restant');
        montants.forEach(montant => {
            montant.addEventListener('mouseover', function() {
                this.style.fontSize = '1.2em';
            });
            montant.addEventListener('mouseout', function() {
                this.style.fontSize = '1em';
            });
        });
    });
    </script>
</body>
</html>