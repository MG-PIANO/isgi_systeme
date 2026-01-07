<?php
// dashboard/admin_principal/test_action.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
require_once ROOT_PATH . '/config/database.php';

session_start();
$_SESSION['user_id'] = 4; // ID de votre admin

echo "<h2>Test Action 'valider'</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Prendre la première demande
    $demande = $db->query("SELECT * FROM demande_inscriptions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if (!$demande) {
        echo "❌ Aucune demande trouvée<br>";
        exit;
    }
    
    echo "Demande trouvée: {$demande['nom']} {$demande['prenom']}<br>";
    
    // Simuler l'action 'valider'
    $action = 'valider';
    $demande_id = $demande['id'];
    $commentaire = 'Test automatique';
    
    // Code de création d'étudiant (identique)
    $matricule = 'ISGI-' . date('Y') . '-' . str_pad($demande_id, 5, '0', STR_PAD_LEFT);
    $site_id = 1;
    
    $sql = "INSERT INTO etudiants 
            (utilisateur_id, site_id, classe_id, matricule, nom, prenom, numero_cni, 
             date_naissance, lieu_naissance, sexe, nationalite, adresse, ville, pays, 
             profession, situation_matrimoniale, statut)
            VALUES (NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')";
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        $site_id,
        $matricule,
        $demande['nom'],
        $demande['prenom'],
        $demande['numero_cni'],
        $demande['date_naissance'],
        $demande['lieu_naissance'],
        $demande['sexe'],
        $demande['nationalite'] ?? 'Congolaise',
        $demande['adresse'],
        $demande['ville'],
        $demande['pays'] ?? 'Congo',
        $demande['profession'],
        $demande['situation_matrimoniale']
    ]);
    
    if ($result) {
        $id = $db->lastInsertId();
        echo "✅ ACTION 'valider' RÉUSSIE !<br>";
        echo "🆔 Étudiant ID: $id<br>";
        echo "📋 Matricule: $matricule<br>";
        echo "👤 Nom: {$demande['nom']} {$demande['prenom']}<br>";
        
        // Vérifiez dans la base
        $check = $db->query("SELECT * FROM etudiants WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        echo "✅ Vérification base: " . ($check ? "OK" : "Non trouvé") . "<br>";
    } else {
        echo "❌ Échec: " . print_r($stmt->errorInfo(), true) . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}
?>