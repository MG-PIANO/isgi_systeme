<?php
// dashboard/gestionnaire/paiements.php

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

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4)
if (!isset($_SESSION['role_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

// Configuration
$mois_annee_academique = 10; // L'année académique dure 10 mois

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Gestionnaire - Paiements";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    $user_id = $_SESSION['user_id'];
    $site_nom = '';
    
    // Récupérer le nom du site si assigné
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
    }
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'valide':
                return '<span class="badge bg-success">Validé</span>';
            case 'en_attente':
                return '<span class="badge bg-warning">En attente</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'rembourse':
                return '<span class="badge bg-info">Remboursé</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Fonction pour récupérer le montant global et le reste à payer
    function getMontantGlobalEtReste($db, $etudiant_id, $annee_academique_id, $site_id) {
        $result = [];
        
        // Chercher d'abord dans etudiant_options
        $query_liaison = "SELECT eo.option_id, eo.niveau_id 
                         FROM etudiant_options eo
                         WHERE eo.etudiant_id = ? AND eo.annee_academique_id = ? 
                         ORDER BY eo.date_inscription DESC LIMIT 1";
        
        $stmt = $db->prepare($query_liaison);
        $stmt->execute([$etudiant_id, $annee_academique_id]);
        $liaison = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($liaison && $liaison['option_id'] && $liaison['niveau_id']) {
            // Rechercher le tarif correspondant
            $query_tarif = "SELECT t.montant, o.nom as option_nom, n.libelle as niveau_libelle
                          FROM tarifs t
                          JOIN options_formation o ON t.option_id = o.id
                          JOIN niveaux n ON t.niveau_id = n.id
                          JOIN types_frais tf ON t.type_frais_id = tf.id
                          WHERE t.option_id = ? 
                          AND t.niveau_id = ?
                          AND t.annee_academique_id = ?
                          AND tf.nom LIKE '%scolarité%'
                          AND t.site_id = ?
                          LIMIT 1";
            
            $stmt = $db->prepare($query_tarif);
            $stmt->execute([
                $liaison['option_id'], 
                $liaison['niveau_id'], 
                $annee_academique_id, 
                $site_id
            ]);
            $tarif = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tarif) {
                $result['montant_global'] = $tarif['montant'] * 10; // 10 mois
                $result['option_nom'] = $tarif['option_nom'];
                $result['niveau_libelle'] = $tarif['niveau_libelle'];
            }
        }
        
        // Si pas de tarif trouvé, chercher dans les inscriptions
        if (!isset($result['montant_global'])) {
            $query_inscription = "SELECT i.montant_total, f.nom as filiere_nom, 
                                         n.libelle as niveau_libelle
                                  FROM inscriptions i
                                  JOIN filieres f ON i.filiere_id = f.id
                                  JOIN niveaux n ON i.niveau = n.id
                                  WHERE i.etudiant_id = ? 
                                  AND i.annee_academique_id = ?
                                  AND i.statut = 'validee'
                                  LIMIT 1";
            
            $stmt = $db->prepare($query_inscription);
            $stmt->execute([$etudiant_id, $annee_academique_id]);
            $inscription = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscription) {
                $result['montant_global'] = $inscription['montant_total'];
                $result['filiere_nom'] = $inscription['filiere_nom'];
                $result['niveau_libelle'] = $inscription['niveau_libelle'];
            } else {
                return null;
            }
        }
        
        // Calculer le total des paiements de scolarité
        $query_paiements = "SELECT SUM(p.montant) as total_paye
                           FROM paiements p
                           JOIN types_frais tf ON p.type_frais_id = tf.id
                           WHERE p.etudiant_id = ?
                           AND p.annee_academique_id = ?
                           AND tf.nom LIKE '%scolarité%'
                           AND p.statut = 'valide'";
        
        $stmt = $db->prepare($query_paiements);
        $stmt->execute([$etudiant_id, $annee_academique_id]);
        $paiements = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['total_paye'] = $paiements['total_paye'] ?? 0;
        $result['reste_a_payer'] = $result['montant_global'] - $result['total_paye'];
        
        return $result;
    }
    
    // Fonction pour récupérer la dette de l'étudiant (maintenant utilisée pour suggestion simple)
    function getDetteEtudiant($db, $etudiant_id, $annee_academique_id) {
        $query = "SELECT montant_du as montant_restant, montant_paye 
                  FROM dettes 
                  WHERE etudiant_id = ? 
                  AND annee_academique_id = ? 
                  AND statut = 'en_cours'
                  LIMIT 1";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$etudiant_id, $annee_academique_id]);
        
        $dette = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dette) {
            // Calculer le montant total (montant payé + restant)
            $dette['total_montant'] = $dette['montant_paye'] + $dette['montant_restant'];
            return $dette;
        }
        
        return null;
    }
    
    // Variables
    $error = null;
    $success = null;
    $paiements = array();
    $sites = array();
    $etudiants = array();
    $types_frais = array();
    $annees_academiques = array();
    $tarif_suggestion = null;
    $dette_etudiant = null;
    
    // Récupérer les sites
    if ($site_id) {
        $query = "SELECT * FROM sites WHERE id = ? AND statut = 'actif'";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sites = $db->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY ville")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Récupérer les étudiants actifs
    $query_etudiants = "SELECT id, matricule, nom, prenom, site_id FROM etudiants WHERE statut = 'actif'";
    if ($site_id) {
        $query_etudiants .= " AND site_id = ?";
        $stmt = $db->prepare($query_etudiants);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->query($query_etudiants);
    }
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les types de frais
    $types_frais = $db->query("SELECT * FROM types_frais ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les années académiques
    $annees_academiques = $db->query("SELECT * FROM annees_academiques ORDER BY date_debut DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_etudiant = isset($_GET['etudiant']) ? intval($_GET['etudiant']) : 0;
    $filtre_type_frais = isset($_GET['type_frais']) ? intval($_GET['type_frais']) : 0;
    $filtre_annee = isset($_GET['annee']) ? intval($_GET['annee']) : 0;
    $filtre_date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $filtre_date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    $filtre_reference = isset($_GET['reference']) ? trim($_GET['reference']) : '';
    $filtre_transaction = isset($_GET['transaction']) ? trim($_GET['transaction']) : '';
    
    // Traitement des actions (nouveau paiement, validation, annulation)
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $paiement_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    
    // TRAITEMENT DU FORMULAIRE DE NOUVEAU PAIEMENT
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'nouveau_paiement') {
        try {
            $db->beginTransaction();
            
            // Récupérer les données du formulaire
            $etudiant_id = intval($_POST['etudiant_id']);
            $type_frais_id = intval($_POST['type_frais_id']);
            $annee_academique_id = intval($_POST['annee_academique_id']);
            $montant = floatval($_POST['montant']);
            $mode_paiement = trim($_POST['mode_paiement']);
            $numero_transaction = trim($_POST['numero_transaction'] ?? '');
            $banque = trim($_POST['banque'] ?? '');
            $numero_cheque = trim($_POST['numero_cheque'] ?? '');
            $date_paiement = $_POST['date_paiement'];
            $commentaires = trim($_POST['commentaires'] ?? '');
            
            // Validation des données
            if ($montant <= 0) {
                throw new Exception("Le montant doit être supérieur à 0.");
            }
            
            if (empty($date_paiement)) {
                throw new Exception("La date de paiement est obligatoire.");
            }
            
            // Vérifier si l'étudiant existe
            $stmt = $db->prepare("SELECT id, matricule FROM etudiants WHERE id = ? AND statut = 'actif'");
            $stmt->execute([$etudiant_id]);
            $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$etudiant) {
                throw new Exception("Étudiant non trouvé ou inactif.");
            }
            
            // Vérifier si l'année académique existe
            $stmt = $db->prepare("SELECT id FROM annees_academiques WHERE id = ?");
            $stmt->execute([$annee_academique_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Année académique invalide.");
            }
            
            // Vérifier si le type de frais existe
            $stmt = $db->prepare("SELECT nom, montant_base FROM types_frais WHERE id = ?");
            $stmt->execute([$type_frais_id]);
            $type_frais_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$type_frais_info) {
                throw new Exception("Type de frais invalide.");
            }
            
            // Vérifier le montant par rapport au reste à payer (pour scolarité)
            if (stripos($type_frais_info['nom'], 'scolarité') !== false) {
                // Récupérer le montant global et le reste
                $montant_info = getMontantGlobalEtReste($db, $etudiant_id, $annee_academique_id, $site_id);
                
                if ($montant_info && $montant_info['reste_a_payer'] > 0) {
                    if ($montant > $montant_info['reste_a_payer']) {
                        throw new Exception("Le montant dépasse le reste à payer (" . formatMoney($montant_info['reste_a_payer']) . ")");
                    }
                }
            }
            
            // Générer une référence unique
            $prefixe = strtoupper(substr($type_frais_info['nom'], 0, 3));
            $date = date('ym');
            $sequence_query = "SELECT COUNT(*) as count FROM paiements WHERE reference LIKE ?";
            $stmt = $db->prepare($sequence_query);
            $stmt->execute([$prefixe . $date . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $sequence = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
            $reference = $prefixe . $date . $sequence;
            
            // Calculer les frais de transaction pour mobile money
            $frais_transaction = 0;
            if (in_array($mode_paiement, ['Airtel Money', 'MTN Mobile Money'])) {
                $frais_transaction = $montant * 0.015; // 1.5%
            }
            $montant_net = $montant - $frais_transaction;
            
            // Insérer le paiement
            $query_paiement = "INSERT INTO paiements (
                etudiant_id, type_frais_id, annee_academique_id, reference, 
                montant, frais_transaction, mode_paiement, numero_transaction,
                banque, numero_cheque, date_paiement, caissier_id, statut, 
                commentaires, date_creation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'valide', ?, NOW())";
            
            $stmt = $db->prepare($query_paiement);
            $stmt->execute([
                $etudiant_id, $type_frais_id, $annee_academique_id, $reference,
                $montant, $frais_transaction, $mode_paiement, $numero_transaction,
                $banque, $numero_cheque, $date_paiement, $user_id, $commentaires
            ]);
            
            $paiement_id = $db->lastInsertId();
            
            // Mettre à jour la dette de l'étudiant si c'est un paiement de scolarité
            if (stripos($type_frais_info['nom'], 'scolarité') !== false) {
                // Vérifier s'il existe une dette pour cette année
                $stmt = $db->prepare("SELECT id, montant_du, montant_paye FROM dettes WHERE etudiant_id = ? AND annee_academique_id = ?");
                $stmt->execute([$etudiant_id, $annee_academique_id]);
                $dette = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dette) {
                    $nouveau_montant_paye = $dette['montant_paye'] + $montant_net;
                    $nouveau_reste = $dette['montant_du'] - $nouveau_montant_paye;
                    if ($nouveau_reste < 0) $nouveau_reste = 0;
                    
                    $stmt = $db->prepare("UPDATE dettes SET 
                        montant_paye = ?, 
                        montant_restant = ?,
                        statut = ?,
                        date_maj = NOW()
                        WHERE id = ?");
                    $stmt->execute([
                        $nouveau_montant_paye,
                        $nouveau_reste,
                        $nouveau_reste > 0 ? 'en_cours' : 'soldee',
                        $dette['id']
                    ]);
                } else {
                    // Récupérer le montant global
                    $montant_info = getMontantGlobalEtReste($db, $etudiant_id, $annee_academique_id, $site_id);
                    $montant_total = $montant_info ? $montant_info['montant_global'] : $montant * 10;
                    
                    $stmt = $db->prepare("INSERT INTO dettes (
                        etudiant_id, annee_academique_id, montant_du,
                        montant_paye, montant_restant, statut, date_creation, date_maj
                    ) VALUES (?, ?, ?, ?, ?, 'en_cours', NOW(), NOW())");
                    $stmt->execute([
                        $etudiant_id, $annee_academique_id, $montant_total,
                        $montant_net, ($montant_total - $montant_net)
                    ]);
                }
            }
            
            // Enregistrer dans l'historique
            $query_historique = "INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée, 
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'nouveau_paiement', 'paiements', ?, ?, NOW())";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $user_id,
                $paiement_id,
                "Nouveau paiement: " . $etudiant['matricule'] . " - " . formatMoney($montant)
            ]);
            
            $db->commit();
            $success = "Paiement enregistré avec succès ! Référence : <strong>$reference</strong>";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de l'enregistrement du paiement: " . $e->getMessage();
        }
    }
    
    // Traitement des actions de validation/annulation
    if ($action == 'valider' && $paiement_id > 0) {
        $stmt = $db->prepare("UPDATE paiements SET statut = 'valide', date_validation = NOW() WHERE id = ? AND statut = 'en_attente'");
        $stmt->execute([$paiement_id]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Paiement validé avec succès !";
        } else {
            $error = "Impossible de valider ce paiement.";
        }
    } elseif ($action == 'annuler' && $paiement_id > 0) {
        $motif = isset($_POST['motif']) ? trim($_POST['motif']) : 'Non spécifié';
        
        $stmt = $db->prepare("UPDATE paiements SET statut = 'annule', date_annulation = NOW(), motif_annulation = ? WHERE id = ?");
        $stmt->execute([$motif, $paiement_id]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Paiement annulé avec succès !";
        } else {
            $error = "Impossible d'annuler ce paiement.";
        }
    }
    
    // Requête pour récupérer les paiements
    $query = "SELECT p.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
              tf.nom as type_frais_nom,
              aa.libelle as annee_academique,
              s.nom as site_nom,
              CONCAT(u.nom, ' ', u.prenom) as caissier_nom
              FROM paiements p
              INNER JOIN etudiants e ON p.etudiant_id = e.id
              INNER JOIN types_frais tf ON p.type_frais_id = tf.id
              INNER JOIN annees_academiques aa ON p.annee_academique_id = aa.id
              INNER JOIN sites s ON e.site_id = s.id
              LEFT JOIN utilisateurs u ON p.caissier_id = u.id
              WHERE 1=1";
    
    $params = array();
    
    // Filtrer par site
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $params[] = $site_id;
    } elseif ($filtre_site > 0) {
        $query .= " AND e.site_id = ?";
        $params[] = $filtre_site;
    }
    
    if (!empty($filtre_statut)) {
        $query .= " AND p.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if ($filtre_etudiant > 0) {
        $query .= " AND p.etudiant_id = ?";
        $params[] = $filtre_etudiant;
    }
    
    if ($filtre_type_frais > 0) {
        $query .= " AND p.type_frais_id = ?";
        $params[] = $filtre_type_frais;
    }
    
    if ($filtre_annee > 0) {
        $query .= " AND p.annee_academique_id = ?";
        $params[] = $filtre_annee;
    }
    
    if (!empty($filtre_date_debut)) {
        $query .= " AND p.date_paiement >= ?";
        $params[] = $filtre_date_debut;
    }
    
    if (!empty($filtre_date_fin)) {
        $query .= " AND p.date_paiement <= ?";
        $params[] = $filtre_date_fin;
    }
    
    if (!empty($filtre_reference)) {
        $query .= " AND p.reference LIKE ?";
        $params[] = '%' . $filtre_reference . '%';
    }
    
    if (!empty($filtre_transaction)) {
        $query .= " AND p.numero_transaction LIKE ?";
        $params[] = '%' . $filtre_transaction . '%';
    }
    
    $query .= " ORDER BY p.date_paiement DESC, p.date_creation DESC";
    
    // Exécuter la requête
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer les statistiques
    $stats = [
        'total_paiements' => 0,
        'total_montant' => 0,
        'total_frais' => 0,
        'total_net' => 0,
        'par_statut' => [],
        'par_mois' => []
    ];
    
    foreach ($paiements as $paiement) {
        $stats['total_paiements']++;
        $stats['total_montant'] += $paiement['montant'];
        $stats['total_frais'] += $paiement['frais_transaction'] ?? 0;
        $stats['total_net'] += $paiement['montant'] - ($paiement['frais_transaction'] ?? 0);
        
        // Par statut
        $statut = $paiement['statut'];
        if (!isset($stats['par_statut'][$statut])) {
            $stats['par_statut'][$statut] = ['count' => 0, 'montant' => 0];
        }
        $stats['par_statut'][$statut]['count']++;
        $stats['par_statut'][$statut]['montant'] += $paiement['montant'];
        
        // Par mois
        $mois = date('Y-m', strtotime($paiement['date_paiement']));
        if (!isset($stats['par_mois'][$mois])) {
            $stats['par_mois'][$mois] = ['count' => 0, 'montant' => 0];
        }
        $stats['par_mois'][$mois]['count']++;
        $stats['par_mois'][$mois]['montant'] += $paiement['montant'];
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur paiements.php: " . $e->getMessage());
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
    
    .form-section {
        background: rgba(0, 0, 0, 0.02);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        border-left: 4px solid var(--primary-color);
    }
    
    .required-field::after {
        content: " *";
        color: var(--accent-color);
    }
    
    /* Calculatrice pour paiements échelonnés */
    .calculatrice-paiement {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
    
    .paiement-option {
        cursor: pointer;
        transition: all 0.3s;
        border: 2px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        text-align: center;
    }
    
    .paiement-option:hover {
        border-color: var(--primary-color);
        background: rgba(52, 152, 219, 0.05);
    }
    
    .paiement-option.active {
        border-color: var(--primary-color);
        background: rgba(52, 152, 219, 0.1);
    }
    
    .echeance-item {
        padding: 8px 12px;
        margin-bottom: 5px;
        background: rgba(52, 152, 219, 0.1);
        border-radius: 4px;
        font-size: 0.9rem;
        border-left: 3px solid var(--primary-color);
    }
    
    .resultat-calc {
        font-size: 1.1rem;
        padding: 10px;
        background: rgba(52, 152, 219, 0.1);
        border-radius: 5px;
        text-align: center;
    }
    
    /* Nouveaux styles pour paiements */
    .modalite-badge {
        font-size: 0.7rem;
        padding: 3px 8px;
        border-radius: 12px;
    }
    
    .annuel-badge {
        background-color: #28a745;
        color: white;
    }
    
    .semestriel-badge {
        background-color: #17a2b8;
        color: white;
    }
    
    .trimestriel-badge {
        background-color: #ffc107;
        color: #212529;
    }
    
    .mensuel-badge {
        background-color: #6f42c1;
        color: white;
    }
    
    .echeance-badge {
        background-color: #6c757d;
        color: white;
        font-size: 0.7rem;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 5px;
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
    
    /* Styles spécifiques pour les paiements */
    .paiement-card {
        transition: all 0.3s ease;
    }
    
    .paiement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .mobile-money-icon {
        color: #FF6B00;
    }
    
    .mtn-money-icon {
        color: #FFCC00;
    }
    
    .espece-icon {
        color: #28a745;
    }
    
    .virement-icon {
        color: #007bff;
    }
    
    .cheque-icon {
        color: #6c757d;
    }
    
    .mode-paiement-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
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
                    <div class="nav-section-title">Gestion Financière</div>
                    <a href="paiements.php" class="nav-link active">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Dettes Étudiants</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-receipt"></i>
                        <span>Factures</span>
                    </a>
                    <a href="tarifs.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        <span>Tarifs & Frais</span>
                    </a>
                    <a href="rapports.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports Financiers</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Étudiants</span>
                    </a>
                    <a href="inscriptions.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        <span>Inscriptions</span>
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
                            <i class="fas fa-money-bill-wave me-2"></i>
                            Gestion des Paiements
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="quick-actions">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPaiement">
                            <i class="fas fa-plus-circle"></i> Nouveau Paiement
                        </button>
                        <button type="button" class="btn btn-primary" onclick="imprimerListe()">
                            <i class="fas fa-print"></i> Imprimer Liste
                        </button>
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalStats">
                            <i class="fas fa-chart-line"></i> Statistiques
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
                                    <?php echo htmlspecialchars($site['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label for="etudiant" class="form-label">Étudiant</label>
                            <select class="form-select" id="etudiant" name="etudiant">
                                <option value="0">Tous les étudiants</option>
                                <?php foreach($etudiants as $etudiant): ?>
                                <option value="<?php echo $etudiant['id']; ?>" <?php echo $filtre_etudiant == $etudiant['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="type_frais" class="form-label">Type de frais</label>
                            <select class="form-select" id="type_frais" name="type_frais">
                                <option value="0">Tous les types</option>
                                <?php foreach($types_frais as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo $filtre_type_frais == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="valide" <?php echo $filtre_statut == 'valide' ? 'selected' : ''; ?>>Validé</option>
                                <option value="en_attente" <?php echo $filtre_statut == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="annule" <?php echo $filtre_statut == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="date_debut" class="form-label">Date début</label>
                            <input type="date" class="form-control" id="date_debut" name="date_debut" value="<?php echo htmlspecialchars($filtre_date_debut); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="date_fin" class="form-label">Date fin</label>
                            <input type="date" class="form-control" id="date_fin" name="date_fin" value="<?php echo htmlspecialchars($filtre_date_fin); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" class="form-control" id="reference" name="reference" value="<?php echo htmlspecialchars($filtre_reference); ?>" placeholder="Référence paiement">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="transaction" class="form-label">Numéro transaction</label>
                            <input type="text" class="form-control" id="transaction" name="transaction" value="<?php echo htmlspecialchars($filtre_transaction); ?>" placeholder="Numéro transaction">
                        </div>
                        
                        <div class="col-md-12">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Rechercher
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || $filtre_etudiant > 0 || $filtre_type_frais > 0 || !empty($filtre_date_debut) || !empty($filtre_date_fin) || !empty($filtre_reference) || !empty($filtre_transaction)): ?>
                                <a href="paiements.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Réinitialiser
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques rapides -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3><?php echo $stats['total_paiements']; ?></h3>
                            <p class="text-muted mb-0">Paiements</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <h3><?php echo formatMoney($stats['total_montant']); ?></h3>
                            <p class="text-muted mb-0">Montant total</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <h3><?php echo formatMoney($stats['total_frais']); ?></h3>
                            <p class="text-muted mb-0">Frais transaction</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-info stats-icon">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <h3><?php echo formatMoney($stats['total_net']); ?></h3>
                            <p class="text-muted mb-0">Montant net</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Liste des paiements -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Paiements
                    </h5>
                    <div class="text-muted">
                        <?php echo count($paiements); ?> paiement(s) trouvé(s)
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($paiements)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Aucun paiement trouvé avec les critères sélectionnés
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="paiementsTable">
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Étudiant</th>
                                    <th>Type & Montant</th>
                                    <th>Mode Paiement</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th>Caissier</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($paiements as $paiement): 
                                $mode_icon = '';
                                $mode_class = '';
                                
                                // Icône mode de paiement
                                switch($paiement['mode_paiement']) {
                                    case 'Airtel Money':
                                        $mode_icon = 'fas fa-mobile-alt mobile-money-icon';
                                        $mode_class = 'bg-warning text-dark';
                                        break;
                                    case 'MTN Mobile Money':
                                        $mode_icon = 'fas fa-mobile-alt mtn-money-icon';
                                        $mode_class = 'bg-warning text-dark';
                                        break;
                                    case 'Espèces':
                                        $mode_icon = 'fas fa-money-bill espece-icon';
                                        $mode_class = 'bg-success text-white';
                                        break;
                                    case 'Virement bancaire':
                                        $mode_icon = 'fas fa-university virement-icon';
                                        $mode_class = 'bg-primary text-white';
                                        break;
                                    case 'Chèque':
                                        $mode_icon = 'fas fa-file-invoice-dollar cheque-icon';
                                        $mode_class = 'bg-secondary text-white';
                                        break;
                                    default:
                                        $mode_icon = 'fas fa-credit-card';
                                        $mode_class = 'bg-info text-white';
                                }
                                
                                // Récupérer le montant global et le reste (pour scolarité)
                                $montant_info = null;
                                if (stripos($paiement['type_frais_nom'], 'scolarité') !== false) {
                                    $montant_info = getMontantGlobalEtReste($db, $paiement['etudiant_id'], $paiement['annee_academique_id'], $site_id);
                                }
                                ?>
                                <tr class="paiement-card">
                                    <td>
                                        <strong><?php echo htmlspecialchars($paiement['reference']); ?></strong>
                                        <?php if($paiement['numero_transaction']): ?>
                                        <br><small class="text-muted">Trans: <?php echo htmlspecialchars($paiement['numero_transaction']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <i class="fas fa-user-graduate"></i>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($paiement['etudiant_prenom'] . ' ' . $paiement['etudiant_nom']); ?></strong>
                                                <div class="text-muted small">
                                                    <?php echo htmlspecialchars($paiement['matricule']); ?>
                                                </div>
                                                <div class="small">
                                                    <i class="fas fa-school"></i> <?php echo htmlspecialchars($paiement['site_nom']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($paiement['type_frais_nom']); ?></strong>
                                            <div class="mt-2">
                                                <span class="badge bg-dark montant-badge">
                                                    <?php echo formatMoney($paiement['montant']); ?>
                                                </span>
                                                <?php if($paiement['frais_transaction'] > 0): ?>
                                                <small class="text-danger d-block mt-1">
                                                    Frais: <?php echo formatMoney($paiement['frais_transaction']); ?>
                                                </small>
                                                <?php endif; ?>
                                                <?php if($montant_info): ?>
                                                <small class="text-muted d-block mt-1">
                                                    Global: <?php echo formatMoney($montant_info['montant_global']); ?> | 
                                                    Reste: <?php echo formatMoney($montant_info['reste_a_payer']); ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="mode-paiement-badge <?php echo $mode_class; ?>">
                                            <i class="<?php echo $mode_icon; ?>"></i>
                                            <?php echo htmlspecialchars($paiement['mode_paiement']); ?>
                                        </span>
                                        <?php if($paiement['banque']): ?>
                                        <div class="small mt-1">
                                            <?php echo htmlspecialchars($paiement['banque']); ?>
                                            <?php if($paiement['numero_cheque']): ?>
                                            <br>Chèque: <?php echo htmlspecialchars($paiement['numero_cheque']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($paiement['date_paiement'])); ?>
                                        <div class="small text-muted">
                                            Année: <?php echo htmlspecialchars($paiement['annee_academique']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo getStatutBadge($paiement['statut']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($paiement['caissier_nom'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" title="Voir détails" onclick="voirDetails(<?php echo $paiement['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-primary" title="Imprimer reçu" onclick="imprimerRecu(<?php echo $paiement['id']; ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <?php if($paiement['statut'] == 'en_attente'): ?>
                                            <a href="paiements.php?action=valider&id=<?php echo $paiement['id']; ?>" 
                                               class="btn btn-success" title="Valider" onclick="return confirm('Valider ce paiement ?')">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <button class="btn btn-danger" title="Annuler" onclick="annulerPaiement(<?php echo $paiement['id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
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
    
    <!-- Modal pour nouveau paiement -->
    <div class="modal fade" id="modalPaiement" tabindex="-1" aria-labelledby="modalPaiementLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalPaiementLabel">
                        <i class="fas fa-money-bill-wave me-2"></i>Nouveau Paiement
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="nouveau_paiement">
                        
                        <div class="form-section">
                            <h6><i class="fas fa-user-graduate me-2"></i>Étudiant et Type de Paiement</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="etudiant_id" class="form-label required-field">Étudiant</label>
                                    <select class="form-select" id="etudiant_id" name="etudiant_id" required onchange="chargerSuggestions()">
                                        <option value="">Sélectionner un étudiant</option>
                                        <?php foreach($etudiants as $etudiant): ?>
                                        <option value="<?php echo $etudiant['id']; ?>">
                                            <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="type_frais_id" class="form-label required-field">Type de frais</label>
                                    <select class="form-select" id="type_frais_id" name="type_frais_id" required onchange="chargerSuggestions()">
                                        <option value="">Sélectionner un type</option>
                                        <?php foreach($types_frais as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" data-montant="<?php echo $type['montant_base']; ?>">
                                            <?php echo htmlspecialchars($type['nom'] . ' (' . formatMoney($type['montant_base']) . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="annee_academique_id" class="form-label required-field">Année académique</label>
                                    <select class="form-select" id="annee_academique_id" name="annee_academique_id" required onchange="chargerSuggestions()">
                                        <?php foreach($annees_academiques as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['libelle']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="montant" class="form-label required-field">Montant (FCFA)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="montant" name="montant" min="0" step="100" required oninput="calculerFrais(); calculerOptionsPaiement()">
                                        <span class="input-group-text">FCFA</span>
                                    </div>
                                    <div id="suggestions" class="mt-2"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="remplirMontantStandard()">
                                        <i class="fas fa-dollar-sign"></i> Remplir montant standard
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section calculatrice pour paiements échelonnés -->
                        <div id="calculatriceSection" style="display: none;">
                            <div class="calculatrice-paiement">
                                <h6><i class="fas fa-calculator me-2"></i>Options de Paiement Échelonné</h6>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <div class="paiement-option active" id="optionAnnuel" onclick="selectOption('annuel')">
                                            <div class="text-center">
                                                <h5 class="text-success">Annuel</h5>
                                                <div class="fs-4 fw-bold" id="montantAnnuel">-</div>
                                                <small>1 paiement</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="paiement-option" id="optionSemestriel" onclick="selectOption('semestriel')">
                                            <div class="text-center">
                                                <h5 class="text-primary">Semestriel</h5>
                                                <div class="fs-4 fw-bold" id="montantSemestre">-</div>
                                                <small>2 paiements</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="paiement-option" id="optionTrimestriel" onclick="selectOption('trimestriel')">
                                            <div class="text-center">
                                                <h5 class="text-warning">Trimestriel</h5>
                                                <div class="fs-4 fw-bold" id="montantTrimestre">-</div>
                                                <small>4 paiements</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="paiement-option" id="optionMensuel" onclick="selectOption('mensuel')">
                                            <div class="text-center">
                                                <h5 class="text-info">Mensuel</h5>
                                                <div class="fs-4 fw-bold" id="montantMensuel">-</div>
                                                <small>10 paiements</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Détails des mensualités -->
                                <div class="mt-4" id="detailsPaiement">
                                    <h6>Détails du paiement : <span id="typePaiementSelectionne">Annuel</span></h6>
                                    <div class="row" id="listeEcheances">
                                        <!-- Les échéances seront affichées ici -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-credit-card me-2"></i>Mode de Paiement</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="mode_paiement" class="form-label required-field">Mode de paiement</label>
                                    <select class="form-select" id="mode_paiement" name="mode_paiement" required onchange="afficherChampsSupp(); calculerFrais()">
                                        <option value="">Sélectionner un mode</option>
                                        <option value="Espèces">Espèces</option>
                                        <option value="Airtel Money">Airtel Money</option>
                                        <option value="MTN Mobile Money">MTN Mobile Money</option>
                                        <option value="Virement bancaire">Virement bancaire</option>
                                        <option value="Chèque">Chèque</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="date_paiement" class="form-label required-field">Date de paiement</label>
                                    <input type="date" class="form-control" id="date_paiement" name="date_paiement" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <!-- Champs Mobile Money -->
                                <div class="col-md-12 mt-3" id="mobileMoneyFields" style="display: none;">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Pour les paiements Mobile Money, veuillez vérifier la transaction sur votre téléphone.
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <label for="numero_transaction" class="form-label required-field">Numéro de transaction</label>
                                            <input type="text" class="form-control" id="numero_transaction" name="numero_transaction" placeholder="Ex: 7A2B4C8D9E">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Champs Virement/Chèque -->
                                <div class="col-md-12 mt-3" id="bankFields" style="display: none;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label for="banque" class="form-label required-field">Banque</label>
                                            <input type="text" class="form-control" id="banque" name="banque" placeholder="Nom de la banque">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="numero_cheque" class="form-label">Numéro de chèque</label>
                                            <input type="text" class="form-control" id="numero_cheque" name="numero_cheque" placeholder="Si paiement par chèque">
                                        </div>
                                        <div class="col-md-12">
                                            <label for="numero_transaction" class="form-label required-field">Référence du virement</label>
                                            <input type="text" class="form-control" id="numero_transaction" name="numero_transaction" placeholder="Référence du virement bancaire">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Champs Espèces -->
                                <div class="col-md-12 mt-3" id="cashFields" style="display: none;">
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Pour les paiements en espèces, veuillez remettre l'argent à la caisse et conserver le reçu.
                                    </div>
                                </div>
                                
                                <!-- Calcul des frais -->
                                <div class="col-md-12 mt-3" id="fraisCalcul">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Calcul des frais</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <p class="mb-1"><strong>Montant principal:</strong></p>
                                                    <p id="montantPrincipal">0 FCFA</p>
                                                </div>
                                                <div class="col-md-4">
                                                    <p class="mb-1"><strong>Frais de transaction:</strong></p>
                                                    <p id="fraisTransaction">0 FCFA</p>
                                                </div>
                                                <div class="col-md-4">
                                                    <p class="mb-1"><strong>Montant net:</strong></p>
                                                    <p id="montantNet" class="fw-bold">0 FCFA</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-comment me-2"></i>Commentaires</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <textarea class="form-control" id="commentaires" name="commentaires" rows="3" placeholder="Informations complémentaires sur le paiement..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Enregistrer le Paiement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour annulation -->
    <div class="modal fade" id="modalAnnulation" tabindex="-1" aria-labelledby="modalAnnulationLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="modalAnnulationLabel">
                        <i class="fas fa-times-circle me-2"></i>Annuler un Paiement
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="formAnnulation">
                    <input type="hidden" name="action" value="annuler">
                    <input type="hidden" name="id" id="annulation_paiement_id">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Attention ! Cette action est irréversible.
                        </div>
                        <div class="mb-3">
                            <label for="motif" class="form-label required-field">Motif d'annulation</label>
                            <textarea class="form-control" id="motif" name="motif" rows="3" required placeholder="Veuillez indiquer le motif de l'annulation..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Confirmer l'annulation
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
    // Configuration
    const MOIS_ANNEE_ACADEMIQUE = 10;
    
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('isgi_theme', newTheme);
        
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
        const theme = localStorage.getItem('isgi_theme') || 'light';
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
        $('#paiementsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
            },
            pageLength: 10,
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7] }
            ]
        });
        
        // Initialiser les calculs
        calculerFrais();
        calculerOptionsPaiement();
    });
    
    // Formater un nombre avec séparateurs de milliers
    function formatNombre(nombre) {
        return new Intl.NumberFormat('fr-FR').format(Math.round(nombre));
    }
    
    // Charger les suggestions basées sur l'étudiant et le type de frais
    function chargerSuggestions() {
        const etudiantId = document.getElementById('etudiant_id').value;
        const typeFraisId = document.getElementById('type_frais_id').value;
        const anneeId = document.getElementById('annee_academique_id').value;
        const montantInput = document.getElementById('montant');
        const suggestionsDiv = document.getElementById('suggestions');
        const calculatriceSection = document.getElementById('calculatriceSection');
        
        suggestionsDiv.innerHTML = '';
        
        if (etudiantId && typeFraisId && anneeId) {
            // Récupérer le type de frais sélectionné
            const typeFraisSelect = document.getElementById('type_frais_id');
            const selectedOption = typeFraisSelect.options[typeFraisSelect.selectedIndex];
            const typeFraisNom = selectedOption.text.toLowerCase();
            
            // Charger les informations financières de l'étudiant via AJAX
            fetch('get_finances_etudiant.php?etudiant_id=' + etudiantId + '&annee_id=' + anneeId)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        suggestionsDiv.innerHTML = `
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                ${data.error}
                            </div>
                        `;
                    } else {
                        let html = '';
                        
                        // Afficher les informations générales
                        html += `
                            <div class="alert alert-info">
                                <i class="fas fa-user-graduate"></i>
                                <strong>${data.nom_complet}</strong> (${data.matricule})<br>
                                ${data.option_nom || data.filiere_nom || 'Non spécifié'} | 
                                Niveau: ${data.niveau_libelle || 'Non spécifié'}
                            </div>
                        `;
                        
                        // Si c'est un paiement de scolarité
                        if (typeFraisNom.includes('scolarité')) {
                            calculatriceSection.style.display = 'block';
                            
                            if (data.montant_global > 0) {
                                html += `
                                    <div class="alert alert-primary">
                                        <i class="fas fa-calculator"></i>
                                        <strong>Montant global annuel:</strong> ${formatNombre(data.montant_global)} FCFA<br>
                                        <strong>Déjà payé:</strong> ${formatNombre(data.total_paye)} FCFA<br>
                                        <strong>Reste à payer:</strong> ${formatNombre(data.reste_a_payer)} FCFA
                                        ${data.mois_actuel ? `<br><small>Mois ${data.mois_actuel}/10 de l'année académique</small>` : ''}
                                    </div>
                                `;
                                
                                if (data.reste_a_payer > 0) {
                                    html += `
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <button type="button" class="btn btn-sm btn-primary" onclick="remplirMontantReste(${data.reste_a_payer})">
                                                <i class="fas fa-dollar-sign"></i> Reste à payer (${formatNombre(data.reste_a_payer)} FCFA)
                                            </button>
                                            <button type="button" class="btn btn-sm btn-success" onclick="remplirMensualite(${data.mensualite_suggestee})">
                                                <i class="fas fa-calendar-alt"></i> Mensualité (${formatNombre(data.mensualite_suggestee)} FCFA)
                                            </button>
                                            <button type="button" class="btn btn-sm btn-warning" onclick="remplirMontantGlobal(${data.montant_global})">
                                                <i class="fas fa-money-bill-wave"></i> Montant global (${formatNombre(data.montant_global)} FCFA)
                                            </button>
                                        </div>
                                    `;
                                } else {
                                    html += `
                                        <div class="alert alert-success mt-2">
                                            <i class="fas fa-check-circle"></i> La scolarité est entièrement payée pour cette année !
                                        </div>
                                    `;
                                }
                            } else {
                                html += `
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Aucun montant global défini pour cet étudiant.
                                    </div>
                                `;
                            }
                        } else {
                            calculatriceSection.style.display = 'none';
                        }
                        
                        suggestionsDiv.innerHTML = html;
                        
                        // Mettre à jour la calculatrice
                        if (typeFraisNom.includes('scolarité') && data.montant_global > 0) {
                            calculerOptionsPaiementAvecMontant(data.montant_global);
                        }
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    suggestionsDiv.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Impossible de charger les informations financières de l'étudiant
                        </div>
                    `;
                });
        } else {
            calculatriceSection.style.display = 'none';
        }
    }
    
    // Remplir avec le reste à payer
    function remplirMontantReste(montant) {
        document.getElementById('montant').value = montant;
        calculerFrais();
        calculerOptionsPaiement();
    }
    
    // Remplir avec la mensualité suggérée
    function remplirMensualite(montant) {
        document.getElementById('montant').value = montant;
        calculerFrais();
        calculerOptionsPaiement();
    }
    
    // Remplir avec le montant global
    function remplirMontantGlobal(montant) {
        document.getElementById('montant').value = montant;
        calculerFrais();
        calculerOptionsPaiement();
    }
    
    // Remplir le montant standard selon le type de frais
    function remplirMontantStandard() {
        const typeFraisSelect = document.getElementById('type_frais_id');
        const selectedOption = typeFraisSelect.options[typeFraisSelect.selectedIndex];
        
        if (selectedOption.value) {
            const montant = selectedOption.getAttribute('data-montant');
            document.getElementById('montant').value = montant;
            calculerFrais();
            calculerOptionsPaiement();
        } else {
            alert('Veuillez d\'abord sélectionner un type de frais');
        }
    }
    
    // Calculer les options de paiement avec un montant spécifique
    function calculerOptionsPaiementAvecMontant(montantGlobal) {
        const montantMensuel = Math.round(montantGlobal / 10);
        const montantSemestre = Math.round(montantGlobal / 2);
        const montantTrimestre = Math.round(montantGlobal / 4);
        
        document.getElementById('montantAnnuel').textContent = formatNombre(montantGlobal) + ' FCFA';
        document.getElementById('montantSemestre').textContent = formatNombre(montantSemestre) + ' FCFA';
        document.getElementById('montantTrimestre').textContent = formatNombre(montantTrimestre) + ' FCFA';
        document.getElementById('montantMensuel').textContent = formatNombre(montantMensuel) + ' FCFA';
        
        // Afficher les détails de l'option sélectionnée
        const optionActive = document.querySelector('.paiement-option.active').id;
        const typePaiement = optionActive.replace('option', '').toLowerCase();
        afficherDetailsPaiement(typePaiement, montantGlobal);
    }
    
    // Calculer toutes les options de paiement
    function calculerOptionsPaiement() {
        const montant = parseFloat(document.getElementById('montant').value) || 0;
        
        if (montant <= 0) {
            // Cacher la section calculatrice si pas de montant
            document.getElementById('calculatriceSection').style.display = 'none';
            return;
        }
        
        // Calculer les montants pour chaque option
        const montantAnnuel = montant;
        const montantSemestre = Math.round(montant / 2);
        const montantTrimestre = Math.round(montant / 4);
        const montantMensuel = Math.round(montant / MOIS_ANNEE_ACADEMIQUE);
        
        // Mettre à jour l'affichage
        document.getElementById('montantAnnuel').textContent = formatNombre(montantAnnuel) + ' FCFA';
        document.getElementById('montantSemestre').textContent = formatNombre(montantSemestre) + ' FCFA';
        document.getElementById('montantTrimestre').textContent = formatNombre(montantTrimestre) + ' FCFA';
        document.getElementById('montantMensuel').textContent = formatNombre(montantMensuel) + ' FCFA';
        
        // Afficher les détails de l'option sélectionnée
        const optionActive = document.querySelector('.paiement-option.active').id;
        const typePaiement = optionActive.replace('option', '').toLowerCase();
        afficherDetailsPaiement(typePaiement, montant);
    }
    
    // Sélectionner une option de paiement
    function selectOption(type) {
        // Retirer la classe active de toutes les options
        document.querySelectorAll('.paiement-option').forEach(option => {
            option.classList.remove('active');
        });
        
        // Ajouter la classe active à l'option sélectionnée
        document.getElementById('option' + type.charAt(0).toUpperCase() + type.slice(1)).classList.add('active');
        
        // Afficher les détails
        const montant = parseFloat(document.getElementById('montant').value) || 0;
        afficherDetailsPaiement(type, montant);
    }
    
    // Afficher les détails du paiement
    function afficherDetailsPaiement(type, montantTotal) {
        let detailsHTML = '';
        let typeTexte = '';
        
        switch(type) {
            case 'annuel':
                typeTexte = 'Annuel (1 paiement)';
                detailsHTML = `
                    <div class="col-12">
                        <div class="echeance-item">
                            <div class="d-flex justify-content-between">
                                <span>Paiement unique</span>
                                <strong>${formatNombre(montantTotal)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début d'année académique</small>
                        </div>
                    </div>
                `;
                break;
                
            case 'semestriel':
                typeTexte = 'Semestriel (2 paiements)';
                const montantSemestre = Math.round(montantTotal / 2);
                detailsHTML = `
                    <div class="col-md-6">
                        <div class="echeance-item">
                            <div class="d-flex justify-content-between">
                                <span>1er semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début de 1er semestre</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="echeance-item">
                            <div class="d-flex justify-content-between">
                                <span>2ème semestre (5 mois)</span>
                                <strong>${formatNombre(montantSemestre)} FCFA</strong>
                            </div>
                            <small class="text-muted">À payer en début de 2ème semestre</small>
                        </div>
                    </div>
                `;
                break;
                
            case 'trimestriel':
                typeTexte = 'Trimestriel (4 paiements)';
                const montantTrimestre = Math.round(montantTotal / 4);
                const moisParTrimestre = [3, 3, 3, 1]; // 3+3+3+1 = 10 mois
                
                for (let i = 0; i < 4; i++) {
                    detailsHTML += `
                        <div class="col-md-3 col-6">
                            <div class="echeance-item">
                                <div class="d-flex justify-content-between">
                                    <span>Trimestre ${i+1} (${moisParTrimestre[i]} mois)</span>
                                    <strong>${formatNombre(montantTrimestre)} FCFA</strong>
                                </div>
                                <small class="text-muted">À payer en début de trimestre</small>
                            </div>
                        </div>
                    `;
                }
                break;
                
            case 'mensuel':
                typeTexte = 'Mensuel (10 paiements)';
                const montantMensuel = Math.round(montantTotal / MOIS_ANNEE_ACADEMIQUE);
                
                for (let i = 0; i < MOIS_ANNEE_ACADEMIQUE; i++) {
                    detailsHTML += `
                        <div class="col-md-2 col-4">
                            <div class="echeance-item">
                                <div class="d-flex justify-content-between">
                                    <span>Mois ${i+1}</span>
                                    <strong>${formatNombre(montantMensuel)} FCFA</strong>
                                </div>
                                <small class="text-muted">Fin du mois</small>
                            </div>
                        </div>
                    `;
                }
                break;
        }
        
        document.getElementById('typePaiementSelectionne').textContent = typeTexte;
        document.getElementById('listeEcheances').innerHTML = detailsHTML;
    }
    
    // Afficher les champs supplémentaires selon le mode de paiement
    function afficherChampsSupp() {
        const modeSelect = document.getElementById('mode_paiement');
        const selectedMode = modeSelect.value;
        
        // Masquer tous les champs d'abord
        document.getElementById('mobileMoneyFields').style.display = 'none';
        document.getElementById('bankFields').style.display = 'none';
        document.getElementById('cashFields').style.display = 'none';
        
        // Afficher les champs correspondants
        if (selectedMode === 'Airtel Money' || selectedMode === 'MTN Mobile Money') {
            document.getElementById('mobileMoneyFields').style.display = 'block';
        } else if (selectedMode === 'Virement bancaire' || selectedMode === 'Chèque') {
            document.getElementById('bankFields').style.display = 'block';
        } else if (selectedMode === 'Espèces') {
            document.getElementById('cashFields').style.display = 'block';
        }
        
        calculerFrais();
    }
    
    // Calculer les frais de transaction
    function calculerFrais() {
        const montantInput = document.getElementById('montant');
        const modeSelect = document.getElementById('mode_paiement');
        const selectedMode = modeSelect.value;
        
        let montant = parseFloat(montantInput.value) || 0;
        let fraisPourcentage = 0;
        
        if (selectedMode === 'Airtel Money' || selectedMode === 'MTN Mobile Money') {
            fraisPourcentage = 1.5; // 1.5% pour mobile money
        }
        
        const fraisTransaction = (montant * fraisPourcentage) / 100;
        const montantNet = montant - fraisTransaction;
        
        // Mettre à jour l'affichage
        document.getElementById('montantPrincipal').textContent = 
            formatNombre(montant) + ' FCFA';
        document.getElementById('fraisTransaction').textContent = 
            formatNombre(fraisTransaction) + ' FCFA';
        document.getElementById('montantNet').textContent = 
            formatNombre(montantNet) + ' FCFA';
    }
    
    // Écouteurs d'événements pour le calcul des frais
    document.getElementById('montant').addEventListener('input', function() {
        calculerFrais();
        calculerOptionsPaiement();
    });
    
    document.getElementById('mode_paiement').addEventListener('change', afficherChampsSupp);
    document.getElementById('type_frais_id').addEventListener('change', function() {
        chargerSuggestions();
        remplirMontantStandard();
    });
    
    document.getElementById('etudiant_id').addEventListener('change', chargerSuggestions);
    document.getElementById('annee_academique_id').addEventListener('change', chargerSuggestions);
    
    // Initialiser au chargement
    window.onload = function() {
        afficherChampsSupp();
        calculerFrais();
        calculerOptionsPaiement();
    };
    
    // Fonction pour annuler un paiement
    function annulerPaiement(paiementId) {
        document.getElementById('annulation_paiement_id').value = paiementId;
        const modal = new bootstrap.Modal(document.getElementById('modalAnnulation'));
        modal.show();
    }
    
    // Fonction pour voir les détails d'un paiement
    function voirDetails(paiementId) {
        window.location.href = 'details_paiement.php?id=' + paiementId;
    }
    
    // Fonction pour imprimer un reçu
    function imprimerRecu(paiementId) {
        window.open('recu_paiement.php?id=' + paiementId, '_blank');
    }
    
    // Fonction pour imprimer la liste
    function imprimerListe() {
        window.print();
    }
    
    // Validation du formulaire
    document.querySelector('form[action=""]').addEventListener('submit', function(e) {
        const montant = document.getElementById('montant').value;
        const modePaiement = document.getElementById('mode_paiement').value;
        const numeroTransaction = document.getElementById('numero_transaction')?.value || '';
        
        if (montant <= 0) {
            e.preventDefault();
            alert('Le montant doit être supérieur à 0');
            return false;
        }
        
        if (modePaiement === 'Airtel Money' || modePaiement === 'MTN Mobile Money') {
            if (!numeroTransaction.trim()) {
                e.preventDefault();
                alert('Le numéro de transaction est obligatoire pour les paiements Mobile Money');
                return false;
            }
        }
        
        if (modePaiement === 'Virement bancaire') {
            const banque = document.getElementById('banque').value;
            if (!banque.trim()) {
                e.preventDefault();
                alert('Le nom de la banque est obligatoire pour les virements');
                return false;
            }
        }
        
        return true;
    });
    </script>
</body>
</html>