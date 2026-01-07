<?php
require_once 'includes/header.php';

$pageTitle = "Administrateur Site";
$db = Database::getInstance();

// Récupérer le site de l'utilisateur
$siteId = SessionManager::getSiteId();

if (!$siteId) {
    header('Location: login.php');
    exit();
}

// Récupérer les infos du site
$query = "SELECT * FROM sites WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$siteId]);
$site = $stmt->fetch();

// Statistiques du site spécifique
$stats = [];

// Total étudiants du site
$query = "SELECT COUNT(*) as total FROM etudiants 
          WHERE site_id = ? AND statut = 'actif'";
$stmt = $db->prepare($query);
$stmt->execute([$siteId]);
$stats['total_etudiants'] = $stmt->fetch()['total'];

// Total professeurs du site
$query = "SELECT COUNT(*) as total FROM enseignants 
          WHERE site_id = ? AND statut = 'actif'";
$stmt = $db->prepare($query);
$stmt->execute([$siteId]);
$stats['total_professeurs'] = $stmt->fetch()['total'];

// Demandes d'inscription du site
$query = "SELECT COUNT(*) as total FROM demande_inscriptions 
          WHERE site_id = ? AND statut = 'en_attente'";
$stmt = $db->prepare($query);
$stmt->execute([$siteId]);
$stats['demandes_attente'] = $stmt->fetch()['total'];

// Recettes du mois du site
$query = "SELECT SUM(p.montant) as total FROM paiements p
          JOIN etudiants e ON p.etudiant_id = e.id
          WHERE e.site_id = ? 
          AND MONTH(p.date_paiement) = MONTH(CURRENT_DATE())
          AND YEAR(p.date_paiement) = YEAR(CURRENT_DATE())
          AND p.statut = 'valide'";
$stmt = $db->prepare($query);
$stmt->execute([$siteId]);
$stats['recettes_mois'] = $stmt->fetch()['total'] ?? 0;
?>

<!-- Sidebar spécifique à l'admin site -->
<div class="sidebar col-md-2">
    <div class="text-center mb-4">
        <div class="logo-placeholder mb-2">
            <i class="fas fa-building fa-3x" style="color: var(--secondary-color);"></i>
        </div>
        <h5><?php echo htmlspecialchars($site['nom']); ?></h5>
        <div class="badge bg-info mt-2">Administrateur Site</div>
        <p class="small mt-2"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin Site'); ?></p>
        
        <!-- Bouton Mode Sombre/Clair -->
        <div class="mt-3">
            <button class="btn btn-sm btn-outline-light w-100" onclick="toggleTheme()">
                <i class="fas fa-moon"></i> Mode Sombre
            </button>
        </div>
    </div>
    
    <nav class="nav flex-column">
        <div class="menu-section">
            <h6>Tableau de Bord</h6>
            <a href="dashboard_admin_site.php" class="active">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard Site
            </a>
        </div>
        
        <div class="menu-section">
            <h6>Gestion du Site</h6>
            <a href="site_utilisateurs.php">
                <i class="fas fa-users me-2"></i>Utilisateurs du Site
            </a>
            <a href="site_demandes.php">
                <i class="fas fa-user-plus me-2"></i>Demandes Site
                <?php if($stats['demandes_attente'] > 0): ?>
                <span class="badge bg-danger float-end"><?php echo $stats['demandes_attente']; ?></span>
                <?php endif; ?>
            </a>
            <a href="site_salles.php">
                <i class="fas fa-door-open me-2"></i>Salles de Classe
            </a>
        </div>
        
        <!-- ... autres menus spécifiques au site ... -->
    </nav>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-building me-2"></i>
            <?php echo htmlspecialchars($site['nom']); ?> - Tableau de Bord
        </h2>
        <div class="btn-group">
            <button class="btn btn-outline-primary" onclick="genererRapportSite()">
                <i class="fas fa-file-pdf"></i> Rapport Site
            </button>
        </div>
    </div>
    
    <!-- Cartes de statistiques du site -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-user-graduate fa-2x text-success mb-2"></i>
                <h3><?php echo $stats['total_etudiants']; ?></h3>
                <p class="text-muted">Étudiants Actifs</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-chalkboard-teacher fa-2x text-warning mb-2"></i>
                <h3><?php echo $stats['total_professeurs']; ?></h3>
                <p class="text-muted">Professeurs</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-money-bill-wave fa-2x text-info mb-2"></i>
                <h3><?php echo number_format($stats['recettes_mois'], 0, ',', ' ') . ' FCFA'; ?></h3>
                <p class="text-muted">Recettes Mois</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-clock fa-2x text-primary mb-2"></i>
                <h3>98%</h3>
                <p class="text-muted">Taux de Présence</p>
            </div>
        </div>
    </div>
    
    <!-- Contenu spécifique au site -->
    <!-- ... -->
</div>

<!-- Scripts similaires avec ajout de fonctionnalités spécifiques au site -->
<script>
// Générer un rapport du site
function genererRapportSite() {
    const siteId = <?php echo $siteId; ?>;
    window.open(`generate_rapport_site.php?site_id=${siteId}`, '_blank');
}

// Autres fonctions spécifiques...
</script>

<?php require_once 'includes/footer.php'; ?>