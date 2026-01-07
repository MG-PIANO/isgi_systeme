<?php
// dashboard/dac/saisie_notes.php

// Activer l'affichage des erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Définir le chemin vers la racine
define('ROOT_PATH', dirname(dirname(__DIR__)));

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur a le rôle DAC (ID 5)
if ($_SESSION['role_id'] != 5) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Accès Refusé</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center;'>
        <div class='card shadow-lg' style='width: 100%; max-width: 500px;'>
            <div class='card-header bg-danger text-white'>
                <h4 class='mb-0'><i class='fas fa-ban'></i> Accès Refusé</h4>
            </div>
            <div class='card-body text-center'>
                <div class='alert alert-warning'>
                    <h5>Vous n'avez pas les droits nécessaires !</h5>
                    <p class='mb-0'>Cette page est réservée au Directeur des Affaires Académiques (DAC).</p>
                </div>
                <p><strong>Votre rôle :</strong> " . ($_SESSION['role_nom'] ?? 'Non défini') . "</p>
                <p><strong>Rôle requis :</strong> Directeur des Affaires Académiques</p>
                <div class='mt-4'>
                    <a href='" . ROOT_PATH . "/dashboard/' class='btn btn-primary'>
                        <i class='fas fa-tachometer-alt'></i> Retour au Dashboard
                    </a>
                    <a href='" . ROOT_PATH . "/auth/logout.php' class='btn btn-outline-secondary'>
                        <i class='fas fa-sign-out-alt'></i> Se déconnecter
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>";
    exit();
}

// Inclure la configuration de la base de données
try {
    // Chemin vers database.php
    $config_path = ROOT_PATH . '/config/database.php';
    
    // Vérifier si le fichier existe
    if (!file_exists($config_path)) {
        throw new Exception("Fichier de configuration introuvable: " . $config_path);
    }
    
    // Inclure le fichier
    require_once $config_path;
    
    // Vérifier si la classe Database existe
    if (!class_exists('Database')) {
        throw new Exception("La classe Database n'est pas définie dans database.php");
    }
    
    // Obtenir l'instance de la base de données
    $db = Database::getInstance()->getConnection();
    
    if (!$db) {
        throw new Exception("La connexion à la base de données est nulle");
    }
    
    // Tester la connexion
    $test_query = $db->query("SELECT 1");
    if (!$test_query) {
        throw new Exception("Échec du test de connexion à la base de données");
    }
    
} catch (Exception $e) {
    // Afficher un message d'erreur clair
    $error_message = htmlspecialchars($e->getMessage());
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Erreur de Configuration</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
        <style>
            body { 
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                max-width: 800px;
                width: 100%;
            }
            .debug-info {
                background: #f8f9fa;
                border-left: 4px solid #dc3545;
                padding: 15px;
                margin-top: 20px;
                font-family: monospace;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <div class='card shadow-lg'>
                <div class='card-header bg-danger text-white'>
                    <h4 class='mb-0'><i class='fas fa-exclamation-triangle'></i> Erreur de Configuration</h4>
                </div>
                <div class='card-body'>
                    <div class='alert alert-danger'>
                        <h5><i class='fas fa-database'></i> Problème de Connexion à la Base de Données</h5>
                        <p class='mb-0'>$error_message</p>
                    </div>
                    
                    <div class='debug-info'>
                        <strong>Informations de débogage :</strong><br>
                        ROOT_PATH : " . ROOT_PATH . "<br>
                        Chemin config : " . $config_path . "<br>
                        Fichier existe : " . (file_exists($config_path) ? 'OUI' : 'NON') . "<br>
                        Session user_id : " . ($_SESSION['user_id'] ?? 'NON') . "<br>
                        Session role_id : " . ($_SESSION['role_id'] ?? 'NON') . "
                    </div>
                    
                    <div class='mt-4'>
                        <h5>Solutions possibles :</h5>
                        <ol>
                            <li>Vérifiez que le fichier <code>config/database.php</code> existe</li>
                            <li>Vérifiez les identifiants MySQL dans <code>database.php</code></li>
                            <li>Assurez-vous que le service MySQL est démarré (WAMP/MAMP/XAMPP)</li>
                            <li>Vérifiez que la base de données 'isgi_systeme' existe</li>
                        </ol>
                        
                        <div class='d-grid gap-2 d-md-flex justify-content-md-center mt-4'>
                            <a href='javascript:location.reload()' class='btn btn-primary'>
                                <i class='fas fa-redo'></i> Réessayer
                            </a>
                            <a href='" . ROOT_PATH . "/dashboard/' class='btn btn-outline-secondary'>
                                <i class='fas fa-home'></i> Retour au Dashboard
                            </a>
                            <a href='" . ROOT_PATH . "/auth/logout.php' class='btn btn-outline-danger'>
                                <i class='fas fa-sign-out-alt'></i> Déconnexion
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
    exit();
}

// Définir le titre de la page
$pageTitle = "DAC - Saisie des Notes";

// Récupérer l'ID du site de l'utilisateur
$site_id = $_SESSION['site_id'] ?? null;

// Fonctions utilitaires
function formatMoney($amount) {
    if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00') return '';
    $timestamp = strtotime($date);
    if ($timestamp === false) return '';
    return date($format, $timestamp);
}

function getStatutBadge($statut) {
    $badges = [
        'valide' => 'success',
        'validee' => 'success', 
        'publie' => 'success',
        'brouillon' => 'warning',
        'en_attente' => 'warning',
        'planifie' => 'warning',
        'annule' => 'danger',
        'rejete' => 'danger',
        'termine' => 'info',
        'reporte' => 'secondary'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst($statut) . '</span>';
}

// Variables pour les actions
$action = $_GET['action'] ?? 'list';
$examen_id = $_GET['examen_id'] ?? null;
$matiere_id = $_GET['matiere_id'] ?? null;
$classe_id = $_GET['classe_id'] ?? null;
$type_examen_id = $_GET['type_examen_id'] ?? null;
$semestre_id = $_GET['semestre_id'] ?? null;
$annee_id = $_GET['annee_id'] ?? null;

// Initialiser les variables
$examen = null;
$matiere = null;
$classe = null;
$type_examen = null;
$etudiants = [];
$notes_existantes = [];
$error = null;
$success_message = null;

// Récupérer les données de l'examen si spécifié
if ($examen_id && $site_id) {
    try {
        // Récupérer les informations de l'examen
        $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code, m.coefficient as matiere_coeff,
                 m.filiere_id, m.niveau_id,
                 c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle,
                 te.nom as type_examen, te.pourcentage as type_pourcentage,
                 aa.libelle as annee_libelle, aa.id as annee_id,
                 ca.semestre as semestre_numero,
                 CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN classes c ON ce.classe_id = c.id
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN niveaux n ON c.niveau_id = n.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                 LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                 LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                 LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                 WHERE ce.id = :examen_id AND c.site_id = :site_id";
        
        $stmt = $db->prepare($query);
        $stmt->execute(['examen_id' => $examen_id, 'site_id' => $site_id]);
        $examen = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($examen) {
            $matiere_id = $examen['matiere_id'];
            $classe_id = $examen['classe_id'];
            $type_examen_id = $examen['type_examen_id'];
            
            // Récupérer les étudiants de la classe
            $query = "SELECT e.* 
                     FROM etudiants e
                     WHERE e.classe_id = :classe_id AND e.statut = 'actif'
                     ORDER BY e.nom, e.prenom";
            $stmt = $db->prepare($query);
            $stmt->execute(['classe_id' => $classe_id]);
            $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Récupérer les notes existantes pour cet examen
            $query = "SELECT n.*, e.matricule, e.nom, e.prenom
                     FROM notes n
                     JOIN etudiants e ON n.etudiant_id = e.id
                     WHERE n.matiere_id = :matiere_id 
                     AND n.type_examen_id = :type_examen_id
                     AND n.annee_academique_id = :annee_id
                     AND n.semestre_id = (SELECT id FROM semestres WHERE annee_academique_id = :annee_id2 AND numero = :semestre)";
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                'matiere_id' => $matiere_id,
                'type_examen_id' => $type_examen_id,
                'annee_id' => $examen['annee_id'],
                'annee_id2' => $examen['annee_id'],
                'semestre' => $examen['semestre_numero']
            ]);
            $notes_existantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Organiser les notes par étudiant
            $notes_par_etudiant = [];
            foreach ($notes_existantes as $note) {
                $notes_par_etudiant[$note['etudiant_id']] = $note;
            }
        }
    } catch (PDOException $e) {
        $error = "Erreur de base de données: " . $e->getMessage();
    }
}

// Traitement de la soumission des notes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['saisie_notes'])) {
    try {
        $user_id = $_SESSION['user_id'];
        $examen_id = $_POST['examen_id'];
        $matiere_id = $_POST['matiere_id'];
        $classe_id = $_POST['classe_id'];
        $type_examen_id = $_POST['type_examen_id'];
        $semestre_numero = $_POST['semestre_numero'];
        $annee_academique_id = $_POST['annee_academique_id'];
        
        // Récupérer l'ID du semestre
        $query_semestre = "SELECT id FROM semestres WHERE annee_academique_id = :annee_id AND numero = :numero";
        $stmt_semestre = $db->prepare($query_semestre);
        $stmt_semestre->execute(['annee_id' => $annee_academique_id, 'numero' => $semestre_numero]);
        $semestre = $stmt_semestre->fetch(PDO::FETCH_ASSOC);
        $semestre_id = $semestre['id'] ?? null;
        
        // Récupérer l'ID de l'enseignant (évaluateur)
        $query = "SELECT id FROM enseignants WHERE utilisateur_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->execute(['user_id' => $user_id]);
        $enseignant = $stmt->fetch(PDO::FETCH_ASSOC);
        $evaluateur_id = $enseignant['id'] ?? $user_id;
        
        // Traiter chaque note
        $notes = $_POST['notes'] ?? [];
        $coefficients = $_POST['coefficients'] ?? [];
        $remarques = $_POST['remarques'] ?? [];
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($notes as $etudiant_id => $note_value) {
            if (trim($note_value) === '') continue;
            
            $note_value = floatval(str_replace(',', '.', $note_value));
            $coefficient = isset($coefficients[$etudiant_id]) ? floatval($coefficients[$etudiant_id]) : 1.0;
            $remarque = isset($remarques[$etudiant_id]) ? trim($remarques[$etudiant_id]) : null;
            
            // Vérifier si une note existe déjà
            $query_check = "SELECT id FROM notes 
                           WHERE etudiant_id = :etudiant_id 
                           AND matiere_id = :matiere_id 
                           AND type_examen_id = :type_examen_id 
                           AND semestre_id = :semestre_id 
                           AND annee_academique_id = :annee_id";
            $stmt_check = $db->prepare($query_check);
            $stmt_check->execute([
                'etudiant_id' => $etudiant_id,
                'matiere_id' => $matiere_id,
                'type_examen_id' => $type_examen_id,
                'semestre_id' => $semestre_id,
                'annee_id' => $annee_academique_id
            ]);
            $existing_note = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_note) {
                // Mettre à jour
                $query_update = "UPDATE notes 
                               SET note = :note, coefficient_note = :coefficient, 
                               date_evaluation = CURDATE(), evaluateur_id = :evaluateur_id,
                               remarques = :remarques, statut = 'valide'
                               WHERE id = :id";
                $stmt_update = $db->prepare($query_update);
                $result = $stmt_update->execute([
                    'note' => $note_value,
                    'coefficient' => $coefficient,
                    'evaluateur_id' => $evaluateur_id,
                    'remarques' => $remarque,
                    'id' => $existing_note['id']
                ]);
            } else {
                // Insérer
                $query_insert = "INSERT INTO notes 
                               (etudiant_id, matiere_id, type_examen_id, note, coefficient_note,
                                date_evaluation, evaluateur_id, semestre_id, annee_academique_id,
                                remarques, statut, date_creation)
                               VALUES (:etudiant_id, :matiere_id, :type_examen_id, :note, :coefficient,
                                       CURDATE(), :evaluateur_id, :semestre_id, :annee_id,
                                       :remarques, 'valide', NOW())";
                $stmt_insert = $db->prepare($query_insert);
                $result = $stmt_insert->execute([
                    'etudiant_id' => $etudiant_id,
                    'matiere_id' => $matiere_id,
                    'type_examen_id' => $type_examen_id,
                    'note' => $note_value,
                    'coefficient' => $coefficient,
                    'evaluateur_id' => $evaluateur_id,
                    'semestre_id' => $semestre_id,
                    'annee_id' => $annee_academique_id,
                    'remarques' => $remarque
                ]);
            }
            
            if ($result) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        // Mettre à jour le statut de l'examen
        if ($success_count > 0) {
            $query_update_examen = "UPDATE calendrier_examens 
                                   SET notes_saisies = 1, modifie_par = :user_id, date_modification = NOW()
                                   WHERE id = :examen_id";
            $stmt_update_examen = $db->prepare($query_update_examen);
            $stmt_update_examen->execute(['user_id' => $user_id, 'examen_id' => $examen_id]);
            
            $success_message = "$success_count note(s) enregistrée(s) avec succès" . 
                             ($error_count > 0 ? " ($error_count erreur(s))" : "");
        } else {
            $error = "Aucune note n'a pu être enregistrée";
        }
        
    } catch (PDOException $e) {
        $error = "Erreur lors de l'enregistrement: " . $e->getMessage();
    }
}

// Traitement pour valider les notes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['valider_notes'])) {
    try {
        $examen_id = $_POST['examen_id'];
        $user_id = $_SESSION['user_id'];
        
        $query = "UPDATE calendrier_examens 
                 SET notes_validees = 1, date_publication_notes = CURDATE(),
                 valide_par = :user_id, date_validation = NOW()
                 WHERE id = :examen_id";
        $stmt = $db->prepare($query);
        $stmt->execute(['user_id' => $user_id, 'examen_id' => $examen_id]);
        
        $success_message = "Notes validées et publiées avec succès";
        
        // Recharger les données de l'examen
        if ($examen) {
            $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code, m.coefficient as matiere_coeff,
                     c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle,
                     te.nom as type_examen, te.pourcentage as type_pourcentage,
                     aa.libelle as annee_libelle, aa.id as annee_id,
                     ca.semestre as semestre_numero,
                     CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
                     FROM calendrier_examens ce
                     JOIN matieres m ON ce.matiere_id = m.id
                     JOIN classes c ON ce.classe_id = c.id
                     JOIN filieres f ON c.filiere_id = f.id
                     JOIN niveaux n ON c.niveau_id = n.id
                     JOIN types_examens te ON ce.type_examen_id = te.id
                     LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                     LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                     LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                     LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                     WHERE ce.id = :examen_id";
            
            $stmt = $db->prepare($query);
            $stmt->execute(['examen_id' => $examen_id]);
            $examen = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        $error = "Erreur lors de la validation: " . $e->getMessage();
    }
}

// Récupérer les listes pour les filtres
$filieres = [];
$niveaux = [];
$matieres = [];
$classes = [];
$types_examens = [];
$examens_recent = [];

try {
    if ($site_id) {
        // Récupérer les filières
        $query = "SELECT f.id, f.nom, o.nom as option_nom 
                 FROM filieres f 
                 JOIN options_formation o ON f.option_id = o.id
                 ORDER BY f.nom";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les niveaux
        $query = "SELECT * FROM niveaux ORDER BY ordre";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les matières
        $query = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
                 FROM matieres m 
                 JOIN filieres f ON m.filiere_id = f.id
                 JOIN niveaux n ON m.niveau_id = n.id
                 WHERE m.site_id = :site_id
                 ORDER BY m.code";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les classes
        $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle,
                 aa.libelle as annee_libelle
                 FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN niveaux n ON c.niveau_id = n.id
                 JOIN annees_academiques aa ON c.annee_academique_id = aa.id
                 WHERE c.site_id = :site_id
                 ORDER BY f.nom, n.ordre";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les types d'examens
        $query = "SELECT * FROM types_examens ORDER BY ordre";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $types_examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les examens récents
        $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                 c.nom as classe_nom, te.nom as type_examen,
                 DATE_FORMAT(ce.date_examen, '%d/%m/%Y') as date_formatee
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN classes c ON ce.classe_id = c.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 WHERE c.site_id = :site_id
                 AND ce.date_examen <= CURDATE()
                 AND ce.statut = 'termine'
                 ORDER BY ce.date_examen DESC, ce.heure_debut DESC
                 LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $examens_recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// Démarrer l'output buffering pour éviter les erreurs d'en-tête
ob_start();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #f8f9fa;
        --card-bg: #ffffff;
        --text-color: #212529;
        --text-muted: #6c757d;
        --sidebar-bg: #2c3e50;
        --sidebar-text: #ffffff;
        --border-color: #dee2e6;
    }
    
    [data-theme="dark"] {
        --primary-color: #3498db;
        --secondary-color: #2980b9;
        --accent-color: #e74c3c;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --text-color: #e0e0e0;
        --text-muted: #a0a0a0;
        --sidebar-bg: #1a1a1a;
        --sidebar-text: #ffffff;
        --border-color: #333333;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
    }
    
    /* Header principal */
    .main-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 20px 0;
        margin-bottom: 30px;
    }
    
    /* Navigation secondaire */
    .secondary-nav {
        background-color: var(--card-bg);
        border-bottom: 1px solid var(--border-color);
        padding: 10px 0;
        margin-bottom: 20px;
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .card-header {
        background-color: rgba(var(--primary-color), 0.1);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
        font-weight: 600;
    }
    
    /* Tableaux */
    .table th {
        background-color: var(--info-color);
        color: white;
        border: none;
    }
    
    .table td {
        vertical-align: middle;
    }
    
    /* Badges */
    .badge {
        padding: 6px 12px;
        font-weight: 500;
    }
    
    /* Input de notes */
    .note-input {
        width: 80px;
        text-align: center;
        font-weight: bold;
        padding: 8px;
    }
    
    /* Alertes */
    .alert {
        border-radius: 8px;
        border: none;
    }
    
    /* Boutons */
    .btn {
        border-radius: 6px;
        padding: 8px 16px;
        font-weight: 500;
    }
    
    /* Informations examen */
    .exam-info-card {
        background: linear-gradient(135deg, var(--info-color), var(--secondary-color));
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 14px;
        }
        
        .note-input {
            width: 60px;
            padding: 5px;
        }
    }
    
    /* Loading spinner */
    .spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: var(--info-color);
        animation: spin 1s ease-in-out infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    </style>
</head>
<body>
    <!-- Header principal -->
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-2">
                        <i class="fas fa-graduation-cap"></i> ISGI - Saisie des Notes
                    </h1>
                    <p class="mb-0 opacity-75">
                        Directeur des Affaires Académiques | 
                        <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?>
                    </p>
                </div>
                <div class="text-end">
                    <div class="badge bg-light text-dark mb-2">
                        Site: <?php echo htmlspecialchars($_SESSION['site_nom'] ?? 'Non défini'); ?>
                    </div>
                    <br>
                    <a href="<?php echo ROOT_PATH; ?>/dashboard/" class="btn btn-light btn-sm">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="<?php echo ROOT_PATH; ?>/auth/logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i> Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Navigation secondaire -->
    <nav class="secondary-nav">
        <div class="container">
            <div class="d-flex flex-wrap gap-2">
                <a href="saisie_notes.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-plus-circle"></i> Nouvelle saisie
                </a>
                <a href="notes.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-list"></i> Liste des notes
                </a>
                <a href="calendrier_examens.php" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-calendar"></i> Calendrier
                </a>
                <a href="bulletins.php" class="btn btn-outline-success btn-sm">
                    <i class="fas fa-file-alt"></i> Bulletins
                </a>
                <div class="ms-auto">
                    <button class="btn btn-outline-dark btn-sm" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> Thème
                    </button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Contenu principal -->
    <main class="container">
        <!-- Messages d'alerte -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Contenu selon l'action -->
        <?php if (!$examen): ?>
        <!-- Sélection d'examen -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-search"></i> Sélectionnez un examen pour saisir les notes
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($examens_recent)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Aucun examen disponible pour la saisie.
                    <p class="mb-0 mt-2">
                        <small>Les examens doivent être terminés pour pouvoir saisir les notes.</small>
                    </p>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($examens_recent as $exam): ?>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 exam-card" onclick="window.location.href='saisie_notes.php?examen_id=<?php echo $exam['id']; ?>'">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title text-primary">
                                            <?php echo htmlspecialchars($exam['matiere_nom']); ?>
                                        </h6>
                                        <p class="card-text mb-1">
                                            <small class="text-muted">
                                                <i class="fas fa-users"></i> 
                                                <?php echo htmlspecialchars($exam['classe_nom']); ?>
                                            </small>
                                        </p>
                                        <p class="card-text mb-1">
                                            <small>
                                                <i class="fas fa-calendar"></i> 
                                                <?php echo $exam['date_formatee']; ?>
                                            </small>
                                        </p>
                                        <p class="card-text mb-0">
                                            <span class="badge bg-info">
                                                <?php echo htmlspecialchars($exam['type_examen']); ?>
                                            </span>
                                            <span class="badge bg-secondary ms-1">
                                                <?php echo $exam['matiere_code']; ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div>
                                        <?php if ($exam['notes_saisies'] == 1): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check"></i> Saisi
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="fas fa-clock"></i> À saisir
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent text-center">
                                <small>
                                    <a href="saisie_notes.php?examen_id=<?php echo $exam['id']; ?>" 
                                       class="text-decoration-none">
                                        <i class="fas fa-edit"></i> Saisir les notes
                                    </a>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Formulaire de saisie des notes -->
        <div class="exam-info-card">
            <div class="row">
                <div class="col-md-8">
                    <h4 class="mb-2">
                        <i class="fas fa-book"></i> 
                        <?php echo htmlspecialchars($examen['matiere_nom'] . ' (' . $examen['matiere_code'] . ')'); ?>
                    </h4>
                    <p class="mb-1">
                        <i class="fas fa-chalkboard-teacher"></i> 
                        Classe: <?php echo htmlspecialchars($examen['classe_nom']); ?>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-layer-group"></i> 
                        <?php echo htmlspecialchars($examen['filiere_nom'] . ' - ' . $examen['niveau_libelle']); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-clipboard-check"></i> 
                        <?php echo htmlspecialchars($examen['type_examen']); ?> 
                        (<?php echo $examen['type_pourcentage']; ?>%) - 
                        Coefficient: <?php echo $examen['matiere_coeff']; ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="d-flex flex-column align-items-end">
                        <span class="badge bg-light text-dark mb-2">
                            <i class="fas fa-calendar"></i> 
                            <?php echo formatDateFr($examen['date_examen']); ?>
                        </span>
                        <div>
                            <?php if ($examen['notes_validees'] == 1): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-double"></i> Validé
                            </span>
                            <?php elseif ($examen['notes_saisies'] == 1): ?>
                            <span class="badge bg-warning">
                                <i class="fas fa-check"></i> Saisi
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="fas fa-clock"></i> En attente
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistiques rapides -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <h6 class="card-title text-muted">Étudiants</h6>
                        <h2 class="text-primary"><?php echo count($etudiants); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <h6 class="card-title text-muted">Notes saisies</h6>
                        <h2 class="text-success"><?php echo count($notes_existantes); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <h6 class="card-title text-muted">Taux de saisie</h6>
                        <h2 class="text-info">
                            <?php 
                            $taux = count($etudiants) > 0 ? 
                                   (count($notes_existantes) / count($etudiants)) * 100 : 0;
                            echo number_format($taux, 1) . '%';
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <h6 class="card-title text-muted">Moyenne</h6>
                        <h2 class="text-warning">
                            <?php
                            if (count($notes_existantes) > 0) {
                                $somme = 0;
                                foreach ($notes_existantes as $note) {
                                    $somme += $note['note'];
                                }
                                echo number_format($somme / count($notes_existantes), 2);
                            } else {
                                echo '0.00';
                            }
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Formulaire de saisie -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-edit"></i> Saisie des notes
                </h5>
                <div>
                    <?php if ($examen['notes_validees'] == 0): ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="calculerMoyennes()">
                        <i class="fas fa-calculator"></i> Calculer
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#validerModal">
                        <i class="fas fa-check-circle"></i> Valider
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($etudiants)): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle"></i> Aucun étudiant trouvé dans cette classe.
                </div>
                <?php else: ?>
                <form method="post" id="formSaisieNotes">
                    <input type="hidden" name="saisie_notes" value="1">
                    <input type="hidden" name="examen_id" value="<?php echo $examen['id']; ?>">
                    <input type="hidden" name="matiere_id" value="<?php echo $examen['matiere_id']; ?>">
                    <input type="hidden" name="classe_id" value="<?php echo $examen['classe_id']; ?>">
                    <input type="hidden" name="type_examen_id" value="<?php echo $examen['type_examen_id']; ?>">
                    <input type="hidden" name="semestre_numero" value="<?php echo $examen['semestre_numero']; ?>">
                    <input type="hidden" name="annee_academique_id" value="<?php echo $examen['annee_id']; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-info">
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="15%">Matricule</th>
                                    <th width="25%">Étudiant</th>
                                    <th width="15%">Note /20</th>
                                    <th width="15%">Coefficient</th>
                                    <th width="25%">Remarques</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($etudiants as $etudiant): 
                                    $note_existante = $notes_par_etudiant[$etudiant['id']] ?? null;
                                    $note_value = $note_existante ? $note_existante['note'] : '';
                                    $coefficient = $note_existante ? $note_existante['coefficient_note'] : 1.0;
                                    $remarque = $note_existante ? $note_existante['remarques'] : '';
                                ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <span class="badge bg-dark"><?php echo htmlspecialchars($etudiant['matricule']); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="notes[<?php echo $etudiant['id']; ?>]"
                                               class="form-control note-input"
                                               min="0" max="20" step="0.25"
                                               value="<?php echo $note_value; ?>"
                                               placeholder="0.00"
                                               onchange="updateNoteStatus(this, <?php echo $etudiant['id']; ?>)">
                                    </td>
                                    <td>
                                        <input type="number"
                                               name="coefficients[<?php echo $etudiant['id']; ?>]"
                                               class="form-control"
                                               min="0.1" max="5" step="0.1"
                                               value="<?php echo $coefficient; ?>"
                                               style="width: 80px;">
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="remarques[<?php echo $etudiant['id']; ?>]"
                                               class="form-control"
                                               placeholder="Observation..."
                                               value="<?php echo htmlspecialchars($remarque); ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6" class="text-end">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <button type="button" class="btn btn-outline-secondary" onclick="remplirNotesTest()">
                                                    <i class="fas fa-vial"></i> Test
                                                </button>
                                                <button type="button" class="btn btn-outline-warning" onclick="resetForm()">
                                                    <i class="fas fa-undo"></i> Réinitialiser
                                                </button>
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-primary btn-lg">
                                                    <i class="fas fa-save"></i> Enregistrer toutes les notes
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Modal de validation -->
        <div class="modal fade" id="validerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="valider_notes" value="1">
                        <input type="hidden" name="examen_id" value="<?php echo $examen['id']; ?>">
                        
                        <div class="modal-header">
                            <h5 class="modal-title">Validation des Notes</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Attention !</strong> Cette action est définitive.
                            </div>
                            <p>En validant, les notes seront publiées et visibles par les étudiants.</p>
                            <div class="mb-3">
                                <label class="form-label">Date de publication</label>
                                <input type="date" name="date_publication" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary">Confirmer la validation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Pied de page -->
        <footer class="mt-5 pt-3 border-top text-center text-muted">
            <p class="mb-1">
                <small>
                    <i class="fas fa-copyright"></i> ISGI - Système de Gestion Académique
                </small>
            </p>
            <p class="mb-0">
                <small>
                    Session: <?php echo date('d/m/Y H:i'); ?> | 
                    Version: 1.0
                </small>
            </p>
        </footer>
    </main>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Fonction pour basculer le thème
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('isgi_theme', newTheme);
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('isgi_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        // Auto-suppression des alertes après 5 secondes
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    
    // Mettre à jour le statut visuel de la note
    function updateNoteStatus(input, etudiantId) {
        const note = parseFloat(input.value) || 0;
        if (note >= 16) {
            input.classList.add('border-success');
            input.classList.remove('border-warning', 'border-danger');
        } else if (note >= 10) {
            input.classList.add('border-warning');
            input.classList.remove('border-success', 'border-danger');
        } else if (note > 0) {
            input.classList.add('border-danger');
            input.classList.remove('border-success', 'border-warning');
        } else {
            input.classList.remove('border-success', 'border-warning', 'border-danger');
        }
    }
    
    // Calculer les statistiques
    function calculerMoyennes() {
        const inputs = document.querySelectorAll('input[name^="notes"]');
        let total = 0;
        let count = 0;
        let valides = 0;
        
        inputs.forEach(input => {
            const note = parseFloat(input.value);
            if (!isNaN(note) && note >= 0 && note <= 20) {
                total += note;
                count++;
                if (note >= 10) valides++;
            }
        });
        
        if (count > 0) {
            const moyenne = total / count;
            const tauxReussite = (valides / count) * 100;
            
            alert(`Statistiques :\n\n` +
                  `Notes saisies : ${count}\n` +
                  `Moyenne : ${moyenne.toFixed(2)}/20\n` +
                  `Taux de réussite : ${tauxReussite.toFixed(1)}%\n` +
                  `Étudiants ≥ 10/20 : ${valides}`);
        } else {
            alert('Aucune note valide saisie');
        }
    }
    
    // Remplir avec des notes de test
    function remplirNotesTest() {
        if (!confirm('Remplir avec des notes de test ?')) return;
        
        const inputs = document.querySelectorAll('input[name^="notes"]');
        inputs.forEach(input => {
            // Générer une note entre 8 et 18
            const note = (Math.random() * 10 + 8).toFixed(2);
            input.value = note;
            
            // Mettre à jour le style
            const id = input.name.match(/\[(\d+)\]/)[1];
            updateNoteStatus(input, id);
        });
        
        // Remplir quelques remarques
        const remarques = document.querySelectorAll('input[name^="remarques"]');
        const texts = ['Bon travail', 'À améliorer', 'Excellent', 'Moyen', 'Satisfaisant'];
        
        remarques.forEach((input, index) => {
            if (index % 3 === 0) {
                input.value = texts[Math.floor(Math.random() * texts.length)];
            }
        });
        
        alert('Notes de test générées. N\'oubliez pas de sauvegarder !');
    }
    
    // Réinitialiser le formulaire
    function resetForm() {
        if (!confirm('Voulez-vous vraiment réinitialiser toutes les notes ?')) return;
        
        document.querySelectorAll('input[name^="notes"]').forEach(input => {
            input.value = '';
            input.classList.remove('border-success', 'border-warning', 'border-danger');
        });
        
        document.querySelectorAll('input[name^="coefficients"]').forEach(input => {
            input.value = '1.0';
        });
        
        document.querySelectorAll('input[name^="remarques"]').forEach(input => {
            input.value = '';
        });
    }
    
    // Validation du formulaire
    document.getElementById('formSaisieNotes')?.addEventListener('submit', function(e) {
        const inputs = document.querySelectorAll('input[name^="notes"]');
        let hasNotes = false;
        let invalidNotes = [];
        
        inputs.forEach((input, index) => {
            const note = parseFloat(input.value);
            if (!isNaN(note)) {
                hasNotes = true;
                if (note < 0 || note > 20) {
                    invalidNotes.push(`Ligne ${index + 1}: ${note}/20`);
                }
            }
        });
        
        if (invalidNotes.length > 0) {
            e.preventDefault();
            alert(`Notes invalides :\n\n${invalidNotes.join('\n')}\n\nLes notes doivent être entre 0 et 20.`);
        } else if (!hasNotes) {
            if (!confirm('Aucune note n\'est remplie. Voulez-vous continuer ?')) {
                e.preventDefault();
            }
        }
    });
    
    // Raccourcis clavier
    document.addEventListener('keydown', function(e) {
        // Ctrl + S pour sauvegarder
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('formSaisieNotes')?.submit();
        }
        
        // Ctrl + R pour réinitialiser
        if (e.ctrlKey && e.key === 'r' && e.altKey) {
            e.preventDefault();
            resetForm();
        }
    });
    </script>
</body>
</html>

<?php
// Fin du output buffering
ob_end_flush();
?>