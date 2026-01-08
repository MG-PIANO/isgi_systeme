<?php
// dashboard/etudiant/dashboard.php

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
    $pageTitle = "Tableau de Bord Étudiant";
    
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
            case 'actif':
            case 'valide':
            case 'present':
            case 'admis':
                return '<span class="badge bg-success">Actif</span>';
            case 'inactif':
            case 'en_attente':
            case 'en_cours':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
            case 'rejete':
            case 'absent':
            case 'en_retard':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'terminee':
            case 'retourne':
            case 'soldee':
                return '<span class="badge bg-info">Terminé</span>';
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
    $stats = array(
        'moyenne_generale' => 0,
        'taux_presence' => 0,
        'total_dettes' => 0,
        'cours_actifs' => 0,
        'examens_prochains' => 0,
        'livres_disponibles' => 0,
        'documents_importants' => 0,
        'reunions_prochaines' => 0
    );
    
    $info_etudiant = array();
    $notes_recentes = array();
    $presence_mois = array();
    $dettes_detail = array();
    $cours_semaine = array();
    $examens_prochains = array();
    $annonces_recentes = array();
    $livres_recommandes = array();
    $documents_personnels = array();
    $calendrier_events = array();
    $reunions_prochaines = array();
    $salles_disponibles = array();
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
        
        // Récupérer la moyenne générale (si disponible)
        $result = executeSingleQuery($db, 
            "SELECT AVG(n.note) as moyenne 
             FROM notes n 
             JOIN etudiants e ON n.etudiant_id = e.id 
             WHERE e.id = ? AND n.statut = 'valide'",
            [$etudiant_id]);
        $stats['moyenne_generale'] = isset($result['moyenne']) ? round(floatval($result['moyenne']), 2) : 0;
        
        // Récupérer le taux de présence du mois
        $currentMonth = date('m');
        $currentYear = date('Y');
        $result = executeSingleQuery($db,
            "SELECT 
                COUNT(CASE WHEN statut = 'present' THEN 1 END) as presents,
                COUNT(*) as total
             FROM presences 
             WHERE etudiant_id = ? 
             AND MONTH(date_heure) = ? 
             AND YEAR(date_heure) = ?",
            [$etudiant_id, $currentMonth, $currentYear]);
        
        if (isset($result['total']) && $result['total'] > 0) {
            $stats['taux_presence'] = round((intval($result['presents']) / intval($result['total'])) * 100, 1);
        }
        
        // Récupérer les dettes
        $result = executeSingleQuery($db,
            "SELECT COALESCE(SUM(montant_restant), 0) as total 
             FROM dettes 
             WHERE etudiant_id = ? AND statut IN ('en_cours', 'en_retard')",
            [$etudiant_id]);
        $stats['total_dettes'] = isset($result['total']) ? floatval($result['total']) : 0;
        
        // Récupérer les cours actifs (matières de la classe)
        if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
            $result = executeSingleQuery($db,
                "SELECT COUNT(*) as total 
                 FROM matieres m
                 JOIN classes c ON m.filiere_id = c.filiere_id 
                 AND m.niveau_id = c.niveau_id
                 WHERE c.id = ?",
                [intval($info_etudiant['classe_id'])]);
            $stats['cours_actifs'] = isset($result['total']) ? intval($result['total']) : 0;
        }
        
        // Récupérer les examens prochains (7 prochains jours)
        if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
            $result = executeSingleQuery($db,
                "SELECT COUNT(*) as total 
                 FROM calendrier_examens ce
                 JOIN classes c ON ce.classe_id = c.id
                 WHERE c.id = ? 
                 AND ce.date_examen >= CURDATE() 
                 AND ce.date_examen <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 AND ce.statut = 'planifie'",
                [intval($info_etudiant['classe_id'])]);
            $stats['examens_prochains'] = isset($result['total']) ? intval($result['total']) : 0;
        }
        
        // Récupérer les livres recommandés (par filière)
        $site_id = isset($info_etudiant['site_id']) ? intval($info_etudiant['site_id']) : 0;
        $filiere_id = isset($info_etudiant['filiere_id']) ? intval($info_etudiant['filiere_id']) : 0;
        
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM bibliotheque_livres bl
             WHERE bl.site_id = ? 
             AND (bl.filiere_id = ? OR bl.filiere_id IS NULL OR bl.filiere_id = 0)
             AND bl.statut = 'disponible'",
            [$site_id, $filiere_id]);
        $stats['livres_disponibles'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Récupérer les documents importants
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM documents_etudiants 
             WHERE etudiant_id = ? AND statut = 'valide'",
            [$etudiant_id]);
        $stats['documents_importants'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Récupérer les réunions prochaines
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total 
             FROM reunions r
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion >= CURDATE()
             AND r.statut = 'planifiee'",
            [$user_id]);
        $stats['reunions_prochaines'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Récupérer les notes récentes
        $notes_recentes = executeQuery($db,
            "SELECT n.*, m.nom as matiere_nom, m.coefficient, te.nom as type_examen
             FROM notes n
             JOIN matieres m ON n.matiere_id = m.id
             JOIN types_examens te ON n.type_examen_id = te.id
             WHERE n.etudiant_id = ? 
             AND n.statut = 'valide'
             ORDER BY n.date_evaluation DESC
             LIMIT 5",
            [$etudiant_id]);
        
        // Récupérer les présences du mois
        $presence_mois = executeQuery($db,
            "SELECT p.*, m.nom as matiere_nom, s.nom as surveillant_nom
             FROM presences p
             LEFT JOIN matieres m ON p.matiere_id = m.id
             LEFT JOIN utilisateurs s ON p.surveillant_id = s.id
             WHERE p.etudiant_id = ? 
             AND MONTH(p.date_heure) = ? 
             AND YEAR(p.date_heure) = ?
             ORDER BY p.date_heure DESC
             LIMIT 10",
            [$etudiant_id, $currentMonth, $currentYear]);
        
        // Récupérer les détails des dettes
        $dettes_detail = executeQuery($db,
            "SELECT d.*, aa.libelle as annee_academique
             FROM dettes d
             JOIN annees_academiques aa ON d.annee_academique_id = aa.id
             WHERE d.etudiant_id = ? 
             AND d.statut IN ('en_cours', 'en_retard')
             ORDER BY d.date_limite ASC
             LIMIT 5",
            [$etudiant_id]);
        
        // Récupérer les cours de la semaine
        if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
            $cours_semaine = executeQuery($db,
                "SELECT edt.*, m.nom as matiere_nom, 
                        CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
                 FROM emploi_du_temps edt
                 JOIN matieres m ON edt.matiere_id = m.id
                 JOIN enseignants e ON edt.enseignant_id = e.id
                 JOIN utilisateurs u ON e.utilisateur_id = u.id
                 WHERE edt.classe_id = ? 
                 AND edt.date_creation >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY edt.jour_semaine, edt.heure_debut
                 LIMIT 10",
                [intval($info_etudiant['classe_id'])]);
        }
        
        // Récupérer les examens prochains
        if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
            $examens_prochains = executeQuery($db,
                "SELECT ce.*, m.nom as matiere_nom, te.nom as type_examen,
                        DATEDIFF(ce.date_examen, CURDATE()) as jours_restants
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen >= CURDATE()
                 AND ce.statut = 'planifie'
                 ORDER BY ce.date_examen ASC
                 LIMIT 5",
                [intval($info_etudiant['classe_id'])]);
        }
        
        // Récupérer les annonces récentes (globales et par site)
        $annonces_recentes = executeQuery($db,
            "SELECT 'global' as type, n.titre, n.message, n.date_notification
             FROM notifications n
             WHERE n.type = 'annonce' 
             AND n.date_notification >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             UNION
             SELECT 'site' as type, ca.observations as titre, 
                    CONCAT('Calendrier: ', ca.type_rentree) as message,
                    ca.date_creation as date_notification
             FROM calendrier_academique ca
             WHERE ca.site_id = ? 
             AND ca.publie = 1
             AND ca.date_creation >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY date_notification DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les livres recommandés
        $livres_recommandes = executeQuery($db,
            "SELECT bl.*, bc.nom as categorie_nom
             FROM bibliotheque_livres bl
             LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
             WHERE bl.site_id = ? 
             AND (bl.filiere_id = ? OR bl.filiere_id IS NULL OR bl.filiere_id = 0)
             AND bl.statut = 'disponible'
             ORDER BY bl.date_ajout DESC
             LIMIT 5",
            [$site_id, $filiere_id]);
        
        // Récupérer les documents personnels
        $documents_personnels = executeQuery($db,
            "SELECT de.*, 
                    CASE 
                        WHEN de.type_document = 'bulletin' THEN 'Bulletin de notes'
                        WHEN de.type_document = 'attestation' THEN 'Attestation'
                        WHEN de.type_document = 'certificat' THEN 'Certificat'
                        WHEN de.type_document = 'releve' THEN 'Relevé de notes'
                        ELSE 'Autre document'
                    END as type_document_libelle
             FROM documents_etudiants de
             WHERE de.etudiant_id = ? 
             AND de.statut = 'valide'
             ORDER BY de.date_upload DESC
             LIMIT 5",
            [$etudiant_id]);
        
        // Récupérer le calendrier académique
        $calendrier_events = executeQuery($db,
            "SELECT ca.*, 
                    CONCAT('Semestre ', ca.semestre, ' - ', ca.type_rentree) as titre,
                    CASE 
                        WHEN CURDATE() BETWEEN ca.date_debut_cours AND ca.date_fin_cours THEN 'En cours'
                        WHEN ca.date_debut_cours > CURDATE() THEN 'À venir'
                        ELSE 'Terminé'
                    END as statut_cours
             FROM calendrier_academique ca
             WHERE ca.site_id = ? 
             AND ca.statut IN ('planifie', 'en_cours')
             ORDER BY ca.date_debut_cours ASC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les réunions prochaines
        $reunions_prochaines = executeQuery($db,
            "SELECT r.*, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as organisateur_nom
             FROM reunions r
             JOIN sites s ON r.site_id = s.id
             JOIN utilisateurs u ON r.organisateur_id = u.id
             JOIN reunion_participants rp ON r.id = rp.reunion_id
             WHERE rp.utilisateur_id = ? 
             AND r.date_reunion >= CURDATE()
             AND r.statut = 'planifiee'
             ORDER BY r.date_reunion ASC
             LIMIT 5",
            [$user_id]);
        
        // Récupérer les salles disponibles
        $salles_disponibles = executeQuery($db,
            "SELECT s.*, 
                    CASE 
                        WHEN s.type_salle = 'classe' THEN 'Salle de classe'
                        WHEN s.type_salle = 'amphi' THEN 'Amphithéâtre'
                        WHEN s.type_salle = 'labo' THEN 'Laboratoire'
                        WHEN s.type_salle = 'bureau' THEN 'Bureau'
                        WHEN s.type_salle = 'salle_examen' THEN 'Salle d\'examen'
                        ELSE 'Autre'
                    END as type_salle_libelle
             FROM salles s
             WHERE s.site_id = ? 
             AND s.statut = 'disponible'
             ORDER BY s.nom
             LIMIT 5",
            [$site_id]);
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
    
    /* Graphiques */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    /* Textes spécifiques */
    .text-muted {
        color: var(--text-muted) !important;
    }
    
    .text-primary {
        color: var(--primary-color) !important;
    }
    
    .text-success {
        color: var(--success-color) !important;
    }
    
    .text-warning {
        color: var(--warning-color) !important;
    }
    
    .text-danger {
        color: var(--accent-color) !important;
    }
    
    .text-info {
        color: var(--info-color) !important;
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
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Carte étudiante */
    .student-card {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 20px;
        position: relative;
        overflow: hidden;
    }
    
    .student-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(30deg);
    }
    
    .student-photo {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        border: 3px solid white;
        object-fit: cover;
        margin-bottom: 15px;
    }
    
    .student-qr {
        width: 80px;
        height: 80px;
        background: white;
        padding: 5px;
        border-radius: 5px;
        margin-top: 10px;
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
        
        .student-card {
            padding: 15px;
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
    
    /* Boutons */
    .btn-outline-light {
        color: var(--sidebar-text);
        border-color: var(--sidebar-text);
    }
    
    .btn-outline-light:hover {
        background-color: var(--sidebar-text);
        color: var(--sidebar-bg);
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
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
    }
    
    .progress-bar {
        background-color: var(--primary-color);
    }
    
    /* List group */
    .list-group-item {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .list-group-item:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .list-group-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Calendrier */
    .fc {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc-theme-standard .fc-scrollgrid {
        border-color: var(--border-color);
    }
    
    .fc-theme-standard td, .fc-theme-standard th {
        border-color: var(--border-color);
    }
    
    .fc .fc-toolbar-title {
        color: var(--text-color);
    }
    
    .fc .fc-button-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .fc .fc-button-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    /* Widgets */
    .widget {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
    }
    
    .widget-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: var(--text-color);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .widget-title i {
        color: var(--primary-color);
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
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations Personnelles</span>
                    </a>
                    <a href="carte.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Carte Étudiante</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes & Moyennes</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours Actifs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances</div>
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Situation Financière</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Factures & Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Dettes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Ressources</div>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                    </a>
                    <a href="documents.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Mes Documents</span>
                    </a>
                    <a href="annonces.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>Annonces</span>
                    </a>
                    <a href="calendrier.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier Académique</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Examens</div>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens & Concours</span>
                    </a>
                    <a href="resultats.php" class="nav-link">
                        <i class="fas fa-poll"></i>
                        <span>Résultats</span>
                    </a>
                    <a href="rattrapages.php" class="nav-link">
                        <i class="fas fa-redo-alt"></i>
                        <span>Rattrapages</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="messagerie.php" class="nav-link">
                        <i class="fas fa-envelope"></i>
                        <span>Messagerie</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Mes Professeurs</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Infrastructure</div>
                    <a href="salles.php" class="nav-link">
                        <i class="fas fa-door-open"></i>
                        <span>Salles de Classe</span>
                    </a>
                    <a href="reservations.php" class="nav-link">
                        <i class="fas fa-clock"></i>
                        <span>Réservations</span>
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
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Tableau de Bord Étudiant
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
                        <a href="carte_etudiante.php" class="btn btn-success">
                            <i class="fas fa-id-card"></i> Carte Étudiante
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section 1: Informations rapides et carte étudiante -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Personnelles
                            </h5>
                            <a href="informations.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i> Modifier
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <?php if(isset($info_etudiant['photo_identite']) && !empty($info_etudiant['photo_identite'])): ?>
                                    <img src="<?php echo safeHtml($info_etudiant['photo_identite']); ?>" 
                                         alt="Photo" class="student-photo">
                                    <?php else: ?>
                                    <div class="student-photo bg-secondary d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user fa-3x text-white"></i>
                                    </div>
                                    <?php endif; ?>
                                    <h5 class="mt-3 mb-1"><?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></h5>
                                    <p class="text-muted mb-0"><?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?></p>
                                </div>
                                <div class="col-md-8">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Filière</small>
                                            <p class="mb-0"><?php echo safeHtml($info_etudiant['filiere_nom'] ?? 'Non assigné'); ?></p>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Niveau</small>
                                            <p class="mb-0"><?php echo safeHtml($info_etudiant['niveau_libelle'] ?? 'Non assigné'); ?></p>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Classe</small>
                                            <p class="mb-0"><?php echo safeHtml($info_etudiant['classe_nom'] ?? 'Non assigné'); ?></p>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Site</small>
                                            <p class="mb-0"><?php echo safeHtml($info_etudiant['site_nom'] ?? ''); ?></p>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Statut</small>
                                            <p class="mb-0"><?php echo getStatutBadge($info_etudiant['statut'] ?? ''); ?></p>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <small class="text-muted">Date d'inscription</small>
                                            <p class="mb-0"><?php echo formatDateFr($info_etudiant['date_inscription'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="student-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-2">Carte Étudiante</h5>
                                <p class="mb-1"><?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></p>
                                <p class="mb-1 small"><?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?></p>
                                <p class="mb-0 small"><?php echo safeHtml($info_etudiant['filiere_nom'] ?? ''); ?></p>
                            </div>
                            <div class="student-qr">
                                <!-- QR Code sera généré ici -->
                                <div class="text-center">
                                    <i class="fas fa-qrcode fa-3x text-dark"></i>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <small>Valable jusqu'à: <?php echo date('m/Y', strtotime('+1 year')); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Statistiques Principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['moyenne_generale'], 2); ?>/20</div>
                        <div class="stat-label">Moyenne Générale</div>
                        <div class="stat-change">
                            <?php if($stats['moyenne_generale'] >= 10): ?>
                            <span class="positive"><i class="fas fa-arrow-up"></i> Admis</span>
                            <?php else: ?>
                            <span class="negative"><i class="fas fa-arrow-down"></i> En danger</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['taux_presence']; ?>%</div>
                        <div class="stat-label">Taux de Présence</div>
                        <div class="stat-change">
                            <?php if($stats['taux_presence'] >= 80): ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> Bon</span>
                            <?php else: ?>
                            <span class="negative"><i class="fas fa-exclamation-circle"></i> À améliorer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-danger stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo formatMoney($stats['total_dettes']); ?></div>
                        <div class="stat-label">Dettes Totales</div>
                        <div class="stat-change">
                            <?php if($stats['total_dettes'] > 0): ?>
                            <span class="negative"><i class="fas fa-exclamation-triangle"></i> À régler</span>
                            <?php else: ?>
                            <span class="positive"><i class="fas fa-check-circle"></i> À jour</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['cours_actifs']; ?></div>
                        <div class="stat-label">Cours Actifs</div>
                        <div class="stat-change">
                            <i class="fas fa-clipboard-list"></i> <?php echo $stats['examens_prochains']; ?> examens à venir
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="dashboardTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="academique-tab" data-bs-toggle="tab" data-bs-target="#academique" type="button">
                                <i class="fas fa-graduation-cap me-2"></i>Académique
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="finances-tab" data-bs-toggle="tab" data-bs-target="#finances" type="button">
                                <i class="fas fa-money-bill-wave me-2"></i>Finances
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ressources-tab" data-bs-toggle="tab" data-bs-target="#ressources" type="button">
                                <i class="fas fa-book me-2"></i>Ressources
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="examens-tab" data-bs-toggle="tab" data-bs-target="#examens" type="button">
                                <i class="fas fa-clipboard-list me-2"></i>Examens
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboardTabsContent">
                        <!-- Tab 1: Académique -->
                        <div class="tab-pane fade show active" id="academique">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-chart-line me-2"></i>Notes Récentes</h5>
                                    <?php if(empty($notes_recentes)): ?>
                                    <div class="alert alert-info">
                                        Aucune note disponible pour le moment
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Matière</th>
                                                    <th>Type</th>
                                                    <th>Note</th>
                                                    <th>Coeff</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($notes_recentes as $note): 
                                                    $note_class = '';
                                                    $note_value = floatval($note['note'] ?? 0);
                                                    if ($note_value >= 16) $note_class = 'note-excellent';
                                                    elseif ($note_value >= 12) $note_class = 'note-good';
                                                    elseif ($note_value >= 10) $note_class = 'note-average';
                                                    else $note_class = 'note-poor';
                                                ?>
                                                <tr>
                                                    <td><?php echo safeHtml($note['matiere_nom'] ?? ''); ?></td>
                                                    <td><small><?php echo safeHtml($note['type_examen'] ?? ''); ?></small></td>
                                                    <td>
                                                        <span class="note-badge <?php echo $note_class; ?>">
                                                            <?php echo number_format($note_value, 2); ?>/20
                                                        </span>
                                                    </td>
                                                    <td><?php echo safeHtml($note['coefficient'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($note['date_evaluation'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar-check me-2"></i>Présences du Mois</h5>
                                    <?php if(empty($presence_mois)): ?>
                                    <div class="alert alert-info">
                                        Aucune donnée de présence ce mois-ci
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($presence_mois as $presence): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php echo safeHtml($presence['matiere_nom'] ?? 'Entrée/Sortie'); ?>
                                                </h6>
                                                <small><?php echo formatDateFr($presence['date_heure'] ?? '', 'd/m H:i'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <span class="badge bg-<?php echo ($presence['statut'] ?? '') == 'present' ? 'success' : (($presence['statut'] ?? '') == 'absent' ? 'danger' : 'warning'); ?>">
                                                    <?php echo ucfirst($presence['statut'] ?? ''); ?>
                                                </span>
                                                <?php if(!empty($presence['surveillant_nom'])): ?>
                                                <small class="ms-2">Par: <?php echo safeHtml($presence['surveillant_nom']); ?></small>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-calendar-alt me-2"></i>Emploi du Temps - Cette Semaine</h5>
                                    <?php if(empty($cours_semaine)): ?>
                                    <div class="alert alert-info">
                                        Aucun cours planifié cette semaine
                                    </div>
                                    <?php else: ?>
                                    <div id="calendar"></div>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        var calendarEl = document.getElementById('calendar');
                                        var calendar = new FullCalendar.Calendar(calendarEl, {
                                            initialView: 'timeGridWeek',
                                            locale: 'fr',
                                            headerToolbar: {
                                                left: 'prev,next today',
                                                center: 'title',
                                                right: 'timeGridWeek,timeGridDay'
                                            },
                                            events: [
                                                <?php foreach($cours_semaine as $cours): ?>
                                                {
                                                    title: '<?php echo safeHtml($cours["matiere_nom"] ?? ''); ?>',
                                                    start: '<?php echo date("Y-m-d", strtotime(($cours["jour_semaine"] ?? "Monday") . " this week")) . "T" . ($cours["heure_debut"] ?? "08:00:00"); ?>',
                                                    end: '<?php echo date("Y-m-d", strtotime(($cours["jour_semaine"] ?? "Monday") . " this week")) . "T" . ($cours["heure_fin"] ?? "10:00:00"); ?>',
                                                    description: '<?php echo safeHtml($cours["enseignant_nom"] ?? ''); ?>',
                                                    color: '#3498db'
                                                },
                                                <?php endforeach; ?>
                                            ],
                                            eventClick: function(info) {
                                                alert(
                                                    'Cours: ' + info.event.title + '\n' +
                                                    'Enseignant: ' + info.event.extendedProps.description + '\n' +
                                                    'Heure: ' + info.event.start.toLocaleTimeString()
                                                );
                                            }
                                        });
                                        calendar.render();
                                    });
                                    </script>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-users me-2"></i>Mes Professeurs</h5>
                                    <?php if(isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])): 
                                        $professeurs = executeQuery($db,
                                            "SELECT DISTINCT e.id, u.nom, u.prenom, m.nom as matiere_nom
                                             FROM enseignants e
                                             JOIN utilisateurs u ON e.utilisateur_id = u.id
                                             JOIN matieres m ON m.enseignant_id = e.id
                                             JOIN classes c ON m.filiere_id = c.filiere_id 
                                             AND m.niveau_id = c.niveau_id
                                             WHERE c.id = ? 
                                             AND u.statut = 'actif'
                                             LIMIT 5",
                                            [intval($info_etudiant['classe_id'])]);
                                    ?>
                                    <?php if(empty($professeurs)): ?>
                                    <div class="alert alert-info">
                                        Aucun professeur assigné pour le moment
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($professeurs as $prof): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml(($prof['nom'] ?? '') . ' ' . ($prof['prenom'] ?? '')); ?></h6>
                                            </div>
                                            <p class="mb-1">
                                                <small><?php echo safeHtml($prof['matiere_nom'] ?? ''); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Finances -->
                        <div class="tab-pane fade" id="finances">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-invoice-dollar me-2"></i>Détails des Dettes</h5>
                                    <?php if(empty($dettes_detail)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune dette enregistrée
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Année Académique</th>
                                                    <th>Montant dû</th>
                                                    <th>Restant</th>
                                                    <th>Date limite</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($dettes_detail as $dette): ?>
                                                <tr>
                                                    <td><?php echo safeHtml($dette['annee_academique'] ?? ''); ?></td>
                                                    <td><?php echo formatMoney($dette['montant_du'] ?? 0); ?></td>
                                                    <td><?php echo formatMoney($dette['montant_restant'] ?? 0); ?></td>
                                                    <td><?php echo formatDateFr($dette['date_limite'] ?? ''); ?></td>
                                                    <td><?php echo getStatutBadge($dette['statut'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-pie me-2"></i>Répartition des Frais</h5>
                                    <canvas id="fraisChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('fraisChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['Scolarité', 'Inscription', 'Examens', 'Autres'],
                                                datasets: [{
                                                    data: [70, 15, 10, 5],
                                                    backgroundColor: [
                                                        '#3498db',
                                                        '#2ecc71',
                                                        '#e74c3c',
                                                        '#f39c12'
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
                                                        text: 'Répartition des frais'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-history me-2"></i>Historique des Paiements</h5>
                                    <?php 
                                    $paiements_history = executeQuery($db,
                                        "SELECT p.*, tf.nom as type_frais, u.nom as caissier_nom
                                         FROM paiements p
                                         JOIN types_frais tf ON p.type_frais_id = tf.id
                                         LEFT JOIN utilisateurs u ON p.caissier_id = u.id
                                         WHERE p.etudiant_id = ? 
                                         AND p.statut = 'valide'
                                         ORDER BY p.date_paiement DESC
                                         LIMIT 5",
                                        [$etudiant_id]);
                                    ?>
                                    
                                    <?php if(empty($paiements_history)): ?>
                                    <div class="alert alert-info">
                                        Aucun historique de paiement disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($paiements_history as $paiement): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($paiement['type_frais'] ?? ''); ?></h6>
                                                <strong><?php echo formatMoney($paiement['montant'] ?? 0); ?></strong>
                                            </div>
                                            <p class="mb-1">
                                                <small>Référence: <?php echo safeHtml($paiement['reference'] ?? ''); ?></small><br>
                                                <small>Mode: <?php echo safeHtml($paiement['mode_paiement'] ?? ''); ?></small><br>
                                                <small>Date: <?php echo formatDateFr($paiement['date_paiement'] ?? ''); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar me-2"></i>Échéances à Venir</h5>
                                    <?php 
                                    $echeances = executeQuery($db,
                                        "SELECT f.*, tf.nom as type_frais
                                         FROM factures f
                                         JOIN types_frais tf ON f.type_frais_id = tf.id
                                         WHERE f.etudiant_id = ? 
                                         AND f.statut IN ('en_attente', 'en_retard')
                                         AND f.date_echeance >= CURDATE()
                                         ORDER BY f.date_echeance ASC
                                         LIMIT 5",
                                        [$etudiant_id]);
                                    ?>
                                    
                                    <?php if(empty($echeances)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucune échéance à venir
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($echeances as $echeance): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($echeance['type_frais'] ?? ''); ?></h6>
                                                <strong><?php echo formatMoney($echeance['montant_restant'] ?? 0); ?></strong>
                                            </div>
                                            <p class="mb-1">
                                                <small>N°: <?php echo safeHtml($echeance['numero_facture'] ?? ''); ?></small><br>
                                                <small>Échéance: <?php echo formatDateFr($echeance['date_echeance'] ?? ''); ?></small><br>
                                                <span class="badge bg-<?php echo ($echeance['statut'] ?? '') == 'en_retard' ? 'danger' : 'warning'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $echeance['statut'] ?? '')); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Ressources -->
                        <div class="tab-pane fade" id="ressources">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-bullhorn me-2"></i>Annonces Récentes</h5>
                                    <?php if(empty($annonces_recentes)): ?>
                                    <div class="alert alert-info">
                                        Aucune annonce récente
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($annonces_recentes as $annonce): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php if(($annonce['type'] ?? '') == 'global'): ?>
                                                    <i class="fas fa-globe text-primary me-2"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-school text-info me-2"></i>
                                                    <?php endif; ?>
                                                    <?php echo safeHtml($annonce['titre'] ?? ''); ?>
                                                </h6>
                                                <small><?php echo formatDateFr($annonce['date_notification'] ?? '', 'd/m H:i'); ?></small>
                                            </div>
                                            <p class="mb-1"><?php echo safeHtml(substr($annonce['message'] ?? '', 0, 100)); ?>...</p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-book-reader me-2"></i>Livres Recommandés</h5>
                                    <?php if(empty($livres_recommandes)): ?>
                                    <div class="alert alert-info">
                                        Aucun livre recommandé pour votre filière
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($livres_recommandes as $livre): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($livre['titre'] ?? ''); ?></h6>
                                                <small><?php echo safeHtml($livre['auteur'] ?? ''); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Catégorie: <?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></small><br>
                                                <small>ISBN: <?php echo safeHtml($livre['isbn'] ?? 'Non disponible'); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-alt me-2"></i>Mes Documents</h5>
                                    <?php if(empty($documents_personnels)): ?>
                                    <div class="alert alert-info">
                                        Aucun document disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($documents_personnels as $doc): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($doc['type_document_libelle'] ?? ''); ?></h6>
                                                <small><?php echo formatDateFr($doc['date_upload'] ?? ''); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Fichier: <?php echo safeHtml($doc['nom_fichier'] ?? ''); ?></small><br>
                                                <?php if(!empty($doc['qr_code'])): ?>
                                                <small><i class="fas fa-qrcode"></i> QR Code disponible</small>
                                                <?php endif; ?>
                                            </p>
                                            <div class="mt-2">
                                                <?php if(!empty($doc['chemin_fichier'])): ?>
                                                <a href="<?php echo safeHtml($doc['chemin_fichier']); ?>" 
                                                   class="btn btn-sm btn-primary" target="_blank">
                                                    <i class="fas fa-download"></i> Télécharger
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-calendar me-2"></i>Calendrier Académique</h5>
                                    <?php if(empty($calendrier_events)): ?>
                                    <div class="alert alert-info">
                                        Aucun événement calendaire disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($calendrier_events as $event): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($event['titre'] ?? ''); ?></h6>
                                                <span class="badge bg-<?php echo ($event['statut_cours'] ?? '') == 'En cours' ? 'success' : (($event['statut_cours'] ?? '') == 'À venir' ? 'warning' : 'secondary'); ?>">
                                                    <?php echo safeHtml($event['statut_cours'] ?? ''); ?>
                                                </span>
                                            </div>
                                            <p class="mb-1">
                                                <small>Début: <?php echo formatDateFr($event['date_debut_cours'] ?? ''); ?></small><br>
                                                <small>Fin: <?php echo formatDateFr($event['date_fin_cours'] ?? ''); ?></small>
                                            </p>
                                            <?php if(!empty($event['date_debut_examens'])): ?>
                                            <p class="mb-1">
                                                <small>Examens: <?php echo formatDateFr($event['date_debut_examens']); ?> - <?php echo formatDateFr($event['date_fin_examens'] ?? ''); ?></small>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Examens -->
                        <div class="tab-pane fade" id="examens">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-clipboard-list me-2"></i>Examens à Venir</h5>
                                    <?php if(empty($examens_prochains)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucun examen à venir
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($examens_prochains as $examen): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($examen['matiere_nom'] ?? ''); ?></h6>
                                                <span class="badge bg-<?php echo (intval($examen['jours_restants'] ?? 0) <= 3 ? 'danger' : (intval($examen['jours_restants'] ?? 0) <= 7 ? 'warning' : 'info')); ?>">
                                                    J-<?php echo intval($examen['jours_restants'] ?? 0); ?>
                                                </span>
                                            </div>
                                            <p class="mb-1">
                                                <small>Type: <?php echo safeHtml($examen['type_examen'] ?? ''); ?></small><br>
                                                <small>Date: <?php echo formatDateFr($examen['date_examen'] ?? ''); ?> à <?php echo substr($examen['heure_debut'] ?? '00:00:00', 0, 5); ?></small><br>
                                                <small>Salle: <?php echo safeHtml($examen['salle'] ?? 'Non définie'); ?></small>
                                            </p>
                                            <?php if(!empty($examen['consignes'])): ?>
                                            <div class="alert alert-warning mt-2 py-2">
                                                <small><strong>Consignes:</strong> <?php echo safeHtml(substr($examen['consignes'], 0, 100)); ?>...</small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-bar me-2"></i>Statistiques des Notes</h5>
                                    <canvas id="notesChart"></canvas>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('notesChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: ['<10', '10-12', '12-14', '14-16', '>16'],
                                                datasets: [{
                                                    label: 'Nombre de notes',
                                                    data: [2, 5, 8, 6, 3],
                                                    backgroundColor: [
                                                        '#e74c3c',
                                                        '#f39c12',
                                                        '#3498db',
                                                        '#2ecc71',
                                                        '#9b59b6'
                                                    ]
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
                                                        text: 'Répartition de vos notes'
                                                    }
                                                }
                                            }
                                        });
                                    });
                                    </script>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-door-open me-2"></i>Salles Disponibles</h5>
                                    <?php if(empty($salles_disponibles)): ?>
                                    <div class="alert alert-info">
                                        Aucune information sur les salles disponibles
                                    </div>
                                    <?php else: ?>
                                    <div class="row">
                                        <?php foreach($salles_disponibles as $salle): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?php echo safeHtml($salle['nom'] ?? ''); ?></h6>
                                                    <p class="card-text small">
                                                        <i class="fas fa-users"></i> Capacité: <?php echo intval($salle['capacite'] ?? 0); ?> places<br>
                                                        <i class="fas fa-building"></i> <?php echo safeHtml($salle['type_salle_libelle'] ?? ''); ?><br>
                                                        <?php if(!empty($salle['batiment'])): ?>
                                                        <i class="fas fa-map-marker-alt"></i> <?php echo safeHtml($salle['batiment']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <span class="badge bg-success">Disponible</span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-users me-2"></i>Réunions à Venir</h5>
                                    <?php if(empty($reunions_prochaines)): ?>
                                    <div class="alert alert-info">
                                        Aucune réunion programmée
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($reunions_prochaines as $reunion): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($reunion['titre'] ?? ''); ?></h6>
                                                <small><?php echo formatDateFr($reunion['date_reunion'] ?? '', 'd/m H:i'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Type: <?php echo safeHtml(ucfirst($reunion['type_reunion'] ?? '')); ?></small><br>
                                                <small>Organisateur: <?php echo safeHtml($reunion['organisateur_nom'] ?? ''); ?></small><br>
                                                <small>Lieu: <?php echo safeHtml($reunion['lieu'] ?? 'Non spécifié'); ?></small>
                                            </p>
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
                                        <div class="title">Consulter Notes</div>
                                        <div class="description">Voir vos résultats</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='presences.php'">
                                        <i class="fas fa-calendar-check"></i>
                                        <div class="title">Vérifier Présences</div>
                                        <div class="description">État des présences</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='factures.php'">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <div class="title">Payer Facture</div>
                                        <div class="description">Régler vos frais</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='bibliotheque.php'">
                                        <i class="fas fa-book"></i>
                                        <div class="title">Bibliothèque</div>
                                        <div class="description">Consulter livres</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='documents.php'">
                                        <i class="fas fa-download"></i>
                                        <div class="title">Télécharger</div>
                                        <div class="description">Documents importants</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='messagerie.php'">
                                        <i class="fas fa-envelope"></i>
                                        <div class="title">Messagerie</div>
                                        <div class="description">Contacter professeurs</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Informations importantes -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Alertes & Rappels
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if($stats['total_dettes'] > 0): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> 
                                <strong>Dettes en cours:</strong> Vous avez <?php echo formatMoney($stats['total_dettes']); ?> de dettes à régler.
                                <a href="dettes.php" class="alert-link">Régler maintenant</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['taux_presence'] < 80): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-calendar-times"></i> 
                                <strong>Taux de présence bas:</strong> Votre taux de présence est de <?php echo $stats['taux_presence']; ?>%.
                                <a href="presences.php" class="alert-link">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['examens_prochains'] > 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-clipboard-list"></i> 
                                <strong>Examens à venir:</strong> Vous avez <?php echo $stats['examens_prochains']; ?> examen(s) programmé(s).
                                <a href="examens.php" class="alert-link">Consulter le calendrier</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['moyenne_generale'] < 10 && $stats['moyenne_generale'] > 0): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-chart-line"></i> 
                                <strong>Attention:</strong> Votre moyenne générale est en dessous de 10/20.
                                <a href="notes.php" class="alert-link">Consulter vos notes</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!$stats['total_dettes'] && $stats['taux_presence'] >= 80 && $stats['moyenne_generale'] >= 10): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> 
                                <strong>Tout est en ordre!</strong> Votre situation académique et financière est à jour.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Utiles
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-phone-alt"></i> Contacts importants</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Secrétariat:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Service financier:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Service académique:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Urgences:</strong> +242 XX XX XX XX</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-clock"></i> Horaires d'ouverture</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Lundi - Vendredi:</strong> 7h30 - 18h00</li>
                                    <li><strong>Samedi:</strong> 8h00 - 13h00</li>
                                    <li><strong>Bibliothèque:</strong> 8h00 - 20h00 (Lun-Ven)</li>
                                    <li><strong>Service financier:</strong> 8h00 - 16h00</li>
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
    });
    </script>
</body>
</html>