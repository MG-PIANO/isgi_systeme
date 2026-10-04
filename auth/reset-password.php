<?php
// auth/reset_password.php
require_once '../config/database.php';
require_once '../lib/EmailSender.php';

session_start();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$valid_token = false;
$user_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $token = $_POST['token'] ?? '';
        
        // Validation
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Veuillez remplir tous les champs';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Les mots de passe ne correspondent pas';
        } elseif (strlen($new_password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères';
        } else {
            // Vérifier le token
            $query = "SELECT * FROM utilisateurs 
                      WHERE reset_token = ? 
                      AND reset_token_expiry > NOW() 
                      AND statut = 'actif'";
            $stmt = $db->prepare($query);
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Hasher le nouveau mot de passe
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Mettre à jour le mot de passe et effacer le token
                $updateQuery = "UPDATE utilisateurs 
                                SET mot_de_passe = ?, 
                                    reset_token = NULL, 
                                    reset_token_expiry = NULL,
                                    date_modification = NOW()
                                WHERE id = ?";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([$hashed_password, $user['id']]);
                
                $success = 'Votre mot de passe a été réinitialisé avec succès !';
                
                // Envoyer un email de confirmation
                $emailSender = EmailSender::getInstance();
                $emailSender->sendPasswordChangedConfirmation(
                    $user['email'],
                    $user['prenom'] . ' ' . $user['nom']
                );
                
            } else {
                $error = 'Lien de réinitialisation invalide ou expiré';
            }
        }
    } catch (Exception $e) {
        error_log("Erreur réinitialisation: " . $e->getMessage());
        $error = 'Une erreur est survenue. Veuillez réessayer.';
    }
} elseif (!empty($token)) {
    // Vérifier la validité du token au chargement de la page
    try {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT email, prenom, nom FROM utilisateurs 
                  WHERE reset_token = ? 
                  AND reset_token_expiry > NOW() 
                  AND statut = 'actif'";
        $stmt = $db->prepare($query);
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $valid_token = true;
            $user_email = $user['email'];
        } else {
            $error = 'Lien de réinitialisation invalide ou expiré';
        }
    } catch (Exception $e) {
        error_log("Erreur vérification token: " . $e->getMessage());
        $error = 'Erreur lors de la vérification du lien';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Réinitialisation mot de passe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #f5f8ff 0%, #e6f0ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .reset-container {
            width: 100%;
            max-width: 500px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #0066cc;
        }
        
        .card-header {
            background: linear-gradient(135deg, #0066cc, #0052a3);
            color: white;
            border-bottom: none;
            padding: 25px 30px;
            border-radius: 15px 15px 0 0 !important;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .alert {
            border-radius: 10px;
            border-left: 4px solid;
            margin-bottom: 20px;
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e1e5eb;
        }
        
        .form-control:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.25rem rgba(0, 102, 204, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #0066cc, #0052a3);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 102, 204, 0.3);
        }
        
        .password-requirements {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .password-requirements ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        
        .password-strength {
            margin-top: 5px;
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0">
                    <i class="fas fa-key me-2"></i>
                    Réinitialisation du mot de passe
                </h4>
            </div>
            
            <div class="card-body">
                <!-- Logo ISGI -->
                <div class="text-center mb-4">
                    <img src="../image/logo isgi.jpg" alt="ISGI Logo" width="80" class="rounded-circle">
                </div>
                
                <!-- Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <br><br>
                        <div class="text-center">
                            <a href="login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-1"></i> Se connecter
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    
                    <?php if ($valid_token): ?>
                        <!-- Formulaire de réinitialisation -->
                        <div class="alert alert-info">
                            <i class="fas fa-user me-2"></i>
                            Réinitialisation pour : <strong><?php echo htmlspecialchars($user_email); ?></strong>
                        </div>
                        
                        <div class="password-requirements">
                            <p class="mb-2"><strong>Exigences de sécurité :</strong></p>
                            <ul>
                                <li>Minimum 8 caractères</li>
                                <li>Lettres et chiffres recommandés</li>
                                <li>Évitez les mots de passe courants</li>
                            </ul>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Nouveau mot de passe</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="new_password" 
                                           id="newPassword" placeholder="Votre nouveau mot de passe" required
                                           oninput="checkPasswordStrength(this.value)">
                                    <button type="button" class="btn btn-outline-secondary" 
                                            onclick="togglePassword('newPassword')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength">
                                    <div class="password-strength-bar" id="passwordStrength"></div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Confirmer le mot de passe</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="confirm_password" 
                                           id="confirmPassword" placeholder="Confirmez votre mot de passe" required>
                                    <button type="button" class="btn btn-outline-secondary" 
                                            onclick="togglePassword('confirmPassword')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-danger" id="passwordMatchError"></small>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Réinitialiser
                                </button>
                                <a href="login.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Annuler
                                </a>
                            </div>
                        </form>
                        
                    <?php else: ?>
                        <!-- Token invalide -->
                        <div class="text-center py-4">
                            <div class="mb-4">
                                <i class="fas fa-exclamation-triangle text-warning" style="font-size: 48px;"></i>
                            </div>
                            <h5 class="mb-3">Lien invalide ou expiré</h5>
                            <p class="text-muted mb-4">
                                Ce lien de réinitialisation n'est plus valable.
                                Veuillez demander un nouveau lien.
                            </p>
                            <a href="login.php?step=forgot_password" class="btn btn-primary">
                                <i class="fas fa-redo me-2"></i> Nouvelle demande
                            </a>
                        </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            
            strengthBar.style.width = strength + '%';
            
            if (strength < 50) {
                strengthBar.style.backgroundColor = '#dc3545';
            } else if (strength < 75) {
                strengthBar.style.backgroundColor = '#ffc107';
            } else {
                strengthBar.style.backgroundColor = '#28a745';
            }
        }
        
        // Validation en temps réel
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const newPass = document.getElementById('newPassword');
            const confirmPass = document.getElementById('confirmPassword');
            const errorMsg = document.getElementById('passwordMatchError');
            const submitBtn = document.getElementById('submitBtn');
            
            if (form && newPass && confirmPass) {
                function checkPasswords() {
                    if (newPass.value && confirmPass.value) {
                        if (newPass.value !== confirmPass.value) {
                            errorMsg.textContent = 'Les mots de passe ne correspondent pas';
                            submitBtn.disabled = true;
                        } else {
                            errorMsg.textContent = '';
                            submitBtn.disabled = false;
                        }
                    }
                }
                
                newPass.addEventListener('input', checkPasswords);
                confirmPass.addEventListener('input', checkPasswords);
            }
        });
    </script>
</body>
</html>