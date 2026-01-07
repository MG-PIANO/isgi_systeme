<?php
// dashboard/gestionnaire/generer_recu.php

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
    
    // Vérifier l'ID de la facture
    $facture_id = isset($_GET['facture_id']) ? $_GET['facture_id'] : null;
    $paiement_id = isset($_GET['paiement_id']) ? $_GET['paiement_id'] : null;
    
    if (!$facture_id && !$paiement_id) {
        die("ID de facture ou paiement requis.");
    }
    
    // Si on a un paiement_id, générer un reçu pour ce paiement spécifique
    if ($paiement_id) {
        $query = "SELECT 
                    p.*,
                    f.numero_facture,
                    f.type_facture,
                    e.matricule,
                    e.nom as etudiant_nom,
                    e.prenom as etudiant_prenom,
                    tf.nom as type_frais_nom,
                    s.nom as site_nom,
                    s.adresse as site_adresse,
                    CONCAT(u.nom, ' ', u.prenom) as caissier_nom
                  FROM paiements p
                  JOIN factures f ON p.facture_id = f.id
                  JOIN etudiants e ON p.etudiant_id = e.id
                  JOIN types_frais tf ON p.type_frais_id = tf.id
                  JOIN sites s ON e.site_id = s.id
                  LEFT JOIN utilisateurs u ON p.caissier_id = u.id
                  WHERE p.id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$paiement_id]);
        $paiement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$paiement) {
            die("Paiement non trouvé.");
        }
        
        // Générer un numéro de reçu
        $prefixe = 'RECU-' . date('Y') . '-';
        $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_recu, 9) AS UNSIGNED)) as max_num 
                              FROM recus_paiement 
                              WHERE numero_recu LIKE ?");
        $stmt->execute([$prefixe . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_num = ($result['max_num'] ?? 0) + 1;
        $numero_recu = $prefixe . str_pad($next_num, 6, '0', STR_PAD_LEFT);
        
        // Enregistrer le reçu dans la base
        $query = "INSERT INTO recus_paiement (
                    paiement_id,
                    numero_recu,
                    emis_par,
                    date_emission
                  ) VALUES (?, ?, ?, NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $paiement_id,
            $numero_recu,
            $_SESSION['user_id']
        ]);
        
        $recu_id = $db->lastInsertId();
        
        // Rediriger vers la vue du reçu
        header("Location: voir_recu.php?id=$recu_id");
        exit();
        
    } 
    // Si on a un facture_id, vérifier qu'elle a des paiements
    else if ($facture_id) {
        // Récupérer la facture et ses paiements
        $query = "SELECT 
                    f.*,
                    e.matricule,
                    e.nom as etudiant_nom,
                    e.prenom as etudiant_prenom,
                    tf.nom as type_frais_nom,
                    s.nom as site_nom,
                    s.adresse as site_adresse
                  FROM factures f
                  JOIN etudiants e ON f.etudiant_id = e.id
                  JOIN types_frais tf ON f.type_frais_id = tf.id
                  JOIN sites s ON f.site_id = s.id
                  WHERE f.id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$facture_id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$facture) {
            die("Facture non trouvée.");
        }
        
        // Vérifier si la facture a des paiements
        $query = "SELECT id FROM paiements WHERE facture_id = ? AND statut = 'valide'";
        $stmt = $db->prepare($query);
        $stmt->execute([$facture_id]);
        $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($paiements)) {
            // Afficher un formulaire pour enregistrer un paiement
            $action = 'nouveau_paiement';
        } else {
            // Demander pour quel paiement générer un reçu
            $action = 'choisir_paiement';
        }
    }
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}

// Si on arrive ici, c'est qu'on doit afficher un formulaire
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générer un Reçu</title>
    
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
    
    .container {
        max-width: 600px;
        margin: 0 auto;
        background: white;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }
    
    .header {
        text-align: center;
        margin-bottom: 30px;
    }
    
    .logo {
        width: 80px;
        height: 80px;
        background: #3498db;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        margin: 0 auto 20px;
    }
    
    .facture-info {
        background-color: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
    }
    
    .info-row {
        display: flex;
        margin-bottom: 10px;
    }
    
    .info-label {
        flex: 0 0 120px;
        font-weight: bold;
        color: #7f8c8d;
    }
    
    .info-value {
        flex: 1;
        color: #2c3e50;
    }
    
    .btn-action {
        padding: 12px 30px;
        font-weight: 500;
        margin: 10px 5px;
    }
    
    .paiement-item {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .paiement-item:hover {
        background-color: #f8f9fa;
        border-color: #3498db;
    }
    
    .paiement-item.selected {
        background-color: #e3f2fd;
        border-color: #1976d2;
    }
    
    .montant {
        font-size: 18px;
        font-weight: bold;
        color: #27ae60;
    }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-receipt"></i>
            </div>
            <h2>Générer un Reçu</h2>
            <p class="text-muted">Système de gestion financière ISGI</p>
        </div>
        
        <?php if($action == 'nouveau_paiement'): ?>
        
        <!-- Formulaire pour nouveau paiement -->
        <div class="facture-info">
            <h5>Facture <?php echo htmlspecialchars($facture['numero_facture']); ?></h5>
            <div class="info-row">
                <div class="info-label">Étudiant :</div>
                <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_nom'] . ' ' . $facture['etudiant_prenom']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Matricule :</div>
                <div class="info-value"><?php echo htmlspecialchars($facture['matricule']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Montant total :</div>
                <div class="info-value"><?php echo number_format($facture['montant_net'], 0, ',', ' '); ?> FCFA</div>
            </div>
            <div class="info-row">
                <div class="info-label">Payé :</div>
                <div class="info-value"><?php echo number_format($facture['montant_paye'], 0, ',', ' '); ?> FCFA</div>
            </div>
            <div class="info-row">
                <div class="info-label">Reste :</div>
                <div class="info-value" style="color: #e74c3c; font-weight: bold;">
                    <?php echo number_format($facture['montant_restant'], 0, ',', ' '); ?> FCFA
                </div>
            </div>
        </div>
        
        <p class="text-center mb-4">Cette facture n'a pas encore de paiement enregistré.</p>
        
        <div class="text-center">
            <a href="nouveau_paiement.php?facture_id=<?php echo $facture_id; ?>" class="btn btn-primary btn-action">
                <i class="fas fa-money-bill-wave"></i> Enregistrer un paiement
            </a>
            <a href="factures.php" class="btn btn-secondary btn-action">
                <i class="fas fa-times"></i> Annuler
            </a>
        </div>
        
        <?php elseif($action == 'choisir_paiement'): ?>
        
        <!-- Liste des paiements pour choisir -->
        <div class="facture-info">
            <h5>Facture <?php echo htmlspecialchars($facture['numero_facture']); ?></h5>
            <div class="info-row">
                <div class="info-label">Étudiant :</div>
                <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_nom'] . ' ' . $facture['etudiant_prenom']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Matricule :</div>
                <div class="info-value"><?php echo htmlspecialchars($facture['matricule']); ?></div>
            </div>
        </div>
        
        <h5 class="mb-3">Sélectionnez un paiement :</h5>
        
        <?php 
        $query = "SELECT 
                    p.*,
                    DATE_FORMAT(p.date_paiement, '%d/%m/%Y') as date_paiement_fr,
                    CONCAT(u.nom, ' ', u.prenom) as caissier_nom
                  FROM paiements p
                  LEFT JOIN utilisateurs u ON p.caissier_id = u.id
                  WHERE p.facture_id = ? AND p.statut = 'valide'
                  ORDER BY p.date_paiement DESC";
        $stmt = $db->prepare($query);
        $stmt->execute([$facture_id]);
        $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <div id="paiements-list">
            <?php foreach($paiements as $paiement): ?>
            <div class="paiement-item" onclick="selectPaiement(this, <?php echo $paiement['id']; ?>)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo htmlspecialchars($paiement['reference']); ?></strong>
                        <br>
                        <small class="text-muted">
                            <?php echo htmlspecialchars($paiement['date_paiement_fr']); ?> 
                            - <?php echo htmlspecialchars($paiement['mode_paiement']); ?>
                            <?php if($paiement['caissier_nom']): ?>
                            (Caissier: <?php echo htmlspecialchars($paiement['caissier_nom']); ?>)
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="montant">
                        <?php echo number_format($paiement['montant'], 0, ',', ' '); ?> FCFA
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <form id="form-generer" method="GET" action="generer_recu.php" class="mt-4">
            <input type="hidden" name="paiement_id" id="selected_paiement_id">
            
            <div class="text-center">
                <button type="submit" id="btn-generer" class="btn btn-primary btn-action" disabled>
                    <i class="fas fa-receipt"></i> Générer le Reçu
                </button>
                <a href="factures.php" class="btn btn-secondary btn-action">
                    <i class="fas fa-times"></i> Annuler
                </a>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
    
    <script>
    function selectPaiement(element, paiementId) {
        // Désélectionner tous les éléments
        document.querySelectorAll('.paiement-item').forEach(item => {
            item.classList.remove('selected');
        });
        
        // Sélectionner l'élément cliqué
        element.classList.add('selected');
        
        // Mettre à jour le champ caché et activer le bouton
        document.getElementById('selected_paiement_id').value = paiementId;
        document.getElementById('btn-generer').disabled = false;
    }
    </script>
</body>
</html>