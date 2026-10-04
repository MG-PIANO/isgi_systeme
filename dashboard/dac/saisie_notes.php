<?php
// dashboard/dac/saisie_notes.php

// ============================================
// 1. INITIALISATION
// ============================================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Vérifier que l'utilisateur est connecté et est un DAC (role_id = 5)
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 5) {
    $root_path = dirname(dirname(dirname(__DIR__)));
    header("Location: $root_path/auth/login.php");
    exit();
}

// ============================================
// 2. CONFIGURATION BASE DE DONNÉES
// ============================================
$database_found = false;
$db = null;

$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/config/database.php',
    dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php',
    '../../../../config/database.php',
    '../../../config/database.php',
    '../config/database.php',
    'config/database.php'
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $database_found = true;
        break;
    }
}

if (!$database_found) {
    try {
        $db = new PDO(
            'mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4',
            'root',
            'admin1234',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (PDOException $e) {
        die("<div style='padding:20px;background:#f8d7da;color:#721c24;border-radius:5px;'>
            <h3>Erreur de connexion à la base de données</h3>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
        </div>");
    }
} else {
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
    }
}

// ============================================
// 3. FONCTIONS UTILITAIRES
// ============================================
function escape($value) {
    return $value !== null ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
}

function tableExists($db, $table_name) {
    try {
        $sql = "SHOW TABLES LIKE '$table_name'";
        $stmt = $db->query($sql);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM $table LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// 4. FONCTIONS DE SAISIE DES NOTES
// ============================================
function getEvaluateurId($db) {
    if (tableExists($db, 'enseignants')) {
        $sql = "SELECT id FROM enseignants LIMIT 1";
        $stmt = $db->query($sql);
        $enseignant = $stmt->fetch();
        if ($enseignant) {
            return $enseignant['id'];
        }
    }
    return 1;
}

function getTypesExamens($db) {
    if (tableExists($db, 'types_examens')) {
        try {
            $sql = "SELECT * FROM types_examens ORDER BY ordre";
            return $db->query($sql)->fetchAll();
        } catch (Exception $e) {
            // Types par défaut
        }
    }
    
    return [
        ['id' => 1, 'nom' => 'DST', 'pourcentage' => 20.00, 'ordre' => 1],
        ['id' => 2, 'nom' => 'Devoir de Recherche', 'pourcentage' => 20.00, 'ordre' => 2],
        ['id' => 3, 'nom' => 'Session', 'pourcentage' => 60.00, 'ordre' => 3]
    ];
}

function getClasses($db) {
    try {
        if (!tableExists($db, 'classes')) {
            return [];
        }
        
        $site_id = $_SESSION['site_id'] ?? 1;
        $sql = "SELECT c.*, f.nom as filiere_nom 
                FROM classes c 
                LEFT JOIN filieres f ON c.filiere_id = f.id 
                WHERE c.site_id = ?
                ORDER BY c.nom";
        $stmt = $db->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Erreur getClasses: " . $e->getMessage());
        return [];
    }
}

function getMatieresParClasse($db, $classe_id) {
    try {
        if (!$classe_id) {
            return [];
        }
        
        $sql_classe = "SELECT * FROM classes WHERE id = ?";
        $stmt_classe = $db->prepare($sql_classe);
        $stmt_classe->execute([$classe_id]);
        $classe = $stmt_classe->fetch();
        
        if (!$classe) {
            return [];
        }
        
        $matieres = [];
        
        if (tableExists($db, 'classe_matiere')) {
            try {
                $sql = "SELECT m.*, cm.coefficient 
                        FROM matieres m
                        INNER JOIN classe_matiere cm ON m.id = cm.matiere_id
                        WHERE cm.classe_id = ?
                        ORDER BY m.nom";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$classe_id]);
                $matieres = $stmt->fetchAll();
                
                if (!empty($matieres)) {
                    return $matieres;
                }
            } catch (Exception $e) {
                // Continuer avec la méthode suivante
            }
        }
        
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
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $matieres = $stmt->fetchAll();
            
            if (!empty($matieres)) {
                return $matieres;
            }
        }
        
        if (tableExists($db, 'matieres')) {
            $sql = "SELECT * FROM matieres ORDER BY nom";
            return $db->query($sql)->fetchAll();
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log("Erreur getMatieresParClasse: " . $e->getMessage());
        return [];
    }
}

function getEtudiantsParClasse($db, $classe_id) {
    try {
        if (!tableExists($db, 'etudiants')) {
            return [];
        }
        
        $sql = "SELECT e.*, c.nom as classe_nom
                FROM etudiants e
                INNER JOIN classes c ON e.classe_id = c.id
                WHERE e.classe_id = ?
                ORDER BY e.nom, e.prenom";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id]);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Erreur getEtudiantsParClasse: " . $e->getMessage());
        return [];
    }
}

function getSemestres($db) {
    if (tableExists($db, 'semestres')) {
        try {
            $sql = "SELECT * FROM semestres ORDER BY numero";
            return $db->query($sql)->fetchAll();
        } catch (Exception $e) {
            // Semestres par défaut
        }
    }
    
    return [
        ['id' => 1, 'numero' => 1, 'nom' => 'Semestre 1'],
        ['id' => 2, 'numero' => 2, 'nom' => 'Semestre 2']
    ];
}

function getAnneesAcademiques($db) {
    if (tableExists($db, 'annees_academiques')) {
        try {
            $sql = "SHOW COLUMNS FROM annees_academiques";
            $stmt = $db->query($sql);
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
            return $db->query($sql)->fetchAll();
        } catch (Exception $e) {
            // Année actuelle
        }
    }
    
    $current_year = date('Y');
    return [
        ['id' => 1, 'annee' => $current_year . '-' . ($current_year + 1)]
    ];
}

function getNotesExistantes($db, $classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id) {
    try {
        if (!tableExists($db, 'notes')) {
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
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id]);
        
        $notes = [];
        while ($row = $stmt->fetch()) {
            $notes[$row['etudiant_id']] = $row;
        }
        
        return $notes;
        
    } catch (Exception $e) {
        error_log("Erreur getNotesExistantes: " . $e->getMessage());
        return [];
    }
}

function sauvegarderNotes($db, $notes_data, $type_examen_id, $matiere_id, $semestre_id, $annee_id) {
    try {
        $evaluateur_id = getEvaluateurId($db);
        
        if (!$semestre_id || !$annee_id) {
            return [
                'success' => false,
                'message' => "Le semestre et l'année académique sont obligatoires!"
            ];
        }
        
        $db->beginTransaction();
        
        $inserted = 0;
        $updated = 0;
        $errors = 0;
        $error_details = [];
        
        foreach ($notes_data as $etudiant_id => $note_data) {
            $note = isset($note_data['note']) && $note_data['note'] !== '' ? $note_data['note'] : null;
            $coefficient = isset($note_data['coefficient']) ? floatval($note_data['coefficient']) : 1.00;
            $remarques = isset($note_data['commentaire']) ? trim($note_data['commentaire']) : '';
            
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
            
            $sql_check = "SELECT id FROM notes 
                         WHERE etudiant_id = ? 
                         AND matiere_id = ? 
                         AND type_examen_id = ?
                         AND semestre_id = ?
                         AND annee_academique_id = ?";
            
            $stmt_check = $db->prepare($sql_check);
            $stmt_check->execute([$etudiant_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id]);
            $existing = $stmt_check->fetch();
            
            try {
                if ($existing) {
                    $sql = "UPDATE notes SET 
                            note = ?, 
                            coefficient_note = ?,
                            remarques = ?,
                            statut = 'valide',
                            date_evaluation = CURDATE()
                            WHERE id = ?";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$note, $coefficient, $remarques, $existing['id']]);
                    $updated++;
                } else {
                    $sql = "INSERT INTO notes 
                           (etudiant_id, matiere_id, type_examen_id, note, coefficient_note, 
                            date_evaluation, evaluateur_id, semestre_id, annee_academique_id, 
                            remarques, statut, date_creation) 
                           VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'valide', NOW())";
                    
                    $stmt = $db->prepare($sql);
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
            } catch (Exception $e) {
                $errors++;
                $error_details[] = "Étudiant ID $etudiant_id: " . $e->getMessage();
            }
        }
        
        $db->commit();
        
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
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Erreur sauvegarderNotes: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => "Erreur lors de la sauvegarde: " . $e->getMessage()
        ];
    }
}

// ============================================
// 5. TRAITEMENT DU FORMULAIRE
// ============================================
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
                $result = sauvegarderNotes($db, $notes_data, $type_examen_id, $matiere_id, $semestre_id, $annee_id);
                
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

// ============================================
// 6. RÉCUPÉRATION DES DONNÉES
// ============================================
$types_examens = getTypesExamens($db);
$classes = getClasses($db);
$semestres = getSemestres($db);
$annees_academiques = getAnneesAcademiques($db);

$type_examen_id = $_GET['type_examen_id'] ?? $_POST['type_examen_id'] ?? null;
$classe_id = $_GET['classe_id'] ?? $_POST['classe_id'] ?? null;
$matiere_id = $_GET['matiere_id'] ?? $_POST['matiere_id'] ?? null;
$semestre_id = $_GET['semestre_id'] ?? $_POST['semestre_id'] ?? ($semestres[0]['id'] ?? 1);
$annee_id = $_GET['annee_id'] ?? $_POST['annee_id'] ?? ($annees_academiques[0]['id'] ?? 1);

$matieres = [];
$etudiants = [];
$notes_existantes = [];

if ($classe_id) {
    $matieres = getMatieresParClasse($db, $classe_id);
    
    if ($matiere_id && $type_examen_id && $semestre_id && $annee_id) {
        $etudiants = getEtudiantsParClasse($db, $classe_id);
        $notes_existantes = getNotesExistantes($db, $classe_id, $matiere_id, $type_examen_id, $semestre_id, $annee_id);
    }
}

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
    <title>Saisie des Notes - DAC | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ========== VARIABLES CSS ========== */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --info-color: #17a2b8;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 250px;
            --sidebar-collapsed: 70px;
        }
        
        /* ========== STYLES GÉNÉRAUX ========== */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* ========== LAYOUT PRINCIPAL ========== */
        .app-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }
        
        /* ========== SIDEBAR ========== */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, #1a252f 100%);
            color: white;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: rgba(0,0,0,0.2);
        }
        
        .sidebar-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .sidebar-logo i {
            font-size: 24px;
        }
        
        .user-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(0,0,0,0.15);
            margin: 15px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-info p {
            margin: 0;
            font-weight: 500;
        }
        
        .user-role {
            display: inline-block;
            padding: 5px 15px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        /* ========== NAVIGATION ========== */
        .sidebar-nav {
            padding: 20px 15px;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 12px;
            padding: 0 15px;
            font-weight: 600;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--info-color);
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(90deg, rgba(23, 162, 184, 0.2), transparent);
            color: white;
            border-left-color: var(--info-color);
            font-weight: 500;
        }
        
        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 16px;
            margin-right: 12px;
            opacity: 0.9;
        }
        
        /* ========== CONTENU PRINCIPAL ========== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 25px;
            transition: all 0.3s ease;
        }
        
        /* ========== EN-TÊTE ========== */
        .content-header {
            background: white;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        .page-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 14px;
        }
        
        /* ========== CARTES DE CONTENU ========== */
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .card-header {
            background: linear-gradient(90deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #dee2e6;
            padding: 18px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header h5 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .card-header h5 i {
            margin-right: 10px;
            color: var(--info-color);
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* ========== STYLES SPÉCIFIQUES SAISIE NOTES ========== */
        .form-card {
            background: #e8f4fd;
            border-left: 4px solid #0d6efd;
        }
        
        .notes-card {
            background: #f8fff8;
            border-left: 4px solid #28a745;
        }
        
        .info-card {
            background: #fff8e1;
            border-left: 4px solid #ffc107;
        }
        
        .table-custom th {
            background-color: #f1f3f4;
            vertical-align: middle;
        }
        
        .note-input {
            width: 80px;
            text-align: center;
            border-radius: 5px;
            border: 1px solid #ced4da;
            padding: 8px;
            font-weight: 500;
        }
        
        .coefficient-input {
            width: 60px;
            text-align: center;
            border-radius: 5px;
            border: 1px solid #ced4da;
            padding: 8px;
        }
        
        .btn-examen {
            padding: 8px 15px;
            margin: 3px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .btn-examen:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .badge-examen {
            font-size: 0.8em;
            padding: 4px 8px;
            border-radius: 10px;
        }
        
        .examen-dst {
            background-color: #0d6efd;
            color: white;
        }
        
        .examen-dr {
            background-color: #6f42c1;
            color: white;
        }
        
        .examen-session {
            background-color: #198754;
            color: white;
        }
        
        .invalid-note {
            border-color: #dc3545 !important;
            background-color: #fff8f8;
        }
        
        .valid-note {
            border-color: #198754 !important;
            background-color: #f8fff9;
        }
        
        .note-hint {
            font-size: 0.75em;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .stat-card {
            transition: all 0.3s ease;
            height: 100%;
            border: none;
            border-radius: 10px;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .btn-purple {
            background-color: #6f42c1;
            color: white;
            border: none;
        }
        
        .btn-purple:hover {
            background-color: #5a32a3;
            color: white;
        }
        
        .quick-actions {
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .quick-actions .btn {
            margin-right: 5px;
            margin-bottom: 5px;
            border-radius: 6px;
        }
        
        .loading {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .matiere-select-group {
            margin-bottom: 15px;
        }
        
        .select-multiple {
            height: 200px;
            border-radius: 8px;
        }
        
        /* ========== BOUTONS ========== */
        .btn-action {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-action i {
            margin-right: 8px;
        }
        
        /* ========== RESPONSIVE ========== */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed);
            }
            
            .sidebar-header h5,
            .user-info,
            .nav-section-title,
            .nav-link span {
                display: none !important;
            }
            
            .sidebar-logo {
                width: 40px;
                height: 40px;
            }
            
            .sidebar-logo i {
                font-size: 18px;
            }
            
            .nav-link {
                justify-content: center;
                padding: 15px;
                margin-bottom: 10px;
            }
            
            .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed);
                padding: 15px;
            }
            
            .content-header {
                padding: 20px;
            }
            
            .note-input {
                width: 70px;
            }
            
            .coefficient-input {
                width: 50px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .table thead th,
            .table tbody td {
                padding: 12px 15px;
            }
            
            .btn-examen {
                padding: 6px 10px;
                font-size: 0.9em;
            }
        }
        
        @media (max-width: 576px) {
            .sidebar {
                display: none;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
            
            .note-input {
                width: 60px;
                font-size: 0.9em;
            }
            
            .coefficient-input {
                width: 40px;
                font-size: 0.9em;
            }
            
            .table-responsive {
                font-size: 0.9em;
            }
        }
        
        /* ========== BOUTON MOBILE ========== */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: var(--info-color);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
        }
        
        /* ========== SCROLLBAR PERSONNALISÉE ========== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--info-color);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #0d8abc;
        }
        
        /* ========== ANIMATIONS ========== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* ========== TOOLTIPS ========== */
        .custom-tooltip {
            --bs-tooltip-bg: var(--bs-primary);
            --bs-tooltip-color: var(--bs-white);
        }
    </style>
</head>
<body>
    <!-- Bouton menu mobile -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="app-container">
        <!-- ========== SIDEBAR ========== -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur DAC'); ?></p>
                <small><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'Site ISGI'); ?></small>
            </div>
            
            <div class="sidebar-nav">
                <!-- Tableau de bord -->
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Gestion des matières</span>
                    </a>
                    <a href="saisie_notes.php" class="nav-link active">
                        <i class="fas fa-pencil-alt"></i>
                        <span>Saisie des notes</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-calculator"></i>
                        <span>Calcul des moyennes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de notes</span>
                    </a>
                </div>
                
                <!-- Gestion des étudiants -->
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste des étudiants</span>
                    </a>
                </div>
                
                <!-- Rapports & Statistiques -->
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Statistiques</div>
                    <a href="rapports_academiques.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports académiques</span>
                    </a>
                </div>
                
                <!-- Compte -->
                <div class="nav-section">
                    <div class="nav-section-title">Compte</div>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- ========== CONTENU PRINCIPAL ========== -->
        <div class="main-content" id="mainContent">
            <!-- En-tête -->
            <div class="content-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-pencil-alt me-2"></i>
                            Saisie des Notes par Matière et Examen
                        </h1>
                        <p class="page-subtitle">
                            Directeur des Affaires Académiques | 
                            Site: <strong><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'ISGI'); ?></strong> | 
                            <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="calcul_moyennes.php" class="btn btn-outline-info btn-action">
                            <i class="fas fa-calculator"></i> Calcul Moyennes
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Message de confirmation -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Filtres principaux -->
            <div class="content-card form-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-filter me-2"></i> 1. Sélectionnez les Paramètres</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3" id="filtres-form">
                        <!-- Classe -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Classe *</label>
                            <select name="classe_id" class="form-select" required onchange="this.form.submit()">
                                <option value="">Sélectionnez une classe...</option>
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
                            <label class="form-label fw-bold">Semestre *</label>
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
                            <label class="form-label fw-bold">Année académique *</label>
                            <select name="annee_id" class="form-select" required onchange="this.form.submit()">
                                <option value="">Sélectionnez...</option>
                                <?php foreach ($annees_academiques as $annee): ?>
                                    <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                        <?php echo escape($annee['annee']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100 btn-action">
                                <i class="fas fa-search"></i> Charger
                            </button>
                        </div>
                    </form>
                    
                    <?php if ($classe_id && $semestre_id && $annee_id): ?>
                    <div class="alert alert-success mt-3">
                        <i class="fas fa-check-circle me-2"></i>
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
            <div class="content-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-clipboard-check me-2"></i> 2. Sélectionnez le Type d'Examen et la Matière</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Sélection du type d'examen -->
                        <div class="col-lg-4 mb-4">
                            <h6 class="fw-bold mb-3"><i class="fas fa-clipboard-data me-2"></i> Type d'Examen</h6>
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
                                <strong><i class="fas fa-check me-2"></i>Type sélectionné:</strong> 
                                <?php echo escape($type_examen_selected['nom']); ?>
                                <span class="badge bg-primary ms-2"><?php echo $type_examen_selected['pourcentage']; ?>%</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Sélection de la matière -->
                        <div class="col-lg-8">
                            <h6 class="fw-bold mb-3"><i class="fas fa-book me-2"></i> Matière</h6>
                            <?php if (empty($matieres)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
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
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Prêt à saisir:</strong><br>
                                        <small>
                                            <?php echo escape($matiere_selected['nom']); ?> - 
                                            <?php echo escape($type_examen_selected['nom']); ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Matières rapides -->
                            <?php if ($type_examen_id): ?>
                            <div class="mt-3">
                                <h6><i class="fas fa-bolt me-2"></i> Accès rapide aux matières:</h6>
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
            <div class="content-card notes-card fade-in">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-pencil-square me-2"></i> 
                        3. Saisie des Notes - 
                        <?php echo escape($type_examen_selected['nom']); ?> - 
                        <?php echo escape($matiere_selected['nom']); ?>
                    </h5>
                    <div>
                        <span class="badge bg-info me-2">
                            <?php echo escape($classe_selected['nom']); ?>
                        </span>
                        <span class="badge bg-secondary">
                            <?php echo count($etudiants); ?> étudiant(s)
                        </span>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Informations détaillées -->
                    <div class="alert alert-info mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <strong><i class="fas fa-clipboard-list me-2"></i>Type d'examen:</strong><br>
                                <span class="badge bg-primary mt-1"><?php echo escape($type_examen_selected['nom']); ?></span>
                                <small class="d-block mt-1">Pondération: <?php echo $type_examen_selected['pourcentage']; ?>%</small>
                            </div>
                            <div class="col-md-3">
                                <strong><i class="fas fa-book me-2"></i>Matière:</strong><br>
                                <?php echo escape($matiere_selected['nom']); ?>
                                <small class="d-block mt-1">
                                    Crédits: <?php echo $matiere_selected['credit'] ?? '?'; ?>
                                    <?php if (isset($matiere_selected['coefficient'])): ?>
                                    | Coef: <?php echo $matiere_selected['coefficient']; ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="col-md-2">
                                <strong><i class="fas fa-calendar me-2"></i>Semestre:</strong><br>
                                <?php echo escape($semestre_selected['nom'] ?? ''); ?>
                            </div>
                            <div class="col-md-2">
                                <strong><i class="fas fa-calendar-alt me-2"></i>Année:</strong><br>
                                <?php echo escape($annee_selected['annee'] ?? ''); ?>
                            </div>
                            <div class="col-md-2">
                                <strong><i class="fas fa-users me-2"></i>Étudiants:</strong><br>
                                <span class="badge bg-info mt-1"><?php echo count($etudiants); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Actions rapides -->
                    <div class="quick-actions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="remplirNotesAleatoires()">
                            <i class="fas fa-random me-1"></i> Notes aléatoires (test)
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="viderToutesLesNotes()">
                            <i class="fas fa-times-circle me-1"></i> Vider tout
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="remplirMoyenne10()">
                            <i class="fas fa-arrow-right me-1"></i> Remplir avec 10
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="remplirMoyenne15()">
                            <i class="fas fa-arrow-up me-1"></i> Remplir avec 15
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
                        
                        <!-- Statistiques -->
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <h4 class="text-primary"><?php echo count($etudiants); ?></h4>
                                        <p class="mb-0 text-muted">Étudiants</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <h4 class="text-success"><?php echo count($notes_existantes); ?></h4>
                                        <p class="mb-0 text-muted">Notes déjà saisies</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <button type="submit" class="btn btn-success btn-lg btn-action">
                                            <i class="fas fa-save me-2"></i> Sauvegarder toutes les notes
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Navigation rapide -->
                    <div class="content-card mt-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-arrows-alt-h me-2"></i> Navigation rapide</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-exchange-alt me-2"></i> Autres types d'examen:</h6>
                                    <div class="d-flex flex-wrap">
                                        <?php foreach ($types_examens as $type): 
                                            if ($type['id'] == $type_examen_id) continue;
                                        ?>
                                        <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type['id']; ?>&matiere_id=<?php echo $matiere_id; ?>" 
                                           class="btn btn-outline-secondary btn-sm me-2 mb-2">
                                            <i class="fas fa-arrow-right me-1"></i>
                                            <?php echo escape($type['nom']); ?>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-book-open me-2"></i> Autres matières:</h6>
                                    <div class="d-flex flex-wrap">
                                        <?php foreach (array_slice($matieres, 0, 5) as $matiere): 
                                            if ($matiere['id'] == $matiere_id) continue;
                                        ?>
                                        <a href="?classe_id=<?php echo $classe_id; ?>&semestre_id=<?php echo $semestre_id; ?>&annee_id=<?php echo $annee_id; ?>&type_examen_id=<?php echo $type_examen_id; ?>&matiere_id=<?php echo $matiere['id']; ?>" 
                                           class="btn btn-outline-primary btn-sm me-2 mb-2">
                                            <i class="fas fa-book me-1"></i>
                                            <?php echo substr(escape($matiere['nom']), 0, 15); ?>...
                                        </a>
                                        <?php endforeach; ?>
                                        <?php if (count($matieres) > 5): ?>
                                        <button class="btn btn-link btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#plusDeMatieres">
                                            <i class="fas fa-ellipsis-h me-1"></i>Voir plus...
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
            <div class="alert alert-warning text-center fade-in">
                <i class="fas fa-users fs-4"></i>
                <h5 class="mt-3">Aucun étudiant dans cette classe</h5>
                <p class="mb-0">Veuillez d'abord assigner des étudiants à cette classe.</p>
                <a href="etudiants.php" class="btn btn-primary mt-3">
                    <i class="fas fa-users me-2"></i> Gérer les étudiants
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Informations sur les pondérations -->
            <div class="content-card info-card mt-4 fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle me-2"></i> Informations sur les pondérations</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-calculator me-2"></i>Formule de calcul de la note finale:</h6>
                            <div class="alert alert-light">
                                <code>Note Finale = (DST × 20%) + (Recherche × 20%) + (Session × 60%)</code>
                            </div>
                            <p>Pour qu'une matière soit prise en compte dans le calcul de la moyenne générale, 
                            les 3 types de notes doivent être saisis pour chaque étudiant.</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-percentage me-2"></i>Types d'examens et pondérations:</h6>
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
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== FONCTIONS UTILITAIRES ==========
    
    // Toggle sidebar sur mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (window.innerWidth <= 576) {
            if (sidebar.style.display === 'block') {
                sidebar.style.display = 'none';
                mainContent.style.marginLeft = '0';
            } else {
                sidebar.style.display = 'block';
                sidebar.style.width = '250px';
                sidebar.style.position = 'fixed';
                sidebar.style.zIndex = '1000';
                mainContent.style.marginLeft = '0';
            }
        }
    }
    
    // Gérer le responsive du sidebar
    function handleSidebarResponsive() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 576) {
            sidebar.style.display = 'none';
            mainContent.style.marginLeft = '0';
            mobileBtn.style.display = 'block';
        } else if (window.innerWidth <= 992) {
            sidebar.style.display = 'block';
            sidebar.style.width = '70px';
            mainContent.style.marginLeft = '70px';
            mobileBtn.style.display = 'none';
        } else {
            sidebar.style.display = 'block';
            sidebar.style.width = '250px';
            mainContent.style.marginLeft = '250px';
            mobileBtn.style.display = 'none';
        }
    }
    
    // Valider une note
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
    
    // Afficher une notification
    function showNotification(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `custom-alert alert alert-${type} alert-dismissible fade show position-fixed`;
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 300px;';
        alert.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 
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
    
    // ========== INITIALISATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Gérer le responsive
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        
        // Bouton menu mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Validation en temps réel des notes
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
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Chargement...';
                    submitBtn.disabled = true;
                    document.body.classList.add('loading');
                }
            });
        }
        
        // Validation du formulaire de saisie
        const formNotes = document.getElementById('form-notes');
        if (formNotes) {
            formNotes.addEventListener('submit', function(e) {
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
                
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sauvegarde en cours...';
                    submitBtn.disabled = true;
                }
                
                return true;
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
        
        // Ajouter des tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    });
    </script>
</body>
</html>