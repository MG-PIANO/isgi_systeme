<?php
// auth/login.php avec double authentification pour production
require_once '../config/database.php';
require_once '../lib/EmailSender.php';

session_start();

// Initialiser toutes les variables importantes
$step = isset($_GET['step']) ? $_GET['step'] : 'login';
$error = '';
$success = '';
$user_data = [];
$email_sent = false; // <-- Initialiser ici

// Rediriger si déjà connecté et vérifié 2FA
if (isset($_SESSION['user_id']) && isset($_SESSION['2fa_verified']) && $_SESSION['2fa_verified'] === true) {
    header('Location: ../dashboard/');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        
        // ÉTAPE 1: Vérification des identifiants
        if ($step === 'login') {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                $error = 'Veuillez remplir tous les champs';
            } else {
                // Rechercher l'utilisateur
                $query = "SELECT u.*, r.nom as role_nom, s.nom as site_nom 
                          FROM utilisateurs u
                          LEFT JOIN roles r ON u.role_id = r.id
                          LEFT JOIN sites s ON u.site_id = s.id
                          WHERE u.email = ? AND u.statut = 'actif'";
                
                $stmt = $db->prepare($query);
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Vérification du mot de passe
                    $password_valid = false;
                    
                    // Essayer d'abord avec password_verify
                    if (password_verify($password, $user['mot_de_passe'])) {
                        $password_valid = true;
                    }
                    // Compatibilité avec mots de passe en clair
                    elseif ($password === $user['mot_de_passe']) {
                        $password_valid = true;
                        // Hasher pour la prochaine fois
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $updateStmt = $db->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?");
                        $updateStmt->execute([$hashed_password, $user['id']]);
                    }
                    
                    if ($password_valid) {
                        // Générer un code de vérification
                        $verification_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                        
                        // Stocker en session
                        $_SESSION['2fa_user_id'] = $user['id'];
                        $_SESSION['2fa_code'] = $verification_code;
                        $_SESSION['2fa_expires'] = $expires_at;
                        $_SESSION['2fa_user_data'] = $user;
                        
                        // Envoyer l'email
                        $emailSender = EmailSender::getInstance();
                        $email_sent = $emailSender->sendVerificationCode(
                            $user['email'],
                            $user['prenom'] . ' ' . $user['nom'],
                            $verification_code
                        );
                        
                        if ($email_sent) {
                            $_SESSION['demo_2fa_code'] = $verification_code;
                            header('Location: ?step=verification');
                            exit();
                        } else {
                            $error = 'Erreur d\'envoi du code. Veuillez réessayer.';
                            // Nettoyer la session
                            unset($_SESSION['2fa_user_id']);
                            unset($_SESSION['2fa_code']);
                            unset($_SESSION['2fa_expires']);
                            unset($_SESSION['2fa_user_data']);
                        }
                    } else {
                        $error = 'Mot de passe incorrect';
                    }
                } else {
                    $error = 'Aucun compte actif trouvé avec cet email';
                }
            }
        }
        
        // ÉTAPE 2: Vérification du code
        elseif ($step === 'verification') {
            $entered_code = '';
            
            // Concaténer les 6 chiffres
            for ($i = 1; $i <= 6; $i++) {
                $entered_code .= $_POST['code' . $i] ?? '';
            }
            
            if (strlen($entered_code) !== 6) {
                $error = 'Veuillez entrer un code complet à 6 chiffres';
            } elseif (!isset($_SESSION['2fa_user_id']) || !isset($_SESSION['2fa_code'])) {
                $error = 'Session expirée. Veuillez vous reconnecter.';
                $step = 'login';
            } elseif (strtotime($_SESSION['2fa_expires']) < time()) {
                $error = 'Le code a expiré. Veuillez vous reconnecter.';
                $step = 'login';
            } elseif ($entered_code !== $_SESSION['2fa_code']) {
                $error = 'Code incorrect. Essayez à nouveau.';
            } else {
                // Code correct - connecter l'utilisateur
                $user = $_SESSION['2fa_user_data'];
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['site_id'] = $user['site_id'];
                $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
                $_SESSION['role_name'] = $user['role_nom'];
                $_SESSION['site_name'] = $user['site_nom'];
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['2fa_verified'] = true;
                
                // Mettre à jour dernière connexion
                $updateQuery = "UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([$user['id']]);
                
                // Nettoyer la session 2FA
                unset($_SESSION['2fa_user_id']);
                unset($_SESSION['2fa_code']);
                unset($_SESSION['2fa_expires']);
                unset($_SESSION['2fa_user_data']);
                unset($_SESSION['demo_2fa_code']);
                
                // Redirection vers dashboard
                header('Location: ../dashboard/');
                exit();
            }
            
            // Récupérer les données utilisateur pour l'affichage
            if (isset($_SESSION['2fa_user_data'])) {
                $user_data = $_SESSION['2fa_user_data'];
            }
        }
        
    } catch (Exception $e) {
        error_log("Erreur login 2FA: " . $e->getMessage());
        $error = 'Une erreur est survenue. Veuillez réessayer.';
    }
} elseif ($step === 'verification' && isset($_SESSION['2fa_user_data'])) {
    // Charger les données utilisateur pour l'affichage
    $user_data = $_SESSION['2fa_user_data'];
}

// Gérer le renvoi de code
if (isset($_GET['resend']) && $_GET['resend'] == 1 && isset($_SESSION['2fa_user_data'])) {
    try {
        $user = $_SESSION['2fa_user_data'];
        
        // Générer un nouveau code
        $new_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['2fa_code'] = $new_code;
        $_SESSION['2fa_expires'] = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Renvoyer l'email
        $emailSender = EmailSender::getInstance();
        $email_sent = $emailSender->sendVerificationCode(
            $user['email'],
            $user['prenom'] . ' ' . $user['nom'],
            $new_code
        );
        
        if ($email_sent) {
            $success = 'Un nouveau code a été envoyé à votre email.';
        } else {
            $error = 'Erreur lors du renvoi du code.';
        }
        
    } catch (Exception $e) {
        error_log("Erreur renvoi code: " . $e->getMessage());
        $error = 'Erreur lors du renvoi du code.';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Authentification 2FA</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-blue: #0066cc;
            --secondary-blue: #0052a3;
            --accent-orange: #ff6b35;
            --success-green: #28a745;
            --warning-yellow: #ffc107;
            --danger-red: #dc3545;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #343a40;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.12);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.15);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f8ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .container-fluid {
            max-width: 1400px;
            padding: 20px;
        }
        
        /* Header Style */
        .main-header {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border-left: 5px solid var(--primary-blue);
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-icon {
            background: var(--primary-blue);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .logo-text h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--primary-blue);
        }
        
        .logo-text p {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 0;
        }
        
        /* Main Container */
        .auth-main-container {
            display: flex;
            gap: 30px;
            min-height: calc(100vh - 150px);
        }
        
        /* Left Info Panel */
        .info-panel {
            flex: 0 0 400px;
            background: white;
            border-radius: var(--radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-md);
            display: flex;
            flex-direction: column;
        }
        
        .panel-header {
            margin-bottom: 30px;
        }
        
        .panel-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 10px;
            line-height: 1.3;
        }
        
        .panel-header p {
            color: var(--text-secondary);
            font-size: 16px;
        }
        
        .security-features {
            margin-top: 40px;
        }
        
        .security-feature {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            border-radius: var(--radius-md);
            background: var(--light-gray);
            transition: transform 0.2s;
        }
        
        .security-feature:hover {
            transform: translateX(5px);
            background: #f0f7ff;
        }
        
        .feature-icon {
            background: var(--primary-blue);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .feature-text h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-primary);
        }
        
        .feature-text p {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 0;
        }
        
        /* Right Form Panel */
        .form-panel {
            flex: 1;
            background: white;
            border-radius: var(--radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-md);
            min-height: 600px;
            display: flex;
            flex-direction: column;
        }
        
        /* Form Styles */
        .form-container {
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .form-title {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-title h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .form-title p {
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        /* Form Card */
        .form-card {
            background: white;
            border-radius: var(--radius-md);
            border: 1px solid var(--medium-gray);
            padding: 30px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-blue), var(--secondary-blue));
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .form-label i {
            color: var(--primary-blue);
            width: 16px;
        }
        
        .form-control {
            padding: 12px 16px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--radius-sm);
            font-size: 15px;
            transition: all 0.3s;
            height: 48px;
        }
        
        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
            outline: none;
        }
        
        /* Password Input Group */
        .password-input-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px;
        }
        
        /* Buttons */
        .btn {
            padding: 14px 28px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            transition: all 0.3s;
            font-size: 15px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-blue);
            color: var(--primary-blue);
            width: 100%;
        }
        
        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }
        
        .btn-link {
            background: transparent;
            color: var(--primary-blue);
            text-decoration: none;
            padding: 8px 0;
            font-weight: 500;
        }
        
        .btn-link:hover {
            text-decoration: underline;
        }
        
        /* Alerts */
        .alert-container {
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 15px;
            border-left: 4px solid transparent;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success-green);
            color: #155724;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger-red);
            color: #721c24;
        }
        
        .alert-info {
            background-color: rgba(0, 102, 204, 0.1);
            border-left-color: var(--primary-blue);
            color: #004085;
        }
        
        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            border-left-color: var(--warning-yellow);
            color: #856404;
        }
        
        /* Verification Code Input */
        .verification-container {
            text-align: center;
            padding: 20px;
        }
        
        .verification-icon {
            font-size: 48px;
            color: var(--primary-blue);
            margin-bottom: 20px;
        }
        
        .code-inputs-container {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        
        .code-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid var(--medium-gray);
            border-radius: 8px;
            transition: all 0.3s;
            color: var(--text-primary);
        }
        
        .code-input:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
            outline: none;
        }
        
        .timer-container {
            margin-top: 20px;
            padding: 15px;
            background: var(--light-gray);
            border-radius: var(--radius-sm);
        }
        
        .timer {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .timer.expired {
            color: var(--danger-red);
        }
        
        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 50px;
            right: 50px;
            height: 2px;
            background: var(--medium-gray);
            z-index: 1;
        }
        
        .step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--medium-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--text-secondary);
            position: relative;
            z-index: 2;
            transition: all 0.3s;
        }
        
        .step.active {
            background: var(--primary-blue);
            border-color: var(--primary-blue);
            color: white;
        }
        
        .step.completed {
            background: var(--success-green);
            border-color: var(--success-green);
            color: white;
        }
        
        /* Demo Box */
        .demo-box {
            background: #f8fbff;
            border: 2px dashed var(--primary-blue);
            border-radius: var(--radius-sm);
            padding: 20px;
            margin-top: 25px;
            text-align: center;
        }
        
        .demo-box h5 {
            color: var(--primary-blue);
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .demo-code {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 3px;
            margin: 15px 0;
            padding: 15px;
            background: white;
            border-radius: var(--radius-sm);
        }
        
        /* Footer Links */
        .form-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--medium-gray);
            margin-top: auto;
        }
        
        .form-footer p {
            color: var(--text-secondary);
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .form-footer-links {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .form-footer-links a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-footer-links a:hover {
            text-decoration: underline;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .auth-main-container {
                flex-direction: column;
            }
            
            .info-panel {
                flex: none;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .container-fluid {
                padding: 15px;
            }
            
            .main-header {
                padding: 15px 20px;
            }
            
            .info-panel,
            .form-panel {
                padding: 25px;
            }
            
            .form-card {
                padding: 20px;
            }
            
            .code-inputs-container {
                gap: 5px;
            }
            
            .code-input {
                width: 40px;
                height: 50px;
                font-size: 20px;
            }
            
            .step-indicator {
                gap: 20px;
            }
            
            .step-indicator::before {
                left: 30px;
                right: 30px;
            }
            
            .form-footer-links {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .code-input {
                width: 35px;
                height: 45px;
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <header class="main-header">
            <div class="logo-container">
                <img src="../image/logo.jpg" alt="Logo ISGI" width="15%" style="border-radius: 5px; padding-left: 100px;">
                <div class="logo-text" style="padding-left: 50px;">
                    <h1>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <p>Système d'authentification sécurisée à double facteur</p>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="auth-main-container">
            <!-- Left Information Panel -->
            <aside class="info-panel">
                <div class="panel-header">
                    <h2>Authentification Sécurisée</h2>
                    <p>Connectez-vous à votre espace personnel avec une double vérification de sécurité</p>
                </div>
                
                <div class="security-features">
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Double Authentification</h4>
                            <p>Code de sécurité envoyé par email à chaque connexion</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-user-lock"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Rôles Personnalisés</h4>
                            <p>Accès selon votre profil utilisateur</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Sessions Sécurisées</h4>
                            <p>Déconnexion automatique après inactivité</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Accessible Partout</h4>
                            <p>Interface responsive compatible mobile et tablette</p>
                        </div>
                    </div>
                </div>
            </aside>
            
            <!-- Right Form Panel -->
            <section class="form-panel">
                <div class="form-container">
                    <!-- Alerts Container -->
                    <div class="alert-container" id="alertContainer">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step <?php echo $step === 'login' ? 'active' : 'completed'; ?>">1</div>
                        <div class="step <?php echo $step === 'verification' ? 'active' : ''; ?>">2</div>
                    </div>
                    
                    <!-- Dynamic Content -->
                    <?php if ($step === 'login'): ?>
                        <!-- ÉTAPE 1: Connexion -->
                        <div class="form-title">
                            <h2><i class="fas fa-sign-in-alt"></i> Connexion</h2>
                            <p>Accédez à votre espace personnel</p>
                        </div>
                        
                        <div class="form-card">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-envelope"></i> Adresse Email
                                    </label>
                                    <input type="email" class="form-control" name="email" 
                                           placeholder="votre@email.isgi.cg" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-lock"></i> Mot de passe
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" name="password" 
                                               placeholder="Votre mot de passe" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword(this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt"></i> Se connecter
                                    </button>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="?step=forgot_password" class="btn-link">
                                        <i class="fas fa-key"></i> Mot de passe oublié ?
                                    </a>
                                </div>
                            </form>
                        </div>
                        
                    <?php elseif ($step === 'verification' && !empty($user_data)): ?>
    <!-- ÉTAPE 2: Vérification 2FA -->
    <div class="form-title">
        <h2><i class="fas fa-shield-alt"></i> Vérification en deux étapes</h2>
        <p>Un code de sécurité a été envoyé à votre email</p>
    </div>
    
    <div class="form-card">
        <div class="verification-container">
            <div class="verification-icon">
                <i class="fas fa-envelope"></i>
            </div>
            
            <h4 style="margin-bottom: 10px;">Vérification requise</h4>
            <p style="color: #666; margin-bottom: 30px;">
                Pour sécuriser votre compte, un code à 6 chiffres a été envoyé à :
                <br>
                <strong><?php echo htmlspecialchars($user_data['email']); ?></strong>
            </p>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Code de vérification à 6 chiffres</label>
                    <div class="code-inputs-container">
                        <input type="text" class="code-input" name="code1" maxlength="1" 
                               oninput="moveToNext(this, 1)" onkeyup="moveToPrev(this, event)" 
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        <input type="text" class="code-input" name="code2" maxlength="1" 
                               oninput="moveToNext(this, 2)" onkeyup="moveToPrev(this, event)"
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        <input type="text" class="code-input" name="code3" maxlength="1" 
                               oninput="moveToNext(this, 3)" onkeyup="moveToPrev(this, event)"
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        <input type="text" class="code-input" name="code4" maxlength="1" 
                               oninput="moveToNext(this, 4)" onkeyup="moveToPrev(this, event)"
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        <input type="text" class="code-input" name="code5" maxlength="1" 
                               oninput="moveToNext(this, 5)" onkeyup="moveToPrev(this, event)"
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        <input type="text" class="code-input" name="code6" maxlength="1" 
                               oninput="moveToNext(this, 6)" onkeyup="moveToPrev(this, event)"
                               pattern="[0-9]" inputmode="numeric" autocomplete="off">
                    </div>
                </div>
                
                <div class="timer-container">
                    <div class="timer" id="timer">Le code expire dans : <strong>10:00</strong></div>
                </div>
                
                <div class="form-group" style="margin-top: 25px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> Vérifier le code
                    </button>
                </div>
            </form>
            
            <div style="margin-top: 20px;">
                <p style="color: #666; margin-bottom: 10px;">Vous n'avez pas reçu le code ?</p>
                <a href="?step=verification&resend=1" class="btn btn-outline">
                    <i class="fas fa-redo"></i> Renvoyer le code
                </a>
            </div>
        </div>
    </div>

                    <?php elseif ($step === 'forgot_password'): ?>
                        <!-- MOT DE PASSE OUBLIÉ -->
                        <div class="form-title">
                            <h2><i class="fas fa-key"></i> Mot de passe oublié</h2>
                            <p>Réinitialisez votre mot de passe en quelques étapes</p>
                        </div>
                        
                        <div class="form-card">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-envelope"></i> Adresse email
                                    </label>
                                    <input type="email" class="form-control" name="email" 
                                           placeholder="Entrez votre adresse email" required>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        Un lien de réinitialisation vous sera envoyé par email.
                                        Ce lien sera valable pendant 1 heure.
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Envoyer le lien
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                    <?php endif; ?>
                    
                    <!-- Footer -->
                    <div class="form-footer">
                        <p><?php echo $step === 'login' ? 'Système d\'authentification ISGI v2.0 - Production' : 'Vérification de sécurité en cours'; ?></p>
                        <div class="form-footer-links">
                            <?php if ($step === 'login'): ?>
                                <a href="#" onclick="alert('Support technique : support@isgi.cg')">
                                    <i class="fas fa-headset"></i> Support
                                </a>
                                <a href="#" onclick="alert('Système d\'authentification sécurisée ISGI')">
                                    <i class="fas fa-info-circle"></i> À propos
                                </a>
                            <?php elseif ($step === 'verification'): ?>
                                <a href="?step=login">
                                    <i class="fas fa-arrow-left"></i> Retour à la connexion
                                </a>
                            <?php elseif ($step === 'forgot_password'): ?>
                                <a href="?step=login">
                                    <i class="fas fa-arrow-left"></i> Retour à la connexion
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Application JavaScript -->
    <script>
        // Fonction pour basculer l'affichage du mot de passe
        function togglePassword(button) {
            const input = button.parentElement.querySelector('input');
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        // Fonctions pour la saisie du code
        function moveToNext(input, index) {
            if (input.value.length >= input.maxLength) {
                const nextInput = document.querySelector(`input[name="code${index + 1}"]`);
                if (nextInput) {
                    nextInput.focus();
                }
            }
            updateFullCode();
        }
        
        function moveToPrev(input, event) {
            if (event.key === 'Backspace' && input.value.length === 0) {
                const prevInput = input.previousElementSibling;
                if (prevInput) {
                    prevInput.focus();
                }
            }
            updateFullCode();
        }
        
        function updateFullCode() {
            const inputs = document.querySelectorAll('.code-input');
            let fullCode = '';
            inputs.forEach(input => {
                fullCode += input.value;
            });
            const fullCodeInput = document.getElementById('fullCode');
            if (fullCodeInput) {
                fullCodeInput.value = fullCode;
            }
        }
        
        // Timer pour le code de vérification
        <?php if ($step === 'verification'): ?>
        let timeLeft = 600; // 10 minutes en secondes
        
        const timerInterval = setInterval(() => {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            
            const timerElement = document.getElementById('timer');
            if (timerElement) {
                timerElement.innerHTML = `Le code expire dans : <strong>${minutes}:${seconds.toString().padStart(2, '0')}</strong>`;
                
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    timerElement.classList.add('expired');
                    timerElement.innerHTML = '<strong>Code expiré</strong> - Veuillez demander un nouveau code';
                }
            }
            
            timeLeft--;
        }, 1000);
        
        // Auto-focus sur le premier champ de code
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.querySelector('.code-input');
            if (firstInput) {
                firstInput.focus();
            }
        });
        <?php endif; ?>
        
        // Auto-focus sur le champ email au chargement
        <?php if ($step === 'login' || $step === 'forgot_password'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.querySelector('input[name="email"]');
            if (emailInput) {
                emailInput.focus();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>