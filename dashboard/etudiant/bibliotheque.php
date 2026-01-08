<?php
// dashboard/etudiant/bibliotheque.php

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
    $pageTitle = "Bibliothèque - Étudiant";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function formatFileSize($bytes) {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
    
    function getStatutBadge($statut) {
        $statut = strval($statut);
        switch ($statut) {
            case 'disponible':
            case 'actif':
            case 'publie':
                return '<span class="badge bg-success">Disponible</span>';
            case 'emprunte':
                return '<span class="badge bg-warning">Emprunté</span>';
            case 'reserve':
                return '<span class="badge bg-info">Réservé</span>';
            case 'maintenance':
            case 'brouillon':
            case 'en_revision':
                return '<span class="badge bg-danger">Indisponible</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Fonction pour obtenir l'URL correcte de l'image
    function getCoverImageUrl($imagePath) {
        if (empty($imagePath)) {
            return ''; // Aucune image
        }
        
        // Si c'est déjà une URL complète
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }
        
        // Si c'est un chemin relatif
        if (strpos($imagePath, '/') === 0) {
            // Chemin absolu sur le serveur
            return $imagePath;
        }
        
        // Par défaut, supposer que c'est dans uploads/bibliotheque/
        return '/uploads/bibliotheque/' . $imagePath;
    }
    
    // Fonction pour obtenir l'URL correcte du fichier PDF
    function getPdfFileUrl($pdfPath) {
        if (empty($pdfPath)) {
            return ''; // Aucun fichier
        }
        
        // Si c'est déjà une URL complète
        if (filter_var($pdfPath, FILTER_VALIDATE_URL)) {
            return $pdfPath;
        }
        
        // Si c'est un chemin relatif
        if (strpos($pdfPath, '/') === 0) {
            // Chemin absolu sur le serveur
            return $pdfPath;
        }
        
        // Par défaut, supposer que c'est dans uploads/bibliotheque/pdf/
        return '/uploads/bibliotheque/pdf/' . $pdfPath;
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
    
    // Récupérer l'ID de l'étudiant et du site
    $etudiant_id = SessionManager::getEtudiantId();
    $user_id = SessionManager::getUserId();
    $site_id = SessionManager::getSiteId();
    
    // Récupérer les informations de l'étudiant pour la filière et vérifier les frais
    $info_etudiant = array();
    $frais_payes = false;
    $date_limite_frais = '';
    $montant_frais = 50; // 50 FCFA par mois
    
    if ($etudiant_id) {
        // Requête pour récupérer les infos de l'étudiant et vérifier les frais de bibliothèque
        $stmt = $db->prepare("SELECT 
                e.*, 
                f.id as filiere_id,
                (SELECT COUNT(*) FROM paiements_frais 
                 WHERE etudiant_id = e.id 
                 AND type_frais = 'bibliotheque' 
                 AND mois = MONTH(CURRENT_DATE()) 
                 AND annee = YEAR(CURRENT_DATE()) 
                 AND statut = 'paye') as frais_payes_count
            FROM etudiants e 
            LEFT JOIN classes c ON e.classe_id = c.id 
            LEFT JOIN filieres f ON c.filiere_id = f.id 
            WHERE e.id = ?");
        
        $stmt->execute([$etudiant_id]);
        $info_etudiant = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        
        // Vérifier si les frais sont payés pour le mois en cours
        $frais_payes = isset($info_etudiant['frais_payes_count']) && $info_etudiant['frais_payes_count'] > 0;
        
        // Calculer la date limite pour payer les frais (dernier jour du mois)
        $date_limite_frais = date('Y-m-t');
    }
    
    $filiere_id = $info_etudiant['filiere_id'] ?? 0;
    
    // Initialiser les variables
    $stats = array(
        'livres_total' => 0,
        'livres_disponibles' => 0,
        'livres_empruntes' => 0,
        'emprunts_actifs' => 0,
        'favoris_total' => 0,
        'telechargements_total' => 0,
        'categories_total' => 0,
        'documents_total' => 0
    );
    
    $livres_populaires = array();
    $livres_recents = array();
    $livres_favoris = array();
    $emprunts_actifs = array();
    $historique_emprunts = array();
    $categories = array();
    $documents_recents = array();
    $suggestions = array();
    $error = null;
    $retards = 0; // Initialisation de la variable retards
    
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
    
    // Récupérer les statistiques
    if ($site_id) {
        // Livres totaux
        $result = executeSingleQuery($db, 
            "SELECT COUNT(*) as total FROM bibliotheque_livres WHERE site_id = ?", 
            [$site_id]);
        $stats['livres_total'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Livres disponibles
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total FROM bibliotheque_livres WHERE site_id = ? AND statut = 'disponible'",
            [$site_id]);
        $stats['livres_disponibles'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Livres empruntés
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total FROM bibliotheque_livres WHERE site_id = ? AND statut = 'emprunte'",
            [$site_id]);
        $stats['livres_empruntes'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Emprunts actifs de l'étudiant
        if ($etudiant_id) {
            $result = executeSingleQuery($db,
                "SELECT COUNT(*) as total FROM bibliotheque_emprunts WHERE etudiant_id = ? AND statut = 'en_cours'",
                [$etudiant_id]);
            $stats['emprunts_actifs'] = isset($result['total']) ? intval($result['total']) : 0;
            
            // Favoris
            $result = executeSingleQuery($db,
                "SELECT COUNT(*) as total FROM bibliotheque_favoris WHERE utilisateur_id = ?",
                [$user_id]);
            $stats['favoris_total'] = isset($result['total']) ? intval($result['total']) : 0;
            
            // Téléchargements
            $result = executeSingleQuery($db,
                "SELECT COUNT(*) as total FROM bibliotheque_telechargements WHERE utilisateur_id = ?",
                [$user_id]);
            $stats['telechargements_total'] = isset($result['total']) ? intval($result['total']) : 0;
        }
        
        // Catégories totales
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total FROM bibliotheque_categories WHERE site_id = ? OR site_id IS NULL",
            [$site_id]);
        $stats['categories_total'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Documents rédactionnels
        $result = executeSingleQuery($db,
            "SELECT COUNT(*) as total FROM bibliotheque_documents WHERE site_id = ? AND statut = 'publie'",
            [$site_id]);
        $stats['documents_total'] = isset($result['total']) ? intval($result['total']) : 0;
        
        // Récupérer les livres populaires (les plus téléchargés)
        $livres_populaires = executeQuery($db,
            "SELECT bl.*, bc.nom as categorie_nom,
                    (SELECT COUNT(*) FROM bibliotheque_telechargements WHERE livre_id = bl.id) as telechargements
             FROM bibliotheque_livres bl
             LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
             WHERE bl.site_id = ? 
             AND bl.statut = 'disponible'
             ORDER BY telechargements DESC, bl.nombre_telechargements DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les livres récemment ajoutés
        $livres_recents = executeQuery($db,
            "SELECT bl.*, bc.nom as categorie_nom
             FROM bibliotheque_livres bl
             LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
             WHERE bl.site_id = ? 
             AND bl.statut = 'disponible'
             ORDER BY bl.date_ajout DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les livres favoris de l'étudiant
        if ($user_id) {
            $livres_favoris = executeQuery($db,
                "SELECT bl.*, bc.nom as categorie_nom
                 FROM bibliotheque_favoris bf
                 JOIN bibliotheque_livres bl ON bf.livre_id = bl.id
                 LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
                 WHERE bf.utilisateur_id = ? 
                 AND bl.statut = 'disponible'
                 ORDER BY bf.date_ajout DESC
                 LIMIT 5",
                [$user_id]);
        }
        
        // Récupérer les emprunts actifs
        if ($etudiant_id) {
            $emprunts_actifs = executeQuery($db,
                "SELECT be.*, bl.titre, bl.auteur, bl.isbn
                 FROM bibliotheque_emprunts be
                 JOIN bibliotheque_livres bl ON be.livre_id = bl.id
                 WHERE be.etudiant_id = ? 
                 AND be.statut = 'en_cours'
                 ORDER BY be.date_retour_prevue ASC
                 LIMIT 5",
                [$etudiant_id]);
            
            // Vérifier les retards
            if($stats['emprunts_actifs'] > 0) {
                foreach($emprunts_actifs as $emprunt) {
                    $date_retour = new DateTime($emprunt['date_retour_prevue']);
                    $date_actuelle = new DateTime();
                    if ($date_retour < $date_actuelle) {
                        $retards++;
                    }
                }
            }
            
            // Historique des emprunts
            $historique_emprunts = executeQuery($db,
                "SELECT be.*, bl.titre, bl.auteur
                 FROM bibliotheque_emprunts be
                 JOIN bibliotheque_livres bl ON be.livre_id = bl.id
                 WHERE be.etudiant_id = ? 
                 AND be.statut IN ('retourne', 'en_retard')
                 ORDER BY be.date_retour_effectif DESC
                 LIMIT 5",
                [$etudiant_id]);
        }
        
        // Récupérer les catégories principales
        $categories = executeQuery($db,
            "SELECT bc.*, COUNT(bl.id) as nb_livres
             FROM bibliotheque_categories bc
             LEFT JOIN bibliotheque_livres bl ON bc.id = bl.categorie_id AND bl.statut = 'disponible'
             WHERE (bc.site_id = ? OR bc.site_id IS NULL)
             AND bc.parent_id IS NULL
             GROUP BY bc.id
             ORDER BY bc.nom
             LIMIT 8",
            [$site_id]);
        
        // Récupérer les documents rédactionnels récents
        $documents_recents = executeQuery($db,
            "SELECT bd.*, CONCAT(u.nom, ' ', u.prenom) as auteur_nom
             FROM bibliotheque_documents bd
             JOIN utilisateurs u ON bd.auteur_id = u.id
             WHERE bd.site_id = ? 
             AND bd.statut = 'publie'
             ORDER BY bd.date_publication DESC, bd.date_modification DESC
             LIMIT 5",
            [$site_id]);
        
        // Récupérer les suggestions basées sur la filière
        if ($filiere_id > 0) {
            $suggestions = executeQuery($db,
                "SELECT bl.*, bc.nom as categorie_nom
                 FROM bibliotheque_livres bl
                 LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
                 WHERE bl.site_id = ? 
                 AND bl.statut = 'disponible'
                 AND (bl.filiere_id = ? OR bl.filiere_id IS NULL)
                 ORDER BY bl.date_ajout DESC
                 LIMIT 5",
                [$site_id, $filiere_id]);
        } else {
            // Suggestions générales si pas de filière
            $suggestions = executeQuery($db,
                "SELECT bl.*, bc.nom as categorie_nom
                 FROM bibliotheque_livres bl
                 LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
                 WHERE bl.site_id = ? 
                 AND bl.statut = 'disponible'
                 ORDER BY RAND()
                 LIMIT 5",
                [$site_id]);
        }
    }
    
    // Traitement des actions
    $action = $_GET['action'] ?? '';
    $livre_id = $_GET['id'] ?? 0;
    $message = '';
    $message_type = '';
    
    if ($action && $livre_id && $user_id) {
        // Vérifier si l'étudiant a payé ses frais avant d'autoriser certaines actions
        $actionAutorisee = true;
        $actionRequiertPaiement = in_array($action, ['telecharger', 'ajouter_favori', 'retirer_favori']);
        
        if ($actionRequiertPaiement && !$frais_payes) {
            $actionAutorisee = false;
            $message = "Vous devez payer vos frais de bibliothèque (50 FCFA/mois) pour effectuer cette action.";
            $message_type = 'warning';
        }
        
        if ($actionAutorisee) {
            switch ($action) {
                case 'ajouter_favori':
                    try {
                        // Vérifier si déjà en favoris
                        $stmt = $db->prepare("SELECT id FROM bibliotheque_favoris WHERE livre_id = ? AND utilisateur_id = ?");
                        $stmt->execute([$livre_id, $user_id]);
                        if (!$stmt->fetch()) {
                            $stmt = $db->prepare("INSERT INTO bibliotheque_favoris (livre_id, utilisateur_id) VALUES (?, ?)");
                            $stmt->execute([$livre_id, $user_id]);
                            $message = "Livre ajouté aux favoris avec succès!";
                            $message_type = 'success';
                        } else {
                            $message = "Ce livre est déjà dans vos favoris";
                            $message_type = 'info';
                        }
                    } catch (Exception $e) {
                        $message = "Erreur lors de l'ajout aux favoris: " . $e->getMessage();
                        $message_type = 'danger';
                    }
                    break;
                    
                case 'retirer_favori':
                    try {
                        $stmt = $db->prepare("DELETE FROM bibliotheque_favoris WHERE livre_id = ? AND utilisateur_id = ?");
                        $stmt->execute([$livre_id, $user_id]);
                        $message = "Livre retiré des favoris avec succès!";
                        $message_type = 'success';
                    } catch (Exception $e) {
                        $message = "Erreur lors du retrait des favoris: " . $e->getMessage();
                        $message_type = 'danger';
                    }
                    break;
                    
                case 'telecharger':
                    try {
                        // Vérifier si le livre existe et est disponible
                        $stmt = $db->prepare("SELECT * FROM bibliotheque_livres WHERE id = ? AND statut = 'disponible'");
                        $stmt->execute([$livre_id]);
                        $livre = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($livre && !empty($livre['fichier_pdf'])) {
                            // Enregistrer le téléchargement dans l'historique
                            $stmt = $db->prepare("INSERT INTO bibliotheque_telechargements (livre_id, utilisateur_id) VALUES (?, ?)");
                            $stmt->execute([$livre_id, $user_id]);
                            
                            // Incrémenter le compteur de téléchargements
                            $stmt = $db->prepare("UPDATE bibliotheque_livres SET nombre_telechargements = nombre_telechargements + 1 WHERE id = ?");
                            $stmt->execute([$livre_id]);
                            
                            // Rediriger vers le fichier PDF
                            $pdf_url = getPdfFileUrl($livre['fichier_pdf']);
                            header('Location: ' . $pdf_url);
                            exit();
                        } else {
                            $message = "Le livre n'est pas disponible pour téléchargement";
                            $message_type = 'warning';
                        }
                    } catch (Exception $e) {
                        $message = "Erreur lors du téléchargement: " . $e->getMessage();
                        $message_type = 'danger';
                    }
                    break;
                    
                case 'preview':
                    // Pour l'aperçu, nous utiliserons une page séparée
                    try {
                        $stmt = $db->prepare("SELECT * FROM bibliotheque_livres WHERE id = ?");
                        $stmt->execute([$livre_id]);
                        $livre = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($livre) {
                            // Rediriger vers la page d'aperçu
                            $_SESSION['livre_preview'] = $livre;
                            header('Location: bibliotheque_preview.php?id=' . $livre_id);
                            exit();
                        }
                    } catch (Exception $e) {
                        $message = "Erreur lors de l'accès à l'aperçu: " . $e->getMessage();
                        $message_type = 'danger';
                    }
                    break;
            }
        }
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
    
    <!-- Styles personnalisés -->
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
    
    /* Sidebar (identique au dashboard étudiant) */
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
    
    /* Menu contextuel pour les livres */
    .context-menu {
        position: absolute;
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        z-index: 1000;
        min-width: 180px;
        display: none;
    }
    
    .context-menu-item {
        padding: 10px 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.2s;
    }
    
    .context-menu-item:hover {
        background: #f8f9fa;
    }
    
    .context-menu-item i {
        width: 20px;
        color: var(--primary-color);
    }
    
    .context-menu-divider {
        height: 1px;
        background: #eee;
        margin: 5px 0;
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
    
    /* Livre cards */
    .livre-card {
        transition: all 0.3s;
        height: 100%;
        position: relative;
        cursor: pointer;
    }
    
    .livre-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .livre-cover {
        height: 200px;
        background-color: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border-radius: 5px;
        margin-bottom: 15px;
    }
    
    .livre-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }
    
    .livre-card:hover .livre-cover img {
        transform: scale(1.05);
    }
    
    .livre-cover i {
        font-size: 4rem;
        color: var(--text-muted);
    }
    
    .livre-info {
        padding: 0 5px;
    }
    
    .livre-title {
        font-weight: 600;
        font-size: 1.1rem;
        margin-bottom: 5px;
        color: var(--text-color);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        height: 3em;
    }
    
    .livre-auteur {
        color: var(--text-muted);
        font-size: 0.9rem;
        margin-bottom: 5px;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .livre-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
    }
    
    .livre-actions {
        display: flex;
        gap: 5px;
        position: relative;
    }
    
    /* Boutons d'action */
    .btn-action {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        position: relative;
    }
    
    /* Menu flottant */
    .floating-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        z-index: 1000;
        min-width: 180px;
        display: none;
    }
    
    .floating-menu.show {
        display: block;
    }
    
    .floating-menu-item {
        padding: 10px 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.2s;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .floating-menu-item:last-child {
        border-bottom: none;
    }
    
    .floating-menu-item:hover {
        background: #f8f9fa;
    }
    
    .floating-menu-item i {
        width: 20px;
        color: var(--primary-color);
    }
    
    /* Catégorie badges */
    .categorie-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        margin-bottom: 5px;
        margin-right: 5px;
    }
    
    /* Quick Actions */
    .quick-action {
        text-align: center;
        cursor: pointer;
        padding: 15px;
        border-radius: 8px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        transition: all 0.3s;
    }
    
    .quick-action:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        background: var(--primary-color);
        color: white;
    }
    
    .quick-action i {
        font-size: 2rem;
        margin-bottom: 10px;
        color: var(--primary-color);
    }
    
    .quick-action:hover i {
        color: white;
    }
    
    .quick-action .title {
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .quick-action .description {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    .quick-action:hover .description {
        color: rgba(255,255,255,0.8);
    }
    
    /* Recherche */
    .search-box {
        position: relative;
    }
    
    .search-box input {
        padding-right: 40px;
    }
    
    .search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }
    
    /* Filtres */
    .filters-container {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
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
    
    /* Pagination */
    .pagination .page-link {
        color: var(--primary-color);
    }
    
    .pagination .page-item.active .page-link {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
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
        
        .livre-cover {
            height: 150px;
        }
        
        .quick-action i {
            font-size: 1.5rem;
        }
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
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
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
    }
    
    .progress-bar {
        background-color: var(--primary-color);
    }
    
    /* Message frais non payés */
    .frais-alert {
        border-left: 5px solid #ffc107;
        background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    /* Mini menu livre */
    .mini-menu {
        display: none;
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 10;
    }
    
    .livre-card:hover .mini-menu {
        display: block;
    }
    
    .mini-menu-btn {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(255,255,255,0.9);
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .mini-menu-btn:hover {
        background: white;
        transform: scale(1.1);
    }
    
    /* Modal d'aperçu */
    .preview-modal img {
        max-width: 100%;
        height: auto;
        border-radius: 5px;
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
                <?php if($frais_payes): ?>
                <div class="badge bg-success mt-1">Frais payés</div>
                <?php else: ?>
                <div class="badge bg-warning mt-1">Frais en attente</div>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="bibliotheque.php" class="nav-link active">
                        <i class="fas fa-book-reader"></i>
                        <span>Bibliothèque</span>
                        <?php if($stats['emprunts_actifs'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['emprunts_actifs']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bibliothèque</div>
                    <a href="bibliotheque.php?section=recherche" class="nav-link">
                        <i class="fas fa-search"></i>
                        <span>Recherche</span>
                    </a>
                    <a href="bibliotheque.php?section=catalogue" class="nav-link">
                        <i class="fas fa-list"></i>
                        <span>Catalogue</span>
                    </a>
                    <a href="bibliotheque.php?section=emprunts" class="nav-link">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Mes Emprunts</span>
                        <?php if($stats['emprunts_actifs'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['emprunts_actifs']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="bibliotheque.php?section=favoris" class="nav-link">
                        <i class="fas fa-heart"></i>
                        <span>Mes Favoris</span>
                        <?php if($stats['favoris_total'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['favoris_total']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Catégories</div>
                    <?php foreach($categories as $categorie): ?>
                    <a href="bibliotheque.php?categorie=<?php echo $categorie['id']; ?>" class="nav-link">
                        <i class="fas <?php echo safeHtml($categorie['icone'] ?? 'fa-book'); ?>"></i>
                        <span><?php echo safeHtml($categorie['nom']); ?></span>
                        <?php if($categorie['nb_livres'] > 0): ?>
                        <span class="nav-badge"><?php echo $categorie['nb_livres']; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Documents</div>
                    <a href="bibliotheque.php?section=documents" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Documents Rédactionnels</span>
                        <?php if($stats['documents_total'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['documents_total']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="bibliotheque.php?section=telechargements" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Mes Téléchargements</span>
                        <?php if($stats['telechargements_total'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['telechargements_total']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <?php if(!$frais_payes): ?>
                    <a href="../paiements/payer_frais.php?type=bibliotheque" class="nav-link">
                        <i class="fas fa-credit-card"></i>
                        <span>Payer frais bibliothèque (50 FCFA)</span>
                    </a>
                    <?php endif; ?>
                    <a href="../dashboard.php" class="nav-link">
                        <i class="fas fa-arrow-left"></i>
                        <span>Retour au Dashboard</span>
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
                            <i class="fas fa-book-reader me-2"></i>
                            Bibliothèque ISGI
                        </h2>
                        <p class="text-muted mb-0">
                            Accédez à notre collection de livres, documents et ressources académiques
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="?section=emprunts" class="btn btn-success">
                            <i class="fas fa-exchange-alt"></i> Mes Emprunts
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(!$frais_payes): ?>
            <div class="frais-alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning me-3"></i>
                    <div>
                        <h5 class="mb-1">Frais de bibliothèque non payés</h5>
                        <p class="mb-1">Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA/mois) pour pouvoir :</p>
                        <ul class="mb-1">
                            <li>Télécharger des livres</li>
                            <li>Ajouter des livres aux favoris</li>
                            <li>Emprunter des livres physiques</li>
                        </ul>
                        <p class="mb-0">Date limite de paiement : <?php echo formatDateFr($date_limite_frais); ?></p>
                        <a href="../paiements/payer_frais.php?type=bibliotheque" class="btn btn-warning btn-sm mt-2">
                            <i class="fas fa-credit-card"></i> Payer maintenant (<?php echo $montant_frais; ?> FCFA)
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(isset($message) && !empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <?php echo safeHtml($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Menu contextuel (caché par défaut) -->
            <div class="context-menu" id="contextMenu">
                <div class="context-menu-item" onclick="showPreviewModal()">
                    <i class="fas fa-eye"></i>
                    <span>Aperçu rapide</span>
                </div>
                <div class="context-menu-item" onclick="location.href='?action=preview&id=' + currentLivreId">
                    <i class="fas fa-external-link-alt"></i>
                    <span>Voir détails</span>
                </div>
                <div class="context-menu-divider"></div>
                <div class="context-menu-item" onclick="addToFavorites()">
                    <i class="fas fa-heart"></i>
                    <span>Ajouter aux favoris</span>
                </div>
                <div class="context-menu-item" onclick="downloadBook()">
                    <i class="fas fa-download"></i>
                    <span>Télécharger</span>
                </div>
            </div>
            
            <!-- Section 1: Barre de recherche et statistiques -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-search me-2"></i>Rechercher dans la bibliothèque
                            </h5>
                            <form action="bibliotheque.php" method="GET">
                                <div class="input-group">
                                    <input type="text" name="q" class="form-control" 
                                           placeholder="Rechercher par titre, auteur, ISBN..." 
                                           value="<?php echo safeHtml($_GET['q'] ?? ''); ?>"
                                           <?php if(!$frais_payes) echo 'disabled title="Frais non payés"'; ?>>
                                    <button class="btn btn-primary" type="submit" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                        <i class="fas fa-search"></i> Rechercher
                                    </button>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <select name="categorie" class="form-select" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                            <option value="">Toutes les catégories</option>
                                            <?php foreach($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" 
                                                <?php echo (($_GET['categorie'] ?? '') == $cat['id']) ? 'selected' : ''; ?>>
                                                <?php echo safeHtml($cat['nom']); ?> (<?php echo $cat['nb_livres']; ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="langue" class="form-select" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                            <option value="">Toutes les langues</option>
                                            <option value="Français" <?php echo (($_GET['langue'] ?? '') == 'Français') ? 'selected' : ''; ?>>Français</option>
                                            <option value="Anglais" <?php echo (($_GET['langue'] ?? '') == 'Anglais') ? 'selected' : ''; ?>>Anglais</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="statut" class="form-select" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                            <option value="">Tous les statuts</option>
                                            <option value="disponible" <?php echo (($_GET['statut'] ?? '') == 'disponible') ? 'selected' : ''; ?>>Disponible</option>
                                            <option value="emprunte" <?php echo (($_GET['statut'] ?? '') == 'emprunte') ? 'selected' : ''; ?>>Emprunté</option>
                                        </select>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-user-circle me-2"></i>Mon Espace
                            </h5>
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <div class="stat-value"><?php echo $stats['emprunts_actifs']; ?></div>
                                    <div class="stat-label">Emprunts actifs</div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="stat-value"><?php echo $stats['favoris_total']; ?></div>
                                    <div class="stat-label">Favoris</div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-value"><?php echo $stats['telechargements_total']; ?></div>
                                    <div class="stat-label">Téléchargements</div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-value"><?php echo count($historique_emprunts); ?></div>
                                    <div class="stat-label">Historique</div>
                                </div>
                            </div>
                            <?php if(!$frais_payes): ?>
                            <div class="alert alert-warning mt-2 p-2">
                                <small><i class="fas fa-info-circle"></i> Accès limité - Frais non payés</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Statistiques Principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-primary stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['livres_total']; ?></div>
                        <div class="stat-label">Livres Totaux</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-success stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['livres_disponibles']; ?></div>
                        <div class="stat-label">Livres Disponibles</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-warning stat-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['livres_empruntes']; ?></div>
                        <div class="stat-label">Livres Empruntés</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card">
                        <div class="text-info stat-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['categories_total']; ?></div>
                        <div class="stat-label">Catégories</div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Onglets pour différentes sections -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="bibliothequeTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="accueil-tab" data-bs-toggle="tab" data-bs-target="#accueil" type="button">
                                <i class="fas fa-home me-2"></i>Accueil
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="catalogue-tab" data-bs-toggle="tab" data-bs-target="#catalogue" type="button">
                                <i class="fas fa-list me-2"></i>Catalogue
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="emprunts-tab" data-bs-toggle="tab" data-bs-target="#emprunts" type="button">
                                <i class="fas fa-exchange-alt me-2"></i>Mes Emprunts
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button">
                                <i class="fas fa-file-alt me-2"></i>Documents
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="bibliothequeTabsContent">
                        <!-- Tab 1: Accueil -->
                        <div class="tab-pane fade show active" id="accueil">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-fire me-2"></i>Livres Populaires</h5>
                                    <?php if(empty($livres_populaires)): ?>
                                    <div class="alert alert-info">
                                        Aucun livre populaire pour le moment
                                    </div>
                                    <?php else: ?>
                                    <div class="row">
                                        <?php foreach($livres_populaires as $livre): 
                                            $cover_url = getCoverImageUrl($livre['couverture'] ?? '');
                                            $pdf_url = getPdfFileUrl($livre['fichier_pdf'] ?? '');
                                        ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card livre-card" data-livre-id="<?php echo $livre['id']; ?>" 
                                                 data-livre-titre="<?php echo safeHtml($livre['titre']); ?>"
                                                 data-livre-auteur="<?php echo safeHtml($livre['auteur']); ?>"
                                                 data-livre-description="<?php echo safeHtml($livre['description'] ?? ''); ?>"
                                                 data-livre-cover="<?php echo $cover_url; ?>"
                                                 onclick="showLivreDetails(<?php echo $livre['id']; ?>)">
                                                <div class="livre-cover">
                                                    <?php if(!empty($cover_url)): ?>
                                                    <img src="<?php echo $cover_url; ?>" 
                                                         alt="<?php echo safeHtml($livre['titre']); ?>"
                                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGRvbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM2Yzc1N2QiPkxpdnJlPC90ZXh0Pjwvc3ZnPg=='">
                                                    <?php else: ?>
                                                    <i class="fas fa-book"></i>
                                                    <?php endif; ?>
                                                    <div class="mini-menu">
                                                        <button class="mini-menu-btn" onclick="event.stopPropagation(); showMiniMenu(<?php echo $livre['id']; ?>, event)">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="livre-info">
                                                    <div class="livre-title"><?php echo safeHtml($livre['titre']); ?></div>
                                                    <div class="livre-auteur"><?php echo safeHtml($livre['auteur']); ?></div>
                                                    <div class="livre-meta">
                                                        <span class="badge bg-info"><?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></span>
                                                        <div class="livre-actions">
                                                            <?php if($frais_payes): ?>
                                                            <a href="?action=ajouter_favori&id=<?php echo $livre['id']; ?>" 
                                                               class="btn-action btn btn-sm btn-outline-danger" 
                                                               title="Ajouter aux favoris"
                                                               onclick="event.stopPropagation()">
                                                                <i class="fas fa-heart"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if(!empty($pdf_url)): ?>
                                                            <a href="<?php echo $pdf_url; ?>" 
                                                               target="_blank" 
                                                               class="btn-action btn btn-sm btn-outline-primary"
                                                               title="Consulter"
                                                               onclick="event.stopPropagation()">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-history me-2"></i>Historique des Emprunts</h5>
                                    <?php if(empty($historique_emprunts)): ?>
                                    <div class="alert alert-info">
                                        Aucun historique d'emprunt
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Livre</th>
                                                    <th>Date emprunt</th>
                                                    <th>Date retour</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($historique_emprunts as $emprunt): ?>
                                                <tr>
                                                    <td><?php echo safeHtml($emprunt['titre'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($emprunt['date_emprunt'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($emprunt['date_retour_effectif'] ?? ''); ?></td>
                                                    <td><?php echo getStatutBadge($emprunt['statut'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-clock me-2"></i>Livres Récents</h5>
                                    <?php if(empty($livres_recents)): ?>
                                    <div class="alert alert-info">
                                        Aucun livre récent
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($livres_recents as $livre): 
                                            $pdf_url = getPdfFileUrl($livre['fichier_pdf'] ?? '');
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($livre['titre']); ?></h6>
                                                <small><?php echo formatDateFr($livre['date_ajout'] ?? ''); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Auteur: <?php echo safeHtml($livre['auteur']); ?></small><br>
                                                <small>Catégorie: <?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></small>
                                            </p>
                                            <div class="mt-2">
                                                <?php if(!empty($pdf_url)): ?>
                                                <a href="<?php echo $pdf_url; ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye"></i> Consulter
                                                </a>
                                                <?php endif; ?>
                                                <?php if($frais_payes): ?>
                                                <a href="?action=ajouter_favori&id=<?php echo $livre['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-heart"></i> Favori
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-lightbulb me-2"></i>Suggestions pour vous</h5>
                                    <?php if(empty($suggestions)): ?>
                                    <div class="alert alert-info">
                                        Aucune suggestion pour le moment
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($suggestions as $livre): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($livre['titre']); ?></h6>
                                                <span class="badge bg-primary">Suggestions</span>
                                            </div>
                                            <p class="mb-1">
                                                <small><?php echo safeHtml($livre['auteur']); ?></small><br>
                                                <small>Catégorie: <?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-bar me-2"></i>Statistiques par Catégorie</h5>
                                    <canvas id="categorieChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Catalogue -->
                        <div class="tab-pane fade" id="catalogue">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="filters-container">
                                        <h6><i class="fas fa-filter me-2"></i>Filtres</h6>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Catégorie</label>
                                            <select class="form-select" id="filterCategorie" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <option value="">Toutes</option>
                                                <?php foreach($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>">
                                                    <?php echo safeHtml($cat['nom']); ?> (<?php echo $cat['nb_livres']; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Langue</label>
                                            <select class="form-select" id="filterLangue" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <option value="">Toutes</option>
                                                <option value="Français">Français</option>
                                                <option value="Anglais">Anglais</option>
                                                <option value="Autre">Autre</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Statut</label>
                                            <select class="form-select" id="filterStatut" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <option value="">Tous</option>
                                                <option value="disponible">Disponible</option>
                                                <option value="emprunte">Emprunté</option>
                                                <option value="reserve">Réservé</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Trier par</label>
                                            <select class="form-select" id="filterTri" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <option value="date_desc">Plus récents</option>
                                                <option value="titre_asc">Titre A-Z</option>
                                                <option value="titre_desc">Titre Z-A</option>
                                                <option value="populaire">Populaires</option>
                                            </select>
                                        </div>
                                        
                                        <button class="btn btn-primary w-100" onclick="appliquerFiltres()" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                            <i class="fas fa-check"></i> Appliquer
                                        </button>
                                        <?php if(!$frais_payes): ?>
                                        <div class="alert alert-warning mt-3 p-2">
                                            <small><i class="fas fa-info-circle"></i> Payez vos frais (<?php echo $montant_frais; ?> FCFA) pour utiliser les filtres</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-9">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5>Catalogue complet</h5>
                                        <div class="btn-group">
                                            <button class="btn btn-outline-secondary active" onclick="changerVue('grid')" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <i class="fas fa-th"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="changerVue('list')" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                <i class="fas fa-list"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div id="vueGrid" class="row">
                                        <?php 
                                        // Récupérer tous les livres disponibles
                                        $catalogue_livres = executeQuery($db,
                                            "SELECT bl.*, bc.nom as categorie_nom
                                             FROM bibliotheque_livres bl
                                             LEFT JOIN bibliotheque_categories bc ON bl.categorie_id = bc.id
                                             WHERE bl.site_id = ? 
                                             ORDER BY bl.date_ajout DESC
                                             LIMIT 12",
                                            [$site_id]);
                                        ?>
                                        
                                        <?php if(empty($catalogue_livres)): ?>
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                Aucun livre dans le catalogue
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <?php foreach($catalogue_livres as $livre): 
                                            $cover_url = getCoverImageUrl($livre['couverture'] ?? '');
                                            $pdf_url = getPdfFileUrl($livre['fichier_pdf'] ?? '');
                                        ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card livre-card" data-livre-id="<?php echo $livre['id']; ?>"
                                                 onclick="showLivreDetails(<?php echo $livre['id']; ?>)">
                                                <div class="livre-cover">
                                                    <?php if(!empty($cover_url)): ?>
                                                    <img src="<?php echo $cover_url; ?>" 
                                                         alt="<?php echo safeHtml($livre['titre']); ?>"
                                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGRvbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM2Yzc1N2QiPkxpdnJlPC90ZXh0Pjwvc3ZnPg=='">
                                                    <?php else: ?>
                                                    <i class="fas fa-book"></i>
                                                    <?php endif; ?>
                                                    <div class="mini-menu">
                                                        <button class="mini-menu-btn" onclick="event.stopPropagation(); showMiniMenu(<?php echo $livre['id']; ?>, event)">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="livre-info">
                                                    <div class="livre-title"><?php echo safeHtml($livre['titre']); ?></div>
                                                    <div class="livre-auteur"><?php echo safeHtml($livre['auteur']); ?></div>
                                                    <div class="livre-meta">
                                                        <span class="badge bg-<?php echo ($livre['statut'] ?? '') == 'disponible' ? 'success' : (($livre['statut'] ?? '') == 'emprunte' ? 'warning' : 'danger'); ?>">
                                                            <?php echo ucfirst($livre['statut'] ?? ''); ?>
                                                        </span>
                                                        <div class="livre-actions">
                                                            <?php if(!empty($pdf_url)): ?>
                                                            <a href="<?php echo $pdf_url; ?>" 
                                                               target="_blank" 
                                                               class="btn-action btn btn-sm btn-outline-primary"
                                                               title="Consulter"
                                                               onclick="event.stopPropagation()">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if($frais_payes): ?>
                                                            <a href="?action=ajouter_favori&id=<?php echo $livre['id']; ?>" 
                                                               class="btn-action btn btn-sm btn-outline-danger" 
                                                               title="Ajouter aux favoris"
                                                               onclick="event.stopPropagation()">
                                                                <i class="fas fa-heart"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div id="vueList" class="d-none">
                                        <?php if(!empty($catalogue_livres)): ?>
                                        <div class="list-group">
                                            <?php foreach($catalogue_livres as $livre): 
                                                $cover_url = getCoverImageUrl($livre['couverture'] ?? '');
                                                $pdf_url = getPdfFileUrl($livre['fichier_pdf'] ?? '');
                                            ?>
                                            <div class="list-group-item">
                                                <div class="row align-items-center">
                                                    <div class="col-md-2">
                                                        <div class="livre-cover" style="height: 100px;">
                                                            <?php if(!empty($cover_url)): ?>
                                                            <img src="<?php echo $cover_url; ?>" 
                                                                 alt="<?php echo safeHtml($livre['titre']); ?>"
                                                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGRvbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM2Yzc1N2QiPkxpdnJlPC90ZXh0Pjwvc3ZnPg=='">
                                                            <?php else: ?>
                                                            <i class="fas fa-book"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-7">
                                                        <h6><?php echo safeHtml($livre['titre']); ?></h6>
                                                        <p class="mb-1">
                                                            <small>Auteur: <?php echo safeHtml($livre['auteur']); ?></small><br>
                                                            <small>Catégorie: <?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></small><br>
                                                            <small>ISBN: <?php echo safeHtml($livre['isbn'] ?? 'Non disponible'); ?></small>
                                                        </p>
                                                    </div>
                                                    <div class="col-md-3 text-end">
                                                        <?php echo getStatutBadge($livre['statut'] ?? ''); ?><br>
                                                        <div class="mt-2">
                                                            <?php if(!empty($pdf_url)): ?>
                                                            <a href="<?php echo $pdf_url; ?>" 
                                                               target="_blank" 
                                                               class="btn btn-sm btn-primary">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if($frais_payes): ?>
                                                            <a href="?action=ajouter_favori&id=<?php echo $livre['id']; ?>" 
                                                               class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-heart"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Pagination -->
                                    <nav aria-label="Pagination" class="mt-3">
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
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Mes Emprunts -->
                        <div class="tab-pane fade" id="emprunts">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-exchange-alt me-2"></i>Emprunts Actifs</h5>
                                    <?php if(empty($emprunts_actifs)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucun emprunt actif
                                    </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Livre</th>
                                                    <th>Date emprunt</th>
                                                    <th>Retour prévu</th>
                                                    <th>Jours restants</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($emprunts_actifs as $emprunt): 
                                                    $jours_restants = '';
                                                    $date_retour = new DateTime($emprunt['date_retour_prevue']);
                                                    $date_actuelle = new DateTime();
                                                    $difference = $date_actuelle->diff($date_retour);
                                                    $jours = $difference->days;
                                                    
                                                    if ($date_retour > $date_actuelle) {
                                                        $jours_restants = $jours . ' jours';
                                                    } else {
                                                        $jours_restants = '<span class="text-danger">Retard: ' . $jours . ' jours</span>';
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo safeHtml($emprunt['titre'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($emprunt['date_emprunt'] ?? ''); ?></td>
                                                    <td><?php echo formatDateFr($emprunt['date_retour_prevue'] ?? ''); ?></td>
                                                    <td><?php echo $jours_restants; ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-warning" 
                                                                onclick="demanderProlongation(<?php echo $emprunt['id']; ?>)" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                                            <i class="fas fa-clock"></i> Prolonger
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-heart me-2"></i>Mes Favoris</h5>
                                    <?php if(empty($livres_favoris)): ?>
                                    <div class="alert alert-info">
                                        Aucun livre dans vos favoris
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($livres_favoris as $livre): 
                                            $cover_url = getCoverImageUrl($livre['couverture'] ?? '');
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3" style="width: 60px;">
                                                    <div class="livre-cover" style="height: 60px;">
                                                        <?php if(!empty($cover_url)): ?>
                                                        <img src="<?php echo $cover_url; ?>" 
                                                             alt="<?php echo safeHtml($livre['titre']); ?>"
                                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGRvbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM2Yzc1N2QiPkxpdnJlPC90ZXh0Pjwvc3ZnPg=='">
                                                        <?php else: ?>
                                                        <i class="fas fa-book"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex w-100 justify-content-between">
                                                        <h6 class="mb-1"><?php echo safeHtml($livre['titre']); ?></h6>
                                                        <?php if($frais_payes): ?>
                                                        <a href="?action=retirer_favori&id=<?php echo $livre['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-heart-broken"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="mb-1">
                                                        <small><?php echo safeHtml($livre['auteur']); ?></small><br>
                                                        <small>Catégorie: <?php echo safeHtml($livre['categorie_nom'] ?? 'Non catégorisé'); ?></small>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-calendar me-2"></i>Calendrier des Retours</h5>
                                    <div id="calendar"></div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-chart-line me-2"></i>Mes Statistiques</h5>
                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="stat-value"><?php echo count($historique_emprunts); ?></div>
                                            <div class="stat-label">Emprunts totaux</div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="stat-value"><?php echo $retards; ?></div>
                                            <div class="stat-label">Retards</div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-value"><?php echo $stats['favoris_total']; ?></div>
                                            <div class="stat-label">Favoris</div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-value"><?php echo $stats['telechargements_total']; ?></div>
                                            <div class="stat-label">Consultations</div>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-question-circle me-2"></i>Règlement de la Bibliothèque</h5>
                                    <div class="alert alert-info">
                                        <h6>Conditions d'emprunt :</h6>
                                        <ul class="mb-0 small">
                                            <li>Durée maximum : 14 jours</li>
                                            <li>Renouvellement possible 1 fois</li>
                                            <li>Amende de retard : 500 FCFA/jour</li>
                                            <li>Maximum 3 livres simultanés</li>
                                            <li>Frais de bibliothèque : <?php echo $montant_frais; ?> FCFA/mois</li>
                                            <li>Cartes d'étudiant obligatoire</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Documents -->
                        <div class="tab-pane fade" id="documents">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-file-alt me-2"></i>Documents Rédactionnels</h5>
                                    <?php if(empty($documents_recents)): ?>
                                    <div class="alert alert-info">
                                        Aucun document rédactionnel disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($documents_recents as $doc): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($doc['titre']); ?></h6>
                                                <small><?php echo formatDateFr($doc['date_modification'] ?? ''); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>Auteur: <?php echo safeHtml($doc['auteur_nom']); ?></small><br>
                                                <small>Pages: <?php echo intval($doc['nombre_pages'] ?? 0); ?></small>
                                                <small class="ms-2">Version: <?php echo intval($doc['version'] ?? 1); ?></small>
                                            </p>
                                            <div class="mt-2">
                                                <?php if(!empty($doc['contenu_html'])): ?>
                                                <a href="bibliotheque_document.php?id=<?php echo $doc['id']; ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye"></i> Lire
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-download me-2"></i>Derniers Téléchargements</h5>
                                    <?php if($user_id): 
                                        $telechargements = executeQuery($db,
                                            "SELECT bt.*, bl.titre, bl.auteur
                                             FROM bibliotheque_telechargements bt
                                             JOIN bibliotheque_livres bl ON bt.livre_id = bl.id
                                             WHERE bt.utilisateur_id = ?
                                             ORDER BY bt.date_telechargement DESC
                                             LIMIT 5",
                                            [$user_id]);
                                    ?>
                                    <?php if(empty($telechargements)): ?>
                                    <div class="alert alert-info">
                                        Aucun téléchargement récent
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($telechargements as $dl): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($dl['titre']); ?></h6>
                                                <small><?php echo formatDateFr($dl['date_telechargement'] ?? '', 'd/m H:i'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small><?php echo safeHtml($dl['auteur']); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5><i class="fas fa-edit me-2"></i>Éditeur en Ligne</h5>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle"></i> Créez vos documents</h6>
                                        <p class="mb-2">Utilisez notre éditeur en ligne pour créer et sauvegarder vos documents académiques.</p>
                                        <a href="bibliotheque_editeur.php" class="btn btn-primary" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                            <i class="fas fa-plus"></i> Nouveau document
                                        </a>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-save me-2"></i>Mes Sauvegardes</h5>
                                    <?php if($user_id): 
                                        $sauvegardes = executeQuery($db,
                                            "SELECT bs.*, bd.titre
                                             FROM bibliotheque_sauvegardes bs
                                             JOIN bibliotheque_documents bd ON bs.document_id = bd.id
                                             WHERE bd.auteur_id = ?
                                             ORDER BY bs.date_sauvegarde DESC
                                             LIMIT 5",
                                            [$user_id]);
                                    ?>
                                    <?php if(empty($sauvegardes)): ?>
                                    <div class="alert alert-info">
                                        Aucune sauvegarde disponible
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($sauvegardes as $save): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($save['titre']); ?></h6>
                                                <small>V<?php echo intval($save['version'] ?? 1); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small><?php echo formatDateFr($save['date_sauvegarde'] ?? '', 'd/m H:i'); ?></small><br>
                                                <small>Taille: <?php echo formatFileSize($save['taille'] ?? 0); ?></small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <h5 class="mt-4"><i class="fas fa-comments me-2"></i>Mes Commentaires</h5>
                                    <?php if($user_id): 
                                        $commentaires = executeQuery($db,
                                            "SELECT bc.*, bl.titre
                                             FROM bibliotheque_commentaires bc
                                             JOIN bibliotheque_livres bl ON bc.livre_id = bl.id
                                             WHERE bc.utilisateur_id = ?
                                             AND bc.statut = 'actif'
                                             ORDER BY bc.date_commentaire DESC
                                             LIMIT 5",
                                            [$user_id]);
                                    ?>
                                    <?php if(empty($commentaires)): ?>
                                    <div class="alert alert-info">
                                        Aucun commentaire posté
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach($commentaires as $com): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo safeHtml($com['titre']); ?></h6>
                                                <small>
                                                    <?php for($i = 0; $i < intval($com['note'] ?? 5); $i++): ?>
                                                    <i class="fas fa-star text-warning"></i>
                                                    <?php endfor; ?>
                                                </small>
                                            </div>
                                            <p class="mb-1">
                                                <small><?php echo safeHtml(substr($com['commentaire'] ?? '', 0, 100)); ?>...</small>
                                            </p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
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
                                    <div class="quick-action" onclick="window.location.href='?section=catalogue'">
                                        <i class="fas fa-search"></i>
                                        <div class="title">Rechercher</div>
                                        <div class="description">Trouver un livre</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='?section=emprunts'">
                                        <i class="fas fa-exchange-alt"></i>
                                        <div class="title">Mes Emprunts</div>
                                        <div class="description">Vérifier mes prêts</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='?section=favoris'">
                                        <i class="fas fa-heart"></i>
                                        <div class="title">Favoris</div>
                                        <div class="description">Livres préférés</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='bibliotheque_editeur.php'" <?php if(!$frais_payes) echo 'style="opacity:0.5;cursor:not-allowed"'; ?>>
                                        <i class="fas fa-edit"></i>
                                        <div class="title">Éditeur</div>
                                        <div class="description">Créer document</div>
                                        <?php if(!$frais_payes): ?><div class="text-warning small mt-1">Frais requis</div><?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="window.location.href='?section=documents'">
                                        <i class="fas fa-file-alt"></i>
                                        <div class="title">Documents</div>
                                        <div class="description">Consulter</div>
                                    </div>
                                </div>
                                <div class="col-md-2 col-6 mb-3">
                                    <div class="quick-action" onclick="demanderAchat()" <?php if(!$frais_payes) echo 'style="opacity:0.5;cursor:not-allowed"'; ?>>
                                        <i class="fas fa-shopping-cart"></i>
                                        <div class="title">Demander Achat</div>
                                        <div class="description">Nouveau livre</div>
                                        <?php if(!$frais_payes): ?><div class="text-warning small mt-1">Frais requis</div><?php endif; ?>
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
                            <?php if($stats['emprunts_actifs'] > 0): ?>
                            
                            <?php if($retards > 0): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> 
                                <strong>Retards de retour:</strong> Vous avez <?php echo $retards; ?> livre(s) en retard.
                                <a href="?section=emprunts" class="alert-link">Voir le détail</a>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-clock"></i> 
                                <strong>Emprunts en cours:</strong> Vous avez <?php echo $stats['emprunts_actifs']; ?> livre(s) emprunté(s).
                                <a href="?section=emprunts" class="alert-link">Gérer mes emprunts</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!$frais_payes): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-credit-card"></i> 
                                <strong>Frais non payés:</strong> Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour accéder à toutes les fonctionnalités.
                                <a href="../paiements/payer_frais.php?type=bibliotheque" class="alert-link">Payer maintenant</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['favoris_total'] > 5): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-heart"></i> 
                                <strong>Favoris:</strong> Vous avez <?php echo $stats['favoris_total']; ?> livres en favoris.
                                <a href="?section=favoris" class="alert-link">Consulter</a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($stats['emprunts_actifs'] == 0 && $retards == 0 && $frais_payes): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> 
                                <strong>Tout est en ordre!</strong> Aucun emprunt en cours ni retard. Frais à jour.
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
                                <h6><i class="fas fa-clock"></i> Horaires d'ouverture</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Lundi - Vendredi:</strong> 8h00 - 20h00</li>
                                    <li><strong>Samedi:</strong> 9h00 - 13h00</li>
                                    <li><strong>Dimanche:</strong> Fermé</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-credit-card"></i> Frais de bibliothèque</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Montant:</strong> <?php echo $montant_frais; ?> FCFA/mois</li>
                                    <li><strong>Paiement:</strong> Fin du mois</li>
                                    <li><strong>Services inclus:</strong> Téléchargements, Favoris, Emprunts</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="fas fa-phone-alt"></i> Contact Bibliothèque</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Responsable:</strong> M. Jean Dupont</li>
                                    <li><strong>Téléphone:</strong> +242 XX XX XX XX</li>
                                    <li><strong>Email:</strong> bibliotheque@isgi.cg</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour l'aperçu des livres -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalTitle">Aperçu du Livre</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div id="previewCover" class="livre-cover" style="height: 300px;">
                                <!-- L'image sera insérée ici par JavaScript -->
                            </div>
                        </div>
                        <div class="col-md-8">
                            <h4 id="previewTitle"></h4>
                            <p><strong>Auteur:</strong> <span id="previewAuteur"></span></p>
                            <p id="previewDescription"></p>
                            <div class="mt-4">
                                <button class="btn btn-primary" onclick="viewFullDetails()">
                                    <i class="fas fa-external-link-alt"></i> Voir les détails complets
                                </button>
                                <button class="btn btn-outline-danger" onclick="addToFavoritesFromPreview()" <?php if(!$frais_payes) echo 'disabled'; ?>>
                                    <i class="fas fa-heart"></i> Ajouter aux favoris
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Menu flottant pour les livres -->
    <div class="floating-menu" id="floatingMenu">
        <div class="floating-menu-item" onclick="showQuickPreview()">
            <i class="fas fa-eye"></i>
            <span>Aperçu rapide</span>
        </div>
        <div class="floating-menu-item" onclick="viewBookDetails()">
            <i class="fas fa-info-circle"></i>
            <span>Détails complets</span>
        </div>
        <div class="floating-menu-item" onclick="addToFavoritesFromMenu()" id="favoriteMenuItem">
            <i class="fas fa-heart"></i>
            <span>Ajouter aux favoris</span>
        </div>
        <div class="floating-menu-item" onclick="downloadBookFromMenu()">
            <i class="fas fa-download"></i>
            <span>Télécharger</span>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    
    <script>
    // Variables globales
    let currentLivreId = null;
    let currentLivreData = null;
    
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
        
        // Initialiser les onglets Bootstrap
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            new bootstrap.Tab(tabEl);
        });
        
        // Initialiser FullCalendar pour les retours
        var calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: [
                    // Ajouter les dates de retour des emprunts
                    <?php foreach($emprunts_actifs as $emprunt): ?>
                    {
                        title: 'Retour: <?php echo addslashes($emprunt["titre"]); ?>',
                        start: '<?php echo $emprunt["date_retour_prevue"]; ?>',
                        color: '#e74c3c',
                        textColor: 'white'
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    alert('Retour prévu pour: ' + info.event.title);
                }
            });
            calendar.render();
        }
        
        // Initialiser Chart.js pour les statistiques
        const ctx = document.getElementById('categorieChart');
        if (ctx) {
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Informatique', 'Gestion', 'Droit', 'Langues', 'Sciences'],
                    datasets: [{
                        data: [30, 25, 20, 15, 10],
                        backgroundColor: [
                            '#3498db',
                            '#2ecc71',
                            '#e74c3c',
                            '#f39c12',
                            '#9b59b6'
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
                            text: 'Répartition par catégorie'
                        }
                    }
                }
            });
        }
        
        // Fermer le menu contextuel quand on clique ailleurs
        document.addEventListener('click', function() {
            hideContextMenu();
            hideFloatingMenu();
        });
    });
    
    // Fonctions pour le catalogue
    function changerVue(type) {
        const vueGrid = document.getElementById('vueGrid');
        const vueList = document.getElementById('vueList');
        const btnGrid = document.querySelector('button[onclick="changerVue(\'grid\')"]');
        const btnList = document.querySelector('button[onclick="changerVue(\'list\')"]');
        
        if (type === 'grid') {
            vueGrid.classList.remove('d-none');
            vueList.classList.add('d-none');
            btnGrid.classList.add('active');
            btnList.classList.remove('active');
        } else {
            vueGrid.classList.add('d-none');
            vueList.classList.remove('d-none');
            btnGrid.classList.remove('active');
            btnList.classList.add('active');
        }
    }
    
    function appliquerFiltres() {
        const categorie = document.getElementById('filterCategorie').value;
        const langue = document.getElementById('filterLangue').value;
        const statut = document.getElementById('filterStatut').value;
        const tri = document.getElementById('filterTri').value;
        
        // Ici, vous devriez implémenter l'actualisation de la liste des livres
        // en fonction des filtres via AJAX
        alert('Filtres appliqués:\nCatégorie: ' + categorie + 
              '\nLangue: ' + langue + 
              '\nStatut: ' + statut + 
              '\nTri: ' + tri);
    }
    
    // Fonctions pour le menu contextuel
    function showContextMenu(livreId, event) {
        event.preventDefault();
        currentLivreId = livreId;
        
        const contextMenu = document.getElementById('contextMenu');
        contextMenu.style.display = 'block';
        contextMenu.style.left = event.pageX + 'px';
        contextMenu.style.top = event.pageY + 'px';
        
        // Récupérer les données du livre
        const livreCard = event.target.closest('.livre-card');
        if (livreCard) {
            currentLivreData = {
                id: livreCard.dataset.livreId,
                titre: livreCard.dataset.livreTitre,
                auteur: livreCard.dataset.livreAuteur,
                description: livreCard.dataset.livreDescription,
                cover: livreCard.dataset.livreCover
            };
        }
    }
    
    function hideContextMenu() {
        document.getElementById('contextMenu').style.display = 'none';
    }
    
    // Fonctions pour le menu flottant
    function showMiniMenu(livreId, event) {
        event.stopPropagation();
        currentLivreId = livreId;
        
        const livreCard = event.target.closest('.livre-card');
        if (livreCard) {
            currentLivreData = {
                id: livreCard.dataset.livreId,
                titre: livreCard.dataset.livreTitre,
                auteur: livreCard.dataset.livreAuteur,
                description: livreCard.dataset.livreDescription,
                cover: livreCard.dataset.livreCover
            };
        }
        
        const floatingMenu = document.getElementById('floatingMenu');
        floatingMenu.classList.add('show');
        floatingMenu.style.left = event.pageX + 'px';
        floatingMenu.style.top = event.pageY + 'px';
    }
    
    function hideFloatingMenu() {
        document.getElementById('floatingMenu').classList.remove('show');
    }
    
    // Fonctions pour les actions du menu
    function showQuickPreview() {
        if (!currentLivreData) return;
        
        const modal = new bootstrap.Modal(document.getElementById('previewModal'));
        document.getElementById('previewModalTitle').textContent = currentLivreData.titre;
        document.getElementById('previewTitle').textContent = currentLivreData.titre;
        document.getElementById('previewAuteur').textContent = currentLivreData.auteur;
        document.getElementById('previewDescription').textContent = currentLivreData.description || 'Aucune description disponible.';
        
        const coverContainer = document.getElementById('previewCover');
        if (currentLivreData.cover) {
            coverContainer.innerHTML = `<img src="${currentLivreData.cover}" alt="${currentLivreData.titre}" 
                onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGRvbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM2Yzc1N2QiPkxpdnJlPC90ZXh0Pjwvc3ZnPg=='">`;
        } else {
            coverContainer.innerHTML = '<i class="fas fa-book"></i>';
        }
        
        modal.show();
        hideFloatingMenu();
    }
    
    function viewBookDetails() {
        if (!currentLivreId) return;
        window.location.href = '?action=preview&id=' + currentLivreId;
    }
    
    function addToFavoritesFromMenu() {
        <?php if(!$frais_payes): ?>
        alert('Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour ajouter des livres aux favoris.');
        return;
        <?php endif; ?>
        
        if (!currentLivreId) return;
        window.location.href = '?action=ajouter_favori&id=' + currentLivreId;
    }
    
    function downloadBookFromMenu() {
        <?php if(!$frais_payes): ?>
        alert('Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour télécharger des livres.');
        return;
        <?php endif; ?>
        
        if (!currentLivreId) return;
        window.location.href = '?action=telecharger&id=' + currentLivreId;
    }
    
    // Fonctions pour les détails des livres
    function showLivreDetails(livreId) {
        window.location.href = '?action=preview&id=' + livreId;
    }
    
    function viewFullDetails() {
        if (!currentLivreId) return;
        window.location.href = '?action=preview&id=' + currentLivreId;
    }
    
    function addToFavoritesFromPreview() {
        <?php if(!$frais_payes): ?>
        alert('Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour ajouter des livres aux favoris.');
        return;
        <?php endif; ?>
        
        if (!currentLivreId) return;
        window.location.href = '?action=ajouter_favori&id=' + currentLivreId;
    }
    
    function demanderProlongation(empruntId) {
        <?php if(!$frais_payes): ?>
        alert('Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour demander une prolongation.');
        return;
        <?php endif; ?>
        
        if (confirm('Voulez-vous demander une prolongation de 7 jours pour cet emprunt ?')) {
            // Ici, vous devriez faire un appel AJAX pour demander la prolongation
            alert('Demande de prolongation envoyée !');
        }
    }
    
    function demanderAchat() {
        <?php if(!$frais_payes): ?>
        alert('Vous devez payer vos frais de bibliothèque (<?php echo $montant_frais; ?> FCFA) pour demander l\'achat d\'un livre.');
        return;
        <?php endif; ?>
        
        const titre = prompt('Titre du livre que vous souhaitez :');
        if (titre) {
            const auteur = prompt('Auteur du livre :');
            if (auteur) {
                // Ici, vous devriez envoyer la demande d'achat via AJAX
                alert('Demande d\'achat envoyée pour :\n' + titre + '\n' + auteur);
            }
        }
    }
    </script>
</body>
</html>