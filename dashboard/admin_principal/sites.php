<?php
// dashboard/admin_principal/sites.php

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
    $pageTitle = "Administrateur Principal - Gestion des Sites";
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'actif':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
                return '<span class="badge bg-danger">Inactif</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $sites = array();
    
    // Traitement des actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'ajouter':
                    $nom = trim($_POST['nom']);
                    $ville = trim($_POST['ville']);
                    $adresse = trim($_POST['adresse']);
                    $telephone = trim($_POST['telephone']);
                    $email = trim($_POST['email']);
                    
                    if (!empty($nom) && !empty($ville)) {
                        $sql = "INSERT INTO sites (nom, ville, adresse, telephone, email, statut) 
                                VALUES (?, ?, ?, ?, ?, 'actif')";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([$nom, $ville, $adresse, $telephone, $email])) {
                            $success = "Site ajouté avec succès!";
                        } else {
                            $error = "Erreur lors de l'ajout du site.";
                        }
                    }
                    break;
                    
                case 'modifier':
                    $id = intval($_POST['id']);
                    $nom = trim($_POST['nom']);
                    $ville = trim($_POST['ville']);
                    $adresse = trim($_POST['adresse']);
                    $telephone = trim($_POST['telephone']);
                    $email = trim($_POST['email']);
                    $statut = $_POST['statut'];
                    
                    if ($id > 0 && !empty($nom) && !empty($ville)) {
                        $sql = "UPDATE sites SET nom = ?, ville = ?, adresse = ?, telephone = ?, 
                                email = ?, statut = ? WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([$nom, $ville, $adresse, $telephone, $email, $statut, $id])) {
                            $success = "Site modifié avec succès!";
                        } else {
                            $error = "Erreur lors de la modification du site.";
                        }
                    }
                    break;
                    
                case 'supprimer':
                    $id = intval($_POST['id']);
                    
                    if ($id > 0) {
                        // Vérifier si le site a des données associées
                        $check = $db->prepare("SELECT COUNT(*) FROM etudiants WHERE site_id = ?");
                        $check->execute([$id]);
                        $count = $check->fetchColumn();
                        
                        if ($count == 0) {
                            $sql = "DELETE FROM sites WHERE id = ?";
                            $stmt = $db->prepare($sql);
                            if ($stmt->execute([$id])) {
                                $success = "Site supprimé avec succès!";
                            } else {
                                $error = "Erreur lors de la suppression du site.";
                            }
                        } else {
                            $error = "Impossible de supprimer ce site car il contient des étudiants. Désactivez-le à la place.";
                        }
                    }
                    break;
            }
        }
    }
    
    // Récupérer la liste des sites avec statistiques
    $query = "SELECT s.*, 
              (SELECT COUNT(*) FROM etudiants e WHERE e.site_id = s.id AND e.statut = 'actif') as nb_etudiants,
              (SELECT COUNT(*) FROM enseignants en WHERE en.site_id = s.id AND en.statut = 'actif') as nb_professeurs,
              (SELECT COUNT(*) FROM administrateurs a WHERE a.site_id = s.id) as nb_administrateurs
              FROM sites s 
              ORDER BY s.ville, s.nom";
    
    $sites = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    .site-card {
        transition: transform 0.2s;
    }
    
    .site-card:hover {
        transform: translateY(-2px);
    }
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
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
                <small>Gestion des Sites</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="sites.php" class="nav-link active">
                        <i class="fas fa-building"></i>
                        <span>Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
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
                            <i class="fas fa-building me-2"></i>
                            Gestion des Sites
                        </h2>
                        <p class="text-muted mb-0">Gérez tous les sites de l'ISGI</p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ajouterSiteModal">
                        <i class="fas fa-plus me-2"></i>Ajouter un Site
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
            
            <!-- Statistiques des sites -->
            <div class="row mb-4">
                <?php 
                $total_etudiants = 0;
                $total_professeurs = 0;
                foreach($sites as $site) {
                    $total_etudiants += $site['nb_etudiants'];
                    $total_professeurs += $site['nb_professeurs'];
                }
                ?>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <h3><?php echo count($sites); ?></h3>
                            <p class="text-muted mb-0">Sites Actifs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h3><?php echo $total_etudiants; ?></h3>
                            <p class="text-muted mb-0">Étudiants Total</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <h3><?php echo $total_professeurs; ?></h3>
                            <p class="text-muted mb-0">Professeurs Total</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des sites -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Sites
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($sites)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun site trouvé
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="sitesTable">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Ville</th>
                                    <th>Téléphone</th>
                                    <th>Statistiques</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sites as $site): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($site['nom']); ?></strong>
                                        <?php if($site['email']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($site['email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($site['ville']); ?></td>
                                    <td><?php echo htmlspecialchars($site['telephone'] ?? 'Non renseigné'); ?></td>
                                    <td>
                                        <span class="badge bg-info">Étudiants: <?php echo $site['nb_etudiants']; ?></span>
                                        <span class="badge bg-warning">Professeurs: <?php echo $site['nb_professeurs']; ?></span>
                                        <span class="badge bg-secondary">Admins: <?php echo $site['nb_administrateurs']; ?></span>
                                    </td>
                                    <td><?php echo getStatutBadge($site['statut']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#modifierSiteModal"
                                                    onclick="chargerSite(<?php echo htmlspecialchars(json_encode($site)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#supprimerSiteModal"
                                                    onclick="preparerSuppression(<?php echo $site['id']; ?>, '<?php echo htmlspecialchars(addslashes($site['nom'])); ?>')">
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
    
    <!-- Modal Ajouter Site -->
    <div class="modal fade" id="ajouterSiteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Ajouter un Nouveau Site</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="ajouter">
                        
                        <div class="mb-3">
                            <label for="nom" class="form-label">Nom du Site *</label>
                            <input type="text" class="form-control" id="nom" name="nom" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="ville" class="form-label">Ville *</label>
                            <input type="text" class="form-control" id="ville" name="ville" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse</label>
                            <textarea class="form-control" id="adresse" name="adresse" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="telephone" name="telephone">
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter le Site</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Modifier Site -->
    <div class="modal fade" id="modifierSiteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifier le Site</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" id="edit_id" name="id">
                        
                        <div class="mb-3">
                            <label for="edit_nom" class="form-label">Nom du Site *</label>
                            <input type="text" class="form-control" id="edit_nom" name="nom" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_ville" class="form-label">Ville *</label>
                            <input type="text" class="form-control" id="edit_ville" name="ville" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_adresse" class="form-label">Adresse</label>
                            <textarea class="form-control" id="edit_adresse" name="adresse" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_telephone" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="edit_telephone" name="telephone">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_statut" class="form-label">Statut</label>
                            <select class="form-select" id="edit_statut" name="statut" required>
                                <option value="actif">Actif</option>
                                <option value="inactif">Inactif</option>
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
    
    <!-- Modal Supprimer Site -->
    <div class="modal fade" id="supprimerSiteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Supprimer le Site</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" id="delete_id" name="id">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p class="mb-0" id="delete_message"></p>
                        </div>
                        
                        <p>Êtes-vous sûr de vouloir supprimer ce site ? Cette action est irréversible.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer Définitivement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
    // Initialiser DataTable
    document.addEventListener('DOMContentLoaded', function() {
        $('#sitesTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']]
        });
    });
    
    // Charger les données d'un site pour modification
    function chargerSite(site) {
        document.getElementById('edit_id').value = site.id;
        document.getElementById('edit_nom').value = site.nom;
        document.getElementById('edit_ville').value = site.ville;
        document.getElementById('edit_adresse').value = site.adresse || '';
        document.getElementById('edit_telephone').value = site.telephone || '';
        document.getElementById('edit_email').value = site.email || '';
        document.getElementById('edit_statut').value = site.statut;
    }
    
    // Préparer la suppression d'un site
    function preparerSuppression(id, nom) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_message').innerHTML = 
            'Vous êtes sur le point de supprimer le site: <strong>' + nom + '</strong>';
    }
    </script>
</body>
</html>