<?php
// dashboard/etudiant/informations.php

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
    $pageTitle = "Informations Personnelles";
    
    // Fonctions utilitaires avec validation
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return 'Non spécifié';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getSexeLibelle($sexe) {
        switch ($sexe) {
            case 'M': return 'Masculin';
            case 'F': return 'Féminin';
            default: return 'Non spécifié';
        }
    }
    
    function getSituationMatrimonialeLibelle($situation) {
        switch ($situation) {
            case 'celibataire': return 'Célibataire';
            case 'marie': return 'Marié(e)';
            case 'divorce': return 'Divorcé(e)';
            case 'veuf': return 'Veuf/Veuve';
            default: return ucfirst($situation);
        }
    }
    
    // Fonction sécurisée pour afficher du texte
    function safeHtml($text) {
        if ($text === null || $text === '') {
            return 'Non renseigné';
        }
        return htmlspecialchars(strval($text), ENT_QUOTES, 'UTF-8');
    }
    
    // Fonction pour formater la taille des fichiers
    function formatFileSize($filepath, $rootPath = ROOT_PATH) {
        if (empty($filepath)) {
            return 'N/A';
        }
        
        $fullpath = $rootPath . '/' . $filepath;
        if (!file_exists($fullpath)) {
            return 'Fichier manquant';
        }
        
        $bytes = filesize($fullpath);
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }
    
    // Fonction pour générer l'URL de téléchargement sécurisé
    function getDocumentUrl($type, $etudiantId, $action = 'view') {
        if (empty($etudiantId)) return '#';
        return "download_document.php?type=" . urlencode($type) . 
               "&id=" . intval($etudiantId) . 
               "&action=" . $action;
    }
    
    // Fonction pour vérifier si un document existe
    function documentExists($filepath, $rootPath = ROOT_PATH) {
        if (empty($filepath)) return false;
        return file_exists($rootPath . '/' . $filepath);
    }
    
    // Récupérer l'ID de l'utilisateur
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    
    // Initialiser les variables
    $info_etudiant = array();
    $info_utilisateur = array();
    $info_classe = array();
    $error = null;
    
    // Récupérer les informations de l'utilisateur
    $info_utilisateur = $db->prepare(
        "SELECT u.*, r.nom as role_nom, s.nom as site_nom
         FROM utilisateurs u
         JOIN roles r ON u.role_id = r.id
         LEFT JOIN sites s ON u.site_id = s.id
         WHERE u.id = ?"
    );
    $info_utilisateur->execute([$user_id]);
    $info_utilisateur = $info_utilisateur->fetch(PDO::FETCH_ASSOC) ?: array();
    
    // Récupérer les informations de l'étudiant
    $query_etudiant = $db->prepare(
        "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                f.nom as filiere_nom, n.libelle as niveau_libelle,
                aa.libelle as annee_academique
         FROM etudiants e
         LEFT JOIN sites s ON e.site_id = s.id
         LEFT JOIN classes c ON e.classe_id = c.id
         LEFT JOIN filieres f ON e.classe_id IS NOT NULL AND c.filiere_id = f.id
         LEFT JOIN niveaux n ON e.classe_id IS NOT NULL AND c.niveau_id = n.id
         LEFT JOIN annees_academiques aa ON aa.statut = 'active'
         WHERE e.utilisateur_id = ? OR e.id = ?"
    );
    
    // Essayer d'abord avec utilisateur_id, sinon avec l'ID étudiant de session
    $etudiant_id = isset($_SESSION['etudiant_id']) ? intval($_SESSION['etudiant_id']) : null;
    $query_etudiant->execute([$user_id, $etudiant_id]);
    $info_etudiant = $query_etudiant->fetch(PDO::FETCH_ASSOC) ?: array();
    
    // Si on a trouvé l'étudiant, récupérer aussi les informations de classe
    if (!empty($info_etudiant['classe_id'])) {
        $query_classe = $db->prepare(
            "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle,
                    aa.libelle as annee_academique
             FROM classes c
             JOIN filieres f ON c.filiere_id = f.id
             JOIN niveaux n ON c.niveau_id = n.id
             JOIN annees_academiques aa ON c.annee_academique_id = aa.id
             WHERE c.id = ?"
        );
        $query_classe->execute([intval($info_etudiant['classe_id'])]);
        $info_classe = $query_classe->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    
    // Calculer le pourcentage de documents fournis
    $documents_types = ['photo_identite', 'acte_naissance', 'releve_notes', 
                       'attestation_legalisee', 'cni_passport', 'diplome'];
    $documents_fournis = 0;
    $documents_details = [];
    
    foreach ($documents_types as $type) {
        $exists = !empty($info_etudiant[$type]) && documentExists($info_etudiant[$type]);
        if ($exists) {
            $documents_fournis++;
        }
        $documents_details[$type] = [
            'exists' => $exists,
            'path' => $info_etudiant[$type] ?? '',
            'size' => $exists ? formatFileSize($info_etudiant[$type]) : 'N/A'
        ];
    }
    
    $pourcentage_documents = count($documents_types) > 0 ? 
        round(($documents_fournis / count($documents_types)) * 100) : 0;
        
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
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
    
    <!-- PDF.js pour la visualisation des PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    
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
    
    /* Informations */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .info-item {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        transition: all 0.3s;
    }
    
    .info-item:hover {
        border-color: var(--primary-color);
    }
    
    .info-label {
        font-size: 0.85rem;
        color: var(--text-muted);
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-label i {
        color: var(--primary-color);
    }
    
    .info-value {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-color);
    }
    
    /* Photo de profil */
    .profile-photo-container {
        text-align: center;
        padding: 20px;
    }
    
    .profile-photo {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid var(--primary-color);
        margin-bottom: 15px;
        cursor: zoom-in;
        transition: transform 0.3s;
    }
    
    .profile-photo:hover {
        transform: scale(1.05);
    }
    
    .photo-placeholder {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        border: 4px solid var(--primary-color);
    }
    
    .photo-placeholder i {
        font-size: 3rem;
        color: white;
    }
    
    /* Badges */
    .status-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .status-active {
        background-color: rgba(39, 174, 96, 0.2);
        color: var(--success-color);
    }
    
    .status-inactive {
        background-color: rgba(108, 117, 125, 0.2);
        color: var(--text-muted);
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
        
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Boutons */
    .btn-print {
        background: var(--primary-color);
        color: white;
        border: none;
    }
    
    .btn-print:hover {
        background: var(--secondary-color);
        color: white;
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
    
    /* Section headers */
    .section-header {
        border-bottom: 2px solid var(--primary-color);
        padding-bottom: 10px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .section-header h3 {
        color: var(--text-color);
        margin: 0;
    }
    
    .section-header h3 i {
        margin-right: 10px;
        color: var(--primary-color);
    }
    
    /* Documents */
    .document-item {
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s;
    }
    
    .document-item:hover {
        border-color: var(--primary-color);
        background-color: rgba(52, 152, 219, 0.05);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .document-icon {
        font-size: 2rem;
        color: var(--primary-color);
        margin-right: 15px;
    }
    
    .document-info {
        flex: 1;
    }
    
    .document-name {
        font-weight: 500;
        margin-bottom: 5px;
    }
    
    .document-meta {
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    
    .document-meta small {
        font-size: 0.75rem;
        opacity: 0.8;
    }
    
    .document-actions {
        display: flex;
        gap: 5px;
    }
    
    .document-actions .btn {
        padding: 5px 10px;
        font-size: 0.875rem;
    }
    
    /* Boutons pour les documents */
    .btn-document {
        border-radius: 5px;
        transition: all 0.3s;
    }
    
    .btn-document:hover {
        transform: translateY(-1px);
    }
    
    .btn-view {
        background-color: var(--primary-color);
        color: white;
        border: none;
    }
    
    .btn-download {
        background-color: var(--success-color);
        color: white;
        border: none;
    }
    
    .btn-print-doc {
        background-color: var(--warning-color);
        color: white;
        border: none;
    }
    
    /* Modal pour les documents */
    .modal-document {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }
    
    .modal-document-content {
        background-color: var(--card-bg);
        border-radius: 10px;
        width: 90%;
        max-width: 1000px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    }
    
    .modal-document-header {
        padding: 20px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-document-body {
        padding: 20px;
        height: 70vh;
        overflow-y: auto;
    }
    
    .modal-document-footer {
        padding: 15px 20px;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .document-preview {
        width: 100%;
        height: 100%;
        border-radius: 5px;
        overflow: hidden;
    }
    
    .document-preview iframe {
        width: 100%;
        height: 100%;
        border: none;
    }
    
    .image-preview {
        width: 100%;
        max-height: 60vh;
        object-fit: contain;
        border-radius: 5px;
    }
    
    .pdf-viewer {
        width: 100%;
        height: 65vh;
        border: 1px solid var(--border-color);
        border-radius: 5px;
    }
    
    /* Barre de progression */
    .progress {
        background-color: var(--border-color);
        border-radius: 10px;
        height: 10px;
        overflow: hidden;
    }
    
    .progress-bar {
        background-color: var(--success-color);
        height: 100%;
        transition: width 0.6s ease;
    }
    
    /* Quick actions */
    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    
    .quick-action-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 5px;
        color: var(--text-color);
        text-decoration: none;
        transition: all 0.3s;
    }
    
    .quick-action-btn:hover {
        background-color: var(--primary-color);
        color: white;
        transform: translateY(-2px);
    }
    
    /* QR Code */
    .qr-code-container {
        text-align: center;
        padding: 20px;
        background: white;
        border-radius: 10px;
        border: 1px solid var(--border-color);
    }
    
    .qr-code {
        width: 150px;
        height: 150px;
        margin: 0 auto 15px;
    }
    
    .qr-code img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    
    /* Table */
    .info-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .info-table th {
        background-color: var(--primary-color);
        color: white;
        padding: 12px 15px;
        text-align: left;
        font-weight: 500;
        border: none;
    }
    
    .info-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .info-table tr:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }
    
    [data-theme="dark"] .info-table tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Image zoom modal */
    .image-zoom-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.9);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        cursor: zoom-out;
    }
    
    .image-zoom-content {
        max-width: 90%;
        max-height: 90%;
        object-fit: contain;
    }
    
    .close-zoom {
        position: absolute;
        top: 20px;
        right: 20px;
        color: white;
        font-size: 40px;
        cursor: pointer;
        z-index: 10000;
    }
    
    /* Download all button */
    .download-all-btn {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s;
    }
    
    .download-all-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
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
                <p class="mb-1"><?php echo safeHtml($info_utilisateur['prenom'] ?? 'Étudiant'); ?> <?php echo safeHtml($info_utilisateur['nom'] ?? ''); ?></p>
                <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                <small>Matricule: <?php echo safeHtml($info_etudiant['matricule']); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="informations.php" class="nav-link active">
                        <i class="fas fa-user-circle"></i>
                        <span>Informations Personnelles</span>
                    </a>
                    <a href="carte_etudiante.php" class="nav-link">
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
                            <i class="fas fa-user-circle me-2"></i>
                            Informations Personnelles
                        </h2>
                        <p class="text-muted mb-0">
                            Consultation de vos informations personnelles et académiques
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> Retour au Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="quick-actions mb-4">
                <a href="#documents" class="quick-action-btn">
                    <i class="fas fa-file-alt"></i>
                    <span>Voir mes documents</span>
                </a>
                <a href="#academique" class="quick-action-btn">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Informations académiques</span>
                </a>
                <a href="#contact" class="quick-action-btn">
                    <i class="fas fa-address-book"></i>
                    <span>Coordonnées</span>
                </a>
                <button class="quick-action-btn" onclick="exportToPDF()">
                    <i class="fas fa-download"></i>
                    <span>Exporter en PDF</span>
                </button>
            </div>
            
            <!-- Section 1: Photo et informations de base -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-id-card me-2"></i>
                                Photo d'identité
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="profile-photo-container">
                                <?php if(isset($info_etudiant['photo_identite']) && !empty($info_etudiant['photo_identite']) && documentExists($info_etudiant['photo_identite'])): ?>
                                <img src="<?php echo ROOT_PATH . '/' . safeHtml($info_etudiant['photo_identite']); ?>" 
                                     alt="Photo d'identité" 
                                     class="profile-photo"
                                     onclick="previewImage(this.src, 'Photo d\'identité')"
                                     onerror="this.style.display='none'; document.getElementById('placeholder-photo').style.display='flex';">
                                <?php endif; ?>
                                <div id="placeholder-photo" class="photo-placeholder" 
                                     style="<?php echo (!isset($info_etudiant['photo_identite']) || empty($info_etudiant['photo_identite']) || !documentExists($info_etudiant['photo_identite'])) ? 'display: flex;' : 'display: none;'; ?>">
                                    <i class="fas fa-user"></i>
                                </div>
                                
                                <h5 class="mt-3 mb-1"><?php echo safeHtml($info_etudiant['prenom'] ?? $info_utilisateur['prenom']); ?> <?php echo safeHtml($info_etudiant['nom'] ?? $info_utilisateur['nom']); ?></h5>
                                
                                <?php if(isset($info_etudiant['matricule']) && !empty($info_etudiant['matricule'])): ?>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-id-badge"></i> <?php echo safeHtml($info_etudiant['matricule']); ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <span class="status-badge <?php echo ($info_etudiant['statut'] ?? '') == 'actif' ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-circle"></i> 
                                        <?php echo ($info_etudiant['statut'] ?? '') == 'actif' ? 'Actif' : 'Inactif'; ?>
                                    </span>
                                </div>
                                
                                <!-- Actions rapides pour la photo -->
                                <?php if(isset($info_etudiant['photo_identite']) && !empty($info_etudiant['photo_identite']) && documentExists($info_etudiant['photo_identite'])): ?>
                                <div class="mt-3 document-actions">
                                    <button class="btn btn-sm btn-view" onclick="previewImage('<?php echo ROOT_PATH . '/' . safeHtml($info_etudiant['photo_identite']); ?>', 'Photo d\'identité')">
                                        <i class="fas fa-eye"></i> Agrandir
                                    </button>
                                    <a href="download_document.php?type=photo_identite&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                       class="btn btn-sm btn-download"
                                       download="photo_identite_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.jpg">
                                        <i class="fas fa-download"></i> Télécharger
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Informations Générales
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-id-card"></i> Matricule
                                    </div>
                                    <div class="info-value">
                                        <?php echo safeHtml($info_etudiant['matricule'] ?? 'Non attribué'); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-birthday-cake"></i> Date de naissance
                                    </div>
                                    <div class="info-value">
                                        <?php echo formatDateFr($info_etudiant['date_naissance'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-map-marker-alt"></i> Lieu de naissance
                                    </div>
                                    <div class="info-value">
                                        <?php echo safeHtml($info_etudiant['lieu_naissance'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-venus-mars"></i> Sexe
                                    </div>
                                    <div class="info-value">
                                        <?php echo getSexeLibelle($info_etudiant['sexe'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-flag"></i> Nationalité
                                    </div>
                                    <div class="info-value">
                                        <?php echo safeHtml($info_etudiant['nationalite'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-heart"></i> Situation matrimoniale
                                    </div>
                                    <div class="info-value">
                                        <?php echo getSituationMatrimonialeLibelle($info_etudiant['situation_matrimoniale'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-briefcase"></i> Profession
                                    </div>
                                    <div class="info-value">
                                        <?php echo safeHtml($info_etudiant['profession'] ?? ''); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-calendar-alt"></i> Date d'inscription
                                    </div>
                                    <div class="info-value">
                                        <?php echo formatDateFr($info_etudiant['date_inscription'] ?? ''); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Informations académiques -->
            <div class="row mb-4" id="academique">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-graduation-cap me-2"></i>
                                Informations Académiques
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($info_classe)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Vous n'êtes pas encore assigné à une classe. Veuillez contacter l'administration.
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="info-table">
                                        <tr>
                                            <th style="width: 40%;">Filière</th>
                                            <td><?php echo safeHtml($info_classe['filiere_nom'] ?? $info_etudiant['filiere_nom'] ?? ''); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Niveau</th>
                                            <td><?php echo safeHtml($info_classe['niveau_libelle'] ?? $info_etudiant['niveau_libelle'] ?? ''); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Classe</th>
                                            <td><?php echo safeHtml($info_classe['nom'] ?? $info_etudiant['classe_nom'] ?? ''); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Site</th>
                                            <td><?php echo safeHtml($info_classe['site_nom'] ?? $info_etudiant['site_nom'] ?? ''); ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="info-table">
                                        <tr>
                                            <th style="width: 40%;">Année académique</th>
                                            <td><?php echo safeHtml($info_classe['annee_academique'] ?? $info_etudiant['annee_academique'] ?? ''); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Type de rentrée</th>
                                            <td>
                                                <?php 
                                                if(isset($info_etudiant['type_rentree'])) {
                                                    echo safeHtml($info_etudiant['type_rentree']);
                                                } else {
                                                    echo 'Non spécifié';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Cycle</th>
                                            <td>
                                                <?php 
                                                $niveau_code = '';
                                                if(isset($info_classe['niveau_id'])) {
                                                    $query_cycle = $db->prepare("SELECT cycle FROM niveaux WHERE id = ?");
                                                    $query_cycle->execute([$info_classe['niveau_id']]);
                                                    $niveau = $query_cycle->fetch(PDO::FETCH_ASSOC);
                                                    echo $niveau ? safeHtml($niveau['cycle']) : 'Non spécifié';
                                                } else {
                                                    echo 'Non spécifié';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Effectif max</th>
                                            <td><?php echo safeHtml($info_classe['effectif_max'] ?? ''); ?> étudiants</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Coordonnées -->
            <div class="row mb-4" id="contact">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-address-book me-2"></i>
                                Coordonnées et Contact
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-home me-2"></i> Adresse</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-map-pin"></i> Adresse
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['adresse'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-city"></i> Ville
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['ville'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-globe"></i> Pays
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['pays'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6><i class="fas fa-phone-alt me-2"></i> Contacts</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-phone"></i> Téléphone
                                            </div>
                                            <div class="info-value">
                                                <?php 
                                                echo safeHtml($info_etudiant['telephone'] ?? $info_utilisateur['telephone'] ?? '');
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-envelope"></i> Email
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_utilisateur['email'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-id-card"></i> Numéro CNI
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['numero_cni'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Informations familiales -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-users me-2"></i>
                                Informations Familiales
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-male me-2"></i> Père</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-user"></i> Nom complet
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['nom_pere'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-briefcase"></i> Profession
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['profession_pere'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6><i class="fas fa-female me-2"></i> Mère</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-user"></i> Nom complet
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['nom_mere'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-briefcase"></i> Profession
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['profession_mere'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6><i class="fas fa-user-tie me-2"></i> Tuteur (si différent des parents)</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-user"></i> Nom complet
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['nom_tuteur'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-briefcase"></i> Profession
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['profession_tuteur'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-phone"></i> Téléphone
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['telephone_tuteur'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-building"></i> Lieu de service
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['lieu_service_tuteur'] ?? ''); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">
                                                <i class="fas fa-phone-alt"></i> Téléphone parent/tuteur
                                            </div>
                                            <div class="info-value">
                                                <?php echo safeHtml($info_etudiant['telephone_parent'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 5: Documents -->
            <div class="row mb-4" id="documents">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-folder me-2"></i>
                                Mes Documents
                            </h5>
                            <div>
                                <button class="btn btn-sm btn-outline-primary me-2" onclick="showDocumentInfo()">
                                    <i class="fas fa-info-circle"></i> Infos
                                </button>
                                <button class="btn btn-sm btn-primary download-all-btn" onclick="downloadAllDocuments()">
                                    <i class="fas fa-download"></i> Télécharger tous
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Résumé des documents -->
                            <div class="alert alert-info mb-4">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <i class="fas fa-info-circle"></i> 
                                        <strong>État des documents:</strong> 
                                        <?php echo $documents_fournis; ?> sur <?php echo count($documents_types); ?> documents fournis
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="progress flex-grow-1">
                                                <div class="progress-bar" style="width: <?php echo $pourcentage_documents; ?>%"></div>
                                            </div>
                                            <span class="fw-bold"><?php echo $pourcentage_documents; ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <!-- Photo d'identité -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-image"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">Photo d'identité</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['photo_identite']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['photo_identite']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['photo_identite']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('photo_identite', 'Photo d\'identité')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=photo_identite&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="photo_identite_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.jpg">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Acte de naissance -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-pdf"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">Acte de naissance</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['acte_naissance']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['acte_naissance']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['acte_naissance']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('acte_naissance', 'Acte de naissance')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=acte_naissance&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="acte_naissance_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.pdf">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Relevé de notes -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-alt"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">Relevé de notes</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['releve_notes']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['releve_notes']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['releve_notes']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('releve_notes', 'Relevé de notes')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=releve_notes&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="releve_notes_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.pdf">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Attestation légalisée -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-certificate"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">Attestation légalisée</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['attestation_legalisee']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['attestation_legalisee']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['attestation_legalisee']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('attestation_legalisee', 'Attestation légalisée')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=attestation_legalisee&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="attestation_legalisee_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.pdf">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- CNI/Passport -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-contract"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">CNI/Passport</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['cni_passport']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['cni_passport']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['cni_passport']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('cni_passport', 'CNI/Passport')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=cni_passport&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="cni_passport_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.pdf">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Diplôme -->
                                <div class="col-md-4 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="document-icon">
                                                <i class="fas fa-file-certificate"></i>
                                            </div>
                                            <div class="document-info">
                                                <div class="document-name">Diplôme</div>
                                                <div class="document-meta">
                                                    <?php if($documents_details['diplome']['exists']): ?>
                                                    <span class="text-success">
                                                        <i class="fas fa-check-circle"></i> Fourni
                                                        <br>
                                                        <small><?php echo $documents_details['diplome']['size']; ?></small>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="fas fa-exclamation-circle"></i> À fournir
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if($documents_details['diplome']['exists']): ?>
                                        <div class="document-actions">
                                            <button class="btn btn-sm btn-view" 
                                                    onclick="viewDocument('diplome', 'Diplôme')"
                                                    title="Visualiser">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="download_document.php?type=diplome&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download" 
                                               class="btn btn-sm btn-download"
                                               title="Télécharger"
                                               download="diplome_<?php echo safeHtml($info_etudiant['matricule'] ?? ''); ?>.pdf">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Informations sur les documents -->
                            <div class="alert alert-warning mt-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h6 class="alert-heading">Informations importantes</h6>
                                        <ul class="mb-0">
                                            <li><strong>Formats acceptés:</strong> PDF, JPG, PNG, JPEG (max 5MB par fichier)</li>
                                            <li><strong>Qualité requise:</strong> Les documents doivent être clairs, lisibles et non modifiés</li>
                                            <li><strong>Mise à jour:</strong> Pour modifier ou ajouter un document, contactez le service des inscriptions</li>
                                            <li><strong>Sécurité:</strong> Vos documents sont stockés de manière sécurisée et ne sont accessibles que par vous</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 6: Informations complémentaires -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clipboard-list me-2"></i>
                                Informations Complémentaires
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6><i class="fas fa-history me-2"></i> Historique</h6>
                                        <ul class="list-unstyled">
                                            <li class="mb-2">
                                                <i class="fas fa-calendar-check text-primary me-2"></i>
                                                <strong>Dernière connexion:</strong> 
                                                <?php 
                                                if(isset($info_utilisateur['derniere_connexion']) && !empty($info_utilisateur['derniere_connexion'])) {
                                                    echo formatDateFr($info_utilisateur['derniere_connexion'], 'd/m/Y à H:i');
                                                } else {
                                                    echo 'Jamais';
                                                }
                                                ?>
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-user-check text-success me-2"></i>
                                                <strong>Compte créé le:</strong> 
                                                <?php echo formatDateFr($info_utilisateur['date_creation'] ?? '', 'd/m/Y'); ?>
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-sync-alt text-info me-2"></i>
                                                <strong>Dernière mise à jour:</strong> 
                                                <?php echo formatDateFr($info_utilisateur['date_modification'] ?? '', 'd/m/Y à H:i'); ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6><i class="fas fa-shield-alt me-2"></i> Sécurité du compte</h6>
                                        <div class="alert alert-success">
                                            <i class="fas fa-check-circle"></i> 
                                            <strong>Statut du compte:</strong> 
                                            <?php 
                                            switch($info_utilisateur['statut'] ?? '') {
                                                case 'actif':
                                                    echo '<span class="text-success">Actif</span>';
                                                    break;
                                                case 'en_attente':
                                                    echo '<span class="text-warning">En attente de validation</span>';
                                                    break;
                                                case 'suspendu':
                                                    echo '<span class="text-danger">Suspendu</span>';
                                                    break;
                                                default:
                                                    echo 'Non défini';
                                            }
                                            ?>
                                        </div>
                                        <p class="small text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            Pour modifier votre mot de passe ou vos coordonnées, veuillez utiliser la page "Paramètres".
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Note importante -->
            <div class="alert alert-info mt-4">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle fa-2x"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="alert-heading">Note importante</h6>
                        <p class="mb-0">
                            Ces informations sont à titre consultatif uniquement. 
                            Toute modification doit être demandée auprès du service administratif. 
                            En cas d'erreur ou pour mettre à jour vos documents, veuillez contacter l'administration de votre site.
                            <br>
                            <small class="text-muted">Dernière vérification: <?php echo date('d/m/Y'); ?></small>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour visualiser les documents -->
    <div id="documentModal" class="modal-document">
        <div class="modal-document-content">
            <div class="modal-document-header">
                <h5 id="modalDocumentTitle">Document</h5>
                <button onclick="closeModal()" class="btn-close" style="border: none; background: none; font-size: 1.5rem; cursor: pointer;">×</button>
            </div>
            <div class="modal-document-body">
                <div id="pdfViewerContainer" class="pdf-viewer" style="display: none;">
                    <iframe id="pdfFrame" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
                <div id="imageViewerContainer" style="display: none;">
                    <img id="imagePreview" src="" alt="" class="image-preview">
                </div>
                <div id="unsupportedViewerContainer" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-file-alt fa-4x mb-3" style="color: var(--text-muted);"></i>
                    <h5>Format non pris en charge pour la visualisation</h5>
                    <p class="text-muted">Ce type de fichier ne peut pas être affiché directement dans le navigateur.</p>
                    <button onclick="downloadCurrentDocument()" class="btn btn-primary">
                        <i class="fas fa-download"></i> Télécharger le fichier
                    </button>
                </div>
            </div>
            <div class="modal-document-footer">
                <div>
                    <span id="documentInfo" class="text-muted small"></span>
                </div>
                <div class="document-actions">
                    <button onclick="downloadCurrentDocument()" class="btn btn-download">
                        <i class="fas fa-download"></i> Télécharger
                    </button>
                    <button onclick="printCurrentDocument()" class="btn btn-print-doc">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <button onclick="closeModal()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour zoomer les images -->
    <div id="imageZoomModal" class="image-zoom-modal">
        <span class="close-zoom" onclick="closeImageZoom()">×</span>
        <img id="zoomedImage" class="image-zoom-content">
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <script>
    // Variables globales pour la gestion des documents
    let currentDocumentType = '';
    let currentDocumentTitle = '';
    let currentDocumentUrl = '';
    
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
    
    // Fonction pour visualiser un document
    function viewDocument(type, title) {
        currentDocumentType = type;
        currentDocumentTitle = title;
        currentDocumentUrl = `download_document.php?type=${encodeURIComponent(type)}&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=view`;
        
        const modal = document.getElementById('documentModal');
        const titleElement = document.getElementById('modalDocumentTitle');
        const pdfContainer = document.getElementById('pdfViewerContainer');
        const imageContainer = document.getElementById('imageViewerContainer');
        const unsupportedContainer = document.getElementById('unsupportedViewerContainer');
        const pdfFrame = document.getElementById('pdfFrame');
        const imagePreview = document.getElementById('imagePreview');
        const documentInfo = document.getElementById('documentInfo');
        
        // Afficher le modal
        modal.style.display = 'flex';
        titleElement.textContent = title;
        
        // Cacher tous les conteneurs
        pdfContainer.style.display = 'none';
        imageContainer.style.display = 'none';
        unsupportedContainer.style.display = 'none';
        
        // Déterminer le type de fichier
        const extension = type === 'photo_identite' ? 'jpg' : 'pdf';
        
        if (extension === 'pdf') {
            // Afficher le PDF
            pdfFrame.src = currentDocumentUrl;
            pdfContainer.style.display = 'block';
            documentInfo.textContent = 'Document PDF - Utilisez les contrôles pour naviguer';
        } else if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
            // Afficher l'image
            imagePreview.src = currentDocumentUrl;
            imageContainer.style.display = 'block';
            documentInfo.textContent = 'Image - Cliquez pour zoomer';
            
            // Ajouter l'événement de clic pour zoomer
            imagePreview.onclick = function() {
                previewImage(this.src, title);
            };
        } else {
            // Format non supporté
            unsupportedContainer.style.display = 'block';
            documentInfo.textContent = 'Document à télécharger';
        }
    }
    
    // Fonction pour fermer le modal
    function closeModal() {
        const modal = document.getElementById('documentModal');
        modal.style.display = 'none';
        
        // Réinitialiser l'iframe PDF
        const pdfFrame = document.getElementById('pdfFrame');
        pdfFrame.src = '';
        
        // Réinitialiser l'image
        const imagePreview = document.getElementById('imagePreview');
        imagePreview.src = '';
    }
    
    // Fonction pour télécharger le document courant
    function downloadCurrentDocument() {
        if (!currentDocumentType) return;
        
        const downloadUrl = `download_document.php?type=${encodeURIComponent(currentDocumentType)}&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download`;
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = `${currentDocumentType}_<?php echo safeHtml($info_etudiant['matricule'] ?? 'document'); ?>.${currentDocumentType === 'photo_identite' ? 'jpg' : 'pdf'}`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
    
    // Fonction pour imprimer le document courant
    function printCurrentDocument() {
        if (!currentDocumentType) return;
        
        const printUrl = `download_document.php?type=${encodeURIComponent(currentDocumentType)}&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=print`;
        const printWindow = window.open(printUrl, '_blank');
        
        if (printWindow) {
            printWindow.onload = function() {
                printWindow.print();
            };
        }
    }
    
    // Fonction pour prévisualiser une image avec zoom
    function previewImage(src, title) {
        const modal = document.getElementById('imageZoomModal');
        const zoomedImage = document.getElementById('zoomedImage');
        
        zoomedImage.src = src;
        zoomedImage.alt = title;
        modal.style.display = 'flex';
        
        // Empêcher le défilement de la page
        document.body.style.overflow = 'hidden';
    }
    
    // Fonction pour fermer le zoom d'image
    function closeImageZoom() {
        const modal = document.getElementById('imageZoomModal');
        modal.style.display = 'none';
        
        // Réactiver le défilement
        document.body.style.overflow = '';
    }
    
    // Fermer le zoom en cliquant à l'extérieur
    document.getElementById('imageZoomModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeImageZoom();
        }
    });
    
    // Fonction pour télécharger tous les documents
    function downloadAllDocuments() {
        const documents = [
            {type: 'photo_identite', name: 'photo_identite'},
            {type: 'acte_naissance', name: 'acte_naissance'},
            {type: 'releve_notes', name: 'releve_notes'},
            {type: 'attestation_legalisee', name: 'attestation_legalisee'},
            {type: 'cni_passport', name: 'cni_passport'},
            {type: 'diplome', name: 'diplome'}
        ];
        
        // Filtrer les documents disponibles
        const availableDocs = documents.filter(doc => {
            const exists = <?php echo json_encode($documents_details); ?>[doc.type]?.exists;
            return exists === true;
        });
        
        if (availableDocs.length === 0) {
            alert('Aucun document disponible pour le téléchargement.');
            return;
        }
        
        if (confirm(`Voulez-vous télécharger ${availableDocs.length} document(s) ?\nCette opération peut prendre quelques secondes.`)) {
            // Télécharger chaque document avec un délai
            availableDocs.forEach((doc, index) => {
                setTimeout(() => {
                    const downloadUrl = `download_document.php?type=${encodeURIComponent(doc.type)}&id=<?php echo $info_etudiant['id'] ?? ''; ?>&action=download`;
                    const a = document.createElement('a');
                    a.href = downloadUrl;
                    a.download = `${doc.name}_<?php echo safeHtml($info_etudiant['matricule'] ?? 'document'); ?>.${doc.type === 'photo_identite' ? 'jpg' : 'pdf'}`;
                    a.style.display = 'none';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }, index * 1000); // 1 seconde d'intervalle entre chaque téléchargement
            });
            
            // Afficher une notification
            showNotification(`${availableDocs.length} document(s) en cours de téléchargement...`);
        }
    }
    
    // Fonction pour afficher des informations sur les documents
    function showDocumentInfo() {
        const availableDocs = <?php echo $documents_fournis; ?>;
        const totalDocs = <?php echo count($documents_types); ?>;
        const percentage = <?php echo $pourcentage_documents; ?>;
        
        alert(`📋 ÉTAT DES DOCUMENTS\n\n` +
              `✅ Documents fournis: ${availableDocs}/${totalDocs} (${percentage}%)\n\n` +
              `📄 Documents requis:\n` +
              `• Photo d'identité\n` +
              `• Acte de naissance\n` +
              `• Relevé de notes\n` +
              `• Attestation légalisée\n` +
              `• CNI/Passport\n` +
              `• Diplôme\n\n` +
              `📝 Pour mettre à jour vos documents, contactez le service administratif.`);
    }
    
    // Fonction pour afficher une notification
    function showNotification(message, type = 'info') {
        // Créer l'élément de notification
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} notification-alert`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        `;
        
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
                <div class="flex-grow-1">${message}</div>
                <button class="btn-close" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Supprimer automatiquement après 5 secondes
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
    
    // Fonction pour exporter en PDF
    function exportToPDF() {
        showNotification('Export PDF en cours de développement...', 'info');
        // Implémentation PDF à venir
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
        
        // Ajouter le CSS pour les animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            .notification-alert {
                animation: slideIn 0.3s ease;
            }
        `;
        document.head.appendChild(style);
        
        // Ajouter les écouteurs d'événements pour fermer les modals avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeImageZoom();
            }
        });
    });
    </script>
</body>
</html>