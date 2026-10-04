<?php
/**
 * Saisie des Notes - ISGI Congo
 * Version améliorée - Saisie par matière et examen
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

// Fonction pour échapper les valeurs
function escape($value) {
    return $value !== null ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
}

// Fonction pour vérifier si une table existe
function tableExists($pdo, $table_name) {
    try {
        $sql = "SHOW TABLES LIKE '$table_name'";
        $stmt = $pdo->query($sql);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

// Fonction pour obtenir l'évaluateur
function getEvaluateurId($pdo) {
    if (tableExists($pdo, 'enseignants')) {
        $sql = "SELECT id FROM enseignants LIMIT 1";
        $stmt = $pdo->query($sql);
        $enseignant = $stmt->fetch();
        if ($enseignant) {
            return $enseignant['id'];
        }
    }
    return 1;
}

// Fonction pour récupérer les types d'examen
function getTypesExamens($pdo) {
    if (tableExists($pdo, 'types_examens')) {
        try {
            $sql = "SELECT * FROM types_examens ORDER BY ordre";
            $stmt = $pdo->query($sql);
            $types = $stmt->fetchAll();
            if (!empty($types)) {
                return $types;
            }
        } catch (PDOException $e) {
            // Continuer avec les types par défaut
        }
    }
    
    return [
        ['id' => 1, 'nom' => 'DST', 'pourcentage' => 20.00, 'ordre' => 1],
        ['id' => 2, 'nom' => 'Devoir de Recherche', 'pourcentage' => 20.00, 'ordre' => 2],
        ['id' => 3, 'nom' => 'Session', 'pourcentage' => 60.00, 'ordre' => 3]
    ];
}

// Fonction pour récupérer les classes
function getClasses($pdo) {
    try {
        if (!tableExists($pdo, 'classes')) {
            return [];
        }
        
        $sql = "SELECT c.*, f.nom as filiere_nom 
                FROM classes c 
                LEFT JOIN filieres f ON c.filiere_id = f.id 
                ORDER BY c.nom";
        return $pdo->query($sql)->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Erreur getClasses: " . $e->getMessage());
        return [];
    }
}

// Fonction pour récupérer les matières d'une classe
function getMatieresParClasse($pdo, $classe_id) {
    try {
        if (!$classe_id) {
            return [];
        }
        
        // Récupérer la classe
        $sql_classe = "SELECT * FROM classes WHERE id = ?";
        $stmt_classe = $pdo->prepare($sql_classe);
        $stmt_classe->execute([$classe_id]);
        $classe = $stmt_classe->fetch();
        
        if (!$classe) {
            return [];
        }
        
        $matieres = [];
        
        // Méthode 1: Via table d'association classe_matiere
        if (tableExists($pdo, 'classe_matiere')) {
            try {
                $sql = "SELECT m.*, cm.coefficient 
                        FROM matieres m
                        INNER JOIN classe_matiere cm ON m.id = cm.matiere_id
                        WHERE cm.classe_id = ?
                        ORDER BY m.nom";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$classe_id]);
                $matieres = $stmt->fetchAll();
                
                if (!empty($matieres)) {
                    return $matieres;
                }
            } catch (PDOException $e) {
                // Continuer avec la méthode suivante
            }
        }
        
        // Méthode 2: Via filière
        if (isset($classe['filiere_id']) && $classe['filiere_id']) {
            $sql = "SELECT m.* 
                    FROM matieres m
                    WHERE m.filiere_id = ?";
            
            $params = [$classe['filiere_id']];
            
            if (isset($classe['niveau_id']) && $classe['niveau_id']) {
                $sql .= " AND m.niveau_id = ?";
                $params[] = $classe['niveau_id'];
            }
            
            $sql .= " ORDER BY m.nom";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $matieres = $stmt->fetchAll();
            
            if (!empty($matieres)) {
                return $matieres;
            }
        }
        
        // Méthode 3: Toutes les matières
        if (tableExists($pdo, 'matieres')) {
            $sql = "SELECT * FROM matieres ORDER BY nom";
            return $pdo->query($sql)->fetchAll();
        }
        
        return [];
        
    } catch (PDOException $e) {
        error_log("Erreur getMatieresParClasse: " . $e->getMessage());
        return [];
    }
}

// Fonction pour récupérer les étudiants d'une classe
function getEtudiantsParClasse($pdo, $classe_id) {
    try {
        if (!tableExists($pdo, 'etudiants')) {
            return [];
        }
        
        $sql = "SELECT e.*, c.nom as classe_nom
                FROM etudiants e
                INNER JOIN classes c ON e.classe_id = c.id
                WHERE e.classe_id = ?
                ORDER BY e.nom, e.prenom";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$classe_id]);
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Erreur getEtudiantsParClasse: " . $e->getMessage());
        return [];
    }
}

// Fonction pour récupérer les semestres
function getSemestres($pdo) {
    if (tableExists($pdo, 'semestres')) {
        try {
            $sql = "SELECT * FROM semestres ORDER BY numero";
            $stmt = $pdo->query($sql);
            $semestres = $stmt->fetchAll();
            
            if (!empty($semestres)) {
                return $semestres;
            }
        } catch (PDOException $e) {
            // Continuer avec les semestres par défaut
        }
    }
    
    return [
        ['id' => 1, 'numero' => 1, 'nom' => 'Semestre 1'],
        ['id' => 2, 'numero' => 2, 'nom' => 'Semestre 2']
    ];
}

// Fonction pour récupérer les années académiques
function getAnneesAcademiques($pdo) {
    if (tableExists($pdo, 'annees_academiques')) {
        try {
            $sql = "SHOW COLUMNS FROM annees_academiques";
            $stmt = $pdo->query($sql);
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $annee_col = 'id';
            if (in_array('libelle', $columns)) {
                $annee_col = 'libelle';
            } elseif (in_array('annee', $columns)) {
                $annee_col = 'annee';
            } elseif (in_array('nom', $columns)) {
                $annee_col = 'nom';
            }
            
            $sql = "SELECT id, $annee_col as annee FROM annees_academiques ORDER BY $annee_col DESC";
            $stmt = $pdo->query($sql);
            $annees = $stmt->fetchAll();
            
            if (!empty($annees)) {
                return $annees;
            }
        } catch (PDOException $e) {
            // Continuer avec l'année actuelle
        }
    }
    
    $current_year = date('Y');
    return [
        ['id' => 1, 'annee' => $current_year . '-' . ($current_year + 1)]
    ];
}

// Fonction pour récupérer les notes existantes
function getNotesExistantes($pdo, $classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id) {
    try {
        if (!tableExists($pdo, 'notes')) {
            return [];
        }
        
        $sql = "SELECT n.*, e.matricule, e.nom, e.prenom
                FROM notes n
                INNER JOIN etudiants e ON n.etudiant_id = e.id
                WHERE e.classe_id = ?
                  AND n.matiere_id = ?
                  AND n.type_examen_id = ?
                  AND n.semestre_id = ?
                  AND n.annee_academique_id = ?
                ORDER BY e.nom, e.prenom";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id]);
        
        $notes = [];
        while ($row = $stmt->fetch()) {
            $notes[$row['etudiant_id']] = $row;
        }
        
        return $notes;
        
    } catch (PDOException $e) {
        error_log("Erreur getNotesExistantes: " . $e->getMessage());
        return [];
    }
}

// Fonction pour sauvegarder les notes
function sauvegarderNotes($pdo, $notes_data, $type_examen_id, $matiere_id, $semestre_id, $annee_id) {
    try {
        $evaluateur_id = getEvaluateurId($pdo);
        
        if (!$semestre_id || !$annee_id) {
            return [
                'success' => false,
                'message' => "Le semestre et l'année académique sont obligatoires!"
            ];
        }
        
        $pdo->beginTransaction();
        
        $inserted = 0;
        $updated = 0;
        $errors = 0;
        $error_details = [];
        
        foreach ($notes_data as $etudiant_id => $note_data) {
            $note = isset($note_data['note']) && $note_data['note'] !== '' ? $note_data['note'] : null;
            $coefficient = isset($note_data['coefficient']) ? floatval($note_data['coefficient']) : 1.00;
            $remarques = isset($note_data['commentaire']) ? trim($note_data['commentaire']) : '';
            
            // Vérification
            if ($note === null || $note === '' || !is_numeric($note)) {
                $errors++;
                continue;
            }
            
            $note = floatval($note);
            
            if ($note < 0 || $note > 20) {
                $errors++;
                $error_details[] = "Étudiant ID $etudiant_id: Note $note invalide";
                continue;
            }
            
            // Vérifier si la note existe déjà
            $sql_check = "SELECT id FROM notes 
                         WHERE etudiant_id = ? 
                         AND matiere_id = ? 
                         AND type_examen_id = ?
                         AND semestre_id = ?
                         AND annee_academique_id = ?";
            
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([$etudiant_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id]);
            $existing = $stmt_check->fetch();
            
            try {
                if ($existing) {
                    // Mettre à jour
                    $sql = "UPDATE notes SET 
                            note = ?, 
                            coefficient_note = ?,
                            remarques = ?,
                            statut = 'valide',
                            date_evaluation = CURDATE()
                            WHERE id = ?";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$note, $coefficient, $remarques, $existing['id']]);
                    $updated++;
                } else {
                    // Insérer
                    $sql = "INSERT INTO notes 
                           (etudiant_id, matiere_id, type_examen_id, note, coefficient_note, 
                            date_evaluation, evaluateur_id, semestre_id, annee_academique_id, 
                            remarques, statut, date_creation) 
                           VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'valide', NOW())";
                    
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute([
                        $etudiant_id, 
                        $matiere_id, 
                        $type_examen_id, 
                        $note, 
                        $coefficient,
                        $evaluateur_id,
                        $semestre_id,
                        $annee_id,
                        $remarques
                    ]);
                    
                    if ($result) {
                        $inserted++;
                    } else {
                        $errors++;
                    }
                }
            } catch (PDOException $e) {
                $errors++;
                $error_details[] = "Étudiant ID $etudiant_id: " . $e->getMessage();
            }
        }
        
        $pdo->commit();
        
        $message = "Notes sauvegardées avec succès! ";
        $message .= "($inserted nouvelles notes, $updated notes mises à jour)";
        
        if ($errors > 0) {
            $message .= " - $errors erreur(s)";
        }
        
        return [
            'success' => true,
            'message' => $message,
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors
        ];
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur sauvegarderNotes: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => "Erreur lors de la sauvegarde: " . $e->getMessage()
        ];
    }
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'sauvegarder_notes') {
        $type_examen_id = $_POST['type_examen_id'] ?? null;
        $classe_id = $_POST['classe_id'] ?? null;
        $matiere_id = $_POST['matiere_id'] ?? null;
        $semestre_id = $_POST['semestre_id'] ?? null;
        $annee_id = $_POST['annee_id'] ?? null;
        
        if (!$type_examen_id || !$classe_id || !$matiere_id || !$semestre_id || !$annee_id) {
            $message = "Tous les champs sont requis!";
            $message_type = "danger";
        } else {
            $notes_data = [];
            if (isset($_POST['notes']) && is_array($_POST['notes'])) {
                foreach ($_POST['notes'] as $etudiant_id => $note_data) {
                    $note = isset($note_data['note']) && trim($note_data['note']) !== '' ? trim($note_data['note']) : null;
                    
                    if ($note !== null && is_numeric($note)) {
                        $note_val = floatval($note);
                        if ($note_val >= 0 && $note_val <= 20) {
                            $notes_data[$etudiant_id] = [
                                'note' => $note_val,
                                'coefficient' => isset($note_data['coefficient']) ? floatval($note_data['coefficient']) : 1,
                                'commentaire' => isset($note_data['commentaire']) ? trim($note_data['commentaire']) : ''
                            ];
                        }
                    }
                }
            }
            
            if (!empty($notes_data)) {
                $result = sauvegarderNotes($pdo, $notes_data, $type_examen_id, $matiere_id, $semestre_id, $annee_id);
                
                if ($result['success']) {
                    $message = $result['message'];
                    $message_type = "success";
                } else {
                    $message = $result['message'];
                    $message_type = "danger";
                }
            } else {
                $message = "Aucune note valide à sauvegarder!";
                $message_type = "warning";
            }
        }
    }
}

// Récupération des données
$types_examens = getTypesExamens($pdo);
$classes = getClasses($pdo);
$semestres = getSemestres($pdo);
$annees_academiques = getAnneesAcademiques($pdo);

// Variables sélectionnées
$type_examen_id = $_GET['type_examen_id'] ?? $_POST['type_examen_id'] ?? null;
$classe_id = $_GET['classe_id'] ?? $_POST['classe_id'] ?? null;
$matiere_id = $_GET['matiere_id'] ?? $_POST['matiere_id'] ?? null;
$semestre_id = $_GET['semestre_id'] ?? $_POST['semestre_id'] ?? ($semestres[0]['id'] ?? 1);
$annee_id = $_GET['annee_id'] ?? $_POST['annee_id'] ?? ($annees_academiques[0]['id'] ?? 1);

// Récupérer les données selon les filtres
$matieres = [];
$etudiants = [];
$notes_existantes = [];

if ($classe_id) {
    $matieres = getMatieresParClasse($pdo, $classe_id);
    
    if ($matiere_id && $type_examen_id && $semestre_id && $annee_id) {
        $etudiants = getEtudiantsParClasse($pdo, $classe_id);
        $notes_existantes = getNotesExistantes($pdo, $classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id);
    }
}

// Récupérer les informations sélectionnées
$type_examen_selected = null;
$classe_selected = null;
$matiere_selected = null;
$semestre_selected = null;
$annee_selected = null;

if ($type_examen_id) {
    foreach ($types_examens as $type) {
        if ($type['id'] == $type_examen_id) {
            $type_examen_selected = $type;
            break;
        }
    }
}

if ($classe_id) {
    foreach ($classes as $classe) {
        if ($classe['id'] == $classe_id) {
            $classe_selected = $classe;
            break;
        }
    }
}

if ($matiere_id) {
    foreach ($matieres as $matiere) {
        if ($matiere['id'] == $matiere_id) {
            $matiere_selected = $matiere;
            break;
        }
    }
}

if ($semestre_id) {
    foreach ($semestres as $semestre) {
        if ($semestre['id'] == $semestre_id) {
            $semestre_selected = $semestre;
            break;
        }
    }
}

if ($annee_id) {
    foreach ($annees_academiques as $annee) {
        if ($annee['id'] == $annee_id) {
            $annee_selected = $annee;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saisie des Notes - ISGI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .container { max-width: 1600px; }
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .card { border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-card { background: #e8f4fd; border-left: 4px solid #0d6efd; }
        .notes-card { background: #f8fff8; border-left: 4px solid #28a745; }
        .info-card { background: #fff8e1; border-left: 4px solid #ffc107; }
        .table-custom th { background-color: #f1f3f4; }
        .note-input { width: 80px; text-align: center; }
        .coefficient-input { width: 60px; text-align: center; }
        .btn-examen { padding: 8px 15px; margin: 3px; }
        .badge-examen { font-size: 0.8em; padding: 4px 8px; }
        .examen-dst { background-color: #0d6efd; }
        .examen-dr { background-color: #6f42c1; }
        .examen-session { background-color: #198754; }
        .invalid-note { border-color: #dc3545 !important; background-color: #fff8f8; }
        .valid-note { border-color: #198754 !important; background-color: #f8fff9; }
        .note-hint { font-size: 0.75em; color: #6c757d; margin-top: 2px; }
        .stat-card { transition: all 0.3s ease; height: 100%; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .progress-bar-custom { height: 10px; }
        .btn-purple { background-color: #6f42c1; color: white; }
        .btn-purple:hover { background-color: #5a32a3; color: white; }
        .select-multiple { height: 200px; }
        .quick-actions { margin-bottom: 15px; }
        .quick-actions .btn { margin-right: 5px; margin-bottom: 5px; }
        .loading { opacity: 0.5; pointer-events: none; }
        .matiere-select-group { margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header text-center">
            <h1><i class="bi bi-journal-text"></i> Saisie des Notes par Matière et Examen</h1>
            <p class="mb-0">Système de gestion des notes - ISGI Congo</p>
        </div>
        
        <!-- Message de confirmation -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Filtres principaux -->
        <div class="card form-card">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-funnel"></i> 1. Sélectionnez les Paramètres</h5>
                <form method="GET" action="" class="row g-3" id="filtres-form">
                    <!-- Classe -->
                    <div class="col-md-3">
                        <label class="form-label">Classe *</label>
                        <select name="classe_id" class="form-select" required onchange="this.form.submit()">
                            <option value="">Sélectionnez...</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>" <?php echo ($classe_id == $classe['id']) ? 'selected' : ''; ?>>
                                    <?php echo escape($classe['nom']); ?>
                                    <?php if (isset($classe['filiere_nom'])): ?>
                                    (<?php echo escape($classe['filiere_nom']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Semestre -->
                    <div class="col-md-2">
                        <label class="form-label">Semestre *</label>
                        <select name="semestre_id" class="form-select" required onchange="this.form.submit()">
                            <option value="">Sélectionnez...</option>
                            <?php foreach ($semestres as $semestre): ?>
                                <option value="<?php echo $semestre['id']; ?>" <?php echo ($semestre_id == $semestre['id']) ? 'selected' : ''; ?>>
                                    <?php echo escape($semestre['nom'] ?? 'Semestre ' . $semestre['numero']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Année académique -->
                    <div class="col-md-3">
                        <label class="form-label">Année académique *</label>
                        <select name="annee_id" class="form-select" required onchange="this.form.submit()">
                            <option value="">Sélectionnez...</option>
                            <?php foreach ($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                    <?php echo escape($annee['annee']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Charger les matières
                        </button>
                    </div>
                </form>
                
                <?php if ($classe_id && $semestre_id && $annee_id): ?>
                <div class="alert alert-success mt-3">
                    <i class="bi bi-check-circle"></i> 
                    <strong>Paramètres sélectionnés:</strong>
                    Classe: <?php echo escape($classe_selected['nom'] ?? ''); ?> | 
                    Semestre: <?php echo escape($semestre_selected['nom'] ?? ''); ?> | 
                    Année: <?php echo escape($annee_selected['annee'] ?? ''); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($classe_id && $semestre_id && $annee_id): ?>
        <!-- Sélection du type d'examen et de la matière -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> 2. Sélectionnez le Type d'Examen et la Matière</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Sélection du type d'examen -->
                    <div class="col-md-4">
                        <h6><i class="bi bi-clipboard-data"></i> Type d'Examen</h6>
                        <div class="d-flex flex-wrap">
                            <?php foreach ($types_examens as $type): 
                                $btn_class = '';
                                $badge_class = '';
                                
                                if (stripos($type['nom'], 'DST') !== false) {
                                    $btn_class = 'btn-primary';
                                    $badge_class = 'examen-dst';
                                } elseif (stripos($type['nom'], 'Recherche') !== false) {
                                    $btn_class = 'btn-purple';
                                    $badge_class = 'examen-dr';
                                } elseif (stripos($type['nom'], 'Session') !== false) {
                                    $btn_class = 'btn-success';
                                    $badge_class = 'examen-session';
                                } else {
                                    $btn_class = 'btn-secondary';
                                }
                            ?>
                            <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type['id']; ?>" 
                               class="btn <?php echo $btn_class; ?> btn-examen <?php echo ($type_examen_id == $type['id']) ? 'active' : ''; ?>">
                                <span class="badge <?php echo $badge_class; ?> me-1"><?php echo $type['pourcentage']; ?>%</span>
                                <?php echo escape($type['nom']); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($type_examen_id): ?>
                        <div class="alert alert-info mt-3">
                            <strong>Type sélectionné:</strong> 
                            <?php echo escape($type_examen_selected['nom']); ?>
                            <span class="badge bg-primary ms-2"><?php echo $type_examen_selected['pourcentage']; ?>%</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Sélection de la matière -->
                    <div class="col-md-8">
                        <h6><i class="bi bi-book"></i> Matière</h6>
                        <?php if (empty($matieres)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Aucune matière trouvée pour cette classe.
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <div class="col-md-8">
                                <select name="matiere_id" class="form-select" onchange="window.location.href='?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type_examen_id; ?>&matiere_id='+this.value" <?php echo !$type_examen_id ? 'disabled' : ''; ?>>
                                    <option value="">Sélectionnez une matière...</option>
                                    <?php foreach ($matieres as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>" <?php echo ($matiere_id == $matiere['id']) ? 'selected' : ''; ?>>
                                            <?php echo escape($matiere['nom']); ?> 
                                            (<?php echo $matiere['credit'] ?? '?'; ?> crédits)
                                            <?php if (isset($matiere['coefficient'])): ?>
                                            - Coef: <?php echo $matiere['coefficient']; ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$type_examen_id): ?>
                                <small class="text-muted">Veuillez d'abord sélectionner un type d'examen</small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-4">
                                <?php if ($type_examen_id && $matiere_id): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> 
                                    <strong>Prêt à saisir:</strong><br>
                                    <small>
                                        <?php echo escape($matiere_selected['nom']); ?> - 
                                        <?php echo escape($type_examen_selected['nom']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Matières rapides (pour navigation facile) -->
                        <?php if ($type_examen_id): ?>
                        <div class="mt-3">
                            <h6><i class="bi bi-lightning"></i> Accès rapide aux matières:</h6>
                            <div class="d-flex flex-wrap">
                                <?php foreach (array_slice($matieres, 0, 8) as $matiere): ?>
                                <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type_examen_id; ?>&matiere_id=<?php echo $matiere['id']; ?>" 
                                   class="btn btn-outline-primary btn-sm me-2 mb-2 <?php echo ($matiere_id == $matiere['id']) ? 'active' : ''; ?>">
                                    <?php echo substr(escape($matiere['nom']), 0, 20); ?><?php echo strlen($matiere['nom']) > 20 ? '...' : ''; ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($type_examen_id && $matiere_id && !empty($etudiants)): ?>
        <!-- Formulaire de saisie des notes -->
        <div class="card notes-card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-pencil-square"></i> 
                    3. Saisie des Notes - 
                    <?php echo escape($type_examen_selected['nom']); ?> - 
                    <?php echo escape($matiere_selected['nom']); ?>
                </h5>
                <div>
                    <span class="badge bg-light text-dark me-2">
                        <?php echo escape($classe_selected['nom']); ?>
                    </span>
                    <span class="badge bg-light text-dark">
                        <?php echo count($etudiants); ?> étudiant(s)
                    </span>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Informations détaillées -->
                <div class="alert alert-info mb-4">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Type d'examen:</strong><br>
                            <span class="badge bg-primary"><?php echo escape($type_examen_selected['nom']); ?></span>
                            <small class="d-block mt-1">Pondération: <?php echo $type_examen_selected['pourcentage']; ?>%</small>
                        </div>
                        <div class="col-md-3">
                            <strong>Matière:</strong><br>
                            <?php echo escape($matiere_selected['nom']); ?>
                            <small class="d-block mt-1">
                                Crédits: <?php echo $matiere_selected['credit'] ?? '?'; ?>
                                <?php if (isset($matiere_selected['coefficient'])): ?>
                                | Coef: <?php echo $matiere_selected['coefficient']; ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-md-2">
                            <strong>Semestre:</strong><br>
                            <?php echo escape($semestre_selected['nom'] ?? ''); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Année:</strong><br>
                            <?php echo escape($annee_selected['annee'] ?? ''); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Étudiants:</strong><br>
                            <span class="badge bg-info"><?php echo count($etudiants); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Actions rapides -->
                <div class="quick-actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="remplirNotesAleatoires()">
                        <i class="bi bi-shuffle"></i> Notes aléatoires (test)
                    </button>
                    <button type="button" class="btn btn-warning btn-sm" onclick="viderToutesLesNotes()">
                        <i class="bi bi-x-circle"></i> Vider tout
                    </button>
                    <button type="button" class="btn btn-info btn-sm" onclick="remplirMoyenne10()">
                        <i class="bi bi-arrow-right-circle"></i> Remplir avec 10
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="remplirMoyenne15()">
                        <i class="bi bi-arrow-up-circle"></i> Remplir avec 15
                    </button>
                    <div class="float-end">
                        <span class="badge bg-warning me-2">
                            <?php echo count($notes_existantes); ?> note(s) déjà saisie(s)
                        </span>
                    </div>
                </div>
                
                <!-- Formulaire de saisie -->
                <form method="POST" action="" id="form-notes">
                    <input type="hidden" name="action" value="sauvegarder_notes">
                    <input type="hidden" name="type_examen_id" value="<?php echo $type_examen_id; ?>">
                    <input type="hidden" name="classe_id" value="<?php echo $classe_id; ?>">
                    <input type="hidden" name="matiere_id" value="<?php echo $matiere_id; ?>">
                    <input type="hidden" name="semestre_id" value="<?php echo $semestre_id; ?>">
                    <input type="hidden" name="annee_id" value="<?php echo $annee_id; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-primary">
                                <tr>
                                    <th width="50">#</th>
                                    <th width="120">Matricule</th>
                                    <th>Nom & Prénom</th>
                                    <th width="140">Note /20 *</th>
                                    <th width="100">Coefficient</th>
                                    <th>Commentaire</th>
                                    <th width="100">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($etudiants as $index => $etudiant): 
                                    $note_existante = $notes_existantes[$etudiant['id']] ?? null;
                                    $note_value = $note_existante ? $note_existante['note'] : '';
                                    $coefficient_value = $note_existante ? $note_existante['coefficient_note'] : 1;
                                    $commentaire_value = $note_existante ? $note_existante['remarques'] : '';
                                    $statut_class = $note_existante ? 'success' : 'warning';
                                    $statut_text = $note_existante ? 'Déjà saisie' : 'À saisir';
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo escape($etudiant['matricule']); ?></td>
                                    <td><?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
                                    <td>
                                        <input type="number" 
                                               name="notes[<?php echo $etudiant['id']; ?>][note]" 
                                               class="form-control note-input" 
                                               min="0" max="20" step="0.01"
                                               value="<?php echo $note_value; ?>"
                                               placeholder="0-20"
                                               required
                                               data-etudiant-id="<?php echo $etudiant['id']; ?>">
                                        <div class="note-hint">Note obligatoire (0-20)</div>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="notes[<?php echo $etudiant['id']; ?>][coefficient]" 
                                               class="form-control coefficient-input" 
                                               min="0.1" max="5" step="0.1"
                                               value="<?php echo $coefficient_value; ?>"
                                               title="Coefficient (0.1 à 5)">
                                    </td>
                                    <td>
                                        <input type="text" 
                                               name="notes[<?php echo $etudiant['id']; ?>][commentaire]" 
                                               class="form-control" 
                                               value="<?php echo escape($commentaire_value); ?>"
                                               placeholder="Commentaire optionnel">
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $statut_class; ?>">
                                            <?php echo $statut_text; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Statistiques des notes -->
                    <div class="row mt-4">
                        <div class="col-md-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <h4><?php echo count($etudiants); ?></h4>
                                    <p class="mb-0 text-muted">Étudiants</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <h4><?php echo count($notes_existantes); ?></h4>
                                    <p class="mb-0 text-muted">Notes déjà saisies</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-save"></i> Sauvegarder toutes les notes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Navigation rapide -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-arrow-left-right"></i> Navigation rapide</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Autres types d'examen pour cette matière:</h6>
                                <div class="d-flex flex-wrap">
                                    <?php foreach ($types_examens as $type): 
                                        if ($type['id'] == $type_examen_id) continue;
                                    ?>
                                    <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type['id']; ?>&matiere_id=<?php echo $matiere_id; ?>" 
                                       class="btn btn-outline-secondary btn-sm me-2 mb-2">
                                        <i class="bi bi-arrow-right"></i> 
                                        <?php echo escape($type['nom']); ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Autres matières pour cet examen:</h6>
                                <div class="d-flex flex-wrap">
                                    <?php foreach (array_slice($matieres, 0, 5) as $matiere): 
                                        if ($matiere['id'] == $matiere_id) continue;
                                    ?>
                                    <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type_examen_id; ?>&matiere_id=<?php echo $matiere['id']; ?>" 
                                       class="btn btn-outline-primary btn-sm me-2 mb-2">
                                        <i class="bi bi-book"></i> 
                                        <?php echo substr(escape($matiere['nom']), 0, 15); ?>...
                                    </a>
                                    <?php endforeach; ?>
                                    <?php if (count($matieres) > 5): ?>
                                    <button class="btn btn-link btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#plusDeMatieres">
                                        Voir plus...
                                    </button>
                                    <div class="collapse mt-2" id="plusDeMatieres">
                                        <div class="d-flex flex-wrap">
                                            <?php foreach (array_slice($matieres, 5) as $matiere): 
                                                if ($matiere['id'] == $matiere_id) continue;
                                            ?>
                                            <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type_examen_id; ?>&matiere_id=<?php echo $matiere['id']; ?>" 
                                               class="btn btn-outline-primary btn-sm me-2 mb-2">
                                                <?php echo substr(escape($matiere['nom']), 0, 15); ?>...
                                            </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($type_examen_id && $matiere_id && empty($etudiants)): ?>
        <!-- Message si aucun étudiant -->
        <div class="alert alert-warning text-center">
            <i class="bi bi-people fs-4"></i>
            <h5 class="mt-3">Aucun étudiant dans cette classe</h5>
            <p class="mb-0">Veuillez d'abord assigner des étudiants à cette classe.</p>
            <a href="assignation_etudiants.php" class="btn btn-primary mt-3">
                <i class="bi bi-people"></i> Assigner des étudiants
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Informations sur les pondérations -->
        <div class="card info-card mt-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informations sur les pondérations</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Formule de calcul de la note finale:</h6>
                        <div class="alert alert-light">
                            <code>Note Finale = (DST × 20%) + (Recherche × 20%) + (Session × 60%)</code>
                        </div>
                        <p>Pour qu'une matière soit prise en compte dans le calcul de la moyenne générale, 
                        les 3 types de notes doivent être saisis pour chaque étudiant.</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Types d'examens et pondérations:</h6>
                        <ul class="list-group">
                            <?php foreach ($types_examens as $type): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo escape($type['nom']); ?>
                                <span class="badge bg-primary rounded-pill"><?php echo $type['pourcentage']; ?>%</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fonction pour valider une note
        function validateNoteInput(input) {
            const value = input.value.trim();
            input.classList.remove('invalid-note', 'valid-note');
            
            if (value === '') {
                input.classList.add('invalid-note');
                return false;
            }
            
            const note = parseFloat(value);
            if (isNaN(note) || note < 0 || note > 20) {
                input.classList.add('invalid-note');
                return false;
            }
            
            input.classList.add('valid-note');
            return true;
        }
        
        // Remplir aléatoirement
        function remplirNotesAleatoires() {
            if (!confirm('Remplir les notes avec des valeurs aléatoires? (Pour test seulement)')) {
                return;
            }
            
            const inputs = document.querySelectorAll('input.note-input');
            inputs.forEach(input => {
                const note = (Math.random() * 15 + 5).toFixed(2);
                input.value = note;
                validateNoteInput(input);
            });
            
            showNotification('Notes remplies aléatoirement pour test!', 'info');
        }
        
        // Remplir avec 10
        function remplirMoyenne10() {
            if (!confirm('Remplir toutes les notes avec 10/20?')) {
                return;
            }
            
            const inputs = document.querySelectorAll('input.note-input');
            inputs.forEach(input => {
                input.value = '10.00';
                validateNoteInput(input);
            });
            
            showNotification('Toutes les notes remplies avec 10/20', 'info');
        }
        
        // Remplir avec 15
        function remplirMoyenne15() {
            if (!confirm('Remplir toutes les notes avec 15/20?')) {
                return;
            }
            
            const inputs = document.querySelectorAll('input.note-input');
            inputs.forEach(input => {
                input.value = '15.00';
                validateNoteInput(input);
            });
            
            showNotification('Toutes les notes remplies avec 15/20', 'info');
        }
        
        // Vider toutes les notes
        function viderToutesLesNotes() {
            if (confirm('Êtes-vous sûr de vouloir vider toutes les notes?')) {
                const inputs = document.querySelectorAll('input.note-input');
                inputs.forEach(input => {
                    input.value = '';
                    input.classList.remove('invalid-note', 'valid-note');
                });
                
                showNotification('Toutes les notes ont été vidées!', 'warning');
            }
        }
        
        // Valider le formulaire
        document.getElementById('form-notes')?.addEventListener('submit', function(e) {
            let hasErrors = false;
            const noteInputs = document.querySelectorAll('input.note-input');
            
            noteInputs.forEach(input => {
                if (!validateNoteInput(input)) {
                    hasErrors = true;
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                showNotification('Certaines notes sont invalides! Vérifiez que toutes les notes sont entre 0 et 20.', 'danger');
                return false;
            }
            
            if (!confirm('Confirmez-vous la sauvegarde de toutes les notes?')) {
                e.preventDefault();
                return false;
            }
            
            // Indicateur de chargement
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sauvegarde en cours...';
                submitBtn.disabled = true;
            }
            
            return true;
        });
        
        // Afficher une notification
        function showNotification(message, type = 'info') {
            // Supprimer les notifications existantes
            document.querySelectorAll('.custom-alert').forEach(alert => alert.remove());
            
            // Créer l'alerte
            const alert = document.createElement('div');
            alert.className = `custom-alert alert alert-${type} alert-dismissible fade show position-fixed`;
            alert.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 300px;';
            alert.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle' : 
                                 type === 'warning' ? 'exclamation-triangle' : 
                                 type === 'danger' ? 'x-circle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alert);
            
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // Validation en temps réel
        document.addEventListener('DOMContentLoaded', function() {
            // Validation des notes
            document.querySelectorAll('input.note-input').forEach(input => {
                input.addEventListener('blur', function() {
                    validateNoteInput(this);
                });
                
                input.addEventListener('input', function() {
                    const value = this.value;
                    if (value && value !== '') {
                        validateNoteInput(this);
                    } else {
                        this.classList.remove('invalid-note', 'valid-note');
                    }
                });
            });
            
            // Validation des coefficients
            document.querySelectorAll('input.coefficient-input').forEach(input => {
                input.addEventListener('blur', function() {
                    const value = parseFloat(this.value);
                    if (this.value && this.value !== '') {
                        if (isNaN(value) || value < 0.1 || value > 5) {
                            this.classList.add('is-invalid');
                        } else {
                            this.classList.remove('is-invalid');
                        }
                    }
                });
            });
            
            // Auto-focus sur le premier champ vide
            const firstEmptyNote = document.querySelector('input.note-input[value=""]');
            if (firstEmptyNote) {
                setTimeout(() => firstEmptyNote.focus(), 100);
            }
            
            // Gestion du chargement des filtres
            const filtresForm = document.getElementById('filtres-form');
            if (filtresForm) {
                filtresForm.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Chargement...';
                        submitBtn.disabled = true;
                        document.body.classList.add('loading');
                    }
                });
            }
            
            // Raccourci clavier: Ctrl+S pour sauvegarder
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    const submitBtn = document.querySelector('#form-notes button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.click();
                    }
                }
            });
        });
    </script>
</body>
</html>