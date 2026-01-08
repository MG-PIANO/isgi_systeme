<?php
// dashboard/etudiant/factures.php

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
    $pageTitle = "Factures & Paiements";
    
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
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'payee':
            case 'valide':
                return '<span class="badge bg-success">Payée</span>';
            case 'partiel':
                return '<span class="badge bg-warning">Partiel</span>';
            case 'en_attente':
                return '<span class="badge bg-info">En attente</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            case 'annule':
                return '<span class="badge bg-secondary">Annulée</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getPrioriteBadge($jours_restants) {
        if ($jours_restants < 0) {
            return '<span class="badge bg-danger">En retard</span>';
        } elseif ($jours_restants <= 3) {
            return '<span class="badge bg-warning">Urgent</span>';
        } elseif ($jours_restants <= 7) {
            return '<span class="badge bg-info">À échéance</span>';
        } else {
            return '<span class="badge bg-secondary">Normal</span>';
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
    $stats = array(
        'total_factures' => 0,
        'factures_payees' => 0,
        'factures_impayees' => 0,
        'factures_en_retard' => 0,
        'montant_total' => 0,
        'montant_paye' => 0,
        'montant_impaye' => 0,
        'taux_paiement' => 0,
        'prochaine_echeance' => ''
    );
    
    $info_etudiant = array();
    $factures_en_cours = array();
    $factures_payees = array();
    $factures_en_retard = array();
    $factures_a_venir = array();
    $factures_annulees = array();
    $modes_paiement = array();
    $historique_paiements = array();
    $paiements_en_attente = array();
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
        
        // Récupérer les statistiques des factures
        $result = executeSingleQuery($db,
            "SELECT 
                COUNT(*) as total_factures,
                COUNT(CASE WHEN statut = 'payee' THEN 1 END) as factures_payees,
                COUNT(CASE WHEN statut IN ('en_attente', 'partiel') THEN 1 END) as factures_impayees,
                COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as factures_en_retard,
                COALESCE(SUM(montant_total), 0) as montant_total,
                COALESCE(SUM(montant_paye), 0) as montant_paye,
                COALESCE(SUM(montant_restant), 0) as montant_impaye
             FROM factures 
             WHERE etudiant_id = ?",
            [$etudiant_id]);
        
        $stats['total_factures'] = isset($result['total_factures']) ? intval($result['total_factures']) : 0;
        $stats['factures_payees'] = isset($result['factures_payees']) ? intval($result['factures_payees']) : 0;
        $stats['factures_impayees'] = isset($result['factures_impayees']) ? intval($result['factures_impayees']) : 0;
        $stats['factures_en_retard'] = isset($result['factures_en_retard']) ? intval($result['factures_en_retard']) : 0;
        $stats['montant_total'] = isset($result['montant_total']) ? floatval($result['montant_total']) : 0;
        $stats['montant_paye'] = isset($result['montant_paye']) ? floatval($result['montant_paye']) : 0;
        $stats['montant_impaye'] = isset($result['montant_impaye']) ? floatval($result['montant_impaye']) : 0;
        
        if ($stats['montant_total'] > 0) {
            $stats['taux_paiement'] = round(($stats['montant_paye'] / $stats['montant_total']) * 100, 1);
        }
        
        // Prochaine échéance
        $result = executeSingleQuery($db,
            "SELECT MIN(date_echeance) as prochaine_date
             FROM factures 
             WHERE etudiant_id = ? 
             AND statut IN ('en_attente', 'partiel')
             AND date_echeance >= CURDATE()",
            [$etudiant_id]);
        $stats['prochaine_echeance'] = isset($result['prochaine_date']) ? $result['prochaine_date'] : '';
        
        // Récupérer les factures en cours (non payées)
        $factures_en_cours = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais, aa.libelle as annee_academique,
                    DATEDIFF(f.date_echeance, CURDATE()) as jours_restants,
                    CONCAT(u.nom, ' ', u.prenom) as emis_par_nom
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             JOIN annees_academiques aa ON f.annee_academique_id = aa.id
             LEFT JOIN utilisateurs u ON f.emis_par = u.id
             WHERE f.etudiant_id = ? 
             AND f.statut IN ('en_attente', 'partiel', 'en_retard')
             ORDER BY 
                CASE 
                    WHEN f.statut = 'en_retard' THEN 1
                    WHEN f.date_echeance < CURDATE() THEN 2
                    ELSE 3
                END,
                f.date_echeance ASC
             LIMIT 15",
            [$etudiant_id]);
        
        // Récupérer les factures payées
        $factures_payees = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais, aa.libelle as annee_academique,
                    CONCAT(u.nom, ' ', u.prenom) as emis_par_nom
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             JOIN annees_academiques aa ON f.annee_academique_id = aa.id
             LEFT JOIN utilisateurs u ON f.emis_par = u.id
             WHERE f.etudiant_id = ? 
             AND f.statut = 'payee'
             ORDER BY f.date_echeance DESC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les factures en retard
        $factures_en_retard = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais,
                    DATEDIFF(CURDATE(), f.date_echeance) as jours_retard
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             WHERE f.etudiant_id = ? 
             AND f.statut = 'en_retard'
             ORDER BY f.date_echeance ASC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les factures à venir
        $factures_a_venir = executeQuery($db,
            "SELECT f.*, tf.nom as type_frais,
                    DATEDIFF(f.date_echeance, CURDATE()) as jours_restants
             FROM factures f
             JOIN types_frais tf ON f.type_frais_id = tf.id
             WHERE f.etudiant_id = ? 
             AND f.statut IN ('en_attente', 'partiel')
             AND f.date_echeance >= CURDATE()
             ORDER BY f.date_echeance ASC
             LIMIT 10",
            [$etudiant_id]);
        
        // Récupérer les modes de paiement disponibles
        $modes_paiement = executeQuery($db,
            "SELECT * FROM modes_paiement WHERE active = 1 ORDER BY ordre ASC");
        
        // Récupérer l'historique des paiements liés aux factures
        $historique_paiements = executeQuery($db,
            "SELECT p.*, tf.nom as type_frais, f.numero_facture,
                    CONCAT(u.nom, ' ', u.prenom) as caissier_nom
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             LEFT JOIN factures f ON p.facture_id = f.id
             LEFT JOIN utilisateurs u ON p.caissier_id = u.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'valide'
             ORDER BY p.date_paiement DESC
             LIMIT 15",
            [$etudiant_id]);
        
        // Récupérer les paiements en attente
        $paiements_en_attente = executeQuery($db,
            "SELECT p.*, tf.nom as type_frais
             FROM paiements p
             JOIN types_frais tf ON p.type_frais_id = tf.id
             WHERE p.etudiant_id = ? 
             AND p.statut = 'en_attente'
             ORDER BY p.date_paiement ASC",
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
    <title><?php echo safeHtml($pageTitle); ?> - Dashboard Étudiant</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- DataTables pour les tableaux -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
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
    
    /* Sidebar - Même style que le dashboard principal */
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
        font-size: 1.8rem;
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
        padding: 12px;
        font-weight: 600;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px;
        color: var(--text-color);
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* DataTables custom */
    .dataTables_wrapper .dataTables_filter input {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .dataTables_wrapper .dataTables_length select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        background-color: var(--card-bg) !important;
        color: var(--text-color) !important;
        border-color: var(--border-color) !important;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background-color: var(--primary-color) !important;
        color: white !important;
        border-color: var(--primary-color) !important;
    }
    
    /* Tabs */
    .nav-tabs .nav-link {
        color: var(--text-color);
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-bottom: none;
        margin-right: 5px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
        height: 8px;
        border-radius: 4px;
        margin: 5px 0;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
        border-radius: 4px;
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
        border-left: 4px solid;
    }
    
    .alert-info {
        border-left-color: var(--info-color);
        background-color: rgba(23, 162, 184, 0.1);
    }
    
    .alert-success {
        border-left-color: var(--success-color);
        background-color: rgba(39, 174, 96, 0.1);
    }
    
    .alert-warning {
        border-left-color: var(--warning-color);
        background-color: rgba(243, 156, 18, 0.1);
    }
    
    .alert-danger {
        border-left-color: var(--accent-color);
        background-color: rgba(231, 76, 60, 0.1);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
        font-weight: 600;
    }
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 250px;
        width: 100%;
    }
    
    /* Boutons d'action */
    .action-btn {
        padding: 6px 12px;
        border-radius: 5px;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.875rem;
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    /* Montants */
    .amount {
        font-weight: 600;
        font-size: 1.1em;
    }
    
    .amount.positive {
        color: var(--success-color);
    }
    
    .amount.negative {
        color: var(--accent-color);
    }
    
    .amount.neutral {
        color: var(--text-color);
    }
    
    /* Facture widget */
    .facture-widget {
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        transition: all 0.3s;
        background-color: var(--card-bg);
    }
    
    .facture-widget:hover {
        border-color: var(--primary-color);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .facture-widget.retard {
        border-left: 4px solid var(--accent-color);
        background-color: rgba(231, 76, 60, 0.05);
    }
    
    .facture-widget.urgent {
        border-left: 4px solid var(--warning-color);
        background-color: rgba(243, 156, 18, 0.05);
    }
    
    .facture-widget.normal {
        border-left: 4px solid var(--info-color);
        background-color: rgba(23, 162, 184, 0.05);
    }
    
    .facture-widget.payee {
        border-left: 4px solid var(--success-color);
        background-color: rgba(39, 174, 96, 0.05);
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
    
    /* Section d'impression */
    .print-section {
        display: none;
    }
    
    @media print {
        .sidebar, .no-print, .action-buttons {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .print-section {
            display: block;
        }
        
        .card {
            box-shadow: none !important;
            border: 1px solid #000 !important;
        }
        
        .table {
            border: 1px solid #000 !important;
        }
        
        .table th, .table td {
            border: 1px solid #000 !important;
        }
    }
    
    /* Modal de paiement */
    .modal-content {
        background-color: var(--card-bg);
        color: var(--text-color);
        border: 1px solid var(--border-color);
    }
    
    .modal-header {
        border-bottom-color: var(--border-color);
    }
    
    .modal-footer {
        border-top-color: var(--border-color);
    }
    
    /* Timeline des paiements */
    .timeline-paiement {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline-paiement::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--border-color);
    }
    
    .timeline-item-paiement {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item-paiement::before {
        content: '';
        position: absolute;
        left: -23px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--success-color);
    }
    
    /* État de la facture */
    .etat-facture {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .etat-payee {
        background-color: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .etat-partiel {
        background-color: rgba(243, 156, 18, 0.2);
        color: var(--warning-color);
    }
    
    .etat-attente {
        background-color: rgba(52, 152, 219, 0.2);
        color: var(--secondary-color);
    }
    
    .etat-retard {
        background-color: rgba(231, 76, 60, 0.2);
        color: var(--accent-color);
    }
    
    /* Filtres */
    .filters-container {
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        margin-bottom: 10px;
    }
    
    /* Quick stats */
    .quick-stats {
        display: flex;
        justify-content: space-around;
        text-align: center;
        padding: 15px;
        background-color: var(--card-bg);
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .quick-stat-item {
        flex: 1;
        padding: 10px;
    }
    
    .quick-stat-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .quick-stat-label {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique au dashboard principal) -->
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
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Situation Financière</span>
                    </a>
                    <a href="factures.php" class="nav-link active">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Dettes</span>
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
                            <i class="fas fa-file-invoice-dollar me-2"></i>
                            Factures & Paiements
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['filiere_nom']) && !empty($info_etudiant['filiere_nom'])): ?>
                            <?php echo safeHtml($info_etudiant['filiere_nom']); ?> - 
                            <?php endif; ?>
                            Gestion de vos factures et paiements
                        </p>
                    </div>
                    <div class="btn-group action-buttons">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-info" onclick="genererReleve()">
                            <i class="fas fa-file-pdf"></i> Relevé
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section d'impression (visible uniquement à l'impression) -->
            <div class="print-section mb-4">
                <h4>Factures - <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></h4>
                <p>Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?> | Date: <?php echo date('d/m/Y H:i'); ?></p>
            </div>
            
            <!-- Section 1: Vue d'ensemble des factures -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_factures']; ?></div>
                        <div class="stat-label">Total Factures</div>
                        <div class="stat-change">
                            <span class="<?php echo $stats['factures_impayees'] > 0 ? 'negative' : 'positive'; ?>">
                                <?php echo $stats['factures_payees']; ?> payée(s)
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['montant_paye']); ?></div>
                        <div class="stat-label">Montant Payé</div>
                        <div class="stat-change">
                            <span class="<?php echo $stats['taux_paiement'] >= 80 ? 'positive' : ($stats['taux_paiement'] >= 50 ? 'warning' : 'negative'); ?>">
                                <i class="fas fa-percentage"></i> <?php echo $stats['taux_paiement']; ?>%
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['factures_en_retard']; ?></div>
                        <div class="stat-label">Factures en Retard</div>
                        <div class="stat-change">
                            <?php if($stats['factures_en_retard'] > 0): ?>
                            <span class="negative"><i class="fas fa-clock"></i> À régler d'urgence</span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check"></i> Aucun retard</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['montant_impaye']); ?></div>
                        <div class="stat-label">Restant à Payer</div>
                        <div class="stat-change">
                            <?php if($stats['montant_impaye'] > 0): ?>
                            <span class="negative"><i class="fas fa-calendar-day"></i> 
                                <?php if(!empty($stats['prochaine_echeance'])): ?>
                                Échéance: <?php echo formatDateFr($stats['prochaine_echeance'], 'd/m'); ?>
                                <?php else: ?>
                                À régler
                                <?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check"></i> Tout est payé</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick stats -->
            <div class="quick-stats mb-4">
                <div class="quick-stat-item">
                    <div class="quick-stat-value text-info"><?php echo $stats['factures_payees']; ?></div>
                    <div class="quick-stat-label">Payées</div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value text-warning"><?php echo $stats['factures_impayees']; ?></div>
                    <div class="quick-stat-label">En attente</div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value text-danger"><?php echo $stats['factures_en_retard']; ?></div>
                    <div class="quick-stat-label">En retard</div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-value text-success"><?php echo count($factures_a_venir); ?></div>
                    <div class="quick-stat-label">À venir</div>
                </div>
            </div>
            
            <!-- Alertes importantes -->
            <?php if($stats['factures_en_retard'] > 0): ?>
            <div class="alert alert-danger mb-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <strong>Attention!</strong> Vous avez <?php echo $stats['factures_en_retard']; ?> facture(s) en retard. 
                        Réglez-les immédiatement pour éviter les sanctions.
                    </div>
                    <button class="btn btn-danger" onclick="showRetardModal()">
                        <i class="fas fa-credit-card"></i> Régler maintenant
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if($stats['montant_impaye'] > 0 && $stats['factures_en_retard'] == 0): ?>
            <div class="alert alert-warning mb-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fas fa-clock fa-2x me-3"></i>
                        <strong>Rappel:</strong> Vous avez <?php echo formatMoney($stats['montant_impaye']); ?> à régler. 
                        <?php if(!empty($stats['prochaine_echeance'])): ?>
                        Prochaine échéance: <?php echo formatDateFr($stats['prochaine_echeance']); ?>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-warning" onclick="showPaiementModal()">
                        <i class="fas fa-calendar-check"></i> Programmer paiement
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section 2: Graphiques -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Répartition des Factures
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="facturesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-line me-2"></i>
                                Évolution des Paiements
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="evolutionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="facturesTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="en-cours-tab" data-bs-toggle="tab" data-bs-target="#en-cours" type="button">
                                <i class="fas fa-clock me-2"></i>En Cours
                                <?php if($stats['factures_en_retard'] > 0): ?>
                                <span class="badge bg-danger ms-2"><?php echo $stats['factures_en_retard']; ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="payees-tab" data-bs-toggle="tab" data-bs-target="#payees" type="button">
                                <i class="fas fa-check-circle me-2"></i>Payées
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="a-venir-tab" data-bs-toggle="tab" data-bs-target="#a-venir" type="button">
                                <i class="fas fa-calendar-day me-2"></i>À Venir
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="historique-tab" data-bs-toggle="tab" data-bs-target="#historique" type="button">
                                <i class="fas fa-history me-2"></i>Historique
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="facturesTabsContent">
                        <!-- Tab 1: Factures en Cours -->
                        <div class="tab-pane fade show active" id="en-cours">
                            <!-- Filtres -->
                            <div class="filters-container mb-4">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="filter-group">
                                            <label class="form-label">Filtrer par statut:</label>
                                            <select class="form-select" id="filterStatut">
                                                <option value="">Tous les statuts</option>
                                                <option value="en_attente">En attente</option>
                                                <option value="partiel">Paiement partiel</option>
                                                <option value="en_retard">En retard</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="filter-group">
                                            <label class="form-label">Filtrer par type:</label>
                                            <select class="form-select" id="filterType">
                                                <option value="">Tous les types</option>
                                                <option value="scolarite">Scolarité</option>
                                                <option value="inscription">Inscription</option>
                                                <option value="examen">Examen</option>
                                                <option value="stage">Stage</option>
                                                <option value="divers">Divers</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="filter-group">
                                            <label class="form-label">Tri par:</label>
                                            <select class="form-select" id="filterTri">
                                                <option value="date">Date d'échéance</option>
                                                <option value="montant">Montant restant</option>
                                                <option value="statut">Statut</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if(empty($factures_en_cours)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune facture en cours. Toutes vos factures sont payées!
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="tableFacturesEnCours">
                                    <thead>
                                        <tr>
                                            <th>N° Facture</th>
                                            <th>Type</th>
                                            <th>Montant Total</th>
                                            <th>Payé</th>
                                            <th>Restant</th>
                                            <th>Émission</th>
                                            <th>Échéance</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($factures_en_cours as $facture): 
                                            $jours_restants = isset($facture['jours_restants']) ? intval($facture['jours_restants']) : 0;
                                            $statut_class = '';
                                            if ($facture['statut'] == 'en_retard') {
                                                $statut_class = 'retard';
                                            } elseif ($jours_restants <= 3) {
                                                $statut_class = 'urgent';
                                            } else {
                                                $statut_class = 'normal';
                                            }
                                        ?>
                                        <tr class="facture-row" data-statut="<?php echo safeHtml($facture['statut']); ?>" data-type="<?php echo safeHtml($facture['type_facture'] ?? ''); ?>">
                                            <td>
                                                <strong><?php echo safeHtml($facture['numero_facture'] ?? ''); ?></strong>
                                                <?php if(!empty($facture['description'])): ?>
                                                <br><small class="text-muted"><?php echo safeHtml(substr($facture['description'], 0, 50)); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($facture['type_frais'] ?? ''); ?>
                                                <br><small class="text-muted"><?php echo safeHtml($facture['annee_academique'] ?? ''); ?></small>
                                            </td>
                                            <td class="amount neutral"><?php echo formatMoney($facture['montant_total'] ?? 0); ?></td>
                                            <td class="amount positive"><?php echo formatMoney($facture['montant_paye'] ?? 0); ?></td>
                                            <td class="amount negative"><?php echo formatMoney($facture['montant_restant'] ?? 0); ?></td>
                                            <td>
                                                <?php echo formatDateFr($facture['date_emission'] ?? ''); ?>
                                                <?php if(!empty($facture['emis_par_nom'])): ?>
                                                <br><small class="text-muted">Par: <?php echo safeHtml($facture['emis_par_nom']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span><?php echo formatDateFr($facture['date_echeance'] ?? ''); ?></span>
                                                    <?php if($jours_restants >= 0): ?>
                                                    <small class="text-<?php echo $jours_restants <= 3 ? 'danger' : ($jours_restants <= 7 ? 'warning' : 'muted'); ?>">
                                                        <?php echo $jours_restants; ?> jour(s) restant(s)
                                                    </small>
                                                    <?php else: ?>
                                                    <small class="text-danger">
                                                        <?php echo abs($jours_restants); ?> jour(s) de retard
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo getStatutBadge($facture['statut'] ?? ''); ?>
                                                <br><?php echo getPrioriteBadge($jours_restants); ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="voirFacture(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if($facture['montant_restant'] > 0): ?>
                                                    <button class="btn btn-outline-success" onclick="payerFacture(<?php echo $facture['id']; ?>, <?php echo floatval($facture['montant_restant']); ?>)">
                                                        <i class="fas fa-credit-card"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-info" onclick="telechargerFacture(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning" onclick="contesterFacture(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-question-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Résumé des factures en cours -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <h6><i class="fas fa-info-circle"></i> Informations</h6>
                                                <small>Total factures en cours: <?php echo count($factures_en_cours); ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <h6><i class="fas fa-money-bill"></i> Montants</h6>
                                                <small>Total à payer: <?php echo formatMoney($stats['montant_impaye']); ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <h6><i class="fas fa-calendar"></i> Dates</h6>
                                                <small>
                                                    <?php if(!empty($stats['prochaine_echeance'])): ?>
                                                    Prochaine échéance: <?php echo formatDateFr($stats['prochaine_echeance']); ?>
                                                    <?php else: ?>
                                                    Aucune échéance
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div class="col-md-3">
                                                <h6><i class="fas fa-percentage"></i> Paiement</h6>
                                                <small>Taux de paiement: <?php echo $stats['taux_paiement']; ?>%</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Factures Payées -->
                        <div class="tab-pane fade" id="payees">
                            <?php if(empty($factures_payees)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucune facture payée à afficher
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="tableFacturesPayees">
                                    <thead>
                                        <tr>
                                            <th>N° Facture</th>
                                            <th>Type</th>
                                            <th>Montant Total</th>
                                            <th>Date de paiement</th>
                                            <th>Mode de paiement</th>
                                            <th>Reçu</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Associer les paiements aux factures
                                        $factures_avec_paiements = array();
                                        foreach($factures_payees as $facture) {
                                            $paiements_facture = array();
                                            foreach($historique_paiements as $paiement) {
                                                if(isset($paiement['numero_facture']) && $paiement['numero_facture'] == $facture['numero_facture']) {
                                                    $paiements_facture[] = $paiement;
                                                }
                                            }
                                            $facture['paiements'] = $paiements_facture;
                                            $factures_avec_paiements[] = $facture;
                                        }
                                        
                                        foreach($factures_avec_paiements as $facture): 
                                            $dernier_paiement = !empty($facture['paiements']) ? $facture['paiements'][0] : null;
                                        ?>
                                        <tr class="facture-payee-row">
                                            <td>
                                                <strong><?php echo safeHtml($facture['numero_facture'] ?? ''); ?></strong>
                                                <br><small class="text-muted">Échéance: <?php echo formatDateFr($facture['date_echeance'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <?php echo safeHtml($facture['type_frais'] ?? ''); ?>
                                                <br><small class="text-muted"><?php echo safeHtml($facture['annee_academique'] ?? ''); ?></small>
                                            </td>
                                            <td class="amount neutral"><?php echo formatMoney($facture['montant_total'] ?? 0); ?></td>
                                            <td>
                                                <?php if($dernier_paiement): ?>
                                                <?php echo formatDateFr($dernier_paiement['date_paiement'] ?? ''); ?>
                                                <br><small class="text-muted">Ref: <?php echo safeHtml($dernier_paiement['reference'] ?? ''); ?></small>
                                                <?php else: ?>
                                                <span class="text-muted">Non disponible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($dernier_paiement): ?>
                                                <?php echo safeHtml($dernier_paiement['mode_paiement'] ?? ''); ?>
                                                <?php if(!empty($dernier_paiement['numero_transaction'])): ?>
                                                <br><small class="text-muted">N°: <?php echo safeHtml($dernier_paiement['numero_transaction']); ?></small>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($dernier_paiement): ?>
                                                <span class="badge bg-success">Disponible</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">Non disponible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="voirFacture(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-success" onclick="telechargerFacture(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <button class="btn btn-outline-info" onclick="telechargerRecu(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-file-invoice"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning" onclick="imprimerAttestation(<?php echo $facture['id']; ?>)">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Timeline des paiements -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5 class="mb-0"><i class="fas fa-history me-2"></i> Derniers Paiements</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="timeline-paiement">
                                                <?php 
                                                $paiements_recents = array_slice($historique_paiements, 0, 5);
                                                if(empty($paiements_recents)): ?>
                                                <div class="alert alert-info">
                                                    Aucun historique de paiement récent
                                                </div>
                                                <?php else: 
                                                    foreach($paiements_recents as $paiement): ?>
                                                    <div class="timeline-item-paiement">
                                                        <div class="card mb-3">
                                                            <div class="card-body">
                                                                <div class="d-flex justify-content-between align-items-start">
                                                                    <div>
                                                                        <h6 class="mb-1"><?php echo safeHtml($paiement['type_frais'] ?? ''); ?></h6>
                                                                        <p class="mb-1 small">
                                                                            Référence: <strong><?php echo safeHtml($paiement['reference'] ?? ''); ?></strong>
                                                                            <?php if(!empty($paiement['numero_facture'])): ?>
                                                                            <br>Facture: <?php echo safeHtml($paiement['numero_facture']); ?>
                                                                            <?php endif; ?>
                                                                        </p>
                                                                    </div>
                                                                    <div class="text-end">
                                                                        <div class="amount positive"><?php echo formatMoney($paiement['montant'] ?? 0); ?></div>
                                                                        <small class="text-muted"><?php echo formatDateFr($paiement['date_paiement'] ?? '', 'd/m/Y H:i'); ?></small>
                                                                        <?php if(!empty($paiement['caissier_nom'])): ?>
                                                                        <br><small>Par: <?php echo safeHtml($paiement['caissier_nom']); ?></small>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; 
                                                endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 3: Factures à Venir -->
                        <div class="tab-pane fade" id="a-venir">
                            <?php if(empty($factures_a_venir)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Aucune facture à venir pour le moment
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($factures_a_venir as $facture): 
                                    $jours_restants = isset($facture['jours_restants']) ? intval($facture['jours_restants']) : 0;
                                    $widget_class = '';
                                    if ($jours_restants <= 3) {
                                        $widget_class = 'urgent';
                                    } elseif ($jours_restants <= 7) {
                                        $widget_class = 'normal';
                                    } else {
                                        $widget_class = 'normal';
                                    }
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="facture-widget <?php echo $widget_class; ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h6 class="mb-1"><?php echo safeHtml($facture['type_frais'] ?? ''); ?></h6>
                                                <p class="mb-0 text-muted small"><?php echo safeHtml($facture['numero_facture'] ?? ''); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="amount neutral"><?php echo formatMoney($facture['montant_total'] ?? 0); ?></div>
                                                <span class="badge bg-<?php echo $jours_restants <= 3 ? 'warning' : ($jours_restants <= 7 ? 'info' : 'secondary'); ?>">
                                                    J-<?php echo $jours_restants; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="progress">
                                                <div class="progress-bar bg-<?php echo $jours_restants <= 3 ? 'warning' : 'info'; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo ($facture['montant_paye'] / $facture['montant_total']) * 100; ?>%">
                                                    <?php echo round(($facture['montant_paye'] / $facture['montant_total']) * 100, 1); ?>%
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between small text-muted">
                                                <span>Payé: <?php echo formatMoney($facture['montant_paye'] ?? 0); ?></span>
                                                <span>Restant: <?php echo formatMoney($facture['montant_restant'] ?? 0); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                Échéance: <?php echo formatDateFr($facture['date_echeance'] ?? ''); ?>
                                            </small>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" onclick="voirFacture(<?php echo $facture['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-success btn-sm" onclick="programmerPaiement(<?php echo $facture['id']; ?>)">
                                                    <i class="fas fa-calendar-plus"></i>
                                                </button>
                                                <button class="btn btn-outline-info btn-sm" onclick="telechargerFacture(<?php echo $facture['id']; ?>)">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Calendrier des échéances -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i> Calendrier des Échéances</h5>
                                        </div>
                                        <div class="card-body">
                                            <div id="calendrierEcheances"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Tab 4: Historique -->
                        <div class="tab-pane fade" id="historique">
                            <!-- Filtres pour l'historique -->
                            <div class="filters-container mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="filter-group">
                                            <label class="form-label">Période:</label>
                                            <select class="form-select" id="filterPeriode">
                                                <option value="30">30 derniers jours</option>
                                                <option value="90">3 derniers mois</option>
                                                <option value="180">6 derniers mois</option>
                                                <option value="365">1 an</option>
                                                <option value="all">Tout l'historique</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="filter-group">
                                            <label class="form-label">Type:</label>
                                            <select class="form-select" id="filterTypeHistorique">
                                                <option value="">Tous types</option>
                                                <option value="facture">Factures</option>
                                                <option value="paiement">Paiements</option>
                                                <option value="remboursement">Remboursements</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="filter-group">
                                            <label class="form-label">Statut:</label>
                                            <select class="form-select" id="filterStatutHistorique">
                                                <option value="">Tous statuts</option>
                                                <option value="valide">Validé</option>
                                                <option value="annule">Annulé</option>
                                                <option value="en_attente">En attente</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="filter-group">
                                            <label class="form-label">Montant minimum:</label>
                                            <input type="number" class="form-control" id="filterMontantMin" placeholder="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if(empty($historique_paiements) && empty($factures_payees)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Aucun historique disponible
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="tableHistorique">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Référence</th>
                                            <th>Montant</th>
                                            <th>Statut</th>
                                            <th>Mode</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Combiner factures et paiements pour l'historique
                                        $historique_complet = array();
                                        
                                        // Ajouter les factures
                                        foreach(array_merge($factures_en_cours, $factures_payees) as $facture) {
                                            $historique_complet[] = array(
                                                'type' => 'facture',
                                                'date' => $facture['date_emission'],
                                                'description' => $facture['type_frais'] . ' - ' . $facture['numero_facture'],
                                                'reference' => $facture['numero_facture'],
                                                'montant' => $facture['montant_total'],
                                                'statut' => $facture['statut'],
                                                'mode' => '',
                                                'id' => $facture['id']
                                            );
                                        }
                                        
                                        // Ajouter les paiements
                                        foreach($historique_paiements as $paiement) {
                                            $historique_complet[] = array(
                                                'type' => 'paiement',
                                                'date' => $paiement['date_paiement'],
                                                'description' => $paiement['type_frais'],
                                                'reference' => $paiement['reference'],
                                                'montant' => $paiement['montant'],
                                                'statut' => $paiement['statut'],
                                                'mode' => $paiement['mode_paiement'],
                                                'id' => $paiement['id']
                                            );
                                        }
                                        
                                        // Trier par date
                                        usort($historique_complet, function($a, $b) {
                                            return strtotime($b['date']) - strtotime($a['date']);
                                        });
                                        
                                        // Limiter à 20 éléments
                                        $historique_complet = array_slice($historique_complet, 0, 20);
                                        
                                        foreach($historique_complet as $item): 
                                            $montant_class = $item['type'] == 'paiement' ? 'positive' : 'neutral';
                                            $type_badge = $item['type'] == 'facture' ? 'info' : 'success';
                                        ?>
                                        <tr>
                                            <td><?php echo formatDateFr($item['date'] ?? '', 'd/m/Y H:i'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $type_badge; ?>">
                                                    <?php echo ucfirst($item['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo safeHtml($item['description'] ?? ''); ?></td>
                                            <td><code><?php echo safeHtml($item['reference'] ?? ''); ?></code></td>
                                            <td class="amount <?php echo $montant_class; ?>"><?php echo formatMoney($item['montant'] ?? 0); ?></td>
                                            <td><?php echo getStatutBadge($item['statut'] ?? ''); ?></td>
                                            <td>
                                                <?php if(!empty($item['mode'])): ?>
                                                <small><?php echo safeHtml($item['mode']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($item['type'] == 'facture'): ?>
                                                <button class="btn btn-sm btn-outline-primary" onclick="voirFacture(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php else: ?>
                                                <button class="btn btn-sm btn-outline-info" onclick="voirPaiement(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-receipt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Statistiques de l'historique -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Statistiques Mensuelles</h5>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="historiqueChart" height="100"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Modes de paiement et informations -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-credit-card me-2"></i>
                                Modes de Paiement Disponibles
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($modes_paiement)): ?>
                            <div class="alert alert-warning">
                                Aucun mode de paiement configuré
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach($modes_paiement as $mode): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <div class="mb-3">
                                                <i class="fas fa-<?php 
                                                    switch($mode['code']) {
                                                        case 'espece': echo 'money-bill-wave'; break;
                                                        case 'virement': echo 'university'; break;
                                                        case 'mtn_momo': echo 'mobile-alt'; break;
                                                        case 'airtel_money': echo 'sim-card'; break;
                                                        case 'cheque': echo 'file-invoice-dollar'; break;
                                                        default: echo 'credit-card';
                                                    }
                                                ?> fa-3x text-primary"></i>
                                            </div>
                                            <h6><?php echo safeHtml($mode['nom']); ?></h6>
                                            <?php if(!empty($mode['description'])): ?>
                                            <p class="small text-muted"><?php echo safeHtml($mode['description']); ?></p>
                                            <?php endif; ?>
                                            <?php if(floatval($mode['frais_pourcentage']) > 0): ?>
                                            <div class="badge bg-warning">
                                                Frais: <?php echo $mode['frais_pourcentage']; ?>%
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-footer text-center">
                                            <button class="btn btn-sm btn-outline-primary" onclick="utiliserModePaiement('<?php echo safeHtml($mode['code']); ?>')">
                                                Utiliser
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Informations de paiement -->
                            <div class="alert alert-info mt-3">
                                <h6><i class="fas fa-info-circle me-2"></i> Informations importantes</h6>
                                <ul class="mb-0 small">
                                    <li>Les paiements par mobile money ont des frais de transaction de 1.5%</li>
                                    <li>Les virements bancaires peuvent prendre 24-48h pour être validés</li>
                                    <li>Conservez toujours vos reçus de paiement</li>
                                    <li>Pour toute question, contactez le service financier</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-question-circle me-2"></i>
                                Aide & Support
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-phone-alt"></i> Contacts du Service Financier</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Téléphone:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Email:</strong> finances@isgi.cg</li>
                                    <li><strong>WhatsApp:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Horaires:</strong> 8h00 - 16h00 (Lun-Ven)</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Procédures importantes</h6>
                                <ul class="mb-0 small">
                                    <li>Les retards de paiement entraînent des pénalités de 10% du montant</li>
                                    <li>Après 30 jours de retard, l'accès aux cours peut être suspendu</li>
                                    <li>Les contestations doivent être faites dans les 7 jours</li>
                                    <li>Les reçus de paiement sont disponibles pendant 1 an</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-lightbulb"></i> Conseils pratiques</h6>
                                <ul class="mb-0 small">
                                    <li>Programmez vos paiements à l'avance pour éviter les retards</li>
                                    <li>Utilisez les alertes par email pour les échéances</li>
                                    <li>Téléchargez et archivez tous vos documents</li>
                                    <li>Vérifiez régulièrement votre solde et vos échéances</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de paiement -->
    <div class="modal fade" id="paiementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-credit-card me-2"></i> Effectuer un Paiement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="paiementContent">
                        <!-- Contenu chargé dynamiquement -->
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
        
        // Initialiser DataTables
        $('#tableFacturesEnCours').DataTable({
            pageLength: 10,
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/fr-FR.json'
            },
            order: [[6, 'asc']]
        });
        
        $('#tableFacturesPayees').DataTable({
            pageLength: 10,
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/fr-FR.json'
            },
            order: [[0, 'desc']]
        });
        
        $('#tableHistorique').DataTable({
            pageLength: 10,
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/fr-FR.json'
            },
            order: [[0, 'desc']]
        });
        
        // Graphique des factures
        const ctxFactures = document.getElementById('facturesChart').getContext('2d');
        new Chart(ctxFactures, {
            type: 'doughnut',
            data: {
                labels: ['Payées', 'En attente', 'Partiel', 'En retard'],
                datasets: [{
                    data: [
                        <?php echo $stats['factures_payees']; ?>,
                        <?php echo $stats['factures_impayees'] - $stats['factures_en_retard']; ?>,
                        <?php echo 0; // À calculer selon vos données ?>,
                        <?php echo $stats['factures_en_retard']; ?>
                    ],
                    backgroundColor: [
                        '#27ae60',
                        '#3498db',
                        '#f39c12',
                        '#e74c3c'
                    ],
                    borderWidth: 1
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
                        text: 'Répartition des factures par statut'
                    }
                }
            }
        });
        
        // Graphique d'évolution
        const ctxEvolution = document.getElementById('evolutionChart').getContext('2d');
        new Chart(ctxEvolution, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                datasets: [{
                    label: 'Factures émises',
                    data: [2, 3, 1, 2, 3, 2, 1, 2, 3, 2, 1, 2],
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Factures payées',
                    data: [2, 2, 1, 2, 2, 2, 1, 2, 2, 2, 1, 1],
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Évolution mensuelle des factures'
                    }
                }
            }
        });
        
        // Graphique historique
        const ctxHistorique = document.getElementById('historiqueChart').getContext('2d');
        new Chart(ctxHistorique, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Montant (FCFA)',
                    data: [150000, 120000, 180000, 200000, 220000, 190000],
                    backgroundColor: [
                        '#3498db', '#2ecc71', '#9b59b6', '#f39c12', '#e74c3c', '#1abc9c'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
                            }
                        }
                    }
                }
            }
        });
        
        // Initialiser le calendrier des échéances
        initCalendrierEcheances();
        
        // Gestion des filtres
        document.getElementById('filterStatut').addEventListener('change', filterFactures);
        document.getElementById('filterType').addEventListener('change', filterFactures);
        document.getElementById('filterTri').addEventListener('change', filterFactures);
        
        // Modal de paiement
        const paiementModal = new bootstrap.Modal(document.getElementById('paiementModal'));
    });
    
    // Fonctions pour les actions
    function voirFacture(factureId) {
        alert('Affichage de la facture #' + factureId);
        // window.location.href = 'facture_details.php?id=' + factureId;
    }
    
    function payerFacture(factureId, montant) {
        const modal = new bootstrap.Modal(document.getElementById('paiementModal'));
        document.getElementById('paiementContent').innerHTML = `
            <div class="text-center mb-4">
                <i class="fas fa-credit-card fa-4x text-primary mb-3"></i>
                <h4>Paiement de facture</h4>
                <p class="text-muted">Montant à payer: <strong>${formatMontant(montant)}</strong></p>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-mobile-alt fa-3x text-success mb-3"></i>
                            <h5>Mobile Money</h5>
                            <p class="small text-muted">Paiement instantané avec frais de 1.5%</p>
                            <button class="btn btn-success w-100" onclick="payerMobileMoney(${factureId}, ${montant})">
                                Payer avec Mobile Money
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-university fa-3x text-primary mb-3"></i>
                            <h5>Virement Bancaire</h5>
                            <p class="small text-muted">Virement sans frais (24-48h)</p>
                            <button class="btn btn-primary w-100" onclick="payerVirement(${factureId}, ${montant})">
                                Payer par Virement
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Informations importantes</h6>
                <ul class="mb-0 small">
                    <li>Les paiements sont validés dans les 24h ouvrables</li>
                    <li>Conservez votre numéro de transaction</li>
                    <li>Le reçu sera disponible dans votre espace</li>
                </ul>
            </div>
        `;
        modal.show();
    }
    
    function programmerPaiement(factureId) {
        const date = prompt('Date de paiement programmé (JJ/MM/AAAA):');
        if(date && isValidDate(date)) {
            if(confirm(`Programmer le paiement pour le ${date} ?`)) {
                alert('Paiement programmé avec succès!');
                // Envoyer la requête AJAX
            }
        } else if(date) {
            alert('Date invalide. Format attendu: JJ/MM/AAAA');
        }
    }
    
    function telechargerFacture(factureId) {
        alert('Téléchargement de la facture #' + factureId);
        // window.location.href = 'telecharger_facture.php?id=' + factureId;
    }
    
    function telechargerRecu(factureId) {
        alert('Téléchargement du reçu pour la facture #' + factureId);
        // window.location.href = 'telecharger_recu.php?id=' + factureId;
    }
    
    function contesterFacture(factureId) {
        const motif = prompt('Motif de la contestation:');
        if(motif) {
            if(confirm(`Contester la facture #${factureId} avec le motif: "${motif}" ?`)) {
                alert('Contestation envoyée. Vous serez recontacté dans les 48h.');
                // Envoyer la requête AJAX
            }
        }
    }
    
    function voirPaiement(paiementId) {
        alert('Affichage du paiement #' + paiementId);
        // window.location.href = 'paiement_details.php?id=' + paiementId;
    }
    
    function utiliserModePaiement(mode) {
        alert('Utilisation du mode de paiement: ' + mode);
        // Logique pour utiliser le mode de paiement
    }
    
    function genererReleve() {
        if(confirm('Générer un relevé de factures ?')) {
            alert('Génération du relevé en cours... Le PDF sera téléchargé.');
            // window.location.href = 'generer_releve.php';
        }
    }
    
    function imprimerAttestation(factureId) {
        alert('Impression de l\'attestation pour la facture #' + factureId);
        // window.location.href = 'imprimer_attestation.php?id=' + factureId;
    }
    
    function showRetardModal() {
        alert('Affichage des factures en retard');
        // Afficher uniquement les factures en retard
        document.querySelector('[data-bs-target="#en-cours"]').click();
        document.getElementById('filterStatut').value = 'en_retard';
        filterFactures();
    }
    
    function showPaiementModal() {
        document.querySelector('[data-bs-target="#en-cours"]').click();
        payerFacture(0, <?php echo $stats['montant_impaye']; ?>);
    }
    
    // Fonctions utilitaires
    function formatMontant(montant) {
        return new Intl.NumberFormat('fr-FR').format(montant) + ' FCFA';
    }
    
    function isValidDate(dateString) {
        const regex = /^\d{2}\/\d{2}\/\d{4}$/;
        if(!regex.test(dateString)) return false;
        const parts = dateString.split('/');
        const day = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10);
        const year = parseInt(parts[2], 10);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }
    
    // Filtrage des factures
    function filterFactures() {
        const statut = document.getElementById('filterStatut').value;
        const type = document.getElementById('filterType').value;
        const tri = document.getElementById('filterTri').value;
        
        const rows = document.querySelectorAll('.facture-row');
        rows.forEach(row => {
            const rowStatut = row.getAttribute('data-statut');
            const rowType = row.getAttribute('data-type');
            
            let show = true;
            if(statut && rowStatut !== statut) show = false;
            if(type && rowType !== type) show = false;
            
            row.style.display = show ? '' : 'none';
        });
        
        // Trier les lignes visibles
        // À implémenter selon le tri sélectionné
    }
    
    // Calendrier des échéances
    function initCalendrierEcheances() {
        const echeances = [
            <?php foreach($factures_a_venir as $facture): ?>
            {
                title: '<?php echo safeHtml($facture["type_frais"] ?? ""); ?>',
                start: '<?php echo $facture["date_echeance"] ?? ""; ?>',
                color: '<?php 
                    $jours = isset($facture["jours_restants"]) ? intval($facture["jours_restants"]) : 0;
                    if($jours <= 3) echo "#e74c3c";
                    elseif($jours <= 7) echo "#f39c12";
                    else echo "#3498db";
                ?>',
                montant: '<?php echo formatMoney($facture["montant_restant"] ?? 0); ?>'
            },
            <?php endforeach; ?>
        ];
        
        const calendrierEl = document.getElementById('calendrierEcheances');
        if (calendrierEl) {
            if(echeances.length === 0 || echeances[0].title === '') {
                calendrierEl.innerHTML = '<div class="alert alert-info">Aucune échéance à venir</div>';
                return;
            }
            
            let html = '<div class="list-group">';
            echeances.forEach(echeance => {
                if(!echeance.title) return;
                const date = new Date(echeance.start);
                const joursRestants = Math.ceil((date - new Date()) / (1000 * 60 * 60 * 24));
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge" style="background-color: ${echeance.color}">&nbsp;&nbsp;</span>
                                <strong>${echeance.title}</strong>
                                <br><small class="text-muted">${echeance.montant}</small>
                            </div>
                            <div>
                                <span class="badge bg-${joursRestants <= 7 ? 'warning' : 'info'}">
                                    ${date.toLocaleDateString('fr-FR')} (J-${joursRestants})
                                </span>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            calendrierEl.innerHTML = html;
        }
    }
    
    // Simulation de paiement
    function payerMobileMoney(factureId, montant) {
        const numero = prompt('Numéro de téléphone:');
        if(numero && numero.length >= 9) {
            if(confirm(`Confirmer le paiement de ${formatMontant(montant)} via Mobile Money au numéro ${numero} ?`)) {
                alert('Paiement initié. Vous recevrez une demande de confirmation sur votre téléphone.');
                // Logique de paiement mobile money
            }
        } else if(numero) {
            alert('Numéro de téléphone invalide');
        }
    }
    
    function payerVirement(factureId, montant) {
        const iban = prompt('IBAN ou numéro de compte:');
        if(iban && iban.length >= 10) {
            alert(`Instructions de virement:\n\nMontant: ${formatMontant(montant)}\nIBAN: ${iban}\nBénéficiaire: ISGI\nCommunication: FACTURE-${factureId}\n\nLe virement sera validé dans 24-48h.`);
            // Afficher les instructions de virement
        } else if(iban) {
            alert('IBAN invalide');
        }
    }
    </script>
</body>
</html>