<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Création de Compte</title>
    
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
        
        /* Role Select Styling */
        .role-select-container {
            position: relative;
        }
        
        .role-select-wrapper {
            border: 2px solid var(--medium-gray);
            border-radius: var(--radius-sm);
            background: white;
            transition: all 0.3s;
        }
        
        .role-select-wrapper:focus-within {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        .role-select {
            width: 100%;
            padding: 12px 16px;
            border: none;
            background: transparent;
            font-size: 15px;
            color: var(--text-primary);
            cursor: pointer;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            height: 48px;
        }
        
        .role-select-arrow {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--primary-blue);
        }
        
        /* Site Select Styling */
        .site-select-container {
            position: relative;
        }
        
        .site-select-wrapper {
            border: 2px solid var(--medium-gray);
            border-radius: var(--radius-sm);
            background: white;
            transition: all 0.3s;
        }
        
        .site-select-wrapper:focus-within {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        .site-select {
            width: 100%;
            padding: 12px 16px;
            border: none;
            background: transparent;
            font-size: 15px;
            color: var(--text-primary);
            cursor: pointer;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            height: 48px;
        }
        
        /* Role Preview */
        .role-preview {
            margin-top: 15px;
            padding: 15px;
            border-radius: var(--radius-sm);
            background: #f8fbff;
            border: 1px solid #e1f0ff;
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .role-preview.show {
            display: block;
        }
        
        .role-preview-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .role-icon-preview {
            color: var(--primary-blue);
            font-size: 18px;
        }
        
        .role-preview-header h5 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
            color: var(--text-primary);
        }
        
        .role-description {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0;
            line-height: 1.5;
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <header class="main-header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="logo-text">
                    <h1>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <p>Création de compte utilisateur</p>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="auth-main-container">
            <!-- Left Information Panel -->
            <aside class="info-panel">
                <div class="panel-header">
                    <h2>Création de Compte</h2>
                    <p>Créez votre compte personnel pour accéder à la plateforme ISGI</p>
                </div>
                
                <div class="security-features">
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Sécurité des Données</h4>
                            <p>Vos informations personnelles sont cryptées et protégées</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Rôles Personnalisés</h4>
                            <p>10 rôles différents avec des permissions adaptées</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Multi-Sites</h4>
                            <p>Accès aux sites de Brazzaville, Pointe-Noire et Ouesso</p>
                        </div>
                    </div>
                    
                    <div class="security-feature">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Support Technique</h4>
                            <p>Assistance disponible pour toute question</p>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: auto; padding-top: 20px; border-top: 1px solid var(--medium-gray);">
                    <div class="alert alert-info" style="margin: 0; padding: 15px;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Information :</strong> Votre compte doit être validé par un administrateur avant activation.
                        </div>
                    </div>
                </div>
            </aside>
            
            <!-- Right Form Panel -->
            <section class="form-panel">
                <div class="form-container">
                    <!-- Step Indicator -->
                    <div class="step-indicator" id="stepIndicator">
                        <div class="step active" id="step1">1</div>
                        <div class="step" id="step2">2</div>
                    </div>
                    
                    <!-- Alerts Container -->
                    <div class="alert-container" id="alertContainer"></div>
                    
                    <!-- Dynamic Content -->
                    <div id="contentContainer">
                        <!-- Content will be loaded here -->
                    </div>
                    
                    <!-- Footer -->
                    <div class="form-footer" id="formFooter">
                        <!-- Footer links will be loaded here -->
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Application JavaScript -->
    <script>
        // ==================== APPLICATION CONFIGURATION ====================
        const APP_CONFIG = {
            name: "ISGI",
            baseUrl: window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '')
        };
        
        // Rôles Configuration basée sur votre base de données
        const ROLES = {
            1: {
                label: "Administrateur Principal",
                description: "Accès complet à tous les sites et toutes les fonctionnalités",
                icon: "fa-user-shield",
                color: "#8e44ad",
                requiresApproval: true
            },
            2: {
                label: "Administrateur Site",
                description: "Gestion complète d'un site spécifique",
                icon: "fa-user-tie",
                color: "#16a085",
                requiresApproval: true
            },
            3: {
                label: "Gestionnaire Principal",
                description: "Gestion financière et inscriptions - Principal",
                icon: "fa-chart-line",
                color: "#3498db",
                requiresApproval: true
            },
            4: {
                label: "Gestionnaire Secondaire",
                description: "Gestion financière et inscriptions - Secondaire",
                icon: "fa-chart-bar",
                color: "#95a5a6",
                requiresApproval: true
            },
            5: {
                label: "DAC",
                description: "Directeur des Affaires Académiques",
                icon: "fa-chalkboard-teacher",
                color: "#e67e22",
                requiresApproval: true
            },
            6: {
                label: "Surveillant Général",
                description: "Gestion des présences et discipline",
                icon: "fa-clipboard-check",
                color: "#2980b9",
                requiresApproval: true
            },
            7: {
                label: "Professeur",
                description: "Enseignant - Saisie notes et présences",
                icon: "fa-chalkboard",
                color: "#27ae60",
                requiresApproval: true
            },
            8: {
                label: "Étudiant",
                description: "Accès étudiant - Consultation",
                icon: "fa-user-graduate",
                color: "#2c3e50",
                requiresApproval: false
            },
            9: {
                label: "Tuteur",
                description: "Parent/Tuteur - Suivi étudiant",
                icon: "fa-hands-helping",
                color: "#f1c40f",
                requiresApproval: false
            }
        };
        
        // Sites Configuration basée sur votre base de données
        const SITES = {
            1: {
                nom: "ISGI Brazzaville",
                ville: "Brazzaville"
            },
            2: {
                nom: "ISGI Pointe-Noire",
                ville: "Pointe-Noire"
            },
            3: {
                nom: "ISGI Ouesso",
                ville: "Ouesso"
            }
        };
        
        // ==================== APPLICATION STATE ====================
        let appState = {
            currentStep: "step1",
            formData: {
                role_id: "",
                site_id: "",
                email: "",
                mot_de_passe: "",
                confirm_password: "",
                nom: "",
                prenom: "",
                telephone: ""
            }
        };
        
        // ==================== INITIALIZATION ====================
        document.addEventListener("DOMContentLoaded", function() {
            showStep1();
        });
        
        // ==================== UI UTILITIES ====================
        function showAlert(message, type = "info") {
            const alertContainer = document.getElementById("alertContainer");
            const icons = {
                success: "fa-check-circle",
                danger: "fa-exclamation-circle",
                warning: "fa-exclamation-triangle",
                info: "fa-info-circle"
            };
            
            const alertHTML = `
                <div class="alert alert-${type}">
                    <i class="fas ${icons[type] || icons.info}"></i>
                    <div>${message}</div>
                </div>
            `;
            
            alertContainer.innerHTML = alertHTML;
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertContainer.firstChild) {
                    alertContainer.firstChild.remove();
                }
            }, 5000);
        }
        
        function clearAlerts() {
            document.getElementById("alertContainer").innerHTML = "";
        }
        
        // ==================== FORM VALIDATION ====================
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        function validatePhone(phone) {
            // Validation simple pour les numéros de téléphone
            const re = /^[+\d\s\-\(\)]{8,20}$/;
            return re.test(phone);
        }
        
        function validatePassword(password) {
            // Au moins 8 caractères, une majuscule, une minuscule, un chiffre
            const re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
            return re.test(password);
        }
        
        // ==================== STEP MANAGEMENT ====================
        function showStep1() {
            appState.currentStep = "step1";
            clearAlerts();
            
            // Mettre à jour l'indicateur d'étape
            document.getElementById("step1").className = "step active";
            document.getElementById("step2").className = "step";
            
            // Générer les options de rôle
            const roleOptions = Object.entries(ROLES).map(([id, role]) => 
                `<option value="${id}">${role.label}</option>`
            ).join("");
            
            // Générer les options de site
            const siteOptions = Object.entries(SITES).map(([id, site]) => 
                `<option value="${id}">${site.nom} (${site.ville})</option>`
            ).join("");
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-title">
                    <h2><i class="fas fa-user-plus"></i> Informations Personnelles</h2>
                    <p>Étape 1 : Rôle et informations de base</p>
                </div>
                
                <div class="form-card">
                    <form id="step1Form" onsubmit="handleStep1(event)">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-tag"></i> Type de compte
                            </label>
                            <div class="role-select-container">
                                <div class="role-select-wrapper">
                                    <select class="role-select" id="role_id" onchange="updateRolePreview(this.value)" required>
                                        <option value="">-- Choisissez votre rôle --</option>
                                        ${roleOptions}
                                    </select>
                                    <div class="role-select-arrow">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="role-preview" id="rolePreview"></div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Site d'affectation
                            </label>
                            <div class="site-select-container">
                                <div class="site-select-wrapper">
                                    <select class="site-select" id="site_id" required>
                                        <option value="">-- Choisissez un site --</option>
                                        ${siteOptions}
                                    </select>
                                    <div class="role-select-arrow">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 5px;">
                                Sélectionnez le site où vous serez affecté
                            </small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-user"></i> Nom
                                    </label>
                                    <input type="text" class="form-control" id="nom" 
                                           placeholder="Votre nom de famille" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-user"></i> Prénom
                                    </label>
                                    <input type="text" class="form-control" id="prenom" 
                                           placeholder="Votre prénom" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Adresse email
                            </label>
                            <input type="email" class="form-control" id="email" 
                                   placeholder="votre.email@exemple.com" required>
                            <small class="text-muted" style="display: block; margin-top: 5px;">
                                Cette adresse servira pour la connexion
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right"></i> Suivant
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
            document.getElementById("formFooter").innerHTML = `
                <div class="form-footer-links">
                    <a href="#" onclick="window.history.back()">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                    <a href="#" onclick="showAlert('Pour toute assistance, contactez le support technique.', 'info')">
                        <i class="fas fa-question-circle"></i> Aide
                    </a>
                </div>
            `;
            
            // Initialiser la prévisualisation du rôle si déjà sélectionné
            if (appState.formData.role_id) {
                updateRolePreview(appState.formData.role_id);
            }
            
            // Remplir les champs avec les données existantes
            if (appState.formData.site_id) {
                document.getElementById('site_id').value = appState.formData.site_id;
            }
            if (appState.formData.nom) {
                document.getElementById('nom').value = appState.formData.nom;
            }
            if (appState.formData.prenom) {
                document.getElementById('prenom').value = appState.formData.prenom;
            }
            if (appState.formData.email) {
                document.getElementById('email').value = appState.formData.email;
            }
        }
        
        function updateRolePreview(roleId) {
            const rolePreview = document.getElementById("rolePreview");
            
            if (!roleId || !ROLES[roleId]) {
                rolePreview.classList.remove("show");
                return;
            }
            
            const role = ROLES[roleId];
            appState.formData.role_id = roleId;
            
            rolePreview.innerHTML = `
                <div class="role-preview-header">
                    <i class="fas ${role.icon} role-icon-preview"></i>
                    <h5>${role.label}</h5>
                </div>
                <p class="role-description">${role.description}</p>
                ${role.requiresApproval ? 
                    '<div class="alert alert-warning" style="margin-top: 10px; padding: 8px 12px; font-size: 12px;">' +
                    '<i class="fas fa-exclamation-triangle"></i> ' +
                    'Ce rôle nécessite une validation par un administrateur' +
                    '</div>' : 
                    ''
                }
            `;
            
            rolePreview.style.borderLeftColor = role.color;
            rolePreview.classList.add("show");
        }
        
        function showStep2() {
            appState.currentStep = "step2";
            clearAlerts();
            
            // Mettre à jour l'indicateur d'étape
            document.getElementById("step1").className = "step completed";
            document.getElementById("step2").className = "step active";
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-title">
                    <h2><i class="fas fa-lock"></i> Sécurité du compte</h2>
                    <p>Étape 2 : Créez votre mot de passe sécurisé</p>
                </div>
                
                <div class="form-card">
                    <form id="step2Form" onsubmit="handleStep2(event)">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-phone"></i> Téléphone
                                    </label>
                                    <input type="tel" class="form-control" id="telephone" 
                                           placeholder="+242 XX XXX XXX" value="${appState.formData.telephone || ''}">
                                    <small class="text-muted" style="display: block; margin-top: 5px;">
                                        Facultatif mais recommandé
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-lock"></i> Mot de passe
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="mot_de_passe" 
                                               placeholder="Minimum 8 caractères" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('mot_de_passe')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" style="display: block; margin-top: 5px;">
                                        Doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre
                                    </small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-lock"></i> Confirmer le mot de passe
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="confirm_password" 
                                               placeholder="Répétez votre mot de passe" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info" style="margin: 20px 0;">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Sécurité :</strong> Votre mot de passe sera crypté de manière sécurisée. 
                                Nous ne stockons jamais les mots de passe en clair.
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-6">
                                <button type="button" class="btn btn-outline" onclick="showStep1()">
                                    <i class="fas fa-arrow-left"></i> Retour
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus"></i> Créer le compte
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            `;
            
            document.getElementById("formFooter").innerHTML = `
                <div class="form-footer-links">
                    <a href="#" onclick="showStep1()">
                        <i class="fas fa-edit"></i> Modifier les informations
                    </a>
                </div>
            `;
        }
        
        function showSuccessScreen() {
            clearAlerts();
            
            const role = ROLES[appState.formData.role_id];
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-title">
                    <h2><i class="fas fa-check-circle" style="color: var(--success-green);"></i> Compte créé avec succès !</h2>
                    <p>Votre demande a été enregistrée</p>
                </div>
                
                <div class="form-card">
                    <div style="text-align: center; padding: 20px;">
                        <div style="font-size: 64px; color: var(--success-green); margin-bottom: 20px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        
                        <h4 style="margin-bottom: 15px;">Félicitations !</h4>
                        <p style="color: #666; margin-bottom: 25px;">
                            Votre demande de création de compte a été enregistrée avec succès.
                        </p>
                        
                        <div class="alert alert-info" style="text-align: left; margin: 25px 0;">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Récapitulatif :</strong><br>
                                <strong>Nom :</strong> ${appState.formData.nom} ${appState.formData.prenom}<br>
                                <strong>Email :</strong> ${appState.formData.email}<br>
                                <strong>Rôle :</strong> ${role.label}<br>
                                <strong>Site :</strong> ${SITES[appState.formData.site_id].nom}
                            </div>
                        </div>
                        
                        ${role.requiresApproval ? 
                            `<div class="alert alert-warning" style="text-align: left;">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>En attente de validation :</strong><br>
                                    Votre compte nécessite une validation par un administrateur. 
                                    Vous recevrez un email de confirmation une fois votre compte activé.
                                </div>
                            </div>` : 
                            `<div class="alert alert-success" style="text-align: left;">
                                <i class="fas fa-envelope"></i>
                                <div>
                                    <strong>Compte activé :</strong><br>
                                    Votre compte a été créé avec succès. Vous pouvez maintenant vous connecter.
                                </div>
                            </div>`
                        }
                        
                        <div style="margin-top: 30px;">
                            <button type="button" class="btn btn-primary" onclick="resetForm()" style="width: auto; padding: 12px 30px;">
                                <i class="fas fa-user-plus"></i> Créer un autre compte
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById("formFooter").innerHTML = `
                <div class="form-footer-links">
                    <a href="login.html">
                        <i class="fas fa-sign-in-alt"></i> Page de connexion
                    </a>
                    <a href="#" onclick="window.history.back()">
                        <i class="fas fa-home"></i> Page d'accueil
                    </a>
                </div>
            `;
        }
        
        // ==================== FORM HANDLERS ====================
        function handleStep1(event) {
            event.preventDefault();
            
            const role_id = document.getElementById("role_id").value;
            const site_id = document.getElementById("site_id").value;
            const nom = document.getElementById("nom").value.trim();
            const prenom = document.getElementById("prenom").value.trim();
            const email = document.getElementById("email").value.trim();
            
            // Validation
            if (!role_id || !site_id || !nom || !prenom || !email) {
                showAlert("Veuillez remplir tous les champs obligatoires", "danger");
                return;
            }
            
            if (!validateEmail(email)) {
                showAlert("Adresse email invalide", "danger");
                return;
            }
            
            // Stocker les données
            appState.formData.role_id = role_id;
            appState.formData.site_id = site_id;
            appState.formData.nom = nom;
            appState.formData.prenom = prenom;
            appState.formData.email = email;
            
            // Aller à l'étape 2
            showStep2();
        }
        
        function handleStep2(event) {
            event.preventDefault();
            
            const telephone = document.getElementById("telephone").value.trim();
            const mot_de_passe = document.getElementById("mot_de_passe").value;
            const confirm_password = document.getElementById("confirm_password").value;
            
            // Validation
            if (telephone && !validatePhone(telephone)) {
                showAlert("Numéro de téléphone invalide", "danger");
                return;
            }
            
            if (!validatePassword(mot_de_passe)) {
                showAlert("Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre", "danger");
                return;
            }
            
            if (mot_de_passe !== confirm_password) {
                showAlert("Les mots de passe ne correspondent pas", "danger");
                return;
            }
            
            // Stocker les données restantes
            appState.formData.telephone = telephone;
            appState.formData.mot_de_passe = mot_de_passe;
            
            // Envoyer les données au serveur
            createAccount();
        }
        
        // ==================== API FUNCTIONS ====================
        async function createAccount() {
            try {
                // Afficher un indicateur de chargement
                showAlert("Création du compte en cours...", "info");
                
                // Préparer les données pour l'envoi
                const formData = new FormData();
                Object.keys(appState.formData).forEach(key => {
                    formData.append(key, appState.formData[key]);
                });
                
                // Envoyer la requête POST
                const response = await fetch('create_account_api.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessScreen();
                    
                    // Enregistrer dans le localStorage pour référence
                    localStorage.setItem('last_created_account', JSON.stringify({
                        email: appState.formData.email,
                        role: ROLES[appState.formData.role_id].label,
                        timestamp: new Date().toISOString()
                    }));
                    
                } else {
                    showAlert(result.message || "Erreur lors de la création du compte", "danger");
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                showAlert("Erreur de connexion au serveur", "danger");
                
                // Pour le développement : simuler un succès
                // showSuccessScreen();
            }
        }
        
        // ==================== UTILITY FUNCTIONS ====================
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector("i");
            
            if (input.type === "password") {
                input.type = "text";
                icon.className = "fas fa-eye-slash";
            } else {
                input.type = "password";
                icon.className = "fas fa-eye";
            }
        }
        
        function resetForm() {
            appState = {
                currentStep: "step1",
                formData: {
                    role_id: "",
                    site_id: "",
                    email: "",
                    mot_de_passe: "",
                    confirm_password: "",
                    nom: "",
                    prenom: "",
                    telephone: ""
                }
            };
            
            showStep1();
        }
    </script>
</body>
</html>