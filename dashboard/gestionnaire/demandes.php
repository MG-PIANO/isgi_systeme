<?php
// dashboard/gestionnaire_principal/demandes.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire principal (rôle_id = 3)
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

/**
 * Formate une taille de fichier en octets en format lisible
 * @param int $bytes Taille en octets
 * @param int $precision Nombre de décimales
 * @return string Taille formatée
 */
function formatTaille($bytes, $precision = 2) {
    $units = array('o', 'Ko', 'Mo', 'Go', 'To');
    
    if ($bytes == 0 || $bytes === null) {
        return '0 o';
    }
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Envoyer un email de notification
 * @param string $to Email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email
 * @return bool Succès de l'envoi
 */
function envoyerEmail($to, $subject, $body) {
    // Version test - loggue seulement
    error_log("📧 EMAIL SIMULÉ: À $to | Sujet: $subject");
    error_log("Message: " . substr($body, 0, 500));
    return true;
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Demandes d'Inscription - Gestionnaire Principal";
    
    // Récupérer l'ID du site du gestionnaire
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    $user_id = $_SESSION['user_id'];
    
    // Si le gestionnaire n'a pas de site assigné, rediriger
    if (!$site_id) {
        die("Erreur: Vous n'êtes pas assigné à un site. Contactez l'administrateur.");
    }
    
    // Récupérer le nom du site
    $stmt_site = $db->prepare("SELECT nom, ville FROM sites WHERE id = ?");
    $stmt_site->execute([$site_id]);
    $site_info = $stmt_site->fetch(PDO::FETCH_ASSOC);
    $site_nom = $site_info['nom'] . ' - ' . $site_info['ville'];
    
    // Fonctions utilitaires
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'en_traitement':
                return '<span class="badge bg-info">En traitement</span>';
            case 'validee':
                return '<span class="badge bg-success">Validée</span>';
            case 'rejetee':
                return '<span class="badge bg-danger">Rejetée</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilisateur';
        }
        
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
        
        public static function getUserId() {
            return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        }
    }
    
    // Récupérer les paramètres de filtrage
    $statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'en_attente';
    $date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Construire la requête avec filtres - SEULEMENT POUR LE SITE DU GESTIONNAIRE
    $where_conditions = array("d.site_id = ?");
    $params = array($site_id);

    if (!empty($statut_filter) && $statut_filter != 'tous') {
        // Gestion des statuts multiples séparés par des virgules
        if (strpos($statut_filter, ',') !== false) {
            $statuts = explode(',', $statut_filter);
            $placeholders = rtrim(str_repeat('?,', count($statuts)), ',');
            $where_conditions[] = "d.statut IN ($placeholders)";
            $params = array_merge($params, $statuts);
        } else {
            $where_conditions[] = "d.statut = ?";
            $params[] = $statut_filter;
        }
    }
    
    if (!empty($date_debut)) {
        $where_conditions[] = "DATE(d.date_demande) >= ?";
        $params[] = $date_debut;
    }
    
    if (!empty($date_fin)) {
        $where_conditions[] = "DATE(d.date_demande) <= ?";
        $params[] = $date_fin;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(d.nom LIKE ? OR d.prenom LIKE ? OR d.email LIKE ? OR d.numero_demande LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Récupérer les demandes avec informations des validateurs - SEULEMENT POUR LE SITE DU GESTIONNAIRE
    $query = "SELECT d.*, s.nom as site_nom, s.ville as site_ville,
              CONCAT(uv.nom, ' ', uv.prenom) as validateur_nom,
              CONCAT(ua.nom, ' ', ua.prenom) as admin_traitant_nom
              FROM demande_inscriptions d
              LEFT JOIN sites s ON d.site_id = s.id
              LEFT JOIN utilisateurs uv ON d.validee_par = uv.id
              LEFT JOIN utilisateurs ua ON d.admin_traitant_id = ua.id
              $where_clause
              ORDER BY d.date_demande DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Compter les demandes par statut - SEULEMENT POUR LE SITE DU GESTIONNAIRE
    $stats_query = "SELECT statut, COUNT(*) as count FROM demande_inscriptions WHERE site_id = ? GROUP BY statut";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute([$site_id]);
    $stats_result = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = array(
        'en_attente' => 0,
        'en_traitement' => 0,
        'validee' => 0,
        'rejetee' => 0,
        'total' => 0
    );
    
    foreach ($stats_result as $row) {
        $stats[$row['statut']] = $row['count'];
        $stats['total'] += $row['count'];
    }
    
    // Traitement des actions
    $message = '';
    $message_type = '';
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $action = $_POST['action'] ?? '';
        $demande_id = $_POST['demande_id'] ?? 0;
        $commentaire = $_POST['commentaire'] ?? '';
        $raison_rejet = $_POST['raison_rejet'] ?? '';
        
        if ($action && $demande_id) {
            try {
                $db->beginTransaction();
                
                // Récupérer la demande et vérifier qu'elle appartient au site du gestionnaire
                $demande_query = "SELECT * FROM demande_inscriptions WHERE id = ? AND site_id = ?";
                $demande_stmt = $db->prepare($demande_query);
                $demande_stmt->execute([$demande_id, $site_id]);
                $demande = $demande_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$demande) {
                    throw new Exception("Demande non trouvée ou vous n'avez pas accès à cette demande.");
                }
                
                $user_id = SessionManager::getUserId();
                
                // ACTION : TRAITER
                if ($action == 'traiter') {
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'en_traitement', 
                                        admin_traitant_id = ?,
                                        date_traitement = NOW(),
                                        commentaire_admin = ?
                                    WHERE id = ? AND site_id = ?";
                    
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([$user_id, $commentaire, $demande_id, $site_id]);
                    
                    $message = "Demande mise en traitement";
                    
                    // Email
                    $sujet = "Votre demande est en traitement";
                    $corps = "Bonjour " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Votre demande d'inscription n°" . $demande['numero_demande'] . " est en cours de traitement.\n";
                    $corps .= "Nous vous tiendrons informé de l'avancement.\n\nCordialement,\nL'équipe ISGI";
                    envoyerEmail($demande['email'], $sujet, $corps);
                    
                } 
                // ACTION : VALIDER (CRÉER ÉTUDIANT)
                elseif ($action == 'valider') {
                    // 1. Générer matricule en utilisant la fonction MySQL
                    $stmt_mat = $db->query("SELECT fn_generer_prochain_matricule() as matricule");
                    $result_mat = $stmt_mat->fetch(PDO::FETCH_ASSOC);
                    $matricule = $result_mat['matricule'];
                    
                    // 2. Vérifier si l'étudiant existe déjà
                    $check_etudiant = $db->prepare("SELECT id FROM etudiants WHERE numero_cni = ? OR matricule = ?");
                    $check_etudiant->execute([$demande['numero_cni'], $matricule]);
                    
                    if ($check_etudiant->rowCount() > 0) {
                        throw new Exception("Un étudiant avec ce CNI ou matricule existe déjà");
                    }
                    
                    // 3. Créer l'étudiant
                    $etudiant_query = "INSERT INTO etudiants 
                                      (utilisateur_id, site_id, classe_id, matricule, nom, prenom, numero_cni, 
                                       date_naissance, lieu_naissance, sexe, nationalite, adresse, ville, pays, 
                                       profession, situation_matrimoniale,
                                       nom_pere, profession_pere, nom_mere, profession_mere,
                                       telephone_parent, nom_tuteur, profession_tuteur,
                                       telephone_tuteur, lieu_service_tuteur,
                                       photo_identite, acte_naissance, releve_notes, attestation_legalisee,
                                       statut, date_inscription)
                                      VALUES (NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW())";
                    
                    $etudiant_stmt = $db->prepare($etudiant_query);
                    $success = $etudiant_stmt->execute([
                        $site_id,
                        $matricule,
                        $demande['nom'],
                        $demande['prenom'],
                        $demande['numero_cni'],
                        $demande['date_naissance'],
                        $demande['lieu_naissance'],
                        $demande['sexe'],
                        $demande['nationalite'] ?? 'Congolaise',
                        $demande['adresse'],
                        $demande['ville'],
                        $demande['pays'] ?? 'Congo',
                        $demande['profession'],
                        $demande['situation_matrimoniale'],
                        $demande['nom_pere'] ?? '',
                        $demande['profession_pere'] ?? '',
                        $demande['nom_mere'] ?? '',
                        $demande['profession_mere'] ?? '',
                        $demande['telephone_parent'] ?? '',
                        $demande['nom_tuteur'] ?? '',
                        $demande['profession_tuteur'] ?? '',
                        $demande['telephone_tuteur'] ?? '',
                        $demande['lieu_service_tuteur'] ?? '',
                        $demande['photo_identite'] ?? '',
                        $demande['acte_naissance'] ?? '',
                        $demande['releve_notes'] ?? '',
                        $demande['attestation_legalisee'] ?? ''
                    ]);
                    
                    if (!$success) {
                        $error_info = $etudiant_stmt->errorInfo();
                        throw new Exception("Erreur création étudiant: " . $error_info[2]);
                    }
                    
                    $etudiant_id = $db->lastInsertId();
                    
                    // 4. Mettre à jour la demande
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'validee', 
                                        validee_par = ?,
                                        date_validation = NOW(),
                                        date_creation_compte = NOW(),
                                        commentaire_admin = ?
                                    WHERE id = ? AND site_id = ?";
                    
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([$user_id, $commentaire, $demande_id, $site_id]);
                    
                    $message = "🎉 ÉTUDIANT CRÉÉ AVEC SUCCÈS !<br>";
                    $message .= "📋 <strong>Matricule: $matricule</strong><br>";
                    $message .= "👤 <strong>" . $demande['nom'] . " " . $demande['prenom'] . "</strong><br>";
                    $message .= "📧 Email: " . $demande['email'] . "<br>";
                    $message .= "🎓 Filière: " . $demande['filiere'] . "<br>";
                    $message .= "🏫 Site: " . $site_nom . "<br>";
                    $message .= "🆔 ID étudiant: <strong>$etudiant_id</strong>";
                    
                    // Email
                    $sujet = "Votre inscription à l'ISGI est validée";
                    $corps = "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Votre inscription à l'ISGI a été validée avec succès !\n\n";
                    $corps .= "Votre matricule étudiant: " . $matricule . "\n";
                    $corps .= "Site: " . $site_nom . "\n";
                    $corps .= "Vous êtes maintenant officiellement étudiant à l'ISGI.\n\n";
                    $corps .= "Cordialement,\nL'équipe ISGI";
                    envoyerEmail($demande['email'], $sujet, $corps);
                    
                } 
                // ACTION : REJETER
                elseif ($action == 'rejeter') {
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'rejetee', 
                                        admin_traitant_id = ?,
                                        date_traitement = NOW(),
                                        raison_rejet = ?,
                                        commentaire_admin = ?
                                    WHERE id = ? AND site_id = ?";
                    
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([$user_id, $raison_rejet, $commentaire, $demande_id, $site_id]);
                    
                    $message = "Demande rejetée";
                    
                    // Email
                    $sujet = "Votre demande est rejetée";
                    $corps = "Bonjour " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Votre demande d'inscription n°" . $demande['numero_demande'] . " a été rejetée.\n";
                    $corps .= "Raison: " . $raison_rejet . "\n\n";
                    $corps .= "Vous pouvez soumettre une nouvelle demande avec les informations correctes.\n\n";
                    $corps .= "Cordialement,\nL'équipe ISGI";
                    envoyerEmail($demande['email'], $sujet, $corps);
                }
                
                $db->commit();
                $message_type = 'success';
                
                // Rediriger
                header("Location: demandes.php?message=" . urlencode($message) . "&type=success");
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $message = "Erreur: " . $e->getMessage();
                $message_type = 'danger';
                error_log("Erreur traitement: " . $e->getMessage());
            }
        }
    }
    
    // Récupérer les messages depuis l'URL
    if (isset($_GET['message'])) {
        $message = $_GET['message'];
        $message_type = $_GET['type'] ?? 'info';
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
            transition: background-color 0.3s ease, color 0.3s ease;
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
            transition: background-color 0.3s ease;
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
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Cartes */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            color: var(--text-color);
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.03);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
            color: var(--text-color);
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }
        
        [data-theme="dark"] .card-header {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Badges */
        .badge {
            font-size: 0.75em;
            padding: 4px 8px;
        }
        
        /* Tableaux */
        .table {
            color: var(--text-color);
            transition: color 0.3s ease;
        }
        
        [data-theme="dark"] .table {
            --bs-table-bg: var(--card-bg);
            --bs-table-striped-bg: rgba(255, 255, 255, 0.05);
            --bs-table-hover-bg: rgba(255, 255, 255, 0.1);
        }
        
        .table thead th {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }
        
        .table tbody td {
            border-color: var(--border-color);
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
        }
        
        /* Styles spécifiques */
        .stat-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .btn-group-xs > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .demande-details {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            color: var(--text-color);
        }
        
        .detail-row {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .document-card {
            border: 1px solid var(--border-color);
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .document-card .card-body {
            padding: 10px;
        }
        
        /* Onglets de filtrage */
        .filter-tabs .btn {
            border-radius: 4px;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        /* Site badge */
        .site-badge {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI FINANCES</h5>
                <div class="user-role">Gestionnaire Principal</div>
                <div class="site-badge mt-2"><?php echo htmlspecialchars($site_nom); ?></div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Gestion Financière</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Financier</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="inscriptions.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Inscriptions</span>
                    </a>
                    <a href="demandes.php" class="nav-link active">
                        <i class="fas fa-file-alt"></i>
                        <span>Demandes d'Inscription</span>
                        <?php if ($stats['en_attente'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['en_attente']; ?></span>
                        <?php endif; ?>
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
                            <i class="fas fa-file-alt me-2"></i>
                            Gestion des Demandes d'Inscription
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - <?php echo htmlspecialchars($site_nom); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <a href="demandes.php?statut=en_attente" class="btn btn-warning">
                            <i class="fas fa-clock"></i> En attente (<?php echo $stats['en_attente']; ?>)
                        </a>
                        <a href="demandes.php?statut=en_traitement" class="btn btn-info">
                            <i class="fas fa-cogs"></i> En traitement (<?php echo $stats['en_traitement']; ?>)
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'info-circle'; ?>"></i>
                <?php echo nl2br(htmlspecialchars($message)); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Onglets de filtrage rapide -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="filter-tabs">
                        <div class="btn-group" role="group">
                            <a href="demandes.php" class="btn <?php echo empty($statut_filter) || $statut_filter == 'tous' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Toutes (<?php echo $stats['total']; ?>)
                            </a>
                            <a href="demandes.php?statut=en_attente" class="btn <?php echo $statut_filter == 'en_attente' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                En attente (<?php echo $stats['en_attente']; ?>)
                            </a>
                            <a href="demandes.php?statut=en_traitement" class="btn <?php echo $statut_filter == 'en_traitement' ? 'btn-info' : 'btn-outline-info'; ?>">
                                En traitement (<?php echo $stats['en_traitement']; ?>)
                            </a>
                            <a href="demandes.php?statut=validee" class="btn <?php echo $statut_filter == 'validee' ? 'btn-success' : 'btn-outline-success'; ?>">
                                Validées (<?php echo $stats['validee']; ?>)
                            </a>
                            <a href="demandes.php?statut=rejetee" class="btn <?php echo $statut_filter == 'rejetee' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                Rejetées (<?php echo $stats['rejetee']; ?>)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques rapides -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #f1c40f); color: white;">
                        <div class="stat-value"><?php echo $stats['en_attente']; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9); color: white;">
                        <div class="stat-value"><?php echo $stats['en_traitement']; ?></div>
                        <div class="stat-label">En traitement</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #27ae60, #2ecc71); color: white;">
                        <div class="stat-value"><?php echo $stats['validee']; ?></div>
                        <div class="stat-label">Validées</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white;">
                        <div class="stat-value"><?php echo $stats['rejetee']; ?></div>
                        <div class="stat-label">Rejetées</div>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>
                        Filtres de recherche
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select">
                                <option value="tous" <?php echo $statut_filter == 'tous' ? 'selected' : ''; ?>>Tous les statuts</option>
                                <option value="en_attente" <?php echo $statut_filter == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="en_traitement" <?php echo $statut_filter == 'en_traitement' ? 'selected' : ''; ?>>En traitement</option>
                                <option value="validee" <?php echo $statut_filter == 'validee' ? 'selected' : ''; ?>>Validées</option>
                                <option value="rejetee" <?php echo $statut_filter == 'rejetee' ? 'selected' : ''; ?>>Rejetées</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Date début</label>
                            <input type="date" name="date_debut" class="form-control" value="<?php echo htmlspecialchars($date_debut); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="date_fin" class="form-control" value="<?php echo htmlspecialchars($date_fin); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Recherche</label>
                            <input type="text" name="search" class="form-control" placeholder="Nom, email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-12">
                            <div class="d-flex justify-content-between mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Filtrer
                                </button>
                                <a href="demandes.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Réinitialiser
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Liste des demandes -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Demandes (<?php echo count($demandes); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if(empty($demandes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Aucune demande trouvée</h5>
                        <p class="mb-0">Aucune demande ne correspond aux critères de recherche pour votre site.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Demandeur</th>
                                    <th>Email</th>
                                    <th>Filière</th>
                                    <th>Date demande</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($demandes as $demande): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($demande['numero_demande']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($demande['telephone']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($demande['email']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($demande['filiere']); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($demande['niveau']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo formatDateFr($demande['date_demande'], 'd/m/Y H:i'); ?><br>
                                        <small class="text-muted">
                                            <?php 
                                            $date1 = new DateTime($demande['date_demande']);
                                            $date2 = new DateTime();
                                            $interval = $date1->diff($date2);
                                            echo $interval->days . ' jour(s)';
                                            ?>
                                        </small>
                                    </td>
                                    <td><?php echo getStatutBadge($demande['statut']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- Bouton Voir -->
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewModal<?php echo $demande['id']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Actions selon le statut -->
                                            <?php if($demande['statut'] == 'en_attente' || $demande['statut'] == 'en_traitement'): ?>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#validateModal<?php echo $demande['id']; ?>">
                                                    <i class="fas fa-user-check"></i> Valider
                                                </button>
                                                <?php if($demande['statut'] == 'en_attente'): ?>
                                                <button type="button" class="btn btn-sm btn-warning" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#processModal<?php echo $demande['id']; ?>">
                                                    <i class="fas fa-cogs"></i> Traiter
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal<?php echo $demande['id']; ?>">
                                                    <i class="fas fa-times"></i> Rejeter
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Modal pour voir les détails -->
                                <div class="modal fade" id="viewModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Détails de la demande</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="demande-details">
                                                    <h6 class="mb-3">Informations personnelles</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Nom complet</div>
                                                                <div><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Date de naissance</div>
                                                                <div><?php echo formatDateFr($demande['date_naissance']); ?> à <?php echo htmlspecialchars($demande['lieu_naissance']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Sexe</div>
                                                                <div><?php echo htmlspecialchars($demande['sexe']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">CNI</div>
                                                                <div><?php echo htmlspecialchars($demande['numero_cni']); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Adresse</div>
                                                                <div><?php echo htmlspecialchars($demande['adresse'] . ', ' . $demande['ville'] . ', ' . $demande['pays']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Téléphone</div>
                                                                <div><?php echo htmlspecialchars($demande['telephone']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Email</div>
                                                                <div><?php echo htmlspecialchars($demande['email']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Profession</div>
                                                                <div><?php echo htmlspecialchars($demande['profession']); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Informations académiques</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Cycle</div>
                                                                <div><?php echo htmlspecialchars($demande['cycle_formation']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Domaine</div>
                                                                <div><?php echo htmlspecialchars($demande['domaine']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Filière</div>
                                                                <div><?php echo htmlspecialchars($demande['filiere']); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Niveau</div>
                                                                <div><?php echo htmlspecialchars($demande['niveau']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Type rentrée</div>
                                                                <div><?php echo htmlspecialchars($demande['type_rentree']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Site formation</div>
                                                                <div><?php echo htmlspecialchars($demande['site_formation']); ?></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Documents téléchargés</h6>
                                                    <div class="row">
                                                        <?php
                                                        $documents = [
                                                            'Photo d\'identité' => $demande['photo_identite'],
                                                            'Acte de naissance' => $demande['acte_naissance'],
                                                            'Relevé de notes' => $demande['releve_notes'],
                                                            'Attestation légalisée' => $demande['attestation_legalisee']
                                                        ];
                                                        
                                                        foreach ($documents as $label => $fichier):
                                                            if (!empty($fichier)):
                                                                $chemin_complet = ROOT_PATH . '/' . $fichier;
                                                                $taille = file_exists($chemin_complet) ? filesize($chemin_complet) : 0;
                                                        ?>
                                                        <div class="col-md-6 mb-2">
                                                            <div class="document-card">
                                                                <div class="card-body">
                                                                    <div class="d-flex justify-content-between align-items-center">
                                                                        <div>
                                                                            <strong class="d-block"><?php echo $label; ?></strong>
                                                                            <small class="text-muted"><?php echo formatTaille($taille); ?></small>
                                                                        </div>
                                                                        <div>
                                                                            <a href="<?php echo htmlspecialchars($fichier); ?>" 
                                                                               target="_blank" 
                                                                               class="btn btn-sm btn-outline-primary">
                                                                                <i class="fas fa-eye"></i>
                                                                            </a>
                                                                            <a href="<?php echo htmlspecialchars($fichier); ?>" 
                                                                               download 
                                                                               class="btn btn-sm btn-outline-secondary">
                                                                                <i class="fas fa-download"></i>
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endif; endforeach; ?>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Informations administratives</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Numéro demande</div>
                                                                <div><?php echo htmlspecialchars($demande['numero_demande']); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Date demande</div>
                                                                <div><?php echo formatDateFr($demande['date_demande'], 'd/m/Y H:i'); ?></div>
                                                            </div>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Statut</div>
                                                                <div><?php echo getStatutBadge($demande['statut']); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <?php if($demande['date_traitement']): ?>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Date traitement</div>
                                                                <div><?php echo formatDateFr($demande['date_traitement']); ?></div>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php if($demande['date_validation']): ?>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Date validation</div>
                                                                <div><?php echo formatDateFr($demande['date_validation']); ?></div>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php if($demande['commentaire_admin']): ?>
                                                            <div class="detail-row">
                                                                <div class="detail-label">Commentaire admin</div>
                                                                <div><?php echo htmlspecialchars($demande['commentaire_admin']); ?></div>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if($demande['admin_traitant_nom'] || $demande['validateur_nom']): ?>
                                                    <h6 class="mb-3 mt-4">Traçabilité</h6>
                                                    <div class="row">
                                                        <?php if($demande['admin_traitant_nom']): ?>
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Traité par</div>
                                                                <div><?php echo htmlspecialchars($demande['admin_traitant_nom']); ?></div>
                                                                <?php if($demande['date_traitement']): ?>
                                                                <small class="text-muted"><?php echo formatDateFr($demande['date_traitement'], 'd/m/Y H:i'); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if($demande['validateur_nom']): ?>
                                                        <div class="col-md-6">
                                                            <div class="detail-row">
                                                                <div class="detail-label">Validé par</div>
                                                                <div><?php echo htmlspecialchars($demande['validateur_nom']); ?></div>
                                                                <?php if($demande['date_validation']): ?>
                                                                <small class="text-muted"><?php echo formatDateFr($demande['date_validation'], 'd/m/Y H:i'); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal pour traiter -->
                                <div class="modal fade" id="processModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Traiter la demande</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Êtes-vous sûr de vouloir traiter la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                                    <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>">
                                                    <input type="hidden" name="action" value="traiter">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Commentaire (optionnel)</label>
                                                        <textarea name="commentaire" class="form-control" rows="3" placeholder="Ajoutez un commentaire..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                    <button type="submit" class="btn btn-warning">
                                                        <i class="fas fa-cogs me-2"></i>Mettre en traitement
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal pour valider -->
                                <div class="modal fade" id="validateModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Valider la demande</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="alert alert-info">
                                                        <i class="fas fa-info-circle"></i> Cette action créera un étudiant dans la base de données.
                                                    </div>
                                                    <p>Valider la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                                    <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>">
                                                    <input type="hidden" name="action" value="valider">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Commentaire (optionnel)</label>
                                                        <textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire de validation..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="fas fa-user-check me-2"></i>Valider et créer étudiant
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal pour rejeter -->
                                <div class="modal fade" id="rejectModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Rejeter la demande</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Rejeter la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                                    <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>">
                                                    <input type="hidden" name="action" value="rejeter">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Raison du rejet <span class="text-danger">*</span></label>
                                                        <textarea name="raison_rejet" class="form-control" rows="3" placeholder="Expliquez la raison du rejet..." required></textarea>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Commentaire (optionnel)</label>
                                                        <textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire additionnel..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="fas fa-times me-2"></i>Rejeter
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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
        // Sauvegarder dans localStorage pour persistance
        localStorage.setItem('isgi_theme', newTheme);
        
        // Mettre à jour le texte du bouton
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (newTheme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser le thème
    document.addEventListener('DOMContentLoaded', function() {
        // Vérifier localStorage d'abord, puis cookies, puis par défaut light
        const theme = localStorage.getItem('isgi_theme') || 
                     document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 
                     'light';
        
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Fermer automatiquement les alertes après 5 secondes
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    </script>
</body>
</html>