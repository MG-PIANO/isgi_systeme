<?php
// dashboard/gestionnaire_principal/tarifs.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire principal (rôle_id = 3)
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
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
    $pageTitle = "Gestion des Tarifs";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilisateur';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
    }
    
    // Configuration des paramètres
    $mois_annee_academique = 10; // L'année académique dure 10 mois
    
    // Initialiser les messages
    $success_message = '';
    $error_message = '';
    
    // Récupérer les années académiques actives
    $query_annees = "SELECT id, libelle FROM annees_academiques WHERE statut = 'active'";
    if ($site_id) {
        $query_annees .= " AND site_id = ?";
        $stmt = $db->prepare($query_annees);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query_annees);
        $stmt->execute();
    }
    $annees_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les options de formation
    $query_options = "SELECT id, nom FROM options_formation ORDER BY nom";
    $stmt = $db->prepare($query_options);
    $stmt->execute();
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les niveaux
    $query_niveaux = "SELECT id, code, libelle, cycle FROM niveaux ORDER BY ordre";
    $stmt = $db->prepare($query_niveaux);
    $stmt->execute();
    $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les types de frais
    $query_types_frais = "SELECT id, nom, description FROM types_frais ORDER BY id";
    $stmt = $db->prepare($query_types_frais);
    $stmt->execute();
    $types_frais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les tarifs existants
    $query_tarifs = "SELECT t.*, o.nom as option_nom, n.libelle as niveau_libelle, 
                            tf.nom as type_frais_nom, aa.libelle as annee_libelle
                     FROM tarifs t
                     JOIN options_formation o ON t.option_id = o.id
                     JOIN niveaux n ON t.niveau_id = n.id
                     JOIN types_frais tf ON t.type_frais_id = tf.id
                     JOIN annees_academiques aa ON t.annee_academique_id = aa.id
                     WHERE t.site_id = ? 
                     ORDER BY tf.id, o.nom, n.ordre";
    $stmt = $db->prepare($query_tarifs);
    $stmt->execute([$site_id]);
    $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les frais fixes (inscription et réinscription)
    $frais_fixes = [];
    foreach ($types_frais as $type) {
        if (in_array($type['nom'], ['Frais d\'inscription', 'Frais de réinscription'])) {
            $frais_fixes[$type['id']] = [
                'nom' => $type['nom'],
                'montant' => $type['montant_base'] ?? 0
            ];
        }
    }
    
    // Gestion des actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ajouter un nouveau tarif (scolarité)
        if (isset($_POST['action']) && $_POST['action'] == 'ajouter_scolarite') {
            $option_id = $_POST['option_id'];
            $niveau_id = $_POST['niveau_id'];
            $annee_academique_id = $_POST['annee_academique_id'];
            $montant = $_POST['montant_scolarite'];
            $type_frais_id = 2; // ID pour scolarité (à adapter selon votre base)
            
            // Vérifier si le tarif existe déjà
            $query_check = "SELECT id FROM tarifs 
                           WHERE option_id = ? 
                           AND niveau_id = ? 
                           AND type_frais_id = ? 
                           AND annee_academique_id = ? 
                           AND site_id = ?";
            $stmt = $db->prepare($query_check);
            $stmt->execute([$option_id, $niveau_id, $type_frais_id, $annee_academique_id, $site_id]);
            
            if ($stmt->rowCount() > 0) {
                $error_message = "Ce tarif de scolarité existe déjà pour cette configuration.";
            } else {
                // Insérer le nouveau tarif
                $query_insert = "INSERT INTO tarifs (option_id, niveau_id, type_frais_id, 
                                                   montant, annee_academique_id, site_id, 
                                                   date_debut, date_fin, date_creation)
                               VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NULL, NOW())";
                $stmt = $db->prepare($query_insert);
                $stmt->execute([$option_id, $niveau_id, $type_frais_id, $montant, 
                              $annee_academique_id, $site_id]);
                
                $success_message = "Tarif de scolarité ajouté avec succès.";
                
                // Recharger les tarifs
                $stmt = $db->prepare($query_tarifs);
                $stmt->execute([$site_id]);
                $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        // Modifier les frais fixes (inscription/réinscription)
        if (isset($_POST['action']) && $_POST['action'] == 'modifier_frais_fixes') {
            $frais_inscription = $_POST['frais_inscription'];
            $frais_reinscription = $_POST['frais_reinscription'];
            $annee_academique_id = $_POST['annee_frais_fixes'];
            
            // IDs des types de frais (à adapter selon votre base)
            // ID 1 = Inscription, ID ? = Réinscription
            $types_a_mettre_a_jour = [
                1 => $frais_inscription, // Inscription
                // Ajouter l'ID pour réinscription si elle existe
            ];
            
            foreach ($types_a_mettre_a_jour as $type_id => $montant) {
                // Vérifier si le tarif existe déjà
                $query_check = "SELECT id FROM tarifs 
                               WHERE type_frais_id = ? 
                               AND annee_academique_id = ? 
                               AND site_id = ?";
                $stmt = $db->prepare($query_check);
                $stmt->execute([$type_id, $annee_academique_id, $site_id]);
                
                if ($stmt->rowCount() > 0) {
                    // Mettre à jour
                    $query_update = "UPDATE tarifs 
                                   SET montant = ?, date_modification = NOW()
                                   WHERE type_frais_id = ? 
                                   AND annee_academique_id = ? 
                                   AND site_id = ?";
                    $stmt = $db->prepare($query_update);
                    $stmt->execute([$montant, $type_id, $annee_academique_id, $site_id]);
                } else {
                    // Insérer
                    $query_insert = "INSERT INTO tarifs (option_id, niveau_id, type_frais_id, 
                                                       montant, annee_academique_id, site_id, 
                                                       date_debut, date_fin, date_creation)
                                   VALUES (NULL, NULL, ?, ?, ?, ?, CURDATE(), NULL, NOW())";
                    $stmt = $db->prepare($query_insert);
                    $stmt->execute([$type_id, $montant, $annee_academique_id, $site_id]);
                }
            }
            
            $success_message = "Frais fixes mis à jour avec succès.";
            
            // Recharger les tarifs
            $stmt = $db->prepare($query_tarifs);
            $stmt->execute([$site_id]);
            $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Modifier un tarif
        if (isset($_POST['action']) && $_POST['action'] == 'modifier') {
            $tarif_id = $_POST['tarif_id'];
            $montant = $_POST['montant_modif'];
            
            // Mettre à jour le tarif
            $query_update = "UPDATE tarifs 
                           SET montant = ?, date_modification = NOW()
                           WHERE id = ? AND site_id = ?";
            $stmt = $db->prepare($query_update);
            $stmt->execute([$montant, $tarif_id, $site_id]);
            
            $success_message = "Tarif modifié avec succès.";
            
            // Recharger les tarifs
            $stmt = $db->prepare($query_tarifs);
            $stmt->execute([$site_id]);
            $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Supprimer un tarif
        if (isset($_POST['action']) && $_POST['action'] == 'supprimer') {
            $tarif_id = $_POST['tarif_id'];
            
            // Supprimer le tarif
            $query_delete = "DELETE FROM tarifs WHERE id = ? AND site_id = ?";
            $stmt = $db->prepare($query_delete);
            $stmt->execute([$tarif_id, $site_id]);
            
            $success_message = "Tarif supprimé avec succès.";
            
            // Recharger les tarifs
            $stmt = $db->prepare($query_tarifs);
            $stmt->execute([$site_id]);
            $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Ajouter en masse (scolarité)
        if (isset($_POST['action']) && $_POST['action'] == 'ajouter_masse') {
            $option_id = $_POST['option_id_masse'];
            $annee_academique_id = $_POST['annee_academique_id_masse'];
            $montant = $_POST['montant_masse'];
            $type_frais_id = 2; // Scolarité
            
            $added = 0;
            $skipped = 0;
            
            // Pour tous les niveaux
            foreach ($niveaux as $niveau) {
                // Vérifier si le tarif existe déjà
                $query_check = "SELECT id FROM tarifs 
                               WHERE option_id = ? 
                               AND niveau_id = ? 
                               AND type_frais_id = ? 
                               AND annee_academique_id = ? 
                               AND site_id = ?";
                $stmt = $db->prepare($query_check);
                $stmt->execute([$option_id, $niveau['id'], $type_frais_id, 
                              $annee_academique_id, $site_id]);
                
                if ($stmt->rowCount() == 0) {
                    // Insérer le nouveau tarif
                    $query_insert = "INSERT INTO tarifs (option_id, niveau_id, type_frais_id, 
                                                       montant, annee_academique_id, site_id, 
                                                       date_debut, date_fin, date_creation)
                                   VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NULL, NOW())";
                    $stmt_insert = $db->prepare($query_insert);
                    $stmt_insert->execute([$option_id, $niveau['id'], $type_frais_id, 
                                         $montant, $annee_academique_id, $site_id]);
                    $added++;
                } else {
                    $skipped++;
                }
            }
            
            $success_message = "$added tarifs de scolarité ajoutés, $skipped déjà existants.";
            
            // Recharger les tarifs
            $stmt = $db->prepare($query_tarifs);
            $stmt->execute([$site_id]);
            $tarifs_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Récupérer le nom du site si assigné
    $site_nom = '';
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
    // Compter les demandes en attente
    $query = "SELECT COUNT(*) as count FROM demande_inscriptions WHERE statut = 'en_attente'";
    if ($site_id) {
        $query .= " AND site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $demandeCount = $result['count'] ?? 0;
    
} catch (Exception $e) {
    $error_message = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log($error_message);
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
    
    /* Sidebar */
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
    
    .nav-badge {
        margin-left: auto;
        background: var(--accent-color);
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 10px;
    }
    
    /* Contenu principal */
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Tableaux */
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
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Calculatrice */
    .calculatrice {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
    
    .resultat-calc {
        font-size: 1.1rem;
        padding: 10px;
        background: rgba(52, 152, 219, 0.1);
        border-radius: 5px;
        text-align: center;
    }
    
    .mensualite-item {
        padding: 8px 12px;
        margin-bottom: 5px;
        background: rgba(52, 152, 219, 0.1);
        border-radius: 4px;
        font-size: 0.9rem;
        border-left: 3px solid var(--primary-color);
    }
    
    .paiement-option {
        cursor: pointer;
        transition: all 0.3s;
        border: 2px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        text-align: center;
    }
    
    .paiement-option:hover {
        border-color: var(--primary-color);
        background: rgba(52, 152, 219, 0.05);
    }
    
    .paiement-option.active {
        border-color: var(--primary-color);
        background: rgba(52, 152, 219, 0.1);
    }
    
    .frais-fixe-card {
        border-left: 4px solid var(--success-color);
    }
    
    .frais-scolarite-card {
        border-left: 4px solid var(--primary-color);
    }
    
    /* Responsive */
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
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI FINANCES</h5>
                <div class="user-role">Gestionnaire Principal</div>
                <?php if($site_nom): ?>
                <small><?php echo htmlspecialchars($site_nom); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Gestion Financière</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Financier</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="inscriptions.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Inscriptions</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Demandes</span>
                        <?php if ($demandeCount > 0): ?>
                        <span class="nav-badge"><?php echo $demandeCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Paiements & Dettes</div>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Dettes Étudiantes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Financière</div>
                    <a href="tarifs.php" class="nav-link active">
                        <i class="fas fa-tags"></i>
                        <span>Tarifs & Frais</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice"></i>
                        <span>Factures & Reçus</span>
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
                            <i class="fas fa-tags me-2"></i>
                            Gestion des Tarifs et Frais
                        </h2>
                        <p class="text-muted mb-0">
                            Définissez les frais d'inscription, réinscription et scolarité
                        </p>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Frais fixes (Inscription/Réinscription) -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card frais-fixe-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-money-bill-wave me-2"></i>
                                Frais Fixes - Inscription & Réinscription
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">
                                Ces frais sont identiques pour toutes les options et tous les niveaux
                            </p>
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="annee_frais_fixes" class="form-label">Année Académique *</label>
                                            <select class="form-select" id="annee_frais_fixes" name="annee_frais_fixes" required>
                                                <option value="">Sélectionnez une année</option>
                                                <?php foreach($annees_academiques as $annee): ?>
                                                <option value="<?php echo $annee['id']; ?>">
                                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="frais_inscription" class="form-label">Frais d'Inscription (FCFA) *</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="frais_inscription" 
                                                       name="frais_inscription" required min="0" step="1000" 
                                                       placeholder="25000" value="25000">
                                                <span class="input-group-text">FCFA</span>
                                            </div>
                                            <small class="text-muted">Montant pour l'inscription (ex: 25,000 FCFA)</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="frais_reinscription" class="form-label">Frais de Réinscription (FCFA) *</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="frais_reinscription" 
                                                       name="frais_reinscription" required min="0" step="1000" 
                                                       placeholder="20000" value="20000">
                                                <span class="input-group-text">FCFA</span>
                                            </div>
                                            <small class="text-muted">Montant pour la réinscription (ex: 20,000 FCFA)</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <button type="submit" name="action" value="modifier_frais_fixes" 
                                                class="btn btn-success w-100">
                                            <i class="fas fa-save"></i> Enregistrer les Frais Fixes
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Frais de scolarité (par option et niveau) -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card frais-scolarite-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-graduation-cap me-2"></i>
                                Frais de Scolarité
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="annee_academique_id" class="form-label">Année Académique *</label>
                                            <select class="form-select" id="annee_academique_id" name="annee_academique_id" required>
                                                <option value="">Sélectionnez une année</option>
                                                <?php foreach($annees_academiques as $annee): ?>
                                                <option value="<?php echo $annee['id']; ?>">
                                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="option_id" class="form-label">Option *</label>
                                            <select class="form-select" id="option_id" name="option_id" required>
                                                <option value="">Sélectionnez une option</option>
                                                <?php foreach($options as $option): ?>
                                                <option value="<?php echo $option['id']; ?>">
                                                    <?php echo htmlspecialchars($option['nom']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="niveau_id" class="form-label">Niveau *</label>
                                            <select class="form-select" id="niveau_id" name="niveau_id" required>
                                                <option value="">Sélectionnez un niveau</option>
                                                <?php foreach($niveaux as $niveau): ?>
                                                <option value="<?php echo $niveau['id']; ?>">
                                                    <?php echo htmlspecialchars($niveau['libelle'] . ' (' . $niveau['cycle'] . ')'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="montant_scolarite" class="form-label">Montant Annuel (FCFA) *</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="montant_scolarite" 
                                                       name="montant_scolarite" required min="0" step="5000" 
                                                       placeholder="400000" onkeyup="calculerOptionsPaiement()" 
                                                       onchange="calculerOptionsPaiement()">
                                                <span class="input-group-text">FCFA</span>
                                            </div>
                                            <small class="text-muted">Montant total pour l'année (10 mois)</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <button type="submit" name="action" value="ajouter_scolarite" 
                                                class="btn btn-primary w-100 mb-3">
                                            <i class="fas fa-save"></i> Ajouter ce Tarif de Scolarité
                                        </button>
                                        
                                        <button type="button" class="btn btn-success w-100" 
                                                data-bs-toggle="modal" data-bs-target="#modalAjoutMasse">
                                            <i class="fas fa-layer-group"></i> Ajouter en Masse pour cette Option
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Options de paiement -->
                                <div class="calculatrice" id="calculatriceSection">
                                    <h6><i class="fas fa-calculator me-2"></i>Options de Paiement pour la Scolarité</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="paiement-option active" id="optionAnnuel" onclick="selectOption('annuel')">
                                                <div class="text-center">
                                                    <h5 class="text-success">Annuel</h5>
                                                    <div class="fs-4 fw-bold" id="montantAnnuel">-</div>
                                                    <small>1 paiement</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="paiement-option" id="optionSemestriel" onclick="selectOption('semestriel')">
                                                <div class="text-center">
                                                    <h5 class="text-primary">Semestriel</h5>
                                                    <div class="fs-4 fw-bold" id="montantSemestre">-</div>
                                                    <small>2 paiements</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="paiement-option" id="optionTrimestriel" onclick="selectOption('trimestriel')">
                                                <div class="text-center">
                                                    <h5 class="text-warning">Trimestriel</h5>
                                                    <div class="fs-4 fw-bold" id="montantTrimestre">-</div>
                                                    <small>4 paiements</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="paiement-option" id="optionMensuel" onclick="selectOption('mensuel')">
                                                <div class="text-center">
                                                    <h5 class="text-info">Mensuel</h5>
                                                    <div class="fs-4 fw-bold" id="montantMensuel">-</div>
                                                    <small>10 paiements</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Détails des mensualités -->
                                    <div class="mt-4" id="detailsPaiement">
                                        <h6>Détails du paiement : <span id="typePaiementSelectionne">Annuel</span></h6>
                                        <div class="row" id="listeEcheances">
                                            <!-- Les échéances seront affichées ici -->
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Tarifs existants -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                Tarifs Définis
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($tarifs_existants)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun tarif défini pour le moment.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Type de Frais</th>
                                            <th>Option</th>
                                            <th>Niveau</th>
                                            <th>Montant</th>
                                            <th>Année Acad.</th>
                                            <th>Options de Paiement</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_type = '';
                                        foreach($tarifs_existants as $tarif): 
                                            if ($current_type != $tarif['type_frais_nom']):
                                                $current_type = $tarif['type_frais_nom'];
                                                $badge_class = $tarif['type_frais_nom'] == 'Scolarité Mensuelle' ? 'bg-primary' : 'bg-success';
                                        ?>
                                        <tr class="table-secondary">
                                            <td colspan="7" class="fw-bold">
                                                <span class="badge <?php echo $badge_class; ?> me-2">
                                                    <?php echo htmlspecialchars($tarif['type_frais_nom']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <td>
                                                <?php if($tarif['type_frais_nom'] == 'Scolarité Mensuelle'): ?>
                                                <span class="badge bg-primary">
                                                    <?php echo htmlspecialchars($tarif['type_frais_nom']); ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="badge bg-success">
                                                    <?php echo htmlspecialchars($tarif['type_frais_nom']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($tarif['option_nom']): ?>
                                                <?php echo htmlspecialchars($tarif['option_nom']); ?>
                                                <?php else: ?>
                                                <em class="text-muted">Toutes options</em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($tarif['niveau_libelle']): ?>
                                                <?php echo htmlspecialchars($tarif['niveau_libelle']); ?>
                                                <?php else: ?>
                                                <em class="text-muted">Tous niveaux</em>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-success">
                                                <?php echo formatMoney($tarif['montant']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($tarif['annee_libelle']); ?></td>
                                            <td>
                                                <?php if($tarif['type_frais_nom'] == 'Scolarité Mensuelle'): ?>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="voirOptionsPaiement(<?php echo $tarif['montant']; ?>)">
                                                    <i class="fas fa-calculator"></i> Voir options
                                                </button>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalModifier"
                                                            onclick="preparerModification(
                                                                <?php echo $tarif['id']; ?>,
                                                                '<?php echo $tarif['option_nom'] ? addslashes($tarif['option_nom']) : 'Toutes options'; ?>',
                                                                '<?php echo $tarif['niveau_libelle'] ? addslashes($tarif['niveau_libelle']) : 'Tous niveaux'; ?>',
                                                                '<?php echo addslashes($tarif['type_frais_nom']); ?>',
                                                                <?php echo $tarif['montant']; ?>
                                                            )">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" 
                                                            onclick="supprimerTarif(<?php echo $tarif['id']; ?>, 
                                                                   '<?php echo htmlspecialchars(addslashes($tarif['type_frais_nom'] . ' - ' . ($tarif['option_nom'] ?: 'Toutes options') . ' - ' . ($tarif['niveau_libelle'] ?: 'Tous niveaux'))); ?>')">
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
        </div>
    </div>
    
    <!-- Modal pour modification -->
    <div class="modal fade" id="modalModifier" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modifier le Tarif</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Type de Frais</label>
                            <input type="text" class="form-control" id="modal_type" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Option</label>
                            <input type="text" class="form-control" id="modal_option" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Niveau</label>
                            <input type="text" class="form-control" id="modal_niveau" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="montant_modif" class="form-label">Nouveau Montant (FCFA) *</label>
                            <input type="number" class="form-control" id="montant_modif" name="montant_modif" 
                                   required min="0" step="1000">
                        </div>
                        <input type="hidden" id="tarif_id" name="tarif_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="action" value="modifier" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour ajout en masse -->
    <div class="modal fade" id="modalAjoutMasse" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajout de Tarifs en Masse</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Cette action ajoutera le tarif de scolarité pour tous les niveaux de l'option sélectionnée.
                        </div>
                        <div class="mb-3">
                            <label for="option_id_masse" class="form-label">Option *</label>
                            <select class="form-select" id="option_id_masse" name="option_id_masse" required>
                                <option value="">Sélectionnez une option</option>
                                <?php foreach($options as $option): ?>
                                <option value="<?php echo $option['id']; ?>">
                                    <?php echo htmlspecialchars($option['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="annee_academique_id_masse" class="form-label">Année Académique *</label>
                            <select class="form-select" id="annee_academique_id_masse" name="annee_academique_id_masse" required>
                                <option value="">Sélectionnez une année</option>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>">
                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="montant_masse" class="form-label">Montant Annuel (FCFA) *</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="montant_masse" name="montant_masse" 
                                       required min="0" step="5000" placeholder="400000">
                                <span class="input-group-text">FCFA</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="action" value="ajouter_masse" class="btn btn-primary">
                            <i class="fas fa-layer-group"></i> Ajouter en Masse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Configuration
    const MOIS_ANNEE_ACADEMIQUE = 10;
    
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        const button = event.target.closest('button');
        if (button) {
            const icon = button.querySelector('i');
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Calculer initialement les options de paiement
        calculerOptionsPaiement();
    });
    
    // Formater un nombre avec séparateurs de milliers
    function formatNombre(nombre) {
        return new Intl.NumberFormat('fr-FR').format(nombre);
    }
    
    // Calculer toutes les options de paiement
    function calculerOptionsPaiement() {
        const montant = parseFloat(document.getElementById('montant_scolarite').value) || 0;
        
        // Calculer les montants pour chaque option
        const montantAnnuel = montant;
        const montantSemestre = Math.round(montant / 2);
        const montantTrimestre = Math.round(montant / 4);
        const montantMensuel = Math.round(montant / MOIS_ANNEE_ACADEMIQUE);
        
        // Mettre à jour l'affichage
        document.getElementById('montantAnnuel').textContent = formatNombre(montantAnnuel) + ' FCFA';
        document.getElementById('montantSemestre').textContent = formatNombre(montantSemestre) + ' FCFA';
        document.getElementById('montantTrimestre').textContent = formatNombre(montantTrimestre) + ' FCFA';
        document.getElementById('montantMensuel').textContent = formatNombre(montantMensuel) + ' FCFA';
        
        // Afficher les détails de l'option sélectionnée
        const optionActive = document.querySelector('.paiement-option.active').id;
        const typePaiement = optionActive.replace('option', '').toLowerCase();
        afficherDetailsPaiement(typePaiement, montant);
    }
    
    // Sélectionner une option de paiement
    function selectOption(type) {
        // Retirer la classe active de toutes les options
        document.querySelectorAll('.paiement-option').forEach(option => {
            option.classList.remove('active');
        });
        
        // Ajouter la classe active à l'option sélectionnée
        document.getElementById('option' + type.charAt(0).toUpperCase() + type.slice(1)).classList.add('active');
        
        // Afficher les détails
        const montant = parseFloat(document.getElementById('montant_scolarite').value) || 0;
        afficherDetailsPaiement(type, montant);
    }
    
    // Afficher les détails du paiement
    function afficherDetailsPaiement(type, montantTotal) {
        let detailsHTML = '';
        let typeTexte = '';
        
        switch(type) {
            case 'annuel':
                typeTexte = 'Annuel (1 paiement)';
                detailsHTML = `
                    <div class="col-12">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>Paiement unique</span>
                                <strong>${formatNombre(montantTotal)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début d'année académique</small>
                        </div>
                    </div>
                `;
                break;
                
            case 'semestriel':
                typeTexte = 'Semestriel (2 paiements)';
                const montantSemestre = Math.round(montantTotal / 2);
                detailsHTML = `
                    <div class="col-md-6">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>1er semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début de 1er semestre</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>2ème semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début de 2ème semestre</small>
                        </div>
                    </div>
                `;
                break;
                
            case 'trimestriel':
                typeTexte = 'Trimestriel (4 paiements)';
                const montantTrimestre = Math.round(montantTotal / 4);
                const moisParTrimestre = [3, 3, 3, 1]; // 3+3+3+1 = 10 mois
                
                for (let i = 0; i < 4; i++) {
                    detailsHTML += `
                        <div class="col-md-3 col-6">
                            <div class="mensualite-item">
                                <div class="d-flex justify-content-between">
                                    <span>Trimestre ${i+1} (${moisParTrimestre[i]} mois)</span>
                                    <strong>${formatNombre(montantTrimestre)} FCFA</strong>
                                </div>
                                <small class="text-muted">À payer en début de trimestre</small>
                            </div>
                        </div>
                    `;
                }
                break;
                
            case 'mensuel':
                typeTexte = 'Mensuel (10 paiements)';
                const montantMensuel = Math.round(montantTotal / MOIS_ANNEE_ACADEMIQUE);
                
                for (let i = 0; i < MOIS_ANNEE_ACADEMIQUE; i++) {
                    detailsHTML += `
                        <div class="col-md-2 col-4">
                            <div class="mensualite-item">
                                <div class="d-flex justify-content-between">
                                    <span>Mois ${i+1}</span>
                                    <strong>${formatNombre(montantMensuel)} FCFA</strong>
                                </div>
                                <small class="text-muted">Fin du mois</small>
                            </div>
                        </div>
                    `;
                }
                break;
        }
        
        document.getElementById('typePaiementSelectionne').textContent = typeTexte;
        document.getElementById('listeEcheances').innerHTML = detailsHTML;
    }
    
    // Voir les options de paiement pour un tarif existant
    function voirOptionsPaiement(montant) {
        const modalHTML = `
            <div class="modal fade" id="modalOptionsPaiement" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Options de Paiement pour la Scolarité</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Montant annuel: <strong>${formatNombre(montant)} FCFA</strong></label>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="paiement-option active" onclick="selectModalOption('annuel', ${montant})">
                                        <div class="text-center">
                                            <h5 class="text-success">Annuel</h5>
                                            <div class="fs-4 fw-bold">${formatNombre(montant)} FCFA</div>
                                            <small>1 paiement</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="paiement-option" onclick="selectModalOption('semestriel', ${montant})">
                                        <div class="text-center">
                                            <h5 class="text-primary">Semestriel</h5>
                                            <div class="fs-4 fw-bold">${formatNombre(Math.round(montant / 2))} FCFA</div>
                                            <small>2 paiements</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="paiement-option" onclick="selectModalOption('trimestriel', ${montant})">
                                        <div class="text-center">
                                            <h5 class="text-warning">Trimestriel</h5>
                                            <div class="fs-4 fw-bold">${formatNombre(Math.round(montant / 4))} FCFA</div>
                                            <small>4 paiements</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="paiement-option" onclick="selectModalOption('mensuel', ${montant})">
                                        <div class="text-center">
                                            <h5 class="text-info">Mensuel</h5>
                                            <div class="fs-4 fw-bold">${formatNombre(Math.round(montant / ${MOIS_ANNEE_ACADEMIQUE}))} FCFA</div>
                                            <small>10 paiements</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <h6>Détails du paiement : <span id="modalTypePaiement">Annuel</span></h6>
                                <div class="row" id="modalListeEcheances">
                                    <!-- Détails affichés ici -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Ajouter la modal au DOM
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        const modal = new bootstrap.Modal(document.getElementById('modalOptionsPaiement'));
        modal.show();
        
        // Afficher les détails initiaux
        selectModalOption('annuel', montant);
        
        // Nettoyer après fermeture
        document.getElementById('modalOptionsPaiement').addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
    }
    
    // Sélectionner une option dans la modal
    function selectModalOption(type, montant) {
        // Retirer la classe active de toutes les options
        document.querySelectorAll('#modalOptionsPaiement .paiement-option').forEach(option => {
            option.classList.remove('active');
        });
        
        // Ajouter la classe active
        const modalOption = document.querySelector(`#modalOptionsPaiement .paiement-option:nth-child(${getOptionIndex(type)})`);
        if (modalOption) modalOption.classList.add('active');
        
        // Afficher les détails
        let detailsHTML = '';
        let typeTexte = '';
        
        switch(type) {
            case 'annuel':
                typeTexte = 'Annuel (1 paiement)';
                detailsHTML = `
                    <div class="col-12">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>Paiement unique</span>
                                <strong>${formatNombre(montant)} FCFA</strong>
                            </div>
                        </div>
                    </div>
                `;
                break;
                
            case 'semestriel':
                typeTexte = 'Semestriel (2 paiements)';
                const montantSemestre = Math.round(montant / 2);
                detailsHTML = `
                    <div class="col-md-6">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>1er semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mensualite-item">
                            <div class="d-flex justify-content-between">
                                <span>2ème semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                        </div>
                    </div>
                `;
                break;
                
            case 'trimestriel':
                typeTexte = 'Trimestriel (4 paiements)';
                const montantTrimestre = Math.round(montant / 4);
                for (let i = 0; i < 4; i++) {
                    detailsHTML += `
                        <div class="col-md-3 col-6">
                            <div class="mensualite-item">
                                <div class="d-flex justify-content-between">
                                    <span>Trimestre ${i+1}</span>
                                    <strong>${formatNombre(montantTrimestre)} FCFA</strong>
                                </div>
                            </div>
                        </div>
                    `;
                }
                break;
                
            case 'mensuel':
                typeTexte = 'Mensuel (10 paiements)';
                const montantMensuel = Math.round(montant / MOIS_ANNEE_ACADEMIQUE);
                for (let i = 0; i < MOIS_ANNEE_ACADEMIQUE; i++) {
                    detailsHTML += `
                        <div class="col-md-2 col-4">
                            <div class="mensualite-item">
                                <div class="d-flex justify-content-between">
                                    <span>Mois ${i+1}</span>
                                    <strong>${formatNombre(montantMensuel)} FCFA</strong>
                                </div>
                            </div>
                        </div>
                    `;
                }
                break;
        }
        
        const modalTypeElement = document.getElementById('modalTypePaiement');
        const modalListeElement = document.getElementById('modalListeEcheances');
        
        if (modalTypeElement) modalTypeElement.textContent = typeTexte;
        if (modalListeElement) modalListeElement.innerHTML = detailsHTML;
    }
    
    function getOptionIndex(type) {
        switch(type) {
            case 'annuel': return 1;
            case 'semestriel': return 2;
            case 'trimestriel': return 3;
            case 'mensuel': return 4;
            default: return 1;
        }
    }
    
    // Préparer la modification d'un tarif
    function preparerModification(id, option, niveau, type, montant) {
        document.getElementById('modal_type').value = type;
        document.getElementById('modal_option').value = option;
        document.getElementById('modal_niveau').value = niveau;
        document.getElementById('montant_modif').value = montant;
        document.getElementById('tarif_id').value = id;
    }
    
    // Supprimer un tarif
    function supprimerTarif(id, description) {
        if (confirm(`Êtes-vous sûr de vouloir supprimer le tarif : ${description} ?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'action';
            inputAction.value = 'supprimer';
            form.appendChild(inputAction);
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'tarif_id';
            inputId.value = id;
            form.appendChild(inputId);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>