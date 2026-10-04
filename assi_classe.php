<?php
/**
 * Lier les étudiants aux classes - ISGI Congo (Version corrigée)
 */

session_start();

// Connexion à la base de données
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4",
        "root",
        "admin1234",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Fonction pour récupérer les étudiants sans classe
function getEtudiantsSansClasse($pdo) {
    $sql = "SELECT e.*, s.nom as site_nom 
            FROM etudiants e
            LEFT JOIN sites s ON e.site_id = s.id
            WHERE e.classe_id IS NULL AND e.statut = 'actif'
            ORDER BY e.nom, e.prenom";
    return $pdo->query($sql)->fetchAll();
}

// Fonction pour récupérer les étudiants avec classe
function getEtudiantsAvecClasse($pdo) {
    $sql = "SELECT e.*, c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            LEFT JOIN filieres f ON c.filiere_id = f.id
            LEFT JOIN niveaux n ON c.niveau_id = n.id
            LEFT JOIN sites s ON e.site_id = s.id
            WHERE e.statut = 'actif'
            ORDER BY c.nom, e.nom";
    return $pdo->query($sql)->fetchAll();
}

// Fonction pour récupérer toutes les classes
function getClasses($pdo) {
    $sql = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM classes c
            LEFT JOIN filieres f ON c.filiere_id = f.id
            LEFT JOIN niveaux n ON c.niveau_id = n.id
            LEFT JOIN sites s ON c.site_id = s.id
            ORDER BY c.site_id, c.niveau_id, c.nom";
    return $pdo->query($sql)->fetchAll();
}

// Fonction pour assigner un étudiant à une classe
function assignerEtudiantClasse($pdo, $etudiant_id, $classe_id) {
    $sql = "UPDATE etudiants SET classe_id = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$classe_id, $etudiant_id]);
}

// Fonction pour assigner plusieurs étudiants à une classe
function assignerEtudiantsClasse($pdo, $etudiants_ids, $classe_id) {
    if (empty($etudiants_ids)) return false;
    
    $placeholders = str_repeat('?,', count($etudiants_ids) - 1) . '?';
    $sql = "UPDATE etudiants SET classe_id = ? WHERE id IN ($placeholders)";
    
    $params = array_merge([$classe_id], $etudiants_ids);
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// Fonction pour désassigner un étudiant
function desassignerEtudiant($pdo, $etudiant_id) {
    $sql = "UPDATE etudiants SET classe_id = NULL WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$etudiant_id]);
}

// Fonction pour échapper les valeurs nulles
function escape($value) {
    return $value !== null ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assigner':
                if (isset($_POST['etudiant_id']) && isset($_POST['classe_id'])) {
                    if (assignerEtudiantClasse($pdo, $_POST['etudiant_id'], $_POST['classe_id'])) {
                        $message = 'Étudiant assigné à la classe avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors de l\'assignation';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'assigner_groupe':
                if (isset($_POST['etudiants']) && isset($_POST['classe_id_groupe'])) {
                    $etudiants_ids = array_map('intval', $_POST['etudiants']);
                    if (assignerEtudiantsClasse($pdo, $etudiants_ids, $_POST['classe_id_groupe'])) {
                        $message = count($etudiants_ids) . ' étudiant(s) assigné(s) avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors de l\'assignation groupée';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'desassigner':
                if (isset($_POST['etudiant_id'])) {
                    if (desassignerEtudiant($pdo, $_POST['etudiant_id'])) {
                        $message = 'Étudiant retiré de la classe avec succès!';
                        $message_type = 'success';
                    } else {
                        $message = 'Erreur lors du retrait';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'assignation_auto':
                // Assignation automatique basée sur le site
                $etudiants = getEtudiantsSansClasse($pdo);
                $classes = getClasses($pdo);
                
                $success = 0;
                foreach ($etudiants as $etudiant) {
                    // Trouver une classe du même site
                    foreach ($classes as $classe) {
                        if ($classe['site_id'] == $etudiant['site_id']) {
                            if (assignerEtudiantClasse($pdo, $etudiant['id'], $classe['id'])) {
                                $success++;
                            }
                            break;
                        }
                    }
                }
                
                if ($success > 0) {
                    $message = $success . ' étudiant(s) assigné(s) automatiquement!';
                    $message_type = 'success';
                } else {
                    $message = 'Aucun étudiant assigné automatiquement';
                    $message_type = 'warning';
                }
                break;
        }
    }
}

// Récupérer les données
$etudiants_sans_classe = getEtudiantsSansClasse($pdo);
$etudiants_avec_classe = getEtudiantsAvecClasse($pdo);
$classes = getClasses($pdo);

// Statistiques
$total_etudiants = count($etudiants_sans_classe) + count($etudiants_avec_classe);
$pourcentage_sans_classe = $total_etudiants > 0 ? round((count($etudiants_sans_classe) / $total_etudiants) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignation Étudiants ↔ Classes - ISGI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .container { max-width: 1400px; }
        .header { 
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .card { border-radius: 10px; margin-bottom: 20px; }
        .etudiant-item { 
            background: white; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px;
        }
        .etudiant-assigne { border-left: 4px solid #28a745; background-color: #f8fff8; }
        .etudiant-sans-classe { border-left: 4px solid #dc3545; background-color: #fff8f8; }
        .classe-item { 
            background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px; cursor: pointer;
            transition: all 0.3s;
        }
        .classe-item:hover { background: #e9ecef; transform: translateY(-2px); }
        .classe-selected { border: 2px solid #0d6efd; background: #e7f1ff; }
        .badge-small { font-size: 0.75em; }
        .stat-card { text-align: center; padding: 15px; }
        .progress { height: 10px; }
        .empty-text { color: #6c757d; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header text-center">
            <h1><i class="bi bi-people"></i> Assignation Étudiants ↔ Classes</h1>
            <p class="mb-0">Lier les étudiants aux classes pour la saisie des notes</p>
            <div class="mt-3">
                <a href="gestion_notes.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Retour aux notes
                </a>
                <a href="assignation.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-book"></i> Assigner les matières
                </a>
            </div>
        </div>
        
        <!-- Message -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body stat-card">
                        <h1><?php echo $total_etudiants; ?></h1>
                        <p class="mb-0">Étudiants totaux</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body stat-card">
                        <h1><?php echo count($etudiants_avec_classe); ?></h1>
                        <p class="mb-0">Avec classe</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-danger">
                    <div class="card-body stat-card">
                        <h1><?php echo count($etudiants_sans_classe); ?></h1>
                        <p class="mb-0">Sans classe</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body stat-card">
                        <h1><?php echo count($classes); ?></h1>
                        <p class="mb-0">Classes disponibles</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Barre de progression -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Étudiants assignés : <?php echo count($etudiants_avec_classe); ?> / <?php echo $total_etudiants; ?></span>
                    <span><?php echo $pourcentage_sans_classe; ?>% sans classe</span>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-success" style="width: <?php echo (100 - $pourcentage_sans_classe); ?>%">
                        <?php echo (100 - $pourcentage_sans_classe); ?>%
                    </div>
                    <div class="progress-bar bg-danger" style="width: <?php echo $pourcentage_sans_classe; ?>%">
                        <?php echo $pourcentage_sans_classe; ?>%
                    </div>
                </div>
                <div class="text-center mt-2">
                    <form method="POST" action="" class="d-inline">
                        <input type="hidden" name="action" value="assignation_auto">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-robot"></i> Assignation automatique
                        </button>
                    </form>
                    <small class="text-muted ms-2">(Assigne les étudiants aux classes du même site)</small>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Colonne gauche : Étudiants sans classe -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-exclamation-triangle"></i> 
                                Étudiants sans classe (<?php echo count($etudiants_sans_classe); ?>)
                            </h5>
                            <?php if (count($etudiants_sans_classe) > 0): ?>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#modalAssignerGroupe">
                                <i class="bi bi-check-all"></i> Assigner en groupe
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($etudiants_sans_classe)): ?>
                            <div class="alert alert-success text-center">
                                <i class="bi bi-check-circle fs-4"></i>
                                <h5 class="mt-3">Tous les étudiants ont une classe !</h5>
                                <p class="mb-0">Vous pouvez retourner à la saisie des notes.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="formSansClasse">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th width="50">
                                                    <input type="checkbox" id="selectAllSansClasse" class="form-check-input">
                                                </th>
                                                <th>Matricule</th>
                                                <th>Nom & Prénom</th>
                                                <th>Site</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($etudiants_sans_classe as $etudiant): ?>
                                            <tr class="table-danger">
                                                <td>
                                                    <input type="checkbox" name="etudiants[]" value="<?php echo $etudiant['id']; ?>" 
                                                           class="form-check-input etudiant-check">
                                                </td>
                                                <td><strong><?php echo escape($etudiant['matricule']); ?></strong></td>
                                                <td>
                                                    <?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($etudiant['site_nom'])): ?>
                                                    <span class="badge bg-info"><?php echo escape($etudiant['site_nom']); ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Non spécifié</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="modal" data-bs-target="#modalAssigner"
                                                            data-etudiant-id="<?php echo $etudiant['id']; ?>"
                                                            data-etudiant-nom="<?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?>"
                                                            data-site-id="<?php echo $etudiant['site_id']; ?>">
                                                        <i class="bi bi-person-plus"></i> Assigner
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Classes disponibles -->
                <div class="card mt-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Classes disponibles</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($classes)): ?>
                            <div class="alert alert-warning">
                                Aucune classe disponible. Créez d'abord des classes.
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($classes as $classe): 
                                    // Compter les étudiants dans cette classe
                                    $sql = "SELECT COUNT(*) as count FROM etudiants WHERE classe_id = ? AND statut = 'actif'";
                                    $stmt = $pdo->prepare($sql);
                                    $stmt->execute([$classe['id']]);
                                    $result = $stmt->fetch();
                                    $etudiants_count = $result['count'];
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="classe-item" 
                                         onclick="utiliserClasse(<?php echo $classe['id']; ?>, '<?php echo escape($classe['nom']); ?>')"
                                         id="classe-<?php echo $classe['id']; ?>">
                                        <h6 class="mb-1"><?php echo escape($classe['nom']); ?></h6>
                                        <div class="small mb-2">
                                            <span class="badge bg-info"><?php echo escape($classe['site_nom']); ?></span>
                                            <span class="badge bg-success"><?php echo escape($classe['filiere_nom']); ?></span>
                                            <span class="badge bg-warning"><?php echo escape($classe['niveau_libelle']); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge bg-<?php echo $etudiants_count > 0 ? 'primary' : 'secondary'; ?>">
                                                <?php echo $etudiants_count; ?> étudiant(s)
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-success"
                                                    onclick="assignerClasse(<?php echo $classe['id']; ?>)">
                                                <i class="bi bi-check"></i> Utiliser
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Colonne droite : Étudiants avec classe -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-check-circle"></i> 
                            Étudiants avec classe (<?php echo count($etudiants_avec_classe); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($etudiants_avec_classe)): ?>
                            <div class="alert alert-warning text-center">
                                <i class="bi bi-info-circle fs-4"></i>
                                <h5 class="mt-3">Aucun étudiant avec classe</h5>
                                <p class="mb-0">Assignez des étudiants aux classes à gauche.</p>
                            </div>
                        <?php else: ?>
                            <!-- Filtrer par classe -->
                            <div class="mb-3">
                                <label class="form-label">Filtrer par classe :</label>
                                <select class="form-select" onchange="filtrerParClasse(this.value)">
                                    <option value="">Toutes les classes</option>
                                    <?php
                                    $classes_unique = [];
                                    foreach ($etudiants_avec_classe as $etudiant) {
                                        if ($etudiant['classe_nom']) {
                                            $classes_unique[$etudiant['classe_id']] = $etudiant['classe_nom'];
                                        }
                                    }
                                    foreach ($classes_unique as $id => $nom): ?>
                                        <option value="<?php echo $id; ?>"><?php echo escape($nom); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Matricule</th>
                                            <th>Nom & Prénom</th>
                                            <th>Classe</th>
                                            <th>Filière/Niveau</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="etudiantsAvecClasse">
                                        <?php foreach ($etudiants_avec_classe as $etudiant): ?>
                                        <tr class="table-success" data-classe-id="<?php echo $etudiant['classe_id']; ?>">
                                            <td><strong><?php echo escape($etudiant['matricule']); ?></strong></td>
                                            <td><?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
                                            <td>
                                                <?php if (!empty($etudiant['classe_nom'])): ?>
                                                <span class="badge bg-primary"><?php echo escape($etudiant['classe_nom']); ?></span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">Non spécifié</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php 
                                                    $filiere = !empty($etudiant['filiere_nom']) ? escape($etudiant['filiere_nom']) : 'Non spécifié';
                                                    $niveau = !empty($etudiant['niveau_libelle']) ? escape($etudiant['niveau_libelle']) : 'Non spécifié';
                                                    echo $filiere . ' • ' . $niveau;
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="action" value="desassigner">
                                                    <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                            onclick="return confirm('Retirer cet étudiant de la classe ?')">
                                                        <i class="bi bi-x-circle"></i> Retirer
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Statistiques par classe -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Répartition par classe</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $total_etudiants_classe = count($etudiants_avec_classe);
                        $classes_stats = [];
                        
                        // Compter les étudiants par classe
                        foreach ($etudiants_avec_classe as $etudiant) {
                            if ($etudiant['classe_id']) {
                                if (!isset($classes_stats[$etudiant['classe_id']])) {
                                    $classes_stats[$etudiant['classe_id']] = [
                                        'nom' => $etudiant['classe_nom'] ?? 'Non spécifié',
                                        'site' => $etudiant['site_nom'] ?? 'Non spécifié',
                                        'filiere' => $etudiant['filiere_nom'] ?? 'Non spécifié',
                                        'niveau' => $etudiant['niveau_libelle'] ?? 'Non spécifié',
                                        'count' => 0
                                    ];
                                }
                                $classes_stats[$etudiant['classe_id']]['count']++;
                            }
                        }
                        
                        if (empty($classes_stats)):
                        ?>
                            <div class="alert alert-info text-center">
                                Aucune statistique disponible
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Classe</th>
                                            <th>Site</th>
                                            <th>Filière/Niveau</th>
                                            <th>Étudiants</th>
                                            <th>Pourcentage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classes_stats as $classe): 
                                            $pourcentage = $total_etudiants_classe > 0 ? 
                                                round(($classe['count'] / $total_etudiants_classe) * 100) : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo escape($classe['nom']); ?></strong></td>
                                            <td><?php echo escape($classe['site']); ?></td>
                                            <td>
                                                <?php echo escape($classe['filiere']); ?> / 
                                                <?php echo escape($classe['niveau']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $classe['count']; ?></span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $pourcentage; ?>%"></div>
                                                </div>
                                                <small><?php echo $pourcentage; ?>%</small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour assigner un étudiant -->
    <div class="modal fade" id="modalAssigner" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="assigner">
                    <input type="hidden" name="etudiant_id" id="modalEtudiantId">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-person-plus"></i> Assigner un étudiant</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Étudiant</label>
                            <input type="text" class="form-control" id="modalEtudiantNom" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sélectionner une classe</label>
                            <select name="classe_id" class="form-select" required>
                                <option value="">-- Choisir une classe --</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo escape($classe['nom']); ?> 
                                        (<?php echo escape($classe['site_nom']); ?> - 
                                         <?php echo escape($classe['filiere_nom']); ?> - 
                                         <?php echo escape($classe['niveau_libelle']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Assigner</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour assigner en groupe -->
    <div class="modal fade" id="modalAssignerGroupe" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="assigner_groupe">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-people-fill"></i> Assignation groupée</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Les étudiants sélectionnés seront assignés à la même classe.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sélectionner une classe</label>
                            <select name="classe_id_groupe" class="form-select" required>
                                <option value="">-- Choisir une classe --</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo escape($classe['nom']); ?> 
                                        (<?php echo escape($classe['site_nom']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Étudiants sélectionnés (<span id="countSelected">0</span>)</label>
                            <div id="selectedEtudiants" class="border p-2 rounded" style="max-height: 200px; overflow-y: auto;">
                                <!-- Liste des étudiants sélectionnés -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Assigner les étudiants sélectionnés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sélectionner/désélectionner tous les étudiants sans classe
        document.getElementById('selectAllSansClasse').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.etudiant-check');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedEtudiants();
        });
        
        // Mettre à jour la liste des étudiants sélectionnés
        function updateSelectedEtudiants() {
            const checkboxes = document.querySelectorAll('.etudiant-check:checked');
            const container = document.getElementById('selectedEtudiants');
            const countSpan = document.getElementById('countSelected');
            
            countSpan.textContent = checkboxes.length;
            container.innerHTML = '';
            
            if (checkboxes.length === 0) {
                container.innerHTML = '<div class="empty-text">Aucun étudiant sélectionné</div>';
                return;
            }
            
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const nom = row.cells[2].textContent.trim();
                const matricule = row.cells[1].textContent.trim();
                
                const div = document.createElement('div');
                div.className = 'small mb-1';
                div.textContent = matricule + ' - ' + nom;
                container.appendChild(div);
            });
        }
        
        // Écouter les changements sur les checkboxes
        document.querySelectorAll('.etudiant-check').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedEtudiants);
        });
        
        // Modal d'assignation individuelle
        const modalAssigner = document.getElementById('modalAssigner');
        modalAssigner.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const etudiantId = button.getAttribute('data-etudiant-id');
            const etudiantNom = button.getAttribute('data-etudiant-nom');
            
            document.getElementById('modalEtudiantId').value = etudiantId;
            document.getElementById('modalEtudiantNom').value = etudiantNom;
        });
        
        // Utiliser une classe pour assignation
        function assignerClasse(classeId) {
            const modal = new bootstrap.Modal(document.getElementById('modalAssignerGroupe'));
            modal.show();
            
            // Pré-sélectionner la classe
            document.querySelector('select[name="classe_id_groupe"]').value = classeId;
        }
        
        // Surligner la classe sélectionnée
        function utiliserClasse(classeId, nomClasse) {
            // Retirer la sélection précédente
            document.querySelectorAll('.classe-item').forEach(item => {
                item.classList.remove('classe-selected');
            });
            
            // Ajouter la sélection
            const element = document.getElementById('classe-' + classeId);
            if (element) {
                element.classList.add('classe-selected');
            }
            
            // Remplir le champ dans le modal d'assignation groupée
            document.querySelector('select[name="classe_id_groupe"]').value = classeId;
        }
        
        // Filtrer les étudiants par classe
        function filtrerParClasse(classeId) {
            const rows = document.querySelectorAll('#etudiantsAvecClasse tr');
            
            rows.forEach(row => {
                if (!classeId || row.getAttribute('data-classe-id') == classeId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Initialiser
        document.addEventListener('DOMContentLoaded', function() {
            // Préparer la liste des étudiants sélectionnés
            updateSelectedEtudiants();
        });
    </script>
</body>
</html>