<?php
ob_start();
session_start();
require_once 'config/database.php';

// Initialisation
$db = Database::getInstance()->getConnection();
$errors = [];
$success = false;
$form_data = [];
$etudiant = null;

// Si on revient avec des erreurs
if (isset($_SESSION['form_errors'])) {
    $errors = $_SESSION['form_errors'];
    unset($_SESSION['form_errors']);
}

if (isset($_SESSION['form_data'])) {
    $form_data = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Vérifier si un étudiant est connecté (via session)
$matricule = $_SESSION['etudiant_matricule'] ?? null;

// Si pas de session, vérifier si on a un matricule en GET (pour accès direct)
if (!$matricule && isset($_GET['matricule'])) {
    $matricule = trim($_GET['matricule']);
}

// Récupérer les informations de l'étudiant si matricule existe
if ($matricule && !empty($matricule)) {
    try {
        // Rechercher l'étudiant par matricule avec plus d'informations
        $stmt = $db->prepare("
            SELECT e.*, 
                   u.email as email_utilisateur,
                   u.telephone as telephone_utilisateur
            FROM etudiants e
            LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
            WHERE e.matricule = ?
        ");
        $stmt->execute([$matricule]);
        $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$etudiant) {
            $errors[] = "Aucun étudiant trouvé avec le numéro de dossier : " . htmlspecialchars($matricule);
        } else {
            // Vérifier si l'étudiant est actif
            if (isset($etudiant['est_actif']) && $etudiant['est_actif'] == 0) {
                $errors[] = "Cet étudiant n'est plus actif. Veuillez contacter l'administration.";
            }
            
            // Vérifier le statut
            if (isset($etudiant['statut']) && $etudiant['statut'] == 'desactive') {
                $errors[] = "Ce compte étudiant a été désactivé.";
            }
            
            // Définir des valeurs par défaut pour les champs manquants
            $etudiant['filiere'] = isset($etudiant['filiere']) && !empty($etudiant['filiere']) ? 
                $etudiant['filiere'] : 'Non spécifiée';
            $etudiant['niveau'] = isset($etudiant['niveau']) && !empty($etudiant['niveau']) ? 
                $etudiant['niveau'] : 'Non spécifié';
            $etudiant['cycle_formation'] = isset($etudiant['cycle_formation']) && !empty($etudiant['cycle_formation']) ? 
                $etudiant['cycle_formation'] : 'Non spécifié';
            $etudiant['email'] = $etudiant['email_utilisateur'] ?? $etudiant['email'] ?? 'Non spécifié';
            $etudiant['telephone'] = $etudiant['telephone_utilisateur'] ?? $etudiant['telephone'] ?? 'Non spécifié';
            $etudiant['mode_paiement'] = $etudiant['mode_paiement'] ?? 'Non spécifié';
        }
    } catch (PDOException $e) {
        error_log("Erreur recherche étudiant: " . $e->getMessage());
        $errors[] = "Erreur lors de la recherche de l'étudiant.";
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

// Récupérer toutes les filières depuis la base (pour le select)
$allFilieres = [];
try {
    $stmt = $db->query("SELECT * FROM filieres WHERE est_actif = 1 ORDER BY nom");
    $allFilieres = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur chargement filières: " . $e->getMessage());
}

// Récupérer les filières par cycle (pour référence)
$filieresByCycle = [];
try {
    $stmt = $db->query("SELECT * FROM filieres WHERE est_actif = 1 ORDER BY cycle, nom");
    $filieresTemp = $stmt->fetchAll();
    foreach ($filieresTemp as $filiere) {
        $cycle = $filiere['cycle'] ?? 'Non défini';
        if (!isset($filieresByCycle[$cycle])) {
            $filieresByCycle[$cycle] = [];
        }
        $filieresByCycle[$cycle][] = $filiere;
    }
} catch (PDOException $e) {
    error_log("Erreur chargement filières par cycle: " . $e->getMessage());
}

// Déterminer l'année académique suivante
$annee_courante = date('Y');
$mois_courant = date('m');
if ($mois_courant >= 9) { // Si on est après septembre, année suivante
    $annee_academique = $annee_courante . '-' . ($annee_courante + 1);
} else {
    $annee_academique = ($annee_courante - 1) . '-' . $annee_courante;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Réinscription</title>
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
        
        /* Header et navigation */
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
        
        .logo {
            height: 60px;
            width: auto;
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
        
        .auth-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
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
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
        }
        
        /* Page de réinscription */
        .reinscription-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
            padding: 40px 20px;
            background-color: #f8f9fa;
        }
        
        .reinscription-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1000px;
            overflow: hidden;
        }
        
        .reinscription-header {
            background-color: var(--primary-color);
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .reinscription-header h2 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .reinscription-body {
            padding: 30px;
        }
        
        .reinscription-step {
            display: none;
        }
        
        .reinscription-step.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .form-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--info-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-section h4 i {
            color: var(--info-color);
        }
        
        .progress-container {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 40px;
        }
        
        .progress-container::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #e0e0e0;
            z-index: 1;
            transform: translateY(-50%);
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 8px;
            transition: all 0.3s;
        }
        
        .progress-step.active .step-circle {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .step-circle.completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .step-label {
            font-size: 0.9rem;
            color: #666;
            text-align: center;
        }
        
        .progress-step.active .step-label {
            color: var(--secondary-color);
            font-weight: 600;
        }
        
        .reinscription-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid var(--info-color);
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 0 5px 5px 0;
        }
        
        .message-box.success {
            background-color: #d4edda;
            border-left-color: var(--success-color);
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
        
        .file-upload {
            border: 2px dashed #ddd;
            border-radius: 5px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        
        .file-upload:hover {
            border-color: var(--secondary-color);
        }
        
        .file-upload i {
            font-size: 2rem;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }
        
        .paiement-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .paiement-option {
            border: 2px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .paiement-option:hover {
            border-color: var(--secondary-color);
            background-color: #f0f8ff;
        }
        
        .paiement-option.selected {
            border-color: var(--success-color);
            background-color: #f0fff4;
        }
        
        .paiement-option i {
            font-size: 2rem;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }
        
        /* Résumé étudiant */
        .etudiant-summary {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .summary-item {
            padding: 10px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .summary-label {
            font-weight: bold;
            color: #666;
            font-size: 0.9rem;
            display: block;
        }
        
        .summary-value {
            font-size: 1.1rem;
            margin-top: 5px;
            color: var(--primary-color);
        }
        
        .matricule-badge {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .required::after {
            content: " *";
            color: var(--accent-color);
        }
        
        .field-error {
            border-color: var(--accent-color) !important;
        }
        
        .error-text {
            color: var(--accent-color);
            font-size: 0.85rem;
            margin-top: 5px;
            display: block;
        }
        
        /* Loader */
        .loader {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        
        .loader.active {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            
            .paiement-options {
                grid-template-columns: 1fr;
            }
            
            .progress-step .step-label {
                font-size: 0.8rem;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .reinscription-nav {
                flex-direction: column;
                gap: 15px;
            }
            
            .progress-container {
                flex-wrap: wrap;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Loader -->
    <div class="loader" id="loader">
        <div class="spinner"></div>
    </div>
    
    <!-- Header avec logo ISGI -->
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
                <a href="reinscription.php" class="active">Réinscription</a>
                <a href="contacter.php">Nous Contacter</a>
                <a href="apropos.php">A propos de nous</a>
            </nav>
            
            <div class="auth-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='admin/login.php'">Se connecter</button>
                <button class="btn btn-primary" onclick="window.location.href='inscription.php'">S'inscrire</button>
            </div>
        </div>
    </header>

    <!-- Messages d'erreur -->
    <?php if (!empty($errors)): ?>
        <div class="container" style="margin-top: 20px;">
            <div class="message-box error" style="max-width: 600px; margin: 0 auto;">
                <h4><i class="fas fa-search"></i> Numéro de dossier non trouvé</h4>
                <p>Nous n'avons pas trouvé d'étudiant correspondant au numéro de dossier :</p>
                <div style="text-align: center; margin: 15px 0;">
                    <div style="background: #f8d7da; padding: 10px; border-radius: 5px; display: inline-block;">
                        <code style="font-size: 1.2rem; font-weight: bold; color: #721c24;">
                            <?php echo htmlspecialchars($matricule ?? ''); ?>
                        </code>
                    </div>
                </div>
                
                <p><strong>Que faire ?</strong></p>
                <ul style="margin-bottom: 20px;">
                    <li>Vérifiez que le numéro de dossier est correct (exemple: <code>ISGI-2025-00001</code>)</li>
                    <li>Assurez-vous de ne pas avoir fait de fautes de frappe</li>
                    <li>Si vous pensez qu'il s'agit d'une erreur, contactez l'administration</li>
                    <li>Vérifiez que vous avez terminé votre inscription initiale</li>
                </ul>
                
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="window.location.href='reinscription.php'">
                        <i class="fas fa-redo"></i> Réessayer avec un autre numéro
                    </button>
                    <button class="btn btn-secondary" onclick="window.location.href='inscription.php'">
                        <i class="fas fa-user-plus"></i> Faire une première inscription
                    </button>
                    <button class="btn btn-secondary" onclick="window.location.href='contact.php'">
                        <i class="fas fa-envelope"></i> Contacter l'administration
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Message de succès -->
    <?php if (isset($_SESSION['reinscription_success'])): ?>
        <div class="container" style="margin-top: 20px;">
            <div class="message-box success">
                <h4><i class="fas fa-check-circle"></i> Réinscription réussie !</h4>
                <p>Votre réinscription pour l'année académique <?php echo htmlspecialchars($_SESSION['annee_academique'] ?? ''); ?> a été enregistrée.</p>
                
                <?php if (isset($_SESSION['validation_automatique'])): ?>
                    <div style="background-color: #e8f4fd; padding: 10px; border-radius: 5px; margin: 10px 0;">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Bonus :</strong> Votre dossier d'inscription initial a également été validé automatiquement.
                    </div>
                    <?php unset($_SESSION['validation_automatique']); ?>
                <?php endif; ?>
                
                <p><strong>Détails :</strong></p>
                <ul>
                    <?php if (isset($_SESSION['filiere'])): ?>
                        <li>Filière : <?php echo htmlspecialchars($_SESSION['filiere']); ?></li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['niveau'])): ?>
                        <li>Niveau : <?php echo htmlspecialchars($_SESSION['niveau']); ?></li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['matricule'])): ?>
                        <li>Matricule : <?php echo htmlspecialchars($_SESSION['matricule']); ?></li>
                    <?php endif; ?>
                </ul>
                <p>Vous recevrez une confirmation par email.</p>
                
                <div style="margin-top: 20px;">
                    <button class="btn btn-primary" onclick="window.location.href='reinscription.php'">
                        <i class="fas fa-redo"></i> Faire une autre réinscription
                    </button>
                </div>
            </div>
        </div>
        <?php 
        unset($_SESSION['reinscription_success']);
        unset($_SESSION['annee_academique']);
        unset($_SESSION['matricule']);
        unset($_SESSION['filiere']);
        unset($_SESSION['niveau']);
        ?>
    <?php endif; ?>

    <!-- Page de Réinscription -->
    <section id="reinscription-page" class="reinscription-container">
        <div class="reinscription-card">
            <div class="reinscription-header">
                <h2><i class="fas fa-redo-alt"></i> Réinscription - Étudiant</h2>
                <p>Procédure de réinscription pour l'année académique suivante</p>
                <p style="margin-top: 10px; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Frais de réinscription : 
                    <strong><?php echo number_format($configs['frais_reinscription'] ?? 25000, 0, ',', ' '); ?> FCFA</strong>
                </p>
            </div>
            
            <?php if (!$etudiant || !empty($errors)): ?>
                <!-- Étape 1 : Identification de l'étudiant -->
                <div class="reinscription-step active" id="reinscription-step1">
                    <div class="message-box">
                        <h4><i class="fas fa-search"></i> Identification</h4>
                        <p>Veuillez saisir votre numéro de dossier (matricule) pour commencer la réinscription.</p>
                        <p><small><i class="fas fa-info-circle"></i> Exemple: ISGI-2025-00001</small></p>
                    </div>
                    
                    <form action="reinscription.php" method="GET" id="identification-form">
                        <div class="form-group" style="max-width: 400px; margin: 0 auto;">
                            <label for="matricule" class="required">Numéro de dossier (Matricule)</label>
                            <input type="text" id="matricule" name="matricule" class="form-control" 
                                   placeholder="Ex: ISGI-2025-00001" 
                                   value="<?php echo htmlspecialchars($matricule ?? ''); ?>" 
                                   required>
                            <small style="display: block; margin-top: 5px; color: #666;">
                                <i class="fas fa-info-circle"></i> Le numéro de dossier vous a été attribué lors de votre inscription.
                            </small>
                        </div>
                        
                        <div class="reinscription-nav" style="justify-content: center; margin-top: 30px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Rechercher mon dossier
                            </button>
                        </div>
                    </form>
                    
                    <div style="text-align: center; margin-top: 30px;">
                        <p>Vous n'avez pas encore de dossier ?</p>
                        <button class="btn btn-secondary" onclick="window.location.href='inscription.php'">
                            <i class="fas fa-user-plus"></i> Faire une première inscription
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Indicateur de progression -->
                <div class="progress-container">
                    <div class="progress-step active" id="step1">
                        <div class="step-circle">1</div>
                        <div class="step-label">Validation<br>des infos</div>
                    </div>
                    <div class="progress-step" id="step2">
                        <div class="step-circle">2</div>
                        <div class="step-label">Mise à jour<br>académique</div>
                    </div>
                    <div class="progress-step" id="step3">
                        <div class="step-circle">3</div>
                        <div class="step-label">Paiement</div>
                    </div>
                    <div class="progress-step" id="step4">
                        <div class="step-circle">4</div>
                        <div class="step-label">Confirmation</div>
                    </div>
                </div>
                
                <!-- Affichage des informations de l'étudiant -->
                <div class="etudiant-summary">
                    <div class="matricule-badge">
                        <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($etudiant['matricule']); ?>
                    </div>
                    <h4><i class="fas fa-user-graduate"></i> Informations de l'étudiant</h4>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">Nom complet</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Filière actuelle</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['filiere']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Niveau actuel</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['niveau']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Cycle</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['cycle_formation']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Téléphone</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['telephone']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Email</span>
                            <span class="summary-value"><?php echo htmlspecialchars($etudiant['email']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Année académique</span>
                            <span class="summary-value"><?php echo htmlspecialchars($annee_academique); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Statut</span>
                            <span class="summary-value">
                                <?php 
                                $statut = $etudiant['statut'] ?? 'en_attente';
                                $statut_labels = [
                                    'en_attente' => 'En attente',
                                    'valide' => 'Validé',
                                    'refuse' => 'Refusé',
                                    'desactive' => 'Désactivé'
                                ];
                                echo htmlspecialchars($statut_labels[$statut] ?? $statut);
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <form action="traitement-reinscription.php" method="POST" id="reinscription-form">
                    <!-- Champs cachés -->
                    <input type="hidden" name="etudiant_id" value="<?php echo htmlspecialchars($etudiant['id']); ?>">
                    <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($etudiant['matricule']); ?>">
                    <input type="hidden" name="annee_academique" value="<?php echo htmlspecialchars($annee_academique); ?>">
                    
                    <!-- Étape 1 : Validation des informations -->
                    <div class="reinscription-step active" id="reinscription-step1">
                        <div class="message-box">
                            <h4><i class="fas fa-check-circle"></i> Étape 1 : Validation des informations personnelles</h4>
                            <p>Veuillez vérifier vos informations personnelles avant de continuer.</p>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-user-check"></i> Informations personnelles</h4>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Nom complet</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Email</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <?php echo htmlspecialchars($etudiant['email']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Téléphone</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <?php echo htmlspecialchars($etudiant['telephone']); ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Adresse</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <?php echo htmlspecialchars($etudiant['adresse']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Date de naissance</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($etudiant['date_naissance']))); ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label>Lieu de naissance</label>
                                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                                        <?php echo htmlspecialchars($etudiant['lieu_naissance']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="reinscription-nav">
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='reinscription.php'">
                                    <i class="fas fa-times"></i> Annuler
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" onclick="nextReinscriptionStep()">
                                    Continuer <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Étape 2 : Mise à jour académique -->
                    <div class="reinscription-step" id="reinscription-step2">
                        <div class="message-box">
                            <h4><i class="fas fa-graduation-cap"></i> Étape 2 : Informations académiques</h4>
                            <p>Veuillez confirmer ou modifier vos informations académiques pour la nouvelle année.</p>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-book-open"></i> Parcours académique</h4>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="filiere_reinscription" class="required">Filière</label>
                                    <select id="filiere_reinscription" name="filiere" class="form-control" required>
                                        <option value="">Sélectionnez votre filière</option>
                                        <?php 
                                        $currentFiliere = $etudiant['filiere'] ?? '';
                                        $currentFiliereClean = trim(strtolower($currentFiliere));
                                        
                                        foreach ($allFilieres as $filiere): 
                                            $filiereNomClean = trim(strtolower($filiere['nom']));
                                            $selected = ($currentFiliereClean == $filiereNomClean) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo htmlspecialchars($filiere['nom']); ?>"
                                                <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($filiere['nom']); ?>
                                                <?php if (isset($filiere['cycle']) && !empty($filiere['cycle'])): ?>
                                                    (<?php echo htmlspecialchars($filiere['cycle']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                        
                                        <!-- Option pour créer une nouvelle filière si nécessaire -->
                                        <?php if (!empty($currentFiliere) && $currentFiliere != 'Non spécifiée'): ?>
                                            <?php 
                                            $filiereExists = false;
                                            foreach ($allFilieres as $filiere) {
                                                if (trim(strtolower($filiere['nom'])) == $currentFiliereClean) {
                                                    $filiereExists = true;
                                                    break;
                                                }
                                            }
                                            if (!$filiereExists): ?>
                                                <option value="<?php echo htmlspecialchars($currentFiliere); ?>" selected>
                                                    <?php echo htmlspecialchars($currentFiliere); ?> (Non trouvée dans la liste)
                                                </option>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="niveau_reinscription" class="required">Niveau</label>
                                    <select id="niveau_reinscription" name="niveau" class="form-control" required>
                                        <option value="">Sélectionnez votre niveau</option>
                                        <?php 
                                        // Déterminer le niveau suivant
                                        $niveau_actuel = $etudiant['niveau'] ?? '';
                                        $cycle = $etudiant['cycle_formation'] ?? '';
                                        
                                        // Si le cycle n'est pas défini, essayer de le déduire du niveau
                                        if (empty($cycle) && !empty($niveau_actuel)) {
                                            if (strpos($niveau_actuel, 'BTS') !== false) $cycle = 'BTS';
                                            elseif (strpos($niveau_actuel, 'Licence') !== false) $cycle = 'Licence';
                                            elseif (strpos($niveau_actuel, 'Master') !== false) $cycle = 'Master';
                                        }
                                        
                                        // Liste des niveaux disponibles par cycle
                                        $niveaux = [
                                            'BTS' => ['BTS 1', 'BTS 2'],
                                            'Licence' => ['Licence 1', 'Licence 2', 'Licence 3'],
                                            'Master' => ['Master 1', 'Master 2']
                                        ];
                                        
                                        if (isset($niveaux[$cycle])) {
                                            // Par défaut, sélectionner le niveau suivant
                                            $niveau_suivant = '';
                                            if ($niveau_actuel == 'BTS 1') $niveau_suivant = 'BTS 2';
                                            elseif ($niveau_actuel == 'Licence 1') $niveau_suivant = 'Licence 2';
                                            elseif ($niveau_actuel == 'Licence 2') $niveau_suivant = 'Licence 3';
                                            elseif ($niveau_actuel == 'Master 1') $niveau_suivant = 'Master 2';
                                            
                                            foreach ($niveaux[$cycle] as $niveau) {
                                                $selected = '';
                                                if (!empty($niveau_suivant) && $niveau == $niveau_suivant) {
                                                    $selected = 'selected';
                                                } elseif ($niveau == $niveau_actuel) {
                                                    $selected = 'selected';
                                                }
                                                
                                                echo "<option value=\"" . htmlspecialchars($niveau) . "\" $selected>" . 
                                                     htmlspecialchars($niveau) . "</option>";
                                            }
                                        } else {
                                            // Fallback si le cycle n'est pas reconnu
                                            if (!empty($niveau_actuel)) {
                                                echo "<option value=\"" . htmlspecialchars($niveau_actuel) . "\" selected>" . 
                                                     htmlspecialchars($niveau_actuel) . "</option>";
                                            }
                                            // Options générales
                                            $allNiveaux = ['BTS 1', 'BTS 2', 'Licence 1', 'Licence 2', 'Licence 3', 'Master 1', 'Master 2'];
                                            foreach ($allNiveaux as $niveau) {
                                                if ($niveau != $niveau_actuel) {
                                                    echo "<option value=\"" . htmlspecialchars($niveau) . "\">" . 
                                                         htmlspecialchars($niveau) . "</option>";
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="type_rentree_reinscription" class="required">Type de rentrée</label>
                                    <select id="type_rentree_reinscription" name="type_rentree" class="form-control" required>
                                        <option value="">Sélectionnez</option>
                                        <option value="Octobre" <?php echo (($etudiant['type_rentree'] ?? '') == 'Octobre') ? 'selected' : ''; ?>>Rentrée d'octobre</option>
                                        <option value="Janvier" <?php echo (($etudiant['type_rentree'] ?? '') == 'Janvier') ? 'selected' : ''; ?>>Rentrée de janvier</option>
                                        <option value="Avril" <?php echo (($etudiant['type_rentree'] ?? '') == 'Avril') ? 'selected' : ''; ?>>Rentrée d'avril</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="site_formation_reinscription" class="required">Site de formation</label>
                                    <select id="site_formation_reinscription" name="site_formation" class="form-control" required>
                                        <option value="">Sélectionnez</option>
                                        <option value="Brazzaville" <?php echo (($etudiant['site_formation'] ?? '') == 'Brazzaville') ? 'selected' : ''; ?>>Brazzaville</option>
                                        <option value="Pointe-Noire" <?php echo (($etudiant['site_formation'] ?? '') == 'Pointe-Noire') ? 'selected' : ''; ?>>Pointe-Noire</option>
                                        <option value="Ouesso" <?php echo (($etudiant['site_formation'] ?? '') == 'Ouesso') ? 'selected' : ''; ?>>Ouesso</option>
                                        <option value="En ligne" <?php echo (($etudiant['site_formation'] ?? '') == 'En ligne') ? 'selected' : ''; ?>>En ligne</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="cycle_formation_reinscription" class="required">Cycle de formation</label>
                                <select id="cycle_formation_reinscription" name="cycle_formation" class="form-control" required>
                                    <option value="">Sélectionnez le cycle</option>
                                    <option value="BTS" <?php echo (($etudiant['cycle_formation'] ?? '') == 'BTS') ? 'selected' : ''; ?>>BTS</option>
                                    <option value="Licence" <?php echo (($etudiant['cycle_formation'] ?? '') == 'Licence') ? 'selected' : ''; ?>>Licence</option>
                                    <option value="Master" <?php echo (($etudiant['cycle_formation'] ?? '') == 'Master') ? 'selected' : ''; ?>>Master</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="motif_reinscription">Motif spécial (optionnel)</label>
                                <textarea id="motif_reinscription" name="motif" class="form-control" rows="3" 
                                          placeholder="Si vous avez un motif particulier pour la réinscription..."></textarea>
                            </div>
                        </div>
                        
                        <div class="reinscription-nav">
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="prevReinscriptionStep()">
                                    <i class="fas fa-arrow-left"></i> Précédent
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" onclick="nextReinscriptionStep()">
                                    Suivant <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Étape 3 : Paiement -->
                    <div class="reinscription-step" id="reinscription-step3">
                        <div class="message-box">
                            <h4><i class="fas fa-money-bill-wave"></i> Étape 3 : Paiement des frais de réinscription</h4>
                            <p>Frais de réinscription : <strong><?php echo number_format($configs['frais_reinscription'] ?? 25000, 0, ',', ' '); ?> FCFA</strong></p>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-credit-card"></i> Mode de paiement</h4>
                            <div class="paiement-options">
                                <div class="paiement-option" onclick="selectPaiementOption(this, 'Espèce')">
                                    <i class="fas fa-money-bill"></i>
                                    <h5>Espèce</h5>
                                    <p>Paiement au secretariat de l'ISGI</p>
                                </div>
                                
                                <div class="paiement-option" onclick="selectPaiementOption(this, 'MTN Mobile Money')">
                                    <i class="fas fa-mobile-alt"></i>
                                    <h5>MTN Mobile Money</h5>
                                    <p>Paiement via numéro MTN</p>
                                </div>
                                
                                <div class="paiement-option" onclick="selectPaiementOption(this, 'Airtel Money')">
                                    <i class="fas fa-mobile-alt"></i>
                                    <h5>Airtel Money</h5>
                                    <p>Paiement via Airtel Money</p>
                                </div>
                                
                                <div class="paiement-option" onclick="selectPaiementOption(this, 'Virement bancaire')">
                                    <i class="fas fa-university"></i>
                                    <h5>Virement bancaire</h5>
                                    <p>Virement sur compte ISGI</p>
                                </div>
                            </div>
                            <input type="hidden" id="mode_paiement_reinscription" name="mode_paiement" value="<?php echo htmlspecialchars($form_data['mode_paiement'] ?? $etudiant['mode_paiement'] ?? ''); ?>">
                            <span id="mode_paiement_reinscription_error" class="error-text"></span>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-file-invoice-dollar"></i> Informations de paiement</h4>
                            <div class="form-group">
                                <label for="numero_transaction">Numéro de transaction (si mobile money ou virement)</label>
                                <input type="text" id="numero_transaction" name="numero_transaction" class="form-control" 
                                       placeholder="Ex: TXN123456789" 
                                       value="<?php echo htmlspecialchars($form_data['numero_transaction'] ?? ''); ?>">
                                <small style="display: block; margin-top: 5px; color: #666;">
                                    <i class="fas fa-info-circle"></i> À remplir uniquement pour les paiements électroniques
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="date_paiement" class="required">Date de paiement prévue</label>
                                <input type="date" id="date_paiement" name="date_paiement" class="form-control"
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="periodicite_paiement" class="required">Périodicité des paiements</label>
                                <select id="periodicite_paiement" name="periodicite_paiement" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="Mensuel" <?php echo (($etudiant['periodicite_paiement'] ?? '') == 'Mensuel') ? 'selected' : ''; ?>>Mensuel</option>
                                    <option value="Trimestriel" <?php echo (($etudiant['periodicite_paiement'] ?? '') == 'Trimestriel') ? 'selected' : ''; ?>>Trimestriel</option>
                                    <option value="Semestriel" <?php echo (($etudiant['periodicite_paiement'] ?? '') == 'Semestriel') ? 'selected' : ''; ?>>Semestriel</option>
                                    <option value="Annuel" <?php echo (($etudiant['periodicite_paiement'] ?? '') == 'Annuel') ? 'selected' : ''; ?>>Annuel</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="reinscription-nav">
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="prevReinscriptionStep()">
                                    <i class="fas fa-arrow-left"></i> Précédent
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success" onclick="validateAndSubmitReinscription()">
                                    Finaliser la réinscription <i class="fas fa-check"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Étape 4 : Confirmation -->
                    <div class="reinscription-step" id="reinscription-step4">
                        <div class="message-box success">
                            <h4><i class="fas fa-check-circle"></i> Étape 4 : Confirmation</h4>
                            <p>Veuillez vérifier toutes les informations avant de soumettre votre réinscription.</p>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-clipboard-check"></i> Récapitulatif</h4>
                            <div class="summary-grid" id="recap-summary">
                                <!-- Rempli par JavaScript -->
                            </div>
                            
                            <div class="form-group" style="margin-top: 20px;">
                                <div class="checkbox-option" style="display: flex; align-items: flex-start; gap: 10px;">
                                    <input type="checkbox" id="confirmation_reinscription" name="confirmation" required style="margin-top: 3px;">
                                    <label for="confirmation_reinscription" style="margin: 0;">
                                        Je certifie que les informations ci-dessus sont exactes et j'accepte les conditions de réinscription.
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="reinscription-nav">
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="prevReinscriptionStep()">
                                    <i class="fas fa-arrow-left"></i> Modifier
                                </button>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-success" id="submit-reinscription">
                                    Confirmer la réinscription <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer id="main-footer">
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
                        <li><a href="reinscription.php">Réinscription</a></li>
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

    <script>
        // Variables globales pour la réinscription
        let currentReinscriptionStep = 1;
        
        // Navigation entre étapes
        function updateReinscriptionSteps() {
            // Masquer toutes les étapes
            document.querySelectorAll('#reinscription-page .reinscription-step').forEach(step => {
                if (step.id.startsWith('reinscription-step')) {
                    step.style.display = 'none';
                }
            });
            
            // Afficher l'étape courante
            const currentStep = document.getElementById(`reinscription-step${currentReinscriptionStep}`);
            if (currentStep) {
                currentStep.style.display = 'block';
            }
            
            // Mettre à jour la progression
            document.querySelectorAll('#reinscription-page .progress-step').forEach(step => {
                step.classList.remove('active');
                step.querySelector('.step-circle').classList.remove('completed');
            });
            
            for (let i = 1; i <= currentReinscriptionStep; i++) {
                const step = document.getElementById(`step${i}`);
                if (step) {
                    if (i < currentReinscriptionStep) {
                        step.querySelector('.step-circle').classList.add('completed');
                    } else {
                        step.classList.add('active');
                    }
                }
            }
            
            // Scroll vers le haut
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Si on est à l'étape 4, générer le récapitulatif
            if (currentReinscriptionStep === 4) {
                generateRecapSummary();
            }
        }
        
        function nextReinscriptionStep() {
            if (validateCurrentStep()) {
                if (currentReinscriptionStep < 4) {
                    currentReinscriptionStep++;
                    updateReinscriptionSteps();
                }
            }
        }
        
        function prevReinscriptionStep() {
            if (currentReinscriptionStep > 1) {
                currentReinscriptionStep--;
                updateReinscriptionSteps();
            }
        }
        
        // Validation des étapes
        function validateCurrentStep() {
            let isValid = true;
            
            if (currentReinscriptionStep === 2) {
                // Validation des informations académiques
                const filiere = document.getElementById('filiere_reinscription');
                const niveau = document.getElementById('niveau_reinscription');
                const typeRentree = document.getElementById('type_rentree_reinscription');
                const siteFormation = document.getElementById('site_formation_reinscription');
                const cycleFormation = document.getElementById('cycle_formation_reinscription');
                
                // Réinitialiser les erreurs
                [filiere, niveau, typeRentree, siteFormation, cycleFormation].forEach(field => {
                    field.classList.remove('field-error');
                });
                
                if (!filiere.value) {
                    filiere.classList.add('field-error');
                    isValid = false;
                }
                if (!niveau.value) {
                    niveau.classList.add('field-error');
                    isValid = false;
                }
                if (!typeRentree.value) {
                    typeRentree.classList.add('field-error');
                    isValid = false;
                }
                if (!siteFormation.value) {
                    siteFormation.classList.add('field-error');
                    isValid = false;
                }
                if (!cycleFormation.value) {
                    cycleFormation.classList.add('field-error');
                    isValid = false;
                }
            } else if (currentReinscriptionStep === 3) {
                // Validation du paiement
                const modePaiement = document.getElementById('mode_paiement_reinscription').value;
                const datePaiement = document.getElementById('date_paiement').value;
                const periodicite = document.getElementById('periodicite_paiement').value;
                
                // Réinitialiser les erreurs
                document.getElementById('mode_paiement_reinscription_error').textContent = '';
                document.getElementById('date_paiement').classList.remove('field-error');
                document.getElementById('periodicite_paiement').classList.remove('field-error');
                
                if (!modePaiement) {
                    document.getElementById('mode_paiement_reinscription_error').textContent = 
                        'Veuillez sélectionner un mode de paiement';
                    isValid = false;
                }
                
                if (!datePaiement) {
                    document.getElementById('date_paiement').classList.add('field-error');
                    isValid = false;
                }
                
                if (!periodicite) {
                    document.getElementById('periodicite_paiement').classList.add('field-error');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                alert('Veuillez remplir tous les champs obligatoires marqués en rouge.');
            }
            
            return isValid;
        }
        
        // Gestion des options de paiement
        function selectPaiementOption(element, value) {
            document.querySelectorAll('#reinscription-page .paiement-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            element.classList.add('selected');
            document.getElementById('mode_paiement_reinscription').value = value;
            document.getElementById('mode_paiement_reinscription_error').textContent = '';
        }
        
        // Générer le récapitulatif
        function generateRecapSummary() {
            const recapDiv = document.getElementById('recap-summary');
            const data = {
                'Filière': document.getElementById('filiere_reinscription').value,
                'Niveau': document.getElementById('niveau_reinscription').value,
                'Cycle de formation': document.getElementById('cycle_formation_reinscription').value,
                'Type de rentrée': document.getElementById('type_rentree_reinscription').value,
                'Site de formation': document.getElementById('site_formation_reinscription').value,
                'Mode de paiement': document.getElementById('mode_paiement_reinscription').value,
                'Date paiement prévue': document.getElementById('date_paiement').value,
                'Périodicité': document.getElementById('periodicite_paiement').value,
                'Numéro transaction': document.getElementById('numero_transaction').value || 'Non spécifié',
                'Année académique': document.querySelector('input[name="annee_academique"]').value
            };
            
            let html = '';
            for (const [label, value] of Object.entries(data)) {
                html += `
                    <div class="summary-item">
                        <span class="summary-label">${label}</span>
                        <span class="summary-value">${value}</span>
                    </div>
                `;
            }
            
            recapDiv.innerHTML = html;
        }
        
        // Soumission du formulaire
        function validateAndSubmitReinscription() {
            if (validateCurrentStep()) {
                currentReinscriptionStep = 4;
                updateReinscriptionSteps();
            }
        }
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($etudiant && empty($errors)): ?>
                // Initialiser les étapes si étudiant identifié
                updateReinscriptionSteps();
                
                // Pré-sélectionner l'option de paiement si existante
                const modePaiementValue = '<?php echo $etudiant["mode_paiement"] ?? ""; ?>';
                if (modePaiementValue) {
                    const paiementOptions = document.querySelectorAll('#reinscription-page .paiement-option');
                    paiementOptions.forEach(option => {
                        if (option.querySelector('h5').textContent.includes(modePaiementValue)) {
                            option.classList.add('selected');
                            document.getElementById('mode_paiement_reinscription').value = modePaiementValue;
                        }
                    });
                }
            <?php endif; ?>
            
            // Validation de la date de paiement (ne pas permettre les dates passées)
            const datePaiementInput = document.getElementById('date_paiement');
            if (datePaiementInput) {
                const today = new Date().toISOString().split('T')[0];
                datePaiementInput.min = today;
            }
            
            // Empêcher la soumission si la checkbox de confirmation n'est pas cochée
            const form = document.getElementById('reinscription-form');
            const submitBtn = document.getElementById('submit-reinscription');
            const confirmationCheckbox = document.getElementById('confirmation_reinscription');
            
            if (submitBtn && confirmationCheckbox) {
                submitBtn.addEventListener('click', function(e) {
                    if (!confirmationCheckbox.checked) {
                        e.preventDefault();
                        alert('Veuillez confirmer que les informations sont exactes en cochant la case de confirmation.');
                        confirmationCheckbox.focus();
                    } else {
                        // Afficher le loader
                        document.getElementById('loader').classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
<?php
ob_end_flush();
?>
</html>