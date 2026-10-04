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
    
    // Inclure la bibliothèque QR Code
    error_reporting(E_ALL & ~E_DEPRECATED);
    require_once ROOT_PATH . '/libs/phpqrcode/qrlib.php';
    error_reporting(E_ALL);
    
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
    $qr_code_path = '';
    $qr_code_data = '';
    $error = null;
    
    // Récupérer les informations de l'étudiant
    $stmt = $db->prepare(
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
         WHERE e.utilisateur_id = ?"
    );
    $stmt->execute([$user_id]);
    $info_etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$info_etudiant || empty($info_etudiant['id'])) {
        $error = "Aucune information d'étudiant trouvée.";
    } else {
        $etudiant_id = intval($info_etudiant['id']);
        
        // Vérifier si le QR code existe déjà dans la base
        $stmt_qr = $db->prepare("SELECT qr_code_data FROM etudiants WHERE id = ?");
        $stmt_qr->execute([$etudiant_id]);
        $qr_info = $stmt_qr->fetch(PDO::FETCH_ASSOC);
        
        // Chemin du dossier des QR codes
        $qr_dir = ROOT_PATH . '/uploads/qrcodes/etudiants/';
        
        // Créer le dossier s'il n'existe pas
        if (!file_exists($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        
        // Nom du fichier QR code
        $qr_filename = 'etudiant_' . $etudiant_id . '_' . $info_etudiant['matricule'] . '.png';
        $qr_filepath = $qr_dir . $qr_filename;
        
        // CORRECTION IMPORTANTE : Même logique que votre fichier bulletins
        $qr_relative_path = '/uploads/qrcodes/etudiants/' . $qr_filename;
        $qr_web_path = $qr_relative_path; // Par défaut
        
        // Corriger le chemin pour WAMP avec sous-dossier
        if (strpos($qr_relative_path, '/isgi_system/') !== false) {
            // Le chemin contient déjà /isgi_system/
            $qr_final_path = $qr_relative_path;
        } else {
            // Ajouter /isgi_system/ au début si nécessaire
            $qr_final_path = '/isgi_system' . $qr_relative_path;
        }
        
        // Vérifier si le fichier physique existe
        if (!file_exists($qr_filepath)) {
            // Données pour le QR code (format identique à celui du surveillant)
            $qr_data = "ETUDIANT:" . $info_etudiant['matricule'] . "|" .
                      "NOM:" . $info_etudiant['nom'] . "|" .
                      "PRENOM:" . $info_etudiant['prenom'] . "|" .
                      "SITE:" . $info_etudiant['site_id'] . "|" .
                      "TYPE:etudiant|" .
                      "DATE:" . date('YmdHis') . "|" .
                      "HASH:" . md5($info_etudiant['matricule'] . date('Ymd'));
            
            // Générer le QR code
            QRcode::png($qr_data, $qr_filepath, QR_ECLEVEL_H, 10, 2);
            
            // Mettre à jour la base de données avec le chemin relatif
            $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
            $stmt->execute([$qr_relative_path, $etudiant_id]);
            
            $qr_code_data = $qr_data;
        } else {
            // Lire les données du QR code existant si disponible
            if ($qr_info && !empty($qr_info['qr_code_data'])) {
                $qr_code_data = $qr_info['qr_code_data'];
            } else {
                $qr_code_data = "ETUDIANT:" . $info_etudiant['matricule'] . "|" .
                              "NOM:" . $info_etudiant['nom'] . "|" .
                              "PRENOM:" . $info_etudiant['prenom'] . "|" .
                              "DATE:" . date('YmdHis', filemtime($qr_filepath));
            }
        }
        
        // Vérifier l'existence du fichier physique
        $physical_path = ROOT_PATH . $qr_relative_path;
        if (!file_exists($physical_path)) {
            $physical_path = str_replace('\\', '/', ROOT_PATH) . $qr_relative_path;
            if (!file_exists($physical_path)) {
                // Essayer sans le sous-dossier isgi_system
                $alt_path = str_replace('/isgi_system', '', $qr_final_path);
                $physical_path = ROOT_PATH . $alt_path;
            }
        }
        
        // Définir le chemin final pour l'affichage
        if (file_exists($physical_path)) {
            $qr_code_path = $qr_final_path;
        } else {
            // Si le fichier n'existe toujours pas, on va le régénérer
            $qr_data = "ETUDIANT:" . $info_etudiant['matricule'] . "|" .
                      "NOM:" . $info_etudiant['nom'] . "|" .
                      "PRENOM:" . $info_etudiant['prenom'] . "|" .
                      "DATE:" . date('YmdHis');
            
            QRcode::png($qr_data, $qr_filepath, QR_ECLEVEL_H, 10, 2);
            $qr_code_path = $qr_final_path;
            $qr_code_data = $qr_data;
        }
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . safeHtml($e->getMessage());
}

// Définir le chemin du logo (relatif depuis le dossier actuel)
$logo_path = '../../image/logo isgi.jpg';
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
        --gold-color: #d4af37;
        --navy-color: #001f3f;
        --light-gold: #f0e6d2;
        --dark-navy: #001529;
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
        --gold-color: #d4af37;
        --navy-color: #001a35;
        --light-gold: #2a2400;
        --dark-navy: #000814;
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
    
    /* Contenu principal */
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
    }
    
    /* =========================================== */
    /* NOUVEAU DESIGN DE LA CARTE ÉTUDIANTE EN PAYSAGE */
    /* =========================================== */
    
    .carte-etudiante-container {
        max-width: 900px;
        margin: 0 auto 30px;
    }
    
    .carte-etudiante {
        background: linear-gradient(135deg, var(--dark-navy), var(--navy-color));
        color: white;
        border-radius: 20px;
        padding: 30px;
        position: relative;
        overflow: hidden;
        width: 100%;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        margin-bottom: 20px;
        border: 15px solid white;
        min-height: 350px;
        display: flex;
        flex-direction: column;
    }
    
    /* Mode paysage pour l'impression */
    @media print {
        .carte-etudiante {
            width: 85.6mm;
            height: 54mm;
            min-height: 54mm;
            padding: 8px;
            border: 2px solid #000;
            border-radius: 10px;
            transform: rotate(0deg);
            margin: 0;
            box-shadow: none;
        }
        
        .carte-etudiante-paysage {
            flex-direction: row !important;
        }
        
        .carte-header {
            margin-bottom: 10px !important;
        }
        
        .carte-content {
            gap: 10px !important;
        }
        
        .carte-photo-frame {
            width: 45mm !important;
            height: 60mm !important;
        }
        
        .qr-code-img {
            width: 70px !important;
            height: 70px !important;
        }
        
        .carte-nom {
            font-size: 0.9rem !important;
        }
        
        .detail-value {
            font-size: 0.8rem !important;
        }
    }
    
    /* Bords décoratifs dorés */
    .carte-etudiante::before {
        content: '';
        position: absolute;
        top: 5px;
        left: 5px;
        right: 5px;
        bottom: 5px;
        border: 2px solid var(--gold-color);
        border-radius: 15px;
        pointer-events: none;
        z-index: 1;
    }
    
    .carte-etudiante::after {
        content: '';
        position: absolute;
        top: 10px;
        left: 10px;
        right: 10px;
        bottom: 10px;
        border: 1px solid rgba(11, 25, 71, 0.3);
        border-radius: 12px;
        pointer-events: none;
        z-index: 1;
    }
    
    /* Logo ISGI intégré */
    .logo-isgi-container {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 2;
    }
    
    .logo-isgi {
        width: 80px;
        height: 80px;
        border-radius: 10px;
        overflow: hidden;
        border: 2px solid var(--gold-color);
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
    }
    
    .logo-isgi img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    /* En-tête de la carte */
    .carte-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 2;
        padding-right: 100px;
    }
    
    .carte-titre {
        flex: 1;
    }
    
    .carte-titre h1 {
        font-size: 1.8rem;
        font-weight: 800;
        letter-spacing: 2px;
        color: var(--gold-color);
        text-transform: uppercase;
        margin: 0;
        text-align: left;
    }
    
    .carte-titre .sous-titre {
        font-size: 0.9rem;
        color: var(--light-gold);
        text-align: left;
        letter-spacing: 1px;
    }
    
    .carte-statut {
        background: rgba(212, 175, 55, 0.2);
        padding: 8px 15px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 2px solid var(--gold-color);
        color: var(--gold-color);
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    /* Contenu principal de la carte en mode paysage */
    .carte-content {
        display: flex;
        flex-direction: row;
        gap: 30px;
        position: relative;
        z-index: 2;
        flex: 1;
    }
    
    /* Section gauche - Photo et informations principales */
    .carte-section-gauche {
        flex: 0 0 300px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    /* Section photo */
    .carte-photo-container {
        flex-shrink: 0;
        position: relative;
    }
    
    .carte-photo-frame {
        width: 180px;
        height: 220px;
        border-radius: 12px;
        border: 4px solid var(--gold-color);
        overflow: hidden;
        background: var(--light-gold);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4);
        position: relative;
        margin: 0 auto;
    }
    
    .carte-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .photo-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(212, 175, 55, 0.1);
    }
    
    .photo-placeholder i {
        font-size: 3.5rem;
        color: var(--gold-color);
        opacity: 0.7;
    }
    
    .photo-label {
        position: absolute;
        bottom: -5px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--navy-color);
        color: var(--gold-color);
        padding: 5px 15px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid var(--gold-color);
        white-space: nowrap;
    }
    
    /* Informations étudiantes principales */
    .info-principales {
        text-align: center;
        padding: 15px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        border: 2px solid rgba(212, 175, 55, 0.2);
    }
    
    .carte-nom-section {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid rgba(212, 175, 55, 0.3);
    }
    
    .carte-nom {
        font-size: 1.4rem;
        font-weight: 800;
        margin-bottom: 5px;
        color: var(--gold-color);
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .carte-matricule {
        font-size: 1rem;
        background: rgba(212, 175, 55, 0.15);
        padding: 8px 15px;
        border-radius: 20px;
        display: inline-block;
        font-weight: 600;
        border: 1px solid rgba(212, 175, 55, 0.5);
    }
    
    /* Section droite - Détails et QR Code */
    .carte-section-droite {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    /* Détails étudiants en grille */
    .carte-details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .detail-item {
        background: rgba(255, 255, 255, 0.08);
        padding: 12px;
        border-radius: 10px;
        border-left: 3px solid var(--gold-color);
        backdrop-filter: blur(5px);
    }
    
    .detail-label {
        font-size: 0.75rem;
        color: var(--light-gold);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .detail-value {
        font-size: 1rem;
        font-weight: 600;
        color: white;
    }
    
    /* Section QR Code intégrée */
    .carte-qr-section {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 20px;
        border: 2px solid rgba(212, 175, 55, 0.2);
        margin-top: auto;
    }
    
    .qr-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(212, 175, 55, 0.3);
    }
    
    .qr-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--gold-color);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .qr-title i {
        font-size: 1.1rem;
    }
    
    .qr-expiry {
        font-size: 0.8rem;
        color: var(--light-gold);
        background: rgba(0, 0, 0, 0.3);
        padding: 5px 10px;
        border-radius: 12px;
    }
    
    .qr-container {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .qr-code-box {
        background: white;
        padding: 12px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        flex-shrink: 0;
    }
    
    .qr-code-img {
        width: 120px;
        height: 120px;
        object-fit: contain;
        display: block;
    }
    
    .qr-info {
        flex: 1;
    }
    
    .qr-instructions {
        font-size: 0.85rem;
        color: var(--light-gold);
        margin-bottom: 10px;
        line-height: 1.5;
    }
    
    .qr-hint {
        font-size: 0.75rem;
        color: var(--gold-color);
        font-style: italic;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 12px;
    }
    
    /* Pied de carte */
    .carte-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid rgba(212, 175, 55, 0.2);
        font-size: 0.8rem;
        color: var(--light-gold);
        position: relative;
        z-index: 2;
    }
    
    .footer-date {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .footer-id {
        background: rgba(212, 175, 55, 0.1);
        padding: 8px 15px;
        border-radius: 15px;
        border: 1px solid rgba(212, 175, 55, 0.3);
        font-weight: 600;
    }
    
    /* Effets de brillance */
    .shine-effect {
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(
            45deg,
            transparent 30%,
            rgba(255, 255, 255, 0.03) 50%,
            transparent 70%
        );
        transform: rotate(30deg);
        z-index: 1;
        pointer-events: none;
    }
    
    /* Indicateur de validité */
    .validity-stamp {
        position: absolute;
        bottom: 20px;
        left: 20px;
        width: 60px;
        height: 60px;
        background: var(--gold-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transform: rotate(15deg);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        z-index: 2;
    }
    
    .validity-stamp i {
        color: var(--navy-color);
        font-size: 1.5rem;
    }
    
    /* =========================================== */
    /* STYLES EXISTANTS POUR LE RESTE DE LA PAGE */
    /* =========================================== */
    
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
    
    /* Responsive */
    @media (max-width: 992px) {
        .carte-content {
            flex-direction: column;
        }
        
        .carte-section-gauche {
            flex: 1;
        }
        
        .carte-photo-frame {
            width: 150px;
            height: 180px;
        }
        
        .logo-isgi-container {
            position: relative;
            top: 0;
            right: 0;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .carte-header {
            padding-right: 0;
            flex-direction: column;
            gap: 15px;
        }
    }
    
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
            min-height: auto;
        }
        
        .carte-details-grid {
            grid-template-columns: 1fr;
        }
        
        .qr-container {
            flex-direction: column;
            text-align: center;
        }
        
        .carte-footer {
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }
        
        .telechargement-options {
            grid-template-columns: 1fr;
        }
        
        .logo-isgi-container {
            position: relative;
            top: 0;
            right: 0;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .logo-isgi {
            margin: 0 auto;
        }
        
        .carte-header {
            padding-right: 0;
            flex-direction: column;
            gap: 15px;
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
    
    /* Debug pour les images */
    .debug-info {
        background: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 5px;
        font-family: monospace;
        font-size: 12px;
        margin-bottom: 10px;
        display: none;
    }
    
    /* Options d'orientation */
    .orientation-options {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .orientation-btn {
        padding: 8px 20px;
        border: 2px solid var(--secondary-color);
        background: transparent;
        color: var(--secondary-color);
        border-radius: 5px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .orientation-btn.active {
        background: var(--secondary-color);
        color: white;
    }
    
    .orientation-btn:hover {
        background: var(--secondary-color);
        color: white;
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
                    </div>
                </div>
            </div>
            
            <!-- Options d'orientation -->
            <div class="orientation-options">
                <button class="orientation-btn active" onclick="setOrientation('paysage')">
                    <i class="fas fa-arrows-alt-h me-2"></i>Mode Paysage
                </button>
                <button class="orientation-btn" onclick="setOrientation('portrait')">
                    <i class="fas fa-arrows-alt-v me-2"></i>Mode Portrait
                </button>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- DEBUG -->
            <div class="debug-info">
                <strong>Debug Info:</strong><br>
                QR Code Path: <?php echo safeHtml($qr_code_path ?? 'N/A'); ?><br>
                Logo Path: <?php echo safeHtml($logo_path); ?><br>
                File Exists: <?php echo file_exists($logo_path) ? 'OUI' : 'NON'; ?>
            </div>
            
            <!-- Section d'information importante -->
            <div class="warning-card">
                <h5><i class="fas fa-exclamation-triangle me-2"></i> Information importante</h5>
                <p class="mb-0">Cette carte est votre pièce d'identité officielle au sein de l'ISGI. Elle doit être présentée à chaque entrée et sortie de l'établissement, ainsi que pour tous les examens et services administratifs.</p>
            </div>
            
            <!-- Conteneur pour la carte -->
            <div class="carte-etudiante-container">
                <!-- Carte étudiante principale en mode paysage -->
                <div class="carte-etudiante carte-etudiante-paysage" id="cartePrincipale">
                    <!-- Logo ISGI -->
                    <div class="logo-isgi-container">
                        <div class="logo-isgi">
                            <img src="<?php echo safeHtml($logo_path); ?>" 
                                 alt="Logo ISGI" 
                                 onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div style=\'font-size:2.5rem;color:#1e3a5f;font-weight:bold;\'>ISGI</div>';">
                        </div>
                    </div>
                    
                    <!-- Effet de brillance -->
                    <div class="shine-effect"></div>
                    
                    <!-- En-tête -->
                    <div class="carte-header">
                        <div class="carte-titre">
                            <h1>CARTE ÉTUDIANTE</h1>
                            <div class="sous-titre">INSTITUT SUPÉRIEUR DE GESTION INGENIERIE</div>
                        </div>
                        <div class="carte-statut">
                            <i class="fas fa-check-circle"></i> VALIDE
                        </div>
                    </div>
                    
                    <!-- Contenu -->
                    <div class="carte-content">
                        <!-- Section gauche -->
                        <div class="carte-section-gauche">
                            <!-- Photo -->
                            <div class="carte-photo-container">
                                <div class="carte-photo-frame">
                                    <?php if(isset($info_etudiant['photo_identite']) && !empty($info_etudiant['photo_identite'])): ?>
                                    <?php 
                                    $photo_path = $info_etudiant['photo_identite'];
                                    if (strpos($photo_path, '/isgi_system/') !== false) {
                                        $photo_final = $photo_path;
                                    } else {
                                        $photo_final = '/isgi_system' . $photo_path;
                                    }
                                    ?>
                                    <img src="<?php echo safeHtml($photo_final); ?>" 
                                         alt="Photo étudiant" class="carte-photo"
                                         onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div class=\"photo-placeholder\"><i class=\"fas fa-user\"></i></div>';">
                                    <?php else: ?>
                                    <div class="photo-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="photo-label">INFO PERSO</div>
                            </div>
                            
                            <!-- Informations principales -->
                            <div class="info-principales">
                                <div class="carte-nom-section">
                                    <div class="carte-nom">
                                        <?php echo strtoupper(safeHtml($info_etudiant['nom'] ?? '')); ?> <?php echo safeHtml($info_etudiant['prenom'] ?? ''); ?>
                                    </div>
                                    <div class="carte-matricule">
                                        <i class="fas fa-id-badge me-2"></i>
                                        <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="carte-details-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Filière</div>
                                        <div class="detail-value"><?php echo safeHtml($info_etudiant['filiere_nom'] ?? 'Non spécifiée'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Niveau</div>
                                        <div class="detail-value"><?php echo safeHtml($info_etudiant['niveau_libelle'] ?? 'Non spécifié'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Année Académique</div>
                                        <div class="detail-value"><?php echo safeHtml($info_etudiant['annee_academique'] ?? date('Y') . '-' . (date('Y') + 1)); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Date de Naissance</div>
                                        <div class="detail-value"><?php echo formatDateFr($info_etudiant['date_naissance'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section droite -->
                        <div class="carte-section-droite">
                            <div class="carte-details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Site</div>
                                    <div class="detail-value"><?php echo safeHtml($info_etudiant['site_nom'] ?? ''); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Classe</div>
                                    <div class="detail-value"><?php echo safeHtml($info_etudiant['classe_nom'] ?? 'Non spécifiée'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Adresse</div>
                                    <div class="detail-value"><?php echo safeHtml($info_etudiant['site_ville'] ?? ''); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Téléphone</div>
                                    <div class="detail-value"><?php echo safeHtml($info_etudiant['site_telephone'] ?? 'Non spécifié'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Date d'inscription</div>
                                    <div class="detail-value"><?php echo formatDateFr($info_etudiant['date_inscription'] ?? ''); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Statut</div>
                                    <div class="detail-value"><?php echo ($info_etudiant['statut'] ?? 'Actif') == 'actif' ? 'ACTIF' : 'INACTIF'; ?></div>
                                </div>
                            </div>
                            
                            <!-- Section QR Code intégrée -->
                            <div class="carte-qr-section">
                                <div class="qr-header">
                                    <div class="qr-title">
                                        <i class="fas fa-qrcode"></i> CODE DE VÉRIFICATION NUMÉRIQUE
                                    </div>
                                    <div class="qr-expiry">
                                        Valide jusqu'au: <?php echo date('m/Y', strtotime('+1 year')); ?>
                                    </div>
                                </div>
                                
                                <div class="qr-container">
                                    <div class="qr-code-box">
                                        <?php if(!empty($qr_code_path)): ?>
                                        <img src="<?php echo safeHtml($qr_code_path); ?>" 
                                             alt="QR Code étudiant" class="qr-code-img"
                                             onerror="this.onerror=null; this.src=''; this.style.display='none'; this.parentNode.innerHTML='<div style=\"width:120px;height:120px;display:flex;align-items:center;justify-content:center;color:var(--gold-color);\"><i class=\"fas fa-exclamation-triangle fa-2x\"></i></div>';">
                                        <?php else: ?>
                                        <div style="width: 120px; height: 120px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; border-radius: 6px;">
                                            <i class="fas fa-exclamation-triangle text-warning fa-2x"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="qr-info">
                                        <p class="qr-instructions">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Scannez ce code QR avec l'application mobile ISGI ou un lecteur QR standard pour vérifier l'authenticité de cette carte étudiante. Le code contient toutes les informations d'identification officielles et sécurisées.
                                        </p>
                                        <div class="qr-hint">
                                            <i class="fas fa-mobile-alt"></i>
                                            Compatible avec toutes les applications de lecture QR
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pied de carte -->
                    <div class="carte-footer">
                        <div class="footer-date">
                            <i class="fas fa-calendar-alt"></i>
                            Délivrée le: <?php echo date('d/m/Y'); ?>
                        </div>
                        <div class="footer-id">
                            <i class="fas fa-fingerprint me-2"></i>
                            ID: <?php echo substr(md5($info_etudiant['matricule'] ?? '' . time()), 0, 12); ?>
                        </div>
                        <div class="footer-signature">
                            <i class="fas fa-stamp me-2"></i>
                            Cachet officiel ISGI
                        </div>
                    </div>
                    
                    <!-- Timbre de validité -->
                    <div class="validity-stamp">
                        <i class="fas fa-check"></i>
                    </div>
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
                    
                    
                </div>
                
                <div class="info-card mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i> Conseils d'impression</h6>
                    <p class="mb-0 small">Pour une impression optimale, utilisez du papier cartonné de qualité (300g) et imprimez en haute résolution. Laminez votre carte pour plus de durabilité. Le format paysage est recommandé pour une meilleure lisibilité.</p>
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
                                <strong>QR Code unique:</strong> <?php echo substr(md5($info_etudiant['matricule'] ?? '' . time()), 0, 12); ?>
                            </p>
                        </div>
                    </div>
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
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        // Mettre à jour le bouton
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser le thème au chargement
    document.addEventListener('DOMContentLoaded', function() {
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
    });
    
    // Fonction pour changer l'orientation
    function setOrientation(orientation) {
        const carte = document.getElementById('cartePrincipale');
        const buttons = document.querySelectorAll('.orientation-btn');
        
        // Mettre à jour les boutons actifs
        buttons.forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.classList.add('active');
        
        // Changer l'orientation
        if (orientation === 'paysage') {
            carte.classList.add('carte-etudiante-paysage');
            carte.classList.remove('carte-etudiante-portrait');
        } else {
            carte.classList.add('carte-etudiante-portrait');
            carte.classList.remove('carte-etudiante-paysage');
        }
    }
    
    // Fonction d'impression
    function imprimerCarte() {
        // Sauvegarder le thème original
        const originalTheme = document.documentElement.getAttribute('data-theme');
        
        // Forcer le mode clair pour l'impression
        document.documentElement.setAttribute('data-theme', 'light');
        
        // Créer une fenêtre d'impression spéciale pour la carte
        const printContent = document.getElementById('cartePrincipale').outerHTML;
        const printWindow = window.open('', '_blank', 'width=900,height=600');
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Carte Étudiante - ISGI</title>
                <style>
                    body { 
                        margin: 0; 
                        padding: 20px; 
                        display: flex; 
                        justify-content: center; 
                        align-items: center; 
                        min-height: 100vh;
                        background: #f0f0f0;
                    }
                    @media print {
                        body { background: none; }
                    }
                    .carte-etudiante { 
                        margin: 0 auto;
                        transform: scale(1.2);
                        transform-origin: top center;
                    }
                </style>
            </head>
            <body>
                ${printContent}
                <script>
                    window.onload = function() {
                        window.print();
                        setTimeout(function() {
                            window.close();
                        }, 500);
                    };
                <\/script>
            </body>
            </html>
        `);
        
        printWindow.document.close();
        
        // Restaurer le thème original après l'impression
        setTimeout(() => {
            document.documentElement.setAttribute('data-theme', originalTheme);
        }, 1000);
    }
    
    // Fonction pour télécharger la carte en PDF
    function telechargerPDF() {
        const { jsPDF } = window.jspdf;
        
        html2canvas(document.getElementById('cartePrincipale'), {
            scale: 3,
            backgroundColor: '#001f3f',
            useCORS: true,
            logging: false
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4'
            });
            
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            
            // Ajouter la carte centrée
            const imgWidth = 180;
            const imgHeight = 115;
            const x = (pageWidth - imgWidth) / 2;
            const y = (pageHeight - imgHeight) / 2;
            
            pdf.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);
            
            // Ajouter des informations supplémentaires
            pdf.setFontSize(12);
            pdf.setTextColor(40, 40, 40);
            pdf.text('Carte Étudiante ISGI - Format Paysage', pageWidth / 2, y - 15, { align: 'center' });
            pdf.setFontSize(10);
            pdf.text('Date d\'impression: ' + new Date().toLocaleDateString(), pageWidth / 2, y + imgHeight + 15, { align: 'center' });
            pdf.text('Matricule: <?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>', pageWidth / 2, y + imgHeight + 25, { align: 'center' });
            
            pdf.save('carte-etudiante-isgi-paysage.pdf');
            
            showNotification('PDF téléchargé avec succès', 'success');
        }).catch(error => {
            console.error('Erreur lors de la génération PDF:', error);
            showNotification('Erreur lors du téléchargement PDF', 'error');
        });
    }
    
    // Fonction pour télécharger la carte en image
    function telechargerImage() {
        html2canvas(document.getElementById('cartePrincipale'), {
            scale: 2,
            backgroundColor: '#001f3f',
            useCORS: true,
            logging: false
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'carte-etudiante-isgi-paysage.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            showNotification('Image téléchargée avec succès', 'success');
        }).catch(error => {
            console.error('Erreur lors de la génération d\'image:', error);
            showNotification('Erreur lors du téléchargement de l\'image', 'error');
        });
    }
    
    // Fonction pour télécharger le QR Code seul
    function telechargerQR() {
        <?php if(!empty($qr_code_path)): ?>
        // Essayer plusieurs chemins possibles
        const qrUrl = '<?php echo safeHtml($qr_code_path); ?>';
        const qrUrl2 = qrUrl.replace('/isgi_system', '');
        const qrUrl3 = '/isgi_system' + qrUrl2;
        
        // Télécharger en essayant les différentes URLs
        downloadImage(qrUrl, 'qr-code-etudiant-<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.png');
        
        <?php else: ?>
        showNotification('QR Code non disponible', 'error');
        <?php endif; ?>
    }
    
    // Fonction utilitaire pour télécharger une image
    function downloadImage(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Vérifier si le téléchargement a réussi
        setTimeout(() => {
            showNotification('QR Code téléchargé', 'success');
        }, 1000);
    }
    
    // Fonction pour télécharger au format carte de crédit (format paysage optimisé)
    function telechargerFormatCarte() {
        const { jsPDF } = window.jspdf;
        
        html2canvas(document.getElementById('cartePrincipale'), {
            scale: 4,
            backgroundColor: '#001f3f',
            useCORS: true,
            logging: false
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: [85.6, 54] // Format carte de crédit standard
            });
            
            // Pour le format paysage, on ajuste la position
            const imgWidth = 85.6;
            const imgHeight = 54;
            
            pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
            pdf.save('carte-etudiante-isgi-format-carte-paysage.pdf');
            
            showNotification('Carte au format standard (paysage) téléchargée', 'success');
        }).catch(error => {
            console.error('Erreur lors de la génération de la carte format:', error);
            showNotification('Erreur lors du téléchargement', 'error');
        });
    }
    
    // Fonction pour télécharger la carte (bouton principal)
    function telechargerCarte() {
        // Proposer différents formats
        if (confirm('Télécharger la carte en format paysage:\nOK = PDF (A4 paysage)\nAnnuler = Image PNG')) {
            telechargerPDF();
        } else {
            telechargerImage();
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
    </script>
</body>
</html>