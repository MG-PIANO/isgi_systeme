<?php
// payment-interface/index.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(__FILE__)));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier le token
$token = $_GET['token'] ?? '';
$demande_id = $_GET['demande_id'] ?? 0;
$fee_type_id = $_GET['fee_type_id'] ?? 1;
$reference = $_GET['reference'] ?? '';

if (empty($token)) {
    die("Token de paiement invalide");
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Vérifier la validité du token
    $query = "SELECT d.*, s.nom as site_nom 
              FROM demande_inscriptions d 
              LEFT JOIN sites s ON d.site_id = s.id 
              WHERE d.token_paiement = ? 
              AND d.id = ? 
              AND d.statut = 'approuvee'
              AND d.date_expiration_token > NOW()";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$token, $demande_id]);
    $demande = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demande) {
        die("Lien de paiement invalide ou expiré. Veuillez contacter l'administration.");
    }
    
    // Récupérer les informations de paiement
    $payment_query = "SELECT * FROM paiements 
                      WHERE reference LIKE ? 
                      AND statut = 'en_attente'
                      ORDER BY id DESC LIMIT 1";
    $payment_stmt = $db->prepare($payment_query);
    $payment_stmt->execute(['%' . $demande['numero_demande'] . '%']);
    $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si pas de paiement, en créer un
    if (!$payment) {
        // Logique pour créer un paiement si nécessaire
        $montant_frais = 50000; // À adapter selon votre logique
        $frais_transaction = $montant_frais * 0.015; // 1.5%
        
        $insert_payment = "INSERT INTO paiements (
            etudiant_id, type_frais_id, annee_academique_id, reference,
            montant, frais_transaction, mode_paiement, numero_telephone,
            operateur_mobile, date_paiement, statut, date_creation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'en_attente', NOW())";
        
        $insert_stmt = $db->prepare($insert_payment);
        $insert_stmt->execute([
            null,
            $fee_type_id,
            1, // Année académique active
            'PAY-' . date('Ymd') . '-' . $demande['numero_demande'],
            $montant_frais,
            $frais_transaction,
            $demande['mode_paiement'],
            $demande['telephone'],
            ($demande['mode_paiement'] == 'MTN Mobile Money') ? 'MTN' : 
            (($demande['mode_paiement'] == 'Airtel Money') ? 'Airtel' : null)
        ]);
        
        $payment_id = $db->lastInsertId();
        
        // Récupérer le paiement créé
        $payment_query = "SELECT * FROM paiements WHERE id = ?";
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->execute([$payment_id]);
        $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Préparer les données pour l'affichage
    $student_name = $demande['nom'] . ' ' . $demande['prenom'];
    $matricule = 'ISGI-' . date('Y') . '-' . str_pad($demande['id'], 5, '0', STR_PAD_LEFT);
    $total_amount = $payment['montant'] + $payment['frais_transaction'];
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}

// Inclure le header HTML
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement en ligne - ISGI Congo</title>
    <!-- Utiliser le même CSS que dans le fichier que j'ai fourni précédemment -->
    <style>
        /* Copiez tout le CSS du fichier payment-interface/index.html que j'ai fourni */
        /* Pour gagner de l'espace, je ne le remets pas ici */
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1><i class="fas fa-university"></i> ISGI Congo</h1>
            <p>Paiement en ligne des frais académiques</p>
        </div>
        
        <div class="payment-body">
            <!-- Étape 1: Affichage des informations -->
            <div class="payment-info" id="paymentInfo">
                <div class="info-item">
                    <span class="info-label">Nom de l'étudiant:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student_name); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Numéro demande:</span>
                    <span class="info-value"><?php echo htmlspecialchars($demande['numero_demande']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Filière:</span>
                    <span class="info-value"><?php echo htmlspecialchars($demande['filiere']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Site:</span>
                    <span class="info-value"><?php echo htmlspecialchars($demande['site_nom']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Montant frais:</span>
                    <span class="info-value"><?php echo number_format($payment['montant'], 0, ',', ' '); ?> FCFA</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Frais de transaction:</span>
                    <span class="info-value"><?php echo number_format($payment['frais_transaction'], 0, ',', ' '); ?> FCFA</span>
                </div>
                <div class="info-item total">
                    <span class="info-label">Total à payer:</span>
                    <span class="info-value" style="color: #667eea; font-size: 18px;">
                        <?php echo number_format($total_amount, 0, ',', ' '); ?> FCFA
                    </span>
                </div>
            </div>
            
            <?php if ($demande['mode_paiement'] == 'MTN Mobile Money' || $demande['mode_paiement'] == 'Airtel Money'): ?>
            <!-- Étape 2: Formulaire de paiement mobile money -->
            <div class="payment-form" id="paymentForm">
                <div class="operator-select">
                    <div class="operator-option active" data-operator="mtn">
                        <div class="operator-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <div>MTN Mobile Money</div>
                    </div>
                    <div class="operator-option" data-operator="airtel">
                        <div class="operator-icon">
                            <i class="fas fa-sim-card"></i>
                        </div>
                        <div>Airtel Money</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="phoneNumber">
                        <i class="fas fa-phone"></i> 
                        Numéro de téléphone <?php echo $demande['mode_paiement']; ?>
                    </label>
                    <input type="tel" 
                           id="phoneNumber" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($demande['telephone']); ?>"
                           placeholder="Ex: +242 06 123 45 67"
                           maxlength="13">
                    <div class="error-message" id="phoneError">Veuillez entrer un numéro valide</div>
                </div>
                
                <button class="btn-pay" id="payButton" onclick="initiatePayment()">
                    <i class="fas fa-lock"></i> Payer maintenant 
                    (<?php echo number_format($total_amount, 0, ',', ' '); ?> FCFA)
                </button>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>Instructions:</strong><br>
                    1. Vérifiez votre numéro de téléphone<br>
                    2. Cliquez sur "Payer maintenant"<br>
                    3. Confirmez le paiement sur votre téléphone<br>
                    4. Attendez la confirmation
                </div>
            </div>
            
            <!-- Étape 3: Statut du paiement -->
            <div class="payment-status" id="paymentStatus" style="display: none;">
                <!-- Le reste du code JavaScript pour gérer le paiement -->
                <!-- Utilisez le code JavaScript du fichier que j'ai fourni précédemment -->
            </div>
            
            <?php else: ?>
            <!-- Pour les paiements en espèces -->
            <div class="alert alert-warning">
                <h4><i class="fas fa-money-bill-wave"></i> Paiement en espèces</h4>
                <p>Vous avez choisi le paiement en espèces.</p>
                <p><strong>Procédure :</strong></p>
                <ol>
                    <li>Présentez-vous au secrétariat de l'ISGI <?php echo htmlspecialchars($demande['site_nom']); ?></li>
                    <li>Apportez une copie de ce mail et votre pièce d'identité</li>
                    <li>Effectuez le paiement du montant indiqué ci-dessus</li>
                    <li>Vous recevrez un reçu officiel</li>
                </ol>
                <p><strong>Adresse :</strong><br>
                <?php echo getSiteAddress($demande['site_id'] ?? 1); ?></p>
                <p><strong>Horaires :</strong> Lundi - Vendredi, 8h - 17h</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Variables globales
        const token = '<?php echo $token; ?>';
        const demandeId = <?php echo $demande_id; ?>;
        const paymentId = <?php echo $payment['id'] ?? 0; ?>;
        const totalAmount = <?php echo $total_amount; ?>;
        const reference = '<?php echo addslashes($reference); ?>';
        
        // Copiez ici tout le JavaScript du fichier payment-interface/index.html
        // que j'ai fourni précédemment, en adaptant les variables
        
        // Fonction pour initier le paiement
        async function initiatePayment() {
            // Votre code JavaScript pour gérer le paiement
            // Utilisez les API que j'ai créées
        }
        
        // Fonction pour vérifier le statut
        async function checkPaymentStatus() {
            // Votre code JavaScript
        }
    </script>
</body>
</html>