<?php
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

$tables = ['etudiants', 'presences', 'classes', 'utilisateurs', 'annees_academiques'];

foreach ($tables as $table) {
    echo "\nSchema of '$table':\n";
    try {
        $stmt = $db->query("DESCRIBE $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "- {$row['Field']} ({$row['Type']}) " . ($row['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . "\n";
        }
    } catch (Exception $e) {
        echo "Error describing $table: " . $e->getMessage() . "\n";
    }
}
?>
