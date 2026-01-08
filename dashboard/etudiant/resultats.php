<?php
// dashboard/etudiant/resultats.php

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

// Vérifier que l'utilisateur est bien un étudiant
if ($_SESSION['role_id'] != 8) { // 8 = Étudiant
    header('Location: ' . ROOT_PATH . '/dashboard/access_denied.php');
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
    $pageTitle = "Résultats Académiques";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format(floatval($amount), 0, ',', ' ') . ' FCFA';
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'valide':
            case 'admis':
            case 'present':
                return '<span class="badge bg-success">Validé</span>';
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'rattrapage':
                return '<span class="badge bg-info">Rattrapage</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getDecisionBadge($decision) {
        $decision = strval($decision);
        switch ($decision) {
            case 'admis':
                return '<span class="badge bg-success">Admis</span>';
            case 'ajourne':
                return '<span class="badge bg-warning">Ajourné</span>';
            case 'redouble':
                return '<span class="badge bg-danger">Redouble</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($decision) . '</span>';
        }
    }
    
    function getMention($moyenne) {
        $moyenne = floatval($moyenne);
        if ($moyenne >= 16) return 'Très Bien';
        if ($moyenne >= 14) return 'Bien';
        if ($moyenne >= 12) return 'Assez Bien';
        if ($moyenne >= 10) return 'Passable';
        return 'Insuffisant';
    }
    
    function getMentionClass($moyenne) {
        $moyenne = floatval($moyenne);
        if ($moyenne >= 16) return 'mention-tb';
        if ($moyenne >= 14) return 'mention-b';
        if ($moyenne >= 12) return 'mention-ab';
        if ($moyenne >= 10) return 'mention-p';
        return 'mention-i';
    }
    
    // Fonction sécurisée pour afficher du texte
    function safeHtml($text) {
        if ($text === null || $text === '') {
            return '';
        }
        return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Étudiant';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? intval($_SESSION['role_id']) : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? intval($_SESSION['site_id']) : null;
        }
        
        public static function getUserId() {
            return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        }
        
        public static function getEtudiantId() {
            return isset($_SESSION['etudiant_id']) ? intval($_SESSION['etudiant_id']) : null;
        }
    }
    
    // Récupérer l'ID de l'étudiant
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    
    // Initialiser toutes les variables
    $info_etudiant = array();
    $bulletins = array();
    $notes_detaillees = array();
    $resultats_semestres = array();
    $moyennes_generales = array();
    $statistiques = array();
    $rattrapages = array();
    $documents_resultats = array();
    $historique_annuel = array();
    $error = null;
    
    // Fonction pour exécuter les requêtes en toute sécurité
    function executeQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            return array();
        }
    }
    
    function executeSingleQuery($db, $query, $params = array()) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: array();
        } catch (Exception $e) {
            error_log("Single query error: " . $e->getMessage());
            return array();
        }
    }
    
    // Récupérer les informations de l'étudiant
    $info_etudiant = executeSingleQuery($db, 
        "SELECT e.*, s.nom as site_nom, c.id as classe_id, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle,
                aa.libelle as annee_academique_actuelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         LEFT JOIN annees_academiques aa ON c.annee_academique_id = aa.id AND aa.statut = 'active'
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        $site_id = intval($info_etudiant['site_id'] ?? 0);
        $classe_id = intval($info_etudiant['classe_id'] ?? 0);
        
        // Récupérer les bulletins de notes
        $bulletins = executeQuery($db,
            "SELECT b.*, aa.libelle as annee_academique, s.numero as semestre_numero,
                    CONCAT(u.nom, ' ', u.prenom) as editeur_nom,
                    CONCAT(uv.nom, ' ', uv.prenom) as validateur_nom,
                    (SELECT COUNT(*) FROM notes n 
                     JOIN matieres m ON n.matiere_id = m.id
                     WHERE n.etudiant_id = b.etudiant_id 
                     AND n.annee_academique_id = b.annee_academique_id
                     AND n.semestre_id = b.semestre_id) as nb_matieres
             FROM bulletins b
             JOIN annees_academiques aa ON b.annee_academique_id = aa.id
             JOIN semestres s ON b.semestre_id = s.id
             LEFT JOIN utilisateurs u ON b.edite_par = u.id
             LEFT JOIN utilisateurs uv ON b.valide_par = uv.id
             WHERE b.etudiant_id = ? 
             ORDER BY aa.libelle DESC, s.numero DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les notes détaillées pour l'année en cours
        $notes_detaillees = executeQuery($db,
            "SELECT n.*, m.nom as matiere_nom, m.code as matiere_code,
                    m.coefficient, m.credit, te.nom as type_examen,
                    te.pourcentage, aa.libelle as annee_academique,
                    s.numero as semestre_numero,
                    CONCAT(e.nom, ' ', e.prenom) as enseignant_nom,
                    CASE 
                        WHEN n.note >= 16 THEN 'excellent'
                        WHEN n.note >= 12 THEN 'bon'
                        WHEN n.note >= 10 THEN 'moyen'
                        ELSE 'insuffisant'
                    END as appreciation,
                    CASE 
                        WHEN n.note >= 16 THEN 'success'
                        WHEN n.note >= 12 THEN 'info'
                        WHEN n.note >= 10 THEN 'warning'
                        ELSE 'danger'
                    END as appreciation_color
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN types_examens te ON n.type_examen_id = te.id
             JOIN annees_academiques aa ON n.annee_academique_id = aa.id
             JOIN semestres s ON n.semestre_id = s.id
             LEFT JOIN enseignants en ON n.evaluateur_id = en.id
             LEFT JOIN utilisateurs e ON en.utilisateur_id = e.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'valide'
             AND aa.statut = 'active'
             ORDER BY s.numero, m.nom, te.nom
             LIMIT 50",
            [$etudiant_id]);
        
        // Récupérer les résultats par semestre
        $resultats_semestres = executeQuery($db,
            "SELECT aa.libelle as annee_academique, s.numero as semestre,
                    s.date_debut, s.date_fin,
                    AVG(n.note) as moyenne_semestre,
                    COUNT(DISTINCT n.matiere_id) as nb_matieres,
                    SUM(CASE WHEN n.note >= 10 THEN m.credit ELSE 0 END) as credits_obtenus,
                    SUM(m.credit) as credits_totaux
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN annees_academiques aa ON n.annee_academique_id = aa.id
             JOIN semestres s ON n.semestre_id = s.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'valide'
             GROUP BY aa.id, s.id
             ORDER BY aa.libelle DESC, s.numero DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les moyennes générales par année
        $moyennes_generales = executeQuery($db,
            "SELECT aa.libelle as annee_academique,
                    AVG(n.note) as moyenne_annuelle,
                    COUNT(DISTINCT n.matiere_id) as nb_matieres,
                    SUM(m.credit) as credits_totaux,
                    SUM(CASE WHEN n.note >= 10 THEN m.credit ELSE 0 END) as credits_valides,
                    MIN(n.note) as note_min,
                    MAX(n.note) as note_max
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN annees_academiques aa ON n.annee_academique_id = aa.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'valide'
             GROUP BY aa.id
             ORDER BY aa.libelle DESC
             LIMIT 5",
            [$etudiant_id]);
        
        // Récupérer les statistiques globales
        $statistiques = executeSingleQuery($db,
            "SELECT 
                (SELECT AVG(n.note) FROM notes n 
                 JOIN annees_academiques aa ON n.annee_academique_id = aa.id 
                 WHERE n.etudiant_id = ? AND aa.statut = 'active') as moyenne_actuelle,
                
                (SELECT COUNT(*) FROM notes n 
                 JOIN annees_academiques aa ON n.annee_academique_id = aa.id 
                 WHERE n.etudiant_id = ? AND aa.statut = 'active') as total_notes,
                
                (SELECT COUNT(DISTINCT n.matiere_id) FROM notes n 
                 JOIN annees_academiques aa ON n.annee_academique_id = aa.id 
                 WHERE n.etudiant_id = ? AND aa.statut = 'active') as nb_matieres,
                
                (SELECT SUM(m.credit) FROM notes n 
                 JOIN matieres m ON n.matiere_id = m.id
                 JOIN annees_academiques aa ON n.annee_academique_id = aa.id 
                 WHERE n.etudiant_id = ? AND aa.statut = 'active' AND n.note >= 10) as credits_valides,
                
                (SELECT COUNT(*) FROM notes n 
                 WHERE n.etudiant_id = ? AND n.note < 10) as echecs,
                
                (SELECT AVG(n.note) FROM notes n 
                 WHERE n.etudiant_id = ? AND n.note >= 10) as moyenne_succes",
            [$etudiant_id, $etudiant_id, $etudiant_id, $etudiant_id, $etudiant_id, $etudiant_id]);
        
        if (!$statistiques) {
            $statistiques = array(
                'moyenne_actuelle' => 0,
                'total_notes' => 0,
                'nb_matieres' => 0,
                'credits_valides' => 0,
                'echecs' => 0,
                'moyenne_succes' => 0
            );
        }
        
        // Récupérer les matières en rattrapage
        $rattrapages = executeQuery($db,
            "SELECT m.nom as matiere_nom, m.code as matiere_code,
                    MIN(n.note) as note_min, MAX(n.note) as note_max,
                    AVG(n.note) as moyenne_matiere,
                    COUNT(n.id) as nb_notes,
                    COUNT(CASE WHEN n.note < 10 THEN 1 END) as nb_echecs
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN annees_academiques aa ON n.annee_academique_id = aa.id
             WHERE n.etudiant_id = ? 
             AND aa.statut = 'active'
             AND n.statut = 'valide'
             GROUP BY m.id
             HAVING AVG(n.note) < 10
             ORDER BY moyenne_matiere ASC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les documents de résultats
        $documents_resultats = executeQuery($db,
            "SELECT de.*, 
                    CASE 
                        WHEN de.type_document = 'bulletin' THEN 'Bulletin de notes'
                        WHEN de.type_document = 'attestation' THEN 'Attestation de réussite'
                        WHEN de.type_document = 'certificat' THEN 'Certificat de scolarité'
                        WHEN de.type_document = 'releve' THEN 'Relevé de notes'
                        ELSE 'Document académique'
                    END as type_document_libelle,
                    CONCAT(u.nom, ' ', u.prenom) as uploader_nom
             FROM documents_etudiants de
             LEFT JOIN utilisateurs u ON de.upload_par = u.id
             WHERE de.etudiant_id = ? 
             AND de.statut = 'valide'
             AND de.type_document IN ('bulletin', 'attestation', 'certificat', 'releve')
             ORDER BY de.date_upload DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer l'historique annuel
        $historique_annuel = executeQuery($db,
            "SELECT aa.libelle as annee_academique,
                    aa.date_debut, aa.date_fin,
                    (SELECT COUNT(DISTINCT n.matiere_id) FROM notes n 
                     WHERE n.etudiant_id = ? AND n.annee_academique_id = aa.id) as nb_matieres,
                    (SELECT AVG(n.note) FROM notes n 
                     WHERE n.etudiant_id = ? AND n.annee_academique_id = aa.id) as moyenne,
                    (SELECT COUNT(*) FROM bulletins b 
                     WHERE b.etudiant_id = ? AND b.annee_academique_id = aa.id) as nb_bulletins
             FROM annees_academiques aa
             WHERE aa.id IN (SELECT DISTINCT n.annee_academique_id FROM notes n WHERE n.etudiant_id = ?)
             ORDER BY aa.libelle DESC
             LIMIT 5",
            [$etudiant_id, $etudiant_id, $etudiant_id, $etudiant_id]);
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . safeHtml($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safeHtml($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
    /* Réutilisation du même CSS que dashboard.php */
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
    
    /* Sidebar - Même que dashboard.php */
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
    
    .stat-change {
        font-size: 0.85rem;
        margin-top: 5px;
        color: var(--text-muted);
    }
    
    .stat-change.positive {
        color: var(--success-color);
    }
    
    .stat-change.negative {
        color: var(--accent-color);
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
    
    /* Note badges */
    .note-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .note-excellent {
        background-color: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .note-good {
        background-color: rgba(52, 152, 219, 0.2);
        color: var(--secondary-color);
    }
    
    .note-average {
        background-color: rgba(243, 156, 18, 0.2);
        color: var(--warning-color);
    }
    
    .note-poor {
        background-color: rgba(231, 76, 60, 0.2);
        color: var(--accent-color);
    }
    
    /* Mention classes */
    .mention-tb {
        color: #9b59b6;
        font-weight: bold;
    }
    
    .mention-b {
        color: #3498db;
        font-weight: bold;
    }
    
    .mention-ab {
        color: #2ecc71;
        font-weight: bold;
    }
    
    .mention-p {
        color: #f39c12;
        font-weight: bold;
    }
    
    .mention-i {
        color: #e74c3c;
        font-weight: bold;
    }
    
    /* Bulletin card */
    .bulletin-card {
        border-left: 4px solid var(--primary-color);
        margin-bottom: 15px;
    }
    
    .bulletin-card.admis {
        border-left-color: var(--success-color);
    }
    
    .bulletin-card.ajourne {
        border-left-color: var(--warning-color);
    }
    
    .bulletin-card.redouble {
        border-left-color: var(--accent-color);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
        height: 10px;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
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
    
    /* Graph container */
    .chart-container {
        position: relative;
        height: 250px;
        width: 100%;
    }
    
    /* Quick actions */
    .quick-action {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .quick-action:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        border-color: var(--primary-color);
    }
    
    .quick-action i {
        font-size: 2rem;
        margin-bottom: 10px;
        color: var(--primary-color);
    }
    
    .quick-action .title {
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .quick-action .description {
        font-size: 0.85rem;
        color: var(--text-muted);
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
                <h5 class="mt-2 mb-1">ISGI</h5>
                <div class="user-role">Étudiant</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo safeHtml(SessionManager::getUserName()); ?></p>
                <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                <small>Matricule: <?php echo safeHtml($info_etudiant['matricule']); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens & Concours</span>
                    </a>
                    <a href="resultats.php" class="nav-link active">
                        <i class="fas fa-poll"></i>
                        <span>Résultats</span>
                    </a>
                    <a href="rattrapages.php" class="nav-link">
                        <i class="fas fa-redo-alt"></i>
                        <span>Rattrapages</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Documents</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="documents.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Mes Documents</span>
                    </a>
                    <a href="bulletins.php" class="nav-link">
                        <i class="fas fa-file-pdf"></i>
                        <span>Bulletins</span>
                    </a>
                    <a href="attestations.php" class="nav-link">
                        <i class="fas fa-certificate"></i>
                        <span>Attestations</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="parametres.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Paramètres</span>
                    </a>
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
                            <i class="fas fa-poll me-2"></i>
                            Résultats Académiques
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['annee_academique_actuelle']) && !empty($info_etudiant['annee_academique_actuelle'])): ?>
                            Année académique: <?php echo safeHtml($info_etudiant['annee_academique_actuelle']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="printPage()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-info" onclick="exportResults()">
                            <i class="fas fa-download"></i> Exporter
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques des résultats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(floatval($statistiques['moyenne_actuelle'] ?? 0), 2); ?>/20</div>
                        <div class="stat-label">Moyenne Actuelle</div>
                        <div class="stat-change">
                            <span class="<?php echo floatval($statistiques['moyenne_actuelle'] ?? 0) >= 10 ? 'positive' : 'negative'; ?>">
                                <?php echo getMention($statistiques['moyenne_actuelle'] ?? 0); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['nb_matieres'] ?? 0); ?></div>
                        <div class="stat-label">Matières Validées</div>
                        <div class="stat-change">
                            <span class="positive">
                                <?php echo intval($statistiques['credits_valides'] ?? 0); ?> crédits
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['echecs'] ?? 0); ?></div>
                        <div class="stat-label">Échecs</div>
                        <div class="stat-change">
                            <?php if(intval($statistiques['echecs'] ?? 0) > 0): ?>
                            <span class="negative"><?php echo count($rattrapages); ?> rattrapage(s) nécessaire(s)</span>
                            <?php else: ?>
                            <span class="positive">Aucun échec</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="stat-value"><?php echo intval($statistiques['total_notes'] ?? 0); ?></div>
                        <div class="stat-label">Notes Enregistrées</div>
                        <div class="stat-change">
                            <span class="positive">
                                <?php echo number_format(floatval($statistiques['moyenne_succes'] ?? 0), 2); ?>/20 moyenne réussites
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="resultsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="bulletins-tab" data-bs-toggle="tab" data-bs-target="#bulletins" type="button">
                                <i class="fas fa-file-alt me-2"></i>Bulletins
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button">
                                <i class="fas fa-clipboard-list me-2"></i>Notes Détaillées
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="statistiques-tab" data-bs-toggle="tab" data-bs-target="#statistiques" type="button">
                                <i class="fas fa-chart-bar me-2"></i>Statistiques
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button">
                                <i class="fas fa-folder-open me-2"></i>Documents
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="resultsTabsContent">
                        <!-- Tab 1: Bulletins -->
                        <div class="tab-pane fade show active" id="bulletins">
                            <?php if(empty($bulletins)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun bulletin disponible pour le moment.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($bulletins as $bulletin): 
                                    $decision_class = '';
                                    switch($bulletin['decision'] ?? '') {
                                        case 'admis': $decision_class = 'admis'; break;
                                        case 'ajourne': $decision_class = 'ajourne'; break;
                                        case 'redouble': $decision_class = 'redouble'; break;
                                        default: $decision_class = '';
                                    }
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card bulletin-card <?php echo $decision_class; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="card-title mb-1">
                                                        Bulletin S<?php echo safeHtml($bulletin['semestre_numero'] ?? ''); ?>
                                                    </h5>
                                                    <p class="text-muted mb-0">
                                                        <?php echo safeHtml($bulletin['annee_academique'] ?? ''); ?>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <?php echo getDecisionBadge($bulletin['decision'] ?? ''); ?>
                                                    <?php if(!empty($bulletin['qr_code_url'])): ?>
                                                    <a href="<?php echo safeHtml($bulletin['qr_code_url']); ?>" 
                                                       class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                                                        <i class="fas fa-qrcode"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Moyenne Générale</small>
                                                    <h3 class="mb-0 <?php echo getMentionClass($bulletin['moyenne_generale'] ?? 0); ?>">
                                                        <?php echo number_format(floatval($bulletin['moyenne_generale'] ?? 0), 2); ?>/20
                                                    </h3>
                                                    <small class="text-muted">
                                                        <?php echo getMention($bulletin['moyenne_generale'] ?? 0); ?>
                                                    </small>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Classement</small>
                                                    <h4 class="mb-0">
                                                        <?php echo intval($bulletin['rang'] ?? 0); ?>e / <?php echo intval($bulletin['effectif_classe'] ?? 0); ?>
                                                    </h4>
                                                    <small class="text-muted">
                                                        <?php echo intval($bulletin['nb_matieres'] ?? 0); ?> matière(s)
                                                    </small>
                                                </div>
                                            </div>
                                            
                                            <?php if(!empty($bulletin['appreciation'])): ?>
                                            <div class="mb-3">
                                                <small class="text-muted d-block">Appréciation</small>
                                                <p class="mb-0"><?php echo safeHtml($bulletin['appreciation']); ?></p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex justify-content-between align-items-center mt-3">
                                                <div>
                                                    <small class="text-muted">
                                                        Édité le: <?php echo formatDateFr($bulletin['date_edition'] ?? ''); ?>
                                                        <?php if(!empty($bulletin['editeur_nom'])): ?>
                                                        <br>Par: <?php echo safeHtml($bulletin['editeur_nom']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewBulletin(<?php echo intval($bulletin['id'] ?? 0); ?>)">
                                                        <i class="fas fa-eye"></i> Voir
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-success" onclick="downloadBulletin(<?php echo intval($bulletin['id'] ?? 0); ?>)">
                                                        <i class="fas fa-download"></i> PDF
                                                    </button>
                                                    <?php if(!empty($bulletin['qr_code'])): ?>
                                                    <button class="btn btn-sm btn-outline-info" onclick="showQR('<?php echo safeHtml($bulletin['qr_code']); ?>')">
                                                        <i class="fas fa-qrcode"></i> QR
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    <?php echo getStatutBadge($bulletin['statut'] ?? ''); ?>
                                                    <?php if(!empty($bulletin['validateur_nom'])): ?>
                                                    <span class="ms-2">Validé par: <?php echo safeHtml($bulletin['validateur_nom']); ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Résultats par semestre -->
                            <h5 class="mt-4 mb-3">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Évolution par Semestre
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Année Académique</th>
                                            <th>Semestre</th>
                                            <th>Période</th>
                                            <th>Moyenne</th>
                                            <th>Matières</th>
                                            <th>Crédits</th>
                                            <th>Validation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($resultats_semestres as $semestre): 
                                            $validation_rate = intval($semestre['credits_totaux'] ?? 0) > 0 
                                                ? (intval($semestre['credits_obtenus'] ?? 0) / intval($semestre['credits_totaux'] ?? 1) * 100) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo safeHtml($semestre['annee_academique'] ?? ''); ?></strong></td>
                                            <td>S<?php echo safeHtml($semestre['semestre'] ?? ''); ?></td>
                                            <td>
                                                <small>
                                                    <?php echo formatDateFr($semestre['date_debut'] ?? ''); ?> - 
                                                    <?php echo formatDateFr($semestre['date_fin'] ?? ''); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="note-badge <?php echo floatval($semestre['moyenne_semestre'] ?? 0) >= 10 ? 'note-good' : 'note-poor'; ?>">
                                                    <?php echo number_format(floatval($semestre['moyenne_semestre'] ?? 0), 2); ?>/20
                                                </span>
                                            </td>
                                            <td><?php echo intval($semestre['nb_matieres'] ?? 0); ?></td>
                                            <td>
                                                <?php echo intval($semestre['credits_obtenus'] ?? 0); ?>/<?php echo intval($semestre['credits_totaux'] ?? 0); ?>
                                                <div class="progress mt-1" style="height: 5px;">
                                                    <div class="progress-bar bg-<?php echo $validation_rate >= 50 ? 'success' : ($validation_rate >= 30 ? 'warning' : 'danger'); ?>" 
                                                         style="width: <?php echo $validation_rate; ?>%"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if($validation_rate >= 50): ?>
                                                <span class="badge bg-success">Validé</span>
                                                <?php elseif($validation_rate >= 30): ?>
                                                <span class="badge bg-warning">Partiel</span>
                                                <?php else: ?>
                                                <span class="badge bg-danger">Non validé</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Notes détaillées -->
                        <div class="tab-pane fade" id="notes">
                            <?php if(empty($notes_detaillees)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune note disponible pour le moment.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matière</th>
                                                    <th>Type</th>
                                                    <th>Note</th>
                                                    <th>Coeff</th>
                                                    <th>Crédits</th>
                                                    <th>Date</th>
                                                    <th>Semestre</th>
                                                    <th>Enseignant</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($notes_detaillees as $note): 
                                                    $note_class = '';
                                                    $note_value = floatval($note['note'] ?? 0);
                                                    if ($note_value >= 16) $note_class = 'note-excellent';
                                                    elseif ($note_value >= 12) $note_class = 'note-good';
                                                    elseif ($note_value >= 10) $note_class = 'note-average';
                                                    else $note_class = 'note-poor';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo safeHtml($note['matiere_nom'] ?? ''); ?></strong><br>
                                                        <small><?php echo safeHtml($note['matiere_code'] ?? ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?php echo safeHtml($note['type_examen'] ?? ''); ?></small><br>
                                                        <span class="badge bg-secondary">
                                                            <?php echo number_format(floatval($note['pourcentage'] ?? 0), 1); ?>%
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="note-badge <?php echo $note_class; ?>">
                                                            <?php echo number_format($note_value, 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td><?php echo number_format(floatval($note['coefficient'] ?? 1.00), 2); ?></td>
                                                    <td><?php echo intval($note['credit'] ?? 0); ?></td>
                                                    <td><?php echo formatDateFr($note['date_evaluation'] ?? ''); ?></td>
                                                    <td>
                                                        <?php echo safeHtml($note['annee_academique'] ?? ''); ?><br>
                                                        <small>S<?php echo safeHtml($note['semestre_numero'] ?? ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if(!empty($note['enseignant_nom'])): ?>
                                                        <small><?php echo safeHtml($note['enseignant_nom']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-filter me-2"></i>
                                                Filtres
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Année académique</label>
                                                <select class="form-select" id="filterAnnee">
                                                    <option value="all">Toutes les années</option>
                                                    <?php foreach($moyennes_generales as $moyenne): ?>
                                                    <option value="<?php echo safeHtml($moyenne['annee_academique'] ?? ''); ?>">
                                                        <?php echo safeHtml($moyenne['annee_academique'] ?? ''); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Semestre</label>
                                                <select class="form-select" id="filterSemestre">
                                                    <option value="all">Tous les semestres</option>
                                                    <option value="1">Semestre 1</option>
                                                    <option value="2">Semestre 2</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Type d'examen</label>
                                                <select class="form-select" id="filterType">
                                                    <option value="all">Tous les types</option>
                                                    <option value="DST">DST</option>
                                                    <option value="Devoir de Recherche">Devoir de Recherche</option>
                                                    <option value="Session">Session</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Statut</label>
                                                <select class="form-select" id="filterStatut">
                                                    <option value="all">Toutes les notes</option>
                                                    <option value="success">≥ 10/20</option>
                                                    <option value="warning">8-10/20</option>
                                                    <option value="danger">&lt; 8/20</option>
                                                </select>
                                            </div>
                                            
                                            <button class="btn btn-primary w-100" onclick="applyFilters()">
                                                <i class="fas fa-filter"></i> Appliquer les filtres
                                            </button>
                                            
                                            <button class="btn btn-outline-secondary w-100 mt-2" onclick="resetFilters()">
                                                <i class="fas fa-redo"></i> Réinitialiser
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-chart-pie me-2"></i>
                                                Répartition des notes
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="notesRepartitionChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 3: Statistiques -->
                        <div class="tab-pane fade" id="statistiques">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5 class="mb-3">
                                        <i class="fas fa-chart-line me-2"></i>
                                        Évolution des Moyennes
                                    </h5>
                                    
                                    <div class="chart-container">
                                        <canvas id="evolutionChart"></canvas>
                                    </div>
                                    
                                    <h5 class="mt-4 mb-3">
                                        <i class="fas fa-trophy me-2"></i>
                                        Performances par Année
                                    </h5>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Année Académique</th>
                                                    <th>Moyenne</th>
                                                    <th>Mention</th>
                                                    <th>Matières</th>
                                                    <th>Crédits</th>
                                                    <th>Min/Max</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($moyennes_generales as $moyenne): 
                                                    $validation_rate = intval($moyenne['credits_totaux'] ?? 0) > 0 
                                                        ? (intval($moyenne['credits_valides'] ?? 0) / intval($moyenne['credits_totaux'] ?? 1) * 100) 
                                                        : 0;
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo safeHtml($moyenne['annee_academique'] ?? ''); ?></strong></td>
                                                    <td>
                                                        <span class="note-badge <?php echo floatval($moyenne['moyenne_annuelle'] ?? 0) >= 10 ? 'note-good' : 'note-poor'; ?>">
                                                            <?php echo number_format(floatval($moyenne['moyenne_annuelle'] ?? 0), 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="<?php echo getMentionClass($moyenne['moyenne_annuelle'] ?? 0); ?>">
                                                            <?php echo getMention($moyenne['moyenne_annuelle'] ?? 0); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo intval($moyenne['nb_matieres'] ?? 0); ?></td>
                                                    <td>
                                                        <?php echo intval($moyenne['credits_valides'] ?? 0); ?>/<?php echo intval($moyenne['credits_totaux'] ?? 0); ?>
                                                        <div class="progress mt-1" style="height: 5px;">
                                                            <div class="progress-bar bg-<?php echo $validation_rate >= 60 ? 'success' : ($validation_rate >= 40 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo $validation_rate; ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?php echo number_format(floatval($moyenne['note_min'] ?? 0), 1); ?> - 
                                                            <?php echo number_format(floatval($moyenne['note_max'] ?? 0), 1); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mb-3">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Matières en Difficulté
                                    </h5>
                                    
                                    <?php if(empty($rattrapages)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune matière en difficulté. Félicitations!
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($rattrapages as $rattrapage): 
                                            $moyenne_matiere = floatval($rattrapage['moyenne_matiere'] ?? 0);
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?></h6>
                                                <span class="badge bg-danger">
                                                    <?php echo number_format($moyenne_matiere, 2); ?>/20
                                                </span>
                                            </div>
                                            <p class="mb-1">
                                                <small>Code: <?php echo safeHtml($rattrapage['matiere_code'] ?? ''); ?></small><br>
                                                <small>Notes: <?php echo intval($rattrapage['nb_notes'] ?? 0); ?> - 
                                                Échecs: <?php echo intval($rattrapage['nb_echecs'] ?? 0); ?></small>
                                            </p>
                                            <div class="mt-2">
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar bg-danger" 
                                                         style="width: <?php echo $moyenne_matiere * 5; ?>%">
                                                        <?php echo number_format($moyenne_matiere, 1); ?>/20
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <button class="btn btn-sm btn-warning" onclick="showRattrapageInfo('<?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?>')">
                                                    <i class="fas fa-redo-alt"></i> Préparer rattrapage
                                                </button>
                                                <button class="btn btn-sm btn-outline-info ms-2" onclick="showResources('<?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?>')">
                                                    <i class="fas fa-book"></i> Ressources
                                                </button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4 mb-3">
                                        <i class="fas fa-history me-2"></i>
                                        Historique Académique
                                    </h5>
                                    
                                    <div class="list-group">
                                        <?php foreach($historique_annuel as $historique): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($historique['annee_academique'] ?? ''); ?></h6>
                                                <span class="badge bg-info">
                                                    <?php echo intval($historique['nb_matieres'] ?? 0); ?> matières
                                                </span>
                                            </div>
                                            <p class="mb-1">
                                                <small>
                                                    Période: <?php echo formatDateFr($historique['date_debut'] ?? ''); ?> - 
                                                    <?php echo formatDateFr($historique['date_fin'] ?? ''); ?>
                                                </small>
                                            </p>
                                            <p class="mb-1">
                                                <small>
                                                    Moyenne: <?php echo number_format(floatval($historique['moyenne'] ?? 0), 2); ?>/20
                                                    • Bulletins: <?php echo intval($historique['nb_bulletins'] ?? 0); ?>
                                                </small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="card mt-4">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-lightbulb me-2"></i>
                                                Conseils pour Amélioration
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-info">
                                                <h6><i class="fas fa-book"></i> Pour les matières en difficulté</h6>
                                                <ul class="mb-0 small">
                                                    <li>Assistez aux séances de rattrapage</li>
                                                    <li>Consultez les annales d'examens</li>
                                                    <li>Demandez l'aide des enseignants</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="alert alert-warning">
                                                <h6><i class="fas fa-chart-line"></i> Pour améliorer votre moyenne</h6>
                                                <ul class="mb-0 small">
                                                    <li>Concentrez-vous sur les matières à fort coefficient</li>
                                                    <li>Préparez mieux les examens à fort pourcentage</li>
                                                    <li>Participez activement aux travaux pratiques</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Documents -->
                        <div class="tab-pane fade" id="documents">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5 class="mb-3">
                                        <i class="fas fa-folder-open me-2"></i>
                                        Documents Académiques
                                    </h5>
                                    
                                    <?php if(empty($documents_resultats)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun document académique disponible.
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Nom du fichier</th>
                                                    <th>Taille</th>
                                                    <th>Date</th>
                                                    <th>Uploadé par</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($documents_resultats as $document): 
                                                    $file_size = intval($document['taille_fichier'] ?? 0);
                                                    $size_text = $file_size < 1024 ? $file_size . ' octets' : 
                                                                ($file_size < 1048576 ? round($file_size / 1024, 1) . ' Ko' : 
                                                                round($file_size / 1048576, 1) . ' Mo');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-primary">
                                                            <?php echo safeHtml($document['type_document_libelle'] ?? ''); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo safeHtml($document['nom_fichier'] ?? ''); ?></strong>
                                                        <?php if(!empty($document['qr_code'])): ?>
                                                        <br><small><i class="fas fa-qrcode text-info"></i> QR Code disponible</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $size_text; ?></td>
                                                    <td><?php echo formatDateFr($document['date_upload'] ?? ''); ?></td>
                                                    <td>
                                                        <?php if(!empty($document['uploader_nom'])): ?>
                                                        <small><?php echo safeHtml($document['uploader_nom']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if(!empty($document['chemin_fichier'])): ?>
                                                            <a href="<?php echo safeHtml($document['chemin_fichier']); ?>" 
                                                               class="btn btn-outline-primary" target="_blank">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="<?php echo safeHtml($document['chemin_fichier']); ?>" 
                                                               class="btn btn-outline-success" download>
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if(!empty($document['qr_code'])): ?>
                                                            <button class="btn btn-outline-info" onclick="showQR('<?php echo safeHtml($document['qr_code']); ?>')">
                                                                <i class="fas fa-qrcode"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="alert alert-warning mt-4">
                                        <h6><i class="fas fa-exclamation-circle"></i> Information importante</h6>
                                        <p class="mb-0 small">
                                            Les documents officiels (bulletins, attestations) sont généralement publiés dans les 15 jours 
                                            suivant la validation des résultats. En cas de document manquant, veuillez contacter 
                                            le service académique.
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-print me-2"></i>
                                                Impression & Export
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <button class="btn btn-primary w-100 mb-2" onclick="printReleveNotes()">
                                                    <i class="fas fa-print"></i> Imprimer Relevé de Notes
                                                </button>
                                                
                                                <button class="btn btn-success w-100 mb-2" onclick="exportPDF()">
                                                    <i class="fas fa-file-pdf"></i> Exporter en PDF
                                                </button>
                                                
                                                <button class="btn btn-info w-100 mb-2" onclick="exportExcel()">
                                                    <i class="fas fa-file-excel"></i> Exporter en Excel
                                                </button>
                                                
                                                <button class="btn btn-warning w-100" onclick="requestDocument()">
                                                    <i class="fas fa-file-alt"></i> Demander un Document
                                                </button>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <h6><i class="fas fa-info-circle"></i> À propos des documents</h6>
                                                <ul class="mb-0 small">
                                                    <li><strong>Bulletin:</strong> Résultats par semestre</li>
                                                    <li><strong>Attestation:</strong> Document de réussite</li>
                                                    <li><strong>Certificat:</strong> Preuve de scolarité</li>
                                                    <li><strong>Relevé:</strong> Détail complet des notes</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="fas fa-question-circle me-2"></i>
                                                Aide & Support
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <h6><i class="fas fa-phone-alt"></i> Contacts</h6>
                                                <ul class="mb-0 small">
                                                    <li><strong>Service académique:</strong> +242 XX XX XX XX</li>
                                                    <li><strong>Secrétariat pédagogique:</strong> +242 XX XX XX XX</li>
                                                    <li><strong>Email résultats:</strong> resultats@isgi.cg</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <h6><i class="fas fa-clock"></i> Horaires de publication</h6>
                                                <ul class="mb-0 small">
                                                    <li><strong>Résultats partiels:</strong> 72h après examen</li>
                                                    <li><strong>Bulletins semestriels:</strong> 15 jours après session</li>
                                                    <li><strong>Documents officiels:</strong> 30 jours après validation</li>
                                                </ul>
                                            </div>
                                            
                                            <div>
                                                <h6><i class="fas fa-exclamation-triangle"></i> En cas de problème</h6>
                                                <p class="small mb-0">
                                                    Si vous constatez une erreur dans vos résultats, contactez immédiatement 
                                                    le service académique avec vos justificatifs.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Résumé de performance -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-bar me-2"></i>
                                Résumé de Performance
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-trend-up me-2"></i> Progression académique</h6>
                                    <div class="chart-container">
                                        <canvas id="performanceChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-award me-2"></i> Bilan actuel</h6>
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <div class="card bg-light">
                                                <div class="card-body text-center">
                                                    <h3 class="mb-0 <?php echo getMentionClass($statistiques['moyenne_actuelle'] ?? 0); ?>">
                                                        <?php echo number_format(floatval($statistiques['moyenne_actuelle'] ?? 0), 2); ?>
                                                    </h3>
                                                    <small class="text-muted">Moyenne actuelle /20</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card bg-light">
                                                <div class="card-body text-center">
                                                    <h3 class="mb-0 text-success">
                                                        <?php echo intval($statistiques['credits_valides'] ?? 0); ?>
                                                    </h3>
                                                    <small class="text-muted">Crédits validés</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card bg-light">
                                                <div class="card-body text-center">
                                                    <h3 class="mb-0 text-warning">
                                                        <?php echo intval($statistiques['echecs'] ?? 0); ?>
                                                    </h3>
                                                    <small class="text-muted">Échecs à rattraper</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card bg-light">
                                                <div class="card-body text-center">
                                                    <h3 class="mb-0 text-info">
                                                        <?php echo count($bulletins); ?>
                                                    </h3>
                                                    <small class="text-muted">Bulletins disponibles</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert <?php echo floatval($statistiques['moyenne_actuelle'] ?? 0) >= 10 ? 'alert-success' : 'alert-warning'; ?>">
                                        <h6><i class="fas fa-graduation-cap"></i> Situation actuelle</h6>
                                        <p class="mb-0">
                                            <?php if(floatval($statistiques['moyenne_actuelle'] ?? 0) >= 10): ?>
                                            <strong>Félicitations!</strong> Votre moyenne actuelle est suffisante pour valider l'année. 
                                            Continuez sur cette lancée!
                                            <?php else: ?>
                                            <strong>Attention:</strong> Votre moyenne est en dessous de 10. Concentrez-vous sur 
                                            les rattrapages et les matières en difficulté.
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Actions rapides -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Actions Rapides
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='bulletins.php'">
                                        <i class="fas fa-file-pdf"></i>
                                        <div class="title">Bulletins</div>
                                        <div class="description">Consulter PDF</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='rattrapages.php'">
                                        <i class="fas fa-redo-alt"></i>
                                        <div class="title">Rattrapages</div>
                                        <div class="description">Planification</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="printReleveNotes()">
                                        <i class="fas fa-print"></i>
                                        <div class="title">Imprimer</div>
                                        <div class="description">Relevé de notes</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="exportPDF()">
                                        <i class="fas fa-download"></i>
                                        <div class="title">Exporter</div>
                                        <div class="description">PDF complet</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="requestDocument()">
                                        <i class="fas fa-file-alt"></i>
                                        <div class="title">Demander</div>
                                        <div class="description">Document officiel</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='professeurs.php'">
                                        <i class="fas fa-question-circle"></i>
                                        <div class="title">Questions</div>
                                        <div class="description">Contacter profs</div>
                                    </div>
                                </div>
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
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
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
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Graphique de répartition des notes
        const notesRepartitionCtx = document.getElementById('notesRepartitionChart');
        if (notesRepartitionCtx) {
            new Chart(notesRepartitionCtx, {
                type: 'pie',
                data: {
                    labels: ['0-8', '8-10', '10-12', '12-14', '14-16', '16-20'],
                    datasets: [{
                        data: [2, 3, 5, 4, 3, 2],
                        backgroundColor: [
                            '#e74c3c',
                            '#f39c12',
                            '#3498db',
                            '#2ecc71',
                            '#9b59b6',
                            '#1abc9c'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: true,
                            text: 'Répartition des notes'
                        }
                    }
                }
            });
        }
        
        // Graphique d'évolution des moyennes
        const evolutionCtx = document.getElementById('evolutionChart');
        if (evolutionCtx) {
            // Données factices pour l'exemple
            const annees = <?php echo json_encode(array_column($moyennes_generales, 'annee_academique')); ?>;
            const moyennes = <?php echo json_encode(array_column($moyennes_generales, 'moyenne_annuelle')); ?>;
            
            new Chart(evolutionCtx, {
                type: 'line',
                data: {
                    labels: annees.reverse(),
                    datasets: [{
                        label: 'Moyenne annuelle',
                        data: moyennes.reverse(),
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true
                        },
                        title: {
                            display: true,
                            text: 'Évolution de votre moyenne'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 20,
                            title: {
                                display: true,
                                text: 'Moyenne /20'
                            }
                        }
                    }
                }
            });
        }
        
        // Graphique de performance
        const performanceCtx = document.getElementById('performanceChart');
        if (performanceCtx) {
            // Données factices pour l'exemple
            const semestres = ['S1 2023', 'S2 2023', 'S1 2024', 'S2 2024', 'S1 2025'];
            const performances = [10.5, 11.2, 12.8, 13.5, 14.2];
            
            new Chart(performanceCtx, {
                type: 'bar',
                data: {
                    labels: semestres,
                    datasets: [{
                        label: 'Moyenne par semestre',
                        data: performances,
                        backgroundColor: performances.map(m => m >= 10 ? '#2ecc71' : '#e74c3c'),
                        borderColor: performances.map(m => m >= 10 ? '#27ae60' : '#c0392b'),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Progression semestrielle'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 20,
                            title: {
                                display: true,
                                text: 'Moyenne /20'
                            }
                        }
                    }
                }
            });
        }
    });
    
    // Fonctions utilitaires
    function printPage() {
        window.print();
    }
    
    function exportResults() {
        alert('Fonction d\'export des résultats à implémenter');
    }
    
    function viewBulletin(bulletinId) {
        alert('Affichage du bulletin #' + bulletinId + ' - Fonction à implémenter');
    }
    
    function downloadBulletin(bulletinId) {
        alert('Téléchargement du bulletin #' + bulletinId + ' - Fonction à implémenter');
    }
    
    function showQR(qrCode) {
        alert('Affichage du QR Code: ' + qrCode);
    }
    
    function applyFilters() {
        const annee = document.getElementById('filterAnnee').value;
        const semestre = document.getElementById('filterSemestre').value;
        const type = document.getElementById('filterType').value;
        const statut = document.getElementById('filterStatut').value;
        
        alert('Application des filtres:\n' +
              'Année: ' + annee + '\n' +
              'Semestre: ' + semestre + '\n' +
              'Type: ' + type + '\n' +
              'Statut: ' + statut);
    }
    
    function resetFilters() {
        document.getElementById('filterAnnee').value = 'all';
        document.getElementById('filterSemestre').value = 'all';
        document.getElementById('filterType').value = 'all';
        document.getElementById('filterStatut').value = 'all';
        alert('Filtres réinitialisés');
    }
    
    function showRattrapageInfo(matiere) {
        alert('Informations de rattrapage pour: ' + matiere + '\n\n' +
              '1. Consultez le calendrier des rattrapages\n' +
              '2. Contactez l\'enseignant pour les consignes\n' +
              '3. Récupérez les supports de cours\n' +
              '4. Préparez-vous avec les annales');
    }
    
    function showResources(matiere) {
        alert('Ressources disponibles pour: ' + matiere + '\n\n' +
              '• Supports de cours\n' +
              '• Annales d\'examens\n' +
              '• Corrigés types\n' +
              '• Séances de révision');
    }
    
    function printReleveNotes() {
        alert('Impression du relevé de notes - Fonction à implémenter');
    }
    
    function exportPDF() {
        alert('Export PDF des résultats - Fonction à implémenter');
    }
    
    function exportExcel() {
        alert('Export Excel des résultats - Fonction à implémenter');
    }
    
    function requestDocument() {
        const documentType = prompt('Type de document à demander:\n1. Attestation de réussite\n2. Certificat de scolarité\n3. Relevé de notes officiel\n4. Duplicata de bulletin');
        
        if (documentType) {
            const raison = prompt('Motif de la demande:');
            if (raison) {
                alert('Demande envoyée!\n\nType: ' + documentType + '\nMotif: ' + raison + '\n\nVous serez informé par email de la disponibilité.');
            }
        }
    }
    </script>
</body>
</html>