<?php
session_start();

// Vérifier que la demande d'inscription a réussi
if (!isset($_SESSION['inscription_success'])) {
    header('Location: inscription.php');
    exit();
}

$numero_demande = $_SESSION['numero_demande'] ?? '';
$nom_complet = $_SESSION['nom_complet'] ?? '';
$email = $_SESSION['email'] ?? '';

// Nettoyer la session
unset($_SESSION['inscription_success']);
unset($_SESSION['numero_demande']);
unset($_SESSION['nom_complet']);
unset($_SESSION['email']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Confirmation de demande d'inscription</title>
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
        
        .numero-demande-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
            margin: 20px 0;
        }
        
        .numero-demande {
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
        
        .steps-box {
            background: #fff3cd;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: left;
            border-left: 4px solid #ffc107;
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
        
        @media (max-width: 768px) {
            .confirmation-card {
                padding: 20px;
            }
            
            .numero-demande {
                font-size: 1.5rem;
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
            
            <h1>Demande d'Inscription Soumise avec Succès !</h1>
            
            <p style="font-size: 1.2rem; margin-bottom: 20px;">
                Félicitations <strong><?php echo htmlspecialchars($nom_complet); ?></strong>,<br>
                votre demande d'inscription a été enregistrée avec succès.
            </p>
            
            <div class="numero-demande-box">
                <h3><i class="fas fa-id-card"></i> Votre numéro de dossier</h3>
                <div class="numero-demande"><?php echo htmlspecialchars($numero_demande); ?></div>
                <p style="color: #666;">
                    Conservez précieusement ce numéro pour suivre votre dossier
                </p>
            </div>
            
            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> Informations importantes :</h4>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li>Votre numéro de dossier : <strong><?php echo htmlspecialchars($numero_demande); ?></strong></li>
                    <li>Email de confirmation : <strong><?php echo htmlspecialchars($email); ?></strong></li>
                    <li>Votre dossier sera examiné par l'administration sous 3-5 jours ouvrés</li>
                    <li>Vous recevrez une réponse par email après traitement</li>
                    <li>Pour toute question : contact@isgi.cg</li>
                </ul>
            </div>
            
            <div class="steps-box">
                <h4><i class="fas fa-list-ol"></i> Prochaines étapes :</h4>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li><strong>Validation administrative</strong> : Votre dossier sera vérifié</li>
                    <li><strong>Examen des documents</strong> : Vérification des pièces justificatives</li>
                    <li><strong>Notification</strong> : Vous recevrez un email avec la décision</li>
                    <li><strong>Paiement</strong> : Si accepté, procédez au paiement des frais</li>
                    <li><strong>Finalisation</strong> : Réception de vos identifiants étudiant</li>
                </ol>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Retour à l'accueil
                </a>
                <a href="suivi-demande.php?numero=<?php echo urlencode($numero_demande); ?>" class="btn btn-secondary">
                    <i class="fas fa-search"></i> Suivre ma demande
                </a>
                <a href="inscription.php" class="btn btn-success">
                    <i class="fas fa-user-plus"></i> Nouvelle demande
                </a>
            </div>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 0.9rem;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Important</strong> : Votre inscription ne sera définitive qu'après validation 
                    par l'administration et paiement des frais d'inscription.
                </p>
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