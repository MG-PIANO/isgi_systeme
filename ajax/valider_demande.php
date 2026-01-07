<?php
// ajax/valider_demande.php
require_once '../config/database.php';

session_start();

// Vérifier l'authentification
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit();
}

// Vérifier les permissions (seulement admin principal ou admin site)
$userId = $_SESSION['user_id'];
$roleId = $_SESSION['role_id'];

if (!in_array($roleId, [1, 2, 3])) { // Admin principal, Admin site, Gestionnaire
    echo json_encode(['success' => false, 'message' => 'Permission refusée']);
    exit();
}

// Récupérer l'ID de la demande
$demandeId = $_GET['id'] ?? 0;

if (!$demandeId) {
    echo json_encode(['success' => false, 'message' => 'ID de demande invalide']);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();
    
    // 1. Récupérer la demande
    $query = "SELECT * FROM demande_inscriptions WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$demandeId]);
    $demande = $stmt->fetch();
    
    if (!$demande) {
        throw new Exception("Demande non trouvée");
    }
    
    // 2. Vérifier les permissions par site
    if ($roleId != 1) { // Pas admin principal
        $userSiteId = $_SESSION['site_id'];
        if ($demande['site_id'] != $userSiteId) {
            throw new Exception("Vous ne pouvez valider que les demandes de votre site");
        }
    }
    
    // 3. Mettre à jour la demande
    $query = "UPDATE demande_inscriptions 
              SET statut = 'validee',
                  validee_par = ?,
                  date_validation = NOW()
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $demandeId]);
    
    // 4. Créer l'utilisateur étudiant
    // Générer un mot de passe temporaire
    $password = generatePassword(8);
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Générer un matricule
    $matricule = generateMatricule();
    
    // Créer l'utilisateur
    $query = "INSERT INTO utilisateurs (role_id, site_id, email, mot_de_passe, nom, prenom, telephone, statut)
              VALUES (8, ?, ?, ?, ?, ?, ?, 'actif')";
    $stmt = $db->prepare($query);
    $stmt->execute([
        $demande['site_id'],
        $demande['email'],
        $hashedPassword,
        $demande['nom'],
        $demande['prenom'],
        $demande['telephone']
    ]);
    
    $userIdCreated = $db->lastInsertId();
    
    // 5. Créer l'étudiant
    $query = "INSERT INTO etudiants (utilisateur_id, site_id, matricule, numero_cni, 
              date_naissance, lieu_naissance, sexe, nationalite, adresse, ville, pays,
              profession, situation_matrimoniale, nom_pere, profession_pere, nom_mere,
              profession_mere, telephone_parent, nom_tuteur, profession_tuteur,
              telephone_tuteur, lieu_service_tuteur, statut)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        $userIdCreated,
        $demande['site_id'],
        $matricule,
        $demande['numero_cni'],
        $demande['date_naissance'],
        $demande['lieu_naissance'],
        $demande['sexe'],
        $demande['nationalite'],
        $demande['adresse'],
        $demande['ville'],
        $demande['pays'],
        $demande['profession'],
        $demande['situation_matrimoniale'],
        $demande['nom_pere'],
        $demande['profession_pere'],
        $demande['nom_mere'],
        $demande['profession_mere'],
        $demande['telephone_parent'],
        $demande['nom_tuteur'],
        $demande['profession_tuteur'],
        $demande['telephone_tuteur'],
        $demande['lieu_service_tuteur']
    ]);
    
    $db->commit();
    
    // 6. Envoyer un email de bienvenue (simulation)
    // sendEmail($demande['email'], 'Bienvenue à ISGI', 'Votre compte a été créé...');
    
    echo json_encode([
        'success' => true,
        'message' => 'Demande validée et compte étudiant créé',
        'matricule' => $matricule,
        'password' => $password // À supprimer en production
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    logError("Erreur validation demande: " . $e->getMessage(), ['demande_id' => $demandeId]);
    
    echo json_encode([
        'success' => false,
        'message' => 'Erreur: ' . $e->getMessage()
    ]);
}
?>