<?php
// dashboard/etudiant/carte_etudiante.php

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
    $pageTitle = "Carte Étudiante";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
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
    
    // Initialiser les variables
    $info_etudiant = array();
    $info_qr_code = array();
    $historique_cartes = array();
    $error = null;
    
    // Fonction pour exécuter les requêtes en toute sécurité
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
    
    // Récupérer les informations de l'étudiant pour la carte
    $info_etudiant = executeSingleQuery($db, 
        "SELECT e.*, s.nom as site_nom, s.ville as site_ville, s.adresse as site_adresse,
                s.telephone as site_telephone, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle,
                aa.libelle as annee_academique
         FROM etudiants e
         JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON c.filiere_id = f.id
         LEFT JOIN niveaux n ON c.niveau_id = n.id
         LEFT JOIN annees_academiques aa ON c.annee_academique_id = aa.id
         WHERE e.utilisateur_id = ?", 
        [$user_id]);
    
    // Générer ou récupérer le QR Code
    if ($info_etudiant && !empty($info_etudiant['id'])) {
        $etudiant_id = intval($info_etudiant['id']);
        
        // Vérifier si un QR Code existe déjà dans la base
        $info_qr_code = executeSingleQuery($db,
            "SELECT qr_code_data FROM etudiants WHERE id = ?",
            [$etudiant_id]);
            
        // Si pas de QR Code, en générer un nouveau
        if (empty($info_qr_code['qr_code_data']) || $info_qr_code['qr_code_data'] === null) {
            $qr_data = "ETUDIANT:" . ($info_etudiant['matricule'] ?? '') . "|" .
                      "NOM:" . ($info_etudiant['nom'] ?? '') . "|" .
                      "PRENOM:" . ($info_etudiant['prenom'] ?? '') . "|" .
                      "SITE:" . ($info_etudiant['site_id'] ?? '') . "|" .
                      "TYPE:etudiant|" .
                      "DATE:" . date('YmdHis');
            
            // Sauvegarder dans la base
            $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
            $stmt->execute([$qr_data, $etudiant_id]);
            
            $info_qr_code['qr_code_data'] = $qr_data;
        }
        
        // Récupérer l'historique des cartes (si table existe)
        try {
            $historique_cartes = executeQuery($db,
                "SELECT date_creation, statut, raison_annulation 
                 FROM historique_cartes_etudiants 
                 WHERE etudiant_id = ? 
                 ORDER BY date_creation DESC 
                 LIMIT 5",
                [$etudiant_id]);
        } catch (Exception $e) {
            // La table n'existe pas, on continue sans historique
            $historique_cartes = array();
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
    <title><?php echo safeHtml($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- QR Code library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    
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
    
    /* Navigation (identique) */
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
    
    /* Carte étudiante principale */
    .carte-etudiante {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 20px;
        padding: 30px;
        position: relative;
        overflow: hidden;
        min-height: 400px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        margin-bottom: 30px;
    }
    
    .carte-etudiante::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255, 255, 255, 0.05);
        transform: rotate(30deg);
    }
    
    .carte-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 30px;
        position: relative;
        z-index: 1;
    }
    
    .carte-logo {
        text-align: center;
    }
    
    .carte-logo-icon {
        font-size: 3rem;
        margin-bottom: 10px;
    }
    
    .carte-logo-text {
        font-size: 1.5rem;
        font-weight: bold;
        letter-spacing: 2px;
    }
    
    .carte-statut {
        background: rgba(255, 255, 255, 0.2);
        padding: 8px 15px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .carte-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
        z-index: 1;
    }
    
    .carte-info {
        flex: 1;
    }
    
    .carte-photo {
        width: 180px;
        height: 220px;
        border-radius: 10px;
        border: 3px solid white;
        object-fit: cover;
        margin-left: 30px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    .carte-photo-placeholder {
        width: 180px;
        height: 220px;
        border-radius: 10px;
        border: 3px solid white;
        margin-left: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.1);
    }
    
    .carte-nom {
        font-size: 2.2rem;
        font-weight: bold;
        margin-bottom: 5px;
        letter-spacing: 1px;
    }
    
    .carte-matricule {
        font-size: 1.3rem;
        margin-bottom: 20px;
        opacity: 0.9;
    }
    
    .carte-details {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-top: 25px;
    }
    
    .carte-detail-item {
        background: rgba(255, 255, 255, 0.1);
        padding: 12px 15px;
        border-radius: 10px;
    }
    
    .carte-detail-label {
        font-size: 0.85rem;
        opacity: 0.8;
        margin-bottom: 5px;
    }
    
    .carte-detail-value {
        font-size: 1.1rem;
        font-weight: 500;
    }
    
    .carte-qr {
        position: absolute;
        bottom: 20px;
        right: 20px;
        background: white;
        padding: 8px;
        border-radius: 8px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.2);
    }
    
    #qrcode {
        width: 100px;
        height: 100px;
    }
    
    .carte-footer {
        position: absolute;
        bottom: 20px;
        left: 30px;
        font-size: 0.85rem;
        opacity: 0.8;
    }
    
    /* Section téléchargement */
    .telechargement-section {
        background: var(--card-bg);
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        margin-bottom: 30px;
        border: 1px solid var(--border-color);
    }
    
    .telechargement-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .telechargement-option {
        text-align: center;
        padding: 20px;
        border-radius: 10px;
        background: rgba(52, 152, 219, 0.1);
        border: 2px solid transparent;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .telechargement-option:hover {
        border-color: var(--secondary-color);
        transform: translateY(-5px);
    }
    
    .telechargement-option i {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 15px;
    }
    
    .telechargement-option .title {
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .telechargement-option .description {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    /* Historique */
    .historique-section {
        background: var(--card-bg);
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        border: 1px solid var(--border-color);
    }
    
    .historique-list {
        margin-top: 20px;
    }
    
    .historique-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .historique-item:last-child {
        border-bottom: none;
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 5px 10px;
        border-radius: 20px;
    }
    
    .badge-valide {
        background: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .badge-invalide {
        background: rgba(231, 76, 60, 0.2);
        color: var(--accent-color);
    }
    
    .badge-attente {
        background: rgba(243, 156, 18, 0.2);
        color: var(--warning-color);
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
        
        .carte-etudiante {
            padding: 20px;
        }
        
        .carte-content {
            flex-direction: column;
            text-align: center;
        }
        
        .carte-photo, .carte-photo-placeholder {
            margin: 20px auto 0;
            order: -1;
        }
        
        .carte-details {
            grid-template-columns: 1fr;
        }
        
        .telechargement-options {
            grid-template-columns: 1fr;
        }
    }
    
    @media print {
        .sidebar, .no-print {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .carte-etudiante {
            box-shadow: none !important;
            border: 2px solid #000 !important;
        }
        
        .telechargement-section, .historique-section {
            display: none !important;
        }
    }
    
    /* Informations importantes */
    .info-card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        border-left: 4px solid var(--info-color);
        margin-bottom: 20px;
    }
    
    .warning-card {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 20px;
        border-left: 4px solid var(--warning-color);
        margin-bottom: 20px;
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
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar (identique au dashboard) -->
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
                    <a href="informations.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations</span>
                    </a>
                    <a href="carte_etudiante.php" class="nav-link active">
                        <i class="fas fa-id-card"></i>
                        <span>Carte Étudiante</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Services</div>
                    <a href="finances.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Finances</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Notes</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Présences</span>
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
                            <i class="fas fa-id-card me-2"></i>
                            Carte Étudiante
                        </h2>
                        <p class="text-muted mb-0">
                            Votre carte d'étudiant officielle ISGI
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="imprimerCarte()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <button class="btn btn-success" onclick="telechargerCarte()">
                            <i class="fas fa-download"></i> Télécharger
                        </button>
                        <button class="btn btn-info" onclick="actualiserQRCode()">
                            <i class="fas fa-sync-alt"></i> Actualiser QR
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Section d'information importante -->
            <div class="warning-card">
                <h5><i class="fas fa-exclamation-triangle me-2"></i> Information importante</h5>
                <p class="mb-0">Cette carte est votre pièce d'identité officielle au sein de l'ISGI. Elle doit être présentée à chaque entrée et sortie de l'établissement, ainsi que pour tous les examens et services administratifs.</p>
            </div>
            
            <!-- Carte étudiante principale -->
            <div class="carte-etudiante" id="cartePrincipale">
                <div class="carte-header">
                    <div class="carte-logo">
                        <div class="carte-logo-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="carte-logo-text">ISGI</div>
                    </div>
                    <div class="carte-statut">
                        <i class="fas fa-check-circle me-1"></i> VALIDE
                    </div>
                </div>
                
                <div class="carte-content">
                    <div class="carte-info">
                        <div class="carte-nom">
                            <?php echo strtoupper(safeHtml($info_etudiant['nom'] ?? '')); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?>
                        </div>
                        <div class="carte-matricule">
                            <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>
                        </div>
                        
                        <div class="carte-details">
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Filière</div>
                                <div class="carte-detail-value"><?php echo safeHtml($info_etudiant['filiere_nom'] ?? 'Non spécifiée'); ?></div>
                            </div>
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Niveau</div>
                                <div class="carte-detail-value"><?php echo safeHtml($info_etudiant['niveau_libelle'] ?? 'Non spécifié'); ?></div>
                            </div>
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Année Académique</div>
                                <div class="carte-detail-value"><?php echo safeHtml($info_etudiant['annee_academique'] ?? date('Y') . '-' . (date('Y') + 1)); ?></div>
                            </div>
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Date de Naissance</div>
                                <div class="carte-detail-value"><?php echo formatDateFr($info_etudiant['date_naissance'] ?? ''); ?></div>
                            </div>
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Site</div>
                                <div class="carte-detail-value"><?php echo safeHtml($info_etudiant['site_nom'] ?? ''); ?></div>
                            </div>
                            <div class="carte-detail-item">
                                <div class="carte-detail-label">Date d'expiration</div>
                                <div class="carte-detail-value"><?php echo date('m/Y', strtotime('+1 year')); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if(isset($info_etudiant['photo_identite']) && !empty($info_etudiant['photo_identite'])): ?>
                    <img src="<?php echo safeHtml($info_etudiant['photo_identite']); ?>" 
                         alt="Photo étudiant" class="carte-photo">
                    <?php else: ?>
                    <div class="carte-photo-placeholder">
                        <i class="fas fa-user fa-5x text-white"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="carte-qr" id="qrcodeContainer">
                    <div id="qrcode"></div>
                </div>
                
                <div class="carte-footer">
                    Carte délivrée le: <?php echo date('d/m/Y'); ?>
                </div>
            </div>
            
            <!-- Section téléchargement -->
            <div class="telechargement-section">
                <h4 class="mb-4">
                    <i class="fas fa-download me-2"></i>
                    Télécharger votre carte
                </h4>
                <p class="text-muted mb-4">Téléchargez votre carte étudiante dans différents formats pour l'utiliser selon vos besoins.</p>
                
                <div class="telechargement-options">
                    <div class="telechargement-option" onclick="telechargerPDF()">
                        <i class="fas fa-file-pdf"></i>
                        <div class="title">Format PDF</div>
                        <div class="description">Haute qualité pour impression</div>
                    </div>
                    
                    <div class="telechargement-option" onclick="telechargerImage()">
                        <i class="fas fa-image"></i>
                        <div class="title">Format Image</div>
                        <div class="description">PNG haute résolution</div>
                    </div>
                    
                    <div class="telechargement-option" onclick="telechargerQR()">
                        <i class="fas fa-qrcode"></i>
                        <div class="title">QR Code seul</div>
                        <div class="description">QR Code individuel</div>
                    </div>
                    
                    <div class="telechargement-option" onclick="partagerCarte()">
                        <i class="fas fa-share-alt"></i>
                        <div class="title">Partager</div>
                        <div class="description">Partager par email/WhatsApp</div>
                    </div>
                </div>
                
                <div class="info-card mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i> Conseils d'impression</h6>
                    <p class="mb-0 small">Pour une impression optimale, utilisez du papier cartonné de qualité et imprimez en haute résolution. Laminez votre carte pour plus de durabilité.</p>
                </div>
            </div>
            
            <!-- Section informations de contact -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="info-card">
                        <h5><i class="fas fa-university me-2"></i> Informations de l'établissement</h5>
                        <div class="mt-3">
                            <p class="mb-2">
                                <i class="fas fa-map-marker-alt me-2"></i>
                                <strong>Adresse:</strong> <?php echo safeHtml($info_etudiant['site_adresse'] ?? 'Non spécifiée'); ?>
                            </p>
                            <p class="mb-2">
                                <i class="fas fa-city me-2"></i>
                                <strong>Ville:</strong> <?php echo safeHtml($info_etudiant['site_ville'] ?? 'Non spécifiée'); ?>
                            </p>
                            <p class="mb-0">
                                <i class="fas fa-phone me-2"></i>
                                <strong>Téléphone:</strong> <?php echo safeHtml($info_etudiant['site_telephone'] ?? 'Non spécifié'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="info-card">
                        <h5><i class="fas fa-shield-alt me-2"></i> Sécurité et validité</h5>
                        <div class="mt-3">
                            <p class="mb-2">
                                <i class="fas fa-calendar-check me-2"></i>
                                <strong>Date d'émission:</strong> <?php echo date('d/m/Y'); ?>
                            </p>
                            <p class="mb-2">
                                <i class="fas fa-calendar-times me-2"></i>
                                <strong>Date d'expiration:</strong> <?php echo date('d/m/Y', strtotime('+1 year')); ?>
                            </p>
                            <p class="mb-0">
                                <i class="fas fa-qrcode me-2"></i>
                                <strong>QR Code unique:</strong> <?php echo substr(md5($info_etudiant['matricule'] ?? '' . time()), 0, 8); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section historique (si disponible) -->
            <?php if(!empty($historique_cartes)): ?>
            <div class="historique-section mt-4">
                <h4 class="mb-4">
                    <i class="fas fa-history me-2"></i>
                    Historique des cartes
                </h4>
                <div class="historique-list">
                    <?php foreach($historique_cartes as $historique): ?>
                    <div class="historique-item">
                        <div>
                            <h6 class="mb-1">Carte délivrée le <?php echo formatDateFr($historique['date_creation']); ?></h6>
                            <?php if(!empty($historique['raison_annulation'])): ?>
                            <p class="mb-0 small text-muted">Raison: <?php echo safeHtml($historique['raison_annulation']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if(($historique['statut'] ?? '') == 'valide'): ?>
                            <span class="badge badge-valide">Valide</span>
                            <?php elseif(($historique['statut'] ?? '') == 'invalide'): ?>
                            <span class="badge badge-invalide">Invalide</span>
                            <?php else: ?>
                            <span class="badge badge-attente">En attente</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Section demande de renouvellement -->
            <div class="warning-card mt-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1"><i class="fas fa-redo-alt me-2"></i> Demande de renouvellement</h5>
                        <p class="mb-0">Votre carte expire dans moins de 30 jours. Demandez son renouvellement dès maintenant.</p>
                    </div>
                    <button class="btn btn-warning" onclick="demanderRenouvellement()">
                        <i class="fas fa-paper-plane me-2"></i> Demander le renouvellement
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- html2canvas pour capture d'écran -->
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    
    <!-- jsPDF pour génération PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <script>
    // Générer le QR Code
    document.addEventListener('DOMContentLoaded', function() {
        // Données pour le QR Code
        const qrData = `<?php echo safeHtml($info_qr_code['qr_code_data'] ?? 'ETUDIANT:TEST'); ?>`;
        
        // Générer le QR Code
        new QRCode(document.getElementById("qrcode"), {
            text: qrData,
            width: 100,
            height: 100,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
        
        // Initialiser le thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
    });
    
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
    
    // Fonction d'impression
    function imprimerCarte() {
        // Sauvegarder le thème original
        const originalTheme = document.documentElement.getAttribute('data-theme');
        
        // Forcer le mode clair pour l'impression
        document.documentElement.setAttribute('data-theme', 'light');
        
        // Attendre un peu pour que le DOM se mette à jour
        setTimeout(() => {
            window.print();
            
            // Restaurer le thème original
            setTimeout(() => {
                document.documentElement.setAttribute('data-theme', originalTheme);
            }, 500);
        }, 100);
    }
    
    // Fonction pour télécharger la carte en PDF
    function telechargerPDF() {
        const { jsPDF } = window.jspdf;
        
        html2canvas(document.getElementById('cartePrincipale'), {
            scale: 2,
            backgroundColor: '#3498db',
            useCORS: true
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: [85.6, 54] // Format carte de crédit
            });
            
            const imgWidth = 85.6;
            const imgHeight = 54;
            
            pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
            pdf.save('carte-etudiante-isgi.pdf');
            
            showNotification('PDF téléchargé avec succès', 'success');
        });
    }
    
    // Fonction pour télécharger la carte en image
    function telechargerImage() {
        html2canvas(document.getElementById('cartePrincipale'), {
            scale: 2,
            backgroundColor: '#3498db',
            useCORS: true
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'carte-etudiante-isgi.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            showNotification('Image téléchargée avec succès', 'success');
        });
    }
    
    // Fonction pour télécharger le QR Code seul
    function telechargerQR() {
        const qrCanvas = document.querySelector('#qrcode canvas');
        if (qrCanvas) {
            const link = document.createElement('a');
            link.download = 'qr-code-etudiant.png';
            link.href = qrCanvas.toDataURL('image/png');
            link.click();
            
            showNotification('QR Code téléchargé', 'success');
        }
    }
    
    // Fonction pour partager la carte
    function partagerCarte() {
        if (navigator.share) {
            html2canvas(document.getElementById('cartePrincipale'), {
                scale: 2,
                backgroundColor: '#3498db',
                useCORS: true
            }).then(canvas => {
                canvas.toBlob(blob => {
                    const file = new File([blob], 'carte-etudiante.png', { type: 'image/png' });
                    
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        navigator.share({
                            files: [file],
                            title: 'Ma carte étudiante ISGI',
                            text: 'Voici ma carte étudiante ISGI'
                        });
                    }
                });
            });
        } else {
            // Fallback pour les navigateurs qui ne supportent pas l'API Share
            alert('Pour partager votre carte, téléchargez-la d\'abord puis partagez le fichier.');
        }
    }
    
    // Fonction pour actualiser le QR Code
    function actualiserQRCode() {
        if (confirm('Voulez-vous générer un nouveau QR Code ? L\'ancien deviendra invalide.')) {
            // Simuler une requête AJAX pour générer un nouveau QR Code
            fetch('actualiser_qrcode.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    etudiant_id: <?php echo $etudiant_id ?? 0; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Vider le conteneur QR
                    document.getElementById('qrcode').innerHTML = '';
                    
                    // Générer le nouveau QR Code
                    new QRCode(document.getElementById("qrcode"), {
                        text: data.qr_data,
                        width: 100,
                        height: 100,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    showNotification('QR Code actualisé avec succès', 'success');
                } else {
                    showNotification('Erreur lors de l\'actualisation', 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur réseau', 'error');
            });
        }
    }
    
    // Fonction pour demander un renouvellement
    function demanderRenouvellement() {
        if (confirm('Souhaitez-vous demander le renouvellement de votre carte étudiante ?')) {
            // Simuler une requête AJAX
            fetch('demande_renouvellement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    etudiant_id: <?php echo $etudiant_id ?? 0; ?>,
                    raison: 'Expiration prochaine'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Demande de renouvellement envoyée', 'success');
                } else {
                    showNotification('Erreur lors de la demande', 'error');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showNotification('Erreur réseau', 'error');
            });
        }
    }
    
    // Fonction pour afficher des notifications
    function showNotification(message, type) {
        // Créer la notification
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} position-fixed`;
        notification.style.cssText = `
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        `;
        
        // Icône selon le type
        const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
        
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${icon} me-2"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Supprimer automatiquement après 5 secondes
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }
    
    // Gestion de l'expiration de la carte
    function verifierExpiration() {
        const dateExpiration = new Date('<?php echo date('Y-m-d', strtotime('+1 year')); ?>');
        const aujourdhui = new Date();
        const diffJours = Math.ceil((dateExpiration - aujourdhui) / (1000 * 60 * 60 * 24));
        
        if (diffJours <= 30 && diffJours > 0) {
            showNotification(`Votre carte expire dans ${diffJours} jours`, 'warning');
        } else if (diffJours <= 0) {
            showNotification('Votre carte est expirée', 'error');
        }
    }
    
    // Vérifier l'expiration au chargement
    document.addEventListener('DOMContentLoaded', verifierExpiration);
    </script>
</body>
</html>