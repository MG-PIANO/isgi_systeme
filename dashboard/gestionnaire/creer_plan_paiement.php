<?php
// dashboard/gestionnaire/creer_plan_paiement.php

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
    
    // Variables
    $error = null;
    $success = null;
    $dette = null;
    
    // Récupérer l'ID de la dette
    $dette_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($dette_id <= 0) {
        die("ID de dette invalide");
    }
    
    // Récupérer les informations de la dette
    $query = "SELECT d.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
              aa.libelle as annee_academique
              FROM dettes d
              INNER JOIN etudiants e ON d.etudiant_id = e.id
              INNER JOIN annees_academiques aa ON d.annee_academique_id = aa.id
              WHERE d.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$dette_id]);
    $dette = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dette) {
        die("Dette non trouvée");
    }
    
    // Vérifier si un plan existe déjà
    $query_plan = "SELECT id FROM plans_paiement_dettes WHERE dette_id = ? AND statut = 'actif'";
    $stmt = $db->prepare($query_plan);
    $stmt->execute([$dette_id]);
    $plan_existant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // TRAITEMENT DU FORMULAIRE
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        try {
            $db->beginTransaction();
            
            $nombre_tranches = intval($_POST['nombre_tranches']);
            $frequence = $_POST['frequence'];
            $date_debut = $_POST['date_debut'];
            
            // Validation
            if ($nombre_tranches <= 0) {
                throw new Exception("Le nombre de tranches doit être supérieur à 0.");
            }
            
            if ($plan_existant) {
                throw new Exception("Un plan de paiement actif existe déjà pour cette dette.");
            }
            
            // Calculer le montant par tranche
            $montant_tranche = $dette['montant_restant'] / $nombre_tranches;
            
            // Créer le plan
            $stmt = $db->prepare("INSERT INTO plans_paiement_dettes (
                dette_id, nombre_tranches, montant_tranche, frequence, date_debut, statut
            ) VALUES (?, ?, ?, ?, ?, 'actif')");
            
            $stmt->execute([
                $dette_id,
                $nombre_tranches,
                $montant_tranche,
                $frequence,
                $date_debut
            ]);
            
            $plan_id = $db->lastInsertId();
            
            // Créer les échéances
            $date_echeance = new DateTime($date_debut);
            for ($i = 1; $i <= $nombre_tranches; $i++) {
                $stmt = $db->prepare("INSERT INTO echeances_dettes (
                    plan_id, dette_id, numero_tranche, montant, date_echeance, statut
                ) VALUES (?, ?, ?, ?, ?, 'en_attente')");
                
                $stmt->execute([
                    $plan_id,
                    $dette_id,
                    $i,
                    $montant_tranche,
                    $date_echeance->format('Y-m-d')
                ]);
                
                // Mettre à jour la date pour la prochaine échéance selon la fréquence
                switch ($frequence) {
                    case 'mensuelle':
                        $date_echeance->modify('+1 month');
                        break;
                    case 'trimestrielle':
                        $date_echeance->modify('+3 months');
                        break;
                    case 'semestrielle':
                        $date_echeance->modify('+6 months');
                        break;
                    case 'annuelle':
                        $date_echeance->modify('+1 year');
                        break;
                }
            }
            
            // Enregistrer dans l'historique
            $query_historique = "INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée, 
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'nouveau_plan_paiement', 'plans_paiement_dettes', ?, ?, NOW())";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $_SESSION['user_id'],
                $plan_id,
                "Nouveau plan de paiement créé pour dette #" . $dette_id . " - " . $nombre_tranches . " tranches"
            ]);
            
            $db->commit();
            $success = "Plan de paiement créé avec succès !";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la création du plan de paiement: " . $e->getMessage();
        }
    }
    
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
    <title>Créer Plan de Paiement</title>
    
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
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 30px;
    }
    
    .card {
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .montant-restant {
        color: #f39c12;
        font-weight: bold;
        font-size: 1.2em;
    }
    
    .simulation-tranche {
        background-color: #f8f9fa;
        border-left: 4px solid #3498db;
        padding: 15px;
        margin-bottom: 10px;
        border-radius: 5px;
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
                        <i class="fas fa-calendar-alt me-2"></i>
                        Créer un Plan de Paiement
                    </h1>
                    <p class="mb-0">
                        Pour la dette de <strong><?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?></strong>
                        (<?php echo htmlspecialchars($dette['matricule']); ?>)
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="dettes.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Retour
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Messages d'alerte -->
        <?php if(isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if($plan_existant): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong>Attention!</strong> Un plan de paiement actif existe déjà pour cette dette.
            <a href="dettes.php?action=voir_plan&id=<?php echo $dette_id; ?>" class="btn btn-sm btn-outline-warning ms-2">
                Voir le plan existant
            </a>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Informations de la dette -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Informations de la Dette</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td width="40%"><strong>Étudiant:</strong></td>
                                <td><?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Matricule:</strong></td>
                                <td><?php echo htmlspecialchars($dette['matricule']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Année académique:</strong></td>
                                <td><?php echo htmlspecialchars($dette['annee_academique']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Montant dû:</strong></td>
                                <td><?php echo formatMoney($dette['montant_du']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Montant payé:</strong></td>
                                <td><?php echo formatMoney($dette['montant_paye']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Montant restant:</strong></td>
                                <td class="montant-restant"><?php echo formatMoney($dette['montant_restant']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Formulaire de création du plan -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Configuration du Plan</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="formPlanPaiement">
                            <div class="mb-3">
                                <label for="nombre_tranches" class="form-label">Nombre de tranches</label>
                                <select class="form-select" id="nombre_tranches" name="nombre_tranches" required>
                                    <option value="1">1 (Paiement unique)</option>
                                    <option value="2">2 tranches</option>
                                    <option value="3">3 tranches</option>
                                    <option value="4">4 tranches</option>
                                    <option value="6">6 tranches</option>
                                    <option value="12">12 tranches</option>
                                </select>
                                <div class="form-text">Divisez le montant restant en plusieurs échéances.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="frequence" class="form-label">Fréquence des paiements</label>
                                <select class="form-select" id="frequence" name="frequence" required>
                                    <option value="mensuelle">Mensuelle</option>
                                    <option value="trimestrielle">Trimestrielle</option>
                                    <option value="semestrielle">Semestrielle</option>
                                    <option value="annuelle">Annuelle</option>
                                </select>
                                <div class="form-text">Intervalle entre chaque échéance.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="date_debut" class="form-label">Date de la première échéance</label>
                                <input type="date" class="form-control" id="date_debut" name="date_debut" required>
                                <div class="form-text">Date à laquelle la première tranche sera due.</div>
                            </div>
                            
                            <!-- Simulation -->
                            <div class="mb-4">
                                <h6><i class="fas fa-chart-line me-2"></i>Simulation du plan</h6>
                                <div id="simulation">
                                    <p class="text-muted">Configurez le plan pour voir la simulation.</p>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note :</strong> Ce plan de paiement permettra de suivre plus facilement 
                                les échéances et les retards de paiement.
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" <?php echo $plan_existant ? 'disabled' : ''; ?>>
                                    <i class="fas fa-save me-2"></i>Créer le Plan de Paiement
                                </button>
                                <a href="dettes.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Annuler
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser la date à aujourd'hui
        const today = new Date();
        document.getElementById('date_debut').valueAsDate = today;
        
        // Mettre à jour la simulation
        function mettreAJourSimulation() {
            const nombreTranches = parseInt(document.getElementById('nombre_tranches').value);
            const frequence = document.getElementById('frequence').value;
            const dateDebut = document.getElementById('date_debut').value;
            
            if (!dateDebut) return;
            
            const montantRestant = <?php echo $dette['montant_restant']; ?>;
            const montantParTranche = montantRestant / nombreTranches;
            
            // Créer la simulation
            let html = '';
            let dateEcheance = new Date(dateDebut);
            
            for (let i = 1; i <= nombreTranches; i++) {
                const dateFormatee = dateEcheance.toLocaleDateString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                
                html += `
                    <div class="simulation-tranche">
                        <div class="d-flex justify-content-between">
                            <strong>Tranche ${i}</strong>
                            <span class="badge bg-primary">${formatMontant(montantParTranche)} FCFA</span>
                        </div>
                        <small class="text-muted">Échéance: ${dateFormatee}</small>
                    </div>
                `;
                
                // Mettre à jour la date pour la prochaine échéance
                switch (frequence) {
                    case 'mensuelle':
                        dateEcheance.setMonth(dateEcheance.getMonth() + 1);
                        break;
                    case 'trimestrielle':
                        dateEcheance.setMonth(dateEcheance.getMonth() + 3);
                        break;
                    case 'semestrielle':
                        dateEcheance.setMonth(dateEcheance.getMonth() + 6);
                        break;
                    case 'annuelle':
                        dateEcheance.setFullYear(dateEcheance.getFullYear() + 1);
                        break;
                }
            }
            
            document.getElementById('simulation').innerHTML = html;
        }
        
        // Formater un montant
        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR').format(Math.round(montant));
        }
        
        // Écouter les changements
        document.getElementById('nombre_tranches').addEventListener('change', mettreAJourSimulation);
        document.getElementById('frequence').addEventListener('change', mettreAJourSimulation);
        document.getElementById('date_debut').addEventListener('change', mettreAJourSimulation);
        
        // Initialiser la simulation
        mettreAJourSimulation();
        
        // Validation du formulaire
        document.getElementById('formPlanPaiement').addEventListener('submit', function(e) {
            const dateDebut = new Date(document.getElementById('date_debut').value);
            const today = new Date();
            
            if (dateDebut < today) {
                e.preventDefault();
                alert('La date de début doit être supérieure ou égale à aujourd\'hui.');
                return false;
            }
            
            return true;
        });
    });
    </script>
</body>
</html>