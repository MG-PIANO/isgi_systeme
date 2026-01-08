<?php
// dashboard/etudiant/rattrapages.php

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
    $pageTitle = "Gestion des Rattrapages";
    
    // Fonctions utilitaires avec validation
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format(floatval($amount), 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatDateTimeFr($datetime, $format = 'd/m/Y H:i') {
        if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($datetime);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'planifie':
            case 'inscrit':
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'admis':
            case 'valide':
            case 'present':
            case 'termine':
            case 'paye':
                return '<span class="badge bg-success">Validé</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
            case 'en_retard':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'rattrapage':
            case 'en_cours':
                return '<span class="badge bg-info">En cours</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
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
    $stats = array(
        'rattrapages_prevus' => 0,
        'rattrapages_passes' => 0,
        'matieres_en_rattrapage' => 0,
        'frais_rattrapage' => 0,
        'taux_reussite' => 0,
        'duree_moyenne' => 0
    );
    
    $rattrapages_prevus = array();
    $rattrapages_passes = array();
    $matieres_en_rattrapage = array();
    $frais_detail = array();
    $calendrier_rattrapages = array();
    $historique_rattrapages = array();
    $documents_rattrapages = array();
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
        "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        
        // Récupérer les statistiques des rattrapages
        // Rattrapages prévus
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation > CURDATE()",
            [$etudiant_id]);
        $stats['rattrapages_prevus'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Rattrapages passés
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation <= CURDATE()",
            [$etudiant_id]);
        $stats['rattrapages_passes'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Matières en rattrapage
        $result = executeSingleQuery($db,
            "SELECT COUNT(DISTINCT n.matiere_id) as total 
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'",
            [$etudiant_id]);
        $stats['matieres_en_rattrapage'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Frais de rattrapage
        $result = executeSingleQuery($db,
            "SELECT COALESCE(SUM(p.montant), 0) as total 
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'valide'
             AND tf.nom LIKE '%rattrapage%' OR tf.nom LIKE '%examen%'",
            [$etudiant_id]);
        $stats['frais_rattrapage'] = isset($result['total']) ? floatval($result['total']) : 0;
        
        // Taux de réussite aux rattrapages
        $result = executeSingleQuery($db,
            "SELECT 
                COUNT(CASE WHEN n.note >= 10 THEN 1 END) as reussis,
                COUNT(*) as total
             FROM notes n
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation < CURDATE()",
            [$etudiant_id]);
        
        if (isset($result['total']) && $result['total'] > 0) {
            $stats['taux_reussite'] = round((intval($result['reussis']) / intval($result['total'])) * 100, 1);
        }
        
        // Durée moyenne des rattrapages (en jours depuis la note initiale)
        $result = executeSingleQuery($db,
            "SELECT AVG(DATEDIFF(n2.date_evaluation, n1.date_evaluation)) as moyenne
             FROM notes n1
             JOIN notes n2 ON n1.matiere_id = n2.matiere_id 
             AND n1.etudiant_id = n2.etudiant_id
             WHERE n1.etudiant_id = ? 
             AND n1.statut = 'valide'
             AND n2.statut = 'rattrapage'
             AND n2.date_evaluation > n1.date_evaluation",
            [$etudiant_id]);
        $stats['duree_moyenne'] = isset($result['moyenne']) ? round(floatval($result['moyenne']), 1) : 0;
        
        // Récupérer les rattrapages prévus
        $rattrapages_prevus = executeQuery($db,
            "SELECT n.*, m.nom as matiere_nom, m.coefficient,
                    te.nom as type_examen, s.nom as semestre_nom,
                    DATEDIFF(n.date_evaluation, CURDATE()) as jours_restants
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN types_examens te ON n.type_examen_id = te.id
             JOIN semestres s ON n.semestre_id = s.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation > CURDATE()
             ORDER BY n.date_evaluation ASC",
            [$etudiant_id]);
        
        // Récupérer les rattrapages passés
        $rattrapages_passes = executeQuery($db,
            "SELECT n.*, m.nom as matiere_nom, m.coefficient,
                    te.nom as type_examen, s.nom as semestre_nom,
                    CASE 
                        WHEN n.note >= 10 THEN 'Admis'
                        ELSE 'Ajourné'
                    END as resultat
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN types_examens te ON n.type_examen_id = te.id
             JOIN semestres s ON n.semestre_id = s.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation <= CURDATE()
             ORDER BY n.date_evaluation DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les matières en rattrapage
        $matieres_en_rattrapage = executeQuery($db,
            "SELECT DISTINCT m.*, n.note as note_initiale,
                    (SELECT MIN(date_evaluation) 
                     FROM notes n2 
                     WHERE n2.matiere_id = m.id 
                     AND n2.etudiant_id = ? 
                     AND n2.statut = 'rattrapage'
                     AND n2.date_evaluation > CURDATE()) as date_rattrapage
             FROM matieres m
             JOIN notes n ON m.id = n.matiere_id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             GROUP BY m.id, m.nom, m.coefficient, n.note
             ORDER BY m.nom",
            [$etudiant_id, $etudiant_id]);
        
        // Récupérer les frais de rattrapage
        $frais_detail = executeQuery($db,
            "SELECT p.*, tf.nom as type_frais, aa.libelle as annee_academique
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             JOIN annees_academiques aa ON p.annee_academique_id = aa.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'valide'
             AND (tf.nom LIKE '%rattrapage%' OR tf.nom LIKE '%examen%')
             ORDER BY p.date_paiement DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer le calendrier des rattrapages
        $calendrier_rattrapages = executeQuery($db,
            "SELECT n.date_evaluation as date, m.nom as matiere,
                    CONCAT('Rattrapage - ', m.nom) as titre,
                    n.note as note_initiale,
                    DATEDIFF(n.date_evaluation, CURDATE()) as jours_restants
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'rattrapage'
             AND n.date_evaluation >= CURDATE()
             ORDER BY n.date_evaluation ASC",
            [$etudiant_id]);
        
        // Récupérer l'historique des rattrapages
        $historique_rattrapages = executeQuery($db,
            "SELECT n1.*, m.nom as matiere_nom, 
                    n2.note as note_rattrapage,
                    n2.date_evaluation as date_rattrapage,
                    CASE 
                        WHEN n2.note >= 10 THEN 'Réussi'
                        WHEN n2.note IS NULL THEN 'À venir'
                        ELSE 'Échoué'
                    END as statut_rattrapage
             FROM notes n1
             JOIN matieres m ON n1.matiere_id = m.id
             LEFT JOIN notes n2 ON n1.matiere_id = n2.matiere_id 
             AND n1.etudiant_id = n2.etudiant_id
             AND n2.statut = 'rattrapage'
             WHERE n1.etudiant_id = ? 
             AND n1.statut = 'valide'
             AND n1.note < 10
             ORDER BY n1.date_evaluation DESC
             LIMIT 15",
            [$etudiant_id]);
        
        // Récupérer les documents liés aux rattrapages
        $documents_rattrapages = executeQuery($db,
            "SELECT de.*, 
                    CASE 
                        WHEN de.type_document = 'bulletin' THEN 'Bulletin de rattrapage'
                        WHEN de.type_document = 'attestation' THEN 'Attestation de rattrapage'
                        WHEN de.type_document = 'releve' THEN 'Relevé de rattrapage'
                        ELSE 'Document de rattrapage'
                    END as type_document_libelle
             FROM documents_etudiants de
             WHERE de.etudiant_id = ? 
             AND de.statut = 'valide'
             AND (de.nom_fichier LIKE '%rattrapage%' OR de.type_document IN ('bulletin', 'releve'))
             ORDER BY de.date_upload DESC
             LIMIT 5",
            [$etudiant_id]);
        
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
    <title><?php echo safeHtml($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    
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
    
    /* Sidebar (identique au dashboard principal) */
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
    
    /* Stat cards spécifiques aux rattrapages */
    .stat-card {
        text-align: center;
        padding: 20px;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card.rattrapage {
        border-left: 4px solid var(--accent-color);
    }
    
    .stat-card.prevu {
        border-left: 4px solid var(--warning-color);
    }
    
    .stat-card.passe {
        border-left: 4px solid var(--info-color);
    }
    
    .stat-card.reussite {
        border-left: 4px solid var(--success-color);
    }
    
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
        opacity: 0.8;
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
    
    /* Badges spécifiques */
    .badge-rattrapage {
        background-color: var(--accent-color);
        color: white;
    }
    
    .badge-prevu {
        background-color: var(--warning-color);
        color: white;
    }
    
    .badge-termine {
        background-color: var(--info-color);
        color: white;
    }
    
    .badge-reussi {
        background-color: var(--success-color);
        color: white;
    }
    
    .badge-echoue {
        background-color: var(--accent-color);
        color: white;
    }
    
    /* Notes */
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
    
    /* Alertes spécifiques aux rattrapages */
    .alert-rattrapage {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
        color: var(--text-color);
    }
    
    .alert-prevu {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
        color: var(--text-color);
    }
    
    .alert-info-rattrapage {
        background-color: rgba(52, 152, 219, 0.1);
        border-left: 4px solid var(--secondary-color);
        color: var(--text-color);
    }
    
    /* Boutons spécifiques */
    .btn-rattrapage {
        background-color: var(--accent-color);
        color: white;
        border: none;
    }
    
    .btn-rattrapage:hover {
        background-color: #c0392b;
        color: white;
    }
    
    /* Timeline des rattrapages */
    .timeline {
        position: relative;
        padding: 20px 0;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 50%;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--border-color);
        transform: translateX(-50%);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 30px;
    }
    
    .timeline-content {
        position: relative;
        width: 45%;
        padding: 15px;
        background: var(--card-bg);
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }
    
    .timeline-item:nth-child(odd) .timeline-content {
        left: 0;
    }
    
    .timeline-item:nth-child(even) .timeline-content {
        left: 55%;
    }
    
    .timeline-date {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        background: var(--primary-color);
        color: white;
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 0.85rem;
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
        
        .timeline::before {
            left: 30px;
        }
        
        .timeline-content {
            width: calc(100% - 80px);
            left: 80px !important;
        }
        
        .timeline-date {
            left: 30px;
            transform: none;
        }
    }
    
    /* En-têtes */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .content-header h2 {
        color: var(--text-color);
    }
    
    .content-header .text-muted {
        color: var(--text-muted);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
        height: 10px;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
    }
    
    /* Formulaires */
    .form-control, .form-select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .form-control:focus, .form-select:focus {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
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
                    <a href="rattrapages.php" class="nav-link active">
                        <i class="fas fa-redo-alt"></i>
                        <span>Rattrapages</span>
                        <?php if($stats['rattrapages_prevus'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['rattrapages_prevus']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Ressources</div>
                    <a href="documents.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Documents</span>
                    </a>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                    </a>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances</div>
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finances</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures</span>
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
                            <i class="fas fa-redo-alt me-2"></i>
                            Gestion des Rattrapages
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            <?php if(isset($info_etudiant['niveau_libelle']) && !empty($info_etudiant['niveau_libelle'])): ?>
                            <?php echo safeHtml($info_etudiant['niveau_libelle']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="notes.php" class="btn btn-success">
                            <i class="fas fa-chart-line"></i> Voir toutes les notes
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Statistiques des rattrapages -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card rattrapage">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['matieres_en_rattrapage']; ?></div>
                        <div class="stat-label">Matières en Rattrapage</div>
                        <div class="stat-change">
                            <?php if($stats['matieres_en_rattrapage'] > 0): ?>
                            <span class="negative"><i class="fas fa-arrow-up"></i> Attention</span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> Aucun</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card prevu">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['rattrapages_prevus']; ?></div>
                        <div class="stat-label">Rattrapages Prévus</div>
                        <div class="stat-change">
                            <?php if($stats['rattrapages_prevus'] > 0): ?>
                            <span class="warning"><i class="fas fa-clock"></i> À préparer</span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> Aucun</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card passe">
                        <div class="text-info stat-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['rattrapages_passes']; ?></div>
                        <div class="stat-label">Rattrapages Passés</div>
                        <div class="stat-change">
                            <?php if($stats['rattrapages_passes'] > 0): ?>
                            <i class="fas fa-chart-bar"></i> <?php echo $stats['taux_reussite']; ?>% réussite
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> Aucun</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card reussite">
                        <div class="text-success stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['taux_reussite']; ?>%</div>
                        <div class="stat-label">Taux de Réussite</div>
                        <div class="stat-change">
                            <?php if($stats['taux_reussite'] >= 50): ?>
                            <span class="positive"><i class="fas fa-arrow-up"></i> Bon</span>
                            <?php elseif($stats['taux_reussite'] > 0): ?>
                            <span class="negative"><i class="fas fa-arrow-down"></i> À améliorer</span>
                            <?php else: ?>
                            <span class="info">Non applicable</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Alertes importantes -->
            <?php if($stats['matieres_en_rattrapage'] > 0 || $stats['rattrapages_prevus'] > 0): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Alertes & Rappels Importants
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if($stats['rattrapages_prevus'] > 0): ?>
                            <div class="alert alert-prevu">
                                <i class="fas fa-calendar-check"></i> 
                                <strong>Rattrapages à venir:</strong> Vous avez <?php echo $stats['rattrapages_prevus']; ?> rattrapage(s) programmé(s).
                                <a href="#prevus" class="alert-link">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['matieres_en_rattrapage'] > 0): ?>
                            <div class="alert alert-rattrapage">
                                <i class="fas fa-book"></i> 
                                <strong>Matières en rattrapage:</strong> Vous avez <?php echo $stats['matieres_en_rattrapage']; ?> matière(s) nécessitant un rattrapage.
                                <a href="#matieres" class="alert-link">Consulter la liste</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['frais_rattrapage'] > 0): ?>
                            <div class="alert alert-info-rattrapage">
                                <i class="fas fa-money-bill-wave"></i> 
                                <strong>Frais de rattrapage:</strong> Total des frais payés: <?php echo formatMoney($stats['frais_rattrapage']); ?>.
                                <a href="#frais" class="alert-link">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['taux_reussite'] < 50 && $stats['rattrapages_passes'] > 0): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-chart-line"></i> 
                                <strong>Attention:</strong> Votre taux de réussite aux rattrapages est de <?php echo $stats['taux_reussite']; ?>%.
                                Pensez à mieux vous préparer pour les prochains rattrapages.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section 3: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="rattrapagesTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="prevus-tab" data-bs-toggle="tab" data-bs-target="#prevus" type="button">
                                <i class="fas fa-calendar-alt me-2"></i>Rattrapages Prévus
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="passes-tab" data-bs-toggle="tab" data-bs-target="#passes" type="button">
                                <i class="fas fa-history me-2"></i>Historique
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="matieres-tab" data-bs-toggle="tab" data-bs-target="#matieres" type="button">
                                <i class="fas fa-book me-2"></i>Matières
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="frais-tab" data-bs-toggle="tab" data-bs-target="#frais" type="button">
                                <i class="fas fa-money-bill-wave me-2"></i>Frais
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="rattrapagesTabsContent">
                        <!-- Tab 1: Rattrapages Prévus -->
                        <div class="tab-pane fade show active" id="prevus">
                            <div class="row">
                                <div class="col-md-7">
                                    <h5><i class="fas fa-list-ol me-2"></i>Liste des Rattrapages à Venir</h5>
                                    <?php if(empty($rattrapages_prevus)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucun rattrapage prévu pour le moment
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matière</th>
                                                    <th>Date</th>
                                                    <th>Jours restants</th>
                                                    <th>Coefficient</th>
                                                    <th>Type</th>
                                                    <th>Semestre</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($rattrapages_prevus as $rattrapage): 
                                                    $jours_restants = intval($rattrapage['jours_restants'] ?? 0);
                                                    $badge_class = $jours_restants <= 3 ? 'badge-danger' : ($jours_restants <= 7 ? 'badge-warning' : 'badge-info');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?></strong>
                                                    </td>
                                                    <td><?php echo formatDateFr($rattrapage['date_evaluation'] ?? ''); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            J-<?php echo $jours_restants; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo safeHtml($rattrapage['coefficient'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($rattrapage['type_examen'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($rattrapage['semestre_nom'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar me-2"></i>Calendrier des Rattrapages</h5>
                                    <div id="calendarRattrapages"></div>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        var calendarEl = document.getElementById('calendarRattrapages');
                                        var calendar = new FullCalendar.Calendar(calendarEl, {
                                            initialView: 'dayGridMonth',
                                            locale: 'fr',
                                            headerToolbar: {
                                                left: 'prev,next today',
                                                center: 'title',
                                                right: 'dayGridMonth,timeGridWeek,timeGridDay'
                                            },
                                            events: [
                                                <?php foreach($calendrier_rattrapages as $event): ?>
                                                {
                                                    title: '<?php echo safeHtml($event["matiere"] ?? ''); ?>',
                                                    start: '<?php echo date("Y-m-d", strtotime($event["date"] ?? "now")); ?>',
                                                    description: 'Rattrapage - Note initiale: <?php echo isset($event["note_initiale"]) ? number_format(floatval($event["note_initiale"]), 2) : "N/A"; ?>/20',
                                                    color: '#e74c3c',
                                                    textColor: 'white'
                                                },
                                                <?php endforeach; ?>
                                            ],
                                            eventClick: function(info) {
                                                alert(
                                                    'Rattrapage: ' + info.event.title + '\n' +
                                                    'Date: ' + info.event.start.toLocaleDateString() + '\n' +
                                                    info.event.extendedProps.description
                                                );
                                            }
                                        });
                                        calendar.render();
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-5">
                                    <h5><i class="fas fa-chart-pie me-2"></i>Répartition par Matière</h5>
                                    <canvas id="matieresChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('matieresChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'pie',
                                            data: {
                                                labels: [
                                                    <?php 
                                                    $matieres_count = [];
                                                    foreach($rattrapages_prevus as $r) {
                                                        $matiere = $r['matiere_nom'] ?? 'Inconnue';
                                                        if (!isset($matieres_count[$matiere])) {
                                                            $matieres_count[$matiere] = 0;
                                                        }
                                                        $matieres_count[$matiere]++;
                                                    }
                                                    foreach($matieres_count as $matiere => $count) {
                                                        echo "'" . safeHtml($matiere) . "',";
                                                    }
                                                    ?>
                                                ],
                                                datasets: [{
                                                    data: [
                                                        <?php 
                                                        foreach($matieres_count as $count) {
                                                            echo $count . ",";
                                                        }
                                                        ?>
                                                    ],
                                                    backgroundColor: [
                                                        '#e74c3c', '#3498db', '#2ecc71', '#f39c12',
                                                        '#9b59b6', '#1abc9c', '#34495e', '#d35400'
                                                    ]
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        position: 'right',
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Rattrapages par matière'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    
                                    <h5 class="mt-4"><i class="fas fa-lightbulb me-2"></i>Conseils de Préparation</h5>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-graduation-cap"></i> Comment bien préparer vos rattrapages:</h6>
                                        <ul class="mb-0 small">
                                            <li>Révisez les corrigés des examens précédents</li>
                                            <li>Consultez vos professeurs pour des conseils spécifiques</li>
                                            <li>Organisez un planning de révision</li>
                                            <li>Utilisez les ressources de la bibliothèque</li>
                                            <li>Participez aux séances de révision organisées</li>
                                            <li>Préparez vos questions à l'avance</li>
                                        </ul>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-file-alt me-2"></i>Documents Utiles</h5>
                                    <?php if(empty($documents_rattrapages)): ?>
                                    <div class="alert alert-info">
                                        Aucun document spécifique aux rattrapages disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($documents_rattrapages as $doc): ?>
                                        <a href="<?php echo safeHtml($doc['chemin_fichier'] ?? '#'); ?>" 
                                           class="list-group-item list-group-item-action" target="_blank">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($doc['type_document_libelle'] ?? ''); ?></h6>
                                                <small><?php echo formatDateFr($doc['date_upload'] ?? ''); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo safeHtml($doc['nom_fichier'] ?? ''); ?></p>
                                            <small><i class="fas fa-download"></i> Cliquez pour télécharger</small>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Historique des Rattrapages -->
                        <div class="tab-pane fade" id="passes">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-history me-2"></i>Historique des Rattrapages</h5>
                                    <?php if(empty($rattrapages_passes)): ?>
                                    <div class="alert alert-info">
                                        Aucun historique de rattrapage disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matière</th>
                                                    <th>Date</th>
                                                    <th>Note</th>
                                                    <th>Coefficient</th>
                                                    <th>Résultat</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($rattrapages_passes as $rattrapage): 
                                                    $note_value = floatval($rattrapage['note'] ?? 0);
                                                    $note_class = $note_value >= 10 ? 'note-excellent' : 'note-poor';
                                                    $resultat_badge = $note_value >= 10 ? 'badge-reussi' : 'badge-echoue';
                                                    $resultat_text = $note_value >= 10 ? 'Réussi' : 'Échoué';
                                                ?>
                                                <tr>
                                                    <td><?php echo safeHtml($rattrapage['matiere_nom'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($rattrapage['date_evaluation'] ?? ''); ?></td>
                                                    <td>
                                                        <span class="note-badge <?php echo $note_class; ?>">
                                                            <?php echo number_format($note_value, 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td><?php echo safeHtml($rattrapage['coefficient'] ?? ''); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $resultat_badge; ?>">
                                                            <?php echo $resultat_text; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-line me-2"></i>Évolution des Résultats</h5>
                                    <canvas id="evolutionChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('evolutionChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'line',
                                            data: {
                                                labels: [
                                                    <?php 
                                                    $dates = [];
                                                    foreach($rattrapages_passes as $r) {
                                                        $date = formatDateFr($r['date_evaluation'] ?? '', 'd/m');
                                                        echo "'" . $date . "',";
                                                        $dates[] = $date;
                                                    }
                                                    ?>
                                                ],
                                                datasets: [{
                                                    label: 'Notes de rattrapage',
                                                    data: [
                                                        <?php 
                                                        foreach($rattrapages_passes as $r) {
                                                            echo number_format(floatval($r['note'] ?? 0), 2) . ",";
                                                        }
                                                        ?>
                                                    ],
                                                    borderColor: '#3498db',
                                                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                                    fill: true,
                                                    tension: 0.4
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        display: true,
                                                        position: 'top'
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Évolution des notes de rattrapage'
                                                    }
                                                },
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        max: 20,
                                                        title: {
                                                            display: true,
                                                            text: 'Note /20'
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-chart-bar me-2"></i>Statistiques Globales</h5>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <h1 class="display-4"><?php echo $stats['taux_reussite']; ?>%</h1>
                                                    <p class="text-muted">Taux de réussite</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <h1 class="display-4"><?php echo $stats['duree_moyenne']; ?>j</h1>
                                                    <p class="text-muted">Délai moyen</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-project-diagram me-2"></i>Analyse des Résultats</h5>
                                    <canvas id="resultatsChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('resultatsChart').getContext('2d');
                                        const reussis = <?php 
                                            $reussis = 0;
                                            foreach($rattrapages_passes as $r) {
                                                if (floatval($r['note'] ?? 0) >= 10) $reussis++;
                                            }
                                            echo $reussis;
                                        ?>;
                                        const echoues = <?php echo count($rattrapages_passes) - $reussis; ?>;
                                        
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['Réussis', 'Échoués'],
                                                datasets: [{
                                                    data: [reussis, echoues],
                                                    backgroundColor: [
                                                        '#2ecc71',
                                                        '#e74c3c'
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
                                                        text: 'Répartition réussite/échec'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                    
                                    <h5 class="mt-4"><i class="fas fa-timeline me-2"></i>Timeline des Rattrapages</h5>
                                    <div class="timeline">
                                        <?php 
                                        $historique = executeQuery($db,
                                            "SELECT n1.date_evaluation as date_examen, 
                                                    n2.date_evaluation as date_rattrapage,
                                                    m.nom as matiere_nom,
                                                    n1.note as note_initiale,
                                                    n2.note as note_rattrapage
                                             FROM notes n1
                                             JOIN matieres m ON n1.matiere_id = m.id
                                             LEFT JOIN notes n2 ON n1.matiere_id = n2.matiere_id 
                                             AND n1.etudiant_id = n2.etudiant_id
                                             AND n2.statut = 'rattrapage'
                                             WHERE n1.etudiant_id = ? 
                                             AND n1.statut = 'valide'
                                             AND n1.note < 10
                                             ORDER BY n1.date_evaluation DESC
                                             LIMIT 5",
                                            [$etudiant_id]);
                                        ?>
                                        
                                        <?php if(empty($historique)): ?>
                                        <div class="alert alert-info">
                                            Aucune donnée de timeline disponible
                                        </div>
                                        <?php else: ?>
                                        <?php foreach($historique as $item): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-date">
                                                <?php echo formatDateFr($item['date_examen'] ?? ''); ?>
                                            </div>
                                            <div class="timeline-content">
                                                <h6><?php echo safeHtml($item['matiere_nom'] ?? ''); ?></h6>
                                                <p class="mb-1">
                                                    <small>Examen: <?php echo number_format(floatval($item['note_initiale'] ?? 0), 2); ?>/20</small><br>
                                                    <?php if(!empty($item['date_rattrapage'])): ?>
                                                    <small>Rattrapage: <?php echo formatDateFr($item['date_rattrapage']); ?> - 
                                                    Note: <?php echo number_format(floatval($item['note_rattrapage'] ?? 0), 2); ?>/20</small>
                                                    <?php else: ?>
                                                    <small>Rattrapage: À programmer</small>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Matières en Rattrapage -->
                        <div class="tab-pane fade" id="matieres">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-book me-2"></i>Détail des Matières</h5>
                                    <?php if(empty($matieres_en_rattrapage)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune matière en rattrapage actuellement
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($matieres_en_rattrapage as $matiere): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($matiere['nom'] ?? ''); ?></h6>
                                                <span class="badge bg-primary">Coeff: <?php echo safeHtml($matiere['coefficient'] ?? ''); ?></span>
                                            </div>
                                            <p class="mb-1">
                                                <small>Code: <?php echo safeHtml($matiere['code'] ?? ''); ?></small><br>
                                                <small>Note initiale: 
                                                    <span class="badge bg-danger">
                                                        <?php echo number_format(floatval($matiere['note_initiale'] ?? 0), 2); ?>/20
                                                    </span>
                                                </small>
                                            </p>
                                            <?php if(!empty($matiere['date_rattrapage'])): ?>
                                            <div class="alert alert-warning mt-2 py-2">
                                                <small>
                                                    <i class="fas fa-calendar"></i> 
                                                    <strong>Rattrapage prévu le:</strong> 
                                                    <?php echo formatDateFr($matiere['date_rattrapage']); ?>
                                                </small>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-info mt-2 py-2">
                                                <small>
                                                    <i class="fas fa-clock"></i> 
                                                    <strong>Statut:</strong> Rattrapage à programmer
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-bar me-2"></i>Répartition par Coefficient</h5>
                                    <canvas id="coeffChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('coeffChart').getContext('2d');
                                        const coefficients = {
                                            <?php 
                                            $coeff_count = [];
                                            foreach($matieres_en_rattrapage as $m) {
                                                $coeff = $m['coefficient'] ?? '1.00';
                                                if (!isset($coeff_count[$coeff])) {
                                                    $coeff_count[$coeff] = 0;
                                                }
                                                $coeff_count[$coeff]++;
                                            }
                                            $first = true;
                                            foreach($coeff_count as $coeff => $count) {
                                                if (!$first) echo ",";
                                                echo "'" . $coeff . "': " . $count;
                                                $first = false;
                                            }
                                            ?>
                                        };
                                        
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: Object.keys(coefficients),
                                                datasets: [{
                                                    label: 'Nombre de matières',
                                                    data: Object.values(coefficients),
                                                    backgroundColor: '#3498db'
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
                                                        text: 'Matières en rattrapage par coefficient'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-graduation-cap me-2"></i>Ressources de Révision</h5>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-lightbulb"></i> Stratégies de révision par matière:</h6>
                                        <div class="accordion" id="strategiesAccordion">
                                            <?php foreach($matieres_en_rattrapage as $index => $matiere): ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="heading<?php echo $index; ?>">
                                                    <button class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>" 
                                                            type="button" data-bs-toggle="collapse" 
                                                            data-bs-target="#collapse<?php echo $index; ?>">
                                                        <?php echo safeHtml($matiere['nom'] ?? ''); ?>
                                                    </button>
                                                </h2>
                                                <div id="collapse<?php echo $index; ?>" 
                                                     class="accordion-collapse collapse <?php echo $index == 0 ? 'show' : ''; ?>" 
                                                     data-bs-parent="#strategiesAccordion">
                                                    <div class="accordion-body">
                                                        <ul class="mb-0 small">
                                                            <li>Révisez les cours magistraux</li>
                                                            <li>Consultez les corrigés des examens précédents</li>
                                                            <li>Pratiquez avec les exercices supplémentaires</li>
                                                            <li>Participez aux séances de tutorat</li>
                                                            <li>Consultez le professeur pour des conseils spécifiques</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-users me-2"></i>Contacts Professeurs</h5>
                                    <?php if(isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])): 
                                        $professeurs_matieres = executeQuery($db,
                                            "SELECT DISTINCT u.nom, u.prenom, m.nom as matiere_nom, u.email
                                             FROM enseignants e
                                             JOIN utilisateurs u ON e.utilisateur_id = u.id
                                             JOIN matieres m ON m.enseignant_id = e.id
                                             WHERE m.id IN (
                                                 SELECT DISTINCT matiere_id 
                                                 FROM notes 
                                                 WHERE etudiant_id = ? 
                                                 AND statut = 'rattrapage'
                                             )
                                             AND u.statut = 'actif'
                                             LIMIT 5",
                                            [$etudiant_id]);
                                    ?>
                                    <?php if(empty($professeurs_matieres)): ?>
                                    <div class="alert alert-info">
                                        Aucun contact professeur spécifique disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($professeurs_matieres as $prof): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml(($prof['nom'] ?? '') . ' ' . ($prof['prenom'] ?? '')); ?></h6>
                                            </div>
                                            <p class="mb-1">
                                                <small>Matière: <?php echo safeHtml($prof['matiere_nom'] ?? ''); ?></small><br>
                                                <small>Email: <?php echo safeHtml($prof['email'] ?? ''); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-question-circle me-2"></i>FAQ Rattrapages</h5>
                                    <div class="accordion" id="faqAccordion">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="faqHeading1">
                                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse1">
                                                    Quand sont programmés les rattrapages ?
                                                </button>
                                            </h2>
                                            <div id="faqCollapse1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body">
                                                    Les rattrapages sont généralement programmés 2 à 4 semaines après la publication des résultats.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="faqHeading2">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse2">
                                                    Quelles sont les conditions pour passer un rattrapage ?
                                                </button>
                                            </h2>
                                            <div id="faqCollapse2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body">
                                                    Vous devez avoir une note inférieure à 10/20 dans la matière et être à jour de vos frais de scolarité.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="faqHeading3">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse3">
                                                    Comment se préparer efficacement ?
                                                </button>
                                            </h2>
                                            <div id="faqCollapse3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body">
                                                    Consultez les ressources pédagogiques, participez aux séances de révision et contactez vos professeurs pour des conseils.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Frais de Rattrapage -->
                        <div class="tab-pane fade" id="frais">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-invoice-dollar me-2"></i>Détail des Frais</h5>
                                    <?php if(empty($frais_detail)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucun frais de rattrapage enregistré
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Référence</th>
                                                    <th>Type</th>
                                                    <th>Montant</th>
                                                    <th>Date</th>
                                                    <th>Année</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($frais_detail as $frais): ?>
                                                <tr>
                                                    <td><?php echo safeHtml($frais['reference'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($frais['type_frais'] ?? ''); ?></td>
                                                    <td><?php echo formatMoney($frais['montant'] ?? 0); ?></td>
                                                    <td><?php echo formatDateFr($frais['date_paiement'] ?? ''); ?></td>
                                                    <td><?php echo safeHtml($frais['annee_academique'] ?? ''); ?></td>
                                                    <td><?php echo getStatutBadge($frais['statut'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-line me-2"></i>Évolution des Dépenses</h5>
                                    <canvas id="depensesChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('depensesChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'line',
                                            data: {
                                                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin'],
                                                datasets: [{
                                                    label: 'Frais de rattrapage',
                                                    data: [
                                                        <?php 
                                                        // Données simulées - à adapter avec vos données réelles
                                                        echo rand(0, 50000) . ",";
                                                        echo rand(0, 50000) . ",";
                                                        echo rand(0, 50000) . ",";
                                                        echo rand(0, 50000) . ",";
                                                        echo rand(0, 50000) . ",";
                                                        echo rand(0, 50000);
                                                        ?>
                                                    ],
                                                    borderColor: '#e74c3c',
                                                    backgroundColor: 'rgba(231, 76, 60, 0.1)',
                                                    fill: true
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                plugins: {
                                                    legend: {
                                                        display: true,
                                                        position: 'top'
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Évolution des frais de rattrapage'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-calculator me-2"></i>Calculateur de Frais</h5>
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Nombre de matières en rattrapage</label>
                                                <input type="number" class="form-control" id="nbMatieres" value="<?php echo $stats['matieres_en_rattrapage']; ?>" min="0" max="10">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Frais par matière (FCFA)</label>
                                                <input type="number" class="form-control" id="fraisParMatiere" value="15000" min="0">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Frais administratifs (FCFA)</label>
                                                <input type="number" class="form-control" id="fraisAdmin" value="5000" min="0">
                                            </div>
                                            <button class="btn btn-primary w-100" onclick="calculerFrais()">
                                                <i class="fas fa-calculator"></i> Calculer les frais totaux
                                            </button>
                                            <div class="mt-3 alert alert-info" id="resultatCalcul">
                                                Total estimé: 0 FCFA
                                            </div>
                                        </div>
                                    </div>
                                    <script>
                                    function calculerFrais() {
                                        const nbMatieres = parseInt(document.getElementById('nbMatieres').value) || 0;
                                        const fraisParMatiere = parseInt(document.getElementById('fraisParMatiere').value) || 0;
                                        const fraisAdmin = parseInt(document.getElementById('fraisAdmin').value) || 0;
                                        
                                        const total = (nbMatieres * fraisParMatiere) + fraisAdmin;
                                        document.getElementById('resultatCalcul').innerHTML = 
                                            `<strong>Total estimé:</strong> ${total.toLocaleString('fr-FR')} FCFA`;
                                    }
                                    </script>
                                    
                                    <h5 class="mt-4"><i class="fas fa-credit-card me-2"></i>Modes de Paiement</h5>
                                    <div class="alert alert-warning">
                                        <h6><i class="fas fa-info-circle"></i> Modalités de paiement :</h6>
                                        <ul class="mb-0 small">
                                            <li><strong>Espèces:</strong> Au service financier aux heures d'ouverture</li>
                                            <li><strong>Mobile Money:</strong> MTN MoMo: +242 XX XX XX XX</li>
                                            <li><strong>Virement bancaire:</strong> RIB disponible au secrétariat</li>
                                            <li><strong>Chèque:</strong> À l'ordre de ISGI Congo</li>
                                        </ul>
                                        <div class="mt-2">
                                            <small><i class="fas fa-exclamation-triangle"></i> 
                                            Le paiement doit être effectué au moins 3 jours avant le rattrapage.</small>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-file-contract me-2"></i>Règlement des Rattrapages</h5>
                                    <div class="accordion" id="reglementAccordion">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="reglementHeading1">
                                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#reglementCollapse1">
                                                    Article 1 - Inscription
                                                </button>
                                            </h2>
                                            <div id="reglementCollapse1" class="accordion-collapse collapse show" data-bs-parent="#reglementAccordion">
                                                <div class="accordion-body small">
                                                    L'inscription aux rattrapages est obligatoire et conditionnée par le paiement des frais correspondants.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="reglementHeading2">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#reglementCollapse2">
                                                    Article 2 - Absence
                                                </button>
                                            </h2>
                                            <div id="reglementCollapse2" class="accordion-collapse collapse" data-bs-parent="#reglementAccordion">
                                                <div class="accordion-body small">
                                                    En cas d'absence non justifiée, les frais de rattrapage ne sont pas remboursés.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="reglementHeading3">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#reglementCollapse3">
                                                    Article 3 - Résultats
                                                </button>
                                            </h2>
                                            <div id="reglementCollapse3" class="accordion-collapse collapse" data-bs-parent="#reglementAccordion">
                                                <div class="accordion-body small">
                                                    La note du rattrapage remplace la note initiale, qu'elle soit supérieure ou inférieure.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Actions Rapides -->
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
                                    <div class="quick-action" onclick="window.location.href='notes.php'">
                                        <i class="fas fa-chart-line"></i>
                                        <div class="title">Voir Notes</div>
                                        <div class="description">Consulter toutes les notes</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.open('<?php echo ROOT_PATH; ?>/uploads/reglement_rattrapage.pdf', '_blank')">
                                        <i class="fas fa-file-pdf"></i>
                                        <div class="title">Règlement</div>
                                        <div class="description">Télécharger le PDF</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='factures.php'">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <div class="title">Payer</div>
                                        <div class="description">Régler les frais</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='bibliotheque.php?type=rattrapage'">
                                        <i class="fas fa-book"></i>
                                        <div class="title">Ressources</div>
                                        <div class="description">Livres et cours</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='messagerie.php?to=professeur'">
                                        <i class="fas fa-envelope"></i>
                                        <div class="title">Contacter</div>
                                        <div class="description">Professeur référent</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.print()">
                                        <i class="fas fa-print"></i>
                                        <div class="title">Imprimer</div>
                                        <div class="description">Ce tableau de bord</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Informations de contact -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-phone-alt me-2"></i>
                                Contacts Utiles
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-user-tie"></i> Service Académique</h6>
                                        <ul class="mb-0 small">
                                            <li><strong>Responsable:</strong> M. Jean Dupont</li>
                                            <li><strong>Téléphone:</strong> +242 XX XX XX XX</li>
                                            <li><strong>Email:</strong> academique@isgi.cg</li>
                                            <li><strong>Bureau:</strong> Bâtiment A, 1er étage</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-warning">
                                        <h6><i class="fas fa-money-check-alt"></i> Service Financier</h6>
                                        <ul class="mb-0 small">
                                            <li><strong>Responsable:</strong> Mme Marie Martin</li>
                                            <li><strong>Téléphone:</strong> +242 XX XX XX XX</li>
                                            <li><strong>Email:</strong> finances@isgi.cg</li>
                                            <li><strong>Bureau:</strong> Bâtiment B, Rez-de-chaussée</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2"></i>
                                Horaires & Délais
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-success">
                                <h6><i class="fas fa-calendar-check"></i> Dates importantes :</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Inscriptions:</strong> Jusqu'à 7 jours avant l'examen</li>
                                    <li><strong>Paiement:</strong> Au moins 3 jours avant l'examen</li>
                                    <li><strong>Publication résultats:</strong> 48h après l'examen</li>
                                    <li><strong>Recours:</strong> Sous 72h après publication</li>
                                </ul>
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
        
        // Initialiser les accordions
        const accordions = document.querySelectorAll('.accordion-button');
        accordions.forEach(button => {
            button.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (icon) {
                    if (this.classList.contains('collapsed')) {
                        icon.className = 'fas fa-chevron-down';
                    } else {
                        icon.className = 'fas fa-chevron-up';
                    }
                }
            });
        });
        
        // Auto-refresh des données toutes les 5 minutes
        setTimeout(() => {
            const activeTab = document.querySelector('#rattrapagesTabsContent .tab-pane.active');
            if (activeTab && activeTab.id === 'prevus') {
                // Seulement rafraîchir si on est sur l'onglet "Prévus"
                location.reload();
            }
        }, 300000); // 5 minutes
    });
    
    // Fonction pour exporter les données en PDF
    function exportToPDF() {
        alert("Export PDF en cours de développement...");
        // Implémentation future avec jsPDF
    }
    
    // Fonction pour partager les données
    function shareDashboard() {
        if (navigator.share) {
            navigator.share({
                title: 'Mes Rattrapages - ISGI',
                text: 'Tableau de bord de mes rattrapages à l\'ISGI',
                url: window.location.href
            });
        } else {
            alert("Partage non supporté sur ce navigateur");
        }
    }
    </script>
</body>
</html>