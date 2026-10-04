<?php
/**
 * Calcul des Moyennes - ISGI Congo
 * Version corrigée - Gestion des tables manquantes
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

// Fonction pour récupérer les semestres
function getSemestres($pdo) {
    if (tableExists($pdo, 'semestres')) {
        try {
            $sql = "SELECT * FROM semestres ORDER BY numero";
            return $pdo->query($sql)->fetchAll();
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
            // Vérifier les colonnes disponibles
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
            return $pdo->query($sql)->fetchAll();
        } catch (PDOException $e) {
            // Continuer avec l'année actuelle
        }
    }
    
    $current_year = date('Y');
    return [
        ['id' => 1, 'annee' => $current_year . '-' . ($current_year + 1)]
    ];
}

// FONCTION AMÉLIORÉE : Récupérer les matières d'une classe
function getMatieresParClasse($pdo, $classe_id) {
    try {
        if (!$classe_id) {
            return [];
        }
        
        // Récupérer les informations de la classe
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
        
        // Méthode 2: Via filière (si la classe a une filière)
        if (isset($classe['filiere_id']) && $classe['filiere_id']) {
            $sql = "SELECT m.* 
                    FROM matieres m
                    WHERE m.filiere_id = ?";
            
            $params = [$classe['filiere_id']];
            
            // Ajouter le niveau si disponible
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

// FONCTION PRINCIPALE CORRIGÉE : Calculer les moyennes des étudiants
function calculerMoyennesEtudiants($pdo, $classe_id, $semestre_id, $annee_id) {
    $resultats = [];
    
    try {
        // 1. Récupérer tous les étudiants de la classe
        $sql_etudiants = "SELECT e.*, c.nom as classe_nom 
                          FROM etudiants e 
                          JOIN classes c ON e.classe_id = c.id 
                          WHERE e.classe_id = ? 
                          ORDER BY e.nom, e.prenom";
        
        $stmt_etudiants = $pdo->prepare($sql_etudiants);
        $stmt_etudiants->execute([$classe_id]);
        $etudiants = $stmt_etudiants->fetchAll();
        
        if (empty($etudiants)) {
            return [];
        }
        
        // 2. Récupérer les matières de la classe (VERSION CORRIGÉE)
        $matieres = getMatieresParClasse($pdo, $classe_id);
        
        if (empty($matieres)) {
            // Si pas de matières trouvées, retourner un tableau avec juste les étudiants
            foreach ($etudiants as $etudiant) {
                $resultats[$etudiant['id']] = [
                    'etudiant' => $etudiant,
                    'matieres' => [],
                    'total_points' => 0,
                    'total_credits' => 0,
                    'moyenne_generale' => null,
                    'observation' => 'Ajourné (pas de matières)',
                    'mention' => 'Non admis'
                ];
            }
            return $resultats;
        }
        
        // 3. Pour chaque étudiant, calculer les moyennes par matière
        foreach ($etudiants as $etudiant) {
            $etudiant_result = [
                'etudiant' => $etudiant,
                'matieres' => [],
                'total_points' => 0,
                'total_credits' => 0,
                'moyenne_generale' => null,
                'observation' => 'Ajourné',
                'mention' => 'Non admis'
            ];
            
            // Pour chaque matière
            foreach ($matieres as $matiere) {
                $matiere_id = $matiere['id'];
                
                // Récupérer les 3 notes (DST, Recherche, Session)
                $sql_notes = "SELECT n.note, te.nom as type_examen, te.pourcentage 
                              FROM notes n 
                              JOIN types_examens te ON n.type_examen_id = te.id 
                              WHERE n.etudiant_id = ? 
                                AND n.matiere_id = ? 
                                AND n.semestre_id = ? 
                                AND n.annee_academique_id = ? 
                                AND n.statut = 'valide'";
                
                $stmt_notes = $pdo->prepare($sql_notes);
                $stmt_notes->execute([$etudiant['id'], $matiere_id, $semestre_id, $annee_id]);
                $notes = $stmt_notes->fetchAll();
                
                // Initialiser les notes
                $dst_note = null;
                $recherche_note = null;
                $session_note = null;
                
                // Organiser les notes par type
                foreach ($notes as $note) {
                    $type = strtolower($note['type_examen']);
                    if (strpos($type, 'dst') !== false) {
                        $dst_note = $note['note'];
                    } elseif (strpos($type, 'recherche') !== false) {
                        $recherche_note = $note['note'];
                    } elseif (strpos($type, 'session') !== false) {
                        $session_note = $note['note'];
                    }
                }
                
                // Calculer la note finale de la matière selon la formule
                $note_finale = null;
                if ($dst_note !== null && $recherche_note !== null && $session_note !== null) {
                    $note_finale = ($dst_note * 0.20) + ($recherche_note * 0.20) + ($session_note * 0.60);
                    $note_finale = round($note_finale, 2);
                }
                
                // Calculer les points (note finale × crédits)
                $credit = isset($matiere['credit']) ? $matiere['credit'] : (isset($matiere['coefficient']) ? $matiere['coefficient'] : 1);
                $points = $note_finale !== null ? round($note_finale * $credit, 2) : null;
                
                // Ajouter au total si la note existe
                if ($note_finale !== null) {
                    $etudiant_result['total_points'] += $points;
                    $etudiant_result['total_credits'] += $credit;
                }
                
                // Stocker les données de la matière
                $etudiant_result['matieres'][] = [
                    'matiere' => $matiere,
                    'dst' => $dst_note,
                    'recherche' => $recherche_note,
                    'session' => $session_note,
                    'note_finale' => $note_finale,
                    'credit' => $credit,
                    'points' => $points
                ];
            }
            
            // Calculer la moyenne générale
            if ($etudiant_result['total_credits'] > 0) {
                $etudiant_result['moyenne_generale'] = round($etudiant_result['total_points'] / $etudiant_result['total_credits'], 2);
                
                // Déterminer l'observation et la mention
                if ($etudiant_result['moyenne_generale'] >= 10) {
                    $etudiant_result['observation'] = 'Validé';
                    $etudiant_result['mention'] = getMention($etudiant_result['moyenne_generale']);
                }
            }
            
            $resultats[$etudiant['id']] = $etudiant_result;
        }
        
        // 4. Trier les étudiants par moyenne générale décroissante
        uasort($resultats, function($a, $b) {
            if ($a['moyenne_generale'] === null && $b['moyenne_generale'] === null) return 0;
            if ($a['moyenne_generale'] === null) return 1;
            if ($b['moyenne_generale'] === null) return -1;
            return $b['moyenne_generale'] <=> $a['moyenne_generale'];
        });
        
        // Ajouter le rang/classement
        $rang = 1;
        foreach ($resultats as &$resultat) {
            $resultat['rang'] = $rang++;
        }
        
    } catch (PDOException $e) {
        error_log("Erreur calculerMoyennesEtudiants: " . $e->getMessage());
        return [];
    }
    
    return $resultats;
}

// Fonction pour déterminer la mention
function getMention($moyenne) {
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Non admis';
}

// Fonction pour calculer les statistiques globales
function calculerStatistiques($resultats) {
    $stats = [
        'total_etudiants' => count($resultats),
        'admis' => 0,
        'ajournes' => 0,
        'meilleure_moyenne' => null,
        'pire_moyenne' => null,
        'moyenne_classe' => null,
        'total_points_classe' => 0,
        'total_credits_classe' => 0
    ];
    
    foreach ($resultats as $resultat) {
        if ($resultat['observation'] === 'Validé') {
            $stats['admis']++;
        } else {
            $stats['ajournes']++;
        }
        
        if ($resultat['moyenne_generale'] !== null) {
            // Meilleure moyenne
            if ($stats['meilleure_moyenne'] === null || $resultat['moyenne_generale'] > $stats['meilleure_moyenne']) {
                $stats['meilleure_moyenne'] = $resultat['moyenne_generale'];
                $stats['meilleur_etudiant'] = $resultat['etudiant']['nom'] . ' ' . $resultat['etudiant']['prenom'];
            }
            
            // Pire moyenne (parmi les ayant une moyenne)
            if ($stats['pire_moyenne'] === null || $resultat['moyenne_generale'] < $stats['pire_moyenne']) {
                $stats['pire_moyenne'] = $resultat['moyenne_generale'];
                $stats['pire_etudiant'] = $resultat['etudiant']['nom'] . ' ' . $resultat['etudiant']['prenom'];
            }
            
            // Pour la moyenne de classe
            $stats['total_points_classe'] += $resultat['total_points'];
            $stats['total_credits_classe'] += $resultat['total_credits'];
        }
    }
    
    // Calculer la moyenne de classe
    if ($stats['total_credits_classe'] > 0) {
        $stats['moyenne_classe'] = round($stats['total_points_classe'] / $stats['total_credits_classe'], 2);
    }
    
    // Calculer les pourcentages
    $stats['taux_admission'] = $stats['total_etudiants'] > 0 ? round(($stats['admis'] / $stats['total_etudiants']) * 100, 1) : 0;
    $stats['taux_echec'] = $stats['total_etudiants'] > 0 ? round(($stats['ajournes'] / $stats['total_etudiants']) * 100, 1) : 0;
    
    return $stats;
}

// Récupération des paramètres
$classe_id = $_GET['classe_id'] ?? $_POST['classe_id'] ?? null;
$semestre_id = $_GET['semestre_id'] ?? $_POST['semestre_id'] ?? null;
$annee_id = $_GET['annee_id'] ?? $_POST['annee_id'] ?? null;

// Récupération des listes
$classes = getClasses($pdo);
$semestres = getSemestres($pdo);
$annees = getAnneesAcademiques($pdo);

// Calculer les résultats si tous les paramètres sont fournis
$resultats = [];
$statistiques = [];
$classe_selected = null;
$semestre_selected = null;
$annee_selected = null;

if ($classe_id && $semestre_id && $annee_id) {
    $resultats = calculerMoyennesEtudiants($pdo, $classe_id, $semestre_id, $annee_id);
    $statistiques = calculerStatistiques($resultats);
    
    // Récupérer les informations sélectionnées
    foreach ($classes as $classe) {
        if ($classe['id'] == $classe_id) {
            $classe_selected = $classe;
            break;
        }
    }
    
    foreach ($semestres as $semestre) {
        if ($semestre['id'] == $semestre_id) {
            $semestre_selected = $semestre;
            break;
        }
    }
    
    foreach ($annees as $annee) {
        if ($annee['id'] == $annee_id) {
            $annee_selected = $annee;
            break;
        }
    }
}

// Debug: Afficher les informations sur les tables
$debug_info = [];
$debug_info['tables_existantes'] = [];
$tables_check = ['classes', 'etudiants', 'matieres', 'notes', 'types_examens', 'semestres', 'annees_academiques', 'classe_matiere'];

foreach ($tables_check as $table) {
    $debug_info['tables_existantes'][$table] = tableExists($pdo, $table) ? 'OUI' : 'NON';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcul des Moyennes - ISGI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .container { max-width: 1800px; }
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .card { border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-card { background: #e8f4fd; border-left: 4px solid #0d6efd; }
        .stats-card { background: #f0f9ff; }
        .result-card { background: #f8fff8; border-left: 4px solid #28a745; }
        .debug-card { background: #fff3cd; border-left: 4px solid #ffc107; font-size: 0.9em; }
        .table-custom th { background-color: #f1f3f4; vertical-align: middle; }
        .table-success { background-color: #d1e7dd !important; }
        .table-danger { background-color: #f8d7da !important; }
        .table-warning { background-color: #fff3cd !important; }
        .note-cell { text-align: center; font-weight: bold; }
        .badge-success { background-color: #198754; }
        .badge-danger { background-color: #dc3545; }
        .badge-warning { background-color: #ffc107; color: #000; }
        .badge-primary { background-color: #0d6efd; }
        .stat-card {
            transition: all 0.3s ease;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .moyenne-cell {
            font-weight: bold;
            font-size: 1.1em;
        }
        .formule {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .accordion-button:not(.collapsed) {
            background-color: #e7f1ff;
            color: #0c63e4;
        }
        .matiere-detail {
            font-size: 0.9em;
        }
        .total-row {
            font-weight: bold;
            background-color: #e9ecef !important;
        }
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        .debug-table td, .debug-table th {
            padding: 4px 8px;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header text-center">
            <h1><i class="bi bi-calculator"></i> Calcul des Moyennes Générales</h1>
            <p class="mb-0">Système de gestion des notes - ISGI Congo | Formule: (DST×20%) + (Recherche×20%) + (Session×60%)</p>
        </div>
        
        <!-- Panel de debug (optionnel) -->
        <?php if (isset($_GET['debug']) || (isset($classe_id) && empty($resultats))): ?>
        <div class="card debug-card mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-bug"></i> Informations de Débogage</h5>
            </div>
            <div class="card-body">
                <h6>Tables existantes:</h6>
                <table class="table debug-table">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Existe ?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debug_info['tables_existantes'] as $table => $existe): ?>
                        <tr>
                            <td><?php echo $table; ?></td>
                            <td><span class="badge bg-<?php echo $existe === 'OUI' ? 'success' : 'danger'; ?>"><?php echo $existe; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h6 class="mt-3">Paramètres actuels:</h6>
                <ul>
                    <li>Classe ID: <?php echo $classe_id ?? 'Non sélectionnée'; ?></li>
                    <li>Semestre ID: <?php echo $semestre_id ?? 'Non sélectionné'; ?></li>
                    <li>Année ID: <?php echo $annee_id ?? 'Non sélectionnée'; ?></li>
                    <li>Nombre d'étudiants trouvés: <?php echo isset($resultats) ? count($resultats) : 'Non calculé'; ?></li>
                </ul>
                
                <?php if ($classe_id): ?>
                <?php 
                // Tester la récupération des matières
                $test_matieres = getMatieresParClasse($pdo, $classe_id);
                ?>
                <h6>Matières pour cette classe:</h6>
                <?php if (!empty($test_matieres)): ?>
                <ul>
                    <?php foreach ($test_matieres as $matiere): ?>
                    <li><?php echo escape($matiere['nom']); ?> (ID: <?php echo $matiere['id']; ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-danger">Aucune matière trouvée pour cette classe!</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtres -->
        <div class="card form-card">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-funnel"></i> Sélectionnez les Paramètres</h5>
                <form method="GET" action="" class="row g-3">
                    <!-- Classe -->
                    <div class="col-md-4">
                        <label class="form-label">Classe *</label>
                        <select name="classe_id" class="form-select" required>
                            <option value="">Sélectionnez une classe</option>
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
                    <div class="col-md-3">
                        <label class="form-label">Semestre *</label>
                        <select name="semestre_id" class="form-select" required>
                            <option value="">Sélectionnez un semestre</option>
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
                        <select name="annee_id" class="form-select" required>
                            <option value="">Sélectionnez une année</option>
                            <?php foreach ($annees as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                    <?php echo escape($annee['annee'] ?? $annee['libelle'] ?? $annee['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-calculator"></i> Calculer
                        </button>
                        <a href="?debug=1&classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>" 
                           class="btn btn-warning ms-2" title="Mode debug">
                            <i class="bi bi-bug"></i>
                        </a>
                    </div>
                </form>
                
                <!-- Formule -->
                <div class="mt-3">
                    <p class="mb-1"><strong>Formule de calcul:</strong></p>
                    <div class="formule">
                        Note Finale = (DST × 0.20) + (Devoir de Recherche × 0.20) + (Session × 0.60)
                    </div>
                    <div class="formule mt-1">
                        Moyenne Générale = Σ(Note Finale × Crédit) ÷ Σ(Crédits)
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($resultats) && $classe_selected): ?>
        <!-- Statistiques globales -->
        <div class="card stats-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Statistiques de la Classe</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2">
                        <div class="card stat-card text-white bg-primary">
                            <div class="card-body text-center">
                                <h1><?php echo $statistiques['total_etudiants']; ?></h1>
                                <p class="mb-0">Étudiants</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card text-white bg-success">
                            <div class="card-body text-center">
                                <h1><?php echo $statistiques['admis']; ?></h1>
                                <p class="mb-0">Admis</p>
                                <small><?php echo $statistiques['taux_admission']; ?>%</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card text-white bg-danger">
                            <div class="card-body text-center">
                                <h1><?php echo $statistiques['ajournes']; ?></h1>
                                <p class="mb-0">Ajournés</p>
                                <small><?php echo $statistiques['taux_echec']; ?>%</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-white bg-warning">
                            <div class="card-body text-center">
                                <h1><?php echo $statistiques['meilleure_moyenne'] ?? 'N/A'; ?></h1>
                                <p class="mb-0">Meilleure moyenne</p>
                                <small><?php echo $statistiques['meilleur_etudiant'] ?? ''; ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-white bg-secondary">
                            <div class="card-body text-center">
                                <h1><?php echo $statistiques['moyenne_classe'] ?? 'N/A'; ?></h1>
                                <p class="mb-0">Moyenne de classe</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Informations -->
                <div class="alert alert-light mt-3">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Classe:</strong> <?php echo escape($classe_selected['nom']); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Semestre:</strong> <?php echo escape($semestre_selected['nom'] ?? 'Semestre ' . $semestre_selected['numero']); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Année académique:</strong> <?php echo escape($annee_selected['annee'] ?? $annee_selected['libelle'] ?? ''); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Résultats détaillés -->
        <div class="card result-card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-list-ol"></i> 
                    Résultats Détailés - Classement des Étudiants
                </h5>
                <div>
                    <button class="btn btn-light btn-sm" onclick="window.print()">
                        <i class="bi bi-printer"></i> Imprimer
                    </button>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($resultats[array_key_first($resultats)]['matieres'])): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Attention:</strong> Aucune matière n'a été trouvée pour cette classe.
                    Les moyennes ne peuvent pas être calculées sans matières.
                </div>
                <?php endif; ?>
                
                <?php foreach ($resultats as $resultat): 
                    $etudiant = $resultat['etudiant'];
                    $matieres = $resultat['matieres'];
                    $moyenne_generale = $resultat['moyenne_generale'];
                    $observation = $resultat['observation'];
                    $mention = $resultat['mention'];
                    $rang = $resultat['rang'];
                ?>
                <div class="accordion mb-3" id="accordion<?php echo $etudiant['id']; ?>">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?php echo $observation === 'Validé' ? '' : 'collapsed'; ?>" 
                                    type="button" 
                                    data-bs-toggle="collapse" 
                                    data-bs-target="#collapse<?php echo $etudiant['id']; ?>">
                                <div class="d-flex justify-content-between w-100 me-3">
                                    <div>
                                        <span class="badge bg-primary me-2">#<?php echo $rang; ?></span>
                                        <strong><?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                        <small class="ms-2">(<?php echo escape($etudiant['matricule']); ?>)</small>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $observation === 'Validé' ? 'success' : 'danger'; ?> me-2">
                                            <?php echo $observation; ?>
                                        </span>
                                        <span class="badge bg-info me-2">
                                            Moyenne: <?php echo $moyenne_generale ?? 'N/A'; ?>/20
                                        </span>
                                        <?php if ($observation === 'Validé'): ?>
                                        <span class="badge bg-warning">
                                            <?php echo $mention; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        
                        <div id="collapse<?php echo $etudiant['id']; ?>" 
                             class="accordion-collapse collapse <?php echo $observation === 'Validé' ? 'show' : ''; ?>" 
                             data-bs-parent="#accordion<?php echo $etudiant['id']; ?>">
                            <div class="accordion-body">
                                <?php if (!empty($matieres)): ?>
                                <!-- Tableau des matières -->
                                <div class="table-responsive matiere-detail">
                                    <table class="table table-bordered table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th rowspan="2">Matière</th>
                                                <th colspan="3" class="text-center">Notes (/20)</th>
                                                <th rowspan="2">Note Finale</th>
                                                <th rowspan="2">Crédit</th>
                                                <th rowspan="2">Points</th>
                                            </tr>
                                            <tr>
                                                <th class="text-center">DST (20%)</th>
                                                <th class="text-center">Recherche (20%)</th>
                                                <th class="text-center">Session (60%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_points = 0;
                                            $total_credits = 0;
                                            ?>
                                            <?php foreach ($matieres as $matiere_data): 
                                                $matiere = $matiere_data['matiere'];
                                                $dst = $matiere_data['dst'];
                                                $recherche = $matiere_data['recherche'];
                                                $session = $matiere_data['session'];
                                                $note_finale = $matiere_data['note_finale'];
                                                $credit = $matiere_data['credit'];
                                                $points = $matiere_data['points'];
                                                
                                                $total_points += $points ?? 0;
                                                $total_credits += $credit;
                                            ?>
                                            <tr class="<?php echo $note_finale === null ? 'table-warning' : ''; ?>">
                                                <td><?php echo escape($matiere['nom']); ?></td>
                                                <td class="text-center"><?php echo $dst ?? '<span class="text-muted">-</span>'; ?></td>
                                                <td class="text-center"><?php echo $recherche ?? '<span class="text-muted">-</span>'; ?></td>
                                                <td class="text-center"><?php echo $session ?? '<span class="text-muted">-</span>'; ?></td>
                                                <td class="text-center <?php echo $note_finale !== null ? 'fw-bold' : ''; ?>">
                                                    <?php if ($note_finale !== null): ?>
                                                        <?php echo $note_finale; ?>
                                                        <?php if ($dst !== null && $recherche !== null && $session !== null): ?>
                                                        <br><small class="text-muted">
                                                            = (<?php echo $dst; ?>×0.2)+(<?php echo $recherche; ?>×0.2)+(<?php echo $session; ?>×0.6)
                                                        </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-danger">Données incomplètes</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo $credit; ?></td>
                                                <td class="text-center fw-bold">
                                                    <?php echo $points ?? '<span class="text-muted">-</span>'; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Ligne des totaux -->
                                            <tr class="total-row">
                                                <td colspan="4" class="text-end"><strong>TOTAL</strong></td>
                                                <td class="text-center">-</td>
                                                <td class="text-center"><strong><?php echo $resultat['total_credits']; ?></strong></td>
                                                <td class="text-center"><strong><?php echo round($resultat['total_points'], 2); ?></strong></td>
                                            </tr>
                                            
                                            <!-- Ligne de la moyenne -->
                                            <?php if ($resultat['total_credits'] > 0): ?>
                                            <tr class="table-light">
                                                <td colspan="5" class="text-end"><strong>MOYENNE GÉNÉRALE</strong></td>
                                                <td colspan="2" class="text-center moyenne-cell 
                                                    <?php echo $observation === 'Validé' ? 'text-success' : 'text-danger'; ?>">
                                                    <strong>
                                                        <?php if ($moyenne_generale !== null): ?>
                                                            <?php echo $moyenne_generale; ?> / 20
                                                            <br>
                                                            <small class="text-muted">
                                                                = <?php echo round($resultat['total_points'], 2); ?> ÷ <?php echo $resultat['total_credits']; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            N/A
                                                        <?php endif; ?>
                                                    </strong>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Observation -->
                                <div class="alert alert-<?php echo $observation === 'Validé' ? 'success' : 'danger'; ?> mt-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Observation:</strong> <?php echo $observation; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Mention:</strong> 
                                            <span class="badge bg-<?php echo $observation === 'Validé' ? 'warning' : 'secondary'; ?>">
                                                <?php echo $mention; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Aucune matière n'est associée à cet étudiant pour le calcul des moyennes.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Tableau récapitulatif -->
                <div class="mt-4">
                    <h5><i class="bi bi-table"></i> Tableau Récapitulatif</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Rang</th>
                                    <th>Matricule</th>
                                    <th>Nom & Prénom</th>
                                    <th>Moyenne</th>
                                    <th>Observation</th>
                                    <th>Mention</th>
                                    <th>Total Points</th>
                                    <th>Total Crédits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultats as $resultat): 
                                    $etudiant = $resultat['etudiant'];
                                    $moyenne_generale = $resultat['moyenne_generale'];
                                    $observation = $resultat['observation'];
                                    $mention = $resultat['mention'];
                                    $rang = $resultat['rang'];
                                ?>
                                <tr class="<?php echo $observation === 'Validé' ? 'table-success' : 'table-danger'; ?>">
                                    <td class="text-center"><?php echo $rang; ?></td>
                                    <td><?php echo escape($etudiant['matricule']); ?></td>
                                    <td><?php echo escape($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
                                    <td class="text-center fw-bold">
                                        <?php echo $moyenne_generale ?? 'N/A'; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $observation === 'Validé' ? 'success' : 'danger'; ?>">
                                            <?php echo $observation; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $observation === 'Validé' ? 'warning' : 'secondary'; ?>">
                                            <?php echo $mention; ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo round($resultat['total_points'], 2); ?></td>
                                    <td class="text-center"><?php echo $resultat['total_credits']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bouton d'impression -->
        <button class="btn btn-primary print-btn" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimer les Résultats
        </button>
        
        <?php elseif ($classe_id && $semestre_id && $annee_id): ?>
        <!-- Message si pas de résultats -->
        <div class="alert alert-warning text-center">
            <i class="bi bi-exclamation-triangle fs-4"></i>
            <h5 class="mt-3">Aucun résultat trouvé</h5>
            <p class="mb-0">Aucune note n'a été saisie pour cette classe, ce semestre et cette année académique.</p>
            <p class="mb-0">Veuillez d'abord saisir les notes (DST, Devoir de Recherche, Session) pour tous les étudiants.</p>
            <div class="mt-3">
                <a href="saisie_notes.php" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Saisir des notes
                </a>
                <a href="?debug=1&classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>" 
                   class="btn btn-warning">
                    <i class="bi bi-bug"></i> Voir les détails
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Informations sur le calcul -->
        <div class="card mt-4">
            <div class="card-body">
                <h5><i class="bi bi-info-circle"></i> Informations sur le calcul des moyennes</h5>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Formules utilisées:</h6>
                        <ul>
                            <li><strong>Note finale par matière:</strong> 
                                <code>(DST × 0.20) + (Recherche × 0.20) + (Session × 0.60)</code>
                            </li>
                            <li><strong>Points par matière:</strong> 
                                <code>Note finale × Crédits de la matière</code>
                            </li>
                            <li><strong>Moyenne générale:</strong> 
                                <code>Σ(Points) ÷ Σ(Crédits)</code>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Seuils et mentions:</h6>
                        <ul>
                            <li><span class="badge bg-success">Validé</span> : Moyenne ≥ 10/20</li>
                            <li><span class="badge bg-danger">Ajourné</span> : Moyenne < 10/20</li>
                            <li><span class="badge bg-warning">Passable</span> : 10 ≤ Moyenne < 12</li>
                            <li><span class="badge bg-warning">Assez Bien</span> : 12 ≤ Moyenne < 14</li>
                            <li><span class="badge bg-warning">Bien</span> : 14 ≤ Moyenne < 16</li>
                            <li><span class="badge bg-warning">Très Bien</span> : Moyenne ≥ 16</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Pour l'impression, masquer certains éléments
        window.addEventListener('beforeprint', function() {
            // Masquer les boutons et certains éléments
            document.querySelectorAll('.print-btn, .btn, .accordion-button').forEach(el => {
                el.style.display = 'none';
            });
            
            // Déplier tous les accordéons pour l'impression
            document.querySelectorAll('.accordion-collapse').forEach(collapse => {
                collapse.classList.add('show');
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restaurer l'affichage
            document.querySelectorAll('.print-btn, .btn, .accordion-button').forEach(el => {
                el.style.display = '';
            });
        });
        
        // Auto-soumettre le formulaire si des paramètres sont présents
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('classe_id') && urlParams.has('semestre_id') && urlParams.has('annee_id')) {
                // Les paramètres sont déjà présents, pas besoin de soumettre
                console.log('Paramètres de calcul présents dans l\'URL');
            }
        });
    </script>
</body>
</html>