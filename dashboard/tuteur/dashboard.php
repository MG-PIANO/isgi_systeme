<?php
// dashboard/tuteur/dashboard.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 9) { // Rôle 9 = Tuteur
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
    $pageTitle = "Tableau de Bord Tuteur";
    
    // Récupérer l'ID du tuteur connecté
    $tuteur_id = $_SESSION['user_id'];
    
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
            case 'actif':
            case 'valide':
            case 'present':
            case 'admis':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'terminee':
                return '<span class="badge bg-info">Terminé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Tuteur';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
    }
    
    // Initialiser toutes les variables
    $stats = array(
        'total_etudiants' => 0,
        'presences_aujourdhui' => 0,
        'total_dettes' => 0,
        'moyenne_generale' => 0
    );
    
    $etudiants = array();
    $presences_detaillees = array();
    $dettes_detail = array();
    $notes_recentes = array();
    $messages_recus = array();
    $messages_envoyes = array();
    $reunions_prochaines = array();
    $paiements_recents = array();
    $calendrier_academique = array();
    $performances = array();
    $alertes_importantes = array();
    
    // Récupérer les étudiants dont le tuteur est responsable
    $query = "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle
              FROM etudiants e
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN filieres f ON c.filiere_id = f.id
              LEFT JOIN niveaux n ON c.niveau_id = n.id
              WHERE (e.nom_tuteur LIKE :nom_tuteur OR e.telephone_tuteur = :telephone)
              AND e.statut = 'actif'
              ORDER BY e.nom, e.prenom";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':nom_tuteur' => '%' . SessionManager::getUserName() . '%',
        ':telephone' => $_SESSION['telephone'] ?? ''
    ]);
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total_etudiants'] = count($etudiants);
    
    // Récupérer les présences d'aujourd'hui
    if (!empty($etudiants)) {
        $etudiant_ids = array_column($etudiants, 'id');
        $placeholders = str_repeat('?,', count($etudiant_ids) - 1) . '?';
        
        // Présences aujourd'hui
        $query = "SELECT COUNT(DISTINCT p.etudiant_id) as total
                  FROM presences p
                  WHERE p.etudiant_id IN ($placeholders)
                  AND DATE(p.date_heure) = CURDATE()
                  AND p.statut = 'present'";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['presences_aujourdhui'] = $result['total'] ?? 0;
        
        // Détail des présences
        $query = "SELECT p.*, e.nom, e.prenom, e.matricule, m.nom as matiere_nom
                  FROM presences p
                  JOIN etudiants e ON p.etudiant_id = e.id
                  LEFT JOIN matieres m ON p.matiere_id = m.id
                  WHERE p.etudiant_id IN ($placeholders)
                  AND DATE(p.date_heure) = CURDATE()
                  ORDER BY p.date_heure DESC";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $presences_detaillees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Dettes totales
        $query = "SELECT COALESCE(SUM(d.montant_restant), 0) as total
                  FROM dettes d
                  WHERE d.etudiant_id IN ($placeholders)
                  AND d.statut IN ('en_cours', 'en_retard')";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_dettes'] = $result['total'] ?? 0;
        
        // Détail des dettes
        $query = "SELECT d.*, e.matricule, e.nom, e.prenom, e.site_id, s.nom as site_nom
                  FROM dettes d
                  JOIN etudiants e ON d.etudiant_id = e.id
                  JOIN sites s ON e.site_id = s.id
                  WHERE d.etudiant_id IN ($placeholders)
                  AND d.statut IN ('en_cours', 'en_retard')
                  ORDER BY d.montant_restant DESC";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $dettes_detail = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Moyenne générale (si disponible)
        $query = "SELECT AVG(n.moyenne_generale) as moyenne
                  FROM (
                      SELECT AVG(b.moyenne_generale) as moyenne_generale
                      FROM bulletins b
                      WHERE b.etudiant_id IN ($placeholders)
                      AND b.statut = 'valide'
                      GROUP BY b.etudiant_id
                  ) n";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['moyenne_generale'] = $result['moyenne'] ?? 0;
        
        // Notes récentes
        $query = "SELECT n.*, e.matricule, e.nom, e.prenom, m.nom as matiere_nom, te.nom as type_examen
                  FROM notes n
                  JOIN etudiants e ON n.etudiant_id = e.id
                  JOIN matieres m ON n.matiere_id = m.id
                  JOIN types_examens te ON n.type_examen_id = te.id
                  WHERE n.etudiant_id IN ($placeholders)
                  ORDER BY n.date_evaluation DESC
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $notes_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Paiements récents
        $query = "SELECT p.*, e.matricule, e.nom, e.prenom, tf.nom as type_frais
                  FROM paiements p
                  JOIN etudiants e ON p.etudiant_id = e.id
                  JOIN types_frais tf ON p.type_frais_id = tf.id
                  WHERE p.etudiant_id IN ($placeholders)
                  AND p.statut = 'valide'
                  ORDER BY p.date_paiement DESC
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $paiements_recents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Performance des étudiants
        $query = "SELECT e.id, e.matricule, CONCAT(e.nom, ' ', e.prenom) as etudiant,
                         AVG(b.moyenne_generale) as moyenne,
                         COUNT(DISTINCT p.id) as jours_presence,
                         COUNT(DISTINCT CASE WHEN p.statut = 'present' THEN DATE(p.date_heure) END) as jours_presents
                  FROM etudiants e
                  LEFT JOIN bulletins b ON e.id = b.etudiant_id AND b.statut = 'valide'
                  LEFT JOIN presences p ON e.id = p.etudiant_id AND MONTH(p.date_heure) = MONTH(CURDATE())
                  WHERE e.id IN ($placeholders)
                  GROUP BY e.id, e.matricule, e.nom, e.prenom
                  ORDER BY moyenne DESC";
        $stmt = $db->prepare($query);
        $stmt->execute($etudiant_ids);
        $performances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Messages reçus
    $query = "SELECT m.*, u.nom as expediteur_nom, u.prenom as expediteur_prenom
              FROM messages m
              JOIN utilisateurs u ON m.expediteur_id = u.id
              WHERE m.destinataire_id = :tuteur_id
              ORDER BY m.date_envoi DESC
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute([':tuteur_id' => $tuteur_id]);
    $messages_recus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Messages envoyés
    $query = "SELECT m.*, u.nom as destinataire_nom, u.prenom as destinataire_prenom
              FROM messages m
              JOIN utilisateurs u ON m.destinataire_id = u.id
              WHERE m.expediteur_id = :tuteur_id
              ORDER BY m.date_envoi DESC
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute([':tuteur_id' => $tuteur_id]);
    $messages_envoyes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Réunions parent-tuteur
    $query = "SELECT r.*, s.nom as site_nom, u.nom as organisateur_nom, u.prenom as organisateur_prenom
              FROM reunions r
              JOIN sites s ON r.site_id = s.id
              JOIN utilisateurs u ON r.organisateur_id = u.id
              JOIN reunion_participants rp ON r.id = rp.reunion_id
              WHERE rp.utilisateur_id = :tuteur_id
              AND r.date_reunion >= CURDATE()
              AND r.statut = 'planifiee'
              ORDER BY r.date_reunion ASC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute([':tuteur_id' => $tuteur_id]);
    $reunions_prochaines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calendrier académique
    if (!empty($etudiants)) {
        $site_ids = array_unique(array_column($etudiants, 'site_id'));
        $placeholders = str_repeat('?,', count($site_ids) - 1) . '?';
        
        $query = "SELECT ca.*, s.nom as site_nom, aa.libelle as annee_libelle
                  FROM calendrier_academique ca
                  JOIN sites s ON ca.site_id = s.id
                  JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                  WHERE ca.site_id IN ($placeholders)
                  AND ca.statut = 'planifie'
                  AND ca.date_debut_cours >= CURDATE()
                  ORDER BY ca.date_debut_cours ASC
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute($site_ids);
        $calendrier_academique = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Alertes importantes
    $alertes_importantes = [];
    if (!empty($etudiants)) {
        // Alertes dettes
        if ($stats['total_dettes'] > 0) {
            $alertes_importantes[] = [
                'type' => 'danger',
                'titre' => 'Dettes en cours',
                'message' => $stats['total_etudiants'] . ' étudiant(s) ont des dettes totalisant ' . formatMoney($stats['total_dettes'])
            ];
        }
        
        // Alertes absences
        $absents_aujourdhui = $stats['total_etudiants'] - $stats['presences_aujourdhui'];
        if ($absents_aujourdhui > 0) {
            $alertes_importantes[] = [
                'type' => 'warning',
                'titre' => 'Absences aujourd\'hui',
                'message' => $absents_aujourdhui . ' étudiant(s) absent(s) aujourd\'hui'
            ];
        }
        
        // Alertes notes basses
        foreach ($performances as $performance) {
            if ($performance['moyenne'] !== null && $performance['moyenne'] < 10) {
                $alertes_importantes[] = [
                    'type' => 'warning',
                    'titre' => 'Note faible',
                    'message' => $performance['etudiant'] . ' a une moyenne de ' . number_format($performance['moyenne'], 2) . '/20'
                ];
            }
        }
        
        // Alertes réunions proches
        foreach ($reunions_prochaines as $reunion) {
            $date_reunion = new DateTime($reunion['date_reunion']);
            $today = new DateTime();
            $interval = $today->diff($date_reunion);
            
            if ($interval->days <= 2) {
                $alertes_importantes[] = [
                    'type' => 'info',
                    'titre' => 'Réunion proche',
                    'message' => 'Réunion "' . $reunion['titre'] . '" dans ' . $interval->days . ' jour(s)'
                ];
            }
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
    
    /* Sidebar */
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
    
    .nav-badge {
        margin-left: auto;
        background: var(--accent-color);
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 10px;
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
    
    /* Stat cards */
    .stat-card {
        text-align: center;
        padding: 20px;
    }
    
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
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
    
    /* Tabs */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .alert-info {
        background-color: rgba(23, 162, 184, 0.1);
        border-left: 4px solid var(--info-color);
    }
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
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
        
        .stat-value {
            font-size: 1.5rem;
        }
    }
    
    /* Boutons */
    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    /* Présence dynamique */
    .presence-indicator {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 5px;
    }
    
    .presence-present { background-color: var(--success-color); }
    .presence-absent { background-color: var(--accent-color); }
    .presence-retard { background-color: var(--warning-color); }
    
    /* Statut paiement */
    .statut-paiement {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.85em;
        font-weight: 500;
    }
    
    .statut-valide { background-color: #d4edda; color: #155724; }
    .statut-attente { background-color: #fff3cd; color: #856404; }
    .statut-annule { background-color: #f8d7da; color: #721c24; }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-tie"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI TUTORAT</h5>
                <div class="user-role">Tuteur/Parent</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Suivi des étudiants</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de Bord</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion des Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Mes Étudiants</span>
                        <?php if ($stats['total_etudiants'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['total_etudiants']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                        <?php if ($stats['presences_aujourdhui'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['presences_aujourdhui']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes et Résultats</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="messages.php?type=envoyes" class="nav-link">
                        <i class="fas fa-paper-plane"></i>
                        <span>Messages Envoyés</span>
                        <?php if (count($messages_envoyes) > 0): ?>
                        <span class="nav-badge"><?php echo count($messages_envoyes); ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="messages.php?type=recus" class="nav-link">
                        <i class="fas fa-inbox"></i>
                        <span>Messages Reçus</span>
                        <?php if (count($messages_recus) > 0): ?>
                        <span class="nav-badge"><?php echo count($messages_recus); ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="nouveau_message.php" class="nav-link">
                        <i class="fas fa-edit"></i>
                        <span>Nouveau Message</span>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                        <?php if (count($reunions_prochaines) > 0): ?>
                        <span class="nav-badge"><?php echo count($reunions_prochaines); ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances</div>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Dettes</span>
                        <?php if ($stats['total_dettes'] > 0): ?>
                        <span class="nav-badge">!</span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Informations</div>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier</span>
                    </a>
                    <a href="rapports.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques</span>
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
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Tableau de Bord Tuteur
                        </h2>
                        <p class="text-muted mb-0">Suivi des étudiants et statistiques détaillées</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Alertes Importantes -->
            <?php if(!empty($alertes_importantes)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Alertes Importantes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach($alertes_importantes as $alerte): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="alert alert-<?php echo $alerte['type']; ?>">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-<?php echo $alerte['type'] == 'danger' ? 'exclamation-circle' : ($alerte['type'] == 'warning' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="alert-heading"><?php echo htmlspecialchars($alerte['titre']); ?></h6>
                                                <p class="mb-0"><?php echo htmlspecialchars($alerte['message']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section 2: Statistiques Principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_etudiants']; ?></div>
                        <div class="stat-label">Étudiants</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['presences_aujourdhui']; ?></div>
                        <div class="stat-label">Présences Aujourd'hui</div>
                        <?php if($stats['total_etudiants'] > 0): ?>
                        <div class="stat-change">
                            <?php echo number_format(($stats['presences_aujourdhui'] / $stats['total_etudiants']) * 100, 0); ?>% de présence
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['total_dettes']); ?></div>
                        <div class="stat-label">Total Dettes</div>
                        <?php if(!empty($dettes_detail)): ?>
                        <div class="stat-change">
                            <?php echo count($dettes_detail); ?> étudiant(s) concerné(s)
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['moyenne_generale'], 2); ?></div>
                        <div class="stat-label">Moyenne Générale</div>
                        <div class="stat-change">
                            <?php echo $stats['moyenne_generale'] >= 10 ? 'Satisfaisant' : 'À améliorer'; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets pour différentes vues -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="dashboardTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="etudiants-tab" data-bs-toggle="tab" data-bs-target="#etudiants" type="button">
                                <i class="fas fa-user-graduate me-2"></i>Étudiants
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="presences-tab" data-bs-toggle="tab" data-bs-target="#presences" type="button">
                                <i class="fas fa-calendar-check me-2"></i>Présences
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="finances-tab" data-bs-toggle="tab" data-bs-target="#finances" type="button">
                                <i class="fas fa-chart-line me-2"></i>Finances
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="communication-tab" data-bs-toggle="tab" data-bs-target="#communication" type="button">
                                <i class="fas fa-comments me-2"></i>Communication
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboardTabsContent">
                        <!-- Tab 1: Étudiants -->
                        <div class="tab-pane fade show active" id="etudiants">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-list me-2"></i>Liste des Étudiants</h5>
                                    <?php if(empty($etudiants)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun étudiant n'est lié à votre compte tuteur
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matricule</th>
                                                    <th>Nom & Prénom</th>
                                                    <th>Filière</th>
                                                    <th>Niveau</th>
                                                    <th>Site</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($etudiants as $etudiant): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($etudiant['matricule']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($etudiant['nom'] . ' ' . $etudiant['prenom']); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($etudiant['niveau_libelle'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($etudiant['site_nom']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-bar me-2"></i>Performance des Étudiants</h5>
                                    <?php if(empty($performances)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune donnée de performance disponible
                                    </div>
                                    <?php else: ?>
                                    <canvas id="performanceChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('performanceChart').getContext('2d');
                                        const labels = [<?php foreach($performances as $perf): ?>'<?php echo addslashes($perf['etudiant']); ?>',<?php endforeach; ?>];
                                        const data = [<?php foreach($performances as $perf): ?><?php echo $perf['moyenne'] ?? 0; ?>,<?php endforeach; ?>];
                                        
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: labels,
                                                datasets: [{
                                                    label: 'Moyenne /20',
                                                    data: data,
                                                    backgroundColor: data.map(value => 
                                                        value >= 10 ? '#27ae60' : 
                                                        value >= 8 ? '#f39c12' : 
                                                        '#e74c3c'
                                                    ),
                                                    borderColor: '#2c3e50',
                                                    borderWidth: 1
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        max: 20,
                                                        title: {
                                                            display: true,
                                                            text: 'Note /20'
                                                        }
                                                    }
                                                },
                                                plugins: {
                                                    legend: {
                                                        display: false
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-star me-2"></i>Notes Récentes</h5>
                                    <?php if(empty($notes_recentes)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune note récente disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Matière</th>
                                                    <th>Type</th>
                                                    <th>Note</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($notes_recentes as $note): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($note['nom'] . ' ' . $note['prenom']); ?><br>
                                                        <small><?php echo htmlspecialchars($note['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($note['matiere_nom']); ?></td>
                                                    <td><?php echo htmlspecialchars($note['type_examen']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $note['note'] >= 10 ? 'success' : ($note['note'] >= 8 ? 'warning' : 'danger'); ?>">
                                                            <?php echo number_format($note['note'], 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatDateFr($note['date_evaluation']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar-alt me-2"></i>Calendrier Académique</h5>
                                    <?php if(empty($calendrier_academique)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun événement à venir
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($calendrier_academique as $event): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">Semestre <?php echo $event['semestre']; ?> - <?php echo $event['type_rentree']; ?></h6>
                                                <small>Début: <?php echo formatDateFr($event['date_debut_cours']); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Site: <?php echo htmlspecialchars($event['site_nom']); ?></small><br>
                                                <small>Fin: <?php echo formatDateFr($event['date_fin_cours']); ?></small>
                                            </p>
                                            <?php if($event['date_debut_examens']): ?>
                                            <small class="text-muted">
                                                Examens: <?php echo formatDateFr($event['date_debut_examens']); ?> - <?php echo formatDateFr($event['date_fin_examens']); ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Présences -->
                        <div class="tab-pane fade" id="presences">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-calendar-day me-2"></i>Présences Aujourd'hui</h5>
                                    <?php if(empty($presences_detaillees)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune présence enregistrée aujourd'hui
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Heure</th>
                                                    <th>Type</th>
                                                    <th>Matière</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($presences_detaillees as $presence): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?><br>
                                                        <small><?php echo htmlspecialchars($presence['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo date('H:i', strtotime($presence['date_heure'])); ?></td>
                                                    <td><?php echo htmlspecialchars($presence['type_presence']); ?></td>
                                                    <td><?php echo htmlspecialchars($presence['matiere_nom'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = '';
                                                        switch($presence['statut']) {
                                                            case 'present': $badge_class = 'bg-success'; break;
                                                            case 'absent': $badge_class = 'bg-danger'; break;
                                                            case 'retard': $badge_class = 'bg-warning'; break;
                                                            case 'justifie': $badge_class = 'bg-info'; break;
                                                            default: $badge_class = 'bg-secondary';
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            <?php echo ucfirst($presence['statut']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-pie me-2"></i>Statistiques de Présence</h5>
                                    <?php if(empty($performances)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune statistique disponible
                                    </div>
                                    <?php else: ?>
                                    <canvas id="presenceChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('presenceChart').getContext('2d');
                                        const labels = [<?php foreach($performances as $perf): ?>'<?php echo addslashes($perf['etudiant']); ?>',<?php endforeach; ?>];
                                        const presents = [<?php foreach($performances as $perf): ?><?php echo $perf['jours_presents'] ?? 0; ?>,<?php endforeach; ?>];
                                        const jours = [<?php foreach($performances as $perf): ?><?php echo $perf['jours_presence'] ?? 0; ?>,<?php endforeach; ?>];
                                        
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: labels,
                                                datasets: [
                                                    {
                                                        label: 'Jours Présents',
                                                        data: presents,
                                                        backgroundColor: '#27ae60',
                                                        borderColor: '#219653',
                                                        borderWidth: 1
                                                    },
                                                    {
                                                        label: 'Jours Total',
                                                        data: jours,
                                                        backgroundColor: '#3498db',
                                                        borderColor: '#2980b9',
                                                        borderWidth: 1
                                                    }
                                                ]
                                            },
                                            options: {
                                                responsive: true,
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        title: {
                                                            display: true,
                                                            text: 'Nombre de jours'
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-table me-2"></i>Tableau de Présence Dynamique</h5>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Ce tableau affiche les présences en temps réel
                                    </div>
                                    
                                    <div class="card">
                                        <div class="card-header">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <h6 class="mb-0">Présence des étudiants - <?php echo date('d/m/Y'); ?></h6>
                                                </div>
                                                <div class="col-auto">
                                                    <div class="d-flex align-items-center">
                                                        <span class="presence-indicator presence-present me-2"></span>
                                                        <small class="me-3">Présent</small>
                                                        <span class="presence-indicator presence-absent me-2"></span>
                                                        <small class="me-3">Absent</small>
                                                        <span class="presence-indicator presence-retard me-2"></span>
                                                        <small>Retard</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <?php if(empty($etudiants)): ?>
                                            <div class="text-center py-4">
                                                <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">Aucun étudiant à afficher</p>
                                            </div>
                                            <?php else: ?>
                                            <div class="row">
                                                <?php 
                                                // Créer un tableau des présences par étudiant pour aujourd'hui
                                                $presences_par_etudiant = [];
                                                foreach($presences_detaillees as $presence) {
                                                    $presences_par_etudiant[$presence['etudiant_id']] = $presence['statut'];
                                                }
                                                ?>
                                                <?php foreach($etudiants as $etudiant): 
                                                    $statut_presence = $presences_par_etudiant[$etudiant['id']] ?? 'absent';
                                                    $indicator_class = '';
                                                    switch($statut_presence) {
                                                        case 'present': $indicator_class = 'presence-present'; break;
                                                        case 'absent': $indicator_class = 'presence-absent'; break;
                                                        case 'retard': $indicator_class = 'presence-retard'; break;
                                                        default: $indicator_class = 'presence-absent';
                                                    }
                                                ?>
                                                <div class="col-md-4 col-lg-3 mb-3">
                                                    <div class="card">
                                                        <div class="card-body text-center p-3">
                                                            <div class="mb-2">
                                                                <span class="presence-indicator <?php echo $indicator_class; ?>"></span>
                                                            </div>
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . substr($etudiant['nom'], 0, 1)); ?>.</h6>
                                                            <small class="text-muted d-block"><?php echo htmlspecialchars($etudiant['matricule']); ?></small>
                                                            <small class="text-muted d-block"><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'N/A'); ?></small>
                                                            <div class="mt-2">
                                                                <span class="badge bg-<?php echo $statut_presence == 'present' ? 'success' : ($statut_presence == 'retard' ? 'warning' : 'danger'); ?>">
                                                                    <?php echo ucfirst($statut_presence); ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Finances -->
                        <div class="tab-pane fade" id="finances">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-invoice-dollar me-2"></i>Dettes des Étudiants</h5>
                                    <?php if(empty($dettes_detail)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune dette enregistrée
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Montant dû</th>
                                                    <th>Restant</th>
                                                    <th>Date limite</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($dettes_detail as $dette): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($dette['nom'] . ' ' . $dette['prenom']); ?><br>
                                                        <small><?php echo htmlspecialchars($dette['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo formatMoney($dette['montant_du']); ?></td>
                                                    <td>
                                                        <strong><?php echo formatMoney($dette['montant_restant']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php if($dette['date_limite']): ?>
                                                        <?php echo formatDateFr($dette['date_limite']); ?>
                                                        <?php else: ?>
                                                        <span class="text-muted">Non définie</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo getStatutBadge($dette['statut']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-4">
                                        <a href="nouveau_paiement.php" class="btn btn-success">
                                            <i class="fas fa-money-bill-wave me-2"></i>Effectuer un Paiement
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-history me-2"></i>Historique des Paiements</h5>
                                    <?php if(empty($paiements_recents)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun paiement récent
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Type</th>
                                                    <th>Montant</th>
                                                    <th>Date</th>
                                                    <th>Mode</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($paiements_recents as $paiement): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($paiement['nom'] . ' ' . $paiement['prenom']); ?><br>
                                                        <small><?php echo htmlspecialchars($paiement['matricule']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($paiement['type_frais']); ?></td>
                                                    <td><?php echo formatMoney($paiement['montant']); ?></td>
                                                    <td><?php echo formatDateFr($paiement['date_paiement']); ?></td>
                                                    <td><?php echo htmlspecialchars($paiement['mode_paiement']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $statut_class = '';
                                                        switch($paiement['statut']) {
                                                            case 'valide': $statut_class = 'statut-valide'; break;
                                                            case 'en_attente': $statut_class = 'statut-attente'; break;
                                                            case 'annule': $statut_class = 'statut-annule'; break;
                                                        }
                                                        ?>
                                                        <span class="statut-paiement <?php echo $statut_class; ?>">
                                                            <?php echo ucfirst($paiement['statut']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-line me-2"></i>Statistiques Financières</h5>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-success">
                                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                                    </div>
                                                    <h5 class="mt-2">
                                                        <?php 
                                                        $total_paiements = 0;
                                                        foreach($paiements_recents as $paiement) {
                                                            $total_paiements += $paiement['montant'];
                                                        }
                                                        echo formatMoney($total_paiements);
                                                        ?>
                                                    </h5>
                                                    <p class="text-muted">Total Paiements</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-danger">
                                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                                    </div>
                                                    <h5 class="mt-2"><?php echo count($dettes_detail); ?></h5>
                                                    <p class="text-muted">Étudiants Endettés</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Communication -->
                        <div class="tab-pane fade" id="communication">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-inbox me-2"></i>Messages Reçus</h5>
                                    <?php if(empty($messages_recus)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun message reçu
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($messages_recus as $message): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php if($message['type_message'] == 'urgence'): ?>
                                                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                                                    <?php elseif($message['type_message'] == 'annonce'): ?>
                                                    <i class="fas fa-bullhorn text-warning me-2"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-envelope text-primary me-2"></i>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($message['sujet']); ?>
                                                </h6>
                                                <small><?php echo formatDateFr($message['date_envoi'], 'd/m/Y H:i'); ?></small>
                                            </div>
                                            <p class="mb-1"><?php echo htmlspecialchars(substr($message['contenu'], 0, 100)) . '...'; ?></p>
                                            <small>De: <?php echo htmlspecialchars($message['expediteur_nom'] . ' ' . $message['expediteur_prenom']); ?></small>
                                            <?php if(!$message['lu']): ?>
                                            <span class="badge bg-danger float-end">Nouveau</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3">
                                        <a href="nouveau_message.php" class="btn btn-primary">
                                            <i class="fas fa-edit me-2"></i>Nouveau Message
                                        </a>
                                        <a href="messages.php?type=recus" class="btn btn-outline-primary">
                                            <i class="fas fa-inbox me-2"></i>Voir tous les messages
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-users me-2"></i>Réunions à Venir</h5>
                                    <?php if(empty($reunions_prochaines)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucune réunion planifiée
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($reunions_prochaines as $reunion): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($reunion['titre']); ?></h6>
                                                <small><?php echo formatDateFr($reunion['date_reunion'], 'd/m/Y H:i'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Type: <?php echo htmlspecialchars($reunion['type_reunion']); ?></small><br>
                                                <small>Lieu: <?php echo htmlspecialchars($reunion['lieu']); ?></small><br>
                                                <small>Organisateur: <?php echo htmlspecialchars($reunion['organisateur_nom'] . ' ' . $reunion['organisateur_prenom']); ?></small>
                                            </p>
                                            <div class="mt-2">
                                                <span class="badge bg-info">À confirmer</span>
                                                <small class="text-muted ms-2">
                                                    <?php 
                                                    $date_reunion = new DateTime($reunion['date_reunion']);
                                                    $today = new DateTime();
                                                    $interval = $today->diff($date_reunion);
                                                    echo 'Dans ' . $interval->days . ' jour(s)';
                                                    ?>
                                                </small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-paper-plane me-2"></i>Messages Envoyés</h5>
                                    <?php if(empty($messages_envoyes)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun message envoyé
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($messages_envoyes as $message): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <i class="fas fa-share text-success me-2"></i>
                                                    <?php echo htmlspecialchars($message['sujet']); ?>
                                                </h6>
                                                <small><?php echo formatDateFr($message['date_envoi'], 'd/m/Y H:i'); ?></small>
                                            </div>
                                            <p class="mb-1"><?php echo htmlspecialchars(substr($message['contenu'], 0, 80)) . '...'; ?></p>
                                            <small>À: <?php echo htmlspecialchars($message['destinataire_nom'] . ' ' . $message['destinataire_prenom']); ?></small>
                                            <?php if($message['lu']): ?>
                                            <span class="badge bg-success float-end">Lu</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning float-end">Non lu</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Actions Rapides -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="nouveau_message.php?dest=gestionnaire" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-envelope me-2"></i>Message Gestionnaire
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="nouveau_paiement.php" class="btn btn-outline-success w-100">
                                        <i class="fas fa-money-bill-wave me-2"></i>Nouveau Paiement
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="reunions.php?action=creer" class="btn btn-outline-info w-100">
                                        <i class="fas fa-calendar-plus me-2"></i>Demander Réunion
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="rapports.php" class="btn btn-outline-warning w-100">
                                        <i class="fas fa-file-pdf me-2"></i>Générer Rapport
                                    </a>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="notes.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-chart-line me-2"></i>Consulter Notes
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="presences.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-calendar-check me-2"></i>Voir Présences
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="calendrier.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-calendar-alt me-2"></i>Calendrier
                                    </a>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <a href="etudiants.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-user-graduate me-2"></i>Fiches Étudiants
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Résumé
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <h6><i class="fas fa-user-tie text-primary me-2"></i> Statut Tuteur</h6>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> Compte actif
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <h6><i class="fas fa-user-graduate text-success me-2"></i> Étudiants</h6>
                                <p class="mb-1">Nombre: <strong><?php echo $stats['total_etudiants']; ?></strong></p>
                                <p class="mb-1">Présents aujourd'hui: <strong><?php echo $stats['presences_aujourdhui']; ?></strong></p>
                                <p class="mb-0">Moyenne générale: <strong><?php echo number_format($stats['moyenne_generale'], 2); ?>/20</strong></p>
                            </div>
                            
                            <div class="mb-3">
                                <h6><i class="fas fa-money-bill-wave text-warning me-2"></i> Finances</h6>
                                <p class="mb-1">Dettes totales: <strong><?php echo formatMoney($stats['total_dettes']); ?></strong></p>
                                <p class="mb-0">Étudiants endettés: <strong><?php echo count($dettes_detail); ?></strong></p>
                            </div>
                            
                            <div>
                                <h6><i class="fas fa-bell text-danger me-2"></i> Notifications</h6>
                                <p class="mb-1">Messages non lus: <strong><?php echo count(array_filter($messages_recus, fn($m) => !$m['lu'])); ?></strong></p>
                                <p class="mb-0">Réunions à venir: <strong><?php echo count($reunions_prochaines); ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Récupérer le thème sauvegardé ou utiliser 'light' par défaut
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Mettre à jour le bouton
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Actualiser automatiquement les présences toutes les 30 secondes
        setInterval(() => {
            const activeTab = document.querySelector('#dashboardTabsContent .tab-pane.active');
            if (activeTab && activeTab.id === 'presences') {
                // Simuler une actualisation des données
                const timeElements = document.querySelectorAll('#presences .table td:nth-child(2)');
                timeElements.forEach(element => {
                    const now = new Date();
                    element.textContent = now.getHours().toString().padStart(2, '0') + ':' + 
                                        now.getMinutes().toString().padStart(2, '0');
                });
            }
        }, 30000);
    });
    
    // Fonction pour envoyer un message rapide
    function envoyerMessageRapide(destinataire) {
        const sujet = prompt('Sujet du message:');
        if (sujet) {
            const message = prompt('Contenu du message:');
            if (message) {
                // Simuler l'envoi
                alert('Message envoyé à ' + destinataire + ' avec succès!');
                // En réalité, vous feriez une requête AJAX ici
            }
        }
    }
    </script>
</body>
</html>