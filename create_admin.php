<?php
// create_admin.php
echo "<h2>Création d'un compte administrateur</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    
    echo "<h3>Résultat :</h3>";
    echo "Mot de passe : " . htmlspecialchars($password) . "<br>";
    echo "Hash : " . htmlspecialchars($hashed) . "<br><br>";
    
    echo "<strong>Requête SQL à exécuter :</strong><br>";
    echo "<code>";
    echo "INSERT INTO utilisateurs (role_id, email, mot_de_passe, nom, prenom, telephone, statut) ";
    echo "VALUES (1, 'admin@isgi.cg', '" . $hashed . "', 'Admin', 'Principal', '+242000000', 'actif');";
    echo "</code>";
}
?>

<form method="POST">
    <label>Mot de passe :</label><br>
    <input type="text" name="password" value="admin123" required><br><br>
    <button type="submit">Générer le hash</button>
</form>