<?php
// dashboard/gestionnaire_principal/etudiants.php

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
    $pageTitle = "Gestionnaire Principal - Gestion des Étudiants";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function getSexeBadge($sexe) {
        if ($sexe === 'M') {
            return '<span class="badge bg-primary">Homme</span>';
        } else {
            return '<span class="badge bg-pink">Femme</span>';
        }
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
                return '<span class="badge bg-secondary">Inactif</span>';
            case 'diplome':
                return '<span class="badge bg-info">Diplômé</span>';
            case 'abandonne':
                return '<span class="badge bg-danger">Abandonné</span>';
            default:
                return '<span class="badge bg-warning">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getDetteBadge($montant) {
        if ($montant > 50000) {
            return '<span class="badge bg-danger">Haute</span>';
        } elseif ($montant > 20000) {
            return '<span class="badge bg-warning">Moyenne</span>';
        } elseif ($montant > 0) {
            return '<span class="badge bg-info">Faible</span>';
        } else {
            return '<span class="badge bg-success">Aucune</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $etudiants = array();
    $sites = array();
    $filieres = array();
    $site_nom = '';
    
    // Récupérer le nom du site si assigné
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
    // Récupérer les sites (gestionnaire peut voir tous les sites ou seulement le sien)
    if ($site_id) {
        $query = "SELECT * FROM sites WHERE id = ? AND statut = 'actif' ORDER BY ville";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Récupérer les filières
    $filieres = $db->query("SELECT * FROM filieres ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_recherche = isset($_GET['recherche']) ? trim($_GET['recherche']) : '';
    $filtre_dette = isset($_GET['dette']) ? $_GET['dette'] : '';
    
    // Requête pour récupérer les étudiants avec leurs dettes et paiements
    $query = "SELECT e.*, 
              s.nom as site_nom, s.ville as site_ville,
              COALESCE(SUM(d.montant_restant), 0) as dette_totale,
              COALESCE(SUM(p.montant), 0) as total_paye,
              COUNT(DISTINCT p.id) as nb_paiements,
              (SELECT COUNT(*) FROM bulletins b WHERE b.etudiant_id = e.id) as nb_bulletins,
              (SELECT MAX(date_paiement) FROM paiements p2 WHERE p2.etudiant_id = e.id AND p2.statut = 'valide') as dernier_paiement
              FROM etudiants e
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN dettes d ON e.id = d.etudiant_id AND (d.statut = 'en_cours' OR d.statut = 'en_retard')
              LEFT JOIN paiements p ON e.id = p.etudiant_id AND p.statut = 'valide'
              WHERE 1=1";
    
    $params = array();
    
    // Filtrer par site du gestionnaire
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $params[] = $site_id;
    } elseif ($filtre_site > 0) {
        $query .= " AND e.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if (!empty($filtre_statut)) {
        $query .= " AND e.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if (!empty($filtre_recherche)) {
        $query .= " AND (e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ? OR e.numero_cni LIKE ?)";
        $search_param = "%{$filtre_recherche}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Filtrer par dette
    if ($filtre_dette == 'avec') {
        $query .= " HAVING dette_totale > 0";
    } elseif ($filtre_dette == 'sans') {
        $query .= " HAVING dette_totale = 0 OR dette_totale IS NULL";
    } elseif ($filtre_dette == 'retard') {
        $query .= " HAVING dette_totale > 0 AND EXISTS (SELECT 1 FROM dettes d2 WHERE d2.etudiant_id = e.id AND d2.statut = 'en_retard')";
    }
    
    $query .= " GROUP BY e.id ORDER BY e.date_inscription DESC";
    
    // Exécuter la requête
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur etudiants.php: " . $e->getMessage());
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
    
    /* Sidebar (identique au dashboard gestionnaire) */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        transition: background-color 0.3s ease;
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
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .student-avatar {
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
    
    .age-badge {
        font-size: 0.75em;
        padding: 2px 6px;
    }
    
    .montant-dette {
        font-weight: bold;
    }
    
    .montant-dette.high {
        color: var(--accent-color) !important;
    }
    
    .montant-dette.medium {
        color: var(--warning-color) !important;
    }
    
    .montant-dette.low {
        color: var(--info-color) !important;
    }
    
    .montant-dette.none {
        color: var(--success-color) !important;
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
    
    /* Action rapides */
    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    
    .quick-actions .btn {
        flex: 1;
        min-width: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
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
        
        .quick-actions .btn {
            min-width: 100px;
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
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Gestionnaire'); ?></p>
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
                    <a href="etudiants.php" class="nav-link active">
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
                    <a href="paiements_en_ligne.php" class="nav-link">
                        <i class="fas fa-globe"></i>
                        <span>Paiements en Ligne</span>
                    </a>
                    <a href="paiements_presentiel.php" class="nav-link">
                        <i class="fas fa-store"></i>
                        <span>Paiements Présentiel</span>
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
                            <i class="fas fa-user-graduate me-2"></i>
                            Gestion des Étudiants
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="quick-actions">
                        <a href="nouveau_paiement.php" class="btn btn-success">
                            <i class="fas fa-money-bill-wave"></i> Nouveau Paiement
                        </a>
                        <a href="generer_facture.php" class="btn btn-info">
                            <i class="fas fa-file-invoice"></i> Générer Facture
                        </a>
                        <a href="export_etudiants.php" class="btn btn-warning">
                            <i class="fas fa-file-excel"></i> Exporter Excel
                        </a>
                        <button class="btn btn-secondary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
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
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
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
                        <?php if(!$site_id): ?>
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
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label for="statut" class="form-label">Statut Étudiant</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="actif" <?php echo $filtre_statut == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                <option value="inactif" <?php echo $filtre_statut == 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                <option value="diplome" <?php echo $filtre_statut == 'diplome' ? 'selected' : ''; ?>>Diplômé</option>
                                <option value="abandonne" <?php echo $filtre_statut == 'abandonne' ? 'selected' : ''; ?>>Abandonné</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="dette" class="form-label">Situation Dette</label>
                            <select class="form-select" id="dette" name="dette">
                                <option value="">Toutes situations</option>
                                <option value="avec" <?php echo $filtre_dette == 'avec' ? 'selected' : ''; ?>>Avec dette</option>
                                <option value="sans" <?php echo $filtre_dette == 'sans' ? 'selected' : ''; ?>>Sans dette</option>
                                <option value="retard" <?php echo $filtre_dette == 'retard' ? 'selected' : ''; ?>>En retard de paiement</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="recherche" class="form-label">Recherche</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo htmlspecialchars($filtre_recherche); ?>" 
                                       placeholder="Matricule, Nom, Prénom...">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || !empty($filtre_recherche) || !empty($filtre_dette)): ?>
                                <a href="etudiants.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques des étudiants -->
            <?php 
            $total_actifs = 0;
            $total_diplomes = 0;
            $total_avec_dettes = 0;
            $total_retard = 0;
            $total_hommes = 0;
            $total_femmes = 0;
            $montant_dettes_total = 0;
            $montant_paye_total = 0;
            
            foreach($etudiants as $etudiant) {
                if($etudiant['statut'] == 'actif') $total_actifs++;
                if($etudiant['statut'] == 'diplome') $total_diplomes++;
                if($etudiant['dette_totale'] > 0) $total_avec_dettes++;
                if($etudiant['dette_totale'] > 0) {
                    $montant_dettes_total += $etudiant['dette_totale'];
                    // Vérifier si en retard (simplifié)
                    if($etudiant['dernier_paiement']) {
                        $dernier_paiement = new DateTime($etudiant['dernier_paiement']);
                        $aujourdhui = new DateTime();
                        $diff = $aujourdhui->diff($dernier_paiement)->days;
                        if($diff > 30) $total_retard++;
                    }
                }
                $montant_paye_total += $etudiant['total_paye'];
                if($etudiant['sexe'] == 'M') $total_hommes++;
                if($etudiant['sexe'] == 'F') $total_femmes++;
            }
            ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h3><?php echo count($etudiants); ?></h3>
                            <p class="text-muted mb-0">Étudiants</p>
                            <small><?php echo $total_actifs; ?> actifs</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-danger stats-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3><?php echo formatMoney($montant_dettes_total); ?></h3>
                            <p class="text-muted mb-0">Total Dettes</p>
                            <small><?php echo $total_avec_dettes; ?> étudiants</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <h3><?php echo formatMoney($montant_paye_total); ?></h3>
                            <p class="text-muted mb-0">Total Payé</p>
                            <small><?php echo array_sum(array_column($etudiants, 'nb_paiements')); ?> paiements</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h3><?php echo $total_retard; ?></h3>
                            <p class="text-muted mb-0">En Retard</p>
                            <small>Plus de 30 jours</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des étudiants -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Étudiants avec Situation Financière
                    </h5>
                    <div class="text-muted">
                        <?php echo count($etudiants); ?> étudiant(s) trouvé(s)
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($etudiants)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun étudiant trouvé avec les critères sélectionnés
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Informations</th>
                                    <th>Situation Financière</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($etudiants as $etudiant): 
                                // Calculer l'âge
                                $age = 'N/A';
                                if($etudiant['date_naissance'] && $etudiant['date_naissance'] != '0000-00-00') {
                                    $naissance = new DateTime($etudiant['date_naissance']);
                                    $age = $naissance->diff(new DateTime())->y;
                                }
                                
                                // Déterminer la classe de dette
                                $dette_class = 'none';
                                if ($etudiant['dette_totale'] > 50000) {
                                    $dette_class = 'high';
                                } elseif ($etudiant['dette_totale'] > 20000) {
                                    $dette_class = 'medium';
                                } elseif ($etudiant['dette_totale'] > 0) {
                                    $dette_class = 'low';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="student-avatar me-3">
                                                <?php 
                                                $initials = '';
                                                if(!empty($etudiant['prenom']) && !empty($etudiant['nom'])) {
                                                    $initials = strtoupper(substr($etudiant['prenom'], 0, 1) . substr($etudiant['nom'], 0, 1));
                                                } else {
                                                    $initials = '??';
                                                }
                                                echo $initials;
                                                ?>
                                            </div>
                                            <div>
                                                <strong>
                                                    <?php 
                                                    if(!empty($etudiant['prenom']) && !empty($etudiant['nom'])) {
                                                        echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']);
                                                    } else {
                                                        echo 'Nom inconnu';
                                                    }
                                                    ?>
                                                </strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($etudiant['matricule'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="mt-1">
                                                    <?php echo getSexeBadge($etudiant['sexe'] ?? 'M'); ?>
                                                    <?php if($age != 'N/A'): ?>
                                                    <span class="badge bg-secondary age-badge"><?php echo $age; ?> ans</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong>Site:</strong> <?php echo htmlspecialchars($etudiant['site_nom'] ?? 'N/A'); ?></div>
                                            <div><strong>CNI:</strong> <?php echo htmlspecialchars($etudiant['numero_cni'] ?? 'Non renseigné'); ?></div>
                                            <div><strong>Tél Parent:</strong> <?php echo htmlspecialchars($etudiant['telephone_parent'] ?? 'N/A'); ?></div>
                                            <div><strong>Inscrit le:</strong> <?php echo $etudiant['date_inscription'] ? date('d/m/Y', strtotime($etudiant['date_inscription'])) : 'N/A'; ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div>
                                                <strong>Dette:</strong> 
                                                <span class="montant-dette <?php echo $dette_class; ?>">
                                                    <?php echo formatMoney($etudiant['dette_totale']); ?>
                                                </span>
                                                <?php echo getDetteBadge($etudiant['dette_totale']); ?>
                                            </div>
                                            <div>
                                                <strong>Total payé:</strong> 
                                                <span class="text-success">
                                                    <?php echo formatMoney($etudiant['total_paye']); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <strong>Paiements:</strong> 
                                                <span class="badge bg-info"><?php echo $etudiant['nb_paiements']; ?> fois</span>
                                            </div>
                                            <?php if($etudiant['dernier_paiement']): ?>
                                            <div>
                                                <strong>Dernier paiement:</strong> 
                                                <?php echo date('d/m/Y', strtotime($etudiant['dernier_paiement'])); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if($etudiant['nb_bulletins'] > 0): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-secondary">
                                                    <i class="fas fa-file-alt"></i> <?php echo $etudiant['nb_bulletins']; ?> bulletin(s)
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($etudiant['statut']); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="nouveau_paiement.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-success" title="Nouveau paiement">
                                                <i class="fas fa-money-bill"></i>
                                            </a>
                                            <a href="dette_details.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-info" title="Détails dette">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                            <a href="historique_paiements.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-warning" title="Historique">
                                                <i class="fas fa-history"></i>
                                            </a>
                                            <a href="contacter.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-primary" title="Contacter">
                                                <i class="fas fa-envelope"></i>
                                            </a>
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
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
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
        $('#studentsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [4] }
            ]
        });
    });
    
    // Fonction pour envoyer un rappel de paiement
    function envoyerRappel(id, nom) {
        if(confirm('Envoyer un rappel de paiement à ' + nom + ' ?')) {
            // Envoyer une requête AJAX
            $.ajax({
                url: 'envoyer_rappel.php',
                method: 'POST',
                data: { etudiant_id: id },
                success: function(response) {
                    alert('Rappel envoyé avec succès !');
                },
                error: function() {
                    alert('Erreur lors de l\'envoi du rappel.');
                }
            });
        }
    }
    
    // Fonction pour générer un reçu
    function genererRecu(id) {
        window.open('generer_recu.php?etudiant_id=' + id, '_blank');
    }
    
    // Fonction pour vérifier le solde
    function verifierSolde(id) {
        window.location.href = 'solde_etudiant.php?id=' + id;
    }
    </script>
</body>
</html>