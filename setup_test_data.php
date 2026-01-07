<?php
// setup_test_data.php - Créer des données de test complètes
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>Création de données de test</h2>";
    
    // 1. Vérifier/Créer des années académiques
    $annees = [
        ['2024-2025', 'Octobre', '2024-10-01', '2025-09-30', 'active'],
        ['2025-2026', 'Janvier', '2025-01-01', '2025-12-31', 'planifiee']
    ];
    
    foreach ($annees as $annee) {
        // Vérifier si existe
        $check = $db->prepare("SELECT COUNT(*) as count FROM annees_academiques WHERE libelle = ?");
        $check->execute([$annee[0]]);
        
        if ($check->fetch()['count'] == 0) {
            $query = "INSERT INTO annees_academiques (site_id, libelle, type_rentree, date_debut, date_fin, statut) 
                      VALUES (1, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([1, $annee[0], $annee[1], $annee[2], $annee[3], $annee[4]]);
            
            echo "<div class='alert alert-info'>✓ Année académique créée : {$annee[0]}</div>";
        }
    }
    
    // 2. Créer des demandes d'inscription de test
    $demandes = [
        ['Jean', 'Dupont', 'jean.dupont@test.cg', 'Licence 1 Informatique', 'Brazzaville'],
        ['Marie', 'Curie', 'marie.curie@test.cg', 'BTS 2 Gestion', 'Pointe-Noire'],
        ['Pierre', 'Durand', 'pierre.durand@test.cg', 'Master 1 Droit', 'Ouesso']
    ];
    
    foreach ($demandes as $index => $demande) {
        $numero = 'DEM-' . date('Y') . '-' . str_pad($index+1, 5, '0', STR_PAD_LEFT);
        
        $query = "INSERT INTO demande_inscriptions (
                    site_id, numero_demande, nom, prenom, email, filiere, site_formation,
                    statut, date_demande
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente', NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $index+1, // site_id
            $numero,
            $demande[0],
            $demande[1],
            $demande[2],
            $demande[3],
            $demande[4]
        ]);
        
        echo "<div class='alert alert-info'>✓ Demande d'inscription créée : {$demande[0]} {$demande[1]}</div>";
    }
    
    echo "<div class='alert alert-success'>
            <h4><i class='fas fa-check-circle'></i> Données de test créées avec succès !</h4>
            <p>Vous pouvez maintenant :</p>
            <ol>
                <li><a href='auth/login.php'>Vous connecter</a> avec admin@isgi.cg / admin123</li>
                <li>Accéder au <a href='dashboard/'>dashboard</a></li>
                <li>Voir les <a href='dashboard/admin_principal/dashboard.php'>statistiques</a></li>
                <li>Valider les <a href='dashboard/admin_principal/demandes.php'>demandes d'inscription</a></li>
            </ol>
          </div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Erreur : " . $e->getMessage() . "</div>";
}
?>

<style>
    body { padding: 20px; }
    .alert { padding: 15px; margin: 10px 0; border-radius: 5px; }
</style>