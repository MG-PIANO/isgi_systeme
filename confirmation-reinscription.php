<?php
session_start();

// Vérifier que la réinscription a réussi
if (!isset($_SESSION['reinscription_success'])) {
    header('Location: reinscription.php');
    exit();
}

$matricule = $_SESSION['matricule'] ?? '';
$nom_complet = $_SESSION['nom_complet'] ?? '';
$email = $_SESSION['email'] ?? '';
$annee_academique = $_SESSION['annee_academique'] ?? '';
$filiere = $_SESSION['filiere'] ?? '';
$niveau = $_SESSION['niveau'] ?? '';
$mode_paiement = $_SESSION['mode_paiement'] ?? '';

// Nettoyer la session
unset($_SESSION['reinscription_success']);
unset($_SESSION['matricule']);
unset($_SESSION['nom_complet']);
unset($_SESSION['email']);
unset($_SESSION['annee_academique']);
unset($_SESSION['filiere']);
unset($_SESSION['niveau']);
unset($_SESSION['mode_paiement']);
unset($_SESSION['frais_reinscription']);
unset($_SESSION['validation_automatique']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Confirmation de réinscription</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 15px 0;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .logo-text span {
            color: #3498db;
        }
        
        .confirmation-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }
        
        .confirmation-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            padding: 40px;
            text-align: center;
        }
        
        .success-icon {
            color: #27ae60;
            font-size: 80px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .matricule-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
            margin: 20px 0;
        }
        
        .matricule {
            color: #3498db;
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .info-box {
            background: #e8f4fc;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: left;
        }
        
        .details-box {
            background: #f0f8ff;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #27ae60;
            text-align: left;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin: 10px;
        }
        
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-success {
            background-color: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        footer {
            background-color: #2c3e50;
            color: white;
            padding: 30px 0 20px;
            text-align: center;
        }
        
        .copyright {
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.9rem;
            color: #aaa;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-weight: bold;
            color: #555;
            font-size: 0.9rem;
        }
        
        .detail-value {
            font-size: 1.1rem;
            color: #2c3e50;
        }
        
        @media (max-width: 768px) {
            .confirmation-card {
                padding: 20px;
            }
            
            .matricule {
                font-size: 1.5rem;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <div class="logo-container">
                <div style="padding-left: 22px;">
                    <div class="logo-text">IS<span>GI</span></div>
                    <div style="font-size: 0.9rem;color: #666;">Institut Supérieur de Gestion et d'Ingénierie</div>
                </div>
            </div>
        </div>
    </header>

    <!-- Confirmation -->
    <section class="confirmation-container">
        <div class="confirmation-card">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            
            <h1>Réinscription Réussie !</h1>
            
            <p style="font-size: 1.2rem; margin-bottom: 20px;">
                Félicitations <strong><?php echo htmlspecialchars($nom_complet); ?></strong>,<br>
                votre réinscription a été enregistrée avec succès pour l'année académique 
                <strong><?php echo htmlspecialchars($annee_academique); ?></strong>.
            </p>
            
            <div class="matricule-box">
                <h3><i class="fas fa-id-card"></i> Votre numéro de dossier</h3>
                <div class="matricule"><?php echo htmlspecialchars($matricule); ?></div>
                <p style="color: #666;">
                    Conservez précieusement ce numéro pour suivre votre dossier
                </p>
            </div>
            
            <div class="details-box">
                <h4><i class="fas fa-info-circle"></i> Détails de votre réinscription</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="detail-label">Filière</span>
                        <span class="detail-value"><?php echo htmlspecialchars($filiere); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Niveau</span>
                        <span class="detail-value"><?php echo htmlspecialchars($niveau); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Année académique</span>
                        <span class="detail-value"><?php echo htmlspecialchars($annee_academique); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Mode de paiement</span>
                        <span class="detail-value"><?php echo htmlspecialchars($mode_paiement); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="info-box">
                <h4><i class="fas fa-exclamation-circle"></i> Informations importantes :</h4>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li>Un email de confirmation a été envoyé à : <strong><?php echo htmlspecialchars($email); ?></strong></li>
                    <li>Votre dossier a été mis à jour avec les nouvelles informations</li>
                    <li>Votre statut a été mis à jour : <strong>Validé pour <?php echo htmlspecialchars($annee_academique); ?></strong></li>
                    <li>Vous recevrez vos identifiants mis à jour pour l'espace étudiant</li>
                    <li>Pour toute question concernant votre réinscription : contact@isgi.cg</li>
                </ul>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Retour à l'accueil
                </a>
                <a href="espace-etudiant.php" class="btn btn-success">
                    <i class="fas fa-user-graduate"></i> Espace étudiant
                </a>
                <a href="reinscription.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Nouvelle réinscription
                </a>
            </div>
            
            <div style="margin-top: 20px; font-style: italic; color: #666;">
                <p>Merci pour votre confiance et à bientôt sur votre campus !</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>
</body>
</html>