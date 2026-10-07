<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4', 'root', 'admin1234');
    echo "=== MYSQL TABLE STRUCTURE utilisateurs ===\n";
    $stmt = $pdo->query('DESCRIBE utilisateurs');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} - {$col['Type']} - {$col['Null']} - {$col['Key']} - {$col['Default']}\n";
    }
    
    echo "\n=== MYSQL DISTINCT ROLES IN utilisateurs ===\n";
    $stmt2 = $pdo->query('SELECT DISTINCT role FROM utilisateurs');
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "Role: " . json_encode($r['role']) . "\n";
    }

    echo "\n=== ALL USERS IN MYSQL utilisateurs ===\n";
    $stmt3 = $pdo->query('SELECT id, nom, prenom, email, role, statut, site_id FROM utilisateurs LIMIT 50');
    foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo "{$u['id']} | {$u['nom']} {$u['prenom']} | {$u['email']} | role: {$u['role']} | site: {$u['site_id']}\n";
    }
} catch (Exception $e) {
    echo "MySQL Error: " . $e->getMessage() . "\n";
}
