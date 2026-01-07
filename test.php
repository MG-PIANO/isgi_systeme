<?php
// Testez votre fonction après avoir inséré ces données
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

function genererNumeroDemande($db) {
    $annee = date('Y');
    
    // Trouver le dernier numéro pour cette année
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(numero_demande, '-', -1) AS UNSIGNED)) as max_num
        FROM demande_inscriptions 
        WHERE numero_demande LIKE CONCAT('ISGI-', :annee, '-%')
    ");
    $stmt->execute([':annee' => $annee]);
    $result = $stmt->fetch();
    
    $sequence_num = ($result['max_num'] ?? 0) + 1;
    
    return 'ISGI-' . $annee . '-' . str_pad($sequence_num, 5, '0', STR_PAD_LEFT);
}

// Test
echo "Prochain numéro à générer: " . genererNumeroDemande($db); // Devrait afficher: ISGI-2025-00016
?>