<?php
session_start();
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Récupérer les statistiques
$stats = [];
try {
    $stmt = $db->query("SELECT 
        (SELECT COUNT(*) FROM etudiants WHERE statut = 'valide') as etudiants_valides,
        (SELECT COUNT(*) FROM filieres WHERE est_actif = 1) as filieres_actives,
        (SELECT valeur FROM configurations WHERE cle = 'annee_academique') as annee_academique");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    logError("Erreur récupération stats: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Accueil</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reprendre le même CSS que inscription.php */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
        }
        
        .hero {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 20px;
        }
        
        .hero p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto 30px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 40px 0;
        }
        
        .stat-card {
            background: white;
            padding: 30px 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin: 10px 0;
        }
        
        .actions-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin: 50px 0;
        }
        
        .action-card {
            background: white;
            padding: 40px 30px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .action-card:hover {
            transform: translateY(-10px);
        }
        
        .action-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 20px;
        }
        
        .btn-large {
            padding: 15px 40px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <div class="logo-container">
                <div style="padding-left: 22px;">
                    <div class="logo-text">IS<span>GI</span></div>
                    <div style="font-size: 0.9rem;color: #666;">Institut Supérieur de Gestion et d'Ingénierie</div>
                </div>
            </div>
            
            <nav class="nav-links">
                <a href="index.php" class="active">Accueil</a>
                <a href="inscription.php">Inscription</a>
                <a href="reinscription.php">Réinscription</a>
                <a href="admin/login.php">Administration</a>
            </nav>
            
            <div class="auth-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='admin/login.php'">Se connecter</button>
                <button class="btn btn-primary" onclick="window.location.href='inscription.php'">S'inscrire</button>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <h1>Bienvenue à l'ISGI</h1>
            <p>Institut Supérieur de Gestion et d'Ingénierie - Formons les leaders de demain avec excellence et innovation</p>
            <p>Année académique : <strong><?php echo htmlspecialchars($stats['annee_academique'] ?? '2025-2026'); ?></strong></p>
            <div style="margin-top: 30px;">
                <button class="btn btn-primary btn-large" onclick="window.location.href='inscription.php'">
                    <i class="fas fa-user-graduate"></i> Commencer votre inscription
                </button>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section>
        <div class="container">
            <h2 style="text-align: center; margin-bottom: 40px; color: var(--primary-color);">Notre Institution en Chiffres</h2>
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number"><?php echo htmlspecialchars($stats['etudiants_valides'] ?? '0'); ?>+</div>
                    <h3>Étudiants</h3>
                    <p>Étudiants actuellement formés</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="stat-number"><?php echo htmlspecialchars($stats['filieres_actives'] ?? '12'); ?></div>
                    <h3>Filières</h3>
                    <p>Programmes d'excellence</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-number">3</div>
                    <h3>Campus</h3>
                    <p>Brazzaville, Pointe-Noire, Ouesso</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="stat-number">4</div>
                    <h3>Partenaires</h3>
                    <p>International</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Actions Section -->
    <section style="background-color: #f8f9fa; padding: 60px 0;">
        <div class="container">
            <h2 style="text-align: center; margin-bottom: 50px; color: var(--primary-color);">Services en Ligne</h2>
            <div class="actions-container">
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h3>Nouvelle Inscription</h3>
                    <p>Devenez étudiant à l'ISGI. Complétez votre inscription en ligne en quelques étapes simples.</p>
                    <button class="btn btn-primary" onclick="window.location.href='inscription.php'" style="margin-top: 20px;">
                        S'inscrire maintenant
                    </button>
                </div>
                
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-redo-alt"></i>
                    </div>
                    <h3>Réinscription</h3>
                    <p>Étudiants actuels, renouvelez votre inscription pour la nouvelle année académique.</p>
                    <button class="btn btn-success" onclick="window.location.href='reinscription.php'" style="margin-top: 20px;">
                        Se réinscrire
                    </button>
                </div>
                
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <h3>Espace Étudiant</h3>
                    <p>Accédez à vos notes, emploi du temps, paiements et ressources pédagogiques.</p>
                    <button class="btn btn-secondary" onclick="window.location.href='admin/login.php'" style="margin-top: 20px;">
                        Se connecter
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="main-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>ISGI</h3>
                    <p>Institut Supérieur de Gestion et d'Ingénierie, formant les leaders de demain.</p>
                    <div style="margin-top: 15px;">
                        <i class="fas fa-map-marker-alt"></i> Brazzaville, Congo<br>
                        <i class="fas fa-phone"></i> +242 06 848 45 67<br>
                        <i class="fas fa-envelope"></i> contact@isgi.cg
                    </div>
                </div>
                
                <div class="footer-section">
                    <h3>Liens rapides</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="inscription.php">Inscription</a></li>
                        <li><a href="reinscription.php">Réinscription</a></li>
                        <li><a href="admin/login.php">Connexion</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Domaines</h3>
                    <ul class="footer-links">
                        <li><a href="#">Technologies</a></li>
                        <li><a href="#">Gestion</a></li>
                        <li><a href="#">Droit</a></li>
                        <li><a href="#">Industrie</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>
</body>
</html>