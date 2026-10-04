<?php
/**
 * Assignation simple Matières ↔ Classes - ISGI Congo (Version corrigée contraintes)
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

// Fonction pour récupérer toutes les classes
function getClasses($pdo) {
    $sql = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM classes c
            LEFT JOIN filieres f ON c.filiere_id = f.id
            LEFT JOIN niveaux n ON c.niveau_id = n.id
            LEFT JOIN sites s ON c.site_id = s.id
            ORDER BY c.nom";
    return $pdo->query($sql)->fetchAll();
}

// Fonction pour récupérer toutes les matières
function getMatieres($pdo) {
    $sql = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
            FROM matieres m
            LEFT JOIN filieres f ON m.filiere_id = f.id
            LEFT JOIN niveaux n ON m.niveau_id = n.id
            LEFT JOIN sites s ON m.site_id = s.id
            ORDER BY f.nom, n.ordre, m.code";
    return $pdo->query($sql)->fetchAll();
}

// Fonction pour récupérer les matières d'une classe spécifique
function getMatieresClasse($pdo, $classe_id) {
    // Récupérer les infos de la classe
    $sql = "SELECT c.filiere_id, c.niveau_id, c.site_id 
            FROM classes c 
            WHERE c.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch();
    
    if (!$classe) return [];
    
    // Chercher les matières pour cette filière/niveau/site
    $sql = "SELECT m.* FROM matieres m 
            WHERE m.filiere_id = ? 
            AND m.niveau_id = ? 
            AND m.site_id = ?
            ORDER BY m.code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $classe['filiere_id'], 
        $classe['niveau_id'],
        $classe['site_id']
    ]);
    return $stmt->fetchAll();
}

// Fonction pour assigner une matière à une classe
function assignerMatiere($pdo, $matiere_id, $classe_id) {
    // Récupérer les infos de la matière
    $sql = "SELECT * FROM matieres WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$matiere_id]);
    $matiere = $stmt->fetch();
    
    if (!$matiere) return ['success' => false, 'message' => 'Matière non trouvée'];
    
    // Récupérer les infos de la classe
    $sql = "SELECT * FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch();
    
    if (!$classe) return ['success' => false, 'message' => 'Classe non trouvée'];
    
    // Vérifier si la matière existe déjà pour cette filière/niveau/site
    $sql_check = "SELECT COUNT(*) as count FROM matieres 
                  WHERE code = ? AND filiere_id = ? AND niveau_id = ? AND site_id = ?";
    $stmt = $pdo->prepare($sql_check);
    $stmt->execute([
        $matiere['code'],
        $classe['filiere_id'],
        $classe['niveau_id'],
        $classe['site_id']
    ]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        return ['success' => true, 'message' => 'Matière déjà existante'];
    }
    
    // Créer une copie de la matière pour cette classe
    try {
        $sql_insert = "INSERT INTO matieres (code, nom, credit, coefficient, filiere_id, niveau_id, site_id, enseignant_id) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql_insert);
        $success = $stmt->execute([
            $matiere['code'],
            $matiere['nom'],
            $matiere['credit'],
            $matiere['coefficient'],
            $classe['filiere_id'],
            $classe['niveau_id'],
            $classe['site_id'],
            $matiere['enseignant_id']
        ]);
        
        return [
            'success' => $success,
            'message' => $success ? 'Matière assignée avec succès' : 'Erreur d\'insertion'
        ];
    } catch (PDOException $e) {
        // Si erreur de doublon, on modifie le code
        if (strpos($e->getMessage(), '1062') !== false) {
            // Trouver un code disponible
            $code_base = $matiere['code'];
            $code_final = $code_base;
            $suffixe = 1;
            
            do {
                $code_final = $code_base . '_' . $suffixe;
                $suffixe++;
                
                // Vérifier si ce code existe déjà pour cette filière/niveau/site
                $sql_check = "SELECT COUNT(*) as count FROM matieres 
                              WHERE code = ? AND filiere_id = ? AND niveau_id = ? AND site_id = ?";
                $stmt = $pdo->prepare($sql_check);
                $stmt->execute([
                    $code_final,
                    $classe['filiere_id'],
                    $classe['niveau_id'],
                    $classe['site_id']
                ]);
                $result = $stmt->fetch();
            } while ($result['count'] > 0);
            
            // Réessayer avec le nouveau code
            $stmt = $pdo->prepare($sql_insert);
            $success = $stmt->execute([
                $code_final,
                $matiere['nom'],
                $matiere['credit'],
                $matiere['coefficient'],
                $classe['filiere_id'],
                $classe['niveau_id'],
                $classe['site_id'],
                $matiere['enseignant_id']
            ]);
            
            return [
                'success' => $success,
                'message' => $success ? 'Matière assignée (code modifié)' : 'Erreur',
                'code_modifie' => $code_final
            ];
        }
        
        return ['success' => false, 'message' => 'Erreur : ' . $e->getMessage()];
    }
}

// Fonction pour créer une matière simple
function creerMatiereSimple($pdo, $data) {
    // D'abord essayer avec le code fourni
    $sql = "INSERT INTO matieres (code, nom, credit, coefficient, filiere_id, niveau_id, site_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $data['code'],
            $data['nom'],
            $data['credit'] ?? 3,
            $data['coefficient'] ?? 1,
            $data['filiere_id'],
            $data['niveau_id'],
            $data['site_id']
        ]);
        
        return [
            'success' => $success,
            'code_utilise' => $data['code'],
            'message' => 'Matière créée avec succès'
        ];
    } catch (PDOException $e) {
        // Si erreur de doublon, on modifie le code
        if (strpos($e->getMessage(), '1062') !== false) {
            // Trouver un code disponible
            $code_base = $data['code'];
            $code_final = $code_base;
            $suffixe = 1;
            
            do {
                $code_final = $code_base . '_' . $suffixe;
                $suffixe++;
                
                // Vérifier si ce code existe déjà pour cette filière/niveau/site
                $sql_check = "SELECT COUNT(*) as count FROM matieres 
                              WHERE code = ? AND filiere_id = ? AND niveau_id = ? AND site_id = ?";
                $stmt = $pdo->prepare($sql_check);
                $stmt->execute([
                    $code_final,
                    $data['filiere_id'],
                    $data['niveau_id'],
                    $data['site_id']
                ]);
                $result = $stmt->fetch();
            } while ($result['count'] > 0);
            
            // Réessayer avec le nouveau code
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $code_final,
                $data['nom'],
                $data['credit'] ?? 3,
                $data['coefficient'] ?? 1,
                $data['filiere_id'],
                $data['niveau_id'],
                $data['site_id']
            ]);
            
            return [
                'success' => $success,
                'code_utilise' => $code_final,
                'message' => 'Matière créée (code modifié)'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Erreur : ' . $e->getMessage()
        ];
    }
}

// Fonction pour voir la structure de la table matieres
function getTableStructure($pdo, $table_name) {
    $sql = "DESCRIBE $table_name";
    return $pdo->query($sql)->fetchAll();
}

// Traitement du formulaire
$message = '';
$message_type = '';
$details = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assigner':
                if (isset($_POST['matiere_id']) && isset($_POST['classe_id'])) {
                    $result = assignerMatiere($pdo, $_POST['matiere_id'], $_POST['classe_id']);
                    
                    if ($result['success']) {
                        $message = $result['message'];
                        $message_type = 'success';
                        
                        if (isset($result['code_modifie'])) {
                            $details = "Code modifié en '{$result['code_modifie']}'";
                        }
                    } else {
                        $message = $result['message'];
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'creer_assigner':
                if (!empty($_POST['code']) && !empty($_POST['nom']) && !empty($_POST['classe_id'])) {
                    // Récupérer les infos de la classe
                    $sql = "SELECT filiere_id, niveau_id, site_id FROM classes WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$_POST['classe_id']]);
                    $classe = $stmt->fetch();
                    
                    if ($classe) {
                        $data = [
                            'code' => $_POST['code'],
                            'nom' => $_POST['nom'],
                            'credit' => $_POST['credit'] ?? 3,
                            'coefficient' => $_POST['coefficient'] ?? 1,
                            'filiere_id' => $classe['filiere_id'],
                            'niveau_id' => $classe['niveau_id'],
                            'site_id' => $classe['site_id']
                        ];
                        
                        $result = creerMatiereSimple($pdo, $data);
                        
                        if ($result['success']) {
                            $message = $result['message'];
                            $message_type = 'success';
                            
                            if (isset($result['code_utilise']) && $result['code_utilise'] != $_POST['code']) {
                                $details = "Code utilisé : '{$result['code_utilise']}'";
                            }
                        } else {
                            $message = $result['message'];
                            $message_type = 'danger';
                        }
                    }
                }
                break;
                
            case 'assigner_rapide':
                if (isset($_POST['matiere_id']) && isset($_POST['classes_ids'])) {
                    $success = 0;
                    $total = 0;
                    $erreurs = [];
                    
                    foreach ($_POST['classes_ids'] as $classe_id) {
                        $result = assignerMatiere($pdo, $_POST['matiere_id'], $classe_id);
                        $total++;
                        
                        if ($result['success']) {
                            $success++;
                        } else {
                            $erreurs[] = "Classe ID {$classe_id}: {$result['message']}";
                        }
                    }
                    
                    $message = $success . ' classe(s) assignée(s) sur ' . $total;
                    $message_type = $success > 0 ? 'success' : 'warning';
                    
                    if (!empty($erreurs)) {
                        $details = "Erreurs :<br>" . implode('<br>', $erreurs);
                    }
                }
                break;
                
            case 'ajouter_site_id':
                // Ajouter site_id aux matières existantes si nécessaire
                try {
                    // Vérifier si la colonne site_id existe
                    $structure = getTableStructure($pdo, 'matieres');
                    $has_site_id = false;
                    foreach ($structure as $col) {
                        if ($col['Field'] == 'site_id') {
                            $has_site_id = true;
                            break;
                        }
                    }
                    
                    if ($has_site_id) {
                        // Mettre à jour les matières sans site_id
                        // On va utiliser le site de leur filière/niveau
                        $sql = "UPDATE matieres m 
                                JOIN filieres f ON m.filiere_id = f.id 
                                SET m.site_id = (
                                    SELECT MIN(c.site_id) 
                                    FROM classes c 
                                    WHERE c.filiere_id = m.filiere_id 
                                    AND c.niveau_id = m.niveau_id
                                    LIMIT 1
                                )
                                WHERE m.site_id IS NULL";
                        $updated = $pdo->exec($sql);
                        
                        $message = $updated . ' matières mises à jour avec site_id';
                        $message_type = 'success';
                    }
                } catch (Exception $e) {
                    $message = 'Erreur : ' . $e->getMessage();
                    $message_type = 'danger';
                }
                break;
        }
    }
}

// Récupérer les données
$classes = getClasses($pdo);
$matieres = getMatieres($pdo);

// Classe sélectionnée
$classe_selected = isset($_GET['classe']) ? intval($_GET['classe']) : (isset($classes[0]) ? $classes[0]['id'] : null);
$matieres_classe = $classe_selected ? getMatieresClasse($pdo, $classe_selected) : [];

// Vérifier la structure de la table
$table_structure = getTableStructure($pdo, 'matieres');
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignation Matières/Classes - ISGI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .container { max-width: 1200px; }
        .header { 
            background: linear-gradient(135deg, #6f42c1 0%, #6610f2 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .card { border-radius: 10px; margin-bottom: 15px; }
        .matiere-item { 
            background: white; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px;
        }
        .matiere-assigned { border-left: 4px solid #28a745; background-color: #f8fff8; }
        .classe-card { 
            cursor: pointer; transition: all 0.3s; border: 2px solid transparent;
            padding: 10px; border-radius: 8px; margin-bottom: 10px;
        }
        .classe-card:hover { background-color: #f0f0f0; }
        .classe-card.selected { 
            border-color: #0d6efd; background-color: #e7f1ff; 
        }
        .badge-small { font-size: 0.75em; }
        .details { font-size: 0.9em; color: #666; }
        .table-structure th { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header text-center">
            <h1><i class="bi bi-link"></i> Assignation Matières ↔ Classes</h1>
            <p class="mb-0">Système simple d'assignation - ISGI Congo</p>
            <div class="mt-3">
                <a href="gestion_notes.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Retour aux notes
                </a>
                <a href="?debug=1" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-bug"></i> Debug
                </a>
            </div>
        </div>
        
        <!-- Debug info -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-bug"></i> Information de Debug</h5>
            </div>
            <div class="card-body">
                <h6>Structure de la table 'matieres' :</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-structure">
                        <thead>
                            <tr>
                                <th>Champ</th>
                                <th>Type</th>
                                <th>Null</th>
                                <th>Clé</th>
                                <th>Défaut</th>
                                <th>Extra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($table_structure as $col): ?>
                            <tr>
                                <td><strong><?php echo $col['Field']; ?></strong></td>
                                <td><?php echo $col['Type']; ?></td>
                                <td><?php echo $col['Null']; ?></td>
                                <td><?php echo $col['Key']; ?></td>
                                <td><?php echo $col['Default']; ?></td>
                                <td><?php echo $col['Extra']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mt-3">
                    <h6><i class="bi bi-info-circle"></i> Problème détecté :</h6>
                    <p class="mb-2">La table 'matieres' a une contrainte FOREIGN KEY vers 'sites' (site_id).</p>
                    <p class="mb-0">Solution : Ajouter site_id quand on crée une matière.</p>
                </div>
                
                <form method="POST" action="" class="mt-3">
                    <input type="hidden" name="action" value="ajouter_site_id">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-wrench"></i> Corriger les matières existantes (ajouter site_id)
                    </button>
                </form>
                
                <div class="text-center mt-3">
                    <a href="?" class="btn btn-secondary">Retour à l'assignation</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Message -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <div><strong><?php echo $message; ?></strong></div>
            <?php if ($details): ?>
            <div class="details mt-2"><?php echo $details; ?></div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Colonne gauche : Classes -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Classes disponibles</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($classes)): ?>
                            <div class="alert alert-warning">
                                Aucune classe disponible. Créez d'abord des classes.
                            </div>
                        <?php else: ?>
                            <?php foreach ($classes as $classe): 
                                $matieres_count = count(getMatieresClasse($pdo, $classe['id']));
                            ?>
                            <div class="classe-card <?php echo ($classe_selected == $classe['id']) ? 'selected' : ''; ?>"
                                 onclick="window.location.href='?classe=<?php echo $classe['id']; ?>'">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($classe['nom']); ?></strong>
                                        <div class="small">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($classe['site_nom']); ?></span>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($classe['filiere_nom']); ?></span>
                                            <span class="badge bg-warning"><?php echo htmlspecialchars($classe['niveau_libelle']); ?></span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $matieres_count > 0 ? 'success' : 'danger'; ?>">
                                            <?php echo $matieres_count; ?> matière(s)
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Assignation rapide -->
                <div class="card mt-3">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Assignation rapide</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="assigner_rapide">
                            <div class="mb-3">
                                <label class="form-label">Sélectionner une matière :</label>
                                <select name="matiere_id" class="form-select" required>
                                    <option value="">-- Choisir une matière --</option>
                                    <?php foreach ($matieres as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>">
                                            <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                            (<?php echo htmlspecialchars($matiere['filiere_nom'] . ' ' . $matiere['niveau_libelle']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Sélectionner les classes :</label>
                                <div style="max-height: 150px; overflow-y: auto;" class="border p-2 rounded">
                                    <?php foreach ($classes as $classe): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                               name="classes_ids[]" value="<?php echo $classe['id']; ?>"
                                               id="classe_<?php echo $classe['id']; ?>">
                                        <label class="form-check-label small" for="classe_<?php echo $classe['id']; ?>">
                                            <?php echo htmlspecialchars($classe['nom']); ?>
                                            (<?php echo htmlspecialchars($classe['site_nom']); ?>)
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-check-circle"></i> Assigner aux classes sélectionnées
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Info -->
                <div class="card mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Information</h6>
                    </div>
                    <div class="card-body small">
                        <p class="mb-0">
                            <strong>Note :</strong> Les matières sont liées à :
                            <ul class="mb-0">
                                <li>Filière</li>
                                <li>Niveau</li>
                                <li>Site</li>
                            </ul>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Colonne droite : Matières de la classe -->
            <div class="col-md-8">
                <?php if ($classe_selected): 
                    $classe_actuelle = null;
                    foreach ($classes as $c) {
                        if ($c['id'] == $classe_selected) {
                            $classe_actuelle = $c;
                            break;
                        }
                    }
                ?>
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-book"></i> 
                                    Matières de : <?php echo htmlspecialchars($classe_actuelle['nom']); ?>
                                </h5>
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <?php echo htmlspecialchars($classe_actuelle['site_nom']); ?> - 
                                        <?php echo htmlspecialchars($classe_actuelle['filiere_nom']); ?> - 
                                        <?php echo htmlspecialchars($classe_actuelle['niveau_libelle']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($matieres_classe)): ?>
                                <div class="alert alert-warning text-center py-4">
                                    <i class="bi bi-exclamation-triangle fs-4"></i>
                                    <h5 class="mt-3">Cette classe n'a aucune matière !</h5>
                                    <p>Les étudiants ne pourront pas recevoir de notes.</p>
                                    <div class="mt-3">
                                        <a href="?debug=1" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-bug"></i> Vérifier les problèmes
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($matieres_classe as $matiere): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="matiere-item matiere-assigned">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <strong><?php echo htmlspecialchars($matiere['code']); ?></strong>
                                                    </h6>
                                                    <p class="mb-1"><?php echo htmlspecialchars($matiere['nom']); ?></p>
                                                    <div>
                                                        <span class="badge bg-primary badge-small">Crédit: <?php echo $matiere['credit']; ?></span>
                                                        <span class="badge bg-info badge-small">Coeff: <?php echo $matiere['coefficient']; ?></span>
                                                    </div>
                                                </div>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check"></i> Assignée
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Formulaire pour assigner une matière -->
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Ajouter une matière à cette classe</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" class="row g-3">
                                        <input type="hidden" name="action" value="assigner">
                                        <input type="hidden" name="classe_id" value="<?php echo $classe_selected; ?>">
                                        
                                        <div class="col-md-8">
                                            <label class="form-label">Choisir une matière existante :</label>
                                            <select name="matiere_id" class="form-select">
                                                <option value="">-- Sélectionner une matière --</option>
                                                <?php foreach ($matieres as $matiere): ?>
                                                    <option value="<?php echo $matiere['id']; ?>">
                                                        <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                                        (<?php echo htmlspecialchars($matiere['filiere_nom'] . ' ' . $matiere['niveau_libelle'] . ' - ' . $matiere['site_nom']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-link"></i> Assigner
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <hr>
                                    
                                    <!-- Créer une nouvelle matière -->
                                    <h6 class="mt-3">Ou créer une nouvelle matière :</h6>
                                    <form method="POST" action="" class="row g-3">
                                        <input type="hidden" name="action" value="creer_assigner">
                                        <input type="hidden" name="classe_id" value="<?php echo $classe_selected; ?>">
                                        
                                        <div class="col-md-3">
                                            <input type="text" name="code" class="form-control" 
                                                   placeholder="Code (ex: MATH101)" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" name="nom" class="form-control" 
                                                   placeholder="Nom de la matière" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="credit" class="form-control" 
                                                   placeholder="Crédit" value="3" min="1" max="10" step="0.5">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="coefficient" class="form-control" 
                                                   placeholder="Coeff" value="1" min="0.5" max="5" step="0.5">
                                        </div>
                                        <div class="col-md-1">
                                            <button type="submit" class="btn btn-success w-100">
                                                <i class="bi bi-plus"></i> Créer
                                            </button>
                                        </div>
                                        <div class="col-12">
                                            <small class="text-muted">
                                                La matière sera créée pour : 
                                                <strong><?php echo htmlspecialchars($classe_actuelle['site_nom']); ?> - 
                                                <?php echo htmlspecialchars($classe_actuelle['filiere_nom']); ?> - 
                                                <?php echo htmlspecialchars($classe_actuelle['niveau_libelle']); ?></strong>
                                            </small>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-mouse fs-1 text-muted"></i>
                            <h5 class="mt-3">Sélectionnez une classe</h5>
                            <p class="text-muted">Cliquez sur une classe à gauche pour voir ses matières</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Résumé -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Statistiques</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <h3><?php echo count($classes); ?></h3>
                                <p class="text-muted mb-0">Classes</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3><?php echo count($matieres); ?></h3>
                                <p class="text-muted mb-0">Matières totales</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <?php
                                $classes_avec_matieres = 0;
                                foreach ($classes as $classe) {
                                    if (count(getMatieresClasse($pdo, $classe['id'])) > 0) {
                                        $classes_avec_matieres++;
                                    }
                                }
                                ?>
                                <h3><?php echo $classes_avec_matieres; ?></h3>
                                <p class="text-muted mb-0">Classes avec matières</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3><?php echo count($classes) - $classes_avec_matieres; ?></h3>
                                <p class="text-muted mb-0">Classes sans matières</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple confirmation pour les assignations
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (this.querySelector('button[type="submit"]').textContent.includes('Assigner')) {
                        if (!confirm('Confirmer l\'assignation ?')) {
                            e.preventDefault();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>