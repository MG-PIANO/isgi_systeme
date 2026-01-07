<?php
// dashboard/dac/includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3">
        <div class="sidebar-header p-3">
            <h5 class="text-center">
                <i class="fas fa-graduation-cap text-primary"></i><br>
                <span class="text-muted">Directeur Académique</span>
            </h5>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" 
                   href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <small class="text-muted ps-3">GESTION DES ÉTUDIANTS</small>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'etudiants.php' ? 'active' : ''; ?>" 
                   href="etudiants.php">
                    <i class="fas fa-users"></i> Liste des étudiants
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'cartes_etudiant.php' ? 'active' : ''; ?>" 
                   href="cartes_etudiant.php">
                    <i class="fas fa-id-card"></i> Cartes étudiant
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'presences.php' ? 'active' : ''; ?>" 
                   href="presences.php">
                    <i class="fas fa-calendar-check"></i> Présences
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'salles.php' ? 'active' : ''; ?>" 
                   href="salles.php">
                    <i class="fas fa-door-open"></i> Salles de classe
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <small class="text-muted ps-3">CALENDRIER & EXAMENS</small>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'calendrier_academique.php' ? 'active' : ''; ?>" 
                   href="calendrier_academique.php">
                    <i class="fas fa-calendar"></i> Calendrier académique
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'calendrier_examens.php' ? 'active' : ''; ?>" 
                   href="calendrier_examens.php">
                    <i class="fas fa-calendar-alt"></i> Calendrier d'examens
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'reunions.php' ? 'active' : ''; ?>" 
                   href="reunions.php">
                    <i class="fas fa-users"></i> Réunions
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <small class="text-muted ps-3">ÉVALUATIONS & NOTES</small>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'notes.php' ? 'active' : ''; ?>" 
                   href="notes.php">
                    <i class="fas fa-file-alt"></i> Gestion des notes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'bulletins.php' ? 'active' : ''; ?>" 
                   href="bulletins.php">
                    <i class="fas fa-file-certificate"></i> Bulletins
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'examens.php' ? 'active' : ''; ?>" 
                   href="examens.php">
                    <i class="fas fa-clipboard-check"></i> Organisation examens
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <small class="text-muted ps-3">RAPPORTS & STATISTIQUES</small>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'rapports_academiques.php' ? 'active' : ''; ?>" 
                   href="rapports_academiques.php">
                    <i class="fas fa-chart-bar"></i> Rapports académiques
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'statistiques.php' ? 'active' : ''; ?>" 
                   href="statistiques.php">
                    <i class="fas fa-chart-pie"></i> Statistiques
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'export_data.php' ? 'active' : ''; ?>" 
                   href="export_data.php">
                    <i class="fas fa-download"></i> Export des données
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <hr>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?php echo ROOT_PATH; ?>/auth/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Déconnexion
                </a>
            </li>
        </ul>
    </div>
</div>