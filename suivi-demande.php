<?php
session_start();
require_once 'config/database.php';

// Initialisation
$db = Database::getInstance()->getConnection();
$demande = null;
$erreur = null;

// Récupérer le numéro de demande depuis l'URL ou le formulaire
$numero_demande = $_GET['numero'] ?? $_POST['numero_demande'] ?? '';

if (!empty($numero_demande)) {
    try {
        // Récupérer les informations de la demande
        $stmt = $db->prepare("
            SELECT d.*, 
                   p.montant_total, p.montant_paye, p.statut as statut_paiement
            FROM demande_inscriptions d
            LEFT JOIN paiements p ON d.numero_demande = p.numero_demande
            WHERE d.numero_demande = ?
        ");
        $stmt->execute([$numero_demande]);
        $demande = $stmt->fetch();
        
        if (!$demande) {
            $erreur = "Aucune demande trouvée avec le numéro : " . htmlspecialchars($numero_demande);
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la recherche de la demande : " . $e->getMessage();
    }
}

// Récupérer les configurations
$configs = [];
try {
    $stmt = $db->query("SELECT cle, valeur FROM configurations");
    $config_rows = $stmt->fetchAll();
    foreach ($config_rows as $row) {
        $configs[$row['cle']] = $row['valeur'];
    }
} catch (PDOException $e) {
    error_log("Erreur chargement configurations: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Suivi de demande</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
        }
        
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
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .logo-text span {
            color: var(--secondary-color);
        }
        
        .nav-links {
            display: flex;
            gap: 25px;
            align-items: center;
        }
        
        .nav-links a {
            text-decoration: none;
            color: var(--dark-color);
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav-links a:hover {
            color: var(--secondary-color);
        }
        
        .nav-links a.active {
            color: var(--secondary-color);
            font-weight: 600;
        }
        
        /* Recherche */
        .search-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 300px;
            padding: 40px 20px;
            background-color: #f8f9fa;
        }
        
        .search-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
            padding: 40px;
            text-align: center;
        }
        
        .search-icon {
            color: var(--secondary-color);
            font-size: 60px;
            margin-bottom: 20px;
        }
        
        /* Détails de la demande */
        .demande-container {
            padding: 40px 20px;
        }
        
        .demande-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            padding: 30px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .status-en_attente {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-en_traitement {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-approuvee {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-rejetee {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-validee {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .info-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .info-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--info-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-section h3 i {
            color: var(--info-color);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            padding: 10px 0;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .info-value {
            color: #666;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-secondary {
            background-color: var(--light-color);
            color: var(--dark-color);
        }
        
        .btn-secondary:hover {
            background-color: #d5dbdb;
        }
        
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid var(--info-color);
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 0 5px 5px 0;
        }
        
        .message-box.error {
            background-color: #f8d7da;
            border-left-color: var(--accent-color);
        }
        
        .message-box h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--secondary-color);
            outline: none;
        }
        
        /* Footer */
        footer {
            background-color: var(--primary-color);
            color: white;
            padding: 50px 0 20px;
            margin-top: 50px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 30px;
        }
        
        .footer-section h3 {
            margin-bottom: 20px;
            font-size: 1.3rem;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 10px;
        }
        
        .footer-links a {
            color: #ddd;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-links a:hover {
            color: white;
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.9rem;
            color: #aaa;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .search-card, .demande-card {
                padding: 20px;
            }
            
            .info-grid {
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
                    <div style="font-size: 0.9rem;color: #666;">
                        <?php echo htmlspecialchars($configs['site_nom'] ?? 'Institut Supérieur de Gestion et d\'Ingénierie'); ?>
                    </div>
                </div>
            </div>
            
            <nav class="nav-links">
                <a href="index.php">Accueil</a>
                <a href="inscription.php">Inscription</a>
                <a href="suivi-demande.php" class="active">Suivi demande</a>
                <a href="admin/login.php">Administration</a>
            </nav>
        </div>
    </header>

    <!-- Formulaire de recherche -->
    <section class="search-container">
        <div class="search-card">
            <div class="search-icon">
                <i class="fas fa-search"></i>
            </div>
            
            <h2>Suivi de votre demande d'inscription</h2>
            <p style="margin-bottom: 30px; color: #666;">
                Entrez votre numéro de dossier pour suivre l'avancement de votre demande
            </p>
            
            <form method="POST" action="">
                <div class="form-group">
                    <input type="text" 
                           name="numero_demande" 
                           class="form-control" 
                           placeholder="Ex: ISGI-2026-00001" 
                           value="<?php echo htmlspecialchars($numero_demande); ?>"
                           required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-search"></i> Rechercher
                </button>
            </form>
            
            <?php if ($erreur): ?>
                <div class="message-box error" style="margin-top: 20px;">
                    <h4><i class="fas fa-exclamation-triangle"></i> Erreur</h4>
                    <p><?php echo $erreur; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Détails de la demande -->
    <?php if ($demande): ?>
    <section class="demande-container">
        <div class="container">
            <div class="demande-card">
                <!-- En-tête avec statut -->
                <div class="status-badge status-<?php echo $demande['statut']; ?>">
                    <i class="fas fa-<?php 
                        switch($demande['statut']) {
                            case 'en_attente': echo 'clock'; break;
                            case 'en_traitement': echo 'cogs'; break;
                            case 'approuvee': echo 'check-circle'; break;
                            case 'rejetee': echo 'times-circle'; break;
                            case 'validee': echo 'user-check'; break;
                            default: echo 'info-circle';
                        }
                    ?>"></i>
                    <?php 
                        $statut_text = [
                            'en_attente' => 'En attente',
                            'en_traitement' => 'En traitement',
                            'approuvee' => 'Approuvée',
                            'rejetee' => 'Rejetée',
                            'validee' => 'Validée'
                        ];
                        echo $statut_text[$demande['statut']] ?? $demande['statut'];
                    ?>
                </div>
                
                <h2>Détails de la demande</h2>
                <p style="color: #666; margin-bottom: 30px;">
                    Numéro de dossier : <strong><?php echo htmlspecialchars($demande['numero_demande']); ?></strong>
                </p>
                
                <!-- Informations de base -->
                <div class="info-section">
                    <h3><i class="fas fa-user"></i> Informations personnelles</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Nom complet</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date de naissance</div>
                            <div class="info-value"><?php echo date('d/m/Y', strtotime($demande['date_naissance'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['email']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Téléphone</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['telephone']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Informations académiques -->
                <div class="info-section">
                    <h3><i class="fas fa-graduation-cap"></i> Informations académiques</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Cycle de formation</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['cycle_formation']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Filière</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['filiere']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Niveau</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['niveau']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Site de formation</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['site_formation']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Informations de paiement -->
                <?php if ($demande['montant_total']): ?>
                <div class="info-section">
                    <h3><i class="fas fa-money-bill-wave"></i> Informations de paiement</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Mode de paiement</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['mode_paiement']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Périodicité</div>
                            <div class="info-value"><?php echo htmlspecialchars($demande['periodicite_paiement']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Montant total</div>
                            <div class="info-value"><?php echo number_format($demande['montant_total'], 0, ',', ' ') . ' FCFA'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Statut paiement</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo $demande['statut_paiement'] ?? 'en_attente'; ?>" style="padding: 4px 10px; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($demande['statut_paiement'] ?? 'En attente'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Dates importantes -->
                <div class="info-section">
                    <h3><i class="fas fa-calendar-alt"></i> Dates importantes</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Date de la demande</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($demande['date_demande'])); ?></div>
                        </div>
                        <?php if ($demande['date_traitement']): ?>
                        <div class="info-item">
                            <div class="info-label">Date de traitement</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($demande['date_traitement'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($demande['date_validation']): ?>
                        <div class="info-item">
                            <div class="info-label">Date de validation</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($demande['date_validation'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Commentaires admin -->
                <?php if ($demande['commentaire_admin'] || $demande['raison_rejet']): ?>
                <div class="info-section">
                    <h3><i class="fas fa-comments"></i> Communication de l'administration</h3>
                    <?php if ($demande['commentaire_admin']): ?>
                    <div class="message-box">
                        <h4><i class="fas fa-info-circle"></i> Commentaire</h4>
                        <p><?php echo nl2br(htmlspecialchars($demande['commentaire_admin'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($demande['raison_rejet']): ?>
                    <div class="message-box error">
                        <h4><i class="fas fa-exclamation-circle"></i> Raison du rejet</h4>
                        <p><?php echo nl2br(htmlspecialchars($demande['raison_rejet'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; text-align: center;">
                    <a href="inscription.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Nouvelle inscription
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i> Retour à l'accueil
                    </a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>ISGI</h3>
                    <p><?php echo htmlspecialchars($configs['site_nom'] ?? ''); ?>, formant les leaders de demain.</p>
                    <div style="margin-top: 15px;">
                        <i class="fas fa-map-marker-alt"></i> Brazzaville, Congo<br>
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($configs['site_telephone'] ?? '+242 06 848 45 67'); ?><br>
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($configs['site_email'] ?? 'contact@isgi.cg'); ?>
                    </div>
                </div>
                
                <div class="footer-section">
                    <h3>Liens rapides</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="inscription.php">Inscription</a></li>
                        <li><a href="suivi-demande.php">Suivi demande</a></li>
                        <li><a href="admin/login.php">Connexion</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Ressources</h3>
                    <ul class="footer-links">
                        <li><a href="#">Emploi du temps</a></li>
                        <li><a href="#">Résultats d'examens</a></li>
                        <li><a href="#">Bibliothèque numérique</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>
</body>
</html>