<?php
// dashboard/gestionnaire/voir_facture.php

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

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4) ou un étudiant (rôle_id = 8)
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [3, 4, 8])) {
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
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        die("ID de facture invalide.");
    }
    
    $facture_id = $_GET['id'];
    $user_id = $_SESSION['user_id'];
    $role_id = $_SESSION['role_id'];
    
    // Construire la requête en fonction du rôle
    $query = "SELECT 
                f.*,
                e.matricule,
                e.nom as etudiant_nom,
                e.prenom as etudiant_prenom,
                e.adresse as etudiant_adresse,
                e.telephone as etudiant_telephone,
                e.email as etudiant_email,
                tf.nom as type_frais_nom,
                aa.libelle as annee_academique,
                s.nom as site_nom,
                s.adresse as site_adresse,
                s.telephone as site_telephone,
                s.email as site_email,
                CONCAT(u.nom, ' ', u.prenom) as emis_par_nom
              FROM factures f
              JOIN etudiants e ON f.etudiant_id = e.id
              JOIN types_frais tf ON f.type_frais_id = tf.id
              JOIN annees_academiques aa ON f.annee_academique_id = aa.id
              JOIN sites s ON f.site_id = s.id
              LEFT JOIN utilisateurs u ON f.emis_par = u.id
              WHERE f.id = ?";
    
    // Pour les étudiants, vérifier qu'ils ne peuvent voir que leurs propres factures
    if ($role_id == 8) {
        // Récupérer l'ID de l'étudiant connecté
        $stmt = $db->prepare("SELECT id FROM etudiants WHERE utilisateur_id = ?");
        $stmt->execute([$user_id]);
        $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$etudiant) {
            die("Étudiant non trouvé.");
        }
        
        // Modifier la requête pour vérifier que la facture appartient à l'étudiant
        $query .= " AND f.etudiant_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$facture_id, $etudiant['id']]);
    } else {
        // Pour les gestionnaires
        $stmt = $db->prepare($query);
        $stmt->execute([$facture_id]);
    }
    
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        die("Facture non trouvée ou accès non autorisé.");
    }
    
    // Récupérer les paiements associés à cette facture
    $query = "SELECT p.*, CONCAT(u.nom, ' ', u.prenom) as caissier_nom 
              FROM paiements p 
              LEFT JOIN utilisateurs u ON p.caissier_id = u.id 
              WHERE p.facture_id = ? 
              ORDER BY p.date_paiement DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([$facture_id]);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    // Titre de la page
    $pageTitle = "Facture " . htmlspecialchars($facture['numero_facture']);
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
    /* Styles spécifiques pour l'affichage de la facture */
    body {
        font-family: 'Arial', sans-serif;
        background-color: #f8f9fa;
        color: #333;
    }
    
    .facture-container {
        max-width: 800px;
        margin: 0 auto;
        background: white;
        padding: 30px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        border-radius: 10px;
    }
    
    .en-tete {
        border-bottom: 3px solid #3498db;
        padding-bottom: 20px;
        margin-bottom: 30px;
    }
    
    .logo {
        width: 100px;
        height: 100px;
        background: #3498db;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        margin: 0 auto 20px;
    }
    
    .titre-facture {
        text-align: center;
        color: #2c3e50;
        margin: 20px 0;
        font-size: 28px;
        font-weight: bold;
    }
    
    .numero-facture {
        text-align: center;
        font-size: 18px;
        color: #7f8c8d;
        margin-bottom: 30px;
    }
    
    .infos-section {
        margin-bottom: 30px;
    }
    
    .infos-section h5 {
        color: #2c3e50;
        border-bottom: 2px solid #3498db;
        padding-bottom: 5px;
        margin-bottom: 15px;
    }
    
    .info-row {
        display: flex;
        margin-bottom: 10px;
    }
    
    .info-label {
        flex: 0 0 150px;
        font-weight: bold;
        color: #7f8c8d;
    }
    
    .info-value {
        flex: 1;
        color: #2c3e50;
    }
    
    .table-facture {
        width: 100%;
        margin: 30px 0;
        border-collapse: collapse;
    }
    
    .table-facture th {
        background-color: #2c3e50;
        color: white;
        padding: 15px;
        text-align: left;
        border: 1px solid #ddd;
    }
    
    .table-facture td {
        padding: 12px 15px;
        border: 1px solid #ddd;
    }
    
    .table-facture tbody tr:nth-child(even) {
        background-color: #f8f9fa;
    }
    
    .totaux-section {
        margin-top: 30px;
        background-color: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
    }
    
    .ligne-total {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px dashed #ddd;
    }
    
    .ligne-total:last-child {
        border-bottom: none;
        font-size: 18px;
        font-weight: bold;
        color: #2c3e50;
    }
    
    .statut-badge {
        display: inline-block;
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 12px;
    }
    
    .statut-payee {
        background-color: #27ae60;
        color: white;
    }
    
    .statut-en-attente {
        background-color: #f39c12;
        color: white;
    }
    
    .statut-partiel {
        background-color: #3498db;
        color: white;
    }
    
    .statut-annule {
        background-color: #e74c3c;
        color: white;
    }
    
    .paiements-section {
        margin-top: 40px;
    }
    
    .footer-facture {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 2px solid #ddd;
        text-align: center;
        color: #7f8c8d;
        font-size: 14px;
    }
    
    .signature {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid #ddd;
        text-align: right;
    }
    
    .signature p {
        margin: 0;
    }
    
    /* Boutons d'action */
    .action-buttons {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1000;
        display: flex;
        gap: 10px;
    }
    
    .action-buttons .btn {
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    /* Styles d'impression */
    @media print {
        .action-buttons {
            display: none;
        }
        
        body {
            background: white;
        }
        
        .facture-container {
            box-shadow: none;
            padding: 0;
            margin: 0;
            max-width: 100%;
        }
        
        .btn {
            display: none !important;
        }
    }
    </style>
</head>
<body>
    <!-- Boutons d'action -->
    <div class="action-buttons">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Imprimer
        </button>
        <a href="generer_recu.php?facture_id=<?php echo $facture_id; ?>" class="btn btn-success">
            <i class="fas fa-receipt"></i> Reçu
        </a>
        <?php if($_SESSION['role_id'] != 8): ?>
        <a href="factures.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Contenu de la facture -->
    <div class="facture-container">
        <!-- En-tête -->
        <div class="en-tete">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h1 class="titre-facture">FACTURE</h1>
            <div class="numero-facture">
                <strong>N° <?php echo htmlspecialchars($facture['numero_facture']); ?></strong>
                <br>
                <span class="statut-badge <?php echo 'statut-' . $facture['statut']; ?>">
                    <?php 
                    if($facture['statut'] == 'payee') echo 'PAYÉE';
                    elseif($facture['statut'] == 'en_attente') echo 'EN ATTENTE';
                    elseif($facture['statut'] == 'partiel') echo 'PARTIELLE';
                    elseif($facture['statut'] == 'annule') echo 'ANNULÉE';
                    else echo strtoupper($facture['statut']);
                    ?>
                </span>
            </div>
        </div>
        
        <!-- Informations de l'institut -->
        <div class="row infos-section">
            <div class="col-md-6">
                <h5>Institut Supérieur de Gestion et d'Informatique</h5>
                <div class="info-row">
                    <div class="info-label">Site :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['site_nom']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Adresse :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['site_adresse']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Téléphone :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['site_telephone']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['site_email']); ?></div>
                </div>
            </div>
            
            <div class="col-md-6">
                <h5>Client</h5>
                <div class="info-row">
                    <div class="info-label">Étudiant :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_nom'] . ' ' . $facture['etudiant_prenom']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Matricule :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['matricule']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Adresse :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_adresse']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Téléphone :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_telephone']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email :</div>
                    <div class="info-value"><?php echo htmlspecialchars($facture['etudiant_email']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Détails de la facture -->
        <div class="infos-section">
            <h5>Détails de la facture</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Date d'émission :</div>
                        <div class="info-value"><?php echo formatDateFr($facture['date_emission']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date d'échéance :</div>
                        <div class="info-value"><?php echo formatDateFr($facture['date_echeance']); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Année académique :</div>
                        <div class="info-value"><?php echo htmlspecialchars($facture['annee_academique']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Type de frais :</div>
                        <div class="info-value"><?php echo htmlspecialchars($facture['type_frais_nom']); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if(!empty($facture['description'])): ?>
            <div class="info-row">
                <div class="info-label">Description :</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($facture['description'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Tableau des montants -->
        <table class="table-facture">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th>Montant</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($facture['type_frais_nom']); ?></td>
                    <td><?php echo formatMoney($facture['montant_total']); ?></td>
                </tr>
                <?php if($facture['remise'] > 0): ?>
                <tr>
                    <td>Remise</td>
                    <td>- <?php echo formatMoney($facture['remise']); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totaux -->
        <div class="totaux-section">
            <div class="ligne-total">
                <span>Montant total :</span>
                <span><?php echo formatMoney($facture['montant_total']); ?></span>
            </div>
            <?php if($facture['remise'] > 0): ?>
            <div class="ligne-total">
                <span>Remise :</span>
                <span>- <?php echo formatMoney($facture['remise']); ?></span>
            </div>
            <?php endif; ?>
            <div class="ligne-total">
                <span>Montant net :</span>
                <span><?php echo formatMoney($facture['montant_net']); ?></span>
            </div>
            <div class="ligne-total">
                <span>Montant payé :</span>
                <span><?php echo formatMoney($facture['montant_paye']); ?></span>
            </div>
            <div class="ligne-total">
                <span>Reste à payer :</span>
                <span style="color: <?php echo $facture['montant_restant'] > 0 ? '#e74c3c' : '#27ae60'; ?>; font-weight: bold;">
                    <?php echo formatMoney($facture['montant_restant']); ?>
                </span>
            </div>
        </div>
        
        <!-- Paiements effectués -->
        <?php if(!empty($paiements)): ?>
        <div class="paiements-section">
            <h5>Paiements effectués</h5>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Référence</th>
                        <th>Montant</th>
                        <th>Mode</th>
                        <th>Caissier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($paiements as $paiement): ?>
                    <tr>
                        <td><?php echo formatDateFr($paiement['date_paiement']); ?></td>
                        <td><?php echo htmlspecialchars($paiement['reference']); ?></td>
                        <td><?php echo formatMoney($paiement['montant']); ?></td>
                        <td><?php echo htmlspecialchars($paiement['mode_paiement']); ?></td>
                        <td><?php echo htmlspecialchars($paiement['caissier_nom']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Signature -->
        <div class="signature">
            <p>Le Responsable Financier,</p>
            <br><br><br>
            <p><?php echo htmlspecialchars($facture['emis_par_nom']); ?></p>
            <p>Date : <?php echo formatDateFr($facture['date_creation']); ?></p>
        </div>
        
        <!-- Pied de page -->
        <div class="footer-facture">
            <p><strong>ISGI - Institut Supérieur de Gestion et d'Informatique</strong></p>
            <p>Cette facture est générée automatiquement par le système de gestion financière ISGI</p>
            <p>Pour toute question, contactez le service financier au <?php echo htmlspecialchars($facture['site_telephone']); ?></p>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Mettre en page pour l'impression
    function prepareForPrint() {
        const buttons = document.querySelector('.action-buttons');
        if (buttons) {
            buttons.style.display = 'none';
        }
    }
    
    // Restaurer après impression
    function restoreAfterPrint() {
        const buttons = document.querySelector('.action-buttons');
        if (buttons) {
            buttons.style.display = 'flex';
        }
    }
    
    // Événements d'impression
    window.addEventListener('beforeprint', prepareForPrint);
    window.addEventListener('afterprint', restoreAfterPrint);
    
    // Hotkey pour l'impression (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
    </script>
</body>
</html>