<?php
session_start();
require_once 'config/database.php';

// Initialisation
$db = Database::getInstance()->getConnection();
$errors = [];
$success = false;
$form_data = [];

// Si on revient avec des erreurs
if (isset($_SESSION['form_errors'])) {
    $errors = $_SESSION['form_errors'];
    unset($_SESSION['form_errors']);
}

if (isset($_SESSION['form_data'])) {
    $form_data = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Récupérer les filières depuis la base
$filieres = [];
try {
    $stmt = $db->query("SELECT * FROM filieres WHERE est_actif = 1 ORDER BY domaine, cycle, nom");
    $filieres = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur chargement filières: " . $e->getMessage());
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
    <title>ISGI - Inscription</title>
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
        
        /* Page d'inscription */
        .inscription-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
            padding: 40px 20px;
            background-color: #f8f9fa;
        }
        
        .inscription-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1000px;
            overflow: hidden;
        }
        
        .inscription-header {
            background-color: var(--primary-color);
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .inscription-header h2 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .inscription-body {
            padding: 30px;
        }
        
        .inscription-step {
            display: none;
        }
        
        .inscription-step.active {
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
        
        .inscription-nav {
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
        
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
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
        
        /* Nouveaux styles pour les uploads de fichiers */
        .upload-preview {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .file-preview {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            width: 100px;
            text-align: center;
            background-color: #f9f9f9;
        }
        
        .file-preview img {
            max-width: 100%;
            max-height: 80px;
            border-radius: 3px;
        }
        
        .file-preview .file-name {
            font-size: 0.8rem;
            margin-top: 5px;
            word-break: break-all;
        }
        
        .file-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
        
        .file-size {
            font-size: 0.75rem;
            color: #888;
            margin-top: 3px;
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
            
            .radio-group {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .inscription-nav {
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
                <a href="inscription.php" class="active">Inscription</a>
                <a href="reinscription.php">Réinscription</a>
                <a href="contacter.php">Nous Contacter</a>
                <a href="apropos.php">A propos de nous</a>
            </nav>
            
            <div class="auth-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='admin/login.php'">Se connecter</button>
                <button class="btn btn-primary" onclick="window.location.href='inscription.php'">S'inscrire</button>
            </div>
        </div>
    </header>

    <!-- Messages d'erreur/success -->
    <?php if (!empty($errors)): ?>
        <div class="container" style="margin-top: 20px;">
            <div class="message-box error">
                <h4><i class="fas fa-exclamation-triangle"></i> Erreurs de validation</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['inscription_success'])): ?>
        <div class="container" style="margin-top: 20px;">
            <div class="message-box success">
                <h4><i class="fas fa-check-circle"></i> Demande d'inscription soumise avec succès !</h4>
                <p>Votre demande a été enregistrée. Votre numéro de dossier est : 
                   <strong><?php echo htmlspecialchars($_SESSION['numero_demande'] ?? ''); ?></strong></p>
                <p>Vous recevrez une confirmation par email après validation par l'administration.</p>
                
                <div style="margin-top: 20px;">
                    <button class="btn btn-primary" onclick="window.location.href='inscription.php'">
                        <i class="fas fa-redo"></i> Faire une autre demande
                    </button>
                    <button class="btn btn-secondary" onclick="window.location.href='suivi-demande.php'">
                        <i class="fas fa-search"></i> Suivre ma demande
                    </button>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['inscription_success']); ?>
    <?php endif; ?>

    <!-- Page d'Inscription -->
    <section id="inscription-page" class="inscription-container">
        <div class="inscription-card">
            <div class="inscription-header">
                <h2><i class="fas fa-user-graduate"></i> Demande d'Inscription - Étudiant</h2>
                <p>Procédure complète d'inscription en 4 étapes</p>
                <p style="margin-top: 10px; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Frais d'inscription : 
                    <strong><?php echo number_format($configs['frais_inscription'] ?? 50000, 0, ',', ' '); ?> FCFA</strong>
                </p>
            </div>
            
            <!-- Indicateur de progression -->
            <div class="progress-container">
                <div class="progress-step active" id="step1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Informations<br>Étudiant</div>
                </div>
                <div class="progress-step" id="step2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Documents<br>requis</div>
                </div>
                <div class="progress-step" id="step3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Paiement et<br>message</div>
                </div>
                <div class="progress-step" id="step4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Confirmation</div>
                </div>
            </div>
            
            <form action="traitement-inscription.php" method="POST" id="inscription-form" class="inscription-body" enctype="multipart/form-data">
                <!-- Étape 1 : Informations de l'étudiant -->
                <div class="inscription-step active" id="inscription-step1">
                    <div class="message-box">
                        <h4><i class="fas fa-info-circle"></i> Étape 1 : Informations de l'étudiant</h4>
                        <p>Veuillez remplir soigneusement tous les champs ci-dessous. Les champs marqués d'un <span class="required"></span> sont obligatoires.</p>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-graduation-cap"></i> Informations académiques</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cycle_formation" class="required">Cycle de formation</label>
                                <select id="cycle_formation" name="cycle_formation" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="BTS" <?php echo isset($form_data['cycle_formation']) && $form_data['cycle_formation'] == 'BTS' ? 'selected' : ''; ?>>BTS (2 ans)</option>
                                    <option value="Licence" <?php echo isset($form_data['cycle_formation']) && $form_data['cycle_formation'] == 'Licence' ? 'selected' : ''; ?>>Licence (3 ans)</option>
                                    <option value="Master" <?php echo isset($form_data['cycle_formation']) && $form_data['cycle_formation'] == 'Master' ? 'selected' : ''; ?>>Master (2 ans)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="type_rentree" class="required">Type de rentrée</label>
                                <select id="type_rentree" name="type_rentree" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="Octobre" <?php echo isset($form_data['type_rentree']) && $form_data['type_rentree'] == 'Octobre' ? 'selected' : ''; ?>>Rentrée d'octobre</option>
                                    <option value="Janvier" <?php echo isset($form_data['type_rentree']) && $form_data['type_rentree'] == 'Janvier' ? 'selected' : ''; ?>>Rentrée de janvier</option>
                                    <option value="Avril" <?php echo isset($form_data['type_rentree']) && $form_data['type_rentree'] == 'Avril' ? 'selected' : ''; ?>>Rentrée d'avril</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="domaine" class="required">Domaine</label>
                                <select id="domaine" name="domaine" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="Technologies" <?php echo isset($form_data['domaine']) && $form_data['domaine'] == 'Technologies' ? 'selected' : ''; ?>>Technologies</option>
                                    <option value="Gestion" <?php echo isset($form_data['domaine']) && $form_data['domaine'] == 'Gestion' ? 'selected' : ''; ?>>Gestion et administration</option>
                                    <option value="Droit" <?php echo isset($form_data['domaine']) && $form_data['domaine'] == 'Droit' ? 'selected' : ''; ?>>Droit privé et International</option>
                                    <option value="Industrie" <?php echo isset($form_data['domaine']) && $form_data['domaine'] == 'Industrie' ? 'selected' : ''; ?>>Industrie</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="niveau" class="required">Niveau d'étude</label>
                                <select id="niveau" name="niveau" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <?php if (isset($form_data['cycle_formation'])): ?>
                                        <?php if ($form_data['cycle_formation'] == 'BTS'): ?>
                                            <option value="BTS 1" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'BTS 1' ? 'selected' : ''; ?>>BTS 1</option>
                                            <option value="BTS 2" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'BTS 2' ? 'selected' : ''; ?>>BTS 2</option>
                                        <?php elseif ($form_data['cycle_formation'] == 'Licence'): ?>
                                            <option value="Licence 1" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'Licence 1' ? 'selected' : ''; ?>>Licence 1</option>
                                            <option value="Licence 2" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'Licence 2' ? 'selected' : ''; ?>>Licence 2</option>
                                            <option value="Licence 3" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'Licence 3' ? 'selected' : ''; ?>>Licence 3</option>
                                        <?php elseif ($form_data['cycle_formation'] == 'Master'): ?>
                                            <option value="Master 1" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'Master 1' ? 'selected' : ''; ?>>Master 1</option>
                                            <option value="Master 2" <?php echo isset($form_data['niveau']) && $form_data['niveau'] == 'Master 2' ? 'selected' : ''; ?>>Master 2</option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="filiere" class="required">Filière</label>
                                <select id="filiere" name="filiere" class="form-control" required>
                                    <option value="">Sélectionnez d'abord un domaine</option>
                                    <?php if (isset($form_data['domaine']) && isset($form_data['cycle_formation'])): ?>
                                        <?php foreach ($filieres as $filiere): ?>
                                            <?php if ($filiere['domaine'] == $form_data['domaine'] && $filiere['cycle'] == $form_data['cycle_formation']): ?>
                                                <option value="<?php echo htmlspecialchars($filiere['nom']); ?>"
                                                    <?php echo isset($form_data['filiere']) && $form_data['filiere'] == $filiere['nom'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($filiere['nom']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">Type de formation</label>
                                <div class="radio-group">
                                    <div class="radio-option">
                                        <input type="radio" id="normale" name="type_formation" value="Normale" 
                                               <?php echo (!isset($form_data['type_formation']) || $form_data['type_formation'] == 'Normale') ? 'checked' : ''; ?> required>
                                        <label for="normale">Normale (sans création d'entreprise)</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="speciale" name="type_formation" value="Spéciale"
                                               <?php echo isset($form_data['type_formation']) && $form_data['type_formation'] == 'Spéciale' ? 'checked' : ''; ?>>
                                        <label for="speciale">Spéciale (avec création d'entreprise)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="ecole">École partenaire</label>
                            <select id="ecole" name="ecole" class="form-control">
                                <option value="">Sélectionnez</option>
                                <option value="ISGI Congo" <?php echo isset($form_data['ecole']) && $form_data['ecole'] == 'ISGI Congo' ? 'selected' : ''; ?>>ISGI Congo</option>
                                <option value="OTHM Londres" <?php echo isset($form_data['ecole']) && $form_data['ecole'] == 'OTHM Londres' ? 'selected' : ''; ?>>OTHM Londres</option>
                                <option value="HORIZON France" <?php echo isset($form_data['ecole']) && $form_data['ecole'] == 'HORIZON France' ? 'selected' : ''; ?>>HORIZON France</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-id-card"></i> Informations personnelles</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="numero_cni" class="required">Numéro de la carte CNI/NUI ou passeport</label>
                                <input type="text" id="numero_cni" name="numero_cni" class="form-control" 
                                       placeholder="Ex: SN123456789" 
                                       value="<?php echo htmlspecialchars($form_data['numero_cni'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="sexe" class="required">Sexe</label>
                                <select id="sexe" name="sexe" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="M" <?php echo isset($form_data['sexe']) && $form_data['sexe'] == 'M' ? 'selected' : ''; ?>>Masculin</option>
                                    <option value="F" <?php echo isset($form_data['sexe']) && $form_data['sexe'] == 'F' ? 'selected' : ''; ?>>Féminin</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nom" class="required">Nom</label>
                                <input type="text" id="nom" name="nom" class="form-control" 
                                       placeholder="Votre nom" 
                                       value="<?php echo htmlspecialchars($form_data['nom'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="prenom" class="required">Prénom</label>
                                <input type="text" id="prenom" name="prenom" class="form-control" 
                                       placeholder="Votre prénom" 
                                       value="<?php echo htmlspecialchars($form_data['prenom'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="date_naissance" class="required">Date de naissance</label>
                                <input type="date" id="date_naissance" name="date_naissance" class="form-control"
                                       value="<?php echo htmlspecialchars($form_data['date_naissance'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="lieu_naissance" class="required">Lieu de naissance</label>
                                <input type="text" id="lieu_naissance" name="lieu_naissance" class="form-control" 
                                       placeholder="Ville et pays de naissance" 
                                       value="<?php echo htmlspecialchars($form_data['lieu_naissance'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="nationalite" class="required">Nationalité</label>
                            <input type="text" id="nationalite" name="nationalite" class="form-control" 
                                   placeholder="Votre nationalité" 
                                   value="<?php echo htmlspecialchars($form_data['nationalite'] ?? 'Congolaise'); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="adresse" class="required">Adresse complète</label>
                            <input type="text" id="adresse" name="adresse" class="form-control" 
                                   placeholder="Adresse, ville, code postal" 
                                   value="<?php echo htmlspecialchars($form_data['adresse'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="pays" class="required">Pays</label>
                                <select id="pays" name="pays" class="form-control" required>
                                    <option value="">Sélectionnez votre pays</option>
                                    <option value="Congo" <?php echo (!isset($form_data['pays']) || $form_data['pays'] == 'Congo') ? 'selected' : ''; ?>>Congo</option>
                                    <option value="Côte d'Ivoire" <?php echo isset($form_data['pays']) && $form_data['pays'] == 'Côte d\'Ivoire' ? 'selected' : ''; ?>>Côte d'Ivoire</option>
                                    <option value="Mali" <?php echo isset($form_data['pays']) && $form_data['pays'] == 'Mali' ? 'selected' : ''; ?>>Mali</option>
                                    <option value="Burkina Faso" <?php echo isset($form_data['pays']) && $form_data['pays'] == 'Burkina Faso' ? 'selected' : ''; ?>>Burkina Faso</option>
                                    <option value="Guinée" <?php echo isset($form_data['pays']) && $form_data['pays'] == 'Guinée' ? 'selected' : ''; ?>>Guinée</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="ville" class="required">Ville</label>
                                <input type="text" id="ville" name="ville" class="form-control" 
                                       placeholder="Votre ville de résidence" 
                                       value="<?php echo htmlspecialchars($form_data['ville'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="telephone" class="required">Téléphone</label>
                                <div style="display: flex;">
                                    <select id="indicatif" name="indicatif" class="form-control" style="width: 100px; margin-right: 10px;">
                                        <option value="+242" <?php echo (!isset($form_data['indicatif']) || $form_data['indicatif'] == '+242') ? 'selected' : ''; ?>>+242 (RC)</option>
                                        <option value="+225" <?php echo isset($form_data['indicatif']) && $form_data['indicatif'] == '+225' ? 'selected' : ''; ?>>+225 (CI)</option>
                                        <option value="+223" <?php echo isset($form_data['indicatif']) && $form_data['indicatif'] == '+223' ? 'selected' : ''; ?>>+223 (ML)</option>
                                        <option value="+226" <?php echo isset($form_data['indicatif']) && $form_data['indicatif'] == '+226' ? 'selected' : ''; ?>>+226 (BF)</option>
                                        <option value="+224" <?php echo isset($form_data['indicatif']) && $form_data['indicatif'] == '+224' ? 'selected' : ''; ?>>+224 (GN)</option>
                                    </select>
                                    <input type="tel" id="telephone" name="telephone" class="form-control" 
                                           placeholder="Numéro de téléphone" 
                                           value="<?php echo htmlspecialchars($form_data['telephone'] ?? ''); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="required">Email</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       placeholder="exemple@email.com" 
                                       value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="profession" class="required">Profession ou rôle</label>
                                <select id="profession" name="profession" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="Étudiant" <?php echo isset($form_data['profession']) && $form_data['profession'] == 'Étudiant' ? 'selected' : ''; ?>>Étudiant</option>
                                    <option value="Salarié" <?php echo isset($form_data['profession']) && $form_data['profession'] == 'Salarié' ? 'selected' : ''; ?>>Salarié</option>
                                    <option value="Fonctionnaire" <?php echo isset($form_data['profession']) && $form_data['profession'] == 'Fonctionnaire' ? 'selected' : ''; ?>>Fonctionnaire</option>
                                    <option value="Indépendant" <?php echo isset($form_data['profession']) && $form_data['profession'] == 'Indépendant' ? 'selected' : ''; ?>>Indépendant</option>
                                    <option value="Sans emploi" <?php echo isset($form_data['profession']) && $form_data['profession'] == 'Sans emploi' ? 'selected' : ''; ?>>Sans emploi</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="situation_matrimoniale" class="required">Situation matrimoniale</label>
                                <select id="situation_matrimoniale" name="situation_matrimoniale" class="form-control" required>
                                    <option value="">Sélectionnez</option>
                                    <option value="Célibataire" <?php echo (!isset($form_data['situation_matrimoniale']) || $form_data['situation_matrimoniale'] == 'Célibataire') ? 'selected' : ''; ?>>Célibataire</option>
                                    <option value="Marié(e)" <?php echo isset($form_data['situation_matrimoniale']) && $form_data['situation_matrimoniale'] == 'Marié(e)' ? 'selected' : ''; ?>>Marié(e)</option>
                                    <option value="Divorcé(e)" <?php echo isset($form_data['situation_matrimoniale']) && $form_data['situation_matrimoniale'] == 'Divorcé(e)' ? 'selected' : ''; ?>>Divorcé(e)</option>
                                    <option value="Veuf/Veuve" <?php echo isset($form_data['situation_matrimoniale']) && $form_data['situation_matrimoniale'] == 'Veuf/Veuve' ? 'selected' : ''; ?>>Veuf/Veuve</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="site_formation" class="required">Site de formation</label>
                            <select id="site_formation" name="site_formation" class="form-control" required>
                                <option value="">Sélectionnez</option>
                                <option value="Brazzaville" <?php echo isset($form_data['site_formation']) && $form_data['site_formation'] == 'Brazzaville' ? 'selected' : ''; ?>>Brazzaville</option>
                                <option value="Pointe-Noire" <?php echo isset($form_data['site_formation']) && $form_data['site_formation'] == 'Pointe-Noire' ? 'selected' : ''; ?>>Pointe-Noire</option>
                                <option value="Ouesso" <?php echo isset($form_data['site_formation']) && $form_data['site_formation'] == 'Ouesso' ? 'selected' : ''; ?>>Ouesso</option>
                                <option value="En ligne" <?php echo isset($form_data['site_formation']) && $form_data['site_formation'] == 'En ligne' ? 'selected' : ''; ?>>En ligne</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-users"></i> Renseignements sur les parents</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nom_pere" class="required">Nom complet du père</label>
                                <input type="text" id="nom_pere" name="nom_pere" class="form-control" 
                                       placeholder="Nom et prénom du père" 
                                       value="<?php echo htmlspecialchars($form_data['nom_pere'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="profession_pere">Profession du père</label>
                                <input type="text" id="profession_pere" name="profession_pere" class="form-control" 
                                       placeholder="Profession du père" 
                                       value="<?php echo htmlspecialchars($form_data['profession_pere'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nom_mere" class="required">Nom complet de la mère</label>
                                <input type="text" id="nom_mere" name="nom_mere" class="form-control" 
                                       placeholder="Nom et prénom de la mère" 
                                       value="<?php echo htmlspecialchars($form_data['nom_mere'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="profession_mere">Profession de la mère</label>
                                <input type="text" id="profession_mere" name="profession_mere" class="form-control" 
                                       placeholder="Profession de la mère" 
                                       value="<?php echo htmlspecialchars($form_data['profession_mere'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="telephone_parent" class="required">Téléphone des parents</label>
                            <div style="display: flex;">
                                <select id="indicatif_parent" name="indicatif_parent" class="form-control" style="width: 100px; margin-right: 10px;">
                                    <option value="+242" <?php echo (!isset($form_data['indicatif_parent']) || $form_data['indicatif_parent'] == '+242') ? 'selected' : ''; ?>>+242 (RC)</option>
                                    <option value="+225" <?php echo isset($form_data['indicatif_parent']) && $form_data['indicatif_parent'] == '+225' ? 'selected' : ''; ?>>+225 (CI)</option>
                                    <option value="+223" <?php echo isset($form_data['indicatif_parent']) && $form_data['indicatif_parent'] == '+223' ? 'selected' : ''; ?>>+223 (ML)</option>
                                    <option value="+226" <?php echo isset($form_data['indicatif_parent']) && $form_data['indicatif_parent'] == '+226' ? 'selected' : ''; ?>>+226 (BF)</option>
                                </select>
                                <input type="tel" id="telephone_parent" name="telephone_parent" class="form-control" 
                                       placeholder="Numéro de téléphone des parents" 
                                       value="<?php echo htmlspecialchars($form_data['telephone_parent'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-user-tie"></i> Information du tuteur (si différent des parents)</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nom_tuteur">Nom complet du tuteur</label>
                                <input type="text" id="nom_tuteur" name="nom_tuteur" class="form-control" 
                                       placeholder="Nom et prénom du tuteur" 
                                       value="<?php echo htmlspecialchars($form_data['nom_tuteur'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="profession_tuteur">Profession du tuteur</label>
                                <input type="text" id="profession_tuteur" name="profession_tuteur" class="form-control" 
                                       placeholder="Profession du tuteur" 
                                       value="<?php echo htmlspecialchars($form_data['profession_tuteur'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="telephone_tuteur">Téléphone du tuteur</label>
                                <div style="display: flex;">
                                    <select id="indicatif_tuteur" name="indicatif_tuteur" class="form-control" style="width: 100px; margin-right: 10px;">
                                        <option value="+242" <?php echo (!isset($form_data['indicatif_tuteur']) || $form_data['indicatif_tuteur'] == '+242') ? 'selected' : ''; ?>>+242 (RC)</option>
                                        <option value="+225" <?php echo isset($form_data['indicatif_tuteur']) && $form_data['indicatif_tuteur'] == '+225' ? 'selected' : ''; ?>>+225 (CI)</option>
                                        <option value="+223" <?php echo isset($form_data['indicatif_tuteur']) && $form_data['indicatif_tuteur'] == '+223' ? 'selected' : ''; ?>>+223 (ML)</option>
                                    </select>
                                    <input type="tel" id="telephone_tuteur" name="telephone_tuteur" class="form-control" 
                                           placeholder="Numéro du tuteur" 
                                           value="<?php echo htmlspecialchars($form_data['telephone_tuteur'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="lieu_service_tuteur">Lieu de service du tuteur</label>
                                <input type="text" id="lieu_service_tuteur" name="lieu_service_tuteur" class="form-control" 
                                       placeholder="Entreprise ou institution" 
                                       value="<?php echo htmlspecialchars($form_data['lieu_service_tuteur'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="inscription-nav">
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php'">
                                <i class="fas fa-arrow-left"></i> Annuler
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" onclick="nextInscriptionStep()">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Étape 2 : Documents requis -->
                <div class="inscription-step" id="inscription-step2">
                    <div class="message-box">
                        <h4><i class="fas fa-file-upload"></i> Étape 2 : Documents requis</h4>
                        <p>Veuillez télécharger les documents obligatoires pour votre inscription. Tous les documents doivent être au format PDF ou image (JPG, PNG).</p>
                        <p><small><i class="fas fa-exclamation-circle"></i> Taille maximale par fichier : 2MB. Formats acceptés : PDF, JPG, JPEG, PNG</small></p>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-id-card"></i> Documents d'identité et académiques</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="photo_identite" class="required">Photo d'identité</label>
                                <div class="file-upload" onclick="document.getElementById('photo_identite').click()">
                                    <i class="fas fa-camera"></i>
                                    <p>Cliquez pour télécharger la photo</p>
                                    <p class="file-requirements">Format : JPG/PNG • Taille max : 2MB • Fond clair</p>
                                </div>
                                <input type="file" id="photo_identite" name="photo_identite" accept="image/*" style="display: none;" onchange="previewFile(this, 'photo-preview')">
                                <div id="photo-preview" class="upload-preview"></div>
                                <span id="photo_error" class="error-text"></span>
                            </div>
                            
                            <div class="form-group">
                                <label for="acte_naissance" class="required">Acte de naissance</label>
                                <div class="file-upload" onclick="document.getElementById('acte_naissance').click()">
                                    <i class="fas fa-file-certificate"></i>
                                    <p>Cliquez pour télécharger l'acte</p>
                                    <p class="file-requirements">Format : PDF/JPG/PNG • Taille max : 2MB</p>
                                </div>
                                <input type="file" id="acte_naissance" name="acte_naissance" accept=".pdf,.jpg,.jpeg,.png" style="display: none;" onchange="previewFile(this, 'acte-preview')">
                                <div id="acte-preview" class="upload-preview"></div>
                                <span id="acte_error" class="error-text"></span>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="releve_notes" class="required">Relevé de notes ou dernier diplôme</label>
                                <div class="file-upload" onclick="document.getElementById('releve_notes').click()">
                                    <i class="fas fa-file-alt"></i>
                                    <p>Cliquez pour télécharger le relevé</p>
                                    <p class="file-requirements">Format : PDF/JPG/PNG • Taille max : 2MB</p>
                                </div>
                                <input type="file" id="releve_notes" name="releve_notes" accept=".pdf,.jpg,.jpeg,.png" style="display: none;" onchange="previewFile(this, 'releve-preview')">
                                <div id="releve-preview" class="upload-preview"></div>
                                <span id="releve_error" class="error-text"></span>
                            </div>
                            
                            <div class="form-group">
                                <label for="attestation_legalisee" class="required">Attestation légalisée</label>
                                <div class="file-upload" onclick="document.getElementById('attestation_legalisee').click()">
                                    <i class="fas fa-stamp"></i>
                                    <p>Cliquez pour télécharger l'attestation</p>
                                    <p class="file-requirements">Format : PDF/JPG/PNG • Taille max : 2MB</p>
                                </div>
                                <input type="file" id="attestation_legalisee" name="attestation_legalisee" accept=".pdf,.jpg,.jpeg,.png" style="display: none;" onchange="previewFile(this, 'attestation-preview')">
                                <div id="attestation-preview" class="upload-preview"></div>
                                <span id="attestation_error" class="error-text"></span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="autres_documents">Autres documents (optionnel)</label>
                            <div class="file-upload" onclick="document.getElementById('autres_documents').click()">
                                <i class="fas fa-folder-plus"></i>
                                <p>Cliquez pour ajouter d'autres documents</p>
                                <p class="file-requirements">CV, Lettre de motivation, etc.</p>
                            </div>
                            <input type="file" id="autres_documents" name="autres_documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display: none;" onchange="previewMultipleFiles(this, 'autres-preview')" multiple>
                            <div id="autres-preview" class="upload-preview"></div>
                        </div>
                    </div>
                    
                    <div class="inscription-nav">
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="prevInscriptionStep()">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" onclick="nextInscriptionStep()">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Étape 3 : Paiement et Message -->
                <div class="inscription-step" id="inscription-step3">
                    <div class="message-box">
                        <h4><i class="fas fa-money-bill-wave"></i> Étape 3 : Modalités de paiement et message</h4>
                        <p>Sélectionnez votre mode de paiement et laissez un message à l'administration si nécessaire.</p>
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
                        <input type="hidden" id="mode_paiement" name="mode_paiement" value="<?php echo htmlspecialchars($form_data['mode_paiement'] ?? ''); ?>">
                        <span id="mode_paiement_error" class="error-text"></span>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-calendar-alt"></i> Périodicité des paiements</h4>
                        <div class="radio-group" style="flex-direction: column;">
                            <div class="radio-option">
                                <input type="radio" id="mensuel" name="periodicite_paiement" value="Mensuel"
                                       <?php echo (!isset($form_data['periodicite_paiement']) || $form_data['periodicite_paiement'] == 'Mensuel') ? 'checked' : ''; ?>>
                                <label for="mensuel">Paiement mensuel (le 31 de chaque mois)</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="trimestriel" name="periodicite_paiement" value="Trimestriel"
                                       <?php echo isset($form_data['periodicite_paiement']) && $form_data['periodicite_paiement'] == 'Trimestriel' ? 'checked' : ''; ?>>
                                <label for="trimestriel">Paiement trimestriel</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="semestriel" name="periodicite_paiement" value="Semestriel"
                                       <?php echo isset($form_data['periodicite_paiement']) && $form_data['periodicite_paiement'] == 'Semestriel' ? 'checked' : ''; ?>>
                                <label for="semestriel">Paiement semestriel</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="annuel" name="periodicite_paiement" value="Annuel"
                                       <?php echo isset($form_data['periodicite_paiement']) && $form_data['periodicite_paiement'] == 'Annuel' ? 'checked' : ''; ?>>
                                <label for="annuel">Paiement annuel (toute l'année)</label>
                            </div>
                        </div>
                        <span id="periodicite_error" class="error-text"></span>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-envelope"></i> Message à l'administration</h4>
                        <div class="form-group">
                            <label for="comment_connaissance" class="required">Comment avez-vous connu ISGI ?</label>
                            <select id="comment_connaissance" name="comment_connaissance" class="form-control" required>
                                <option value="">Sélectionnez</option>
                                <option value="Internet" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Internet' ? 'selected' : ''; ?>>Internet / Site web</option>
                                <option value="Réseaux sociaux" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Réseaux sociaux' ? 'selected' : ''; ?>>Réseaux sociaux</option>
                                <option value="Par un ami" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Par un ami' ? 'selected' : ''; ?>>Par un ami / connaissance</option>
                                <option value="Ancien étudiant" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Ancien étudiant' ? 'selected' : ''; ?>>Ancien étudiant de l'ISGI</option>
                                <option value="Salon" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Salon' ? 'selected' : ''; ?>>Salon d'orientation</option>
                                <option value="Presse" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Presse' ? 'selected' : ''; ?>>Presse / Médias</option>
                                <option value="Autre" <?php echo isset($form_data['comment_connaissance']) && $form_data['comment_connaissance'] == 'Autre' ? 'selected' : ''; ?>>Autre</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="commentaires">Autres descriptions ou commentaires</label>
                            <textarea id="commentaires" name="commentaires" class="form-control" rows="5" 
                                      placeholder="Vous pouvez ajouter des informations supplémentaires ici..."><?php echo htmlspecialchars($form_data['commentaires'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="inscription-nav">
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="prevInscriptionStep()">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-success" id="submit-inscription">
                                Soumettre la demande <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
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
                        <li><a href="suivi-demande.php">Suivi de demande</a></li>
                        <li><a href="#">Emploi du temps</a></li>
                        <li><a href="#">Résultats d'examens</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>

    <script>
        // Variables globales
        let currentInscriptionStep = 1;
        
        // Gestion des étapes d'inscription
        function updateInscriptionSteps() {
            // Masquer toutes les étapes
            document.querySelectorAll('.inscription-step').forEach(step => {
                step.style.display = 'none';
            });
            
            // Afficher l'étape courante
            const currentStep = document.getElementById(`inscription-step${currentInscriptionStep}`);
            if (currentStep) {
                currentStep.style.display = 'block';
            }
            
            // Mettre à jour la progression
            document.querySelectorAll('#inscription-page .progress-step').forEach(step => {
                step.classList.remove('active');
                step.querySelector('.step-circle').classList.remove('completed');
            });
            
            for (let i = 1; i <= currentInscriptionStep; i++) {
                const step = document.getElementById(`step${i}`);
                if (step) {
                    if (i < currentInscriptionStep) {
                        step.querySelector('.step-circle').classList.add('completed');
                    } else {
                        step.classList.add('active');
                    }
                }
            }
            
            // Scroll vers le haut
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function nextInscriptionStep() {
            if (validateCurrentStep()) {
                if (currentInscriptionStep < 3) {
                    currentInscriptionStep++;
                    updateInscriptionSteps();
                }
            }
        }
        
        function prevInscriptionStep() {
            if (currentInscriptionStep > 1) {
                currentInscriptionStep--;
                updateInscriptionSteps();
            }
        }
        
        // Validation des étapes - VERSION SIMPLIFIÉE POUR LE DEBUG
        function validateCurrentStep() {
            let isValid = true;
            
            if (currentInscriptionStep === 1) {
                // Validation des informations de base
                const requiredFields = [
                    'cycle_formation', 'type_rentree', 'domaine', 'niveau', 'filiere',
                    'numero_cni', 'sexe', 'nom', 'prenom', 'date_naissance',
                    'lieu_naissance', 'nationalite', 'adresse', 'pays', 'ville',
                    'telephone', 'email', 'profession', 'situation_matrimoniale',
                    'site_formation', 'nom_pere', 'nom_mere', 'telephone_parent'
                ];
                
                // Réinitialiser les erreurs
                document.querySelectorAll('.field-error').forEach(el => {
                    el.classList.remove('field-error');
                });
                
                for (let fieldId of requiredFields) {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        let value = field.value;
                        
                        // Gestion spéciale pour les radios
                        if (fieldId === 'type_formation') {
                            const typeFormationRadio = document.querySelector('input[name="type_formation"]:checked');
                            value = typeFormationRadio ? typeFormationRadio.value : '';
                        }
                        
                        if (!value || value.trim() === '') {
                            field.classList.add('field-error');
                            isValid = false;
                        }
                    }
                }
                
                // Validation email spécifique
                const emailField = document.getElementById('email');
                if (emailField && emailField.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(emailField.value)) {
                        emailField.classList.add('field-error');
                        isValid = false;
                    }
                }
                
            } else if (currentInscriptionStep === 2) {
                // Validation SIMPLIFIÉE des documents - juste vérifier qu'ils sont présents
                const requiredDocuments = [
                    'photo_identite', 'acte_naissance', 'releve_notes', 'attestation_legalisee'
                ];
                
                // Réinitialiser les erreurs
                document.querySelectorAll('.error-text').forEach(el => {
                    el.textContent = '';
                });
                
                for (let docId of requiredDocuments) {
                    const fileInput = document.getElementById(docId);
                    const errorSpan = document.getElementById(docId + '_error');
                    
                    if (fileInput) {
                        if (!fileInput.files || fileInput.files.length === 0) {
                            if (errorSpan) {
                                errorSpan.textContent = 'Ce document est obligatoire';
                            }
                            isValid = false;
                        }
                    }
                }
            } else if (currentInscriptionStep === 3) {
                // Validation du paiement
                const modePaiement = document.getElementById('mode_paiement').value;
                const periodicite = document.querySelector('input[name="periodicite_paiement"]:checked');
                const commentConnaissance = document.getElementById('comment_connaissance').value;
                
                // Réinitialiser les erreurs
                document.getElementById('mode_paiement_error').textContent = '';
                document.getElementById('periodicite_error').textContent = '';
                
                if (!modePaiement) {
                    document.getElementById('mode_paiement_error').textContent = 'Veuillez sélectionner un mode de paiement';
                    isValid = false;
                }
                
                if (!periodicite) {
                    document.getElementById('periodicite_error').textContent = 'Veuillez sélectionner une périodicité';
                    isValid = false;
                }
                
                if (!commentConnaissance) {
                    document.getElementById('comment_connaissance').classList.add('field-error');
                    isValid = false;
                } else {
                    document.getElementById('comment_connaissance').classList.remove('field-error');
                }
            }
            
            if (!isValid) {
                alert('Veuillez remplir tous les champs obligatoires.');
            }
            
            return isValid;
        }
        
        // Gestion des options de paiement
        function selectPaiementOption(element, value) {
            document.querySelectorAll('#inscription-page .paiement-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            element.classList.add('selected');
            document.getElementById('mode_paiement').value = value;
            document.getElementById('mode_paiement_error').textContent = '';
        }
        
        // Prévisualisation des fichiers
        function previewFile(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const fileType = file.type.split('/')[0];
                    let previewContent = '';
                    
                    if (fileType === 'image') {
                        previewContent = `
                            <div class="file-preview">
                                <img src="${e.target.result}" alt="${file.name}">
                                <div class="file-name">${file.name.substring(0, 15)}...</div>
                                <div class="file-size">${formatFileSize(file.size)}</div>
                            </div>
                        `;
                    } else {
                        previewContent = `
                            <div class="file-preview">
                                <i class="fas fa-file-pdf" style="font-size: 2rem; color: #e74c3c;"></i>
                                <div class="file-name">${file.name.substring(0, 15)}...</div>
                                <div class="file-size">${formatFileSize(file.size)}</div>
                            </div>
                        `;
                    }
                    
                    preview.innerHTML = previewContent;
                };
                
                reader.readAsDataURL(file);
            }
        }
        
        function previewMultipleFiles(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            
            if (input.files) {
                for (let i = 0; i < input.files.length; i++) {
                    const file = input.files[i];
                    const reader = new FileReader();
                    
                    reader.onload = (function(file) {
                        return function(e) {
                            const fileType = file.type;
                            let icon = 'fa-file';
                            let color = '#3498db';
                            
                            if (fileType.includes('pdf')) {
                                icon = 'fa-file-pdf';
                                color = '#e74c3c';
                            } else if (fileType.includes('image')) {
                                icon = 'fa-file-image';
                                color = '#27ae60';
                            } else if (fileType.includes('word')) {
                                icon = 'fa-file-word';
                                color = '#2c3e50';
                            }
                            
                            const previewContent = `
                                <div class="file-preview">
                                    <i class="fas ${icon}" style="font-size: 2rem; color: ${color};"></i>
                                    <div class="file-name">${file.name.substring(0, 12)}...</div>
                                    <div class="file-size">${formatFileSize(file.size)}</div>
                                </div>
                            `;
                            
                            preview.innerHTML += previewContent;
                        };
                    })(file);
                    
                    reader.readAsDataURL(file);
                }
            }
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Mise à jour des options dynamiques
        document.getElementById('cycle_formation').addEventListener('change', function() {
            updateNiveauOptions();
            updateFiliereOptions();
        });
        
        document.getElementById('domaine').addEventListener('change', function() {
            updateFiliereOptions();
        });
        
        function updateNiveauOptions() {
            const cycle = document.getElementById('cycle_formation').value;
            const niveauSelect = document.getElementById('niveau');
            
            niveauSelect.innerHTML = '<option value="">Sélectionnez</option>';
            
            if (cycle === 'BTS') {
                niveauSelect.innerHTML += `
                    <option value="BTS 1">BTS 1</option>
                    <option value="BTS 2">BTS 2</option>
                `;
            } else if (cycle === 'Licence') {
                niveauSelect.innerHTML += `
                    <option value="Licence 1">Licence 1</option>
                    <option value="Licence 2">Licence 2</option>
                    <option value="Licence 3">Licence 3</option>
                `;
            } else if (cycle === 'Master') {
                niveauSelect.innerHTML += `
                    <option value="Master 1">Master 1</option>
                    <option value="Master 2">Master 2</option>
                `;
            }
        }
        
        function updateFiliereOptions() {
            const domaine = document.getElementById('domaine').value;
            const cycle = document.getElementById('cycle_formation').value;
            const filiereSelect = document.getElementById('filiere');
            
            filiereSelect.innerHTML = '<option value="">Sélectionnez d\'abord un domaine</option>';
            
            if (domaine && cycle) {
                let options = '';
                
                if (domaine === 'Technologies') {
                    if (cycle === 'BTS') {
                        options = `
                            <option value="Développement Web et Mobile">Développement Web et Mobile</option>
                            <option value="Réseaux Informatiques">Réseaux Informatiques</option>
                        `;
                    } else if (cycle === 'Licence') {
                        options = `
                            <option value="Génie Logiciel">Génie Logiciel</option>
                            <option value="Informatique de Gestion">Informatique de Gestion</option>
                        `;
                    } else if (cycle === 'Master') {
                        options = `
                            <option value="Intelligence Artificielle">Intelligence Artificielle</option>
                            <option value="Cybersécurité">Cybersécurité</option>
                        `;
                    }
                } else if (domaine === 'Gestion') {
                    if (cycle === 'BTS') {
                        options = `
                            <option value="Comptabilité et Gestion">Comptabilité et Gestion</option>
                            <option value="Assistance de Gestion">Assistance de Gestion</option>
                        `;
                    } else if (cycle === 'Licence') {
                        options = `
                            <option value="Finance et Comptabilité">Finance et Comptabilité</option>
                            <option value="Marketing et Vente">Marketing et Vente</option>
                            <option value="Ressources Humaines">Ressources Humaines</option>
                        `;
                    } else if (cycle === 'Master') {
                        options = `
                            <option value="Audit et Contrôle de Gestion">Audit et Contrôle de Gestion</option>
                            <option value="Management Stratégique">Management Stratégique</option>
                        `;
                    }
                } else if (domaine === 'Droit') {
                    if (cycle === 'Licence') {
                        options = `
                            <option value="Droit des Affaires">Droit des Affaires</option>
                            <option value="Droit International">Droit International</option>
                        `;
                    } else if (cycle === 'Master') {
                        options = `
                            <option value="Droit des Entreprises">Droit des Entreprises</option>
                            <option value="Droit de l'Environnement">Droit de l'Environnement</option>
                        `;
                    }
                } else if (domaine === 'Industrie') {
                    if (cycle === 'BTS') {
                        options = `
                            <option value="Maintenance Industrielle">Maintenance Industrielle</option>
                            <option value="Qualité et Sécurité">Qualité et Sécurité</option>
                        `;
                    } else if (cycle === 'Licence') {
                        options = `
                            <option value="Génie Industriel">Génie Industriel</option>
                            <option value="Logistique et Transport">Logistique et Transport</option>
                        `;
                    }
                }
                
                if (options) {
                    filiereSelect.innerHTML = '<option value="">Sélectionnez</option>' + options;
                }
            }
        }
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser les étapes
            updateInscriptionSteps();
            
            // Pré-remplir les options si des données existent
            <?php if (isset($form_data['cycle_formation'])): ?>
                updateNiveauOptions();
            <?php endif; ?>
            
            <?php if (isset($form_data['domaine']) && isset($form_data['cycle_formation'])): ?>
                updateFiliereOptions();
            <?php endif; ?>
            
            // Pré-sélectionner l'option de paiement si existante
            const modePaiementValue = '<?php echo $form_data["mode_paiement"] ?? ""; ?>';
            if (modePaiementValue) {
                const paiementOptions = document.querySelectorAll('#inscription-page .paiement-option');
                paiementOptions.forEach(option => {
                    if (option.querySelector('h5').textContent.includes(modePaiementValue)) {
                        option.classList.add('selected');
                        document.getElementById('mode_paiement').value = modePaiementValue;
                    }
                });
            }
            
            // Gestion de la soumission du formulaire
            const submitBtn = document.getElementById('submit-inscription');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    if (validateCurrentStep()) {
                        // Afficher le loader
                        document.getElementById('loader').classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>