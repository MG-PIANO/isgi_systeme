<?php
// test_email_simple.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT_PATH', dirname(__FILE__));
require_once ROOT_PATH . '/libs/email_sender.php';

echo "<h2>Test simple d'envoi d'email</h2>";

// Données de test
$data = [
    'to' => 'moundouroger@gmail.com', // CHANGEZ par votre email
    'name' => 'Jean Dupont',
    'role' => 8, // Étudiant
    'admin_name' => 'Admin ISGI',
    'admin_email' => 'admin@isgi.cg'
];

echo "Envoi d'email à: " . $data['to'] . "<br>";
echo "Nom: " . $data['name'] . "<br>";
echo "Rôle ID: " . $data['role'] . "<br><br>";

try {
    $result = EmailSender::sendAccountApproval($data);
    
    if ($result === true) {
        echo "<h3 style='color: green;'>✅ SUCCÈS : Email envoyé !</h3>";
        echo "Vérifiez votre boîte email (pensez aux spams).";
    } elseif ($result === false) {
        echo "<h3 style='color: red;'>❌ ÉCHEC : Email non envoyé</h3>";
        echo "Erreur probable :<br>";
        echo "1. Problème de connexion SMTP<br>";
        echo "2. Identifiants Gmail incorrects<br>";
        echo "3. Blocage par Google (activez l'accès aux apps moins sécurisées)<br>";
        echo "<br>Consultez le fichier C:\\wamp64\\logs\\php_error.log";
    }
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ EXCEPTION</h3>";
    echo "Message: " . $e->getMessage() . "<br>";
    echo "Fichier: " . $e->getFile() . "<br>";
    echo "Ligne: " . $e->getLine() . "<br>";
}
?>