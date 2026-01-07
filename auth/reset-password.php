<?php
// auth/reset-password.php
require_once '../config/database.php';
session_start();

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: login.php');
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Vérifier le token
    $stmt = $db->prepare("
        SELECT prt.*, u.email, u.prenom, u.nom 
        FROM password_reset_tokens prt
        JOIN utilisateurs u ON prt.user_id = u.id
        WHERE prt.token = ? AND prt.expires_at > ? AND u.statut = 'actif'
    ");
    
    $current_time = time();
    $stmt->execute([$token, $current_time]);
    $token_data = $stmt->fetch();
    
    if (!$token_data) {
        $error = 'Token de réinitialisation invalide ou expiré.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($password) || empty($confirm_password)) {
            $error = 'Veuillez remplir tous les champs';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères';
        } elseif ($password !== $confirm_password) {
            $error = 'Les mots de passe ne correspondent pas';
        } else {
            // Hasher le nouveau mot de passe
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Mettre à jour le mot de passe
            $updateStmt = $db->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?");
            $updateStmt->execute([$hashed_password, $token_data['user_id']]);
            
            // Supprimer le token utilisé
            $deleteStmt = $db->prepare("DELETE FROM password_reset_tokens WHERE token = ?");
            $deleteStmt->execute([$token]);
            
            $success = 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.';
            
            // Rediriger après 3 secondes
            header('Refresh: 3; url=login.php');
        }
    }
} catch (Exception $e) {
    error_log("Erreur reset password: " . $e->getMessage());
    $error = 'Une erreur est survenue. Veuillez réessayer plus tard.';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Réinitialisation de mot de passe</title>
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
            max-width: 500px;
            width: 100%;
        }
        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .reset-header {
            background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .reset-body {
            padding: 30px;
        }
        .alert {
            border-left: 4px solid transparent;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-card">
            <div class="reset-header">
                <h2><i class="fas fa-lock"></i> ISGI - Nouveau mot de passe</h2>
                <p class="mb-0">Créez votre nouveau mot de passe sécurisé</p>
            </div>
            
            <div class="reset-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                    <p class="text-center">Redirection vers la page de connexion...</p>
                <?php elseif ($token_data): ?>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Nouveau mot de passe</label>
                            <input type="password" class="form-control" name="password" 
                                   placeholder="Minimum 8 caractères" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Confirmer le mot de passe</label>
                            <input type="password" class="form-control" name="confirm_password" 
                                   placeholder="Répétez votre mot de passe" required>
                        </div>
                        
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save"></i> Enregistrer le nouveau mot de passe
                            </button>
                        </div>
                    </form>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Note de sécurité :</strong>
                            <ul class="mb-0">
                                <li>Utilisez au moins 8 caractères</li>
                                <li>Combinez lettres, chiffres et caractères spéciaux</li>
                                <li>Évitez les mots de passe courants</li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>Le lien de réinitialisation est invalide ou a expiré.</div>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Retour à la connexion
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>