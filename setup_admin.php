<?php
// setup_admin.php
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>Configuration de l'administrateur principal</h2>";
    
    // Vérifier si l'admin existe déjà
    $checkQuery = "SELECT COUNT(*) as count FROM utilisateurs WHERE email = 'admin@isgi.cg'";
    $result = $db->query($checkQuery)->fetch();
    
    if ($result['count'] > 0) {
        echo "<div class='alert alert-warning'>L'administrateur existe déjà.</div>";
        
        // Afficher les informations
        $query = "SELECT * FROM utilisateurs WHERE email = 'admin@isgi.cg'";
        $admin = $db->query($query)->fetch();
        
        echo "<h3>Informations actuelles :</h3>";
        echo "<pre>" . print_r($admin, true) . "</pre>";
        
        echo "<h3>Tester le mot de passe :</h3>";
        if (password_verify('admin123', $admin['mot_de_passe'])) {
            echo "<div class='alert alert-success'>✓ Le mot de passe 'admin123' fonctionne</div>";
        } else {
            echo "<div class='alert alert-danger'>✗ Le mot de passe 'admin123' ne fonctionne PAS</div>";
            
            // Réinitialiser le mot de passe
            if (isset($_POST['reset_password'])) {
                $newHash = password_hash('admin123', PASSWORD_DEFAULT);
                $updateQuery = "UPDATE utilisateurs SET mot_de_passe = ? WHERE email = 'admin@isgi.cg'";
                $stmt = $db->prepare($updateQuery);
                $stmt->execute([$newHash]);
                
                echo "<div class='alert alert-success'>Mot de passe réinitialisé avec succès !</div>";
                echo "<p>Nouveau hash : $newHash</p>";
            }
            
            echo "<form method='POST'>
                    <button type='submit' name='reset_password' class='btn btn-danger'>
                        Réinitialiser le mot de passe à 'admin123'
                    </button>
                  </form>";
        }
        
    } else {
        // Créer l'administrateur
        $password = 'admin123';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO utilisateurs (role_id, email, mot_de_passe, nom, prenom, telephone, statut, date_creation) 
                  VALUES (1, 'admin@isgi.cg', ?, 'Admin', 'Principal', '+242000000', 'actif', NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$hashedPassword]);
        
        echo "<div class='alert alert-success'>Administrateur créé avec succès !</div>";
        echo "<h3>Identifiants :</h3>";
        echo "<ul>";
        echo "<li><strong>Email :</strong> admin@isgi.cg</li>";
        echo "<li><strong>Mot de passe :</strong> admin123</li>";
        echo "<li><strong>Hash :</strong> $hashedPassword</li>";
        echo "</ul>";
    }
    
    echo "<hr>";
    echo "<h3>Liste de tous les utilisateurs :</h3>";
    
    $query = "SELECT u.id, u.email, u.nom, u.prenom, r.nom as role, u.statut 
              FROM utilisateurs u 
              LEFT JOIN roles r ON u.role_id = r.id";
    $users = $db->query($query)->fetchAll();
    
    if (empty($users)) {
        echo "<p>Aucun utilisateur trouvé</p>";
    } else {
        echo "<table class='table table-bordered'>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Nom</th>
                        <th>Rôle</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>";
        
        foreach ($users as $user) {
            echo "<tr>
                    <td>{$user['id']}</td>
                    <td>{$user['email']}</td>
                    <td>{$user['prenom']} {$user['nom']}</td>
                    <td>{$user['role']}</td>
                    <td>{$user['statut']}</td>
                  </tr>";
        }
        
        echo "</tbody></table>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Erreur : " . $e->getMessage() . "</div>";
}
?>

<style>
    body { padding: 20px; font-family: Arial, sans-serif; }
    .alert { padding: 15px; margin: 10px 0; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
</style>