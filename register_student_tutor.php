<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Création de Compte Étudiant/Tuteur</title>
    
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
        
        .container {
            max-width: 800px;
            margin: 0 auto;
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
        
        /* Form Panel */
        .form-panel {
            background: white;
            border-radius: var(--radius-lg);
            padding: 40px;
            box-shadow: var(--shadow-md);
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
        
        /* Role Selection */
        .role-options {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .role-option {
            flex: 1;
            border: 2px solid var(--medium-gray);
            border-radius: var(--radius-sm);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .role-option:hover {
            border-color: var(--primary-blue);
            background: #f8fbff;
        }
        
        .role-option.selected {
            border-color: var(--primary-blue);
            background: #f0f7ff;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        .role-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: var(--primary-blue);
        }
        
        .role-option h4 {
            font-size: 16px;
            margin-bottom: 5px;
            color: var(--text-primary);
        }
        
        .role-option p {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0;
        }
        
        /* Student Info Preview */
        .student-info-preview {
            background: #f8fbff;
            border: 1px solid #e1f0ff;
            border-radius: var(--radius-sm);
            padding: 20px;
            margin-top: 10px;
            display: none;
        }
        
        .student-info-preview.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .student-info-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .student-info-header h5 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: var(--text-primary);
        }
        
        .student-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .detail-item {
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .detail-value {
            font-size: 14px;
            color: var(--text-primary);
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
        
        /* Success Screen */
        .success-screen {
            text-align: center;
            padding: 40px 20px;
        }
        
        .success-icon {
            font-size: 64px;
            color: var(--success-green);
            margin-bottom: 20px;
        }
        
        /* Footer */
        .form-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--medium-gray);
            margin-top: 30px;
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
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .form-panel {
                padding: 25px;
            }
            
            .form-card {
                padding: 20px;
            }
            
            .role-options {
                flex-direction: column;
            }
            
            .student-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="main-header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="logo-text">
                    <h1>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <p>Création de compte étudiant/tuteur</p>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main>
            <div class="alert-container" id="alertContainer"></div>
            
            <div id="contentContainer">
                <!-- Contenu dynamique -->
            </div>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Application JavaScript -->
    <script>
        // État de l'application
        let appState = {
            selectedRole: null,
            studentInfo: null,
            formData: {
                role_id: '',
                matricule: '',
                email: '',
                mot_de_passe: '',
                confirm_password: '',
                nom: '',
                prenom: '',
                telephone: ''
            }
        };
        
        // Initialisation
        document.addEventListener("DOMContentLoaded", function() {
            showRoleSelection();
        });
        
        // Fonctions d'affichage
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
            
            // Suppression automatique après 5 secondes
            setTimeout(() => {
                if (alertContainer.firstChild) {
                    alertContainer.firstChild.remove();
                }
            }, 5000);
        }
        
        function clearAlerts() {
            document.getElementById("alertContainer").innerHTML = "";
        }
        
        function showRoleSelection() {
            clearAlerts();
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-panel">
                    <div class="form-title">
                        <h2><i class="fas fa-user-plus"></i> Type de compte</h2>
                        <p>Choisissez le type de compte que vous souhaitez créer</p>
                    </div>
                    
                    <div class="form-card">
                        <div class="role-options">
                            <div class="role-option" onclick="selectRole(8)">
                                <div class="role-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <h4>Étudiant</h4>
                                <p>Créez un compte pour accéder à votre espace étudiant</p>
                            </div>
                            
                            <div class="role-option" onclick="selectRole(9)">
                                <div class="role-icon">
                                    <i class="fas fa-hands-helping"></i>
                                </div>
                                <h4>Tuteur</h4>
                                <p>Créez un compte pour suivre un étudiant</p>
                            </div>
                        </div>
                        
                        <div class="form-footer">
                            <p>Vous êtes un membre du personnel ?</p>
                            <div class="form-footer-links">
                                <a href="create_account_api.php">Créer un compte personnel</a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function selectRole(roleId) {
            appState.selectedRole = roleId;
            appState.formData.role_id = roleId;
            
            // Mettre en évidence l'option sélectionnée
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            if (roleId === 8) {
                document.querySelectorAll('.role-option')[0].classList.add('selected');
                showStudentForm();
            } else if (roleId === 9) {
                document.querySelectorAll('.role-option')[1].classList.add('selected');
                showTutorForm();
            }
        }
        
        function showStudentForm() {
            clearAlerts();
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-panel">
                    <div class="form-title">
                        <h2><i class="fas fa-user-graduate"></i> Compte Étudiant</h2>
                        <p>Étape 1 : Vérification de votre matricule</p>
                    </div>
                    
                    <div class="form-card">
                        <form id="matriculeForm" onsubmit="verifyMatricule(event)">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-id-card"></i> Numéro de matricule
                                </label>
                                <input type="text" class="form-control" id="matricule" 
                                       placeholder="Ex: ISGI-2025-00001" required>
                                <small class="text-muted" style="display: block; margin-top: 5px;">
                                    Entrez votre numéro de matricule fourni par l'administration
                                </small>
                            </div>
                            
                            <div class="student-info-preview" id="studentInfoPreview"></div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" id="verifyButton">
                                    <i class="fas fa-check-circle"></i> Vérifier le matricule
                                </button>
                            </div>
                        </form>
                        
                        <div class="form-footer">
                            <div class="form-footer-links">
                                <a href="#" onclick="showRoleSelection()">
                                    <i class="fas fa-arrow-left"></i> Retour au choix du rôle
                                </a>
                                <a href="#" onclick="showAlert('Contactez le secrétariat pour obtenir votre matricule.', 'info')">
                                    <i class="fas fa-question-circle"></i> Aide
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function showTutorForm() {
            clearAlerts();
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-panel">
                    <div class="form-title">
                        <h2><i class="fas fa-hands-helping"></i> Compte Tuteur</h2>
                        <p>Étape 1 : Vérification du matricule étudiant</p>
                    </div>
                    
                    <div class="form-card">
                        <form id="tutorMatriculeForm" onsubmit="verifyTutorMatricule(event)">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>Information :</strong> Vous devez entrer le matricule de l'étudiant 
                                    que vous souhaitez suivre. Ce matricule doit être validé par l'administration.
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-id-card"></i> Matricule de l'étudiant
                                </label>
                                <input type="text" class="form-control" id="tutorMatricule" 
                                       placeholder="Ex: ISGI-2025-00001" required>
                                <small class="text-muted" style="display: block; margin-top: 5px;">
                                    Entrez le numéro de matricule de l'étudiant que vous souhaitez suivre
                                </small>
                            </div>
                            
                            <div class="student-info-preview" id="tutorStudentInfoPreview"></div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" id="verifyTutorButton">
                                    <i class="fas fa-check-circle"></i> Vérifier le matricule
                                </button>
                            </div>
                        </form>
                        
                        <div class="form-footer">
                            <div class="form-footer-links">
                                <a href="#" onclick="showRoleSelection()">
                                    <i class="fas fa-arrow-left"></i> Retour au choix du rôle
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function showAccountForm() {
            clearAlerts();
            
            const roleName = appState.selectedRole === 8 ? 'étudiant' : 'tuteur';
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-panel">
                    <div class="form-title">
                        <h2><i class="fas fa-user-plus"></i> Création du compte ${roleName}</h2>
                        <p>Étape 2 : Informations personnelles</p>
                    </div>
                    
                    <div class="form-card">
                        <div class="student-info-preview show" style="margin-bottom: 25px;">
                            <div class="student-info-header">
                                <i class="fas fa-user-check" style="color: var(--primary-blue);"></i>
                                <h5>Étudiant vérifié</h5>
                            </div>
                            <div class="student-details">
                                <div class="detail-item">
                                    <div class="detail-label">Matricule :</div>
                                    <div class="detail-value">${appState.formData.matricule}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Nom :</div>
                                    <div class="detail-value">${appState.studentInfo.nom} ${appState.studentInfo.prenom}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Site :</div>
                                    <div class="detail-value">${appState.studentInfo.site_nom || 'Non spécifié'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <form id="accountForm" onsubmit="createAccount(event)">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-user"></i> Nom
                                        </label>
                                        <input type="text" class="form-control" id="nom" 
                                               value="${appState.studentInfo.nom}" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-user"></i> Prénom
                                        </label>
                                        <input type="text" class="form-control" id="prenom" 
                                               value="${appState.studentInfo.prenom}" required>
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
                                <label class="form-label">
                                    <i class="fas fa-phone"></i> Téléphone
                                </label>
                                <input type="tel" class="form-control" id="telephone" 
                                       placeholder="+242 XX XXX XXX">
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
                                            Doit contenir au moins 8 caractères
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
                                    Votre compte devra être validé par un administrateur avant activation.
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-6">
                                    <button type="button" class="btn btn-outline" onclick="${appState.selectedRole === 8 ? 'showStudentForm()' : 'showTutorForm()'}">
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
                </div>
            `;
            
            // Remplir les données existantes
            if (appState.formData.email) {
                document.getElementById('email').value = appState.formData.email;
            }
            if (appState.formData.telephone) {
                document.getElementById('telephone').value = appState.formData.telephone;
            }
        }
        
        function showSuccessScreen() {
            clearAlerts();
            
            const roleName = appState.selectedRole === 8 ? 'Étudiant' : 'Tuteur';
            
            document.getElementById("contentContainer").innerHTML = `
                <div class="form-panel">
                    <div class="success-screen">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        
                        <h3 style="margin-bottom: 15px; color: var(--success-green);">Demande envoyée avec succès !</h3>
                        <p style="color: #666; margin-bottom: 25px; max-width: 600px; margin-left: auto; margin-right: auto;">
                            Votre demande de création de compte a été enregistrée. 
                            Un administrateur vérifiera votre matricule et vos informations 
                            avant d'activer votre compte.
                        </p>
                        
                        <div class="alert alert-info" style="text-align: left; max-width: 500px; margin: 25px auto;">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Récapitulatif :</strong><br>
                                <strong>Type de compte :</strong> ${roleName}<br>
                                <strong>Matricule :</strong> ${appState.formData.matricule}<br>
                                <strong>Nom :</strong> ${appState.formData.nom} ${appState.formData.prenom}<br>
                                <strong>Email :</strong> ${appState.formData.email}
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px;">
                            <button type="button" class="btn btn-primary" onclick="resetForm()" style="width: auto; padding: 12px 30px;">
                                <i class="fas fa-user-plus"></i> Créer un autre compte
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Fonctions de traitement
        async function verifyMatricule(event) {
            event.preventDefault();
            
            const matricule = document.getElementById("matricule").value.trim();
            const verifyButton = document.getElementById("verifyButton");
            
            if (!matricule) {
                showAlert("Veuillez entrer un numéro de matricule", "danger");
                return;
            }
            
            try {
                // Désactiver le bouton pendant la vérification
                verifyButton.disabled = true;
                verifyButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Vérification...';
                
                // Appeler l'API pour vérifier le matricule
                const response = await fetch('verify_matricule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `matricule=${encodeURIComponent(matricule)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    appState.formData.matricule = matricule;
                    appState.studentInfo = result.student_info;
                    appState.studentInfo.site_nom = result.site_nom;
                    
                    // Afficher les informations de l'étudiant
                    const preview = document.getElementById("studentInfoPreview");
                    preview.innerHTML = `
                        <div class="student-info-header">
                            <i class="fas fa-check-circle" style="color: var(--success-green);"></i>
                            <h5>Matricule vérifié</h5>
                        </div>
                        <div class="student-details">
                            <div class="detail-item">
                                <div class="detail-label">Matricule :</div>
                                <div class="detail-value">${matricule}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nom :</div>
                                <div class="detail-value">${result.student_info.nom} ${result.student_info.prenom}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Site :</div>
                                <div class="detail-value">${result.site_nom}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Statut :</div>
                                <div class="detail-value">${result.student_info.statut}</div>
                            </div>
                        </div>
                    `;
                    preview.classList.add("show");
                    
                    // Changer le bouton pour continuer
                    verifyButton.innerHTML = '<i class="fas fa-arrow-right"></i> Continuer';
                    verifyButton.onclick = function() { showAccountForm(); };
                } else {
                    showAlert(result.message || "Matricule non trouvé", "danger");
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                showAlert("Erreur de connexion au serveur", "danger");
            } finally {
                verifyButton.disabled = false;
                if (verifyButton.innerHTML.includes('Vérification')) {
                    verifyButton.innerHTML = '<i class="fas fa-check-circle"></i> Vérifier le matricule';
                }
            }
        }
        
        async function verifyTutorMatricule(event) {
            event.preventDefault();
            
            const matricule = document.getElementById("tutorMatricule").value.trim();
            const verifyButton = document.getElementById("verifyTutorButton");
            
            if (!matricule) {
                showAlert("Veuillez entrer le matricule de l'étudiant", "danger");
                return;
            }
            
            try {
                verifyButton.disabled = true;
                verifyButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Vérification...';
                
                // Appeler l'API pour vérifier le matricule
                const response = await fetch('verify_matricule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `matricule=${encodeURIComponent(matricule)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    appState.formData.matricule = matricule;
                    appState.studentInfo = result.student_info;
                    appState.studentInfo.site_nom = result.site_nom;
                    
                    // Afficher les informations de l'étudiant
                    const preview = document.getElementById("tutorStudentInfoPreview");
                    preview.innerHTML = `
                        <div class="student-info-header">
                            <i class="fas fa-check-circle" style="color: var(--success-green);"></i>
                            <h5>Étudiant vérifié</h5>
                        </div>
                        <div class="student-details">
                            <div class="detail-item">
                                <div class="detail-label">Matricule :</div>
                                <div class="detail-value">${matricule}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nom :</div>
                                <div class="detail-value">${result.student_info.nom} ${result.student_info.prenom}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Site :</div>
                                <div class="detail-value">${result.site_nom}</div>
                            </div>
                        </div>
                        <div class="alert alert-warning" style="margin-top: 10px; padding: 10px; font-size: 13px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Important :</strong> En créant un compte tuteur, vous confirmez être autorisé 
                                à suivre cet étudiant. Cette demande sera vérifiée par l'administration.
                            </div>
                        </div>
                    `;
                    preview.classList.add("show");
                    
                    // Changer le bouton pour continuer
                    verifyButton.innerHTML = '<i class="fas fa-arrow-right"></i> Continuer';
                    verifyButton.onclick = function() { showAccountForm(); };
                } else {
                    showAlert(result.message || "Matricule non trouvé", "danger");
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                showAlert("Erreur de connexion au serveur", "danger");
            } finally {
                verifyButton.disabled = false;
                if (verifyButton.innerHTML.includes('Vérification')) {
                    verifyButton.innerHTML = '<i class="fas fa-check-circle"></i> Vérifier le matricule';
                }
            }
        }
        
        async function createAccount(event) {
            event.preventDefault();
            
            // Récupérer les données du formulaire
            appState.formData.nom = document.getElementById("nom").value.trim();
            appState.formData.prenom = document.getElementById("prenom").value.trim();
            appState.formData.email = document.getElementById("email").value.trim();
            appState.formData.telephone = document.getElementById("telephone").value.trim();
            appState.formData.mot_de_passe = document.getElementById("mot_de_passe").value;
            appState.formData.confirm_password = document.getElementById("confirm_password").value;
            
            // Validation simple
            if (!appState.formData.email || !appState.formData.mot_de_passe) {
                showAlert("Veuillez remplir tous les champs obligatoires", "danger");
                return;
            }
            
            if (appState.formData.mot_de_passe !== appState.formData.confirm_password) {
                showAlert("Les mots de passe ne correspondent pas", "danger");
                return;
            }
            
            if (appState.formData.mot_de_passe.length < 8) {
                showAlert("Le mot de passe doit contenir au moins 8 caractères", "danger");
                return;
            }
            
            try {
                // Afficher un indicateur de chargement
                showAlert("Création du compte en cours...", "info");
                
                // Envoyer les données au serveur
                const formData = new FormData();
                Object.keys(appState.formData).forEach(key => {
                    formData.append(key, appState.formData[key]);
                });
                
                const response = await fetch('create_account_student_tutor.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessScreen();
                } else {
                    showAlert(result.message || "Erreur lors de la création du compte", "danger");
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                showAlert("Erreur de connexion au serveur", "danger");
            }
        }
        
        // Fonction utilitaire pour basculer la visibilité du mot de passe
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            
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
                selectedRole: null,
                studentInfo: null,
                formData: {
                    role_id: '',
                    matricule: '',
                    email: '',
                    mot_de_passe: '',
                    confirm_password: '',
                    nom: '',
                    prenom: '',
                    telephone: ''
                }
            };
            
            showRoleSelection();
        }
    </script>
</body>
</html>