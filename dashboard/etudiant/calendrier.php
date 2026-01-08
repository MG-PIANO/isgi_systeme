<?php
// dashboard/etudiant/calendrier.php

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
    $pageTitle = "Calendrier Académique - Étudiant";
    
    // Fonctions utilitaires avec validation
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
            case 'planifiee':
                return '<span class="badge bg-info">Planifié</span>';
            case 'en_cours':
                return '<span class="badge bg-success">En cours</span>';
            case 'termine':
            case 'terminee':
                return '<span class="badge bg-secondary">Terminé</span>';
            case 'annule':
            case 'annulee':
                return '<span class="badge bg-danger">Annulé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeRentreeBadge($type) {
        $type = strval($type);
        switch ($type) {
            case 'Octobre':
                return '<span class="badge bg-primary">Octobre</span>';
            case 'Janvier':
                return '<span class="badge bg-success">Janvier</span>';
            case 'Avril':
                return '<span class="badge bg-warning">Avril</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    function getSemestreBadge($semestre) {
        $semestre = strval($semestre);
        switch ($semestre) {
            case '1':
                return '<span class="badge bg-info">Semestre 1</span>';
            case '2':
                return '<span class="badge bg-warning">Semestre 2</span>';
            default:
                return '<span class="badge bg-secondary">Semestre ' . htmlspecialchars($semestre) . '</span>';
        }
    }
    
    // Fonction pour calculer les jours restants
    function joursRestants($date) {
        if (empty($date) || $date == '0000-00-00') return null;
        $dateObj = new DateTime($date);
        $today = new DateTime();
        $interval = $today->diff($dateObj);
        return $interval->days * ($interval->invert ? -1 : 1);
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
    $site_id = SessionManager::getSiteId();
    
    // Initialiser toutes les variables
    $info_etudiant = array();
    $calendrier_actuel = array();
    $calendriers_futurs = array();
    $calendriers_passes = array();
    $evenements_speciaux = array();
    $examens_prochains = array();
    $vacances = array();
    $dates_importantes = array();
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
        $site_id = isset($info_etudiant['site_id']) ? intval($info_etudiant['site_id']) : $site_id;
        
        // Récupérer le calendrier académique actuel
        $calendrier_actuel = executeQuery($db,
            "SELECT ca.*, aa.libelle as annee_libelle, s.nom as site_nom,
                    CONCAT(u.nom, ' ', u.prenom) as createur_nom
             FROM calendrier_academique ca
             JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
             JOIN sites s ON ca.site_id = s.id
             LEFT JOIN utilisateurs u ON ca.cree_par = u.id
             WHERE ca.site_id = ? 
             AND ca.statut IN ('planifie', 'en_cours')
             AND CURDATE() BETWEEN ca.date_debut_cours AND ca.date_fin_cours
             ORDER BY ca.date_debut_cours DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les calendriers futurs
        $calendriers_futurs = executeQuery($db,
            "SELECT ca.*, aa.libelle as annee_libelle, s.nom as site_nom
             FROM calendrier_academique ca
             JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
             JOIN sites s ON ca.site_id = s.id
             WHERE ca.site_id = ? 
             AND ca.statut = 'planifie'
             AND ca.date_debut_cours > CURDATE()
             ORDER BY ca.date_debut_cours ASC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les calendriers passés
        $calendriers_passes = executeQuery($db,
            "SELECT ca.*, aa.libelle as annee_libelle, s.nom as site_nom
             FROM calendrier_academique ca
             JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
             JOIN sites s ON ca.site_id = s.id
             WHERE ca.site_id = ? 
             AND ca.statut IN ('termine', 'annule')
             AND ca.date_fin_cours < CURDATE()
             ORDER BY ca.date_fin_cours DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les événements spéciaux (examens, congés, etc.)
        $evenements_speciaux = executeQuery($db,
            "SELECT 
                'examen' as type_event,
                ce.date_examen as date_debut,
                ce.date_examen as date_fin,
                CONCAT('Examen: ', m.nom) as titre,
                CONCAT('Salle: ', COALESCE(ce.salle, 'Non définie'), ' - ', te.nom) as description,
                ce.statut,
                'danger' as couleur
             FROM calendrier_examens ce
             JOIN matieres m ON ce.matiere_id = m.id
             JOIN types_examens te ON ce.type_examen_id = te.id
             JOIN classes c ON ce.classe_id = c.id
             WHERE c.id = ? 
             AND ce.date_examen >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             AND ce.date_examen <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             
             UNION ALL
             
             SELECT 
                'conge' as type_event,
                ca.date_debut_conge_etude as date_debut,
                ca.date_fin_conge_etude as date_fin,
                'Congé d\'étude' as titre,
                'Période de révision avant examens' as description,
                ca.statut,
                'warning' as couleur
             FROM calendrier_academique ca
             WHERE ca.site_id = ?
             AND ca.date_debut_conge_etude IS NOT NULL
             AND ca.date_fin_conge_etude IS NOT NULL
             AND (
                 ca.date_debut_conge_etude BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                 OR ca.date_fin_conge_etude BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             )
             
             UNION ALL
             
             SELECT 
                'stage' as type_event,
                ca.date_debut_stage as date_debut,
                ca.date_fin_stage as date_fin,
                'Stage professionnel' as titre,
                'Période de stage en entreprise' as description,
                ca.statut,
                'success' as couleur
             FROM calendrier_academique ca
             WHERE ca.site_id = ?
             AND ca.date_debut_stage IS NOT NULL
             AND ca.date_fin_stage IS NOT NULL
             AND (
                 ca.date_debut_stage BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                 OR ca.date_fin_stage BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             )
             
             UNION ALL
             
             SELECT 
                'reprise' as type_event,
                ca.date_reprise_cours as date_debut,
                ca.date_reprise_cours as date_fin,
                'Reprise des cours' as titre,
                'Début du semestre suivant' as description,
                ca.statut,
                'info' as couleur
             FROM calendrier_academique ca
             WHERE ca.site_id = ?
             AND ca.date_reprise_cours IS NOT NULL
             AND ca.date_reprise_cours BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             
             ORDER BY date_debut ASC",
            [
                isset($info_etudiant['classe_id']) ? intval($info_etudiant['classe_id']) : 0,
                $site_id,
                $site_id,
                $site_id
            ]);
        
        // Récupérer les examens prochains (pour la section spécifique)
        if (isset($info_etudiant['classe_id']) && !empty($info_etudiant['classe_id'])) {
            $examens_prochains = executeQuery($db,
                "SELECT ce.*, m.nom as matiere_nom, te.nom as type_examen,
                        m.code as matiere_code, c.nom as classe_nom,
                        DATEDIFF(ce.date_examen, CURDATE()) as jours_restants
                 FROM calendrier_examens ce
                 JOIN matieres m ON ce.matiere_id = m.id
                 JOIN types_examens te ON ce.type_examen_id = te.id
                 JOIN classes c ON ce.classe_id = c.id
                 WHERE ce.classe_id = ? 
                 AND ce.date_examen >= CURDATE()
                 AND ce.statut = 'planifie'
                 ORDER BY ce.date_examen ASC
                 LIMIT 10",
                [intval($info_etudiant['classe_id'])]);
        }
        
        // Récupérer les périodes de vacances/congés
        $vacances = executeQuery($db,
            "SELECT 
                'conge_etude' as type_vacance,
                ca.date_debut_conge_etude as date_debut,
                ca.date_fin_conge_etude as date_fin,
                'Congé d\'étude' as titre,
                'Période de révision' as description
             FROM calendrier_academique ca
             WHERE ca.site_id = ?
             AND ca.date_debut_conge_etude IS NOT NULL
             AND ca.date_fin_conge_etude IS NOT NULL
             
             UNION ALL
             
             SELECT 
                'vacances' as type_vacance,
                DATE_ADD(ca.date_fin_cours, INTERVAL 1 DAY) as date_debut,
                DATE_SUB(ca.date_reprise_cours, INTERVAL 1 DAY) as date_fin,
                'Vacances inter-semestres' as titre,
                'Période de vacances' as description
             FROM calendrier_academique ca
             WHERE ca.site_id = ?
             AND ca.date_reprise_cours IS NOT NULL
             
             ORDER BY date_debut ASC",
            [$site_id, $site_id]);
        
        // Compiler toutes les dates importantes
        $dates_importantes = array();
        
        // Ajouter les dates du calendrier actuel
        foreach ($calendrier_actuel as $cal) {
            $dates_importantes[] = array(
                'date' => $cal['date_debut_cours'],
                'titre' => 'Début des cours - ' . $cal['type_rentree'],
                'description' => 'Semestre ' . $cal['semestre'] . ' - ' . $cal['annee_libelle'],
                'type' => 'debut_cours',
                'couleur' => 'success'
            );
            
            $dates_importantes[] = array(
                'date' => $cal['date_fin_cours'],
                'titre' => 'Fin des cours - ' . $cal['type_rentree'],
                'description' => 'Semestre ' . $cal['semestre'],
                'type' => 'fin_cours',
                'couleur' => 'danger'
            );
            
            if ($cal['date_debut_examens']) {
                $dates_importantes[] = array(
                    'date' => $cal['date_debut_examens'],
                    'titre' => 'Début des examens',
                    'description' => 'Session d\'examens',
                    'type' => 'examen',
                    'couleur' => 'warning'
                );
            }
            
            if ($cal['date_fin_examens']) {
                $dates_importantes[] = array(
                    'date' => $cal['date_fin_examens'],
                    'titre' => 'Fin des examens',
                    'description' => 'Fin de session',
                    'type' => 'examen',
                    'couleur' => 'secondary'
                );
            }
        }
        
        // Ajouter les événements spéciaux
        foreach ($evenements_speciaux as $event) {
            $dates_importantes[] = array(
                'date' => $event['date_debut'],
                'titre' => $event['titre'],
                'description' => $event['description'],
                'type' => $event['type_event'],
                'couleur' => $event['couleur']
            );
        }
        
        // Trier par date
        usort($dates_importantes, function($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });
        
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
    
    /* FullCalendar personnalisé */
    #calendar {
        background-color: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc {
        color: var(--text-color);
    }
    
    .fc-theme-standard .fc-scrollgrid {
        border-color: var(--border-color);
    }
    
    .fc-theme-standard td, .fc-theme-standard th {
        border-color: var(--border-color);
    }
    
    .fc .fc-toolbar-title {
        color: var(--text-color);
        font-size: 1.5em;
    }
    
    .fc .fc-button-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .fc .fc-button-primary:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    .fc .fc-daygrid-day-number {
        color: var(--text-color);
    }
    
    .fc .fc-col-header-cell-cushion {
        color: var(--text-color);
        font-weight: 600;
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--border-color);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -30px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--primary-color);
    }
    
    .timeline-item.debut::before {
        background-color: var(--success-color);
    }
    
    .timeline-item.fin::before {
        background-color: var(--accent-color);
    }
    
    .timeline-item.examen::before {
        background-color: var(--warning-color);
    }
    
    .timeline-item.conge::before {
        background-color: var(--info-color);
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
        
        .fc-toolbar {
            flex-direction: column;
        }
        
        .fc-toolbar-chunk {
            margin-bottom: 10px;
        }
    }
    
    /* Cartes d'événements */
    .event-card {
        border-left: 4px solid var(--primary-color);
        margin-bottom: 10px;
    }
    
    .event-card.examen {
        border-left-color: var(--accent-color);
    }
    
    .event-card.conge {
        border-left-color: var(--info-color);
    }
    
    .event-card.stage {
        border-left-color: var(--success-color);
    }
    
    .event-card.reprise {
        border-left-color: var(--warning-color);
    }
    
    /* Filtres */
    .filter-badge {
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .filter-badge:hover {
        opacity: 0.8;
        transform: scale(1.05);
    }
    
    .filter-badge.active {
        box-shadow: 0 0 0 2px var(--primary-color);
    }
    
    /* Calendrier mini */
    .mini-calendar {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 2px;
        text-align: center;
    }
    
    .mini-calendar-day {
        padding: 5px;
        border-radius: 5px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .mini-calendar-day:hover {
        background-color: var(--border-color);
    }
    
    .mini-calendar-day.today {
        background-color: var(--primary-color);
        color: white;
    }
    
    .mini-calendar-day.event {
        background-color: var(--warning-color);
        color: white;
    }
    
    .mini-calendar-header {
        font-weight: bold;
        padding: 5px;
        background-color: var(--border-color);
        border-radius: 5px;
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
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
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
                    <a href="calendrier.php" class="nav-link active">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier</span>
                    </a>
                    <a href="emploi_temps.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Emploi du Temps</span>
                    </a>
                    <a href="examens.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Examens</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique</div>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
                    </a>
                    <a href="cours.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Cours</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Ressources</div>
                    <a href="bibliotheque.php" class="nav-link">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                    </a>
                    <a href="annonces.php" class="nav-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>Annonces</span>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
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
                            <i class="fas fa-calendar me-2"></i>
                            Calendrier Académique
                        </h2>
                        <p class="text-muted mb-0">
                            <?php if(isset($info_etudiant['site_nom']) && !empty($info_etudiant['site_nom'])): ?>
                            Site: <?php echo safeHtml($info_etudiant['site_nom']); ?> - 
                            <?php endif; ?>
                            Année académique en cours
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <button class="btn btn-success" onclick="imprimerCalendrier()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalExport">
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
            
            <!-- Section 1: Calendrier principal -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Calendrier Complet
                            </h5>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-filter me-2"></i>
                                Filtres
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <h6>Types d'événements:</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-primary filter-badge active" data-filter="all" onclick="filterEvents('all')">
                                        Tous
                                    </span>
                                    <span class="badge bg-success filter-badge active" data-filter="debut" onclick="filterEvents('debut')">
                                        Début
                                    </span>
                                    <span class="badge bg-danger filter-badge active" data-filter="fin" onclick="filterEvents('fin')">
                                        Fin
                                    </span>
                                    <span class="badge bg-warning filter-badge active" data-filter="examen" onclick="filterEvents('examen')">
                                        Examens
                                    </span>
                                    <span class="badge bg-info filter-badge active" data-filter="conge" onclick="filterEvents('conge')">
                                        Congés
                                    </span>
                                    <span class="badge bg-secondary filter-badge active" data-filter="stage" onclick="filterEvents('stage')">
                                        Stages
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <h6>Période:</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-sm btn-outline-primary" onclick="changePeriod('month')">
                                        Mois
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary" onclick="changePeriod('week')">
                                        Semaine
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary" onclick="changePeriod('day')">
                                        Jour
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <h6>Mini-calendrier:</h6>
                                <div id="miniCalendar" class="mini-calendar"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Prochains Événements
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($dates_importantes)): ?>
                            <div class="alert alert-info">
                                Aucun événement à venir
                            </div>
                            <?php else: ?>
                            <div class="timeline">
                                <?php 
                                $count = 0;
                                foreach($dates_importantes as $date): 
                                    if($count >= 5) break;
                                    $jours_restants = joursRestants($date['date']);
                                    $date_obj = new DateTime($date['date']);
                                    $today = new DateTime();
                                    if($date_obj >= $today):
                                ?>
                                <div class="timeline-item <?php echo $date['type']; ?>">
                                    <h6 class="mb-1"><?php echo safeHtml($date['titre']); ?></h6>
                                    <p class="mb-1 small">
                                        <strong>Date:</strong> <?php echo formatDateFr($date['date']); ?><br>
                                        <?php if(!empty($date['description'])): ?>
                                        <?php echo safeHtml($date['description']); ?><br>
                                        <?php endif; ?>
                                        <?php if($jours_restants !== null): ?>
                                        <span class="badge bg-<?php echo $jours_restants <= 7 ? 'danger' : ($jours_restants <= 30 ? 'warning' : 'info'); ?>">
                                            <?php if($jours_restants > 0): ?>J-<?php echo $jours_restants; ?>
                                            <?php elseif($jours_restants == 0): ?>Aujourd'hui
                                            <?php else: ?>Passé<?php endif; ?>
                                        </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php 
                                    $count++;
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Calendriers par période -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-play-circle me-2"></i>
                                Période en Cours
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendrier_actuel)): ?>
                            <div class="alert alert-info">
                                Aucune période académique en cours
                            </div>
                            <?php else: ?>
                            <?php foreach($calendrier_actuel as $cal): 
                                $jours_ecoules = joursRestants($cal['date_debut_cours']);
                                $jours_totaux = joursRestants($cal['date_fin_cours']) - $jours_ecoules;
                                $pourcentage = $jours_totaux != 0 ? round((abs($jours_ecoules) / $jours_totaux) * 100) : 0;
                            ?>
                            <div class="event-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php echo getSemestreBadge($cal['semestre']); ?>
                                            <?php echo getTypeRentreeBadge($cal['type_rentree']); ?>
                                        </h6>
                                        <p class="mb-1 small">
                                            <?php echo safeHtml($cal['annee_libelle']); ?>
                                        </p>
                                    </div>
                                    <?php echo getStatutBadge($cal['statut']); ?>
                                </div>
                                
                                <div class="mb-2">
                                    <p class="mb-1 small">
                                        <strong>Début:</strong> <?php echo formatDateFr($cal['date_debut_cours']); ?><br>
                                        <strong>Fin:</strong> <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                    </p>
                                    
                                    <?php if($cal['date_debut_examens']): ?>
                                    <p class="mb-1 small">
                                        <strong>Examens:</strong> <?php echo formatDateFr($cal['date_debut_examens']); ?> 
                                        - <?php echo formatDateFr($cal['date_fin_examens']); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo min($pourcentage, 100); ?>%">
                                        <?php echo $pourcentage; ?>%
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo abs($jours_ecoules); ?> jours écoulés sur <?php echo $jours_totaux; ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2"></i>
                                Périodes à Venir
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendriers_futurs)): ?>
                            <div class="alert alert-info">
                                Aucune période académique à venir
                            </div>
                            <?php else: ?>
                            <?php foreach($calendriers_futurs as $cal): 
                                $jours_restants = joursRestants($cal['date_debut_cours']);
                            ?>
                            <div class="event-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php echo getSemestreBadge($cal['semestre']); ?>
                                            <?php echo getTypeRentreeBadge($cal['type_rentree']); ?>
                                        </h6>
                                        <p class="mb-1 small">
                                            <?php echo safeHtml($cal['annee_libelle']); ?>
                                        </p>
                                    </div>
                                    <?php echo getStatutBadge($cal['statut']); ?>
                                </div>
                                
                                <div class="mb-2">
                                    <p class="mb-1 small">
                                        <strong>Début:</strong> <?php echo formatDateFr($cal['date_debut_cours']); ?><br>
                                        <strong>Fin:</strong> <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                    </p>
                                </div>
                                
                                <div class="alert alert-warning py-2">
                                    <small>
                                        <i class="fas fa-clock"></i> 
                                        Début dans 
                                        <strong><?php echo $jours_restants; ?> jours</strong>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2"></i>
                                Périodes Passées
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($calendriers_passes)): ?>
                            <div class="alert alert-info">
                                Aucune période académique passée récente
                            </div>
                            <?php else: ?>
                            <?php foreach($calendriers_passes as $cal): ?>
                            <div class="event-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php echo getSemestreBadge($cal['semestre']); ?>
                                            <?php echo getTypeRentreeBadge($cal['type_rentree']); ?>
                                        </h6>
                                        <p class="mb-1 small">
                                            <?php echo safeHtml($cal['annee_libelle']); ?>
                                        </p>
                                    </div>
                                    <?php echo getStatutBadge($cal['statut']); ?>
                                </div>
                                
                                <div class="mb-2">
                                    <p class="mb-1 small">
                                        <strong>Début:</strong> <?php echo formatDateFr($cal['date_debut_cours']); ?><br>
                                        <strong>Fin:</strong> <?php echo formatDateFr($cal['date_fin_cours']); ?>
                                    </p>
                                </div>
                                
                                <div class="alert alert-secondary py-2">
                                    <small>
                                        <i class="fas fa-check-circle"></i> 
                                        Période terminée
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Examens prochains -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-list me-2"></i>
                        Examens à Venir
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($examens_prochains)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Aucun examen à venir
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Matière</th>
                                    <th>Type</th>
                                    <th>Heure</th>
                                    <th>Salle</th>
                                    <th>Jours restants</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($examens_prochains as $examen): 
                                    $jours_restants = intval($examen['jours_restants'] ?? 0);
                                ?>
                                <tr class="<?php echo $jours_restants <= 3 ? 'table-danger' : ($jours_restants <= 7 ? 'table-warning' : ''); ?>">
                                    <td>
                                        <?php echo formatDateFr($examen['date_examen'] ?? ''); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo safeHtml($examen['matiere_code'] ?? ''); ?></strong><br>
                                        <small><?php echo safeHtml($examen['matiere_nom'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo safeHtml($examen['type_examen'] ?? ''); ?>
                                    </td>
                                    <td>
                                        <?php echo substr($examen['heure_debut'] ?? '00:00:00', 0, 5); ?> - 
                                        <?php echo substr($examen['heure_fin'] ?? '00:00:00', 0, 5); ?>
                                    </td>
                                    <td>
                                        <?php echo safeHtml($examen['salle'] ?? 'Non définie'); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $jours_restants <= 3 ? 'danger' : ($jours_restants <= 7 ? 'warning' : 'info'); ?>">
                                            <?php if($jours_restants > 0): ?>J-<?php echo $jours_restants; ?>
                                            <?php elseif($jours_restants == 0): ?>Aujourd'hui
                                            <?php else: ?>Passé<?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($examen['statut'] ?? ''); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Section 4: Vacances et congés -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-umbrella-beach me-2"></i>
                                Périodes de Congés
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($vacances)): ?>
                            <div class="alert alert-info">
                                Aucune période de congé programmée
                            </div>
                            <?php else: ?>
                            <div class="list-group">
                                <?php foreach($vacances as $vacance): 
                                    $debut = new DateTime($vacance['date_debut']);
                                    $fin = new DateTime($vacance['date_fin']);
                                    $today = new DateTime();
                                    $duree = $debut->diff($fin)->days + 1;
                                    
                                    $statut = '';
                                    if ($today > $fin) {
                                        $statut = 'passé';
                                        $couleur = 'secondary';
                                    } elseif ($today >= $debut && $today <= $fin) {
                                        $statut = 'en cours';
                                        $couleur = 'success';
                                    } else {
                                        $jours_restants = $today->diff($debut)->days;
                                        $statut = 'dans ' . $jours_restants . ' jours';
                                        $couleur = 'info';
                                    }
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo safeHtml($vacance['titre']); ?></h6>
                                        <span class="badge bg-<?php echo $couleur; ?>"><?php echo $statut; ?></span>
                                    </div>
                                    <p class="mb-1">
                                        <small>
                                            <strong>Du:</strong> <?php echo formatDateFr($vacance['date_debut']); ?><br>
                                            <strong>Au:</strong> <?php echo formatDateFr($vacance['date_fin']); ?><br>
                                            <strong>Durée:</strong> <?php echo $duree; ?> jours
                                        </small>
                                    </p>
                                    <?php if(!empty($vacance['description'])): ?>
                                    <p class="mb-1 small text-muted">
                                        <?php echo safeHtml($vacance['description']); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
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
                                <h6><i class="fas fa-clock"></i> Horaires académiques</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Cours:</strong> 7h30 - 18h00 (Lun-Ven)</li>
                                    <li><strong>Bibliothèque:</strong> 8h00 - 20h00 (Lun-Ven), 9h00 - 16h00 (Sam)</li>
                                    <li><strong>Administration:</strong> 8h00 - 16h00 (Lun-Ven)</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Important</h6>
                                <ul class="mb-0 small">
                                    <li>Les dates peuvent être modifiées par l'administration</li>
                                    <li>Consultez régulièrement les annonces</li>
                                    <li>En cas de doute, contactez le service académique</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-phone-alt"></i> Contacts</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Service académique:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Email:</strong> academique@isgi.cg</li>
                                    <li><strong>Urgences:</strong> +242 XX XX XX XX</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Export -->
    <div class="modal fade" id="modalExport" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Exporter le Calendrier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formExport">
                        <div class="mb-3">
                            <label class="form-label">Format d'export</label>
                            <select class="form-select" id="formatExport">
                                <option value="pdf">PDF (Imprimable)</option>
                                <option value="excel">Excel (Tableur)</option>
                                <option value="csv">CSV (Données)</option>
                                <option value="ical">iCal (Calendrier numérique)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Période</label>
                            <select class="form-select" id="periodeExport">
                                <option value="mois_cours">Mois en cours</option>
                                <option value="semestre">Semestre actuel</option>
                                <option value="annee">Année académique</option>
                                <option value="personnalise">Personnalisée</option>
                            </select>
                        </div>
                        <div class="mb-3 d-none" id="datesPersonnalisees">
                            <label class="form-label">Dates</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="date" class="form-control" id="dateDebutExport">
                                </div>
                                <div class="col-md-6">
                                    <input type="date" class="form-control" id="dateFinExport">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Inclure</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeCours" checked>
                                <label class="form-check-label">Périodes de cours</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeExamens" checked>
                                <label class="form-check-label">Examens</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeConges" checked>
                                <label class="form-check-label">Congés</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeStages" checked>
                                <label class="form-check-label">Stages</label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" onclick="exporterCalendrier()">
                        <i class="fas fa-download"></i> Exporter
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Variables globales
    let calendar;
    let currentFilters = ['all', 'debut', 'fin', 'examen', 'conge', 'stage'];
    
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
        
        // Recharger le calendrier avec le nouveau thème
        if (calendar) {
            calendar.render();
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
        
        // Initialiser le calendrier principal
        initCalendar();
        
        // Initialiser le mini-calendrier
        initMiniCalendar();
        
        // Gérer le modal d'export
        const periodeExport = document.getElementById('periodeExport');
        const datesPersonnalisees = document.getElementById('datesPersonnalisees');
        
        periodeExport.addEventListener('change', function() {
            if (this.value === 'personnalise') {
                datesPersonnalisees.classList.remove('d-none');
            } else {
                datesPersonnalisees.classList.add('d-none');
            }
        });
    });
    
    // Initialiser le calendrier principal
    function initCalendar() {
        const calendarEl = document.getElementById('calendar');
        
        // Préparer les événements
        const events = [];
        
        // Ajouter les événements du calendrier académique
        <?php foreach($calendrier_actuel as $cal): ?>
        events.push({
            title: '📚 Début cours - S<?php echo $cal['semestre']; ?> <?php echo $cal['type_rentree']; ?>',
            start: '<?php echo $cal['date_debut_cours']; ?>',
            end: '<?php echo $cal['date_debut_cours']; ?>T23:59:59',
            color: '#27ae60',
            textColor: 'white',
            type: 'debut'
        });
        
        events.push({
            title: '🏁 Fin cours - S<?php echo $cal['semestre']; ?>',
            start: '<?php echo $cal['date_fin_cours']; ?>',
            end: '<?php echo $cal['date_fin_cours']; ?>T23:59:59',
            color: '#e74c3c',
            textColor: 'white',
            type: 'fin'
        });
        
        <?php if($cal['date_debut_examens'] && $cal['date_fin_examens']): ?>
        events.push({
            title: '📝 Période d\'examens',
            start: '<?php echo $cal['date_debut_examens']; ?>',
            end: '<?php echo $cal['date_fin_examens']; ?>T23:59:59',
            color: '#f39c12',
            textColor: 'white',
            type: 'examen'
        });
        <?php endif; ?>
        
        <?php if($cal['date_debut_conge_etude'] && $cal['date_fin_conge_etude']): ?>
        events.push({
            title: '📖 Congé d\'étude',
            start: '<?php echo $cal['date_debut_conge_etude']; ?>',
            end: '<?php echo $cal['date_fin_conge_etude']; ?>T23:59:59',
            color: '#3498db',
            textColor: 'white',
            type: 'conge'
        });
        <?php endif; ?>
        
        <?php if($cal['date_debut_stage'] && $cal['date_fin_stage']): ?>
        events.push({
            title: '💼 Stage professionnel',
            start: '<?php echo $cal['date_debut_stage']; ?>',
            end: '<?php echo $cal['date_fin_stage']; ?>T23:59:59',
            color: '#2ecc71',
            textColor: 'white',
            type: 'stage'
        });
        <?php endif; ?>
        
        <?php if($cal['date_reprise_cours']): ?>
        events.push({
            title: '🔄 Reprise des cours',
            start: '<?php echo $cal['date_reprise_cours']; ?>',
            end: '<?php echo $cal['date_reprise_cours']; ?>T23:59:59',
            color: '#9b59b6',
            textColor: 'white',
            type: 'reprise'
        });
        <?php endif; ?>
        <?php endforeach; ?>
        
        // Ajouter les examens spécifiques
        <?php foreach($examens_prochains as $examen): ?>
        events.push({
            title: '📄 <?php echo safeHtml($examen["matiere_code"] ?? ""); ?> - <?php echo safeHtml($examen["type_examen"] ?? ""); ?>',
            start: '<?php echo $examen["date_examen"]; ?>T<?php echo $examen["heure_debut"] ?? "08:00:00"; ?>',
            end: '<?php echo $examen["date_examen"]; ?>T<?php echo $examen["heure_fin"] ?? "10:00:00"; ?>',
            color: '#e74c3c',
            textColor: 'white',
            type: 'examen',
            extendedProps: {
                salle: '<?php echo safeHtml($examen["salle"] ?? ""); ?>',
                matiere: '<?php echo safeHtml($examen["matiere_nom"] ?? ""); ?>'
            }
        });
        <?php endforeach; ?>
        
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
            },
            buttonText: {
                today: 'Aujourd\'hui',
                month: 'Mois',
                week: 'Semaine',
                day: 'Jour',
                list: 'Liste'
            },
            events: events,
            eventDidMount: function(info) {
                // Ajouter des tooltips
                if (info.event.extendedProps.salle) {
                    info.el.setAttribute('title', 
                        info.event.title + '\nSalle: ' + info.event.extendedProps.salle
                    );
                }
            },
            eventClick: function(info) {
                let details = info.event.title + '\n';
                
                if (info.event.start) {
                    const date = info.event.start.toLocaleDateString('fr-FR');
                    details += 'Date: ' + date + '\n';
                    
                    if (info.event.start.getHours() !== 0 || info.event.start.getMinutes() !== 0) {
                        const time = info.event.start.toLocaleTimeString('fr-FR', { 
                            hour: '2-digit', 
                            minute: '2-digit' 
                        });
                        const endTime = info.event.end ? info.event.end.toLocaleTimeString('fr-FR', { 
                            hour: '2-digit', 
                            minute: '2-digit' 
                        }) : '';
                        details += 'Heure: ' + time + (endTime ? ' - ' + endTime : '') + '\n';
                    }
                }
                
                if (info.event.extendedProps.salle) {
                    details += 'Salle: ' + info.event.extendedProps.salle + '\n';
                }
                
                if (info.event.extendedProps.matiere) {
                    details += 'Matière: ' + info.event.extendedProps.matiere;
                }
                
                alert(details);
            },
            datesSet: function(info) {
                // Mettre à jour le mini-calendrier
                updateMiniCalendar(info.start, info.end);
            }
        });
        
        calendar.render();
    }
    
    // Initialiser le mini-calendrier
    function initMiniCalendar() {
        const today = new Date();
        updateMiniCalendar(
            new Date(today.getFullYear(), today.getMonth(), 1),
            new Date(today.getFullYear(), today.getMonth() + 1, 0)
        );
    }
    
    // Mettre à jour le mini-calendrier
    function updateMiniCalendar(start, end) {
        const miniCalendarEl = document.getElementById('miniCalendar');
        const year = start.getFullYear();
        const month = start.getMonth();
        
        // Noms des jours
        const days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        
        // Premier jour du mois
        const firstDay = new Date(year, month, 1);
        // Dernier jour du mois
        const lastDay = new Date(year, month + 1, 0);
        
        let html = '';
        
        // En-têtes des jours
        days.forEach(day => {
            html += `<div class="mini-calendar-header">${day}</div>`;
        });
        
        // Jours vides au début
        const startDay = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1;
        for (let i = 0; i < startDay; i++) {
            html += '<div class="mini-calendar-day"></div>';
        }
        
        // Jours du mois
        const today = new Date();
        for (let day = 1; day <= lastDay.getDate(); day++) {
            const currentDate = new Date(year, month, day);
            const dateStr = currentDate.toISOString().split('T')[0];
            
            let className = 'mini-calendar-day';
            
            // Vérifier si c'est aujourd'hui
            if (currentDate.toDateString() === today.toDateString()) {
                className += ' today';
            }
            
            // Vérifier si c'est un jour avec événement
            // (simplifié - en réalité il faudrait vérifier dans events)
            if (day % 7 === 0 || day % 13 === 0) {
                className += ' event';
            }
            
            html += `<div class="${className}" onclick="goToDate('${dateStr}')">${day}</div>`;
        }
        
        miniCalendarEl.innerHTML = html;
    }
    
    // Aller à une date spécifique
    function goToDate(dateStr) {
        calendar.gotoDate(dateStr);
        calendar.changeView('timeGridDay');
    }
    
    // Filtrer les événements
    function filterEvents(type) {
        const badge = document.querySelector(`.filter-badge[data-filter="${type}"]`);
        
        if (type === 'all') {
            // Basculer tous les filtres
            const allActive = badge.classList.contains('active');
            document.querySelectorAll('.filter-badge').forEach(b => {
                if (allActive) {
                    b.classList.remove('active');
                } else {
                    b.classList.add('active');
                }
            });
            
            currentFilters = allActive ? [] : ['all', 'debut', 'fin', 'examen', 'conge', 'stage'];
        } else {
            // Basculer un filtre spécifique
            badge.classList.toggle('active');
            
            const index = currentFilters.indexOf(type);
            if (index > -1) {
                currentFilters.splice(index, 1);
            } else {
                currentFilters.push(type);
            }
            
            // Gérer le filtre "all"
            const allBadge = document.querySelector('.filter-badge[data-filter="all"]');
            if (currentFilters.length === 5 && currentFilters.includes('debut') && 
                currentFilters.includes('fin') && currentFilters.includes('examen') && 
                currentFilters.includes('conge') && currentFilters.includes('stage')) {
                currentFilters.push('all');
                allBadge.classList.add('active');
            } else {
                const allIndex = currentFilters.indexOf('all');
                if (allIndex > -1) {
                    currentFilters.splice(allIndex, 1);
                    allBadge.classList.remove('active');
                }
            }
        }
        
        // Appliquer les filtres
        calendar.getEvents().forEach(event => {
            if (currentFilters.length === 0 || currentFilters.includes('all') || 
                currentFilters.includes(event.extendedProps.type || event.extendedProps.type)) {
                event.setProp('display', 'auto');
            } else {
                event.setProp('display', 'none');
            }
        });
    }
    
    // Changer la période d'affichage
    function changePeriod(view) {
        calendar.changeView(view);
    }
    
    // Imprimer le calendrier
    function imprimerCalendrier() {
        const printContent = document.getElementById('calendar').innerHTML;
        const printWindow = window.open('', '_blank');
        
        const style = document.documentElement.getAttribute('data-theme') === 'dark' ? 
            '<style>body { background: white; color: black; } .fc { background: white; }</style>' : '';
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>Calendrier Académique ISGI</title>
                    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .print-header { text-align: center; margin-bottom: 30px; }
                        .print-header h2 { color: #2c3e50; }
                        .print-info { margin-bottom: 20px; }
                        .fc { max-width: 100% !important; }
                        .fc-toolbar { margin-bottom: 1em; }
                        @media print {
                            body { margin: 0; padding: 0; }
                            .fc-toolbar { display: none; }
                        }
                    </style>
                    ${style}
                </head>
                <body>
                    <div class="print-header">
                        <h2>ISGI - Calendrier Académique</h2>
                        <div class="print-info">
                            <p><strong>Étudiant:</strong> <?php echo safeHtml($info_etudiant['nom'] ?? ''); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?></p>
                            <p><strong>Matricule:</strong> <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?></p>
                            <p><strong>Filière:</strong> <?php echo safeHtml($info_etudiant['filiere_nom'] ?? ''); ?></p>
                            <p><strong>Date d'impression:</strong> ${new Date().toLocaleDateString('fr-FR')}</p>
                        </div>
                    </div>
                    <div id="printCalendar"></div>
                    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"><\/script>
                    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"><\/script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const calendarEl = document.getElementById('printCalendar');
                            const printCalendar = new FullCalendar.Calendar(calendarEl, {
                                initialView: 'dayGridMonth',
                                locale: 'fr',
                                headerToolbar: false,
                                events: ${JSON.stringify(calendar.getEvents().map(e => ({
                                    title: e.title,
                                    start: e.start,
                                    end: e.end,
                                    color: e.backgroundColor
                                })))}
                            });
                            printCalendar.render();
                            
                            setTimeout(() => {
                                window.print();
                                window.close();
                            }, 500);
                        });
                    <\/script>
                </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // Exporter le calendrier
    function exporterCalendrier() {
        const format = document.getElementById('formatExport').value;
        const periode = document.getElementById('periodeExport').value;
        
        // Simuler un téléchargement
        alert(`Export ${format} pour la période ${periode} démarré.`);
        
        // En réalité, on ferait une requête AJAX vers le serveur
        // fetch('api/export_calendrier.php', {
        //     method: 'POST',
        //     body: JSON.stringify({ format, periode })
        // })
        
        // Fermer le modal
        bootstrap.Modal.getInstance(document.getElementById('modalExport')).hide();
    }
    
    // Fonction utilitaire pour formater la date
    function formatDateFr(date) {
        return new Date(date).toLocaleDateString('fr-FR');
    }
    </script>
</body>
</html>