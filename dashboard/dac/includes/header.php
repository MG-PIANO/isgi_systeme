<?php
// dashboard/dac/includes/header.php
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-graduation-cap"></i> ISGI - DAC
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-graduate"></i> Étudiants
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="etudiants.php">Liste des étudiants</a></li>
                        <li><a class="dropdown-item" href="cartes_etudiant.php">Cartes étudiant</a></li>
                        <li><a class="dropdown-item" href="presences.php">Présences</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-calendar"></i> Calendriers
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="calendrier_academique.php">Calendrier académique</a></li>
                        <li><a class="dropdown-item" href="calendrier_examens.php">Examens</a></li>
                        <li><a class="dropdown-item" href="reunions.php">Réunions</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-file-alt"></i> Évaluations
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="notes.php">Notes</a></li>
                        <li><a class="dropdown-item" href="bulletins.php">Bulletins</a></li>
                        <li><a class="dropdown-item" href="examens.php">Examens</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="rapports_academiques.php">
                        <i class="fas fa-chart-bar"></i> Rapports
                    </a>
                </li>
            </ul>
            
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle"></i> <?php echo $_SESSION['user_name'] ?? 'Utilisateur'; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user"></i> Mon profil
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo ROOT_PATH; ?>/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Déconnexion
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>