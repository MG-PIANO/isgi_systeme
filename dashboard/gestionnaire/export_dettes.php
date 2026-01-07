<?php
// dashboard/gestionnaire/export_dettes.php

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

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    $user_id = $_SESSION['user_id'];
    
    // Traitement des filtres
    $filtre_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
    $filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtre_etudiant = isset($_GET['etudiant']) ? intval($_GET['etudiant']) : 0;
    $filtre_annee = isset($_GET['annee']) ? intval($_GET['annee']) : 0;
    $filtre_retard = isset($_GET['retard']) ? intval($_GET['retard']) : 0;
    $filtre_type = isset($_GET['type']) ? $_GET['type'] : '';
    $filtre_montant_min = isset($_GET['montant_min']) ? floatval($_GET['montant_min']) : 0;
    $filtre_montant_max = isset($_GET['montant_max']) ? floatval($_GET['montant_max']) : 0;
    $filtre_date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $filtre_date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    
    // REQUÊTE POUR RÉCUPÉRER LES DETTES
    $query = "SELECT d.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
              aa.libelle as annee_academique,
              s.nom as site_nom,
              CONCAT(u.nom, ' ', u.prenom) as gestionnaire_nom
              FROM dettes d
              INNER JOIN etudiants e ON d.etudiant_id = e.id
              INNER JOIN annees_academiques aa ON d.annee_academique_id = aa.id
              INNER JOIN sites s ON e.site_id = s.id
              LEFT JOIN utilisateurs u ON d.gestionnaire_id = u.id
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
        $query .= " AND d.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if ($filtre_etudiant > 0) {
        $query .= " AND d.etudiant_id = ?";
        $params[] = $filtre_etudiant;
    }
    
    if ($filtre_annee > 0) {
        $query .= " AND d.annee_academique_id = ?";
        $params[] = $filtre_annee;
    }
    
    if (!empty($filtre_type)) {
        $query .= " AND d.type_dette = ?";
        $params[] = $filtre_type;
    }
    
    if ($filtre_retard > 0) {
        $query .= " AND d.date_limite < CURDATE() AND d.statut = 'en_cours'";
    }
    
    if ($filtre_montant_min > 0) {
        $query .= " AND d.montant_restant >= ?";
        $params[] = $filtre_montant_min;
    }
    
    if ($filtre_montant_max > 0) {
        $query .= " AND d.montant_restant <= ?";
        $params[] = $filtre_montant_max;
    }
    
    if (!empty($filtre_date_debut)) {
        $query .= " AND d.date_creation >= ?";
        $params[] = $filtre_date_debut;
    }
    
    if (!empty($filtre_date_fin)) {
        $query .= " AND d.date_creation <= ?";
        $params[] = $filtre_date_fin;
    }
    
    $query .= " ORDER BY d.date_limite ASC, d.montant_restant DESC";
    
    // Exécuter la requête
    if (!empty($params)) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } else {
        $stmt = $db->query($query);
    }
    
    $dettes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fonction pour formater l'argent
    function formatMoneyExport($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0';
        return number_format($amount, 0, ',', ' ');
    }
    
    // En-tête Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="dettes_isgi_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Début du contenu HTML
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    
    // En-têtes
    echo '<tr style="background-color:#4CAF50;color:white;">';
    echo '<th>Matricule</th>';
    echo '<th>Étudiant</th>';
    echo '<th>Site</th>';
    echo '<th>Année Académique</th>';
    echo '<th>Type</th>';
    echo '<th>Montant Dû (FCFA)</th>';
    echo '<th>Montant Payé (FCFA)</th>';
    echo '<th>Montant Restant (FCFA)</th>';
    echo '<th>% Payé</th>';
    echo '<th>Date Limite</th>';
    echo '<th>Jours Retard</th>';
    echo '<th>Statut</th>';
    echo '<th>Date Création</th>';
    echo '<th>Gestionnaire</th>';
    echo '<th>Motif</th>';
    echo '</tr>';
    
    // Données
    foreach ($dettes as $dette) {
        // Calculer le pourcentage
        $pourcentage = $dette['montant_du'] > 0 ? round(($dette['montant_paye'] / $dette['montant_du']) * 100, 1) : 0;
        
        // Calculer les jours de retard
        $jours_retard = 0;
        if ($dette['date_limite'] && strtotime($dette['date_limite']) < time()) {
            $date_limit = new DateTime($dette['date_limite']);
            $now = new DateTime();
            $interval = $date_limit->diff($now);
            $jours_retard = $interval->days;
        }
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($dette['matricule']) . '</td>';
        echo '<td>' . htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']) . '</td>';
        echo '<td>' . htmlspecialchars($dette['site_nom']) . '</td>';
        echo '<td>' . htmlspecialchars($dette['annee_academique']) . '</td>';
        echo '<td>' . htmlspecialchars($dette['type_dette']) . '</td>';
        echo '<td>' . formatMoneyExport($dette['montant_du']) . '</td>';
        echo '<td>' . formatMoneyExport($dette['montant_paye']) . '</td>';
        echo '<td>' . formatMoneyExport($dette['montant_restant']) . '</td>';
        echo '<td>' . $pourcentage . '%</td>';
        echo '<td>' . ($dette['date_limite'] ? date('d/m/Y', strtotime($dette['date_limite'])) : '') . '</td>';
        echo '<td>' . ($jours_retard > 0 ? $jours_retard : '') . '</td>';
        echo '<td>' . htmlspecialchars($dette['statut']) . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($dette['date_creation'])) . '</td>';
        echo '<td>' . htmlspecialchars($dette['gestionnaire_nom'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($dette['motif'] ?? '') . '</td>';
        echo '</tr>';
    }
    
    // Totaux
    $total_du = 0;
    $total_paye = 0;
    $total_restant = 0;
    foreach ($dettes as $dette) {
        $total_du += $dette['montant_du'];
        $total_paye += $dette['montant_paye'];
        $total_restant += $dette['montant_restant'];
    }
    
    echo '<tr style="background-color:#f2f2f2;font-weight:bold;">';
    echo '<td colspan="5">TOTAUX</td>';
    echo '<td>' . formatMoneyExport($total_du) . '</td>';
    echo '<td>' . formatMoneyExport($total_paye) . '</td>';
    echo '<td>' . formatMoneyExport($total_restant) . '</td>';
    echo '<td>' . ($total_du > 0 ? round(($total_paye / $total_du) * 100, 1) . '%' : '0%') . '</td>';
    echo '<td colspan="6"></td>';
    echo '</tr>';
    
    echo '</table>';
    echo '</body></html>';
    
} catch (Exception $e) {
    echo "Erreur lors de l'export: " . $e->getMessage();
}
?>