<?php
// dashboard/dac/notes.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle DAC (ID 5 dans la table roles)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
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
    $pageTitle = "DAC - Gestion des Notes et Examens";
    
    // Récupérer l'ID du site de l'utilisateur
    $site_id = $_SESSION['site_id'] ?? null;
    
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
            case 'validee':
            case 'publie':
                return '<span class="badge bg-success">Validé</span>';
            case 'brouillon':
            case 'en_attente':
            case 'planifie':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'termine':
                return '<span class="badge bg-info">Terminé</span>';
            case 'reporte':
                return '<span class="badge bg-secondary">Reporté</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Variables pour les actions
    $action = $_GET['action'] ?? 'list';
    $id = $_GET['id'] ?? null;
    $filiere_id = $_GET['filiere_id'] ?? null;
    $niveau_id = $_GET['niveau_id'] ?? null;
    $matiere_id = $_GET['matiere_id'] ?? null;
    $semestre_id = $_GET['semestre_id'] ?? null;
    $annee_id = $_GET['annee_id'] ?? null;
    
    // Récupérer les listes pour les filtres
    $filieres = [];
    $niveaux = [];
    $matieres = [];
    $semestres = [];
    $annees_academiques = [];
    
    if ($site_id) {
        // Récupérer les filières
        $query = "SELECT f.id, f.nom, o.nom as option_nom 
                 FROM filieres f 
                 JOIN options_formation o ON f.option_id = o.id
                 ORDER BY f.nom";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les niveaux
        $query = "SELECT * FROM niveaux ORDER BY ordre";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les matières
        $query = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
                 FROM matieres m 
                 JOIN filieres f ON m.filiere_id = f.id
                 JOIN niveaux n ON m.niveau_id = n.id
                 WHERE m.site_id = :site_id
                 ORDER BY m.code";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les semestres
        $query = "SELECT s.*, aa.libelle as annee_libelle 
                 FROM semestres s
                 JOIN annees_academiques aa ON s.annee_academique_id = aa.id
                 WHERE aa.site_id = :site_id
                 ORDER BY s.numero DESC";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $semestres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les années académiques
        $query = "SELECT * FROM annees_academiques 
                 WHERE site_id = :site_id 
                 ORDER BY date_debut DESC";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $annees_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Traitement des actions
    switch ($action) {
        case 'view':
            // Voir les détails d'un examen
            if ($id) {
                $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                         c.nom as classe_nom, te.nom as type_examen,
                         CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                         aa.libelle as annee_libelle
                         FROM calendrier_examens ce
                         JOIN matieres m ON ce.matiere_id = m.id
                         JOIN classes c ON ce.classe_id = c.id
                         JOIN types_examens te ON ce.type_examen_id = te.id
                         LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                         LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                         LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                         LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                         WHERE ce.id = :id AND c.site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute(['id' => $id, 'site_id' => $site_id]);
                $examen = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Récupérer les notes pour cet examen
                if ($examen) {
                    $query = "SELECT n.*, e.matricule, e.nom, e.prenom,
                             CONCAT(u.nom, ' ', u.prenom) as evaluateur_nom
                             FROM notes n
                             JOIN etudiants e ON n.etudiant_id = e.id
                             JOIN enseignants ens ON n.evaluateur_id = ens.id
                             JOIN utilisateurs u ON ens.utilisateur_id = u.id
                             WHERE n.matiere_id = :matiere_id 
                             AND n.type_examen_id = :type_examen_id
                             AND n.semestre_id = :semestre_id
                             AND n.annee_academique_id = :annee_id
                             ORDER BY e.nom, e.prenom";
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        'matiere_id' => $examen['matiere_id'],
                        'type_examen_id' => $examen['type_examen_id'],
                        'semestre_id' => $examen['calendrier_academique_id'], // À adapter
                        'annee_id' => $annee_id // À adapter
                    ]);
                    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            break;
            
        case 'validate':
            // Valider un bulletin
            if ($id && $_SERVER['REQUEST_METHOD'] == 'POST') {
                $user_id = $_SESSION['user_id'];
                $date_validation = date('Y-m-d H:i:s');
                
                $query = "UPDATE bulletins 
                         SET statut = 'valide', valide_par = :user_id, date_validation = :date_val
                         WHERE id = :id AND site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'user_id' => $user_id,
                    'date_val' => $date_validation,
                    'id' => $id,
                    'site_id' => $site_id
                ]);
                
                $_SESSION['success_message'] = "Bulletin validé avec succès";
                header('Location: notes.php?action=bulletins');
                exit();
            }
            break;
            
        case 'publish':
            // Publier des notes
            if ($id && $_SERVER['REQUEST_METHOD'] == 'POST') {
                $query = "UPDATE calendrier_examens 
                         SET notes_validees = 1, date_publication_notes = CURDATE(),
                         valide_par = :user_id, date_validation = NOW()
                         WHERE id = :id AND EXISTS (
                             SELECT 1 FROM classes c WHERE c.id = calendrier_examens.classe_id 
                             AND c.site_id = :site_id
                         )";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'user_id' => $_SESSION['user_id'],
                    'id' => $id,
                    'site_id' => $site_id
                ]);
                
                $_SESSION['success_message'] = "Notes publiées avec succès";
                header('Location: notes.php?action=view&id=' . $id);
                exit();
            }
            break;
            
        case 'generate_bulletin':
            // Générer un bulletin
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $etudiant_id = $_POST['etudiant_id'];
                $annee_academique_id = $_POST['annee_academique_id'];
                $semestre_id = $_POST['semestre_id'];
                $user_id = $_SESSION['user_id'];
                
                // Calculer la moyenne générale
                $query = "SELECT AVG(n.note * n.coefficient_note) as moyenne
                         FROM notes n
                         JOIN calendrier_examens ce ON n.matiere_id = ce.matiere_id 
                         AND n.type_examen_id = ce.type_examen_id
                         WHERE n.etudiant_id = :etudiant_id 
                         AND n.semestre_id = :semestre_id
                         AND n.annee_academique_id = :annee_id
                         AND n.statut = 'valide'";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'etudiant_id' => $etudiant_id,
                    'semestre_id' => $semestre_id,
                    'annee_id' => $annee_academique_id
                ]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $moyenne_generale = $result['moyenne'] ?? null;
                
                // Calculer le rang (simplifié)
                $query = "SELECT COUNT(*) + 1 as rang
                         FROM (
                             SELECT AVG(n.note * n.coefficient_note) as moyenne
                             FROM notes n
                             JOIN etudiants e ON n.etudiant_id = e.id
                             JOIN classes c ON e.classe_id = c.id
                             WHERE c.id = (
                                 SELECT classe_id FROM etudiants WHERE id = :etudiant_id
                             )
                             AND n.semestre_id = :semestre_id
                             AND n.annee_academique_id = :annee_id
                             AND n.statut = 'valide'
                             GROUP BY n.etudiant_id
                             HAVING AVG(n.note * n.coefficient_note) > :moyenne
                         ) as temp";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'etudiant_id' => $etudiant_id,
                    'semestre_id' => $semestre_id,
                    'annee_id' => $annee_academique_id,
                    'moyenne' => $moyenne_generale
                ]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $rang = $result['rang'] ?? 1;
                
                // Créer le bulletin
                $query = "INSERT INTO bulletins 
                         (etudiant_id, annee_academique_id, semestre_id, moyenne_generale, 
                          rang, date_edition, edite_par, statut, site_id)
                         VALUES (:etudiant_id, :annee_id, :semestre_id, :moyenne, 
                                :rang, CURDATE(), :editeur, 'brouillon', :site_id)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'etudiant_id' => $etudiant_id,
                    'annee_id' => $annee_academique_id,
                    'semestre_id' => $semestre_id,
                    'moyenne' => $moyenne_generale,
                    'rang' => $rang,
                    'editeur' => $user_id,
                    'site_id' => $site_id
                ]);
                
                $_SESSION['success_message'] = "Bulletin généré avec succès";
                header('Location: notes.php?action=bulletins');
                exit();
            }
            break;
    }
    
    // Récupérer les données selon l'action
    $examens = [];
    $bulletins = [];
    $statistiques = [];
    
    if ($site_id) {
        switch ($action) {
            case 'examens':
                // Récupérer les examens avec filtres
                $where = "c.site_id = :site_id";
                $params = ['site_id' => $site_id];
                
                if ($filiere_id) {
                    $where .= " AND f.id = :filiere_id";
                    $params['filiere_id'] = $filiere_id;
                }
                
                if ($niveau_id) {
                    $where .= " AND n.id = :niveau_id";
                    $params['niveau_id'] = $niveau_id;
                }
                
                if ($matiere_id) {
                    $where .= " AND ce.matiere_id = :matiere_id";
                    $params['matiere_id'] = $matiere_id;
                }
                
                $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                         c.nom as classe_nom, te.nom as type_examen, f.nom as filiere_nom,
                         CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                         aa.libelle as annee_libelle
                         FROM calendrier_examens ce
                         JOIN matieres m ON ce.matiere_id = m.id
                         JOIN classes c ON ce.classe_id = c.id
                         JOIN filieres f ON c.filiere_id = f.id
                         JOIN niveaux n ON c.niveau_id = n.id
                         JOIN types_examens te ON ce.type_examen_id = te.id
                         LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                         LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                         LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                         LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                         WHERE $where
                         ORDER BY ce.date_examen DESC, ce.heure_debut DESC";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'bulletins':
                // Récupérer les bulletins
                $query = "SELECT b.*, e.matricule, e.nom, e.prenom, 
                         aa.libelle as annee_libelle, s.numero as semestre_numero,
                         CONCAT(u.nom, ' ', u.prenom) as editeur_nom,
                         CONCAT(v.nom, ' ', v.prenom) as validateur_nom
                         FROM bulletins b
                         JOIN etudiants e ON b.etudiant_id = e.id
                         JOIN annees_academiques aa ON b.annee_academique_id = aa.id
                         JOIN semestres s ON b.semestre_id = s.id
                         LEFT JOIN utilisateurs u ON b.edite_par = u.id
                         LEFT JOIN utilisateurs v ON b.valide_par = v.id
                         WHERE e.site_id = :site_id
                         ORDER BY b.date_creation DESC";
                
                $stmt = $db->prepare($query);
                $stmt->execute(['site_id' => $site_id]);
                $bulletins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'statistiques':
                // Récupérer les statistiques
                $query = "SELECT 
                            COUNT(DISTINCT e.id) as total_etudiants,
                            COUNT(DISTINCT m.id) as total_matieres,
                            COUNT(DISTINCT ce.id) as total_examens,
                            COUNT(DISTINCT b.id) as total_bulletins,
                            AVG(b.moyenne_generale) as moyenne_site,
                            MIN(b.moyenne_generale) as moyenne_min,
                            MAX(b.moyenne_generale) as moyenne_max,
                            SUM(CASE WHEN b.statut = 'valide' THEN 1 ELSE 0 END) as bulletins_valides,
                            SUM(CASE WHEN b.decision = 'admis' THEN 1 ELSE 0 END) as admis,
                            SUM(CASE WHEN b.decision = 'ajourne' THEN 1 ELSE 0 END) as ajournes,
                            SUM(CASE WHEN b.decision = 'redouble' THEN 1 ELSE 0 END) as redoublants
                         FROM etudiants e
                         LEFT JOIN matieres m ON e.site_id = m.site_id
                         LEFT JOIN notes n ON e.id = n.etudiant_id
                         LEFT JOIN calendrier_examens ce ON n.matiere_id = ce.matiere_id 
                         LEFT JOIN bulletins b ON e.id = b.etudiant_id
                         WHERE e.site_id = :site_id AND e.statut = 'actif'";
                
                $stmt = $db->prepare($query);
                $stmt->execute(['site_id' => $site_id]);
                $statistiques = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
        }
    }
    
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
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        background: var(--info-color);
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
        background-color: var(--info-color);
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
    
    /* Onglets */
    .nav-tabs .nav-link {
        color: var(--text-color);
        border: 1px solid var(--border-color);
        border-bottom: none;
        margin-right: 5px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--info-color);
        color: white;
        border-color: var(--info-color);
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
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
        background-color: var(--info-color);
        color: white;
        border: none;
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px 15px;
        color: var(--text-color);
    }
    
    /* Badges */
    .badge-note {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .note-excellente { background-color: #d4edda; color: #155724; }
    .note-bonne { background-color: #c3e6cb; color: #155724; }
    .note-moyenne { background-color: #fff3cd; color: #856404; }
    .note-faible { background-color: #f8d7da; color: #721c24; }
    
    /* Boutons d'action */
    .btn-action {
        padding: 5px 10px;
        margin: 2px;
        border-radius: 5px;
        font-size: 0.85rem;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
        margin-bottom: 20px;
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
    }
    
    /* Carte de note */
    .grade-card {
        border-left: 4px solid var(--info-color);
        transition: transform 0.2s;
    }
    
    .grade-card:hover {
        transform: translateY(-2px);
    }
    
    /* Alertes */
    .alert {
        border-radius: 10px;
        border: none;
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
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></p>
                <small>Gestion Académique</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="notes.php" class="nav-link active">
                        <i class="fas fa-file-alt"></i>
                        <span>Notes & Examens</span>
                    </a>
                     <a href="saisie_notes.php" class="nav-link active">
                        <i class="fas fa-file-alt"></i>
                        <span>Saisie des Notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Actions Rapides</div>
                    <a href="notes.php?action=statistiques" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Statistiques</span>
                    </a>
                    <a href="notes.php?action=generate_bulletin" class="nav-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Générer Bulletin</span>
                    </a>
                    <a href="export_data.php?type=notes" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Exporter Notes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="<?php echo ROOT_PATH; ?>/auth/logout.php" class="nav-link">
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
                            <i class="fas fa-file-alt me-2"></i>
                            Gestion des Notes, Examens et Bulletins
                        </h2>
                        <p class="text-muted mb-0">Directeur des Affaires Académiques</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> Retour
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); endif; ?>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Onglets principaux -->
            <ul class="nav nav-tabs mb-4" id="mainTabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $action == 'list' || $action == 'examens' ? 'active' : ''; ?>" 
                       href="notes.php?action=examens">
                        <i class="fas fa-clipboard-check me-2"></i>Examens
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $action == 'bulletins' ? 'active' : ''; ?>" 
                       href="notes.php?action=bulletins">
                        <i class="fas fa-file-certificate me-2"></i>Bulletins
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $action == 'statistiques' ? 'active' : ''; ?>" 
                       href="notes.php?action=statistiques">
                        <i class="fas fa-chart-bar me-2"></i>Statistiques
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $action == 'view' ? 'active' : ''; ?>" 
                       href="notes.php?action=view&id=<?php echo $id ?? ''; ?>">
                        <i class="fas fa-eye me-2"></i>Détails
                    </a>
                </li>
            </ul>
            
            <!-- Contenu selon l'action -->
            <?php switch($action): case 'view': ?>
                <!-- Détails d'un examen -->
                <?php if(isset($examen)): ?>
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Détails de l'examen</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6>Informations Générales</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Matière:</th>
                                                <td><?php echo htmlspecialchars($examen['matiere_nom'] . ' (' . $examen['matiere_code'] . ')'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Classe:</th>
                                                <td><?php echo htmlspecialchars($examen['classe_nom']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Type:</th>
                                                <td><?php echo htmlspecialchars($examen['type_examen']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Enseignant:</th>
                                                <td><?php echo htmlspecialchars($examen['enseignant_nom'] ?? 'Non attribué'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Date et Lieu</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Date:</th>
                                                <td><?php echo formatDateFr($examen['date_examen']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Heure:</th>
                                                <td><?php echo date('H:i', strtotime($examen['heure_debut'])) . ' - ' . date('H:i', strtotime($examen['heure_fin'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Durée:</th>
                                                <td><?php echo $examen['duree_minutes']; ?> minutes</td>
                                            </tr>
                                            <tr>
                                                <th>Salle:</th>
                                                <td><?php echo htmlspecialchars($examen['salle'] ?? 'À définir'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Notes de l'examen -->
                                <h6>Notes des étudiants</h6>
                                <?php if(isset($notes) && !empty($notes)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Étudiant</th>
                                                <th>Matricule</th>
                                                <th>Note</th>
                                                <th>Coefficient</th>
                                                <th>Date Évaluation</th>
                                                <th>Évaluateur</th>
                                                <th>Statut</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($notes as $note): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($note['prenom'] . ' ' . $note['nom']); ?></td>
                                                <td><?php echo htmlspecialchars($note['matricule']); ?></td>
                                                <td>
                                                    <span class="badge-note <?php
                                                        $note_val = $note['note'];
                                                        if($note_val >= 16) echo 'note-excellente';
                                                        elseif($note_val >= 14) echo 'note-bonne';
                                                        elseif($note_val >= 10) echo 'note-moyenne';
                                                        else echo 'note-faible';
                                                    ?>">
                                                        <?php echo number_format($note['note'], 2); ?>/20
                                                    </span>
                                                </td>
                                                <td><?php echo $note['coefficient_note']; ?></td>
                                                <td><?php echo formatDateFr($note['date_evaluation']); ?></td>
                                                <td><?php echo htmlspecialchars($note['evaluateur_nom']); ?></td>
                                                <td><?php echo getStatutBadge($note['statut']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Actions sur les notes -->
                                <div class="d-flex justify-content-between mt-3">
                                    <div>
                                        <?php if($examen['notes_validees'] == 0): ?>
                                        <form method="post" action="notes.php?action=publish&id=<?php echo $examen['id']; ?>" 
                                              onsubmit="return confirm('Publier les notes de cet examen ?')">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-check-circle me-2"></i>Publier les notes
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Notes publiées le <?php echo formatDateFr($examen['date_publication_notes']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <a href="export_data.php?type=notes&examen_id=<?php echo $examen['id']; ?>" 
                                           class="btn btn-outline-primary">
                                            <i class="fas fa-download me-2"></i>Exporter les notes
                                        </a>
                                    </div>
                                </div>
                                
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Aucune note saisie pour cet examen
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Actions rapides -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Actions</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="calendrier_examens.php?action=edit&id=<?php echo $examen['id']; ?>" 
                                       class="btn btn-warning">
                                        <i class="fas fa-edit me-2"></i>Modifier l'examen
                                    </a>
                                    <?php if($examen['statut'] == 'planifie'): ?>
                                    <button class="btn btn-danger" onclick="annulerExamen(<?php echo $examen['id']; ?>)">
                                        <i class="fas fa-times-circle me-2"></i>Annuler l'examen
                                    </button>
                                    <?php endif; ?>
                                    <a href="notes.php?action=examens" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Retour à la liste
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Statistiques de l'examen -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0">Statistiques</h6>
                            </div>
                            <div class="card-body">
                                <?php if(isset($notes) && !empty($notes)): 
                                    $notes_values = array_column($notes, 'note');
                                    $moyenne = array_sum($notes_values) / count($notes_values);
                                    $max = max($notes_values);
                                    $min = min($notes_values);
                                    $admis = count(array_filter($notes_values, function($n) { return $n >= 10; }));
                                    $taux_admission = ($admis / count($notes_values)) * 100;
                                ?>
                                <div class="list-group">
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>Nombre de copies:</span>
                                        <strong><?php echo count($notes); ?></strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>Moyenne:</span>
                                        <strong class="text-primary"><?php echo number_format($moyenne, 2); ?>/20</strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>Note max:</span>
                                        <strong class="text-success"><?php echo number_format($max, 2); ?>/20</strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>Note min:</span>
                                        <strong class="text-danger"><?php echo number_format($min, 2); ?>/20</strong>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>Taux d'admission:</span>
                                        <strong class="<?php echo $taux_admission >= 50 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($taux_admission, 1); ?>%
                                        </strong>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Statistiques non disponibles
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                function annulerExamen(id) {
                    if(confirm('Êtes-vous sûr de vouloir annuler cet examen ?')) {
                        window.location.href = 'calendrier_examens.php?action=cancel&id=' + id;
                    }
                }
                </script>
                
                <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Examen non trouvé
                </div>
                <?php endif; ?>
                
            <?php break; case 'bulletins': ?>
                <!-- Liste des bulletins -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Gestion des Bulletins de Notes</h5>
                                <div>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal">
                                        <i class="fas fa-plus-circle me-2"></i>Générer Bulletin
                                    </button>
                                    <a href="export_data.php?type=bulletins" class="btn btn-success">
                                        <i class="fas fa-download me-2"></i>Exporter tous
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Filtres -->
                                <div class="filter-card">
                                    <form method="get" action="notes.php">
                                        <input type="hidden" name="action" value="bulletins">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Filière</label>
                                                <select name="filiere_id" class="form-select">
                                                    <option value="">Toutes les filières</option>
                                                    <?php foreach($filieres as $filiere): ?>
                                                    <option value="<?php echo $filiere['id']; ?>" <?php echo $filiere_id == $filiere['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($filiere['nom']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Niveau</label>
                                                <select name="niveau_id" class="form-select">
                                                    <option value="">Tous les niveaux</option>
                                                    <?php foreach($niveaux as $niveau): ?>
                                                    <option value="<?php echo $niveau['id']; ?>" <?php echo $niveau_id == $niveau['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($niveau['libelle']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Année académique</label>
                                                <select name="annee_id" class="form-select">
                                                    <option value="">Toutes les années</option>
                                                    <?php foreach($annees_academiques as $annee): ?>
                                                    <option value="<?php echo $annee['id']; ?>" <?php echo $annee_id == $annee['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($annee['libelle']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Statut</label>
                                                <select name="statut" class="form-select">
                                                    <option value="">Tous les statuts</option>
                                                    <option value="brouillon">Brouillon</option>
                                                    <option value="valide">Validé</option>
                                                    <option value="publie">Publié</option>
                                                </select>
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-info">
                                                    <i class="fas fa-filter me-2"></i>Filtrer
                                                </button>
                                                <a href="notes.php?action=bulletins" class="btn btn-outline-secondary">
                                                    <i class="fas fa-times me-2"></i>Réinitialiser
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Tableau des bulletins -->
                                <?php if(empty($bulletins)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Aucun bulletin trouvé
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Étudiant</th>
                                                <th>Année Académique</th>
                                                <th>Semestre</th>
                                                <th>Moyenne</th>
                                                <th>Rang</th>
                                                <th>Date Édition</th>
                                                <th>Statut</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($bulletins as $bulletin): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($bulletin['prenom'] . ' ' . $bulletin['nom']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($bulletin['matricule']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($bulletin['annee_libelle']); ?></td>
                                                <td>
                                                    <span class="badge bg-info">S<?php echo $bulletin['semestre_numero']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if($bulletin['moyenne_generale']): ?>
                                                    <span class="badge-note <?php
                                                        $moyenne = $bulletin['moyenne_generale'];
                                                        if($moyenne >= 16) echo 'note-excellente';
                                                        elseif($moyenne >= 14) echo 'note-bonne';
                                                        elseif($moyenne >= 10) echo 'note-moyenne';
                                                        else echo 'note-faible';
                                                    ?>">
                                                        <?php echo number_format($bulletin['moyenne_generale'], 2); ?>/20
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Non calculée</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if($bulletin['rang']): ?>
                                                    <span class="badge bg-primary"><?php echo $bulletin['rang']; ?>e</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo formatDateFr($bulletin['date_edition']); ?></td>
                                                <td><?php echo getStatutBadge($bulletin['statut']); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="bulletins.php?action=view&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-action btn-info" title="Voir">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if($bulletin['statut'] == 'brouillon'): ?>
                                                        <form method="post" action="notes.php?action=validate&id=<?php echo $bulletin['id']; ?>" 
                                                              style="display: inline;" 
                                                              onsubmit="return confirm('Valider ce bulletin ?')">
                                                            <button type="submit" class="btn btn-action btn-success" title="Valider">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <a href="export_data.php?type=bulletin&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-action btn-warning" title="Exporter PDF">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </a>
                                                        <a href="bulletins.php?action=edit&id=<?php echo $bulletin['id']; ?>" 
                                                           class="btn btn-action btn-primary" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination (simplifiée) -->
                                <nav aria-label="Page navigation">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item disabled">
                                            <a class="page-link" href="#" tabindex="-1">Précédent</a>
                                        </li>
                                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">Suivant</a>
                                        </li>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal pour générer un bulletin -->
                <div class="modal fade" id="generateModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" action="notes.php?action=generate_bulletin">
                                <div class="modal-header">
                                    <h5 class="modal-title">Générer un Bulletin</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Étudiant</label>
                                        <select name="etudiant_id" class="form-select" required>
                                            <option value="">Sélectionner un étudiant</option>
                                            <?php
                                            $query = "SELECT e.id, e.matricule, e.nom, e.prenom, f.nom as filiere_nom
                                                     FROM etudiants e
                                                     LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                                                     LEFT JOIN filieres f ON i.filiere_id = f.id
                                                     WHERE e.site_id = :site_id AND e.statut = 'actif'
                                                     ORDER BY e.nom, e.prenom";
                                            $stmt = $db->prepare($query);
                                            $stmt->execute(['site_id' => $site_id]);
                                            $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach($etudiants as $etudiant):
                                            ?>
                                            <option value="<?php echo $etudiant['id']; ?>">
                                                <?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom'] . ' - ' . $etudiant['matricule'] . ' (' . $etudiant['filiere_nom'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Année Académique</label>
                                        <select name="annee_academique_id" class="form-select" required>
                                            <option value="">Sélectionner une année</option>
                                            <?php foreach($annees_academiques as $annee): ?>
                                            <option value="<?php echo $annee['id']; ?>">
                                                <?php echo htmlspecialchars($annee['libelle']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Semestre</label>
                                        <select name="semestre_id" class="form-select" required>
                                            <option value="">Sélectionner un semestre</option>
                                            <?php foreach($semestres as $semestre): ?>
                                            <option value="<?php echo $semestre['id']; ?>">
                                                Semestre <?php echo $semestre['numero']; ?> (<?php echo htmlspecialchars($semestre['annee_libelle']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-primary">Générer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
            <?php break; case 'statistiques': ?>
                <!-- Statistiques générales -->
                <div class="row mb-4">
                    <!-- Cartes de statistiques -->
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6 class="card-title">Étudiants</h6>
                                <h2 class="text-primary"><?php echo $statistiques['total_etudiants'] ?? 0; ?></h2>
                                <p class="text-muted mb-0">Total</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6 class="card-title">Matérielles</h6>
                                <h2 class="text-success"><?php echo $statistiques['total_matieres'] ?? 0; ?></h2>
                                <p class="text-muted mb-0">Enseignées</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6 class="card-title">Examens</h6>
                                <h2 class="text-warning"><?php echo $statistiques['total_examens'] ?? 0; ?></h2>
                                <p class="text-muted mb-0">Organisés</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6 class="card-title">Bulletins</h6>
                                <h2 class="text-info"><?php echo $statistiques['total_bulletins'] ?? 0; ?></h2>
                                <p class="text-muted mb-0">Générés</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Statistiques de Performance</h6>
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Moyenne générale du site:</span>
                                        <span class="badge bg-primary">
                                            <?php echo number_format($statistiques['moyenne_site'] ?? 0, 2); ?>/20
                                        </span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Moyenne la plus élevée:</span>
                                        <span class="badge bg-success">
                                            <?php echo number_format($statistiques['moyenne_max'] ?? 0, 2); ?>/20
                                        </span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Moyenne la plus basse:</span>
                                        <span class="badge bg-danger">
                                            <?php echo number_format($statistiques['moyenne_min'] ?? 0, 2); ?>/20
                                        </span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Bulletins validés:</span>
                                        <span class="badge bg-info">
                                            <?php echo $statistiques['bulletins_valides'] ?? 0; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Résultats par Décision</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="decisionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Distribution des Notes</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="distributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Graphique des décisions
                    const ctx1 = document.getElementById('decisionChart');
                    if (ctx1) {
                        const decisionChart = new Chart(ctx1, {
                            type: 'pie',
                            data: {
                                labels: ['Admis', 'Ajournés', 'Redoublants'],
                                datasets: [{
                                    data: [
                                        <?php echo $statistiques['admis'] ?? 0; ?>,
                                        <?php echo $statistiques['ajournes'] ?? 0; ?>,
                                        <?php echo $statistiques['redoublants'] ?? 0; ?>
                                    ],
                                    backgroundColor: [
                                        '#28a745', '#ffc107', '#dc3545'
                                    ]
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                    }
                                }
                            }
                        });
                    }
                    
                    // Graphique de distribution des notes
                    const ctx2 = document.getElementById('distributionChart');
                    if (ctx2) {
                        // Récupérer les données de distribution (exemple simplifié)
                        const distributionData = [10, 25, 35, 20, 10]; // Exemple: 0-4, 5-9, 10-14, 15-19, 20
                        
                        const distributionChart = new Chart(ctx2, {
                            type: 'bar',
                            data: {
                                labels: ['0-4', '5-9', '10-14', '15-19', '20'],
                                datasets: [{
                                    label: 'Nombre d\'étudiants',
                                    data: distributionData,
                                    backgroundColor: '#17a2b8'
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    title: {
                                        display: true,
                                        text: 'Distribution des moyennes générales'
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: 'Nombre d\'étudiants'
                                        }
                                    },
                                    x: {
                                        title: {
                                            display: true,
                                            text: 'Intervalle de notes'
                                        }
                                    }
                                }
                            }
                        });
                    }
                });
                </script>
                
            <?php break; default: // Liste des examens ?>
                <!-- Liste des examens -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Gestion des Examens et Notes</h5>
                                <div>
                                    <a href="calendrier_examens.php?action=create" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-2"></i>Planifier Examen
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Filtres -->
                                <div class="filter-card">
                                    <form method="get" action="notes.php">
                                        <input type="hidden" name="action" value="examens">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Filière</label>
                                                <select name="filiere_id" class="form-select">
                                                    <option value="">Toutes les filières</option>
                                                    <?php foreach($filieres as $filiere): ?>
                                                    <option value="<?php echo $filiere['id']; ?>" <?php echo $filiere_id == $filiere['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($filiere['nom']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Niveau</label>
                                                <select name="niveau_id" class="form-select">
                                                    <option value="">Tous les niveaux</option>
                                                    <?php foreach($niveaux as $niveau): ?>
                                                    <option value="<?php echo $niveau['id']; ?>" <?php echo $niveau_id == $niveau['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($niveau['libelle']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Matière</label>
                                                <select name="matiere_id" class="form-select">
                                                    <option value="">Toutes les matières</option>
                                                    <?php foreach($matieres as $matiere): ?>
                                                    <option value="<?php echo $matiere['id']; ?>" <?php echo $matiere_id == $matiere['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Statut</label>
                                                <select name="statut" class="form-select">
                                                    <option value="">Tous les statuts</option>
                                                    <option value="planifie">Planifié</option>
                                                    <option value="en_cours">En cours</option>
                                                    <option value="termine">Terminé</option>
                                                    <option value="annule">Annulé</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Date</label>
                                                <input type="date" name="date" class="form-control">
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-info">
                                                    <i class="fas fa-filter me-2"></i>Filtrer
                                                </button>
                                                <a href="notes.php?action=examens" class="btn btn-outline-secondary">
                                                    <i class="fas fa-times me-2"></i>Réinitialiser
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Tableau des examens -->
                                <?php if(empty($examens)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Aucun examen trouvé
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Matière</th>
                                                <th>Classe</th>
                                                <th>Type</th>
                                                <th>Enseignant</th>
                                                <th>Salle</th>
                                                <th>Notes</th>
                                                <th>Statut</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($examens as $examen): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo formatDateFr($examen['date_examen']); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($examen['heure_debut'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($examen['matiere_code']); ?><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($examen['matiere_nom']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($examen['classe_nom']); ?><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($examen['filiere_nom'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                    switch($examen['type_examen']) {
                                                        case 'DST': echo 'warning'; break;
                                                        case 'Session': echo 'danger'; break;
                                                        default: echo 'info';
                                                    }
                                                    ?>">
                                                        <?php echo htmlspecialchars($examen['type_examen']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($examen['enseignant_nom'] ?? 'Non attribué'); ?></td>
                                                <td><?php echo htmlspecialchars($examen['salle'] ?? 'À définir'); ?></td>
                                                <td>
                                                    <?php if($examen['notes_validees'] == 1): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Publiées
                                                    </span>
                                                    <?php elseif($examen['notes_saisies'] == 1): ?>
                                                    <span class="badge bg-warning">
                                                        <i class="fas fa-clock me-1"></i>En attente
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="fas fa-times-circle me-1"></i>Non saisies
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo getStatutBadge($examen['statut']); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="notes.php?action=view&id=<?php echo $examen['id']; ?>" 
                                                           class="btn btn-action btn-info" title="Voir détails">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="calendrier_examens.php?action=edit&id=<?php echo $examen['id']; ?>" 
                                                           class="btn btn-action btn-warning" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if($examen['statut'] == 'planifie'): ?>
                                                        <a href="calendrier_examens.php?action=cancel&id=<?php echo $examen['id']; ?>" 
                                                           class="btn btn-action btn-danger" title="Annuler"
                                                           onclick="return confirm('Annuler cet examen ?')">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Statistiques rapides -->
                                <div class="row mt-4">
                                    <div class="col-md-3">
                                        <div class="card bg-primary text-white">
                                            <div class="card-body text-center py-3">
                                                <h6>Examens Planifiés</h6>
                                                <h4><?php echo count(array_filter($examens, fn($e) => $e['statut'] == 'planifie')); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-success text-white">
                                            <div class="card-body text-center py-3">
                                                <h6>Examens Terminés</h6>
                                                <h4><?php echo count(array_filter($examens, fn($e) => $e['statut'] == 'termine')); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-warning text-white">
                                            <div class="card-body text-center py-3">
                                                <h6>Notes à Valider</h6>
                                                <h4><?php echo count(array_filter($examens, fn($e) => $e['notes_saisies'] == 1 && $e['notes_validees'] == 0)); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card bg-info text-white">
                                            <div class="card-body text-center py-3">
                                                <h6>Notes Publiées</h6>
                                                <h4><?php echo count(array_filter($examens, fn($e) => $e['notes_validees'] == 1)); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endswitch; ?>
            
            <!-- Pied de page -->
            <footer class="mt-5 pt-3 border-top">
                <div class="row">
                    <div class="col-md-6">
                        <p class="text-muted">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Système de Gestion Académique ISGI - DAC Panel
                            </small>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="text-muted">
                            <small>
                                <i class="fas fa-clock me-1"></i>
                                Dernière mise à jour: <?php echo date('d/m/Y H:i'); ?>
                            </small>
                        </p>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Gestion des alertes
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
        
        // Initialiser les tooltips Bootstrap
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
    
    // Fonction pour exporter les données
    function exportData(type, id = null) {
        let url = 'export_data.php?type=' + type;
        if (id) url += '&id=' + id;
        window.open(url, '_blank');
    }
    
    // Confirmation avant suppression
    function confirmDelete(message) {
        return confirm(message || 'Êtes-vous sûr de vouloir supprimer cet élément ?');
    }
    </script>
</body>
</html>