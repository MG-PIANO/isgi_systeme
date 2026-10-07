<?php
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

echo "Checking tables:\n";
try {
    $stmt = $db->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "- " . $row[0] . "\n";
    }
} catch (Exception $e) {
    echo "Error listing tables: " . $e->getMessage() . "\n";
}

echo "\nChecking 'configurations' table:\n";
try {
    $stmt = $db->query("SELECT * FROM configurations LIMIT 1");
    echo "Configurations table exists.\n";
} catch (Exception $e) {
    echo "Configurations table error: " . $e->getMessage() . "\n";
}

echo "\nChecking 'annees_academiques' table:\n";
try {
    $stmt = $db->query("SELECT * FROM annees_academiques LIMIT 1");
    echo "Annees_academiques table exists.\n";
} catch (Exception $e) {
    echo "Annees_academiques table error: " . $e->getMessage() . "\n";
}
?>
