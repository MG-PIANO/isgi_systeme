<?php
require_once 'includes/header.php';

$pageTitle = "Administrateur Principal";
$db = Database::getInstance();

// Statistiques globales
$stats = [];

// 1. Total étudiants par site
$query = "SELECT s.nom as site, COUNT(e.id) as total_etudiants
          FROM sites s 
          LEFT JOIN etudiants e ON s.id = e.site_id AND e.statut = 'actif'
          WHERE s.statut = 'actif'
          GROUP BY s.id";
$stmt = $db->query($query);
$stats['etudiants_par_site'] = $stmt->fetchAll();

// 2. Total professeurs par site
$query = "SELECT s.nom as site, COUNT(ens.id) as total_professeurs
          FROM sites s 
          LEFT JOIN enseignants ens ON s.id = ens.site_id AND ens.statut = 'actif'
          WHERE s.statut = 'actif'
          GROUP BY s.id";
$stmt = $db->query($query);
$stats['professeurs_par_site'] = $stmt->fetchAll();

// 3. Inscriptions par rentrée
$query = "SELECT type_rentree, COUNT(*) as total, 
          DATE_FORMAT(date_demande, '%Y-%m') as mois
          FROM demande_inscriptions 
          WHERE statut = 'validee'
          GROUP BY type_rentree, mois 
          ORDER BY mois DESC
          LIMIT 6";
$stmt = $db->query($query);
$stats['inscriptions_par_rentree'] = $stmt->fetchAll();

// 4. Recettes du mois par site
$query = "SELECT s.nom as site, SUM(p.montant) as recettes
          FROM paiements p
          JOIN etudiants e ON p.etudiant_id = e.id
          JOIN sites s ON e.site_id = s.id
          WHERE MONTH(p.date_paiement) = MONTH(CURRENT_DATE())
          AND YEAR(p.date_paiement) = YEAR(CURRENT_DATE())
          AND p.statut = 'valide'
          GROUP BY s.id";
$stmt = $db->query($query);
$stats['recettes_par_site'] = $stmt->fetchAll();

// 5. Dernières demandes d'inscription
$query = "SELECT d.*, s.nom as site_nom 
          FROM demande_inscriptions d
          LEFT JOIN sites s ON d.site_id = s.id
          WHERE d.statut = 'en_attente'
          ORDER BY d.date_demande DESC
          LIMIT 10";
$stmt = $db->query($query);
$demandes_en_attente = $stmt->fetchAll();
?>

<!-- Sidebar spécifique à l'admin principal -->
<div class="sidebar col-md-2">
    <div class="text-center mb-4">
        <div class="logo-placeholder mb-2">
            <i class="fas fa-graduation-cap fa-3x" style="color: var(--secondary-color);"></i>
        </div>
        <h5>ISGI - ADMIN</h5>
        <div class="badge bg-success mt-2">Administrateur Principal</div>
        <p class="small mt-2"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
        
        <!-- Sélecteur de site -->
        <div class="mt-3">
            <select class="form-select form-select-sm" id="siteFilter" onchange="filterBySite(this.value)">
                <option value="">Tous les sites</option>
                <?php
                $sitesQuery = "SELECT * FROM sites WHERE statut = 'actif'";
                $sites = $db->query($sitesQuery)->fetchAll();
                foreach ($sites as $site) {
                    echo '<option value="' . $site['id'] . '">' . htmlspecialchars($site['nom']) . '</option>';
                }
                ?>
            </select>
        </div>
        
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
            <a href="dashboard_admin_principal.php" class="active">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard Global
            </a>
        </div>
        
        <div class="menu-section">
            <h6>Gestion Multi-Sites</h6>
            <a href="admin_sites.php">
                <i class="fas fa-building me-2"></i>Sites ISGI
            </a>
            <a href="admin_utilisateurs.php">
                <i class="fas fa-users me-2"></i>Utilisateurs Tous Sites
            </a>
            <a href="admin_demandes.php">
                <i class="fas fa-user-plus me-2"></i>Demandes Comptes
                <?php if(count($demandes_en_attente) > 0): ?>
                <span class="badge bg-danger float-end"><?php echo count($demandes_en_attente); ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="menu-section">
            <h6>Académique Global</h6>
            <a href="admin_etudiants.php">
                <i class="fas fa-user-graduate me-2"></i>Étudiants Tous Sites
            </a>
            <a href="admin_professeurs.php">
                <i class="fas fa-chalkboard-teacher me-2"></i>Professeurs Tous Sites
            </a>
            <a href="admin_filieres.php">
                <i class="fas fa-graduation-cap me-2"></i>Filières & Options
            </a>
        </div>
        
        <div class="menu-section">
            <h6>Finances Globales</h6>
            <a href="admin_paiements.php">
                <i class="fas fa-money-bill-wave me-2"></i>Paiements Tous Sites
            </a>
            <a href="admin_dettes.php">
                <i class="fas fa-exclamation-triangle me-2"></i>Dettes Tous Sites
            </a>
            <a href="admin_rapports_financiers.php">
                <i class="fas fa-chart-bar me-2"></i>Rapports Financiers
            </a>
        </div>
        
        <div class="menu-section">
            <h6>Configuration</h6>
            <a href="admin_configuration.php">
                <i class="fas fa-cog me-2"></i>Configuration Système
            </a>
            <a href="admin_calendrier.php">
                <i class="fas fa-calendar-alt me-2"></i>Calendrier Académique
            </a>
            <a href="admin_backup.php">
                <i class="fas fa-database me-2"></i>Sauvegarde
            </a>
        </div>
        
        <div class="menu-section">
            <a href="../index.html" target="_blank">
                <i class="fas fa-eye me-2"></i>Voir le Site Public
            </a>
            <a href="logout.php">
                <i class="fas fa-sign-out-alt me-2"></i>Déconnexion
            </a>
        </div>
    </nav>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-tachometer-alt me-2"></i>Tableau de Bord - Vue Globale</h2>
        <div class="btn-group">
            <button class="btn btn-outline-secondary" onclick="refreshDashboard()">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button class="btn btn-outline-secondary" onclick="exportDashboard()">
                <i class="fas fa-download"></i> Exporter
            </button>
        </div>
    </div>
    
    <!-- Cartes de statistiques globales -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-building fa-2x text-primary mb-2"></i>
                <h3 id="totalSites"><?php echo count($stats['etudiants_par_site']); ?></h3>
                <p class="text-muted">Sites Actifs</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-user-graduate fa-2x text-success mb-2"></i>
                <h3 id="totalEtudiants"><?php echo array_sum(array_column($stats['etudiants_par_site'], 'total_etudiants')); ?></h3>
                <p class="text-muted">Étudiants Totaux</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-chalkboard-teacher fa-2x text-warning mb-2"></i>
                <h3 id="totalProfesseurs"><?php echo array_sum(array_column($stats['professeurs_par_site'], 'total_professeurs')); ?></h3>
                <p class="text-muted">Professeurs Totaux</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card text-center">
                <i class="fas fa-money-bill-wave fa-2x text-info mb-2"></i>
                <h3 id="totalRecettes"><?php echo array_sum(array_column($stats['recettes_par_site'], 'recettes')) . ' FCFA'; ?></h3>
                <p class="text-muted">Recettes du Mois</p>
            </div>
        </div>
    </div>
    
    <!-- Graphiques comparatifs par site -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="chart-container">
                <h5><i class="fas fa-chart-bar me-2"></i>Étudiants par Site</h5>
                <canvas id="etudiantsParSiteChart"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-container">
                <h5><i class="fas fa-chart-pie me-2"></i>Répartition des Recettes</h5>
                <canvas id="recettesParSiteChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Tableau des sites -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="form-section">
                <h4><i class="fas fa-building me-2"></i>Sites ISGI - Statistiques</h4>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Site</th>
                                <th>Ville</th>
                                <th>Étudiants</th>
                                <th>Professeurs</th>
                                <th>Recettes Mois</th>
                                <th>Taux Remplissage</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($stats['etudiants_par_site'] as $site): 
                                // Trouver les statistiques correspondantes
                                $professeurs = array_filter($stats['professeurs_par_site'], 
                                    function($p) use ($site) { return $p['site'] == $site['site']; });
                                $recettes = array_filter($stats['recettes_par_site'], 
                                    function($r) use ($site) { return $r['site'] == $site['site']; });
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($site['site']); ?></td>
                                <td><?php echo htmlspecialchars(explode(' ', $site['site'])[1] ?? ''); ?></td>
                                <td><strong><?php echo $site['total_etudiants']; ?></strong></td>
                                <td><?php echo !empty($professeurs) ? current($professeurs)['total_professeurs'] : 0; ?></td>
                                <td><?php echo !empty($recettes) ? current($recettes)['recettes'] . ' FCFA' : '0 FCFA'; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <?php $pourcentage = min(100, ($site['total_etudiants'] / 500) * 100); ?>
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $pourcentage; ?>%">
                                            <?php echo round($pourcentage); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success">Actif</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" 
                                            onclick="voirSiteDetails(<?php echo $site['id'] ?? 0; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Dernières activités -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="form-section">
                <h4><i class="fas fa-user-plus me-2"></i>Dernières Demandes d'Inscription</h4>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Nom</th>
                                <th>Site</th>
                                <th>Filière</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($demandes_en_attente as $demande): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($demande['date_demande'])); ?></td>
                                <td><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($demande['site_nom'] ?? 'Non assigné'); ?></td>
                                <td><?php echo htmlspecialchars($demande['filiere']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="validerDemande(<?php echo $demande['id']; ?>)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="form-section">
                <h4><i class="fas fa-bell me-2"></i>Alertes Système</h4>
                <div id="alertesSysteme">
                    <!-- Alertes seront chargées en AJAX -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts JavaScript -->
<script>
// Fonction pour basculer entre mode sombre et clair
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    // Mettre à jour l'attribut
    html.setAttribute('data-theme', newTheme);
    
    // Sauvegarder dans un cookie (30 jours)
    document.cookie = `theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
    
    // Mettre à jour le bouton
    const button = event.target.closest('button');
    if (button) {
        const icon = button.querySelector('i');
        if (newTheme === 'dark') {
            button.innerHTML = '<i class="fas fa-sun"></i> Mode Clair';
        } else {
            button.innerHTML = '<i class="fas fa-moon"></i> Mode Sombre';
        }
    }
}

// Initialiser les graphiques
document.addEventListener('DOMContentLoaded', function() {
    loadCharts();
    loadAlertes();
    
    // Initialiser le bouton thème
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
    if (themeButton) {
        if (currentTheme === 'dark') {
            themeButton.innerHTML = '<i class="fas fa-sun"></i> Mode Clair';
        } else {
            themeButton.innerHTML = '<i class="fas fa-moon"></i> Mode Sombre';
        }
    }
});

// Graphique des étudiants par site
function loadCharts() {
    // Données des étudiants par site
    const sitesEtudiants = <?php echo json_encode(array_column($stats['etudiants_par_site'], 'site')); ?>;
    const nombresEtudiants = <?php echo json_encode(array_column($stats['etudiants_par_site'], 'total_etudiants')); ?>;
    
    // Chart 1: Étudiants par site
    const ctx1 = document.getElementById('etudiantsParSiteChart').getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: sitesEtudiants,
            datasets: [{
                label: 'Nombre d\'étudiants',
                data: nombresEtudiants,
                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
    
    // Chart 2: Répartition des recettes
    const sitesRecettes = <?php echo json_encode(array_column($stats['recettes_par_site'], 'site')); ?>;
    const montantsRecettes = <?php echo json_encode(array_column($stats['recettes_par_site'], 'recettes')); ?>;
    
    const ctx2 = document.getElementById('recettesParSiteChart').getContext('2d');
    new Chart(ctx2, {
        type: 'pie',
        data: {
            labels: sitesRecettes,
            datasets: [{
                data: montantsRecettes,
                backgroundColor: [
                    '#3498db', '#2ecc71', '#e74c3c', '#f39c12', 
                    '#9b59b6', '#1abc9c', '#d35400'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });
}

// Filtrer par site
function filterBySite(siteId) {
    if (!siteId) {
        // Afficher toutes les données
        location.reload();
        return;
    }
    
    // Rediriger vers la vue du site spécifique
    window.location.href = `admin_site_details.php?id=${siteId}`;
}

// Charger les alertes système
function loadAlertes() {
    fetch('ajax/get_alertes.php')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('alertesSysteme');
            let html = '';
            
            if (data.length === 0) {
                html = '<div class="alert alert-info">Aucune alerte pour le moment</div>';
            } else {
                data.forEach(alerte => {
                    html += `
                        <div class="alert alert-${alerte.type} alert-dismissible fade show">
                            <i class="fas ${alerte.icon} me-2"></i>
                            ${alerte.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                });
            }
            
            container.innerHTML = html;
        });
}

// Rafraîchir le dashboard
function refreshDashboard() {
    location.reload();
}

// Exporter le dashboard
function exportDashboard() {
    // Implémenter l'export en PDF/Excel
    alert('Export en cours de développement...');
}

// Voir les détails d'un site
function voirSiteDetails(siteId) {
    window.location.href = `admin_site_details.php?id=${siteId}`;
}

// Valider une demande d'inscription
function validerDemande(demandeId) {
    if (confirm('Êtes-vous sûr de vouloir valider cette demande ?')) {
        fetch(`ajax/valider_demande.php?id=${demandeId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Demande validée avec succès !');
                    location.reload();
                } else {
                    alert('Erreur : ' + data.message);
                }
            });
    }
}

// Vérifier l'expiration de session
setInterval(function() {
    fetch('ajax/check_session.php')
        .then(response => response.json())
        .then(data => {
            if (!data.valid) {
                alert('Votre session a expiré. Veuillez vous reconnecter.');
                window.location.href = 'logout.php';
            }
        });
}, 300000); // Vérifier toutes les 5 minutes
</script>

<?php require_once 'includes/footer.php'; ?>