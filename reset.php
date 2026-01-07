<?php
// reset_database.php
require_once 'config/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Lire et exécuter le script SQL
    $sql = file_get_contents('database/isgi_systeme_final.sql');
    
    // Exécuter les requêtes une par une
    $queries = explode(';', $sql);
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            $db->exec($query . ';');
        }
    }
    
    echo "Base de données réinitialisée avec succès !";
} catch (PDOException $e) {
    echo "Erreur : " . $e->getMessage();
}
?>