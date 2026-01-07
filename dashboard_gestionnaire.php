<?php
session_start();

// Vérifier si l'utilisateur est connecté et s'il est un gestionnaire
// Note : Dans votre login, vous enregistrez 'role_id'. 
// Il faut vérifier quel ID correspond au gestionnaire (ex: 2)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php'; 

$db = Database::getInstance()->getConnection();

// Récupération sécurisée des infos de session
$user_id = $_SESSION['user_id'];
$site_id = $_SESSION['site_id'];
$user_name = $_SESSION['user_name'];

// Récupérer le nom du site s'il n'est pas en session
if (!isset($_SESSION['site_nom'])) {
    $stmtSite = $db->prepare("SELECT nom FROM sites WHERE id = ?");
    $stmtSite->execute([$site_id]);
    $_SESSION['site_nom'] = $stmtSite->fetchColumn() ?: "Site inconnu";
}
$site_nom = $_SESSION['site_nom'];

// Initialisation des compteurs pour éviter les erreurs d'affichage
$recetteMois = 0;
$totalDettes = 0;
$nbEtudiants = 0;

try {
    // 1. Recettes du mois pour ce site spécifique
    $stmt = $db->prepare("SELECT SUM(montant) as total FROM paiements 
                          WHERE site_id = ? AND MONTH(date_paiement) = MONTH(CURRENT_DATE())");
    $stmt->execute([$site_id]);
    $recetteMois = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 2. Dettes totales des étudiants du site
    $stmt = $db->prepare("SELECT SUM(montant_du) as total FROM etudiants 
                          WHERE site_id = ? AND statut_paiement = 'en_retard'");
    $stmt->execute([$site_id]);
    $totalDettes = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 3. Nombre d'étudiants sur le site
    $stmt = $db->prepare("SELECT COUNT(*) FROM etudiants WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $nbEtudiants = $stmt->fetchColumn();

} catch (Exception $e) {
    error_log("Erreur Dashboard: " . $e->getMessage());
}

function formatMoney($amount) {
    return number_format((float)$amount, 0, ',', ' ') . ' FCFA';
}
?>

<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionnaire - ISGI <?php echo $_SESSION['site_nom']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root[data-theme="light"] {
            --bg-color: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --primary: #2c3e50;
            --accent: #3498db;
        }
        :root[data-theme="dark"] {
            --bg-color: #1a1a1a;
            --card-bg: #2d2d2d;
            --text-color: #f4f4f4;
            --primary: #ecf0f1;
            --accent: #3498db;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            transition: all 0.3s ease;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            background: var(--primary);
            color: white;
            position: fixed;
            padding: 20px;
        }

        .main-content {
            margin-left: 270px;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(52, 152, 219, 0.1);
            color: var(--accent);
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            margin-right: 15px;
        }

        .theme-toggle {
            cursor: pointer;
            padding: 10px 20px;
            border-radius: 20px;
            border: none;
            background: var(--accent);
            color: white;
        }

        table {
            width: 100%;
            background: var(--card-bg);
            border-collapse: collapse;
            border-radius: 10px;
            overflow: hidden;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>ISGI - <?php echo $_SESSION['site_nom']; ?></h2>
    <nav>
        <p><i class="fas fa-home"></i> Dashboard</p>
        <p><i class="fas fa-user-plus"></i> Inscriptions</p>
        <p><i class="fas fa-wallet"></i> Caisse & Paiements</p>
        <p><i class="fas fa-file-invoice"></i> Rapports</p>
        <p><i class="fas fa-envelope"></i> Messagerie</p>
    </nav>
</div>

<div class="main-content">
    <div class="header">
        <h1>Tableau de Bord Gestionnaire</h1>
        <button class="theme-toggle" onclick="toggleTheme()">
            <i class="fas fa-moon"></i> Mode Sombre
        </button>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-money-bill-trend-up"></i></div>
            <div>
                <small>Recettes du Mois</small>
                <h3><?php echo formatMoney($recetteMois); ?></h3>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="color: #e74c3c;"><i class="fas fa-hand-holding-dollar"></i></div>
            <div>
                <small>Dettes Étudiants</small>
                <h3><?php echo formatMoney($totalDettes); ?></h3>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div>
                <small>Étudiants Actifs</small>
                <h3><?php echo $nbEtudiants; ?></h3>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Dernières Transactions (Site : <?php echo $_SESSION['site_nom']; ?>)</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Étudiant</th>
                    <th>Montant</th>
                    <th>Méthode</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>27/12/2025</td>
                    <td>Jean Dupont</td>
                    <td>50 000 FCFA</td>
                    <td>Mobile Money</td>
                    <td><span style="color: green;">Validé</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    [cite_start]// Logique du Mode Sombre [cite: 130]
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        
        const btn = document.querySelector('.theme-toggle');
        btn.innerHTML = newTheme === 'dark' ? '<i class="fas fa-sun"></i> Mode Clair' : '<i class="fas fa-moon"></i> Mode Sombre';
    }

    // Charger le thème au démarrage
    window.onload = () => {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    }
</script>

</body>
</html>