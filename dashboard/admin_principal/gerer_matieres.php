<?php
// dashboard/admin_principal/gerer_matieres.php

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
    $pageTitle = "Gérer les Matières d'un Enseignant";
    
    // Vérifier si l'ID est fourni
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        header('Location: enseignants.php?error=ID invalide');
        exit();
    }
    
    $enseignant_id = intval($_GET['id']);
    
    // Récupérer les informations de l'enseignant
    $query = "SELECT e.*, u.nom, u.prenom 
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
    
    // Récupérer les matières assignées
    $matieres_assignees = $db->query("SELECT m.id, m.nom, m.code FROM matieres_enseignants me JOIN matieres m ON me.matiere_id = m.id WHERE me.enseignant_id = " . $enseignant_id)->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les matières disponibles
    $matieres_disponibles = $db->query("SELECT * FROM matieres WHERE id NOT IN (SELECT matiere_id FROM matieres_enseignants) OR id IN (SELECT matiere_id FROM matieres_enseignants WHERE enseignant_id = " . $enseignant_id . ") ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement de l'ajout/suppression de matières
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['matieres_ids'])) {
            // Supprimer toutes les matières actuelles
            $db->query("DELETE FROM matieres_enseignants WHERE enseignant_id = " . $enseignant_id);
            $db->query("UPDATE matieres SET enseignant_id = NULL WHERE enseignant_id = " . $enseignant_id);
            
            // Ajouter les nouvelles matières
            foreach ($_POST['matieres_ids'] as $matiere_id) {
                $matiere_id = intval($matiere_id);
                if ($matiere_id > 0) {
                    // Ajouter à matieres_enseignants
                    $db->query("INSERT INTO matieres_enseignants (enseignant_id, matiere_id) VALUES ($enseignant_id, $matiere_id)");
                    // Mettre à jour la table matieres
                    $db->query("UPDATE matieres SET enseignant_id = $enseignant_id WHERE id = $matiere_id");
                }
            }
            
            header("Location: gerer_matieres.php?id=$enseignant_id&success=Matières mises à jour avec succès");
            exit();
        }
    }
    
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
            max-width: 900px;
        }
        .matiere-item {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
            background-color: white;
        }
        .badge-custom {
            font-size: 0.9em;
            padding: 5px 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">
                    <i class="fas fa-book me-2"></i>
                    Gérer les Matières de <?php echo htmlspecialchars($enseignant['prenom'] . ' ' . $enseignant['nom']); ?>
                </h3>
            </div>
            <div class="card-body">
                <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Cet enseignant est actuellement assigné à <strong><?php echo count($matieres_assignees); ?></strong> matière(s)
                </div>
                
                <form method="POST" action="">
                    <div class="mb-4">
                        <h5>Sélectionner les matières enseignées</h5>
                        <div class="row">
                            <?php foreach($matieres_disponibles as $matiere): 
                                $is_assigned = false;
                                foreach($matieres_assignees as $assigned) {
                                    if ($assigned['id'] == $matiere['id']) {
                                        $is_assigned = true;
                                        break;
                                    }
                                }
                            ?>
                            <div class="col-md-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="matieres_ids[]" 
                                           value="<?php echo $matiere['id']; ?>" 
                                           id="matiere_<?php echo $matiere['id']; ?>"
                                           <?php echo $is_assigned ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="matiere_<?php echo $matiere['id']; ?>">
                                        <?php echo htmlspecialchars($matiere['nom']); ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($matiere['code']); ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="professeurs.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour à la liste
                        </a>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Enregistrer les affectations
                            </button>
                            <a href="modifier_enseignant.php?id=<?php echo $enseignant_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit me-2"></i>Modifier l'enseignant
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Liste des matières actuelles -->
                <div class="mt-5">
                    <h5>Matières actuellement enseignées</h5>
                    <?php if(empty($matieres_assignees)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Cet enseignant n'est actuellement assigné à aucune matière.
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach($matieres_assignees as $matiere): ?>
                        <div class="col-md-4">
                            <div class="matiere-item">
                                <strong><?php echo htmlspecialchars($matiere['nom']); ?></strong>
                                <br>
                                <small class="text-muted">Code: <?php echo htmlspecialchars($matiere['code']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>