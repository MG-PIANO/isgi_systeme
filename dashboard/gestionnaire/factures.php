<?php
// dashboard/gestionnaire/factures.php

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

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4)
if (!isset($_SESSION['role_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
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
    $pageTitle = "Gestionnaire - Factures & Reçus";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
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
        switch ($statut) {
            case 'valide':
            case 'payee':
                return '<span class="badge bg-success">Payée</span>';
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulée</span>';
            case 'partiel':
                return '<span class="badge bg-info">Partiellement payée</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
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
    
    // Variables pour les filtres
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $statut_filter = isset($_GET['statut']) ? $_GET['statut'] : '';
    $date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    $type_facture = isset($_GET['type_facture']) ? $_GET['type_facture'] : '';
    
    // Construire la requête de base pour les factures
    $query = "SELECT 
                f.*,
                e.matricule,
                e.nom as etudiant_nom,
                e.prenom as etudiant_prenom,
                tf.nom as type_frais_nom,
                aa.libelle as annee_academique,
                s.nom as site_nom,
                CONCAT(u.nom, ' ', u.prenom) as emis_par_nom
              FROM factures f
              JOIN etudiants e ON f.etudiant_id = e.id
              JOIN types_frais tf ON f.type_frais_id = tf.id
              JOIN annees_academiques aa ON f.annee_academique_id = aa.id
              JOIN sites s ON f.site_id = s.id
              LEFT JOIN utilisateurs u ON f.emis_par = u.id
              WHERE 1=1";
    
    $params = [];
    
    if ($site_id) {
        $query .= " AND f.site_id = ?";
        $params[] = $site_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (f.numero_facture LIKE ? OR e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)";
        $searchParam = "%$search%";
        array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
    }
    
    if (!empty($statut_filter)) {
        $query .= " AND f.statut = ?";
        $params[] = $statut_filter;
    }
    
    if (!empty($type_facture)) {
        $query .= " AND f.type_facture = ?";
        $params[] = $type_facture;
    }
    
    if (!empty($date_debut)) {
        $query .= " AND f.date_emission >= ?";
        $params[] = $date_debut;
    }
    
    if (!empty($date_fin)) {
        $query .= " AND f.date_emission <= ?";
        $params[] = $date_fin;
    }
    
    $query .= " ORDER BY f.date_emission DESC, f.numero_facture DESC";
    
    // Exécuter la requête avec PDO
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Compter les factures par statut
    $countQuery = "SELECT statut, COUNT(*) as count FROM factures";
    $countParams = [];
    if ($site_id) {
        $countQuery .= " WHERE site_id = ?";
        $countParams[] = $site_id;
    }
    $countQuery .= " GROUP BY statut";
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($countParams);
    $counts = [];
    while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['statut']] = $row['count'];
    }
    
    // Récupérer les types de facture disponibles
    $typeQuery = "SELECT DISTINCT type_facture FROM factures";
    $typeParams = [];
    if ($site_id) {
        $typeQuery .= " WHERE site_id = ?";
        $typeParams[] = $site_id;
    }
    $typeStmt = $db->prepare($typeQuery);
    $typeStmt->execute($typeParams);
    $types_facture = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer le nom du site si assigné
    $site_nom = '';
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log($error);
    $factures = [];
    $counts = [];
    $types_facture = [];
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
    
    /* Sidebar - Copié du dashboard */
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
    
    /* Navigation */
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
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .filter-group {
        flex: 1;
        min-width: 200px;
    }
    
    .filter-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--text-color);
    }
    
    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border-color);
        border-radius: 5px;
        background-color: var(--card-bg);
        color: var(--text-color);
    }
    
    /* Boutons d'action */
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .action-buttons .btn {
        flex: 1;
        min-width: 150px;
    }
    
    /* Badges spécifiques pour factures */
    .badge-facture {
        font-size: 0.75em;
        padding: 4px 8px;
        border-radius: 4px;
    }
    
    .badge-facture-payee {
        background-color: var(--success-color);
        color: white;
    }
    
    .badge-facture-en-attente {
        background-color: var(--warning-color);
        color: white;
    }
    
    .badge-facture-annule {
        background-color: var(--accent-color);
        color: white;
    }
    
    .badge-facture-partiel {
        background-color: var(--info-color);
        color: white;
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
        
        .filter-row {
            flex-direction: column;
        }
        
        .filter-group {
            min-width: 100%;
        }
    }
    
    /* Styles pour l'impression */
    @media print {
        .sidebar, .btn, .filter-card, .action-buttons {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0;
            padding: 0;
        }
        
        .card {
            box-shadow: none;
            border: none;
        }
        
        .table {
            border: 1px solid #000;
        }
        
        .table th, .table td {
            border: 1px solid #000;
        }
    }
    
    /* Statistiques */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
    }
    
    .stat-value {
        font-size: 1.8rem;
        font-weight: bold;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
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
                <div class="user-role">Gestionnaire</div>
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
                    <a href="tarifs.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        <span>Tarifs & Frais</span>
                    </a>
                    <a href="factures.php" class="nav-link active">
                        <i class="fas fa-file-invoice"></i>
                        <span>Factures & Reçus</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Administration</div>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier</span>
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
                            <i class="fas fa-file-invoice me-2"></i>
                            Gestion des Factures & Reçus
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="action-buttons">
                        <a href="generer_facture.php" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Nouvelle Facture
                        </a>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <a href="export_factures.php" class="btn btn-info">
                            <i class="fas fa-file-excel"></i> Exporter
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo isset($counts['payee']) ? $counts['payee'] : 0; ?></div>
                    <div class="stat-label">Factures Payées</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo isset($counts['en_attente']) ? $counts['en_attente'] : 0; ?></div>
                    <div class="stat-label">En Attente</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo isset($counts['annule']) ? $counts['annule'] : 0; ?></div>
                    <div class="stat-label">Annulées</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo isset($counts['partiel']) ? $counts['partiel'] : 0; ?></div>
                    <div class="stat-label">Partielles</div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="filter-card">
                <h5><i class="fas fa-filter me-2"></i>Filtres</h5>
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Recherche</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Numéro, matricule, nom...">
                        </div>
                        <div class="filter-group">
                            <label for="statut">Statut</label>
                            <select id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="payee" <?php echo $statut_filter == 'payee' ? 'selected' : ''; ?>>Payée</option>
                                <option value="en_attente" <?php echo $statut_filter == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="partiel" <?php echo $statut_filter == 'partiel' ? 'selected' : ''; ?>>Partielle</option>
                                <option value="annule" <?php echo $statut_filter == 'annule' ? 'selected' : ''; ?>>Annulée</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="type_facture">Type de facture</label>
                            <select id="type_facture" name="type_facture">
                                <option value="">Tous les types</option>
                                <?php foreach($types_facture as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['type_facture']); ?>"
                                        <?php echo $type_facture == $type['type_facture'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type_facture']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="date_debut">Date début</label>
                            <input type="date" id="date_debut" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="date_fin">Date fin</label>
                            <input type="date" id="date_fin" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>">
                        </div>
                        <div class="filter-group d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtrer
                            </button>
                            <a href="factures.php" class="btn btn-secondary ms-2">
                                <i class="fas fa-times"></i> Réinitialiser
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Tableau des factures -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Factures
                    </h5>
                    <span class="text-muted"><?php echo count($factures); ?> facture(s)</span>
                </div>
                <div class="card-body">
                    <?php if(empty($factures)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucune facture trouvée
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="facturesTable">
                            <thead>
                                <tr>
                                    <th>Numéro</th>
                                    <th>Date</th>
                                    <th>Étudiant</th>
                                    <th>Type</th>
                                    <th>Montant</th>
                                    <th>Payé</th>
                                    <th>Reste</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($factures as $facture): 
                                    $montant_restant = $facture['montant_total'] - $facture['montant_paye'];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($facture['numero_facture']); ?></strong>
                                    </td>
                                    <td><?php echo formatDateFr($facture['date_emission']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($facture['etudiant_nom'] . ' ' . $facture['etudiant_prenom']); ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($facture['matricule']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($facture['type_frais_nom']); ?></td>
                                    <td class="fw-bold"><?php echo formatMoney($facture['montant_total']); ?></td>
                                    <td class="text-success"><?php echo formatMoney($facture['montant_paye']); ?></td>
                                    <td class="<?php echo $montant_restant > 0 ? 'text-danger' : 'text-success'; ?> fw-bold">
                                        <?php echo formatMoney($montant_restant); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = '';
                                        switch($facture['statut']) {
                                            case 'payee':
                                                $badge_class = 'badge-facture-payee';
                                                break;
                                            case 'en_attente':
                                                $badge_class = 'badge-facture-en-attente';
                                                break;
                                            case 'annule':
                                                $badge_class = 'badge-facture-annule';
                                                break;
                                            case 'partiel':
                                                $badge_class = 'badge-facture-partiel';
                                                break;
                                            default:
                                                $badge_class = 'badge bg-secondary';
                                        }
                                        ?>
                                        <span class="badge-facture <?php echo $badge_class; ?>">
                                            <?php 
                                            if($facture['statut'] == 'payee') echo 'Payée';
                                            elseif($facture['statut'] == 'en_attente') echo 'En attente';
                                            elseif($facture['statut'] == 'annule') echo 'Annulée';
                                            elseif($facture['statut'] == 'partiel') echo 'Partielle';
                                            else echo htmlspecialchars($facture['statut']);
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="voir_facture.php?id=<?php echo $facture['id']; ?>" 
                                               class="btn btn-info" title="Voir facture" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="generer_recu.php?facture_id=<?php echo $facture['id']; ?>" 
                                               class="btn btn-success" title="Générer reçu">
                                                <i class="fas fa-receipt"></i>
                                            </a>
                                            <a href="imprimer_facture.php?id=<?php echo $facture['id']; ?>" 
                                               class="btn btn-warning" title="Imprimer" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <?php if($facture['statut'] != 'payee' && $facture['statut'] != 'annule'): ?>
                                            <a href="modifier_facture.php?id=<?php echo $facture['id']; ?>" 
                                               class="btn btn-primary" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
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
    
    <!-- Scripts JavaScript -->
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
        
        // Mettre à jour l'attribut
        html.setAttribute('data-theme', newTheme);
        
        // Sauvegarder dans un cookie (30 jours)
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        // Mettre à jour le bouton
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
    
    // Initialiser DataTables
    $(document).ready(function() {
        $('#facturesTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        
        // Initialiser le thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Mettre à jour le bouton du thème
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    });
    
    // Fonction pour confirmer la suppression
    function confirmerSuppression(id, numero) {
        if (confirm(`Êtes-vous sûr de vouloir supprimer la facture ${numero} ?`)) {
            window.location.href = `supprimer_facture.php?id=${id}`;
        }
    }
    </script>
</body>
</html>