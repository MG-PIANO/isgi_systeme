<?php
session_start();
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Réinscription</h2>";

// 1. Vérifier la table configurations
echo "<h3>1. Table configurations:</h3>";
try {
    $stmt = $db->query("SELECT cle, valeur FROM configurations");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($configs)) {
        echo "❌ Table configurations VIDE<br>";
    } else {
        echo "✅ Table configurations OK<br>";
        echo "<table border='1'><tr><th>Clé</th><th>Valeur</th></tr>";
        foreach ($configs as $c) {
            echo "<tr><td>" . htmlspecialchars($c['cle']) . "</td><td>" . htmlspecialchars($c['valeur']) . "</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "❌ Erreur configurations: " . $e->getMessage() . "<br>";
}

// 2. Vérifier un étudiant
echo "<h3>2. Étudiant test (ID 5):</h3>";
try {
    $stmt = $db->prepare("SELECT id, matricule, nom, prenom, statut, filiere, niveau FROM etudiants WHERE id = 5");
    $stmt->execute();
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($etudiant) {
        echo "✅ Étudiant trouvé:<br>";
        echo "ID: " . $etudiant['id'] . "<br>";
        echo "Matricule: " . $etudiant['matricule'] . "<br>";
        echo "Nom: " . $etudiant['nom'] . " " . $etudiant['prenom'] . "<br>";
        echo "Statut: " . $etudiant['statut'] . "<br>";
        echo "Filière: " . $etudiant['filiere'] . "<br>";
        echo "Niveau: " . $etudiant['niveau'] . "<br>";
    } else {
        echo "❌ Étudiant ID 5 non trouvé<br>";
    }
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
}

// 3. Test UPDATE
echo "<h3>3. Test UPDATE:</h3>";
try {
    $sql = "UPDATE etudiants SET filiere = 'TEST DEBUG' WHERE id = 5";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute();
    
    if ($result) {
        echo "✅ UPDATE test réussi<br>";
        echo "Lignes affectées: " . $stmt->rowCount() . "<br>";
        
        // Revenir à l'ancienne valeur
        $sql = "UPDATE etudiants SET filiere = 'Génie Logiciel' WHERE id = 5";
        $db->exec($sql);
        echo "✅ Valeur restaurée<br>";
    } else {
        echo "❌ UPDATE test échoué<br>";
    }
} catch (Exception $e) {
    echo "❌ Erreur UPDATE: " . $e->getMessage() . "<br>";
}

// 4. Vérifier les logs PHP
echo "<h3>4. Logs PHP récents:</h3>";
$log_file = 'C:\wamp64\logs\php_error.log';
if (file_exists($log_file)) {
    $logs = tailCustom($log_file, 20);
    echo "<pre>" . htmlspecialchars($logs) . "</pre>";
} else {
    echo "Fichier log non trouvé: " . $log_file . "<br>";
}

function tailCustom($filepath, $lines = 1) {
    // Fonction pour lire les dernières lignes d'un fichier
    $f = fopen($filepath, "rb");
    fseek($f, -1, SEEK_END);
    
    $buffer = "";
    $output = "";
    
    while (ftell($f) > 0 && $lines > 0) {
        $chunk = min(1024, ftell($f));
        fseek($f, -$chunk, SEEK_CUR);
        $buffer = fread($f, $chunk) . $buffer;
        fseek($f, -$chunk, SEEK_CUR);
        
        $count = substr_count($buffer, "\n");
        if ($count >= $lines) {
            $buffer = implode("\n", array_slice(explode("\n", $buffer), -$lines));
            break;
        }
        $lines -= $count;
    }
    
    fclose($f);
    return $buffer;
}
?>