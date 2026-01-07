<?php
// dashboard/admin_principal/sidebar.php

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// NE PAS démarrer la session ici car elle est déjà démarrée dans le fichier principal
// session_start();

// Vérifier si la session est démarrée et si les variables nécessaires existent
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Si la session n'est pas démarrée, la démarrer
    session_start();
}

// Compter les demandes en attente
$demandeCount = 0;
if (isset($db)) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM demande_inscriptions WHERE statut = 'en_attente'");
        $stmt->execute();
        $result = $stmt->fetch();
        $demandeCount = isset($result['count']) ? $result['count'] : 0;
    } catch (Exception $e) {
        $demandeCount = 0;
    }
}

// Déterminer la page active
$current_page = basename($_SERVER['PHP_SELF']);

// Variables de session avec valeurs par défaut
$user_name = $_SESSION['user_name'] ?? 'Administrateur';
?>

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
        <p class="mb-1"><?php echo htmlspecialchars($user_name); ?></p>
        <small>Vue Globale Multi-Sites</small>
    </div>
    
    <div class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Tableau de Bord</div>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Global</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Gestion Multi-Sites</div>
            <a href="sites.php" class="nav-link <?php echo $current_page == 'sites.php' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span>Tous les Sites</span>
            </a>
            <a href="utilisateurs.php" class="nav-link <?php echo $current_page == 'utilisateurs.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Tous les Utilisateurs</span>
            </a>
            <a href="demandes.php" class="nav-link <?php echo $current_page == 'demandes.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-plus"></i>
                <span>Demandes d'Inscription</span>
                <?php if ($demandeCount > 0): ?>
                <span class="nav-badge"><?php echo $demandeCount; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Académique Global</div>
            <a href="etudiants.php" class="nav-link <?php echo $current_page == 'etudiants.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate"></i>
                <span>Tous les Étudiants</span>
            </a>
            <a href="professeurs.php" class="nav-link <?php echo $current_page == 'professeurs.php' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Tous les Professeurs</span>
            </a>
            <a href="calendrier_examens.php" class="nav-link <?php echo $current_page == 'calendrier_examens.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>Calendrier Examens</span>
            </a>
            <a href="calendrier_academique.php" class="nav-link <?php echo $current_page == 'calendrier_academique.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i>
                <span>Calendrier Académique</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Pédagogie & Ressources</div>
            <a href="cours_en_ligne.php" class="nav-link <?php echo $current_page == 'cours_en_ligne.php' ? 'active' : ''; ?>">
                <i class="fas fa-laptop"></i>
                <span>Cours en Ligne</span>
            </a>
            <a href="bibliotheque.php" class="nav-link <?php echo $current_page == 'bibliotheque.php' ? 'active' : ''; ?>">
                <i class="fas fa-book"></i>
                <span>Bibliothèque</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Finances Globales</div>
            <a href="paiements.php" class="nav-link <?php echo $current_page == 'paiements.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Gestion Paiements</span>
            </a>
            <a href="dettes.php" class="nav-link <?php echo $current_page == 'dettes.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Gestion Dettes</span>
            </a>
            <a href="rapport_financier.php" class="nav-link <?php echo $current_page == 'rapport_financier.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports Financiers</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Administration</div>
            <a href="reunions.php" class="nav-link <?php echo $current_page == 'reunions.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Réunions</span>
            </a>
            <a href="rapports_statistiques.php" class="nav-link <?php echo $current_page == 'rapports_statistiques.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Rapports Statistiques</span>
            </a>
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Configuration</div>
            <button class="btn btn-outline-light w-100 mb-2" id="themeToggleBtn">
                <i class="fas fa-moon"></i> <span>Mode Sombre</span>
            </button>
            <a href="../../auth/logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </a>
        </div>
    </div>
</div>

<script>
// Fonction pour basculer entre mode sombre et clair
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    // Mettre à jour l'attribut
    html.setAttribute('data-theme', newTheme);
    
    // Sauvegarder dans un cookie (30 jours)
    document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/; SameSite=Lax`;
    
    // Mettre à jour le bouton
    updateThemeButton(newTheme);
    
    // Sauvegarder dans localStorage aussi (pour meilleure performance)
    localStorage.setItem('isgi_theme', newTheme);
}

// Fonction pour mettre à jour le bouton de thème
function updateThemeButton(theme) {
    const themeButton = document.getElementById('themeToggleBtn');
    if (themeButton) {
        if (theme === 'dark') {
            themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
        } else {
            themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
        }
    }
}

// Initialiser le thème
document.addEventListener('DOMContentLoaded', function() {
    // Vérifier d'abord localStorage
    let theme = localStorage.getItem('isgi_theme');
    
    // Si pas dans localStorage, vérifier le cookie
    if (!theme) {
        const match = document.cookie.match(/isgi_theme=([^;]+)/);
        theme = match ? match[1] : 'light';
    }
    
    // Appliquer le thème
    document.documentElement.setAttribute('data-theme', theme);
    
    // Mettre à jour le bouton
    updateThemeButton(theme);
    
    // Ajouter l'événement click sur le bouton
    const themeButton = document.getElementById('themeToggleBtn');
    if (themeButton) {
        themeButton.addEventListener('click', toggleTheme);
    }
    
    // Ajuster les styles pour les éléments spécifiques
    adjustThemeStyles(theme);
});

// Fonction pour ajuster les styles selon le thème
function adjustThemeStyles(theme) {
    // Ajouter/retirer une classe sur le body pour faciliter les sélections CSS
    if (theme === 'dark') {
        document.body.classList.add('dark-theme');
        document.body.classList.remove('light-theme');
    } else {
        document.body.classList.add('light-theme');
        document.body.classList.remove('dark-theme');
    }
}

// Écouter les changements de thème pour ajuster les styles
const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.attributeName === 'data-theme') {
            const theme = document.documentElement.getAttribute('data-theme');
            adjustThemeStyles(theme);
            updateThemeButton(theme);
        }
    });
});

observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme']
});
</script>

<style>
/* Styles spécifiques pour le thème sombre */
[data-theme="dark"] .sidebar {
    background-color: var(--sidebar-bg);
    color: var(--sidebar-text);
}

[data-theme="dark"] .sidebar-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

[data-theme="dark"] .nav-section-title {
    color: rgba(255, 255, 255, 0.6);
}

[data-theme="dark"] .nav-link {
    color: var(--sidebar-text);
}

[data-theme="dark"] .nav-link:hover, 
[data-theme="dark"] .nav-link.active {
    background-color: var(--secondary-color);
    color: white;
}

[data-theme="dark"] .btn-outline-light {
    color: var(--sidebar-text);
    border-color: var(--sidebar-text);
}

[data-theme="dark"] .btn-outline-light:hover {
    background-color: var(--sidebar-text);
    color: var(--sidebar-bg);
}

/* Ajustements pour la responsivité */
@media (max-width: 768px) {
    .sidebar {
        width: 70px;
        overflow-x: hidden;
    }
    
    .sidebar-header, 
    .user-info, 
    .nav-section-title, 
    .nav-link span,
    .btn-outline-light span {
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
    
    .btn-outline-light {
        padding: 10px;
        justify-content: center;
    }
    
    .btn-outline-light i {
        margin-right: 0;
        font-size: 18px;
    }
    
    .user-role {
        display: none;
    }
}
</style>