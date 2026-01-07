<?php
// dashboard/admin_principal/enseignants.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Administrateur Principal - Gestion des Enseignants";
    
    // Fonctions utilitaires
    function getGradeBadge($grade) {
        switch ($grade) {
            case 'PA':
                return '<span class="badge bg-primary">PA</span>';
            case 'PH':
                return '<span class="badge bg-success">PH</span>';
            case 'PES':
                return '<span class="badge bg-info">PES</span>';
            case 'Vacataire':
                return '<span class="badge bg-warning">Vacataire</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($grade) . '</span>';
        }
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
                return '<span class="badge bg-success">Actif</span>';
            case 'retraite':
                return '<span class="badge bg-secondary">Retraité</span>';
            case 'demission':
                return '<span class="badge bg-danger">Démission</span>';
            default:
                return '<span class="badge bg-dark">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $enseignants = array();
    $sites = array();
    $matieres = array();
    $matieres_sans_enseignant = array();
    
    // Récupérer les sites
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer TOUTES les matières
    $matieres = $db->query("SELECT * FROM matieres ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les matières SANS enseignant
    $matieres_sans_enseignant = $db->query("SELECT * FROM matieres WHERE enseignant_id IS NULL ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_grade = isset($_GET['grade']) ? $_GET['grade'] : '';
    $filtre_recherche = isset($_GET['recherche']) ? trim($_GET['recherche']) : '';
    
    // Requête pour récupérer les enseignants
    $query = "SELECT e.*, 
              u.nom, u.prenom, u.email, u.telephone,
              s.nom as site_nom, s.ville as site_ville,
              (SELECT COUNT(*) FROM matieres m WHERE m.enseignant_id = e.id) as nb_matieres
              FROM enseignants e
              LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
              LEFT JOIN sites s ON e.site_id = s.id
              WHERE 1=1";
    
    $params = array();
    
    if ($filtre_site > 0) {
        $query .= " AND e.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if (!empty($filtre_statut)) {
        $query .= " AND e.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if (!empty($filtre_grade)) {
        $query .= " AND e.grade = ?";
        $params[] = $filtre_grade;
    }
    
    if (!empty($filtre_recherche)) {
        $query .= " AND (e.matricule LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.telephone LIKE ? OR e.specialite LIKE ?)";
        $search_param = "%{$filtre_recherche}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " ORDER BY u.nom, u.prenom";
    
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les matières pour chaque enseignant depuis la table matieres_enseignants
    foreach ($enseignants as &$ens) {
        // Utiliser la table matieres_enseignants
        $matieres_query = "SELECT m.id, m.nom, m.code 
                          FROM matieres_enseignants me 
                          JOIN matieres m ON me.matiere_id = m.id 
                          WHERE me.enseignant_id = ?";
        $matieres_stmt = $db->prepare($matieres_query);
        $matieres_stmt->execute([$ens['id']]);
        $ens['matieres_list'] = $matieres_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Valeur par défaut pour sexe (non présent dans la table)
        $ens['sexe'] = 'M';
    }
    
    // Traitement des actions
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action == 'ajouter_enseignant') {
            // Données du formulaire
            $nom = $_POST['nom'] ?? '';
            $prenom = $_POST['prenom'] ?? '';
            $email = $_POST['email'] ?? '';
            $telephone = $_POST['telephone'] ?? '';
            $matricule = $_POST['matricule'] ?? '';
            $specialite = $_POST['specialite'] ?? '';
            $grade = $_POST['grade'] ?? 'Vacataire';
            $site_id = $_POST['site_id'] ?? null;
            $date_embauche = $_POST['date_embauche'] ?? date('Y-m-d');
            $statut = $_POST['statut'] ?? 'actif';
            $matieres_ids = $_POST['matieres_ids'] ?? [];
            
            try {
                $db->beginTransaction();
                
                // 1. Vérifier si l'email existe déjà
                $check_email = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                $check_email->execute([$email]);
                if ($check_email->rowCount() > 0) {
                    throw new Exception("Un utilisateur avec cet email existe déjà.");
                }
                
                // 2. Vérifier si le matricule existe déjà
                $check_matricule = $db->prepare("SELECT id FROM enseignants WHERE matricule = ?");
                $check_matricule->execute([$matricule]);
                if ($check_matricule->rowCount() > 0) {
                    throw new Exception("Un enseignant avec ce matricule existe déjà.");
                }
                
                // 3. Vérifier que les matières sélectionnées n'ont pas déjà un enseignant
                foreach ($matieres_ids as $matiere_id) {
                    $check_matiere = $db->prepare("SELECT enseignant_id FROM matieres WHERE id = ?");
                    $check_matiere->execute([$matiere_id]);
                    $matiere = $check_matiere->fetch(PDO::FETCH_ASSOC);
                    if ($matiere && $matiere['enseignant_id'] !== null) {
                        throw new Exception("Une des matières sélectionnées a déjà un enseignant.");
                    }
                }
                
                // 4. Créer l'utilisateur
                $password = password_hash('Enseignant' . date('Y'), PASSWORD_DEFAULT);
                $utilisateur_query = "INSERT INTO utilisateurs 
                                    (nom, prenom, email, telephone, mot_de_passe, role_id, site_id, 
                                     statut, date_creation)
                                    VALUES (?, ?, ?, ?, ?, 7, ?, 'actif', NOW())";
                
                $utilisateur_stmt = $db->prepare($utilisateur_query);
                $utilisateur_stmt->execute([
                    $nom, $prenom, $email, $telephone, $password, $site_id
                ]);
                
                $utilisateur_id = $db->lastInsertId();
                
                // 5. Créer l'enseignant
                $enseignant_query = "INSERT INTO enseignants 
                                    (utilisateur_id, matricule, specialite, grade, site_id, date_embauche, statut)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $enseignant_stmt = $db->prepare($enseignant_query);
                $enseignant_stmt->execute([
                    $utilisateur_id, $matricule, $specialite, $grade, $site_id, $date_embauche, $statut
                ]);
                
                $enseignant_id = $db->lastInsertId();
                
                // 6. Ajouter les matières enseignées
                foreach ($matieres_ids as $matiere_id) {
                    // Ajouter à matieres_enseignants
                    $matiere_query = "INSERT INTO matieres_enseignants (enseignant_id, matiere_id) VALUES (?, ?)";
                    $matiere_stmt = $db->prepare($matiere_query);
                    $matiere_stmt->execute([$enseignant_id, $matiere_id]);
                    
                    // Mettre à jour la table matieres
                    $update_matiere_query = "UPDATE matieres SET enseignant_id = ? WHERE id = ?";
                    $update_matiere_stmt = $db->prepare($update_matiere_query);
                    $update_matiere_stmt->execute([$enseignant_id, $matiere_id]);
                }
                
                $db->commit();
                
                // Rediriger avec message de succès
                header("Location: enseignants.php?success=" . urlencode("Enseignant ajouté avec succès !"));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Erreur lors de l'ajout de l'enseignant: " . $e->getMessage();
            }
        }
        // Action pour supprimer un enseignant
        elseif ($action == 'supprimer_enseignant') {
            $enseignant_id = $_POST['enseignant_id'] ?? 0;
            
            try {
                $db->beginTransaction();
                
                // Récupérer l'utilisateur_id
                $get_user_query = "SELECT utilisateur_id FROM enseignants WHERE id = ?";
                $get_user_stmt = $db->prepare($get_user_query);
                $get_user_stmt->execute([$enseignant_id]);
                $enseignant = $get_user_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($enseignant) {
                    $utilisateur_id = $enseignant['utilisateur_id'];
                    
                    // Supprimer les matières enseignées de matieres_enseignants
                    $delete_matieres_query = "DELETE FROM matieres_enseignants WHERE enseignant_id = ?";
                    $delete_matieres_stmt = $db->prepare($delete_matieres_query);
                    $delete_matieres_stmt->execute([$enseignant_id]);
                    
                    // Mettre à jour les matières pour enlever l'enseignant_id
                    $update_matieres_query = "UPDATE matieres SET enseignant_id = NULL WHERE enseignant_id = ?";
                    $update_matieres_stmt = $db->prepare($update_matieres_query);
                    $update_matieres_stmt->execute([$enseignant_id]);
                    
                    // Supprimer l'enseignant
                    $delete_enseignant_query = "DELETE FROM enseignants WHERE id = ?";
                    $delete_enseignant_stmt = $db->prepare($delete_enseignant_query);
                    $delete_enseignant_stmt->execute([$enseignant_id]);
                    
                    // Supprimer l'utilisateur
                    $delete_utilisateur_query = "DELETE FROM utilisateurs WHERE id = ?";
                    $delete_utilisateur_stmt = $db->prepare($delete_utilisateur_query);
                    $delete_utilisateur_stmt->execute([$utilisateur_id]);
                }
                
                $db->commit();
                
                // Rediriger avec message de succès
                header("Location: enseignants.php?success=" . urlencode("Enseignant supprimé avec succès !"));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Erreur lors de la suppression de l'enseignant: " . $e->getMessage();
            }
        }
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur enseignants.php: " . $e->getMessage());
}

// Calcul des statistiques
$total_actifs = 0;
$total_pa = 0;
$total_ph = 0;
$total_pes = 0;
$total_vacataires = 0;
$total_cours = 0;

foreach($enseignants as $ens) {
    if($ens['statut'] == 'actif') $total_actifs++;
    if($ens['grade'] == 'PA') $total_pa++;
    if($ens['grade'] == 'PH') $total_ph++;
    if($ens['grade'] == 'PES') $total_pes++;
    if($ens['grade'] == 'Vacataire') $total_vacataires++;
    $total_cours += $ens['nb_matieres'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 pour les sélections multiples -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
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
        --pink-color: #e84393;
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
        --pink-color: #fd79a8;
    }
    
    .badge.bg-pink {
        background-color: var(--pink-color) !important;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
        min-height: 100vh;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    .app-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        transition: background-color 0.3s ease;
        z-index: 1000;
    }
    
    .sidebar-header {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
    }
    
    .sidebar-logo {
        width: 50px;
        height: 50px;
        background: var(--secondary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
    }
    
    .user-info {
        text-align: center;
        margin-bottom: 20px;
        padding: 0 15px;
    }
    
    .user-role {
        display: inline-block;
        padding: 4px 12px;
        background: var(--secondary-color);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        margin-top: 5px;
    }
    
    .sidebar-nav {
        padding: 15px;
    }
    
    .nav-section {
        margin-bottom: 25px;
    }
    
    .nav-section-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.6);
        margin-bottom: 10px;
        padding: 0 10px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--sidebar-text);
        text-decoration: none;
        border-radius: 5px;
        margin-bottom: 5px;
        transition: all 0.3s;
    }
    
    .nav-link:hover, .nav-link.active {
        background-color: var(--secondary-color);
        color: white;
    }
    
    .nav-link i {
        width: 20px;
        margin-right: 10px;
        text-align: center;
    }
    
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
        transition: background-color 0.3s ease, color 0.3s ease;
        width: calc(100% - 250px);
    }
    
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
        color: var(--text-color);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    [data-theme="dark"] .card-header {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    .table {
        color: var(--text-color);
    }
    
    [data-theme="dark"] .table {
        --bs-table-bg: var(--card-bg);
        --bs-table-striped-bg: rgba(255, 255, 255, 0.05);
        --bs-table-hover-bg: rgba(255, 255, 255, 0.1);
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 15px;
        color: var(--text-color);
    }
    
    .teacher-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
    }
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    /* Badges pour les matières */
    .matiere-badge {
        margin: 2px;
        font-size: 0.75em;
    }
    
    /* Corrections mode sombre */
    [data-theme="dark"] .text-muted {
        color: var(--text-muted) !important;
    }
    
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select {
        background-color: #2a2a2a;
        border-color: #444;
        color: var(--text-color);
    }
    
    [data-theme="dark"] .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }
    
    [data-theme="dark"] .btn-outline-primary {
        color: #86b7fe;
        border-color: #86b7fe;
    }
    
    [data-theme="dark"] .btn-outline-primary:hover {
        background-color: #86b7fe;
        color: #000;
    }
    
    [data-theme="dark"] .btn-outline-secondary {
        color: #adb5bd;
        border-color: #adb5bd;
    }
    
    [data-theme="dark"] .btn-outline-secondary:hover {
        background-color: #adb5bd;
        color: #000;
    }
    
    [data-theme="dark"] .btn-light {
        background-color: #444;
        color: var(--text-color);
        border-color: #555;
    }
    
    /* Tous les textes en mode sombre */
    [data-theme="dark"] h1,
    [data-theme="dark"] h2,
    [data-theme="dark"] h3,
    [data-theme="dark"] h4,
    [data-theme="dark"] h5,
    [data-theme="dark"] h6,
    [data-theme="dark"] p,
    [data-theme="dark"] span,
    [data-theme="dark"] div,
    [data-theme="dark"] label,
    [data-theme="dark"] small,
    [data-theme="dark"] strong {
        color: var(--text-color) !important;
    }
    
    /* Alertes en mode sombre */
    [data-theme="dark"] .alert {
        background-color: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.1);
        color: var(--text-color);
    }
    
    [data-theme="dark"] .alert-info {
        background-color: rgba(23, 162, 184, 0.2);
        border-color: rgba(23, 162, 184, 0.3);
    }
    
    [data-theme="dark"] .alert-success {
        background-color: rgba(40, 167, 69, 0.2);
        border-color: rgba(40, 167, 69, 0.3);
    }
    
    [data-theme="dark"] .alert-danger {
        background-color: rgba(220, 53, 69, 0.2);
        border-color: rgba(220, 53, 69, 0.3);
    }
    
    /* Select2 en mode sombre */
    [data-theme="dark"] .select2-container--default .select2-selection--single,
    [data-theme="dark"] .select2-container--default .select2-selection--multiple {
        background-color: #2a2a2a;
        border-color: #444;
        color: var(--text-color);
    }
    
    [data-theme="dark"] .select2-container--default .select2-selection__placeholder {
        color: #888;
    }
    
    [data-theme="dark"] .select2-container--default .select2-selection__rendered {
        color: var(--text-color);
    }
    
    [data-theme="dark"] .select2-container--default .select2-results__option {
        background-color: #2a2a2a;
        color: var(--text-color);
    }
    
    [data-theme="dark"] .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: var(--secondary-color);
        color: white;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            width: 70px;
            overflow-x: hidden;
        }
        
        .sidebar-header, .user-info, .nav-section-title, .nav-link span {
            display: none;
        }
        
        .nav-link {
            justify-content: center;
            padding: 15px;
        }
        
        .nav-link i {
            margin-right: 0;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 70px;
            padding: 15px;
            width: calc(100% - 70px);
        }
    }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI ADMIN</h5>
                <div class="user-role">Administrateur Principal</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                <small>Gestion des Enseignants</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="sites.php" class="nav-link">
                        <i class="fas fa-building"></i>
                        <span>Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Utilisateurs</span>
                    </a>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Étudiants</span>
                    </a>
                    <a href="enseignants.php" class="nav-link active">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Enseignants</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes</span>
                    </a>
                    <a href="matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Matières</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Notes</span>
                    </a>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Contenu Principal -->
        <div class="main-content">
            <!-- En-tête -->
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-chalkboard-teacher me-2"></i>
                            Gestion des Enseignants
                        </h2>
                        <p class="text-muted mb-0">Gérez le corps enseignant de l'ISGI</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ajouterEnseignantModal">
                            <i class="fas fa-plus me-2"></i>Ajouter un Enseignant
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>
                        Filtres de Recherche
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label for="site" class="form-label">Site</label>
                            <select class="form-select" id="site" name="site">
                                <option value="0">Tous les sites</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo $filtre_site == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="actif" <?php echo $filtre_statut == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                <option value="retraite" <?php echo $filtre_statut == 'retraite' ? 'selected' : ''; ?>>Retraité</option>
                                <option value="demission" <?php echo $filtre_statut == 'demission' ? 'selected' : ''; ?>>Démission</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="grade" class="form-label">Grade</label>
                            <select class="form-select" id="grade" name="grade">
                                <option value="">Tous les grades</option>
                                <option value="PA" <?php echo $filtre_grade == 'PA' ? 'selected' : ''; ?>>PA</option>
                                <option value="PH" <?php echo $filtre_grade == 'PH' ? 'selected' : ''; ?>>PH</option>
                                <option value="PES" <?php echo $filtre_grade == 'PES' ? 'selected' : ''; ?>>PES</option>
                                <option value="Vacataire" <?php echo $filtre_grade == 'Vacataire' ? 'selected' : ''; ?>>Vacataire</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="recherche" class="form-label">Recherche</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo htmlspecialchars($filtre_recherche); ?>" 
                                       placeholder="Matricule, Nom, Prénom, Email...">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || !empty($filtre_grade) || !empty($filtre_recherche)): ?>
                                <a href="enseignants.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques des enseignants -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <h3><?php echo count($enseignants); ?></h3>
                            <p class="text-muted mb-0">Total</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <h3><?php echo $total_actifs; ?></h3>
                            <p class="text-muted mb-0">Actifs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <h3><?php echo $total_pa; ?></h3>
                            <p class="text-muted mb-0">PA</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h3><?php echo $total_ph; ?></h3>
                            <p class="text-muted mb-0">PH</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-user-clock"></i>
                            </div>
                            <h3><?php echo $total_vacataires; ?></h3>
                            <p class="text-muted mb-0">Vacataires</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-secondary stats-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <h3><?php echo $total_cours; ?></h3>
                            <p class="text-muted mb-0">Cours total</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des enseignants -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Enseignants
                    </h5>
                    <div class="text-muted">
                        <?php echo count($enseignants); ?> enseignant(s) trouvé(s)
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($enseignants)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun enseignant trouvé avec les critères sélectionnés
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="teachersTable">
                            <thead>
                                <tr>
                                    <th>Enseignant</th>
                                    <th>Informations Professionnelles</th>
                                    <th>Contact & Site</th>
                                    <th>Grade & Statut</th>
                                    <th>Matières enseignées</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($enseignants as $ens): 
                                // Calculer l'ancienneté
                                $anciennete = 'N/A';
                                if($ens['date_embauche'] && $ens['date_embauche'] != '0000-00-00') {
                                    $embauche = new DateTime($ens['date_embauche']);
                                    $anciennete = $embauche->diff(new DateTime())->y;
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="teacher-avatar me-3">
                                                <?php 
                                                $initials = '';
                                                if(!empty($ens['prenom']) && !empty($ens['nom'])) {
                                                    $initials = strtoupper(substr($ens['prenom'], 0, 1) . substr($ens['nom'], 0, 1));
                                                } else {
                                                    $initials = '??';
                                                }
                                                echo $initials;
                                                ?>
                                            </div>
                                            <div>
                                                <strong>
                                                    <?php 
                                                    if(!empty($ens['prenom']) && !empty($ens['nom'])) {
                                                        echo htmlspecialchars($ens['prenom'] . ' ' . $ens['nom']);
                                                    } else {
                                                        echo 'Nom inconnu';
                                                    }
                                                    ?>
                                                </strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($ens['matricule'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong>Spécialité:</strong> <?php echo htmlspecialchars($ens['specialite'] ?? 'Non spécifiée'); ?></div>
                                            <?php if($anciennete != 'N/A'): ?>
                                            <div><strong>Ancienneté:</strong> <?php echo $anciennete; ?> an(s)</div>
                                            <?php endif; ?>
                                            <?php if($ens['date_embauche'] && $ens['date_embauche'] != '0000-00-00'): ?>
                                            <div><strong>Embauché le:</strong> <?php echo date('d/m/Y', strtotime($ens['date_embauche'])); ?></div>
                                            <?php endif; ?>
                                            <?php if(($ens['nb_matieres'] ?? 0) > 0): ?>
                                            <div><strong>Matières en charge:</strong> <?php echo $ens['nb_matieres']; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><i class="fas fa-envelope text-muted me-2"></i><?php echo htmlspecialchars($ens['email'] ?? 'N/A'); ?></div>
                                            <div><i class="fas fa-phone text-muted me-2"></i><?php echo htmlspecialchars($ens['telephone'] ?? 'N/A'); ?></div>
                                            <div class="mt-2">
                                                <i class="fas fa-building text-muted me-2"></i>
                                                <strong><?php echo htmlspecialchars($ens['site_nom'] ?? 'Non assigné'); ?></strong>
                                                <div class="text-muted"><?php echo htmlspecialchars($ens['site_ville'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo getGradeBadge($ens['grade']); ?>
                                        <div class="mt-2">
                                            <?php echo getStatutBadge($ens['statut']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?php if(!empty($ens['matieres_list'])): ?>
                                                <?php foreach($ens['matieres_list'] as $matiere): ?>
                                                <span class="badge bg-info matiere-badge"><?php echo htmlspecialchars($matiere['nom']); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Aucune matière assignée</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-warning" title="Modifier" onclick="modifierEnseignant(<?php echo $ens['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-success" title="Gérer matières" onclick="gererMatieres(<?php echo $ens['id']; ?>)">
                                                <i class="fas fa-book"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" title="Supprimer" onclick="supprimerEnseignant(<?php echo $ens['id']; ?>, '<?php echo htmlspecialchars(addslashes($ens['prenom'] . ' ' . $ens['nom'])); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
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
    
    <!-- Modal pour ajouter un enseignant -->
    <div class="modal fade" id="ajouterEnseignantModal" tabindex="-1" aria-labelledby="ajouterEnseignantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ajouterEnseignantModalLabel">
                        <i class="fas fa-plus me-2"></i>Ajouter un Nouvel Enseignant
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="ajouter_enseignant">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">Informations Personnelles</h6>
                                
                                <div class="mb-3">
                                    <label class="form-label">Nom <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="nom" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Prénom <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="prenom" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h6 class="mb-3">Informations Professionnelles</h6>
                                
                                <div class="mb-3">
                                    <label class="form-label">Matricule <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="matricule" required 
                                           placeholder="Ex: ENS-2023-001" id="matriculeInput">
                                    <small class="text-muted">Le matricule doit être unique</small>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="genererMatricule()">
                                        <i class="fas fa-sync-alt"></i> Générer un matricule
                                    </button>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Grade <span class="text-danger">*</span></label>
                                    <select class="form-select" name="grade" required>
                                        <option value="">Sélectionner un grade</option>
                                        <option value="PA">PA</option>
                                        <option value="PH">PH</option>
                                        <option value="PES">PES</option>
                                        <option value="Vacataire" selected>Vacataire</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Spécialité</label>
                                    <input type="text" class="form-control" name="specialite" 
                                           placeholder="Informatique, Mathématiques, Droit...">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Date d'embauche</label>
                                    <input type="date" class="form-control" name="date_embauche" 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Statut</label>
                                    <select class="form-select" name="statut">
                                        <option value="actif" selected>Actif</option>
                                        <option value="retraite">Retraité</option>
                                        <option value="demission">Démission</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <h6 class="mb-3">Coordonnées</h6>
                                
                                <div class="mb-3">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Téléphone</label>
                                    <input type="text" class="form-control" name="telephone" 
                                           placeholder="+242 XX XX XX XX">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h6 class="mb-3">Affectation</h6>
                                
                                <div class="mb-3">
                                    <label class="form-label">Site d'affectation</label>
                                    <select class="form-select" name="site_id">
                                        <option value="">Sélectionner un site</option>
                                        <?php foreach($sites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Matières enseignées</label>
                                    <select class="form-select" name="matieres_ids[]" multiple id="matieresSelect" style="width: 100%;">
                                        <?php if(empty($matieres_sans_enseignant)): ?>
                                        <option value="" disabled>Aucune matière disponible (toutes ont déjà un enseignant)</option>
                                        <?php else: ?>
                                        <?php foreach($matieres_sans_enseignant as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>">
                                            <?php echo htmlspecialchars($matiere['nom'] . ' (' . $matiere['code'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <small class="text-muted">
                                        Maintenez Ctrl (Cmd sur Mac) pour sélectionner plusieurs matières
                                        <br>
                                        <span class="text-info">
                                            <i class="fas fa-info-circle"></i> 
                                            <?php echo count($matieres_sans_enseignant); ?> matière(s) disponible(s) sur <?php echo count($matieres); ?> au total
                                        </span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer l'Enseignant
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        // Sauvegarder dans localStorage pour persistance
        localStorage.setItem('isgi_theme', newTheme);
        
        // Mettre à jour le texte du bouton
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (newTheme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Fonction pour générer un matricule
    function genererMatricule() {
        const date = new Date();
        const year = date.getFullYear();
        const random = Math.floor(Math.random() * 999).toString().padStart(3, '0');
        const matricule = `ENS-${year}-${random}`;
        document.getElementById('matriculeInput').value = matricule;
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Vérifier localStorage d'abord, puis cookies, puis par défaut light
        const theme = localStorage.getItem('isgi_theme') || 
                     document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 
                     'light';
        
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Initialiser DataTable
        $('#teachersTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [4, 5] }
            ]
        });
        
        // Initialiser Select2 pour les matières
        $('#matieresSelect').select2({
            placeholder: "Sélectionnez les matières enseignées",
            allowClear: true,
            width: '100%'
        });
        
        // Générer automatiquement un matricule au chargement
        genererMatricule();
    });
    
    // Fonctions pour les actions
    function modifierEnseignant(id) {
        // Rediriger vers une page de modification
        window.location.href = 'modifier_enseignant.php?id=' + id;
    }
    
    function gererMatieres(id) {
        // Rediriger vers une page de gestion des matières
        window.location.href = 'gerer_matieres.php?id=' + id;
    }
    
    function supprimerEnseignant(id, nom) {
        if(confirm('Êtes-vous sûr de vouloir supprimer l\'enseignant "' + nom + '" ?\n\nCette action est irréversible et supprimera également son compte utilisateur.')) {
            // Créer un formulaire caché pour la suppression
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'supprimer_enseignant';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'enseignant_id';
            idInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>