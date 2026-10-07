<?php
$ports = [3306, 3307, 3308];
foreach ($ports as $port) {
    try {
        $db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=isgi_systeme;charset=utf8mb4", "root", "admin1234");
        echo "Connected successfully to MySQL on port $port!\n";
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables count: " . count($tables) . "\n";
        foreach ($tables as $t) {
            if (preg_match('/(calen|emploi|salle|cours|matiere|classe)/i', $t)) {
                echo " - $t\n";
            }
        }
        break;
    } catch (Exception $e) {
        echo "Port $port: " . $e->getMessage() . "\n";
    }
}
