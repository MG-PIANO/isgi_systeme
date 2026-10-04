<?php
// dashboard/dac/assignation_matieres.php

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

// ============================================
// 4. FONCTIONS D'ASSIGNATION MATIERES-CLASSES
// ============================================
function getClasses($db) {
    try {
        $site_id = $_SESSION['site_id'] ?? 1;
        $sql = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
                FROM classes c
                LEFT JOIN filieres f ON c.filiere_id = f.id
                LEFT JOIN niveaux n ON c.niveau_id = n.id
                LEFT JOIN sites s ON c.site_id = s.id
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

function getMatieres($db) {
    try {
        $site_id = $_SESSION['site_id'] ?? 1;
        $sql = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle, s.nom as site_nom
                FROM matieres m
                LEFT JOIN filieres f ON m.filiere_id = f.id
                LEFT JOIN niveaux n ON m.niveau_id = n.id
                LEFT JOIN sites s ON m.site_id = s.id
                WHERE m.site_id = ?
                ORDER BY f.nom, n.ordre, m.code";
        $stmt = $db->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur getMatieres: " . $e->getMessage());
        return [];
    }
}

function getMatieresClasse($db, $classe_id) {
    try {
        $sql = "SELECT c.filiere_id, c.niveau_id, c.site_id 
                FROM classes c 
                WHERE c.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id]);
        $classe = $stmt->fetch();
        
        if (!$classe) return [];
        
        $sql = "SELECT m.* FROM matieres m 
                WHERE m.filiere_id = ? 
                AND m.niveau_id = ? 
                AND m.site_id = ?
                ORDER BY m.code";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $classe['filiere_id'], 
            $classe['niveau_id'],
            $classe['site_id']
        ]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur getMatieresClasse: " . $e->getMessage());
        return [];
    }
}

// Fonction pour obtenir le nom d'une classe par son ID
function getClasseNom($db, $classe_id) {
    try {
        $sql = "SELECT nom FROM classes WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id]);
        $result = $stmt->fetch();
        return $result ? $result['nom'] : "ID: $classe_id";
    } catch (Exception $e) {
        error_log("Erreur getClasseNom: " . $e->getMessage());
        return "ID: $classe_id";
    }
}

// Fonction modifiée pour vérifier si la matière existe déjà
function matiereExisteDeja($db, $matiere_code, $filiere_id, $niveau_id, $site_id) {
    try {
        $sql = "SELECT COUNT(*) as count FROM matieres 
                WHERE code = ? AND filiere_id = ? AND niveau_id = ? AND site_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$matiere_code, $filiere_id, $niveau_id, $site_id]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Erreur matiereExisteDeja: " . $e->getMessage());
        return false;
    }
}

// Fonction pour générer un code unique
function genererCodeUnique($db, $code_base, $filiere_id, $niveau_id, $site_id) {
    $code_final = $code_base;
    $suffixe = 1;
    
    // Vérifier si le code de base existe déjà
    $existe = matiereExisteDeja($db, $code_final, $filiere_id, $niveau_id, $site_id);
    
    if (!$existe) {
        return $code_final;
    }
    
    // Chercher un suffixe disponible
    do {
        $code_final = $code_base . '_' . $suffixe;
        $suffixe++;
        $existe = matiereExisteDeja($db, $code_final, $filiere_id, $niveau_id, $site_id);
    } while ($existe);
    
    return $code_final;
}

function assignerMatiere($db, $matiere_id, $classe_id) {
    try {
        // Récupérer les infos de la matière source
        $sql = "SELECT * FROM matieres WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$matiere_id]);
        $matiere = $stmt->fetch();
        
        if (!$matiere) return ['success' => false, 'message' => 'Matière non trouvée'];
        
        // Récupérer les infos de la classe
        $sql = "SELECT * FROM classes WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id]);
        $classe = $stmt->fetch();
        
        if (!$classe) return ['success' => false, 'message' => 'Classe non trouvée'];
        
        // Vérifier si une matière avec le même code existe déjà pour cette filière/niveau/site
        $existe_deja = matiereExisteDeja($db, $matiere['code'], $classe['filiere_id'], $classe['niveau_id'], $classe['site_id']);
        
        if ($existe_deja) {
            // Trouver la matière existante pour donner plus d'informations
            $sql = "SELECT m.* FROM matieres m 
                    WHERE m.code = ? AND m.filiere_id = ? AND m.niveau_id = ? AND m.site_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $matiere['code'],
                $classe['filiere_id'],
                $classe['niveau_id'],
                $classe['site_id']
            ]);
            $matiere_existante = $stmt->fetch();
            
            return [
                'success' => false,
                'message' => 'Cette matière existe déjà pour cette classe',
                'matiere_existante' => $matiere_existante
            ];
        }
        
        // Générer un code unique si nécessaire
        $code_final = genererCodeUnique($db, $matiere['code'], $classe['filiere_id'], $classe['niveau_id'], $classe['site_id']);
        
        // Créer une copie de la matière pour cette classe
        $sql_insert = "INSERT INTO matieres (code, nom, credit, coefficient, filiere_id, niveau_id, site_id, enseignant_id) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql_insert);
        $success = $stmt->execute([
            $code_final,
            $matiere['nom'],
            $matiere['credit'],
            $matiere['coefficient'],
            $classe['filiere_id'],
            $classe['niveau_id'],
            $classe['site_id'],
            $matiere['enseignant_id'] ?? null
        ]);
        
        return [
            'success' => $success,
            'message' => $success ? 'Matière assignée avec succès' : 'Erreur d\'insertion',
            'code_utilise' => $code_final
        ];
        
    } catch (PDOException $e) {
        error_log("Erreur assignerMatiere: " . $e->getMessage());
        
        // Message d'erreur personnalisé pour les doublons
        if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), '23000') !== false) {
            return [
                'success' => false,
                'message' => 'Cette matière existe déjà pour cette classe'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Erreur lors de l\'assignation'
        ];
    }
}

function creerMatiereSimple($db, $data) {
    try {
        // Vérifier si une matière avec le même code existe déjà
        $existe_deja = matiereExisteDeja($db, $data['code'], $data['filiere_id'], $data['niveau_id'], $data['site_id']);
        
        if ($existe_deja) {
            return [
                'success' => false,
                'message' => 'Une matière avec ce code existe déjà pour cette filière/niveau/site'
            ];
        }
        
        // Générer un code unique si nécessaire
        $code_final = genererCodeUnique($db, $data['code'], $data['filiere_id'], $data['niveau_id'], $data['site_id']);
        
        $sql = "INSERT INTO matieres (code, nom, credit, coefficient, filiere_id, niveau_id, site_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
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
            'message' => $success ? 'Matière créée avec succès' : 'Erreur lors de la création'
        ];
        
    } catch (PDOException $e) {
        error_log("Erreur creerMatiereSimple: " . $e->getMessage());
        
        // Message d'erreur personnalisé pour les doublons
        if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), '23000') !== false) {
            return [
                'success' => false,
                'message' => 'Une matière avec ce code existe déjà pour cette filière/niveau/site'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Erreur lors de la création de la matière'
        ];
    }
}

// ============================================
// 5. TRAITEMENT DU FORMULAIRE
// ============================================
$message = '';
$message_type = '';
$details = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assigner':
                if (isset($_POST['matiere_id']) && isset($_POST['classe_id'])) {
                    $result = assignerMatiere($db, $_POST['matiere_id'], $_POST['classe_id']);
                    
                    if ($result['success']) {
                        $message = $result['message'];
                        $message_type = 'success';
                        
                        if (isset($result['code_utilise'])) {
                            $details = "Code utilisé : '{$result['code_utilise']}'";
                        }
                    } else {
                        $message = $result['message'];
                        $message_type = 'warning';
                        
                        // Ajouter des détails si une matière similaire existe
                        if (isset($result['matiere_existante'])) {
                            $matiere = $result['matiere_existante'];
                            $details = "Matière existante : '{$matiere['code']} - {$matiere['nom']}'";
                        }
                    }
                }
                break;
                
            case 'creer_assigner':
                if (!empty($_POST['code']) && !empty($_POST['nom']) && !empty($_POST['classe_id'])) {
                    // Récupérer les infos de la classe
                    $sql = "SELECT filiere_id, niveau_id, site_id FROM classes WHERE id = ?";
                    $stmt = $db->prepare($sql);
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
                        
                        $result = creerMatiereSimple($db, $data);
                        
                        if ($result['success']) {
                            $message = $result['message'];
                            $message_type = 'success';
                            
                            if (isset($result['code_utilise'])) {
                                $details = "Code utilisé : '{$result['code_utilise']}'";
                            }
                        } else {
                            $message = $result['message'];
                            $message_type = 'warning';
                        }
                    }
                }
                break;
                
            case 'assigner_rapide':
                if (isset($_POST['matiere_id']) && isset($_POST['classes_ids'])) {
                    $success = 0;
                    $total = 0;
                    $erreurs = [];
                    $avertissements = [];
                    $matiere_info = null;
                    
                    // Récupérer le nom de la matière pour les messages
                    $sql = "SELECT code, nom FROM matieres WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$_POST['matiere_id']]);
                    $matiere_info = $stmt->fetch();
                    
                    $matiere_nom = $matiere_info ? $matiere_info['nom'] : 'la matière';
                    $matiere_code = $matiere_info ? $matiere_info['code'] : '';
                    
                    foreach ($_POST['classes_ids'] as $classe_id) {
                        $result = assignerMatiere($db, $_POST['matiere_id'], $classe_id);
                        $total++;
                        
                        if ($result['success']) {
                            $success++;
                        } else {
                            // Obtenir le nom de la classe pour le message
                            $classe_nom = getClasseNom($db, $classe_id);
                            
                            if (isset($result['matiere_existante'])) {
                                $avertissements[] = "Classe <strong>'$classe_nom'</strong> : $matiere_nom ($matiere_code) existe déjà";
                            } else {
                                // Message plus simple pour l'utilisateur
                                $erreurs[] = "Classe <strong>'$classe_nom'</strong> : $matiere_nom ($matiere_code) n'a pas pu être assignée";
                            }
                        }
                    }
                    
                    if ($success == $total) {
                        $message = "Toutes les classes ($success) ont reçu la matière '$matiere_nom'";
                        $message_type = 'success';
                    } elseif ($success > 0) {
                        $message = "$success classe(s) sur $total ont reçu la matière '$matiere_nom'";
                        $message_type = 'warning';
                    } else {
                        $message = "Aucune classe n'a pu recevoir la matière '$matiere_nom'";
                        $message_type = 'danger';
                    }
                    
                    // Construire les détails de manière plus lisible
                    $details_lines = [];
                    
                    if (!empty($avertissements)) {
                        $details_lines[] = "<strong>Informations :</strong>";
                        $details_lines = array_merge($details_lines, $avertissements);
                    }
                    
                    if (!empty($erreurs)) {
                        if (!empty($avertissements)) {
                            $details_lines[] = "";
                        }
                        $details_lines[] = "<strong>Problèmes rencontrés :</strong>";
                        $details_lines = array_merge($details_lines, $erreurs);
                    }
                    
                    if (!empty($details_lines)) {
                        $details = implode('<br>', $details_lines);
                    }
                }
                break;
        }
    }
}

// ============================================
// 6. RÉCUPÉRATION DES DONNÉES
// ============================================
$classes = getClasses($db);
$matieres = getMatieres($db);

$classe_selected = isset($_GET['classe']) ? intval($_GET['classe']) : (isset($classes[0]) ? $classes[0]['id'] : null);
$matieres_classe = $classe_selected ? getMatieresClasse($db, $classe_selected) : [];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignation Matières/Classes - DAC | ISGI</title>
    
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
        
        /* ========== STYLES SPÉCIFIQUES ========== */
        .classe-card { 
            cursor: pointer; 
            transition: all 0.3s; 
            border: 2px solid transparent;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .classe-card:hover { 
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            background-color: #f8f9fa;
        }
        
        .classe-card.selected { 
            border-color: var(--info-color); 
            background-color: #e8f4fd;
            box-shadow: 0 4px 10px rgba(23, 162, 184, 0.2);
        }
        
        .matiere-item { 
            background: white; 
            border: 1px solid #e9ecef; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .matiere-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .matiere-assigned { 
            border-left: 4px solid var(--success-color); 
            background-color: #f8fff9; 
        }
        
        .badge-small { 
            font-size: 0.75em; 
            padding: 4px 8px;
            border-radius: 6px;
        }
        
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
                border-left: none;
                border-right: 3px solid transparent;
            }
            
            .nav-link:hover {
                border-right-color: var(--info-color);
                border-left-color: transparent;
            }
            
            .nav-link.active {
                border-right-color: var(--info-color);
                border-left-color: transparent;
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
        }
        
        @media (max-width: 576px) {
            .sidebar {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                z-index: 1050;
                width: 250px !important;
            }
            
            .sidebar.show {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
            
            .nav-link {
                justify-content: flex-start;
                padding: 12px 15px;
                border-left: 3px solid transparent;
                border-right: none;
            }
            
            .nav-link:hover {
                border-left-color: var(--info-color);
                border-right-color: transparent;
            }
            
            .nav-link.active {
                border-left-color: var(--info-color);
                border-right-color: transparent;
            }
            
            .nav-link span {
                display: inline !important;
            }
            
            .nav-section-title {
                display: block !important;
            }
            
            .user-info {
                display: block !important;
            }
        }
        
        /* ========== BOUTON MOBILE ========== */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1051;
            background: var(--info-color);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            font-size: 20px;
        }
        
        /* ========== STYLES POUR MESSAGES D'ERREUR ========== */
        .error-details {
            background-color: #f8f9fa;
            border-left: 4px solid var(--warning-color);
            padding: 12px 15px;
            margin-top: 10px;
            border-radius: 0 8px 8px 0;
        }
        
        .error-details strong {
            color: var(--warning-color);
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
                        <span>Dashboard</span>
                    </a>
                </div>
                
                <!-- Matières & Classes -->
                <div class="nav-section">
                    <div class="nav-section-title">Matières & Classes</div>
                    <a href="assignation_matieres.php" class="nav-link active">
                        <i class="fas fa-link"></i>
                        <span>Assignation matières</span>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="saisie_notes.php" class="nav-link">
                        <i class="fas fa-pencil-alt"></i>
                        <span>Saisie des notes</span>
                    </a>
                    <a href="calcul_moyennes.php" class="nav-link">
                        <i class="fas fa-calculator"></i>
                        <span>Calcul des moyennes</span>
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
                            <i class="fas fa-link me-2"></i>
                            Assignation Matières ↔ Classes
                        </h1>
                        <p class="page-subtitle">
                            Directeur des Affaires Académiques | 
                            Site: <strong><?php echo htmlspecialchars($_SESSION['site_name'] ?? 'ISGI'); ?></strong>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Message de confirmation -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <div class="me-3">
                        <i class="fas fa-<?php 
                            echo $message_type === 'success' ? 'check-circle' : 
                                 ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); 
                        ?>" style="font-size: 1.5rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2"><?php echo $message; ?></h5>
                        <?php if ($details): ?>
                        <div class="error-details mt-3 small">
                            <?php echo $details; ?>
                        </div>
                        <?php endif; ?>
                        <button type="button" class="btn-close mt-3" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Colonne gauche : Classes -->
                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-building me-2"></i> Classes disponibles</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($classes)): ?>
                                <div class="alert alert-warning text-center py-4">
                                    <i class="fas fa-exclamation-triangle fs-4"></i>
                                    <h5 class="mt-3">Aucune classe disponible</h5>
                                </div>
                            <?php else: ?>
                                <?php foreach ($classes as $classe): 
                                    $matieres_count = count(getMatieresClasse($db, $classe['id']));
                                ?>
                                <div class="classe-card <?php echo ($classe_selected == $classe['id']) ? 'selected' : ''; ?>"
                                     onclick="window.location.href='?classe=<?php echo $classe['id']; ?>'">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong class="d-block"><?php echo escape($classe['nom']); ?></strong>
                                            <div class="small mt-1">
                                                <span class="badge bg-info badge-small"><?php echo escape($classe['site_nom']); ?></span>
                                                <span class="badge bg-success badge-small"><?php echo escape($classe['filiere_nom']); ?></span>
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
                </div>
                
                <!-- Colonne droite : Matières de la classe -->
                <div class="col-lg-8">
                    <?php if ($classe_selected): 
                        $classe_actuelle = null;
                        foreach ($classes as $c) {
                            if ($c['id'] == $classe_selected) {
                                $classe_actuelle = $c;
                                break;
                            }
                        }
                    ?>
                        <div class="content-card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-book me-2"></i> 
                                        Matières de : <?php echo escape($classe_actuelle['nom']); ?>
                                    </h5>
                                    <div>
                                        <span class="badge bg-light text-dark">
                                            <?php echo escape($classe_actuelle['site_nom']); ?> - 
                                            <?php echo escape($classe_actuelle['filiere_nom']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($matieres_classe)): ?>
                                    <div class="alert alert-info text-center py-4">
                                        <i class="fas fa-info-circle fs-4"></i>
                                        <h5 class="mt-3">Cette classe n'a aucune matière</h5>
                                        <p>Utilisez le formulaire ci-dessous pour ajouter des matières.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="row">
                                        <?php foreach ($matieres_classe as $matiere): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="matiere-item matiere-assigned">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <strong class="text-primary"><?php echo escape($matiere['code']); ?></strong>
                                                        </h6>
                                                        <p class="mb-1 fw-bold"><?php echo escape($matiere['nom']); ?></p>
                                                        <div class="mt-2">
                                                            <span class="badge bg-primary badge-small">
                                                                Crédit: <?php echo $matiere['credit']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i> Assignée
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Formulaire pour assigner une matière -->
                                <div class="content-card mt-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i> Ajouter une matière</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-4">
                                            <h6><i class="fas fa-link me-2"></i> Assigner une matière existante</h6>
                                            <form method="POST" action="" class="row g-3">
                                                <input type="hidden" name="action" value="assigner">
                                                <input type="hidden" name="classe_id" value="<?php echo $classe_selected; ?>">
                                                
                                                <div class="col-md-8">
                                                    <select name="matiere_id" class="form-select" required>
                                                        <option value="">-- Sélectionner une matière --</option>
                                                        <?php foreach ($matieres as $matiere): ?>
                                                            <option value="<?php echo $matiere['id']; ?>">
                                                                <?php echo escape($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 d-flex align-items-end">
                                                    <button type="submit" class="btn btn-primary w-100 btn-action">
                                                        <i class="fas fa-link"></i> Assigner
                                                    </button>
                                                </div>
                                                <div class="col-12">
                                                    <small class="text-muted">
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        Si la matière existe déjà pour cette classe, vous serez averti.
                                                    </small>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <div class="pt-3 border-top">
                                            <h6><i class="fas fa-plus me-2"></i> Créer une nouvelle matière</h6>
                                            <form method="POST" action="" class="row g-3">
                                                <input type="hidden" name="action" value="creer_assigner">
                                                <input type="hidden" name="classe_id" value="<?php echo $classe_selected; ?>">
                                                
                                                <div class="col-lg-4 col-md-6">
                                                    <input type="text" name="code" class="form-control" 
                                                           placeholder="Code (ex: MATH101)" required>
                                                </div>
                                                <div class="col-lg-5 col-md-6">
                                                    <input type="text" name="nom" class="form-control" 
                                                           placeholder="Nom de la matière" required>
                                                </div>
                                                <div class="col-lg-3 col-md-12 d-flex align-items-end">
                                                    <button type="submit" class="btn btn-success w-100 btn-action">
                                                        <i class="fas fa-plus"></i> Créer
                                                    </button>
                                                </div>
                                                <div class="col-12">
                                                    <small class="text-muted">
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        Si une matière avec ce code existe déjà, vous serez averti.
                                                    </small>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="content-card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-mouse-pointer fs-1 text-muted"></i>
                                <h5 class="mt-3">Sélectionnez une classe</h5>
                                <p class="text-muted">Cliquez sur une classe à gauche pour voir ses matières</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Toggle sidebar sur mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('show');
    }
    
    // Gérer le responsive
    function handleSidebarResponsive() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 576) {
            sidebar.classList.remove('show');
            sidebar.style.width = '';
            mainContent.style.marginLeft = '0';
            mobileBtn.style.display = 'block';
        } else if (window.innerWidth <= 992) {
            sidebar.classList.add('show');
            sidebar.style.width = '70px';
            mainContent.style.marginLeft = '70px';
            mobileBtn.style.display = 'none';
        } else {
            sidebar.classList.add('show');
            sidebar.style.width = '250px';
            mainContent.style.marginLeft = '250px';
            mobileBtn.style.display = 'none';
        }
    }
    
    // Fermer le sidebar si on clique en dehors (sur mobile)
    function closeSidebarOnClickOutside(event) {
        const sidebar = document.getElementById('sidebar');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 576 && 
            !sidebar.contains(event.target) && 
            !mobileBtn.contains(event.target) &&
            sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        document.addEventListener('click', closeSidebarOnClickOutside);
        
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Auto-focus sur le champ code
        const codeInput = document.querySelector('input[name="code"]');
        if (codeInput) {
            setTimeout(() => codeInput.focus(), 100);
        }
        
        // Fermer les alertes automatiquement après 5 secondes
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
    </script>
</body>
</html>