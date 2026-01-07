<?php
// dashboard/admin_principal/utilisateurs.php

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
    $pageTitle = "Administrateur Principal - Gestion des Utilisateurs";
    
    // Fonctions utilitaires
    function getRoleBadge($role_id, $role_name) {
        $badges = [
            1 => 'badge bg-danger', // Administrateur Principal
            2 => 'badge bg-primary', // Administrateur Site
            3 => 'badge bg-success', // Gestionnaire Principal
            4 => 'badge bg-info', // Gestionnaire Secondaire
            5 => 'badge bg-warning', // DAC
            6 => 'badge bg-secondary', // Surveillant Général
            7 => 'badge bg-dark', // Professeur
            8 => 'badge bg-light text-dark', // Étudiant
            9 => 'badge bg-muted' // Tuteur
        ];
        $class = $badges[$role_id] ?? 'badge bg-secondary';
        return '<span class="'.$class.'">'.htmlspecialchars($role_name).'</span>';
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
                return '<span class="badge bg-danger">Inactif</span>';
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'suspendu':
                return '<span class="badge bg-secondary">Suspendu</span>';
            default:
                return '<span class="badge bg-secondary">'.htmlspecialchars($statut).'</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $utilisateurs = array();
    $roles = array();
    $sites = array();
    
    // Récupérer les rôles
    $roles = $db->query("SELECT * FROM roles ORDER BY niveau_acces")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les sites
    $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'ajouter':
                    $email = trim($_POST['email']);
                    $mot_de_passe = $_POST['mot_de_passe'];
                    $confirmation = $_POST['confirmation'];
                    $nom = trim($_POST['nom']);
                    $prenom = trim($_POST['prenom']);
                    $telephone = trim($_POST['telephone']);
                    $role_id = intval($_POST['role_id']);
                    $site_id = !empty($_POST['site_id']) ? intval($_POST['site_id']) : null;
                    
                    // Vérification spécifique pour certains rôles
                    $roles_requerant_site = [2, 6, 7, 8]; // Administrateur Site, Surveillant, Professeur, Étudiant
                    
                    if (in_array($role_id, $roles_requerant_site) && empty($site_id)) {
                        $error = "Ce rôle nécessite l'attribution d'un site.";
                        break;
                    }
                    
                    if (!empty($email) && !empty($mot_de_passe) && $mot_de_passe === $confirmation) {
                        // Vérifier si l'email existe déjà
                        $check = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = ?");
                        $check->execute([$email]);
                        
                        if ($check->fetchColumn() == 0) {
                            $hashed_password = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                            $sql = "INSERT INTO utilisateurs (email, mot_de_passe, nom, prenom, telephone, role_id, site_id, statut) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'actif')";
                            $stmt = $db->prepare($sql);
                            if ($stmt->execute([$email, $hashed_password, $nom, $prenom, $telephone, $role_id, $site_id])) {
                                $success = "Utilisateur ajouté avec succès!";
                                
                                // Si c'est un professeur, créer aussi l'entrée dans la table enseignants
                                if ($role_id == 7 && $site_id) {
                                    $user_id = $db->lastInsertId();
                                    // Générer un matricule d'enseignant
                                    $count_ens = $db->query("SELECT COUNT(*) FROM enseignants")->fetchColumn();
                                    $matricule = 'ENS-' . str_pad($count_ens + 1, 3, '0', STR_PAD_LEFT);
                                    
                                    $sql_ens = "INSERT INTO enseignants (utilisateur_id, matricule, specialite, grade, site_id, date_embauche, statut) 
                                               VALUES (?, ?, 'Spécialité à définir', 'Vacataire', ?, CURDATE(), 'actif')";
                                    $stmt_ens = $db->prepare($sql_ens);
                                    $stmt_ens->execute([$user_id, $matricule, $site_id]);
                                }
                            } else {
                                $error = "Erreur lors de l'ajout de l'utilisateur.";
                            }
                        } else {
                            $error = "Cet email est déjà utilisé par un autre utilisateur.";
                        }
                    } else {
                        $error = "Veuillez remplir tous les champs obligatoires et vérifier la confirmation du mot de passe.";
                    }
                    break;
                    
                case 'modifier':
                    $id = intval($_POST['id']);
                    $email = trim($_POST['email']);
                    $nom = trim($_POST['nom']);
                    $prenom = trim($_POST['prenom']);
                    $telephone = trim($_POST['telephone']);
                    $role_id = intval($_POST['role_id']);
                    $site_id = !empty($_POST['site_id']) ? intval($_POST['site_id']) : null;
                    $statut = $_POST['statut'];
                    
                    // Vérification spécifique pour certains rôles
                    $roles_requerant_site = [2, 6, 7, 8];
                    
                    if (in_array($role_id, $roles_requerant_site) && empty($site_id)) {
                        $error = "Ce rôle nécessite l'attribution d'un site.";
                        break;
                    }
                    
                    if ($id > 0 && !empty($email)) {
                        // Vérifier si l'email existe déjà pour un autre utilisateur
                        $check = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = ? AND id != ?");
                        $check->execute([$email, $id]);
                        
                        if ($check->fetchColumn() == 0) {
                            $sql = "UPDATE utilisateurs SET email = ?, nom = ?, prenom = ?, telephone = ?, 
                                    role_id = ?, site_id = ?, statut = ? WHERE id = ?";
                            $stmt = $db->prepare($sql);
                            if ($stmt->execute([$email, $nom, $prenom, $telephone, $role_id, $site_id, $statut, $id])) {
                                $success = "Utilisateur modifié avec succès!";
                                
                                // Si c'est un professeur, mettre à jour aussi la table enseignants
                                if ($role_id == 7) {
                                    // Vérifier si l'enseignant existe déjà
                                    $check_ens = $db->prepare("SELECT id FROM enseignants WHERE utilisateur_id = ?");
                                    $check_ens->execute([$id]);
                                    
                                    if ($check_ens->fetch()) {
                                        // Mettre à jour l'enseignant existant
                                        $update_ens = $db->prepare("UPDATE enseignants SET site_id = ? WHERE utilisateur_id = ?");
                                        $update_ens->execute([$site_id, $id]);
                                    } else if ($site_id) {
                                        // Créer un nouvel enseignant si nécessaire
                                        $count_ens = $db->query("SELECT COUNT(*) FROM enseignants")->fetchColumn();
                                        $matricule = 'ENS-' . str_pad($count_ens + 1, 3, '0', STR_PAD_LEFT);
                                        
                                        $insert_ens = $db->prepare("INSERT INTO enseignants (utilisateur_id, matricule, specialite, grade, site_id, date_embauche, statut) 
                                                                   VALUES (?, ?, 'Spécialité à définir', 'Vacataire', ?, CURDATE(), 'actif')");
                                        $insert_ens->execute([$id, $matricule, $site_id]);
                                    }
                                }
                            } else {
                                $error = "Erreur lors de la modification de l'utilisateur.";
                            }
                        } else {
                            $error = "Cet email est déjà utilisé par un autre utilisateur.";
                        }
                    }
                    break;
                    
                case 'reinitialiser_mdp':
                    $id = intval($_POST['id']);
                    $nouveau_mdp = $_POST['nouveau_mdp'];
                    $confirmation = $_POST['confirmation'];
                    
                    if ($id > 0 && !empty($nouveau_mdp) && $nouveau_mdp === $confirmation) {
                        $hashed_password = password_hash($nouveau_mdp, PASSWORD_DEFAULT);
                        $sql = "UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([$hashed_password, $id])) {
                            $success = "Mot de passe réinitialisé avec succès!";
                        } else {
                            $error = "Erreur lors de la réinitialisation du mot de passe.";
                        }
                    } else {
                        $error = "Les mots de passe ne correspondent pas.";
                    }
                    break;
            }
        }
    }
    
    // Récupérer la liste des utilisateurs avec informations complètes
    $query = "SELECT u.*, r.nom as role_nom, s.nom as site_nom, s.ville as site_ville,
              CASE 
                WHEN EXISTS(SELECT 1 FROM etudiants e WHERE e.utilisateur_id = u.id) THEN 'Étudiant'
                WHEN EXISTS(SELECT 1 FROM enseignants en WHERE en.utilisateur_id = u.id) THEN 'Enseignant'
                WHEN EXISTS(SELECT 1 FROM administrateurs a WHERE a.utilisateur_id = u.id) THEN 'Administrateur'
                ELSE 'Utilisateur'
              END as type_compte
              FROM utilisateurs u
              LEFT JOIN roles r ON u.role_id = r.id
              LEFT JOIN sites s ON u.site_id = s.id
              ORDER BY u.role_id, u.nom, u.prenom";
    
    $utilisateurs = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
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
        min-height: 100vh;
    }
    
    .app-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar (identique au dashboard) */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
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
    }
    
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    
    .table {
        color: var(--text-color);
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
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--secondary-color);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
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
                <small>Gestion des Utilisateurs</small>
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
                    <a href="utilisateurs.php" class="nav-link active">
                        <i class="fas fa-users"></i>
                        <span>Utilisateurs</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Administration</div>
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
                            <i class="fas fa-users me-2"></i>
                            Gestion des Utilisateurs
                        </h2>
                        <p class="text-muted mb-0">Gérez tous les utilisateurs du système ISGI</p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ajouterUtilisateurModal">
                        <i class="fas fa-plus me-2"></i>Ajouter un Utilisateur
                    </button>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Statistiques des utilisateurs -->
            <div class="row mb-4">
                <?php 
                $total_actifs = 0;
                $total_admins = 0;
                $total_profs = 0;
                foreach($utilisateurs as $user) {
                    if($user['statut'] == 'actif') $total_actifs++;
                    if(in_array($user['role_id'], [1,2,3,4,5,6])) $total_admins++;
                    if($user['role_id'] == 7) $total_profs++;
                }
                ?>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h3><?php echo count($utilisateurs); ?></h3>
                            <p class="text-muted mb-0">Utilisateurs Total</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <h3><?php echo $total_actifs; ?></h3>
                            <p class="text-muted mb-0">Utilisateurs Actifs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <h3><?php echo $total_admins; ?></h3>
                            <p class="text-muted mb-0">Administrateurs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-info stats-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <h3><?php echo $total_profs; ?></h3>
                            <p class="text-muted mb-0">Professeurs</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des utilisateurs -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Utilisateurs
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="filterUsers('all')">
                            Tous
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="filterUsers('actif')">
                            Actifs
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="filterUsers('en_attente')">
                            En attente
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($utilisateurs)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun utilisateur trouvé
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Contact</th>
                                    <th>Rôle</th>
                                    <th>Site</th>
                                    <th>Statut</th>
                                    <th>Dernière Connexion</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($utilisateurs as $user): ?>
                                <tr data-status="<?php echo $user['statut']; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-3">
                                                <?php echo strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></strong>
                                                <div class="text-muted small"><?php echo htmlspecialchars($user['email']); ?></div>
                                                <small class="badge bg-secondary"><?php echo htmlspecialchars($user['type_compte']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($user['telephone']): ?>
                                        <div><i class="fas fa-phone text-muted me-2"></i><?php echo htmlspecialchars($user['telephone']); ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small">Créé le: <?php echo date('d/m/Y', strtotime($user['date_creation'])); ?></div>
                                    </td>
                                    <td>
                                        <?php echo getRoleBadge($user['role_id'], $user['role_nom']); ?>
                                    </td>
                                    <td>
                                        <?php if($user['site_nom']): ?>
                                        <div><strong><?php echo htmlspecialchars($user['site_nom']); ?></strong></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($user['site_ville']); ?></div>
                                        <?php else: ?>
                                        <span class="text-muted">Non assigné</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatutBadge($user['statut']); ?></td>
                                    <td>
                                        <?php if($user['derniere_connexion']): ?>
                                        <div><?php echo date('d/m/Y', strtotime($user['derniere_connexion'])); ?></div>
                                        <div class="text-muted small"><?php echo date('H:i', strtotime($user['derniere_connexion'])); ?></div>
                                        <?php else: ?>
                                        <span class="text-muted">Jamais</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#modifierUtilisateurModal"
                                                    onclick="chargerUtilisateur(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#reinitialiserMdpModal"
                                                    onclick="preparerReinitialisation(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['prenom'] . ' ' . $user['nom'])); ?>')">
                                                <i class="fas fa-key"></i>
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
    
    <!-- Modal Ajouter Utilisateur -->
    <div class="modal fade" id="ajouterUtilisateurModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Ajouter un Nouvel Utilisateur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="ajouter">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nom" class="form-label">Nom *</label>
                                <input type="text" class="form-control" id="nom" name="nom" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="prenom" class="form-label">Prénom *</label>
                                <input type="text" class="form-control" id="prenom" name="prenom" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="telephone" name="telephone">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="mot_de_passe" class="form-label">Mot de passe *</label>
                                <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="confirmation" class="form-label">Confirmation *</label>
                                <input type="password" class="form-control" id="confirmation" name="confirmation" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role_id" class="form-label">Rôle *</label>
                            <select class="form-select" id="role_id" name="role_id" required onchange="toggleSiteField()">
                                <?php foreach($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="siteField">
                            <label for="site_id" class="form-label">Site *</label>
                            <select class="form-select" id="site_id" name="site_id">
                                <option value="">Sélectionner un site</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter l'Utilisateur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Modifier Utilisateur -->
    <div class="modal fade" id="modifierUtilisateurModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier l'Utilisateur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" id="edit_id" name="id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_nom" class="form-label">Nom *</label>
                                <input type="text" class="form-control" id="edit_nom" name="nom" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_prenom" class="form-label">Prénom *</label>
                                <input type="text" class="form-control" id="edit_prenom" name="prenom" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_telephone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="edit_telephone" name="telephone">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_role_id" class="form-label">Rôle *</label>
                            <select class="form-select" id="edit_role_id" name="role_id" required onchange="toggleEditSiteField()">
                                <?php foreach($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="editSiteField">
                            <label for="edit_site_id" class="form-label">Site *</label>
                            <select class="form-select" id="edit_site_id" name="site_id">
                                <option value="">Sélectionner un site</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_statut" class="form-label">Statut *</label>
                            <select class="form-select" id="edit_statut" name="statut" required>
                                <option value="actif">Actif</option>
                                <option value="inactif">Inactif</option>
                                <option value="en_attente">En attente</option>
                                <option value="suspendu">Suspendu</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer les Modifications</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Réinitialiser Mot de Passe -->
    <div class="modal fade" id="reinitialiserMdpModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Réinitialiser le Mot de Passe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reinitialiser_mdp">
                        <input type="hidden" id="reset_id" name="id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Réinitialisation du mot de passe pour: <strong id="reset_nom"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nouveau_mdp" class="form-label">Nouveau mot de passe *</label>
                            <input type="password" class="form-control" id="nouveau_mdp" name="nouveau_mdp" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reset_confirmation" class="form-label">Confirmation *</label>
                            <input type="password" class="form-control" id="reset_confirmation" name="confirmation" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-warning">Réinitialiser le Mot de Passe</button>
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
    
    <script>
    // Initialiser DataTable
    $(document).ready(function() {
        $('#usersTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [6] }
            ]
        });
        
        // Initialiser les champs site
        toggleSiteField();
        toggleEditSiteField();
    });
    
    // Charger les données d'un utilisateur pour modification
    function chargerUtilisateur(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_nom').value = user.nom;
        document.getElementById('edit_prenom').value = user.prenom;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_telephone').value = user.telephone || '';
        document.getElementById('edit_role_id').value = user.role_id;
        document.getElementById('edit_site_id').value = user.site_id || '';
        document.getElementById('edit_statut').value = user.statut;
        
        // Gérer l'affichage du champ site
        toggleEditSiteField();
    }
    
    // Préparer la réinitialisation du mot de passe
    function preparerReinitialisation(id, nom) {
        document.getElementById('reset_id').value = id;
        document.getElementById('reset_nom').textContent = nom;
    }
    
    // Filtrer les utilisateurs par statut
    function filterUsers(status) {
        const table = $('#usersTable').DataTable();
        
        if (status === 'all') {
            table.search('').columns().search('').draw();
        } else {
            table.column(4).search(status).draw();
        }
    }
    
    // Gérer l'affichage du champ site selon le rôle pour l'ajout
    function toggleSiteField() {
        const roleId = document.getElementById('role_id').value;
        const siteField = document.getElementById('siteField');
        const siteSelect = document.getElementById('site_id');
        
        // Rôles qui nécessitent un site (2=Admin Site, 6=Surveillant, 7=Professeur, 8=Étudiant)
        const rolesAvecSite = [2, 6, 7, 8];
        
        if (rolesAvecSite.includes(parseInt(roleId))) {
            siteField.style.display = 'block';
            siteSelect.required = true;
        } else {
            siteField.style.display = 'none';
            siteSelect.required = false;
            siteSelect.value = '';
        }
    }
    
    // Gérer l'affichage du champ site selon le rôle pour la modification
    function toggleEditSiteField() {
        const roleId = document.getElementById('edit_role_id').value;
        const siteField = document.getElementById('editSiteField');
        const siteSelect = document.getElementById('edit_site_id');
        
        // Rôles qui nécessitent un site
        const rolesAvecSite = [2, 6, 7, 8];
        
        if (rolesAvecSite.includes(parseInt(roleId))) {
            siteField.style.display = 'block';
            siteSelect.required = true;
        } else {
            siteField.style.display = 'none';
            siteSelect.required = false;
            siteSelect.value = '';
        }
    }
    </script>
</body>
</html>