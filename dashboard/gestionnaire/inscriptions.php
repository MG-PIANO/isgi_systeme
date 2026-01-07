<?php
// dashboard/gestionnaire_principal/inscriptions.php

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

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Gestionnaire Principal - Inscriptions";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    $user_id = $_SESSION['user_id'];
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'validee':
                return '<span class="badge bg-success">Validée</span>';
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'refusee':
                return '<span class="badge bg-danger">Refusée</span>';
            case 'annulee':
                return '<span class="badge bg-secondary">Annulée</span>';
            case 'en_cours':
                return '<span class="badge bg-primary">En cours</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeInscriptionBadge($type) {
        switch ($type) {
            case 'normale':
                return '<span class="badge bg-primary">Normale</span>';
            case 'redoublement':
                return '<span class="badge bg-warning">Redoublement</span>';
            case 'transfert':
                return '<span class="badge bg-info">Transfert</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    // Variables
    $error = null;
    $success = null;
    $inscriptions = array();
    $sites = array();
    $filieres = array();
    $niveaux = array();
    $annees_academiques = array();
    $etudiants_existants = array();
    $site_nom = '';
    
    // Récupérer le nom du site si assigné
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
    // Récupérer les sites (gestionnaire peut voir tous les sites ou seulement le sien)
    if ($site_id) {
        $query = "SELECT * FROM sites WHERE id = ? AND statut = 'actif' ORDER BY ville";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Récupérer les filières
    $filieres = $db->query("SELECT * FROM filieres ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les niveaux
    $niveaux = $db->query("SELECT * FROM niveaux ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les années académiques actives
    $annees_academiques = $db->query("SELECT * FROM annees_academiques WHERE statut = 'active' ORDER BY date_debut DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les étudiants existants pour la réinscription
    $query_etudiants = "SELECT id, matricule, nom, prenom FROM etudiants WHERE statut = 'actif'";
    if ($site_id) {
        $query_etudiants .= " AND site_id = ?";
        $stmt = $db->prepare($query_etudiants);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->query($query_etudiants);
    }
    $etudiants_existants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_annee = isset($_GET['annee']) ? intval($_GET['annee']) : 0;
    $filtre_filiere = isset($_GET['filiere']) ? intval($_GET['filiere']) : 0;
    $filtre_recherche = isset($_GET['recherche']) ? trim($_GET['recherche']) : '';
    
    // Traitement des actions (inscription, réinscription, validation)
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $inscription_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    
    // TRAITEMENT DU FORMULAIRE D'INSCRIPTION
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'nouvelle_inscription') {
        try {
            $db->beginTransaction();
            
            // Récupérer les données du formulaire
            $nom = trim($_POST['nom']);
            $prenom = trim($_POST['prenom']);
            $date_naissance = $_POST['date_naissance'];
            $lieu_naissance = trim($_POST['lieu_naissance']);
            $sexe = $_POST['sexe'];
            $numero_cni = trim($_POST['numero_cni']);
            $nationalite = trim($_POST['nationalite']) ?: 'Congolaise';
            $adresse = trim($_POST['adresse']);
            $ville = trim($_POST['ville']);
            $pays = trim($_POST['pays']) ?: 'Congo';
            $telephone = trim($_POST['telephone']);
            $email = trim($_POST['email']);
            $nom_pere = trim($_POST['nom_pere']);
            $profession_pere = trim($_POST['profession_pere']);
            $nom_mere = trim($_POST['nom_mere']);
            $profession_mere = trim($_POST['profession_mere']);
            $telephone_parent = trim($_POST['telephone_parent']);
            
            // Récupérer les documents cochés
            $photo_identite = isset($_POST['photo_identite']) ? 'fourni' : 'manquant';
            $acte_naissance = isset($_POST['acte_naissance']) ? 'fourni' : 'manquant';
            $releve_notes = isset($_POST['releve_notes']) ? 'fourni' : 'manquant';
            $attestation_legalisee = isset($_POST['attestation_legalisee']) ? 'fourni' : 'manquant';
            
            // Données académiques
            $site_id_inscription = $site_id ?: intval($_POST['site_id']);
            $annee_academique_id = intval($_POST['annee_academique_id']);
            $filiere_id = intval($_POST['filiere_id']);
            $niveau_ordre = $_POST['niveau'];
            $type_inscription = $_POST['type_inscription'];
            
            // Données financières
            $montant_total = floatval($_POST['montant_total']);
            $mode_paiement = $_POST['mode_paiement'];
            $periodicite_paiement = $_POST['periodicite_paiement'];
            
            // Validation des données
            if (empty($nom) || empty($prenom) || empty($numero_cni)) {
                throw new Exception("Les champs obligatoires (Nom, Prénom, CNI) doivent être remplis.");
            }
            
            // Vérifier si le CNI existe déjà
            $stmt = $db->prepare("SELECT id FROM etudiants WHERE numero_cni = ?");
            $stmt->execute([$numero_cni]);
            if ($stmt->fetch()) {
                throw new Exception("Un étudiant avec ce numéro de CNI existe déjà.");
            }
            
            // 1. Générer le matricule en utilisant la fonction de la base de données
            $stmt = $db->query("SELECT fn_generer_prochain_matricule() as matricule");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $matricule = $result['matricule'];
            
            // 2. Créer l'étudiant directement dans la table etudiants
            $query_etudiant = "INSERT INTO etudiants (
                site_id, matricule, nom, prenom, numero_cni, date_naissance, 
                lieu_naissance, sexe, nationalite, adresse, ville, pays,
                nom_pere, profession_pere, nom_mere, profession_mere, 
                telephone_parent, photo_identite, acte_naissance, releve_notes, 
                attestation_legalisee, statut, date_inscription
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW())";
            
            $stmt = $db->prepare($query_etudiant);
            $stmt->execute([
                $site_id_inscription, $matricule, $nom, $prenom, $numero_cni, $date_naissance,
                $lieu_naissance, $sexe, $nationalite, $adresse, $ville, $pays,
                $nom_pere, $profession_pere, $nom_mere, $profession_mere,
                $telephone_parent, $photo_identite, $acte_naissance, $releve_notes,
                $attestation_legalisee
            ]);
            
            $etudiant_id = $db->lastInsertId();
            
            // 3. Créer l'inscription avec responsable_validation_id = NULL (car gestionnaire n'est pas administrateur)
            $query_inscription = "INSERT INTO inscriptions (
                etudiant_id, filiere_id, annee_academique_id, niveau, type_inscription,
                date_inscription, montant_total, montant_paye, statut, date_validation
            ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 0, 'validee', NOW())";
            
            $stmt = $db->prepare($query_inscription);
            $stmt->execute([
                $etudiant_id, $filiere_id, $annee_academique_id, $niveau_ordre, $type_inscription,
                $montant_total
            ]);
            
            $inscription_id = $db->lastInsertId();
            
            // 4. Créer la dette initiale
            $query_dette = "INSERT INTO dettes (
                etudiant_id, annee_academique_id, montant_du, montant_paye, 
                montant_restant, date_limite, statut
            ) VALUES (?, ?, ?, 0, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'en_cours')";
            
            $stmt = $db->prepare($query_dette);
            $stmt->execute([
                $etudiant_id, $annee_academique_id, $montant_total, $montant_total
            ]);
            
            // 5. Créer un compte utilisateur pour l'étudiant si email fourni
            if (!empty($email)) {
                // Vérifier si l'email existe déjà
                $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                $stmt->execute([$email]);
                if (!$stmt->fetch()) {
                    // Générer un mot de passe aléatoire
                    $mot_de_passe = bin2hex(random_bytes(4)); // 8 caractères hexadécimaux
                    $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                    
                    $query_utilisateur = "INSERT INTO utilisateurs (
                        role_id, site_id, email, mot_de_passe, nom, prenom, telephone, statut
                    ) VALUES (8, ?, ?, ?, ?, ?, ?, 'actif')";
                    
                    $stmt = $db->prepare($query_utilisateur);
                    $stmt->execute([
                        $site_id_inscription, $email, $mot_de_passe_hash, $nom, $prenom, $telephone
                    ]);
                    
                    $utilisateur_id = $db->lastInsertId();
                    
                    // Lier l'étudiant au compte utilisateur
                    $stmt = $db->prepare("UPDATE etudiants SET utilisateur_id = ? WHERE id = ?");
                    $stmt->execute([$utilisateur_id, $etudiant_id]);
                    
                    // TODO: Envoyer un email avec les identifiants
                }
            }
            
            $db->commit();
            $success = "Inscription réussie ! Étudiant créé avec le matricule: <strong>$matricule</strong>";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de l'inscription: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DU FORMULAIRE DE RÉINSCRIPTION
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'reinscription') {
        try {
            $db->beginTransaction();
            
            $etudiant_id = intval($_POST['etudiant_id']);
            $annee_academique_id = intval($_POST['annee_academique_id_reins']);
            $filiere_id = intval($_POST['filiere_id_reins']);
            $niveau_ordre = $_POST['niveau_reins'];
            $montant_total = floatval($_POST['montant_total_reins']);
            
            // Vérifier si l'étudiant existe
            $stmt = $db->prepare("SELECT id, matricule FROM etudiants WHERE id = ? AND statut = 'actif'");
            $stmt->execute([$etudiant_id]);
            $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$etudiant) {
                throw new Exception("Étudiant non trouvé ou inactif.");
            }
            
            // Vérifier s'il n'a pas déjà une inscription pour cette année
            $stmt = $db->prepare("SELECT id FROM inscriptions WHERE etudiant_id = ? AND annee_academique_id = ?");
            $stmt->execute([$etudiant_id, $annee_academique_id]);
            if ($stmt->fetch()) {
                throw new Exception("Cet étudiant a déjà une inscription pour cette année académique.");
            }
            
            // Créer la nouvelle inscription avec responsable_validation_id = NULL
            $query_inscription = "INSERT INTO inscriptions (
                etudiant_id, filiere_id, annee_academique_id, niveau, type_inscription,
                date_inscription, montant_total, montant_paye, statut, date_validation
            ) VALUES (?, ?, ?, ?, 'normale', CURDATE(), ?, 0, 'validee', NOW())";
            
            $stmt = $db->prepare($query_inscription);
            $stmt->execute([
                $etudiant_id, $filiere_id, $annee_academique_id, $niveau_ordre,
                $montant_total
            ]);
            
            // Créer la dette
            $query_dette = "INSERT INTO dettes (
                etudiant_id, annee_academique_id, montant_du, montant_paye, 
                montant_restant, date_limite, statut
            ) VALUES (?, ?, ?, 0, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'en_cours')";
            
            $stmt = $db->prepare($query_dette);
            $stmt->execute([
                $etudiant_id, $annee_academique_id, $montant_total, $montant_total
            ]);
            
            $db->commit();
            $success = "Réinscription réussie pour l'étudiant " . $etudiant['matricule'];
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la réinscription: " . $e->getMessage();
        }
    }
    
    // Traitement des actions de validation
    if ($action == 'valider' && $inscription_id > 0) {
        // Le gestionnaire ne peut pas valider car il n'est pas administrateur
        // On met seulement à jour le statut
        $stmt = $db->prepare("UPDATE inscriptions SET statut = 'validee', date_validation = NOW() WHERE id = ?");
        $stmt->execute([$inscription_id]);
        $success = "Inscription validée avec succès !";
    } elseif ($action == 'refuser' && $inscription_id > 0) {
        $stmt = $db->prepare("UPDATE inscriptions SET statut = 'refusee', date_validation = NOW() WHERE id = ?");
        $stmt->execute([$inscription_id]);
        $success = "Inscription refusée avec succès !";
    } elseif ($action == 'annuler' && $inscription_id > 0) {
        $stmt = $db->prepare("UPDATE inscriptions SET statut = 'annulee' WHERE id = ?");
        $stmt->execute([$inscription_id]);
        $success = "Inscription annulée avec succès !";
    }
    
    // Requête pour récupérer les inscriptions avec détails
    $query = "SELECT i.*, 
          e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom, e.date_naissance, e.sexe,
          f.nom as filiere_nom,
          n.libelle as niveau_libelle,
          aa.libelle as annee_libelle, aa.type_rentree,
          s.nom as site_nom, s.ville as site_ville,
          uv.nom as validateur_nom, uv.prenom as validateur_prenom
          FROM inscriptions i
          INNER JOIN etudiants e ON i.etudiant_id = e.id
          INNER JOIN filieres f ON i.filiere_id = f.id
          INNER JOIN niveaux n ON i.niveau = n.ordre
          INNER JOIN annees_academiques aa ON i.annee_academique_id = aa.id
          INNER JOIN sites s ON e.site_id = s.id
          LEFT JOIN administrateurs a ON i.responsable_validation_id = a.id
          LEFT JOIN utilisateurs uv ON a.utilisateur_id = uv.id
          WHERE 1=1";
    
    $params = array();
    
    // Filtrer par site du gestionnaire
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $params[] = $site_id;
    } elseif ($filtre_site > 0) {
        $query .= " AND e.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if (!empty($filtre_statut)) {
        $query .= " AND i.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if ($filtre_annee > 0) {
        $query .= " AND i.annee_academique_id = ?";
        $params[] = $filtre_annee;
    }
    
    if ($filtre_filiere > 0) {
        $query .= " AND i.filiere_id = ?";
        $params[] = $filtre_filiere;
    }
    
    if (!empty($filtre_recherche)) {
        $query .= " AND (e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)";
        $search_param = "%{$filtre_recherche}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " ORDER BY i.date_inscription DESC";
    
    // Exécuter la requête
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $inscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur inscriptions.php: " . $e->getMessage());
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
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
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
    
    /* Sidebar (identique au dashboard gestionnaire) */
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
    
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
        color: var(--text-color);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    [data-theme="dark"] .card-header {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    .table {
        color: var(--text-color);
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
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 15px;
        color: var(--text-color);
    }
    
    .stats-icon {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .student-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
    }
    
    /* Quick actions */
    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    
    .quick-actions .btn {
        flex: 1;
        min-width: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .montant-badge {
        font-size: 0.9em;
        padding: 5px 10px;
    }
    
    .paiement-attente {
        background-color: rgba(255, 193, 7, 0.2) !important;
    }
    
    .paiement-partiel {
        background-color: rgba(23, 162, 184, 0.2) !important;
    }
    
    .paiement-complet {
        background-color: rgba(40, 167, 69, 0.2) !important;
    }
    
    /* Styles pour les formulaires modaux */
    .form-section {
        background: rgba(0, 0, 0, 0.02);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        border-left: 4px solid var(--primary-color);
    }
    
    .form-section h6 {
        color: var(--primary-color);
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .required-field::after {
        content: " *";
        color: var(--accent-color);
    }
    
    .etudiant-info-card {
        background: rgba(52, 152, 219, 0.1);
        border: 1px solid rgba(52, 152, 219, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .etudiant-info-card h6 {
        color: var(--secondary-color);
        margin-bottom: 10px;
    }
    
    /* Styles pour les checkboxes de documents */
    .document-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background: rgba(0, 0, 0, 0.03);
        border-radius: 5px;
        margin-bottom: 8px;
        border: 1px solid var(--border-color);
    }
    
    .document-checkbox input[type="checkbox"] {
        width: 20px;
        height: 20px;
    }
    
    .document-checkbox label {
        margin: 0;
        flex: 1;
    }
    
    .document-status {
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 3px;
    }
    
    .document-present {
        background-color: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border: 1px solid var(--success-color);
    }
    
    .document-missing {
        background-color: rgba(220, 53, 69, 0.1);
        color: var(--accent-color);
        border: 1px solid var(--accent-color);
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
        
        .quick-actions .btn {
            min-width: 100px;
        }
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
                <?php if($site_nom): ?>
                <small><?php echo htmlspecialchars($site_nom); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Gestionnaire'); ?></p>
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
                    <a href="inscriptions.php" class="nav-link active">
                        <i class="fas fa-user-plus"></i>
                        <span>Inscriptions</span>
                    </a>
                    <a href="demandes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Demandes</span>
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
                            <i class="fas fa-user-plus me-2"></i>
                            Gestion des Inscriptions
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="quick-actions">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalInscription">
                            <i class="fas fa-plus-circle"></i> Nouvelle Inscription
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalReinscription">
                            <i class="fas fa-redo"></i> Nouvelle Réinscription
                        </button>
                        <button class="btn btn-secondary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Messages d'alerte -->
            <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>
                        Filtres de Recherche
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <?php if(!$site_id): ?>
                        <div class="col-md-3">
                            <label for="site" class="form-label">Site</label>
                            <select class="form-select" id="site" name="site">
                                <option value="0">Tous les sites</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo $filtre_site == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label for="annee" class="form-label">Année Académique</label>
                            <select class="form-select" id="annee" name="annee">
                                <option value="0">Toutes les années</option>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>" <?php echo $filtre_annee == $annee['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($annee['libelle'] . ' (' . $annee['type_rentree'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="statut" class="form-label">Statut Inscription</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="validee" <?php echo $filtre_statut == 'validee' ? 'selected' : ''; ?>>Validées</option>
                                <option value="en_attente" <?php echo $filtre_statut == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="refusee" <?php echo $filtre_statut == 'refusee' ? 'selected' : ''; ?>>Refusées</option>
                                <option value="annulee" <?php echo $filtre_statut == 'annulee' ? 'selected' : ''; ?>>Annulées</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="filiere" class="form-label">Filière</label>
                            <select class="form-select" id="filiere" name="filiere">
                                <option value="0">Toutes les filières</option>
                                <?php foreach($filieres as $filiere): ?>
                                <option value="<?php echo $filiere['id']; ?>" <?php echo $filtre_filiere == $filiere['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($filiere['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label for="recherche" class="form-label">Recherche</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo htmlspecialchars($filtre_recherche); ?>" 
                                       placeholder="Matricule, Nom, Prénom...">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || $filtre_annee > 0 || $filtre_filiere > 0 || !empty($filtre_recherche)): ?>
                                <a href="inscriptions.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques des inscriptions -->
            <?php 
            $total_validees = 0;
            $total_en_attente = 0;
            $total_refusees = 0;
            $total_annulees = 0;
            $montant_total = 0;
            $montant_paye = 0;
            
            foreach($inscriptions as $inscription) {
                if($inscription['statut'] == 'validee') $total_validees++;
                if($inscription['statut'] == 'en_attente') $total_en_attente++;
                if($inscription['statut'] == 'refusee') $total_refusees++;
                if($inscription['statut'] == 'annulee') $total_annulees++;
                
                $montant_total += $inscription['montant_total'];
                $montant_paye += $inscription['montant_paye'];
            }
            
            $pourcentage_paye = $montant_total > 0 ? ($montant_paye / $montant_total * 100) : 0;
            ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <h3><?php echo count($inscriptions); ?></h3>
                            <p class="text-muted mb-0">Inscriptions</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h3><?php echo $total_validees; ?></h3>
                            <p class="text-muted mb-0">Validées</p>
                            <small><?php echo formatMoney($montant_total); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h3><?php echo $total_en_attente; ?></h3>
                            <p class="text-muted mb-0">En attente</p>
                            <small>À traiter</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-money-check"></i>
                            </div>
                            <h3><?php echo formatMoney($montant_paye); ?></h3>
                            <p class="text-muted mb-0">Montant payé</p>
                            <div class="progress mt-2" style="height: 5px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo $pourcentage_paye; ?>%"></div>
                            </div>
                            <small><?php echo number_format($pourcentage_paye, 1); ?>%</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des inscriptions -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Inscriptions
                    </h5>
                    <div class="text-muted">
                        <?php echo count($inscriptions); ?> inscription(s) trouvée(s)
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($inscriptions)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucune inscription trouvée avec les critères sélectionnés
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="inscriptionsTable">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Informations Inscription</th>
                                    <th>Situation Paiement</th>
                                    <th>Statut</th>
                                    <th>Validation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($inscriptions as $inscription): 
                                // Calculer l'âge
                                $age = 'N/A';
                                if($inscription['date_naissance'] && $inscription['date_naissance'] != '0000-00-00') {
                                    $naissance = new DateTime($inscription['date_naissance']);
                                    $age = $naissance->diff(new DateTime())->y;
                                }
                                
                                // Déterminer la classe de paiement
                                $paiement_class = '';
                                if ($inscription['montant_paye'] == 0) {
                                    $paiement_class = 'paiement-attente';
                                } elseif ($inscription['montant_paye'] < $inscription['montant_total']) {
                                    $paiement_class = 'paiement-partiel';
                                } else {
                                    $paiement_class = 'paiement-complet';
                                }
                                ?>
                                <tr class="<?php echo $paiement_class; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="student-avatar me-3">
                                                <?php 
                                                $initials = '';
                                                if(!empty($inscription['etudiant_prenom']) && !empty($inscription['etudiant_nom'])) {
                                                    $initials = strtoupper(substr($inscription['etudiant_prenom'], 0, 1) . substr($inscription['etudiant_nom'], 0, 1));
                                                } else {
                                                    $initials = '??';
                                                }
                                                echo $initials;
                                                ?>
                                            </div>
                                            <div>
                                                <strong>
                                                    <?php 
                                                    if(!empty($inscription['etudiant_prenom']) && !empty($inscription['etudiant_nom'])) {
                                                        echo htmlspecialchars($inscription['etudiant_prenom'] . ' ' . $inscription['etudiant_nom']);
                                                    } else {
                                                        echo 'Nom inconnu';
                                                    }
                                                    ?>
                                                </strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($inscription['matricule'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="mt-1">
                                                    <span class="badge bg-secondary"><?php echo $age; ?> ans</span>
                                                    <span class="badge bg-info"><?php echo $inscription['sexe'] == 'M' ? 'Homme' : 'Femme'; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><strong>Année:</strong> <?php echo htmlspecialchars($inscription['annee_libelle'] . ' (' . $inscription['type_rentree'] . ')'); ?></div>
                                            <div><strong>Filière:</strong> <?php echo htmlspecialchars($inscription['filiere_nom']); ?></div>
                                            <div><strong>Niveau:</strong> <?php echo htmlspecialchars($inscription['niveau_libelle']); ?></div>
                                            <div><strong>Site:</strong> <?php echo htmlspecialchars($inscription['site_nom'] . ' - ' . $inscription['site_ville']); ?></div>
                                            <div><strong>Type:</strong> <?php echo getTypeInscriptionBadge($inscription['type_inscription']); ?></div>
                                            <div><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($inscription['date_inscription'])); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div>
                                                <strong>Total:</strong> 
                                                <span class="fw-bold"><?php echo formatMoney($inscription['montant_total']); ?></span>
                                            </div>
                                            <div>
                                                <strong>Payé:</strong> 
                                                <span class="text-success"><?php echo formatMoney($inscription['montant_paye']); ?></span>
                                            </div>
                                            <div>
                                                <strong>Reste:</strong> 
                                                <?php 
                                                $reste = $inscription['montant_total'] - $inscription['montant_paye'];
                                                $class_reste = $reste > 0 ? 'text-danger' : 'text-success';
                                                ?>
                                                <span class="<?php echo $class_reste; ?>"><?php echo formatMoney($reste); ?></span>
                                            </div>
                                            <div class="mt-2">
                                                <div class="progress" style="height: 8px;">
                                                    <?php 
                                                    $pourcentage = $inscription['montant_total'] > 0 ? ($inscription['montant_paye'] / $inscription['montant_total'] * 100) : 0;
                                                    $progress_class = $pourcentage == 100 ? 'bg-success' : ($pourcentage > 50 ? 'bg-warning' : 'bg-danger');
                                                    ?>
                                                    <div class="progress-bar <?php echo $progress_class; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $pourcentage; ?>%"></div>
                                                </div>
                                                <small class="text-muted"><?php echo number_format($pourcentage, 1); ?>% payé</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($inscription['statut']); ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?php if($inscription['date_validation']): ?>
                                            <div><strong>Validée le:</strong> <?php echo date('d/m/Y', strtotime($inscription['date_validation'])); ?></div>
                                            <?php if($inscription['validateur_nom']): ?>
                                            <div><strong>Par:</strong> <?php echo htmlspecialchars($inscription['validateur_nom'] . ' ' . $inscription['validateur_prenom']); ?></div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <div class="text-warning">
                                                <i class="fas fa-clock"></i> En attente
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if($inscription['statut'] == 'en_attente'): ?>
                                            <a href="inscriptions.php?action=valider&id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-success" title="Valider" onclick="return confirm('Valider cette inscription ?')">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="inscriptions.php?action=refuser&id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-danger" title="Refuser" onclick="return confirm('Refuser cette inscription ?')">
                                                <i class="fas fa-times"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if($inscription['statut'] != 'annulee'): ?>
                                            <a href="nouveau_paiement.php?inscription_id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-primary" title="Enregistrer paiement">
                                                <i class="fas fa-money-bill"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <a href="details_inscription.php?id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-info" title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if($inscription['statut'] == 'validee'): ?>
                                            <a href="generer_contrat.php?inscription_id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-warning" title="Générer contrat">
                                                <i class="fas fa-file-contract"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if($inscription['statut'] != 'annulee'): ?>
                                            <a href="inscriptions.php?action=annuler&id=<?php echo $inscription['id']; ?>" 
                                               class="btn btn-dark" title="Annuler" onclick="return confirm('Annuler cette inscription ?')">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour nouvelle inscription -->
    <div class="modal fade" id="modalInscription" tabindex="-1" aria-labelledby="modalInscriptionLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalInscriptionLabel">
                        <i class="fas fa-user-plus me-2"></i>Nouvelle Inscription
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="nouvelle_inscription">
                        
                        <div class="form-section">
                            <h6><i class="fas fa-user me-2"></i>Informations Personnelles</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="nom" class="form-label required-field">Nom</label>
                                    <input type="text" class="form-control" id="nom" name="nom" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="prenom" class="form-label required-field">Prénom</label>
                                    <input type="text" class="form-control" id="prenom" name="prenom" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="date_naissance" class="form-label required-field">Date de Naissance</label>
                                    <input type="date" class="form-control" id="date_naissance" name="date_naissance" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="lieu_naissance" class="form-label required-field">Lieu de Naissance</label>
                                    <input type="text" class="form-control" id="lieu_naissance" name="lieu_naissance" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="sexe" class="form-label required-field">Sexe</label>
                                    <select class="form-select" id="sexe" name="sexe" required>
                                        <option value="M">Masculin</option>
                                        <option value="F">Féminin</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="numero_cni" class="form-label required-field">Numéro CNI</label>
                                    <input type="text" class="form-control" id="numero_cni" name="numero_cni" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="nationalite" class="form-label">Nationalité</label>
                                    <input type="text" class="form-control" id="nationalite" name="nationalite" value="Congolaise">
                                </div>
                                <div class="col-md-12">
                                    <label for="adresse" class="form-label required-field">Adresse</label>
                                    <textarea class="form-control" id="adresse" name="adresse" rows="2" required></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label for="ville" class="form-label required-field">Ville</label>
                                    <input type="text" class="form-control" id="ville" name="ville" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="pays" class="form-label">Pays</label>
                                    <input type="text" class="form-control" id="pays" name="pays" value="Congo">
                                </div>
                                <div class="col-md-4">
                                    <label for="telephone" class="form-label required-field">Téléphone</label>
                                    <input type="text" class="form-control" id="telephone" name="telephone" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-users me-2"></i>Informations Familiales</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="nom_pere" class="form-label required-field">Nom du Père</label>
                                    <input type="text" class="form-control" id="nom_pere" name="nom_pere" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="profession_pere" class="form-label">Profession du Père</label>
                                    <input type="text" class="form-control" id="profession_pere" name="profession_pere">
                                </div>
                                <div class="col-md-6">
                                    <label for="nom_mere" class="form-label required-field">Nom de la Mère</label>
                                    <input type="text" class="form-control" id="nom_mere" name="nom_mere" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="profession_mere" class="form-label">Profession de la Mère</label>
                                    <input type="text" class="form-control" id="profession_mere" name="profession_mere">
                                </div>
                                <div class="col-md-12">
                                    <label for="telephone_parent" class="form-label required-field">Téléphone des Parents</label>
                                    <input type="text" class="form-control" id="telephone_parent" name="telephone_parent" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-file-alt me-2"></i>Documents Fournis</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <p class="text-muted mb-3">Cochez les documents que l'étudiant a fournis :</p>
                                    
                                    <div class="document-checkbox">
                                        <input type="checkbox" id="photo_identite" name="photo_identite" value="1" required>
                                        <label for="photo_identite" class="form-label mb-0">
                                            <i class="fas fa-id-card text-primary me-2"></i>
                                            Photo d'identité
                                        </label>
                                        <span id="photo_status" class="document-status document-missing">Manquant</span>
                                    </div>
                                    
                                    <div class="document-checkbox">
                                        <input type="checkbox" id="acte_naissance" name="acte_naissance" value="1" required>
                                        <label for="acte_naissance" class="form-label mb-0">
                                            <i class="fas fa-certificate text-success me-2"></i>
                                            Acte de naissance
                                        </label>
                                        <span id="acte_status" class="document-status document-missing">Manquant</span>
                                    </div>
                                    
                                    <div class="document-checkbox">
                                        <input type="checkbox" id="releve_notes" name="releve_notes" value="1" required>
                                        <label for="releve_notes" class="form-label mb-0">
                                            <i class="fas fa-file-alt text-info me-2"></i>
                                            Relevé de notes
                                        </label>
                                        <span id="releve_status" class="document-status document-missing">Manquant</span>
                                    </div>
                                    
                                    <div class="document-checkbox">
                                        <input type="checkbox" id="attestation_legalisee" name="attestation_legalisee" value="1">
                                        <label for="attestation_legalisee" class="form-label mb-0">
                                            <i class="fas fa-stamp text-warning me-2"></i>
                                            Attestation légalisée
                                        </label>
                                        <span id="attestation_status" class="document-status document-missing">Manquant</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-graduation-cap me-2"></i>Informations Académiques</h6>
                            <div class="row g-3">
                                <?php if(!$site_id): ?>
                                <div class="col-md-6">
                                    <label for="site_id" class="form-label required-field">Site de Formation</label>
                                    <select class="form-select" id="site_id" name="site_id" required>
                                        <?php foreach($sites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="site_id" value="<?php echo $site_id; ?>">
                                <div class="col-md-6">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Site:</strong> <?php echo htmlspecialchars($site_nom); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-6">
                                    <label for="annee_academique_id" class="form-label required-field">Année Académique</label>
                                    <select class="form-select" id="annee_academique_id" name="annee_academique_id" required>
                                        <?php foreach($annees_academiques as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['libelle'] . ' (' . $annee['type_rentree'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="filiere_id" class="form-label required-field">Filière</label>
                                    <select class="form-select" id="filiere_id" name="filiere_id" required>
                                        <option value="">Sélectionner une filière</option>
                                        <?php foreach($filieres as $filiere): ?>
                                        <option value="<?php echo $filiere['id']; ?>"><?php echo htmlspecialchars($filiere['nom']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="niveau" class="form-label required-field">Niveau</label>
                                    <select class="form-select" id="niveau" name="niveau" required>
                                        <option value="">Sélectionner un niveau</option>
                                        <?php foreach($niveaux as $niveau): ?>
                                        <option value="<?php echo $niveau['ordre']; ?>"><?php echo htmlspecialchars($niveau['libelle'] . ' (' . $niveau['cycle'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="type_inscription" class="form-label required-field">Type d'Inscription</label>
                                    <select class="form-select" id="type_inscription" name="type_inscription" required>
                                        <option value="normale">Normale</option>
                                        <option value="redoublement">Redoublement</option>
                                        <option value="transfert">Transfert</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-money-bill-wave me-2"></i>Informations Financières</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="montant_total" class="form-label required-field">Montant Total (FCFA)</label>
                                    <input type="number" class="form-control" id="montant_total" name="montant_total" min="0" step="1000" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="mode_paiement" class="form-label required-field">Mode de Paiement</label>
                                    <select class="form-select" id="mode_paiement" name="mode_paiement" required>
                                        <option value="Espèces">Espèces</option>
                                        <option value="Airtel Money">Airtel Money</option>
                                        <option value="MTN Mobile Money">MTN Mobile Money</option>
                                        <option value="Virement bancaire">Virement bancaire</option>
                                        <option value="Chèque">Chèque</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="periodicite_paiement" class="form-label required-field">Périodicité de Paiement</label>
                                    <select class="form-select" id="periodicite_paiement" name="periodicite_paiement" required>
                                        <option value="Mensuel">Mensuel</option>
                                        <option value="Trimestriel">Trimestriel</option>
                                        <option value="Semestriel">Semestriel</option>
                                        <option value="Annuel">Annuel</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer l'Inscription
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour réinscription -->
    <div class="modal fade" id="modalReinscription" tabindex="-1" aria-labelledby="modalReinscriptionLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalReinscriptionLabel">
                        <i class="fas fa-redo me-2"></i>Nouvelle Réinscription
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reinscription">
                        
                        <div class="form-section">
                            <h6><i class="fas fa-user-graduate me-2"></i>Sélection de l'Étudiant</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="etudiant_id" class="form-label required-field">Étudiant à Réinscrire</label>
                                    <select class="form-select" id="etudiant_id" name="etudiant_id" required onchange="chargerInfosEtudiant(this.value)">
                                        <option value="">Sélectionner un étudiant</option>
                                        <?php foreach($etudiants_existants as $etudiant): ?>
                                        <option value="<?php echo $etudiant['id']; ?>">
                                            <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div id="infoEtudiant" class="etudiant-info-card" style="display: none;">
                                <h6><i class="fas fa-info-circle me-2"></i>Informations de l'Étudiant</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Matricule:</strong> <span id="matricule_info"></span></p>
                                        <p class="mb-1"><strong>Nom:</strong> <span id="nom_info"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Prénom:</strong> <span id="prenom_info"></span></p>
                                        <p class="mb-1"><strong>CNI:</strong> <span id="cni_info"></span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-graduation-cap me-2"></i>Informations de Réinscription</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="annee_academique_id_reins" class="form-label required-field">Nouvelle Année Académique</label>
                                    <select class="form-select" id="annee_academique_id_reins" name="annee_academique_id_reins" required>
                                        <?php foreach($annees_academiques as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['libelle'] . ' (' . $annee['type_rentree'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="filiere_id_reins" class="form-label required-field">Nouvelle Filière</label>
                                    <select class="form-select" id="filiere_id_reins" name="filiere_id_reins" required>
                                        <option value="">Sélectionner une filière</option>
                                        <?php foreach($filieres as $filiere): ?>
                                        <option value="<?php echo $filiere['id']; ?>"><?php echo htmlspecialchars($filiere['nom']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="niveau_reins" class="form-label required-field">Nouveau Niveau</label>
                                    <select class="form-select" id="niveau_reins" name="niveau_reins" required>
                                        <option value="">Sélectionner un niveau</option>
                                        <?php foreach($niveaux as $niveau): ?>
                                        <option value="<?php echo $niveau['ordre']; ?>"><?php echo htmlspecialchars($niveau['libelle'] . ' (' . $niveau['cycle'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="montant_total_reins" class="form-label required-field">Montant Total (FCFA)</label>
                                    <input type="number" class="form-control" id="montant_total_reins" name="montant_total_reins" min="0" step="1000" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-redo me-2"></i>Réinscrire l'Étudiant
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
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
        
        // Initialiser DataTable
        $('#inscriptionsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [5] }
            ]
        });
        
        // Calculer l'âge automatiquement
        $('#date_naissance').on('change', function() {
            if (this.value) {
                const birthDate = new Date(this.value);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                
                // Afficher l'âge (optionnel)
                console.log("Âge calculé: " + age + " ans");
            }
        });
        
        // Gestion des checkboxes de documents
        const documentCheckboxes = document.querySelectorAll('.document-checkbox input[type="checkbox"]');
        documentCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const statusElement = document.getElementById(this.name + '_status');
                if (this.checked) {
                    statusElement.textContent = 'Fourni';
                    statusElement.className = 'document-status document-present';
                } else {
                    statusElement.textContent = 'Manquant';
                    statusElement.className = 'document-status document-missing';
                }
            });
            
            // Initialiser l'état
            const statusElement = document.getElementById(checkbox.name + '_status');
            if (checkbox.checked) {
                statusElement.textContent = 'Fourni';
                statusElement.className = 'document-status document-present';
            }
        });
        
        // Si des modales étaient ouvertes avant rechargement, les rouvrir
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('show') === 'inscription') {
            const modal = new bootstrap.Modal(document.getElementById('modalInscription'));
            modal.show();
        } else if (urlParams.get('show') === 'reinscription') {
            const modal = new bootstrap.Modal(document.getElementById('modalReinscription'));
            modal.show();
        }
    });
    
    // Fonction pour charger les infos de l'étudiant sélectionné
    function chargerInfosEtudiant(etudiantId) {
        if (!etudiantId) {
            document.getElementById('infoEtudiant').style.display = 'none';
            return;
        }
        
        fetch('get_etudiant_info.php?id=' + etudiantId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('matricule_info').textContent = data.matricule;
                    document.getElementById('nom_info').textContent = data.nom;
                    document.getElementById('prenom_info').textContent = data.prenom;
                    document.getElementById('cni_info').textContent = data.numero_cni;
                    document.getElementById('infoEtudiant').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors du chargement des informations de l\'étudiant');
            });
    }
    
    // Fonction pour pré-remplir le formulaire de réinscription
    function preRemplirReinscription(etudiantId, matricule, nom, prenom) {
        document.getElementById('etudiant_id').value = etudiantId;
        document.getElementById('matricule_info').textContent = matricule;
        document.getElementById('nom_info').textContent = nom;
        document.getElementById('prenom_info').textContent = prenom;
        document.getElementById('infoEtudiant').style.display = 'block';
        
        // Ouvrir le modal de réinscription
        const modal = new bootstrap.Modal(document.getElementById('modalReinscription'));
        modal.show();
    }
    
    // Validation du formulaire d'inscription
    document.querySelector('form[action=""]').addEventListener('submit', function(e) {
        const cni = document.getElementById('numero_cni').value;
        if (cni.length < 5) {
            e.preventDefault();
            alert('Le numéro CNI doit contenir au moins 5 caractères');
            return false;
        }
        
        const montant = document.getElementById('montant_total').value;
        if (montant < 10000) {
            e.preventDefault();
            alert('Le montant minimum d\'inscription est de 10,000 FCFA');
            return false;
        }
        
        return true;
    });
    </script>
</body>
</html>