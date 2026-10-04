<?php
// dashboard/dac/calcul_moyennes.php

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
function formatMoney($amount) {
    if (empty($amount) || $amount == 0) return '0 FCFA';
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function formatDateFr($date) {
    if (empty($date) || $date == '0000-00-00') return '';
    return date('d/m/Y', strtotime($date));
}

function formatDateTimeFr($datetime) {
    if (empty($datetime)) return '';
    return date('d/m/Y H:i', strtotime($datetime));
}

function getStatutBadge($statut) {
    $badges = [
        'actif' => 'success',
        'valide' => 'success',
        'present' => 'success',
        'admis' => 'success',
        'en_attente' => 'warning',
        'en_cours' => 'warning',
        'planifie' => 'warning',
        'brouillon' => 'secondary',
        'annule' => 'danger',
        'rejete' => 'danger',
        'absent' => 'danger',
        'termine' => 'info',
        'validee' => 'info',
        'publie' => 'primary'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst($statut) . '</span>';
}

// Fonction pour vérifier si une colonne existe
function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM $table LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Fonction pour vérifier si une table existe
function tableExists($db, $table_name) {
    try {
        $sql = "SHOW TABLES LIKE '$table_name'";
        $stmt = $db->query($sql);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// 4. FONCTIONS DE CALCUL DES MOYENNES
// ============================================
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

function getSemestres($db) {
    if (tableExists($db, 'semestres')) {
        try {
            $sql = "SELECT * FROM semestres ORDER BY numero";
            return $db->query($sql)->fetchAll();
        } catch (Exception $e) {
            // Continuer avec les semestres par défaut
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
            // Continuer avec l'année actuelle
        }
    }
    
    $current_year = date('Y');
    return [
        ['id' => 1, 'annee' => $current_year . '-' . ($current_year + 1)]
    ];
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

function calculerMoyennesEtudiants($db, $classe_id, $semestre_id, $annee_id) {
    $resultats = [];
    
    try {
        $sql_etudiants = "SELECT e.*, c.nom as classe_nom 
                          FROM etudiants e 
                          JOIN classes c ON e.classe_id = c.id 
                          WHERE e.classe_id = ? 
                          ORDER BY e.nom, e.prenom";
        
        $stmt_etudiants = $db->prepare($sql_etudiants);
        $stmt_etudiants->execute([$classe_id]);
        $etudiants = $stmt_etudiants->fetchAll();
        
        if (empty($etudiants)) {
            return [];
        }
        
        $matieres = getMatieresParClasse($db, $classe_id);
        
        if (empty($matieres)) {
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
            
            foreach ($matieres as $matiere) {
                $matiere_id = $matiere['id'];
                
                $sql_notes = "SELECT n.note, te.nom as type_examen, te.pourcentage 
                              FROM notes n 
                              JOIN types_examens te ON n.type_examen_id = te.id 
                              WHERE n.etudiant_id = ? 
                                AND n.matiere_id = ? 
                                AND n.semestre_id = ? 
                                AND n.annee_academique_id = ? 
                                AND n.statut = 'valide'";
                
                $stmt_notes = $db->prepare($sql_notes);
                $stmt_notes->execute([$etudiant['id'], $matiere_id, $semestre_id, $annee_id]);
                $notes = $stmt_notes->fetchAll();
                
                $dst_note = null;
                $recherche_note = null;
                $session_note = null;
                
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
                
                $note_finale = null;
                if ($dst_note !== null && $recherche_note !== null && $session_note !== null) {
                    $note_finale = ($dst_note * 0.20) + ($recherche_note * 0.20) + ($session_note * 0.60);
                    $note_finale = round($note_finale, 2);
                }
                
                $credit = isset($matiere['credit']) ? $matiere['credit'] : (isset($matiere['coefficient']) ? $matiere['coefficient'] : 1);
                $points = $note_finale !== null ? round($note_finale * $credit, 2) : null;
                
                if ($note_finale !== null) {
                    $etudiant_result['total_points'] += $points;
                    $etudiant_result['total_credits'] += $credit;
                }
                
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
            
            if ($etudiant_result['total_credits'] > 0) {
                $etudiant_result['moyenne_generale'] = round($etudiant_result['total_points'] / $etudiant_result['total_credits'], 2);
                
                if ($etudiant_result['moyenne_generale'] >= 10) {
                    $etudiant_result['observation'] = 'Validé';
                    $etudiant_result['mention'] = getMention($etudiant_result['moyenne_generale']);
                }
            }
            
            $resultats[$etudiant['id']] = $etudiant_result;
        }
        
        uasort($resultats, function($a, $b) {
            if ($a['moyenne_generale'] === null && $b['moyenne_generale'] === null) return 0;
            if ($a['moyenne_generale'] === null) return 1;
            if ($b['moyenne_generale'] === null) return -1;
            return $b['moyenne_generale'] <=> $a['moyenne_generale'];
        });
        
        $rang = 1;
        foreach ($resultats as &$resultat) {
            $resultat['rang'] = $rang++;
        }
        
    } catch (Exception $e) {
        error_log("Erreur calculerMoyennesEtudiants: " . $e->getMessage());
        return [];
    }
    
    return $resultats;
}

function getMention($moyenne) {
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Non admis';
}

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
            if ($stats['meilleure_moyenne'] === null || $resultat['moyenne_generale'] > $stats['meilleure_moyenne']) {
                $stats['meilleure_moyenne'] = $resultat['moyenne_generale'];
                $stats['meilleur_etudiant'] = $resultat['etudiant']['nom'] . ' ' . $resultat['etudiant']['prenom'];
            }
            
            if ($stats['pire_moyenne'] === null || $resultat['moyenne_generale'] < $stats['pire_moyenne']) {
                $stats['pire_moyenne'] = $resultat['moyenne_generale'];
                $stats['pire_etudiant'] = $resultat['etudiant']['nom'] . ' ' . $resultat['etudiant']['prenom'];
            }
            
            $stats['total_points_classe'] += $resultat['total_points'];
            $stats['total_credits_classe'] += $resultat['total_credits'];
        }
    }
    
    if ($stats['total_credits_classe'] > 0) {
        $stats['moyenne_classe'] = round($stats['total_points_classe'] / $stats['total_credits_classe'], 2);
    }
    
    $stats['taux_admission'] = $stats['total_etudiants'] > 0 ? round(($stats['admis'] / $stats['total_etudiants']) * 100, 1) : 0;
    $stats['taux_echec'] = $stats['total_etudiants'] > 0 ? round(($stats['ajournes'] / $stats['total_etudiants']) * 100, 1) : 0;
    
    return $stats;
}

// ============================================
// 5. RÉCUPÉRATION DES DONNÉES
// ============================================
$classe_id = $_GET['classe_id'] ?? $_POST['classe_id'] ?? null;
$semestre_id = $_GET['semestre_id'] ?? $_POST['semestre_id'] ?? null;
$annee_id = $_GET['annee_id'] ?? $_POST['annee_id'] ?? null;

$classes = getClasses($db);
$semestres = getSemestres($db);
$annees = getAnneesAcademiques($db);

$resultats = [];
$statistiques = [];
$classe_selected = null;
$semestre_selected = null;
$annee_selected = null;

if ($classe_id && $semestre_id && $annee_id) {
    $resultats = calculerMoyennesEtudiants($db, $classe_id, $semestre_id, $annee_id);
    $statistiques = calculerStatistiques($resultats);
    
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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcul des Moyennes - DAC | ISGI</title>
    
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
        
        .nav-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
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
        
        /* ========== STYLES SPÉCIFIQUES AU CALCUL DES MOYENNES ========== */
        .form-card {
            background: #e8f4fd;
            border-left: 4px solid #0d6efd;
        }
        
        .stats-card {
            background: #f0f9ff;
        }
        
        .result-card {
            background: #f8fff8;
            border-left: 4px solid #28a745;
        }
        
        .debug-card {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            font-size: 0.9em;
        }
        
        .table-custom th {
            background-color: #f1f3f4;
            vertical-align: middle;
        }
        
        .table-success {
            background-color: #d1e7dd !important;
        }
        
        .table-danger {
            background-color: #f8d7da !important;
        }
        
        .table-warning {
            background-color: #fff3cd !important;
        }
        
        .note-cell {
            text-align: center;
            font-weight: bold;
        }
        
        .badge-success { background-color: #198754; }
        .badge-danger { background-color: #dc3545; }
        .badge-warning { background-color: #ffc107; color: #000; }
        .badge-primary { background-color: #0d6efd; }
        
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
            font-size: 0.9em;
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
            border-radius: 50px;
            padding: 12px 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .debug-table td, .debug-table th {
            padding: 4px 8px;
            border: 1px solid #dee2e6;
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
            .nav-link span,
            .nav-badge {
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
            
            .stat-value {
                font-size: 1.8rem;
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
                    <a href="saisie_notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Saisir des notes</span>
                    </a>
                    <a href="calcul_moyennes.php" class="nav-link active">
                        <i class="fas fa-calculator"></i>
                        <span>Resultats des moyennes</span>
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
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Gestion présence</span>
                    </a>
                </div>
                
                <!-- Calendrier & Examens -->
                <div class="nav-section">
                    <div class="nav-section-title">Calendrier & Examens</div>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier académique</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier examens</span>
                    </a>
                </div>
                
                <!-- Rapports & Statistiques -->
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Statistiques</div>
                    <a href="rapports_academiques.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports académiques</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques détaillées</span>
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
                            <i class="fas fa-calculator me-2"></i>
                            Calcul des Moyennes Générales
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
                        <a href="dashboard.php" class="btn btn-outline-info btn-action">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="content-card form-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-filter me-2"></i> Paramètres de Calcul</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <!-- Classe -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Classe *</label>
                            <select name="classe_id" class="form-select" required>
                                <option value="">Sélectionnez une classe</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>" <?php echo ($classe_id == $classe['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($classe['nom']); ?>
                                        <?php if (isset($classe['filiere_nom'])): ?>
                                        (<?php echo htmlspecialchars($classe['filiere_nom']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Semestre -->
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Semestre *</label>
                            <select name="semestre_id" class="form-select" required>
                                <option value="">Sélectionnez un semestre</option>
                                <?php foreach ($semestres as $semestre): ?>
                                    <option value="<?php echo $semestre['id']; ?>" <?php echo ($semestre_id == $semestre['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($semestre['nom'] ?? 'Semestre ' . $semestre['numero']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Année académique -->
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Année académique *</label>
                            <select name="annee_id" class="form-select" required>
                                <option value="">Sélectionnez une année</option>
                                <?php foreach ($annees as $annee): ?>
                                    <option value="<?php echo $annee['id']; ?>" <?php echo ($annee_id == $annee['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($annee['annee'] ?? $annee['libelle'] ?? $annee['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100 btn-action">
                                <i class="fas fa-calculator"></i> Calculer
                            </button>
                        </div>
                    </form>
                    
                    <!-- Formule -->
                    <div class="mt-4">
                        <p class="mb-2"><strong><i class="fas fa-calculator me-2"></i>Formule de calcul:</strong></p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="formule">
                                    <strong>Note Finale =</strong> (DST × 0.20) + (Devoir de Recherche × 0.20) + (Session × 0.60)
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="formule">
                                    <strong>Moyenne Générale =</strong> Σ(Note Finale × Crédit) ÷ Σ(Crédits)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($resultats) && $classe_selected): ?>
            <!-- Statistiques globales -->
            <div class="content-card stats-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i> Statistiques de la Classe</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                            <div class="card stat-card text-white bg-primary">
                                <div class="card-body text-center">
                                    <h1 class="display-6"><?php echo $statistiques['total_etudiants']; ?></h1>
                                    <p class="mb-0">Étudiants</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                            <div class="card stat-card text-white bg-success">
                                <div class="card-body text-center">
                                    <h1 class="display-6"><?php echo $statistiques['admis']; ?></h1>
                                    <p class="mb-0">Admis</p>
                                    <small><?php echo $statistiques['taux_admission']; ?>%</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                            <div class="card stat-card text-white bg-danger">
                                <div class="card-body text-center">
                                    <h1 class="display-6"><?php echo $statistiques['ajournes']; ?></h1>
                                    <p class="mb-0">Ajournés</p>
                                    <small><?php echo $statistiques['taux_echec']; ?>%</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="card stat-card text-white bg-warning">
                                <div class="card-body text-center">
                                    <h1 class="display-6"><?php echo $statistiques['meilleure_moyenne'] ?? 'N/A'; ?></h1>
                                    <p class="mb-0">Meilleure moyenne</p>
                                    <small><?php echo $statistiques['meilleur_etudiant'] ?? ''; ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="card stat-card text-white bg-secondary">
                                <div class="card-body text-center">
                                    <h1 class="display-6"><?php echo $statistiques['moyenne_classe'] ?? 'N/A'; ?></h1>
                                    <p class="mb-0">Moyenne de classe</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informations -->
                    <div class="alert alert-light mt-3">
                        <div class="row">
                            <div class="col-md-4">
                                <strong><i class="fas fa-users me-1"></i> Classe:</strong> 
                                <?php echo htmlspecialchars($classe_selected['nom']); ?>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="fas fa-calendar me-1"></i> Semestre:</strong> 
                                <?php echo htmlspecialchars($semestre_selected['nom'] ?? 'Semestre ' . $semestre_selected['numero']); ?>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="fas fa-calendar-alt me-1"></i> Année académique:</strong> 
                                <?php echo htmlspecialchars($annee_selected['annee'] ?? $annee_selected['libelle'] ?? ''); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Résultats détaillés -->
            <div class="content-card result-card fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-list-ol me-2"></i> Classement des Étudiants</h5>
                    <div>
                        <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
                
                <div class="card-body">
                    <?php if (empty($resultats[array_key_first($resultats)]['matieres'])): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Attention:</strong> Aucune matière n'a été trouvée pour cette classe.
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
                                            <strong><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></strong>
                                            <small class="ms-2">(<?php echo htmlspecialchars($etudiant['matricule']); ?>)</small>
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
                                                    <td><?php echo htmlspecialchars($matiere['nom']); ?></td>
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
                                        <i class="fas fa-exclamation-triangle"></i>
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
                        <h5><i class="fas fa-table me-2"></i> Tableau Récapitulatif</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
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
                                        <td><?php echo htmlspecialchars($etudiant['matricule']); ?></td>
                                        <td><?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?></td>
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
                <i class="fas fa-print me-2"></i> Imprimer
            </button>
            
            <?php elseif ($classe_id && $semestre_id && $annee_id): ?>
            <!-- Message si pas de résultats -->
            <div class="alert alert-warning text-center fade-in">
                <i class="fas fa-exclamation-triangle fs-4"></i>
                <h5 class="mt-3">Aucun résultat trouvé</h5>
                <p class="mb-0">Aucune note n'a été saisie pour cette classe, ce semestre et cette année académique.</p>
                <p class="mb-0">Veuillez d'abord saisir les notes (DST, Devoir de Recherche, Session) pour tous les étudiants.</p>
                <div class="mt-3">
                    <a href="notes.php" class="btn btn-primary">
                        <i class="fas fa-pencil-square"></i> Saisir des notes
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Informations sur le calcul -->
            <div class="content-card mt-4 fade-in">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle me-2"></i> Informations sur le calcul des moyennes</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-calculator me-2"></i>Formules utilisées:</h6>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item">
                                    <strong>Note finale par matière:</strong> 
                                    <div class="formule mt-1">
                                        (DST × 0.20) + (Recherche × 0.20) + (Session × 0.60)
                                    </div>
                                </li>
                                <li class="list-group-item">
                                    <strong>Points par matière:</strong> 
                                    <div class="formule mt-1">
                                        Note finale × Crédits de la matière
                                    </div>
                                </li>
                                <li class="list-group-item">
                                    <strong>Moyenne générale:</strong> 
                                    <div class="formule mt-1">
                                        Σ(Points) ÷ Σ(Crédits)
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-award me-2"></i>Seuils et mentions:</h6>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <span class="badge bg-success w-100 p-2">Validé</span>
                                    <small class="d-block mt-1">Moyenne ≥ 10/20</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <span class="badge bg-danger w-100 p-2">Ajourné</span>
                                    <small class="d-block mt-1">Moyenne < 10/20</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <span class="badge bg-warning w-100 p-2">Passable</span>
                                    <small class="d-block mt-1">10 ≤ Moyenne < 12</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <span class="badge bg-warning w-100 p-2">Assez Bien</span>
                                    <small class="d-block mt-1">12 ≤ Moyenne < 14</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <span class="badge bg-warning w-100 p-2">Bien</span>
                                    <small class="d-block mt-1">14 ≤ Moyenne < 16</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <span class="badge bg-warning w-100 p-2">Très Bien</span>
                                    <small class="d-block mt-1">Moyenne ≥ 16</small>
                                </div>
                            </div>
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
            // Mobile: sidebar caché par défaut
            sidebar.style.display = 'none';
            mainContent.style.marginLeft = '0';
            mobileBtn.style.display = 'block';
        } else if (window.innerWidth <= 992) {
            // Tablet: sidebar réduit
            sidebar.style.display = 'block';
            sidebar.style.width = '70px';
            mainContent.style.marginLeft = '70px';
            mobileBtn.style.display = 'none';
        } else {
            // Desktop: sidebar complet
            sidebar.style.display = 'block';
            sidebar.style.width = '250px';
            mainContent.style.marginLeft = '250px';
            mobileBtn.style.display = 'none';
        }
    }
    
    // ========== INITIALISATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Gérer le responsive
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        
        // Bouton menu mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Gérer l'impression
        window.addEventListener('beforeprint', function() {
            document.querySelectorAll('.print-btn, .mobile-menu-btn, .sidebar').forEach(el => {
                el.style.display = 'none';
            });
            
            document.querySelectorAll('.accordion-collapse').forEach(collapse => {
                collapse.classList.add('show');
            });
        });
        
        window.addEventListener('afterprint', function() {
            document.querySelectorAll('.print-btn, .mobile-menu-btn').forEach(el => {
                el.style.display = '';
            });
            handleSidebarResponsive();
        });
        
        // Auto-submit si paramètres présents
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('classe_id') && urlParams.has('semestre_id') && urlParams.has('annee_id')) {
            console.log('Paramètres de calcul présents');
        }
        
        // Ajouter des tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    });
    </script>
</body>
</html>