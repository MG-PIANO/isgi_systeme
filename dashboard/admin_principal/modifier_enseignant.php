<?php
// dashboard/admin_principal/modifier_enseignant.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    $db = Database::getInstance()->getConnection();
    $pageTitle = "Modifier un Enseignant";
    
    // Vérifier si l'ID est fourni
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        header('Location: enseignants.php?error=ID invalide');
        exit();
    }
    
    $enseignant_id = intval($_GET['id']);
    
    // Récupérer les données de l'enseignant
    $query = "SELECT e.*, u.nom, u.prenom, u.email, u.telephone 
              FROM enseignants e 
              JOIN utilisateurs u ON e.utilisateur_id = u.id 
              WHERE e.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$enseignant_id]);
    $enseignant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enseignant) {
        header('Location: enseignants.php?error=Enseignant non trouvé');
        exit();
    }
    
    // Récupérer les sites
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les matières assignées à cet enseignant
    $matieres_assignees = $db->query("SELECT m.id, m.nom, m.code FROM matieres_enseignants me JOIN matieres m ON me.matiere_id = m.id WHERE me.enseignant_id = " . $enseignant_id)->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer toutes les matières disponibles
    $matieres_disponibles = $db->query("SELECT * FROM matieres WHERE id NOT IN (SELECT matiere_id FROM matieres_enseignants WHERE enseignant_id != " . $enseignant_id . ") ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 800px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">
                    <i class="fas fa-edit me-2"></i>
                    Modifier l'Enseignant
                </h3>
            </div>
            <div class="card-body">
                <!-- Formulaire de modification -->
                <form method="POST" action="traitement_modification.php">
                    <input type="hidden" name="enseignant_id" value="<?php echo $enseignant_id; ?>">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control" name="nom" value="<?php echo htmlspecialchars($enseignant['nom']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prénom</label>
                            <input type="text" class="form-control" name="prenom" value="<?php echo htmlspecialchars($enseignant['prenom']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($enseignant['email']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="telephone" value="<?php echo htmlspecialchars($enseignant['telephone'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Matricule</label>
                            <input type="text" class="form-control" name="matricule" value="<?php echo htmlspecialchars($enseignant['matricule']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Grade</label>
                            <select class="form-select" name="grade" required>
                                <option value="PA" <?php echo $enseignant['grade'] == 'PA' ? 'selected' : ''; ?>>PA</option>
                                <option value="PH" <?php echo $enseignant['grade'] == 'PH' ? 'selected' : ''; ?>>PH</option>
                                <option value="PES" <?php echo $enseignant['grade'] == 'PES' ? 'selected' : ''; ?>>PES</option>
                                <option value="Vacataire" <?php echo $enseignant['grade'] == 'Vacataire' ? 'selected' : ''; ?>>Vacataire</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Spécialité</label>
                            <input type="text" class="form-control" name="specialite" value="<?php echo htmlspecialchars($enseignant['specialite'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Site</label>
                            <select class="form-select" name="site_id">
                                <option value="">Sélectionner un site</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo $enseignant['site_id'] == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Statut</label>
                            <select class="form-select" name="statut">
                                <option value="actif" <?php echo $enseignant['statut'] == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                <option value="retraite" <?php echo $enseignant['statut'] == 'retraite' ? 'selected' : ''; ?>>Retraité</option>
                                <option value="demission" <?php echo $enseignant['statut'] == 'demission' ? 'selected' : ''; ?>>Démission</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date d'embauche</label>
                            <input type="date" class="form-control" name="date_embauche" value="<?php echo $enseignant['date_embauche'] ?? date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="professeurs.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>