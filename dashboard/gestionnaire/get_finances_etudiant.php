<?php
// dashboard/gestionnaire/get_finances_etudiant.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Accès non autorisé']));
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

if (!class_exists('Database')) {
    exit(json_encode(['error' => 'Erreur de configuration']));
}

try {
    $db = Database::getInstance()->getConnection();
    
    $etudiant_id = isset($_GET['etudiant_id']) ? intval($_GET['etudiant_id']) : 0;
    $annee_id = isset($_GET['annee_id']) ? intval($_GET['annee_id']) : 0;
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    
    if (!$etudiant_id || !$annee_id) {
        exit(json_encode(['error' => 'Paramètres manquants']));
    }
    
    // Récupérer les informations de base de l'étudiant
    $query_etudiant = "SELECT e.id, e.matricule, e.nom, e.prenom,
                              CONCAT(e.prenom, ' ', e.nom) as nom_complet,
                              e.site_id
                       FROM etudiants e
                       WHERE e.id = ? AND e.statut = 'actif'";
    
    $stmt = $db->prepare($query_etudiant);
    $stmt->execute([$etudiant_id]);
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$etudiant) {
        exit(json_encode(['error' => 'Étudiant non trouvé']));
    }
    
    // Récupérer le site de l'étudiant si non spécifié
    if (!$site_id && $etudiant['site_id']) {
        $site_id = $etudiant['site_id'];
    }
    
    // Fonction pour récupérer le tarif de scolarité
    function getTarifScolariteComplet($db, $etudiant_id, $annee_id, $site_id) {
        // Chercher dans la table de liaison etudiant_options
        $query_liaison = "SELECT eo.option_id, eo.niveau_id 
                         FROM etudiant_options eo
                         WHERE eo.etudiant_id = ? AND eo.annee_academique_id = ? 
                         ORDER BY eo.date_inscription DESC LIMIT 1";
        
        $stmt = $db->prepare($query_liaison);
        $stmt->execute([$etudiant_id, $annee_id]);
        $liaison = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$liaison || !$liaison['option_id'] || !$liaison['niveau_id']) {
            return null;
        }
        
        // Rechercher le tarif correspondant
        $query = "SELECT t.montant, o.nom as option_nom, n.libelle as niveau_libelle,
                         n.code as niveau_code
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
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $liaison['option_id'], 
            $liaison['niveau_id'], 
            $annee_id, 
            $site_id
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Chercher d'abord dans la table etudiant_options (liaison option-niveau)
    $result = [
        'id' => $etudiant['id'],
        'matricule' => $etudiant['matricule'],
        'nom_complet' => $etudiant['nom_complet'],
        'montant_global' => 0,
        'total_paye' => 0,
        'reste_a_payer' => 0
    ];
    
    $tarif = getTarifScolariteComplet($db, $etudiant_id, $annee_id, $site_id);
    
    if ($tarif) {
        $result['montant_global'] = $tarif['montant'] * 10; // 10 mois d'année académique
        $result['option_nom'] = $tarif['option_nom'];
        $result['niveau_libelle'] = $tarif['niveau_libelle'];
        $result['niveau_code'] = $tarif['niveau_code'];
    } else {
        // Si pas de liaison trouvée, chercher dans les inscriptions
        $query_inscription = "SELECT i.montant_total, f.nom as filiere_nom, 
                                     n.libelle as niveau_libelle, n.code as niveau_code
                              FROM inscriptions i
                              JOIN filieres f ON i.filiere_id = f.id
                              JOIN niveaux n ON i.niveau = n.id
                              WHERE i.etudiant_id = ? 
                              AND i.annee_academique_id = ?
                              AND i.statut = 'validee'
                              LIMIT 1";
        
        $stmt = $db->prepare($query_inscription);
        $stmt->execute([$etudiant_id, $annee_id]);
        $inscription = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($inscription) {
            $result['montant_global'] = $inscription['montant_total'];
            $result['filiere_nom'] = $inscription['filiere_nom'];
            $result['niveau_libelle'] = $inscription['niveau_libelle'];
            $result['niveau_code'] = $inscription['niveau_code'];
        } else {
            // Chercher la dette de l'étudiant
            $query_dette = "SELECT montant_du, montant_paye, montant_restant
                           FROM dettes 
                           WHERE etudiant_id = ? 
                           AND annee_academique_id = ? 
                           AND statut = 'en_cours'
                           LIMIT 1";
            
            $stmt = $db->prepare($query_dette);
            $stmt->execute([$etudiant_id, $annee_id]);
            $dette = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dette) {
                $result['montant_global'] = $dette['montant_du'] + $dette['montant_paye'];
                $result['total_paye'] = $dette['montant_paye'];
                $result['reste_a_payer'] = $dette['montant_restant'];
                $result['info'] = 'Dette trouvée';
            } else {
                $result['info'] = 'Aucun tarif, inscription ou dette trouvé';
            }
        }
    }
    
    // Calculer le total des paiements de scolarité (si pas déjà fait via dette)
    if ($result['total_paye'] == 0) {
        $query_paiements = "SELECT SUM(p.montant) as total_paye
                           FROM paiements p
                           JOIN types_frais tf ON p.type_frais_id = tf.id
                           WHERE p.etudiant_id = ?
                           AND p.annee_academique_id = ?
                           AND tf.nom LIKE '%scolarité%'
                           AND p.statut = 'valide'";
        
        $stmt = $db->prepare($query_paiements);
        $stmt->execute([$etudiant_id, $annee_id]);
        $paiements = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['total_paye'] = $paiements['total_paye'] ?? 0;
    }
    
    // Calculer le reste à payer
    $result['reste_a_payer'] = $result['montant_global'] - $result['total_paye'];
    if ($result['reste_a_payer'] < 0) {
        $result['reste_a_payer'] = 0;
    }
    
    // Récupérer le mois actuel de l'année académique (pour mensualité)
    $query_mois = "SELECT MONTH(CURDATE()) - MONTH(aa.date_debut) + 1 as mois_actuel
                   FROM annees_academiques aa
                   WHERE aa.id = ?";
    
    $stmt = $db->prepare($query_mois);
    $stmt->execute([$annee_id]);
    $mois_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $result['mois_actuel'] = $mois_data['mois_actuel'] ?? 1;
    $result['mois_restants'] = max(0, 10 - $result['mois_actuel']);
    $result['mensualite_suggestee'] = $result['reste_a_payer'] > 0 && $result['mois_restants'] > 0 
        ? ceil($result['reste_a_payer'] / $result['mois_restants'])
        : ceil($result['montant_global'] / 10);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
}
?>