<?php
session_start();
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Initialiser les variables
$nom = $email = $sujet = $message = $telephone = '';
$type_contact = 'general';
$site_id = 1;
$errors = [];
$success = false;

// Récupérer les sites
$sites = [];
try {
    $stmt = $db->query("SELECT id, nom, ville FROM sites WHERE statut = 'actif' ORDER BY id");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération sites: " . $e->getMessage());
    $sites = [
        ['id' => 1, 'nom' => 'ISGI Brazzaville', 'ville' => 'Brazzaville'],
        ['id' => 2, 'nom' => 'ISGI Pointe-Noire', 'ville' => 'Pointe-Noire'],
        ['id' => 3, 'nom' => 'ISGI Ouesso', 'ville' => 'Ouesso']
    ];
}

// Récupérer les informations de contact des sites
$contact_info = [];
try {
    $stmt = $db->query("
        SELECT id, nom, ville, adresse, telephone 
        FROM sites 
        WHERE statut = 'actif' 
        ORDER BY id
    ");
    $contact_info = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération infos sites: " . $e->getMessage());
    $contact_info = [
        ['id' => 1, 'nom' => 'ISGI Brazzaville', 'ville' => 'Brazzaville', 'adresse' => 'Quartier Poto-Poto, Avenue de France', 'telephone' => '+242 06 848 45 67'],
        ['id' => 2, 'nom' => 'ISGI Pointe-Noire', 'ville' => 'Pointe-Noire', 'adresse' => 'Quartier Mpita-Socoprise, Arrêt OCI', 'telephone' => '+242 06 848 45 68'],
        ['id' => 3, 'nom' => 'ISGI Ouesso', 'ville' => 'Ouesso', 'adresse' => 'Centre-ville, en diagonale de la CNSS', 'telephone' => '+242 06 848 45 69']
    ];
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage des données
    $nom = trim($_POST['nom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sujet = trim($_POST['sujet'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $type_contact = $_POST['type_contact'] ?? 'general';
    $site_id = intval($_POST['site_id'] ?? 1);

    // Validation
    if (empty($nom)) {
        $errors['nom'] = 'Le nom est obligatoire';
    } elseif (strlen($nom) < 2) {
        $errors['nom'] = 'Le nom doit contenir au moins 2 caractères';
    } elseif (strlen($nom) > 100) {
        $errors['nom'] = 'Le nom ne doit pas dépasser 100 caractères';
    }

    if (empty($email)) {
        $errors['email'] = 'L\'email est obligatoire';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez entrer une adresse email valide';
    } elseif (strlen($email) > 150) {
        $errors['email'] = 'L\'email ne doit pas dépasser 150 caractères';
    }

    if (!empty($telephone) && !preg_match('/^[\+\d\s\-\(\)]{8,20}$/', $telephone)) {
        $errors['telephone'] = 'Format de téléphone invalide';
    }

    if (empty($sujet)) {
        $errors['sujet'] = 'Le sujet est obligatoire';
    } elseif (strlen($sujet) < 3) {
        $errors['sujet'] = 'Le sujet doit contenir au moins 3 caractères';
    } elseif (strlen($sujet) > 200) {
        $errors['sujet'] = 'Le sujet ne doit pas dépasser 200 caractères';
    }

    if (empty($message)) {
        $errors['message'] = 'Le message est obligatoire';
    } elseif (strlen($message) < 10) {
        $errors['message'] = 'Le message doit contenir au moins 10 caractères';
    } elseif (strlen($message) > 5000) {
        $errors['message'] = 'Le message ne doit pas dépasser 5000 caractères';
    }

    // Vérifier si le site existe
    $site_exists = false;
    foreach ($sites as $site) {
        if ($site['id'] == $site_id) {
            $site_exists = true;
            break;
        }
    }
    
    if (!$site_exists) {
        $site_id = 1; // Valeur par défaut
    }

    // Si aucune erreur, traiter le formulaire
    if (empty($errors)) {
        try {
            // Enregistrement dans la base de données
            // Note: ip_address et user_agent ne sont pas dans votre table, donc on les retire
            $stmt = $db->prepare("
                INSERT INTO contact_messages 
                (nom, email, telephone, sujet, message, type_contact, site_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $nom, 
                $email, 
                $telephone ?: NULL, 
                $sujet, 
                $message, 
                $type_contact,
                $site_id
            ]);
            
            $message_id = $db->lastInsertId();
            
            // Réinitialiser le formulaire
            $nom = $email = $sujet = $message = $telephone = '';
            $type_contact = 'general';
            $site_id = 1;
            $success = true;
            
            // Log dans les logs_activite si la table existe
            try {
                $log_stmt = $db->prepare("
                    INSERT INTO logs_activite 
                    (utilisateur_id, utilisateur_type, action, table_concernée, id_enregistrement, details) 
                    VALUES (NULL, 'visiteur', 'message_contact', 'contact_messages', ?, ?)
                ");
                
                $details = "Nouveau message de contact: $nom ($email) - $sujet";
                $log_stmt->execute([$message_id, $details]);
            } catch (PDOException $log_error) {
                // Ne pas bloquer si les logs échouent
                error_log("Erreur log contact: " . $log_error->getMessage());
            }
            
        } catch (PDOException $e) {
            // Afficher l'erreur exacte pour le débogage
            $error_message = $e->getMessage();
            error_log("Erreur enregistrement contact: " . $error_message);
            
            // Message utilisateur plus générique
            $errors['global'] = 'Une erreur est survenue lors de l\'enregistrement. Veuillez réessayer.';
            
            // Pour le débogage, vous pouvez afficher l'erreur SQL (à enlever en production)
            // $errors['global'] .= " Erreur technique: " . htmlspecialchars($error_message);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact - ISGI</title>
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
            max-width: 1400px;
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
            font-size: 28px;
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
            padding: 8px 0;
            position: relative;
        }
        
        .nav-links a:hover {
            color: var(--secondary-color);
        }
        
        .nav-links a.active {
            color: var(--secondary-color);
            font-weight: 600;
        }
        
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--secondary-color);
            transition: width 0.3s;
        }
        
        .nav-links a:hover::after,
        .nav-links a.active::after {
            width: 100%;
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background-color: var(--light-color);
            color: var(--dark-color);
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background-color: #d5dbdb;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-large {
            padding: 15px 40px;
            font-size: 1.1rem;
        }
        
        /* Hero Section Contact */
        .hero-contact {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 100px 0 60px;
            text-align: center;
        }
        
        .hero-contact h1 {
            font-size: 3rem;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .hero-contact p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto;
            opacity: 0.9;
            line-height: 1.6;
        }
        
        /* Main Content */
        .contact-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            padding: 80px 0;
        }
        
        @media (max-width: 992px) {
            .contact-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Contact Form */
        .contact-form-container {
            background: white;
            padding: 50px 40px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .contact-form-container h2 {
            color: var(--primary-color);
            font-size: 2.2rem;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--secondary-color);
            display: inline-block;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 1.05rem;
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: #f8f9fa;
        }
        
        .form-control.error {
            border-color: var(--accent-color);
            background-color: #fff5f5;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        textarea.form-control {
            min-height: 180px;
            resize: vertical;
        }
        
        .error-message {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-top: 5px;
            display: block;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
            border-left: 4px solid var(--success-color);
        }
        
        .success-message i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
            color: var(--success-color);
        }
        
        /* Contact Info */
        .contact-info-container {
            background: white;
            padding: 50px 40px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }
        
        .contact-info-container h2 {
            color: var(--primary-color);
            font-size: 2.2rem;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--secondary-color);
            display: inline-block;
        }
        
        .contact-method {
            display: flex;
            align-items: flex-start;
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eee;
        }
        
        .contact-method:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .contact-icon {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            flex-shrink: 0;
        }
        
        .contact-icon i {
            font-size: 1.5rem;
        }
        
        .contact-details h4 {
            color: var(--primary-color);
            font-size: 1.3rem;
            margin-bottom: 8px;
        }
        
        .contact-details p {
            color: #666;
            line-height: 1.7;
            margin-bottom: 5px;
        }
        
        .contact-details a {
            color: var(--secondary-color);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .contact-details a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }
        
        /* Campus List */
        .campus-list {
            margin-top: 40px;
        }
        
        .campus-item {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .campus-item:hover {
            background: #f1f3f4;
            transform: translateX(5px);
        }
        
        .campus-item h4 {
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .campus-item p {
            color: #666;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .campus-item i {
            color: var(--secondary-color);
            margin-right: 8px;
            width: 20px;
        }
        
        /* FAQ Section */
        .faq-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .faq-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .faq-title {
            text-align: center;
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 50px;
            position: relative;
        }
        
        .faq-title::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: var(--secondary-color);
        }
        
        .faq-item {
            background: white;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .faq-question {
            padding: 25px 30px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .faq-question:hover {
            background: #f8f9fa;
        }
        
        .faq-question.active {
            border-bottom-color: var(--secondary-color);
        }
        
        .faq-question i {
            color: var(--secondary-color);
            transition: transform 0.3s;
        }
        
        .faq-question.active i {
            transform: rotate(180deg);
        }
        
        .faq-answer {
            padding: 0 30px;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .faq-answer.active {
            padding: 25px 30px;
            max-height: 500px;
        }
        
        .faq-answer p {
            color: #666;
            line-height: 1.7;
            font-size: 1.05rem;
        }
        
        /* Footer */
        footer {
            background-color: var(--primary-color);
            color: white;
            padding: 80px 0 30px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 50px;
        }
        
        .footer-section h3 {
            font-size: 1.5rem;
            margin-bottom: 25px;
            color: white;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-section h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--secondary-color);
        }
        
        .footer-section p {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            line-height: 1.7;
        }
        
        .footer-section i {
            color: var(--secondary-color);
            margin-right: 10px;
            width: 20px;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 15px;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .footer-links a:hover {
            color: var(--secondary-color);
            transform: translateX(5px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
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
                gap: 15px;
            }
            
            .hero-contact h1 {
                font-size: 2.2rem;
            }
            
            .hero-contact p {
                font-size: 1.1rem;
                padding: 0 20px;
            }
            
            .contact-form-container,
            .contact-info-container {
                padding: 30px 25px;
            }
            
            .contact-container {
                gap: 40px;
            }
            
            .faq-title {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 480px) {
            .contact-method {
                flex-direction: column;
                text-align: center;
            }
            
            .contact-icon {
                margin-right: 0;
                margin-bottom: 15px;
            }
            
            .btn-large {
                padding: 12px 30px;
            }
            
            .hero-contact h1 {
                font-size: 1.8rem;
            }
            
            .contact-form-container h2,
            .contact-info-container h2 {
                font-size: 1.8rem;
            }
        }
        
        /* Animation */
        .fade-in {
            opacity: 0;
            transform: translateY(20px);
            animation: fadeIn 0.5s ease forwards;
        }
        
        @keyframes fadeIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
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
            
            <nav class="nav-links">
                <a href="index.php">Accueil</a>
                <a href="inscription.php">Inscription</a>
                <a href="reinscription.php">Réinscription</a>
                <a href="apropos.php">À propos de nous</a>
                <a href="contact.php" class="active">Nous contacter</a>
            </nav>
            
            <div class="auth-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='auth/login.php'">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
                <button class="btn btn-primary" onclick="window.location.href='register_student_tutor.php'">
                    <i class="fas fa-user-plus"></i> Créer un compte
                </button>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-contact">
        <div class="container">
            <h1 class="fade-in">Contactez-nous</h1>
            <p class="fade-in delay-1">Nous sommes là pour répondre à vos questions. Que ce soit pour des informations sur les inscriptions, les programmes ou toute autre demande, notre équipe est à votre écoute.</p>
        </div>
    </section>

    <!-- Main Content -->
    <section class="container contact-container">
        <!-- Contact Form -->
        <div class="contact-form-container fade-in">
            <h2>Envoyez-nous un message</h2>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <h3 style="color: #155724; margin-bottom: 10px;">Message envoyé avec succès !</h3>
                    <p>Nous avons bien reçu votre message et nous vous répondrons dans les plus brefs délais.</p>
                    <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                        Vous pouvez également nous contacter directement par téléphone pour une réponse plus rapide.
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($errors['global'])): ?>
                <div style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--accent-color);">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['global']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="contact.php" id="contactForm">
                <div class="form-group">
                    <label for="nom">Nom complet *</label>
                    <input type="text" 
                           id="nom" 
                           name="nom" 
                           class="form-control <?php echo isset($errors['nom']) ? 'error' : ''; ?>" 
                           value="<?php echo htmlspecialchars($nom); ?>"
                           placeholder="Votre nom complet"
                           maxlength="100"
                           required>
                    <?php if (isset($errors['nom'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['nom']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="email">Adresse email *</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-control <?php echo isset($errors['email']) ? 'error' : ''; ?>" 
                           value="<?php echo htmlspecialchars($email); ?>"
                           placeholder="votre@email.com"
                           maxlength="150"
                           required>
                    <?php if (isset($errors['email'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['email']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="telephone">Numéro de téléphone</label>
                    <input type="tel" 
                           id="telephone" 
                           name="telephone" 
                           class="form-control <?php echo isset($errors['telephone']) ? 'error' : ''; ?>" 
                           value="<?php echo htmlspecialchars($telephone); ?>"
                           placeholder="+242 XX XX XX XX"
                           maxlength="20">
                    <?php if (isset($errors['telephone'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['telephone']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="site_id">Campus concerné *</label>
                    <select id="site_id" name="site_id" class="form-control" required>
                        <option value="">Sélectionnez un campus</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>" 
                                <?php echo ($site_id == $site['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="type_contact">Type de demande *</label>
                    <select id="type_contact" name="type_contact" class="form-control" required>
                        <option value="">Sélectionnez un type</option>
                        <option value="general" <?php echo $type_contact == 'general' ? 'selected' : ''; ?>>Demande générale</option>
                        <option value="inscription" <?php echo $type_contact == 'inscription' ? 'selected' : ''; ?>>Inscription</option>
                        <option value="reinscription" <?php echo $type_contact == 'reinscription' ? 'selected' : ''; ?>>Réinscription</option>
                        <option value="information" <?php echo $type_contact == 'information' ? 'selected' : ''; ?>>Informations sur les formations</option>
                        <option value="partenariat" <?php echo $type_contact == 'partenariat' ? 'selected' : ''; ?>>Partenariat</option>
                        <option value="autre" <?php echo $type_contact == 'autre' ? 'selected' : ''; ?>>Autre</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="sujet">Sujet *</label>
                    <input type="text" 
                           id="sujet" 
                           name="sujet" 
                           class="form-control <?php echo isset($errors['sujet']) ? 'error' : ''; ?>" 
                           value="<?php echo htmlspecialchars($sujet); ?>"
                           placeholder="Objet de votre message"
                           maxlength="200"
                           required>
                    <?php if (isset($errors['sujet'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['sujet']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="message">Message *</label>
                    <textarea id="message" 
                              name="message" 
                              class="form-control <?php echo isset($errors['message']) ? 'error' : ''; ?>"
                              placeholder="Votre message... (minimum 10 caractères)"
                              rows="6"
                              maxlength="5000"
                              required><?php echo htmlspecialchars($message); ?></textarea>
                    <?php if (isset($errors['message'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['message']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group" style="text-align: center; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary btn-large">
                        <i class="fas fa-paper-plane"></i> Envoyer le message
                    </button>
                </div>
                
                <div style="text-align: center; margin-top: 20px; color: #666; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Les champs marqués d'un * sont obligatoires
                </div>
            </form>
        </div>
        
        <!-- Contact Information -->
        <div class="contact-info-container fade-in delay-2">
            <h2>Nos coordonnées</h2>
            
            <div class="contact-method">
                <div class="contact-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div class="contact-details">
                    <h4>Adresse principale</h4>
                    <p>Institut Supérieur de Gestion et d'Ingénierie</p>
                    <p>Brazzaville, République du Congo</p>
                </div>
            </div>
            
            <div class="contact-method">
                <div class="contact-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="contact-details">
                    <h4>Téléphone</h4>
                    <p><a href="tel:+242068484567">+242 06 848 45 67</a> (Brazzaville)</p>
                    <p><a href="tel:+242068484568">+242 06 848 45 68</a> (Pointe-Noire)</p>
                    <p>Lundi - Vendredi: 8h00 - 17h00</p>
                </div>
            </div>
            
            <div class="contact-method">
                <div class="contact-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="contact-details">
                    <h4>Email</h4>
                    <p><a href="mailto:contact@isgi.cg">contact@isgi.cg</a></p>
                    <p><a href="mailto:inscription@isgi.cg">inscription@isgi.cg</a> (Inscriptions)</p>
                </div>
            </div>
            
            <div class="contact-method">
                <div class="contact-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="contact-details">
                    <h4>Horaires d'ouverture</h4>
                    <p><strong>Bureaux administratifs:</strong></p>
                    <p>Lundi - Vendredi: 8h00 - 17h00</p>
                    <p>Samedi: 8h00 - 12h00</p>
                    <p>Dimanche: Fermé</p>
                </div>
            </div>
            
            <div class="campus-list fade-in delay-3">
                <h3 style="color: var(--primary-color); margin-bottom: 25px; font-size: 1.5rem;">Nos Campus</h3>
                
                <?php foreach ($contact_info as $campus): ?>
                    <div class="campus-item">
                        <h4><?php echo htmlspecialchars($campus['nom']); ?></h4>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($campus['adresse']); ?></p>
                        <p><i class="fas fa-city"></i> <?php echo htmlspecialchars($campus['ville']); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($campus['telephone']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section">
        <div class="container faq-container">
            <h2 class="faq-title fade-in">Questions Fréquentes</h2>
            
            <div class="faq-item fade-in delay-1">
                <div class="faq-question">
                    Comment puis-je m'inscrire à l'ISGI ?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>L'inscription se fait en ligne via notre plateforme. Rendez-vous sur la page <a href="inscription.php" style="color: var(--secondary-color); text-decoration: underline;">Inscription</a>, remplissez le formulaire et téléchargez les documents requis. Vous recevrez un email de confirmation avec les instructions pour finaliser votre inscription.</p>
                    <p style="margin-top: 10px;"><strong>Documents requis :</strong> Acte de naissance, photocopie du diplôme, photos d'identité, relevé de notes, et photocopie de la CNI.</p>
                </div>
            </div>
            
            <div class="faq-item fade-in delay-2">
                <div class="faq-question">
                    En combien de temps recevrai-je une réponse à mon message ?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Nous nous engageons à répondre à tous les messages dans un délai maximum de <strong>48 heures ouvrables</strong>. Pour les demandes urgentes, nous vous recommandons de nous contacter par téléphone.</p>
                    <p style="margin-top: 10px;">Vous recevrez un accusé de réception par email dès que votre message sera enregistré dans notre système.</p>
                </div>
            </div>
            
            <div class="faq-item fade-in delay-3">
                <div class="faq-question">
                    Quels sont les frais de scolarité ?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Les frais de scolarité varient selon la filière, le niveau d'études et le campus. Pour obtenir des informations détaillées et actualisées sur les tarifs, veuillez nous contacter directement ou visiter le campus de votre choix.</p>
                    <p style="margin-top: 10px;">Nous proposons également des facilités de paiement et des bourses d'excellence pour les étudiants méritants.</p>
                </div>
            </div>
            
            <div class="faq-item fade-in delay-4">
                <div class="faq-question">
                    L'ISGI propose-t-il des formations en ligne ?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Oui, l'ISGI propose des formations hybrides et certaines formations entièrement en ligne. Nos cours en ligne sont accessibles via notre plateforme e-learning et comprennent :</p>
                    <ul style="margin-left: 20px; margin-top: 10px; color: #666;">
                        <li>Vidéos de cours enregistrées</li>
                        <li>Supports pédagogiques numériques</li>
                        <li>Sessions interactives avec les enseignants</li>
                        <li>Évaluations en ligne</li>
                        <li>Bibliothèque virtuelle</li>
                    </ul>
                </div>
            </div>
            
            <div class="faq-item fade-in delay-5">
                <div class="faq-question">
                    Comment accéder à la bibliothèque virtuelle ?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>La bibliothèque virtuelle est accessible depuis la page <a href="bibliotheque.php" style="color: var(--secondary-color); text-decoration: underline;">Bibliothèque</a>. Les étudiants inscrits reçoivent des identifiants pour accéder à toutes les ressources. Les visiteurs peuvent consulter une sélection limitée des ressources disponibles.</p>
                    <p style="margin-top: 10px;"><strong>Ressources disponibles :</strong> Livres numériques, articles scientifiques, thèses, vidéos éducatives, et supports de cours.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>ISGI</h3>
                    <p>Institut Supérieur de Gestion et d'Ingénierie, formant les leaders de demain.</p>
                    <div style="margin-top: 15px;">
                        <div><i class="fas fa-map-marker-alt"></i> Brazzaville, Congo</div>
                        <div><i class="fas fa-phone"></i> +242 06 848 45 67</div>
                        <div><i class="fas fa-envelope"></i> contact@isgi.cg</div>
                    </div>
                </div>
                
                <div class="footer-section">
                    <h3>Liens rapides</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="inscription.php">Inscription</a></li>
                        <li><a href="reinscription.php">Réinscription</a></li>
                        <li><a href="bibliotheque.php">Bibliothèque</a></li>
                        <li><a href="apropos.php">À propos de nous</a></li>
                        <li><a href="contact.php">Nous contacter</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Domaines</h3>
                    <ul class="footer-links">
                        <li><a href="#">Technologies</a></li>
                        <li><a href="#">Gestion</a></li>
                        <li><a href="#">Droit</a></li>
                        <li><a href="#">Industrie</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>

    <script>
        // FAQ Accordion
        document.addEventListener('DOMContentLoaded', function() {
            // Gestion de l'accordéon FAQ
            const faqQuestions = document.querySelectorAll('.faq-question');
            
            faqQuestions.forEach(question => {
                question.addEventListener('click', () => {
                    const answer = question.nextElementSibling;
                    const isActive = question.classList.contains('active');
                    
                    // Fermer toutes les réponses
                    document.querySelectorAll('.faq-question').forEach(q => {
                        q.classList.remove('active');
                    });
                    document.querySelectorAll('.faq-answer').forEach(a => {
                        a.classList.remove('active');
                    });
                    
                    // Ouvrir la réponse cliquée si elle n'était pas active
                    if (!isActive) {
                        question.classList.add('active');
                        answer.classList.add('active');
                    }
                });
            });
            
            // Animation au défilement
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observer les éléments avec animation
            document.querySelectorAll('.fade-in').forEach(element => {
                if (!element.classList.contains('fade-in-processed')) {
                    element.classList.add('fade-in-processed');
                    observer.observe(element);
                }
            });
            
            // Validation du formulaire
            const form = document.getElementById('contactForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    let valid = true;
                    const requiredFields = form.querySelectorAll('[required]');
                    
                    requiredFields.forEach(field => {
                        field.style.borderColor = '#e1e5e9';
                        const errorSpan = field.nextElementSibling;
                        
                        if (!field.value.trim()) {
                            valid = false;
                            field.style.borderColor = '#e74c3c';
                            if (errorSpan && errorSpan.classList.contains('error-message')) {
                                errorSpan.textContent = 'Ce champ est obligatoire';
                            }
                        }
                    });
                    
                    // Validation spécifique pour le message
                    const messageField = document.getElementById('message');
                    if (messageField && messageField.value.trim().length < 10) {
                        valid = false;
                        messageField.style.borderColor = '#e74c3c';
                        const errorSpan = messageField.nextElementSibling;
                        if (errorSpan && errorSpan.classList.contains('error-message')) {
                            errorSpan.textContent = 'Le message doit contenir au moins 10 caractères';
                        }
                    }
                    
                    if (!valid) {
                        e.preventDefault();
                        // Scroll vers le premier champ en erreur
                        const firstError = form.querySelector('.error');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstError.focus();
                        }
                    }
                });
            }
            
            // Animation du logo au chargement
            const logoText = document.querySelector('.logo-text');
            if (logoText) {
                setTimeout(() => {
                    logoText.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        logoText.style.transform = 'scale(1)';
                    }, 300);
                }, 500);
            }
            
            // Compteur de caractères pour le message
            const messageField = document.getElementById('message');
            if (messageField) {
                const counter = document.createElement('div');
                counter.style.textAlign = 'right';
                counter.style.fontSize = '0.85rem';
                counter.style.color = '#666';
                counter.style.marginTop = '5px';
                counter.innerHTML = '<span id="charCount">0</span>/5000 caractères';
                messageField.parentNode.appendChild(counter);
                
                messageField.addEventListener('input', function() {
                    const count = this.value.length;
                    document.getElementById('charCount').textContent = count;
                    
                    if (count > 4500) {
                        counter.style.color = '#f39c12';
                    } else if (count > 4800) {
                        counter.style.color = '#e74c3c';
                    } else {
                        counter.style.color = '#666';
                    }
                });
                
                // Initialiser le compteur
                document.getElementById('charCount').textContent = messageField.value.length;
            }
        });
    </script>
</body>
</html>