<?php
// dashboard/gestionnaire/dettes.php

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
    
    // Définir le titre de la page
    $pageTitle = "Gestionnaire - Dettes Étudiants";
    
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
    
    function getStatutBadgeDette($statut) {
        switch ($statut) {
            case 'en_cours':
                return '<span class="badge bg-warning">En cours</span>';
            case 'soldee':
                return '<span class="badge bg-success">Soldée</span>';
            case 'en_retard':
                return '<span class="badge bg-danger">En retard</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    function getTypeDetteBadge($type) {
        switch ($type) {
            case 'scolarite':
                return '<span class="badge bg-primary">Scolarité</span>';
            case 'inscription':
                return '<span class="badge bg-info">Inscription</span>';
            case 'autre':
                return '<span class="badge bg-secondary">Autre</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    // Fonction pour calculer l'âge de la dette
    function getAgeDette($date_creation) {
        $date_creation = new DateTime($date_creation);
        $now = new DateTime();
        $interval = $date_creation->diff($now);
        
        if ($interval->y > 0) {
            return $interval->y . ' an' . ($interval->y > 1 ? 's' : '');
        } elseif ($interval->m > 0) {
            return $interval->m . ' mois';
        } elseif ($interval->d > 0) {
            return $interval->d . ' jour' . ($interval->d > 1 ? 's' : '');
        } else {
            return 'Aujourd\'hui';
        }
    }
    
    // Fonction pour calculer le pourcentage de paiement
    function getPourcentagePaiement($montant_du, $montant_paye) {
        if ($montant_du <= 0) return 0;
        return min(100, round(($montant_paye / $montant_du) * 100));
    }
    
    // Fonction pour calculer les jours de retard
    function getJoursRetard($date_limite) {
        if (!$date_limite) return 0;
        $date_limit = new DateTime($date_limite);
        $now = new DateTime();
        if ($date_limit > $now) return 0;
        $interval = $date_limit->diff($now);
        return $interval->days;
    }
    
    // Variables
    $error = null;
    $success = null;
    $dettes = array();
    $sites = array();
    $etudiants = array();
    $annees_academiques = array();
    
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
    
    // Récupérer les années académiques
    $annees_academiques = $db->query("SELECT * FROM annees_academiques ORDER BY date_debut DESC")->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    // Traitement des actions
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $dette_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    
    // TRAITEMENT DE LA MISE À JOUR DE DETTE
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'maj_dette') {
        try {
            $db->beginTransaction();
            
            $dette_id = intval($_POST['id']);
            $montant_du = floatval($_POST['montant_du']);
            $montant_paye = floatval($_POST['montant_paye']);
            $date_limite = $_POST['date_limite'];
            $statut = $_POST['statut'];
            $type_dette = $_POST['type_dette'] ?? 'scolarite';
            $motif = $_POST['motif'] ?? '';
            
            // Récupérer l'ancienne dette pour l'historique
            $stmt = $db->prepare("SELECT * FROM dettes WHERE id = ?");
            $stmt->execute([$dette_id]);
            $ancienne_dette = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ancienne_dette) {
                throw new Exception("Dette non trouvée.");
            }
            
            // Validation
            if ($montant_du <= 0) {
                throw new Exception("Le montant dû doit être supérieur à 0.");
            }
            
            if ($montant_paye < 0) {
                throw new Exception("Le montant payé ne peut pas être négatif.");
            }
            
            if ($montant_paye > $montant_du) {
                throw new Exception("Le montant payé ne peut pas dépasser le montant dû.");
            }
            
            $montant_restant = $montant_du - $montant_paye;
            
            // Mettre à jour la dette
            $stmt = $db->prepare("UPDATE dettes SET 
                montant_du = ?,
                montant_paye = ?,
                montant_restant = ?,
                date_limite = ?,
                statut = ?,
                type_dette = ?,
                motif = ?,
                modifie_par = ?,
                date_maj = NOW()
                WHERE id = ?");
            
            $stmt->execute([
                $montant_du,
                $montant_paye,
                $montant_restant,
                $date_limite,
                $statut,
                $type_dette,
                $motif,
                $user_id,
                $dette_id
            ]);
            
            // Enregistrer dans l'historique
            $query_historique = "INSERT INTO historique_modifications_dettes (
                dette_id, utilisateur_id, action, anciennes_valeurs, nouvelles_valeurs
            ) VALUES (?, ?, 'maj_dette', ?, ?)";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $dette_id,
                $user_id,
                json_encode([
                    'montant_du' => $ancienne_dette['montant_du'],
                    'montant_paye' => $ancienne_dette['montant_paye'],
                    'date_limite' => $ancienne_dette['date_limite'],
                    'statut' => $ancienne_dette['statut'],
                    'type_dette' => $ancienne_dette['type_dette']
                ]),
                json_encode([
                    'montant_du' => $montant_du,
                    'montant_paye' => $montant_paye,
                    'date_limite' => $date_limite,
                    'statut' => $statut,
                    'type_dette' => $type_dette
                ])
            ]);
            
            $db->commit();
            $success = "Dette mise à jour avec succès !";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la mise à jour de la dette: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DE LA SUPPRESSION DE DETTE
    if ($action == 'supprimer' && $dette_id > 0) {
        $motif = isset($_POST['motif']) ? trim($_POST['motif']) : 'Non spécifié';
        
        try {
            $db->beginTransaction();
            
            // Récupérer les informations de la dette avant suppression
            $stmt = $db->prepare("SELECT e.matricule, d.montant_du FROM dettes d
                                 JOIN etudiants e ON d.etudiant_id = e.id
                                 WHERE d.id = ?");
            $stmt->execute([$dette_id]);
            $dette_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dette_info) {
                throw new Exception("Dette non trouvée.");
            }
            
            // Supprimer la dette
            $stmt = $db->prepare("DELETE FROM dettes WHERE id = ?");
            $stmt->execute([$dette_id]);
            
            if ($stmt->rowCount() > 0) {
                // Enregistrer dans l'historique
                $query_historique = "INSERT INTO logs_activite (
                    utilisateur_id, utilisateur_type, action, table_concernée, 
                    id_enregistrement, details, date_action
                ) VALUES (?, 'admin', 'suppression_dette', 'dettes', ?, ?, NOW())";
                
                $stmt = $db->prepare($query_historique);
                $stmt->execute([
                    $user_id,
                    $dette_id,
                    "Suppression dette: " . $dette_info['matricule'] . " - " . formatMoney($dette_info['montant_du']) . " - Motif: " . $motif
                ]);
                
                $db->commit();
                $success = "Dette supprimée avec succès !";
            } else {
                throw new Exception("Impossible de supprimer cette dette.");
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la suppression: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DE LA CRÉATION DE DETTE MANUELLE
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'nouvelle_dette') {
        try {
            $db->beginTransaction();
            
            $etudiant_id = intval($_POST['etudiant_id']);
            $annee_academique_id = intval($_POST['annee_academique_id']);
            $montant_du = floatval($_POST['montant_du']);
            $montant_paye = floatval($_POST['montant_paye'] ?? 0);
            $date_limite = $_POST['date_limite'];
            $type_dette = $_POST['type_dette'] ?? 'scolarite';
            $motif = trim($_POST['motif'] ?? '');
            $creer_plan = isset($_POST['creer_plan']) ? true : false;
            $nombre_tranches = intval($_POST['nombre_tranches'] ?? 1);
            
            // Validation
            if ($montant_du <= 0) {
                throw new Exception("Le montant dû doit être supérieur à 0.");
            }
            
            if ($montant_paye < 0) {
                throw new Exception("Le montant payé ne peut pas être négatif.");
            }
            
            if ($montant_paye > $montant_du) {
                throw new Exception("Le montant payé ne peut pas dépasser le montant dû.");
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
            
            // Vérifier si une dette existe déjà pour cette année
            $stmt = $db->prepare("SELECT id FROM dettes WHERE etudiant_id = ? AND annee_academique_id = ?");
            $stmt->execute([$etudiant_id, $annee_academique_id]);
            if ($stmt->fetch()) {
                throw new Exception("Une dette existe déjà pour cet étudiant pour cette année académique.");
            }
            
            $montant_restant = $montant_du - $montant_paye;
            $statut = $montant_restant > 0 ? 'en_cours' : 'soldee';
            
            // Créer la dette
            $stmt = $db->prepare("INSERT INTO dettes (
                etudiant_id, annee_academique_id, montant_du,
                montant_paye, montant_restant, date_limite, statut, 
                type_dette, motif, gestionnaire_id, cree_par,
                date_creation, date_maj
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            
            $stmt->execute([
                $etudiant_id, $annee_academique_id, $montant_du,
                $montant_paye, $montant_restant, $date_limite, $statut,
                $type_dette, $motif, $user_id, $user_id
            ]);
            
            $dette_id = $db->lastInsertId();
            
            // Créer un plan de paiement si demandé
            if ($creer_plan && $montant_restant > 0 && $nombre_tranches > 1) {
                $montant_tranche = $montant_restant / $nombre_tranches;
                
                // Créer le plan
                $stmt = $db->prepare("INSERT INTO plans_paiement_dettes (
                    dette_id, nombre_tranches, montant_tranche, frequence, date_debut, statut
                ) VALUES (?, ?, ?, ?, ?, 'actif')");
                
                $stmt->execute([
                    $dette_id,
                    $nombre_tranches,
                    $montant_tranche,
                    'mensuelle',
                    $date_limite
                ]);
                
                $plan_id = $db->lastInsertId();
                
                // Créer les échéances
                $date_echeance = new DateTime($date_limite);
                for ($i = 1; $i <= $nombre_tranches; $i++) {
                    $stmt = $db->prepare("INSERT INTO echeances_dettes (
                        plan_id, dette_id, numero_tranche, montant, date_echeance, statut
                    ) VALUES (?, ?, ?, ?, ?, 'en_attente')");
                    
                    $stmt->execute([
                        $plan_id,
                        $dette_id,
                        $i,
                        $montant_tranche,
                        $date_echeance->format('Y-m-d')
                    ]);
                    
                    $date_echeance->modify('+1 month');
                }
            }
            
            // Enregistrer dans l'historique
            $query_historique = "INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée, 
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'nouvelle_dette', 'dettes', ?, ?, NOW())";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $user_id,
                $dette_id,
                "Nouvelle dette manuelle: " . $etudiant['matricule'] . " - " . formatMoney($montant_du) . " - Type: " . $type_dette
            ]);
            
            $db->commit();
            $success = "Dette créée avec succès !";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la création de la dette: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DE LA CRÉATION D'UN PLAN DE PAIEMENT
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'creer_plan') {
        try {
            $db->beginTransaction();
            
            $dette_id = intval($_POST['dette_id']);
            $nombre_tranches = intval($_POST['nombre_tranches']);
            $montant_tranche = floatval($_POST['montant_tranche']);
            $frequence = $_POST['frequence'];
            $date_debut = $_POST['date_debut'];
            
            // Vérifier la dette
            $stmt = $db->prepare("SELECT montant_restant FROM dettes WHERE id = ?");
            $stmt->execute([$dette_id]);
            $dette = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dette) {
                throw new Exception("Dette non trouvée.");
            }
            
            // Vérifier si un plan existe déjà
            $stmt = $db->prepare("SELECT id FROM plans_paiement_dettes WHERE dette_id = ? AND statut = 'actif'");
            $stmt->execute([$dette_id]);
            if ($stmt->fetch()) {
                throw new Exception("Un plan de paiement actif existe déjà pour cette dette.");
            }
            
            // Créer le plan
            $stmt = $db->prepare("INSERT INTO plans_paiement_dettes (
                dette_id, nombre_tranches, montant_tranche, frequence, date_debut, statut
            ) VALUES (?, ?, ?, ?, ?, 'actif')");
            
            $stmt->execute([
                $dette_id,
                $nombre_tranches,
                $montant_tranche,
                $frequence,
                $date_debut
            ]);
            
            $plan_id = $db->lastInsertId();
            
            // Créer les échéances
            $date_echeance = new DateTime($date_debut);
            for ($i = 1; $i <= $nombre_tranches; $i++) {
                $stmt = $db->prepare("INSERT INTO echeances_dettes (
                    plan_id, dette_id, numero_tranche, montant, date_echeance, statut
                ) VALUES (?, ?, ?, ?, ?, 'en_attente')");
                
                $stmt->execute([
                    $plan_id,
                    $dette_id,
                    $i,
                    $montant_tranche,
                    $date_echeance->format('Y-m-d')
                ]);
                
                // Mettre à jour la date pour la prochaine échéance selon la fréquence
                switch ($frequence) {
                    case 'mensuelle':
                        $date_echeance->modify('+1 month');
                        break;
                    case 'trimestrielle':
                        $date_echeance->modify('+3 months');
                        break;
                    case 'semestrielle':
                        $date_echeance->modify('+6 months');
                        break;
                    case 'annuelle':
                        $date_echeance->modify('+1 year');
                        break;
                }
            }
            
            // Enregistrer dans l'historique
            $query_historique = "INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée, 
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'nouveau_plan_paiement', 'plans_paiement_dettes', ?, ?, NOW())";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $user_id,
                $plan_id,
                "Nouveau plan de paiement créé pour dette #" . $dette_id
            ]);
            
            $db->commit();
            $success = "Plan de paiement créé avec succès !";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la création du plan de paiement: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DE L'ENVOI DE RAPPEL
    if ($action == 'envoyer_rappel' && $dette_id > 0) {
        try {
            // Récupérer les informations de la dette
            $stmt = $db->prepare("SELECT d.*, e.matricule, e.nom, e.prenom, e.email, e.telephone 
                                 FROM dettes d
                                 JOIN etudiants e ON d.etudiant_id = e.id
                                 WHERE d.id = ?");
            $stmt->execute([$dette_id]);
            $dette = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dette) {
                throw new Exception("Dette non trouvée.");
            }
            
            // Récupérer les infos du tuteur si disponible
            $stmt = $db->prepare("SELECT telephone_parent FROM etudiants WHERE id = ?");
            $stmt->execute([$dette['etudiant_id']]);
            $etudiant_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Préparer le message
            $message = "Rappel dette: Étudiant " . $dette['prenom'] . " " . $dette['nom'] . "\n";
            $message .= "Matricule: " . $dette['matricule'] . "\n";
            $message .= "Montant dû: " . formatMoney($dette['montant_du']) . "\n";
            $message .= "Montant payé: " . formatMoney($dette['montant_paye']) . "\n";
            $message .= "Montant restant: " . formatMoney($dette['montant_restant']) . "\n";
            if ($dette['date_limite']) {
                $message .= "Date limite: " . date('d/m/Y', strtotime($dette['date_limite'])) . "\n";
            }
            
            // Ici vous pourriez intégrer un service d'envoi d'email ou SMS
            // Pour l'instant, on simule juste l'envoi
            
            // Enregistrer l'action dans les logs
            $query_historique = "INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée, 
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'rappel_dette', 'dettes', ?, ?, NOW())";
            
            $stmt = $db->prepare($query_historique);
            $stmt->execute([
                $user_id,
                $dette_id,
                "Rappel envoyé pour dette: " . $dette['matricule']
            ]);
            
            $success = "Rappel envoyé avec succès !";
            
        } catch (Exception $e) {
            $error = "Erreur lors de l'envoi du rappel: " . $e->getMessage();
        }
    }
    
    // TRAITEMENT DE L'EXPORT DES DETTES
    if ($action == 'export_dettes') {
        // Ce traitement se fait généralement dans un fichier séparé
        // Voir export_dettes.php plus bas
    }
    
    // REQUÊTE POUR RÉCUPÉRER LES DETTES
    $query = "SELECT d.*, 
              e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom, e.site_id,
              aa.libelle as annee_academique,
              s.nom as site_nom,
              CONCAT(u1.nom, ' ', u1.prenom) as gestionnaire_nom,
              CONCAT(u2.nom, ' ', u2.prenom) as createur_nom
              FROM dettes d
              INNER JOIN etudiants e ON d.etudiant_id = e.id
              INNER JOIN annees_academiques aa ON d.annee_academique_id = aa.id
              INNER JOIN sites s ON e.site_id = s.id
              LEFT JOIN utilisateurs u1 ON d.gestionnaire_id = u1.id
              LEFT JOIN utilisateurs u2 ON d.cree_par = u2.id
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
    
    // Initialiser les statistiques
    $stats = [
        'total_dettes' => 0,
        'total_montant_du' => 0,
        'total_montant_paye' => 0,
        'total_montant_restant' => 0,
        'par_statut' => [],
        'par_type' => [],
        'en_retard' => 0,
        'montant_retard' => 0,
        'pourcentage_paye' => 0,
        'moyenne_dette' => 0
    ];
    
    // Calculer les statistiques seulement si on a des dettes
    if (!empty($dettes)) {
        foreach ($dettes as $dette) {
            $stats['total_dettes']++;
            $stats['total_montant_du'] += $dette['montant_du'];
            $stats['total_montant_paye'] += $dette['montant_paye'];
            $stats['total_montant_restant'] += $dette['montant_restant'];
            
            // Par statut
            $statut = $dette['statut'];
            if (!isset($stats['par_statut'][$statut])) {
                $stats['par_statut'][$statut] = ['count' => 0, 'montant' => 0];
            }
            $stats['par_statut'][$statut]['count']++;
            $stats['par_statut'][$statut]['montant'] += $dette['montant_restant'];
            
            // Par type
            $type = $dette['type_dette'] ?? 'scolarite';
            if (!isset($stats['par_type'][$type])) {
                $stats['par_type'][$type] = ['count' => 0, 'montant' => 0];
            }
            $stats['par_type'][$type]['count']++;
            $stats['par_type'][$type]['montant'] += $dette['montant_restant'];
            
            // Dettes en retard
            if ($dette['statut'] == 'en_cours' && $dette['date_limite'] && strtotime($dette['date_limite']) < time()) {
                $stats['en_retard']++;
                $stats['montant_retard'] += $dette['montant_restant'];
            }
        }
        
        // Calculer le pourcentage global de paiement
        $stats['pourcentage_paye'] = $stats['total_montant_du'] > 0 
            ? round(($stats['total_montant_paye'] / $stats['total_montant_du']) * 100, 1)
            : 0;
            
        // Calculer la moyenne des dettes
        $stats['moyenne_dette'] = $stats['total_dettes'] > 0 
            ? round($stats['total_montant_du'] / $stats['total_dettes'], 2)
            : 0;
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
    error_log("Erreur dettes.php: " . $e->getMessage());
    
    // Initialiser les variables pour éviter les erreurs
    $dettes = [];
    $sites = [];
    $etudiants = [];
    $annees_academiques = [];
    $stats = [
        'total_dettes' => 0,
        'total_montant_du' => 0,
        'total_montant_paye' => 0,
        'total_montant_restant' => 0,
        'par_statut' => [],
        'par_type' => [],
        'en_retard' => 0,
        'montant_retard' => 0,
        'pourcentage_paye' => 0,
        'moyenne_dette' => 0
    ];
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
    
    .progress-dette {
        height: 20px;
        border-radius: 10px;
        overflow: hidden;
    }
    
    .progress-dette .progress-bar {
        transition: width 0.6s ease;
    }
    
    .dette-card {
        transition: all 0.3s ease;
    }
    
    .dette-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .retard-badge {
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
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
    
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
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
    
    /* Styles pour les montants */
    .montant-du {
        color: #e74c3c;
        font-weight: bold;
    }
    
    .montant-paye {
        color: #27ae60;
        font-weight: bold;
    }
    
    .montant-restant {
        color: #f39c12;
        font-weight: bold;
    }
    
    /* Styles pour les échéances */
    .echeance-card {
        border-left: 4px solid #3498db;
        margin-bottom: 10px;
    }
    
    .echeance-retard {
        border-left-color: #e74c3c;
    }
    
    .echeance-payee {
        border-left-color: #27ae60;
    }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI FINANCES</h5>
                <div class="user-role">Gestionnaire Principal</div>
                <?php if($site_nom): ?>
                <small><?php echo htmlspecialchars($site_nom); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Gestionnaire'); ?></p>
                <small>Gestion des Dettes</small>
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
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link active">
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
                            <i class="fas fa-file-invoice-dollar me-2"></i>
                            Gestion des Dettes Étudiants
                        </h2>
                        <p class="text-muted mb-0">
                            Gestionnaire Principal - 
                            <?php echo $site_nom ? htmlspecialchars($site_nom) : 'Tous les sites'; ?>
                        </p>
                    </div>
                    <div class="quick-actions">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNouvelleDette">
                            <i class="fas fa-plus-circle"></i> Nouvelle Dette
                        </button>
                        <button type="button" class="btn btn-primary" onclick="imprimerListe()">
                            <i class="fas fa-print"></i> Imprimer Liste
                        </button>
                        <button type="button" class="btn btn-danger" onclick="exporterDettes()">
                            <i class="fas fa-file-export"></i> Exporter Excel
                        </button>
                        <button type="button" class="btn btn-info" onclick="rafraichirDonnees()">
                            <i class="fas fa-sync-alt"></i> Actualiser
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
            
            <!-- Statistiques détaillées -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-primary stats-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3><?php echo $stats['total_dettes']; ?></h3>
                            <p class="text-muted mb-0">Dettes totales</p>
                            <small class="text-primary">Moyenne: <?php echo formatMoney($stats['moyenne_dette']); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-warning stats-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <h3><?php echo formatMoney($stats['total_montant_restant']); ?></h3>
                            <p class="text-muted mb-0">Montant restant</p>
                            <div class="progress mt-2" style="height: 6px;">
                                <div class="progress-bar bg-warning" role="progressbar" 
                                     style="width: <?php echo $stats['pourcentage_paye']; ?>%">
                                </div>
                            </div>
                            <small class="text-warning"><?php echo $stats['pourcentage_paye']; ?>% payé</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-danger stats-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h3><?php echo $stats['en_retard']; ?></h3>
                            <p class="text-muted mb-0">Dettes en retard</p>
                            <small class="text-danger"><?php echo formatMoney($stats['montant_retard']); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="text-success stats-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <h3><?php echo $stats['pourcentage_paye']; ?>%</h3>
                            <p class="text-muted mb-0">Taux de recouvrement</p>
                            <small><?php echo formatMoney($stats['total_montant_paye']); ?> payés</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Graphiques -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Répartition par statut</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartStatut"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Montants par type</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartType"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
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
                        
                        <div class="col-md-2">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="en_cours" <?php echo $filtre_statut == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                <option value="soldee" <?php echo $filtre_statut == 'soldee' ? 'selected' : ''; ?>>Soldée</option>
                                <option value="en_retard" <?php echo $filtre_statut == 'en_retard' ? 'selected' : ''; ?>>En retard</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="">Tous les types</option>
                                <option value="scolarite" <?php echo $filtre_type == 'scolarite' ? 'selected' : ''; ?>>Scolarité</option>
                                <option value="inscription" <?php echo $filtre_type == 'inscription' ? 'selected' : ''; ?>>Inscription</option>
                                <option value="autre" <?php echo $filtre_type == 'autre' ? 'selected' : ''; ?>>Autre</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="annee" class="form-label">Année académique</label>
                            <select class="form-select" id="annee" name="annee">
                                <option value="0">Toutes les années</option>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>" <?php echo $filtre_annee == $annee['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="montant_min" class="form-label">Montant min (FCFA)</label>
                            <input type="number" class="form-control" id="montant_min" name="montant_min" value="<?php echo $filtre_montant_min; ?>" placeholder="Montant minimum">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="montant_max" class="form-label">Montant max (FCFA)</label>
                            <input type="number" class="form-control" id="montant_max" name="montant_max" value="<?php echo $filtre_montant_max; ?>" placeholder="Montant maximum">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="date_debut" class="form-label">Date début</label>
                            <input type="date" class="form-control" id="date_debut" name="date_debut" value="<?php echo $filtre_date_debut; ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="date_fin" class="form-label">Date fin</label>
                            <input type="date" class="form-control" id="date_fin" name="date_fin" value="<?php echo $filtre_date_fin; ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="retard" name="retard" value="1" <?php echo $filtre_retard ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="retard">
                                    Dettes en retard seulement
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Rechercher
                                </button>
                                <?php if($filtre_site > 0 || !empty($filtre_statut) || $filtre_etudiant > 0 || $filtre_annee > 0 || $filtre_retard || $filtre_montant_min > 0 || $filtre_montant_max > 0 || !empty($filtre_type) || !empty($filtre_date_debut) || !empty($filtre_date_fin)): ?>
                                <a href="dettes.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Réinitialiser
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Liste des dettes -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Liste des Dettes (<?php echo count($dettes); ?>)
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="voirVueTableau()">
                            <i class="fas fa-table"></i> Tableau
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="voirVueCartes()">
                            <i class="fas fa-th-large"></i> Cartes
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(empty($dettes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Aucune dette trouvée</h5>
                        <p class="mb-0">Aucune dette ne correspond à vos critères de recherche.</p>
                        <a href="dettes.php" class="btn btn-primary mt-3">Voir toutes les dettes</a>
                    </div>
                    <?php else: ?>
                    
                    <!-- Vue Tableau -->
                    <div id="vue-tableau">
                        <div class="table-responsive">
                            <table class="table table-hover" id="dettesTable">
                                <thead>
                                    <tr>
                                        <th>Étudiant</th>
                                        <th>Montants</th>
                                        <th>Type</th>
                                        <th>Progression</th>
                                        <th>Échéance</th>
                                        <th>Statut</th>
                                        <th>Âge</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($dettes as $dette): 
                                    $pourcentage = getPourcentagePaiement($dette['montant_du'], $dette['montant_paye']);
                                    $age_dette = getAgeDette($dette['date_creation']);
                                    $jours_retard = getJoursRetard($dette['date_limite']);
                                    $en_retard = ($dette['statut'] == 'en_cours' && $dette['date_limite'] && strtotime($dette['date_limite']) < time());
                                    ?>
                                    <tr class="dette-card <?php echo $en_retard ? 'table-danger' : ''; ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <i class="fas fa-user-graduate"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?></strong>
                                                    <div class="text-muted small">
                                                        <?php echo htmlspecialchars($dette['matricule']); ?>
                                                    </div>
                                                    <div class="small">
                                                        <i class="fas fa-school"></i> <?php echo htmlspecialchars($dette['site_nom']); ?>
                                                        <br><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($dette['annee_academique']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div class="mb-1">
                                                    <small class="text-muted">Dû:</small>
                                                    <span class="montant-du"><?php echo formatMoney($dette['montant_du']); ?></span>
                                                </div>
                                                <div class="mb-1">
                                                    <small class="text-muted">Payé:</small>
                                                    <span class="montant-paye"><?php echo formatMoney($dette['montant_paye']); ?></span>
                                                </div>
                                                <div>
                                                    <small class="text-muted">Reste:</small>
                                                    <span class="montant-restant"><?php echo formatMoney($dette['montant_restant']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo getTypeDetteBadge($dette['type_dette']); ?>
                                            <?php if($dette['motif']): ?>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars(substr($dette['motif'], 0, 30)); ?>...</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="progress-dette mb-2">
                                                <div class="progress-bar 
                                                    <?php echo $pourcentage >= 100 ? 'bg-success' : ($pourcentage >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                                    role="progressbar" 
                                                    style="width: <?php echo $pourcentage; ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?php echo $pourcentage; ?>% payé</small>
                                            <div class="small">
                                                <?php echo formatMoney($dette['montant_paye']); ?> / <?php echo formatMoney($dette['montant_du']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if($dette['date_limite']): ?>
                                                <?php echo date('d/m/Y', strtotime($dette['date_limite'])); ?>
                                                <?php if($en_retard): ?>
                                                <div class="mt-1">
                                                    <span class="badge bg-danger retard-badge">
                                                        <i class="fas fa-exclamation-triangle"></i> Retard: <?php echo $jours_retard; ?> jours
                                                    </span>
                                                </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Non définie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo getStatutBadgeDette($dette['statut']); ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo $age_dette; ?></small>
                                            <div class="small">
                                                Créée: <?php echo date('d/m/Y', strtotime($dette['date_creation'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-info" title="Voir détails" onclick="voirDetails(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-warning" title="Modifier" onclick="modifierDette(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if($dette['statut'] != 'soldee'): ?>
                                                <button class="btn btn-success" title="Nouveau paiement" onclick="nouveauPaiement(<?php echo $dette['etudiant_id']; ?>, <?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </button>
                                                <button class="btn btn-secondary" title="Envoyer rappel" onclick="envoyerRappel(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-bell"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger" title="Supprimer" onclick="supprimerDette(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Vue Cartes -->
                    <div id="vue-cartes" style="display: none;">
                        <div class="row">
                            <?php foreach($dettes as $dette): 
                            $pourcentage = getPourcentagePaiement($dette['montant_du'], $dette['montant_paye']);
                            $age_dette = getAgeDette($dette['date_creation']);
                            $jours_retard = getJoursRetard($dette['date_limite']);
                            $en_retard = ($dette['statut'] == 'en_cours' && $dette['date_limite'] && strtotime($dette['date_limite']) < time());
                            ?>
                            <div class="col-md-4 mb-3">
                                <div class="card dette-card <?php echo $en_retard ? 'border-danger' : ''; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($dette['etudiant_prenom'] . ' ' . $dette['etudiant_nom']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($dette['matricule']); ?></small>
                                            </div>
                                            <?php echo getTypeDetteBadge($dette['type_dette']); ?>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="progress-dette mb-2">
                                                <div class="progress-bar 
                                                    <?php echo $pourcentage >= 100 ? 'bg-success' : ($pourcentage >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                                    role="progressbar" 
                                                    style="width: <?php echo $pourcentage; ?>%">
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo $pourcentage; ?>% payé</small>
                                                <small class="<?php echo $en_retard ? 'text-danger' : 'text-muted'; ?>">
                                                    <?php if($dette['date_limite']): ?>
                                                        <?php echo date('d/m/Y', strtotime($dette['date_limite'])); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-4">
                                                <small class="text-muted d-block">Dû</small>
                                                <strong class="montant-du"><?php echo formatMoney($dette['montant_du']); ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Payé</small>
                                                <strong class="montant-paye"><?php echo formatMoney($dette['montant_paye']); ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Reste</small>
                                                <strong class="montant-restant"><?php echo formatMoney($dette['montant_restant']); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <?php echo getStatutBadgeDette($dette['statut']); ?>
                                                <?php if($en_retard): ?>
                                                <span class="badge bg-danger ms-1">Retard: <?php echo $jours_retard; ?>j</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-info" title="Voir détails" onclick="voirDetails(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning" title="Modifier" onclick="modifierDette(<?php echo $dette['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if(count($dettes) > 10): ?>
            <nav aria-label="Navigation des dettes">
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
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal pour nouvelle dette -->
    <div class="modal fade" id="modalNouvelleDette" tabindex="-1" aria-labelledby="modalNouvelleDetteLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalNouvelleDetteLabel">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Nouvelle Dette
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="formNouvelleDette">
                    <input type="hidden" name="action" value="nouvelle_dette">
                    
                    <div class="modal-body">
                        <div class="form-section">
                            <h6><i class="fas fa-user-graduate me-2"></i>Informations Étudiant</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="etudiant_id" class="form-label required-field">Étudiant</label>
                                    <select class="form-select" id="etudiant_id" name="etudiant_id" required>
                                        <option value="">Sélectionner un étudiant</option>
                                        <?php foreach($etudiants as $etudiant): ?>
                                        <option value="<?php echo $etudiant['id']; ?>">
                                            <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="annee_academique_id" class="form-label required-field">Année académique</label>
                                    <select class="form-select" id="annee_academique_id" name="annee_academique_id" required>
                                        <option value="">Sélectionner une année</option>
                                        <?php foreach($annees_academiques as $annee): ?>
                                        <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['libelle']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-money-bill-wave me-2"></i>Informations Financières</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="type_dette" class="form-label required-field">Type de dette</label>
                                    <select class="form-select" id="type_dette" name="type_dette" required>
                                        <option value="scolarite">Scolarité</option>
                                        <option value="inscription">Inscription</option>
                                        <option value="autre">Autre</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="montant_du" class="form-label required-field">Montant dû (FCFA)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="montant_du" name="montant_du" min="0" step="100" required>
                                        <span class="input-group-text">FCFA</span>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="montant_paye" class="form-label">Montant déjà payé (FCFA)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="montant_paye" name="montant_paye" min="0" step="100" value="0">
                                        <span class="input-group-text">FCFA</span>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="date_limite" class="form-label required-field">Date limite</label>
                                    <input type="date" class="form-control" id="date_limite" name="date_limite" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="motif" class="form-label">Motif de la dette</label>
                                    <input type="text" class="form-control" id="motif" name="motif" placeholder="Ex: Scolarité 2025-2026...">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6><i class="fas fa-calendar-alt me-2"></i>Plan de paiement (Optionnel)</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="creer_plan" name="creer_plan">
                                        <label class="form-check-label" for="creer_plan">
                                            Créer un plan de paiement
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-6" id="div_nombre_tranches" style="display: none;">
                                    <label for="nombre_tranches" class="form-label">Nombre de tranches</label>
                                    <select class="form-select" id="nombre_tranches" name="nombre_tranches">
                                        <option value="1">1 (Paiement unique)</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="6">6</option>
                                        <option value="12">12</option>
                                    </select>
                                </div>
                            </div>
                            <div class="alert alert-info mt-2 small">
                                <i class="fas fa-info-circle"></i> 
                                Un plan de paiement permet de diviser le montant restant en plusieurs échéances.
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Note :</strong> Le système calculera automatiquement le montant restant et le statut de la dette.
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Créer la Dette
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour modification de dette -->
    <div class="modal fade" id="modalModificationDette" tabindex="-1" aria-labelledby="modalModificationDetteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title" id="modalModificationDetteLabel">
                        <i class="fas fa-edit me-2"></i>Modifier la Dette
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="formModificationDette">
                    <input type="hidden" name="action" value="maj_dette">
                    <input type="hidden" name="id" id="modif_dette_id">
                    
                    <div class="modal-body">
                        <div id="chargementModif" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Chargement...</span>
                            </div>
                            <p class="mt-2">Chargement des données...</p>
                        </div>
                        
                        <div id="contenuModif" style="display: none;">
                            <div class="mb-3">
                                <label for="modif_type_dette" class="form-label">Type de dette</label>
                                <select class="form-select" id="modif_type_dette" name="type_dette">
                                    <option value="scolarite">Scolarité</option>
                                    <option value="inscription">Inscription</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="modif_montant_du" class="form-label required-field">Montant dû (FCFA)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="modif_montant_du" name="montant_du" min="0" step="100" required>
                                    <span class="input-group-text">FCFA</span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="modif_montant_paye" class="form-label">Montant déjà payé (FCFA)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="modif_montant_paye" name="montant_paye" min="0" step="100" required>
                                    <span class="input-group-text">FCFA</span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="modif_date_limite" class="form-label required-field">Date limite</label>
                                <input type="date" class="form-control" id="modif_date_limite" name="date_limite" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="modif_statut" class="form-label required-field">Statut</label>
                                <select class="form-select" id="modif_statut" name="statut" required>
                                    <option value="en_cours">En cours</option>
                                    <option value="soldee">Soldée</option>
                                    <option value="en_retard">En retard</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="modif_motif" class="form-label">Motif</label>
                                <input type="text" class="form-control" id="modif_motif" name="motif">
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save me-2"></i>Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour suppression de dette -->
    <div class="modal fade" id="modalSuppressionDette" tabindex="-1" aria-labelledby="modalSuppressionDetteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="modalSuppressionDetteLabel">
                        <i class="fas fa-trash me-2"></i>Supprimer la Dette
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="formSuppressionDette">
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id" id="suppression_dette_id">
                    
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Attention !</strong> Cette action est irréversible. Toutes les informations liées à cette dette seront définitivement supprimées.
                        </div>
                        
                        <div id="infoSuppression" class="mb-3">
                            <!-- Les informations seront chargées ici -->
                        </div>
                        
                        <div class="mb-3">
                            <label for="motif_suppression" class="form-label required-field">Motif de suppression</label>
                            <textarea class="form-control" id="motif_suppression" name="motif" rows="3" required placeholder="Veuillez indiquer le motif de la suppression..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Confirmer la suppression
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal pour plan de paiement -->
    <div class="modal fade" id="modalPlanPaiement" tabindex="-1" aria-labelledby="modalPlanPaiementLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalPlanPaiementLabel">
                        <i class="fas fa-calendar-alt me-2"></i>Plan de Paiement
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="chargementPlan" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Chargement...</span>
                        </div>
                        <p class="mt-2">Chargement du plan de paiement...</p>
                    </div>
                    
                    <div id="contenuPlan" style="display: none;">
                        <!-- Le contenu sera chargé ici -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal pour envoi de rappel -->
    <div class="modal fade" id="modalRappelDette" tabindex="-1" aria-labelledby="modalRappelDetteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title" id="modalRappelDetteLabel">
                        <i class="fas fa-bell me-2"></i>Envoyer un Rappel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="formRappelDette">
                    <input type="hidden" name="action" value="envoyer_rappel">
                    <input type="hidden" name="id" id="rappel_dette_id">
                    
                    <div class="modal-body">
                        <div id="infoRappel" class="mb-3">
                            <!-- Les informations seront chargées ici -->
                        </div>
                        
                        <div class="mb-3">
                            <label for="type_rappel" class="form-label">Type de rappel</label>
                            <select class="form-select" id="type_rappel" name="type_rappel">
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                                <option value="appel">Appel téléphonique</option>
                                <option value="notification">Notification système</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="message_rappel" class="form-label">Message personnalisé</label>
                            <textarea class="form-control" id="message_rappel" name="message_rappel" rows="4" placeholder="Vous pouvez personnaliser le message ici..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Le message par défaut contient déjà les informations essentielles sur la dette.
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Envoyer le rappel
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
        if ($('#dettesTable').length) {
            $('#dettesTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
                },
                pageLength: 10,
                order: [[3, 'asc']], // Tri par date limite par défaut
                columnDefs: [
                    { orderable: false, targets: [7] }
                ]
            });
        }
        
        // Initialiser les graphiques
        initialiserGraphiques();
        
        // Initialiser la date limite à aujourd'hui + 30 jours
        const dateLimite = new Date();
        dateLimite.setDate(dateLimite.getDate() + 30);
        document.getElementById('date_limite').valueAsDate = dateLimite;
        
        // Gérer l'affichage du nombre de tranches
        document.getElementById('creer_plan').addEventListener('change', function() {
            document.getElementById('div_nombre_tranches').style.display = this.checked ? 'block' : 'none';
        });
    });
    
    // Initialiser les graphiques
    function initialiserGraphiques() {
        // Données pour le graphique des statuts
        const dataStatut = {
            labels: ['En cours', 'Soldées', 'En retard'],
            datasets: [{
                data: [
                    <?php echo $stats['par_statut']['en_cours']['count'] ?? 0; ?>,
                    <?php echo $stats['par_statut']['soldee']['count'] ?? 0; ?>,
                    <?php echo $stats['par_statut']['en_retard']['count'] ?? 0; ?>
                ],
                backgroundColor: [
                    'rgba(52, 152, 219, 0.8)',
                    'rgba(46, 204, 113, 0.8)',
                    'rgba(231, 76, 60, 0.8)'
                ],
                borderColor: [
                    'rgb(52, 152, 219)',
                    'rgb(46, 204, 113)',
                    'rgb(231, 76, 60)'
                ],
                borderWidth: 1
            }]
        };
        
        // Graphique des statuts
        const ctxStatut = document.getElementById('chartStatut');
        if (ctxStatut) {
            new Chart(ctxStatut, {
                type: 'doughnut',
                data: dataStatut,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // Données pour le graphique des types
        const dataType = {
            labels: ['Scolarité', 'Inscription', 'Autre'],
            datasets: [{
                label: 'Montant restant (FCFA)',
                data: [
                    <?php echo $stats['par_type']['scolarite']['montant'] ?? 0; ?>,
                    <?php echo $stats['par_type']['inscription']['montant'] ?? 0; ?>,
                    <?php echo $stats['par_type']['autre']['montant'] ?? 0; ?>
                ],
                backgroundColor: 'rgba(155, 89, 182, 0.8)',
                borderColor: 'rgb(155, 89, 182)',
                borderWidth: 1
            }]
        };
        
        // Graphique des types
        const ctxType = document.getElementById('chartType');
        if (ctxType) {
            new Chart(ctxType, {
                type: 'bar',
                data: dataType,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return new Intl.NumberFormat('fr-FR').format(context.raw) + ' FCFA';
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Formater un nombre avec séparateurs de milliers
    function formatNombre(nombre) {
        return new Intl.NumberFormat('fr-FR').format(Math.round(nombre));
    }
    
    // Modifier une dette
    function modifierDette(detteId) {
        document.getElementById('modif_dette_id').value = detteId;
        
        // Afficher le chargement
        document.getElementById('chargementModif').style.display = 'block';
        document.getElementById('contenuModif').style.display = 'none';
        
        // Charger les données de la dette
        fetch('get_dette_details.php?id=' + detteId)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                // Remplir le formulaire
                document.getElementById('modif_montant_du').value = data.montant_du;
                document.getElementById('modif_montant_paye').value = data.montant_paye;
                document.getElementById('modif_date_limite').value = data.date_limite;
                document.getElementById('modif_statut').value = data.statut;
                document.getElementById('modif_type_dette').value = data.type_dette || 'scolarite';
                document.getElementById('modif_motif').value = data.motif || '';
                
                // Cacher le chargement et afficher le contenu
                document.getElementById('chargementModif').style.display = 'none';
                document.getElementById('contenuModif').style.display = 'block';
                
                // Afficher le modal
                const modal = new bootstrap.Modal(document.getElementById('modalModificationDette'));
                modal.show();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Impossible de charger les données de la dette.');
            });
    }
    
    // Supprimer une dette
    function supprimerDette(detteId) {
        document.getElementById('suppression_dette_id').value = detteId;
        
        // Charger les informations de la dette
        fetch('get_dette_details.php?id=' + detteId)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                // Afficher les informations
                const infoHtml = `
                    <div class="alert alert-warning">
                        <strong>Dette à supprimer:</strong><br>
                        Étudiant: ${data.etudiant_nom}<br>
                        Montant dû: ${formatNombre(data.montant_du)} FCFA<br>
                        Montant payé: ${formatNombre(data.montant_paye)} FCFA<br>
                        Montant restant: ${formatNombre(data.montant_restant)} FCFA
                    </div>
                `;
                document.getElementById('infoSuppression').innerHTML = infoHtml;
                
                // Afficher le modal
                const modal = new bootstrap.Modal(document.getElementById('modalSuppressionDette'));
                modal.show();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Impossible de charger les données de la dette.');
            });
    }
    
    // Nouveau paiement pour une dette
    function nouveauPaiement(etudiantId, detteId) {
        // Rediriger vers la page des paiements avec pré-remplissage
        window.location.href = 'paiements.php?etudiant=' + etudiantId + '&dette=' + detteId;
    }
    
    // Envoyer un rappel
    function envoyerRappel(detteId) {
        document.getElementById('rappel_dette_id').value = detteId;
        
        // Charger les informations de la dette
        fetch('get_dette_details.php?id=' + detteId)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                // Afficher les informations
                const infoHtml = `
                    <div class="alert alert-info">
                        <strong>Rappel pour:</strong><br>
                        Étudiant: ${data.etudiant_nom}<br>
                        Matricule: ${data.matricule}<br>
                        Montant restant: ${formatNombre(data.montant_restant)} FCFA<br>
                        Date limite: ${data.date_limite ? new Date(data.date_limite).toLocaleDateString('fr-FR') : 'Non définie'}
                    </div>
                `;
                document.getElementById('infoRappel').innerHTML = infoHtml;
                
                // Pré-remplir le message
                const message = `Bonjour,\n\nRappel concernant votre dette pour l'année académique ${data.annee_academique}.\n\nDétails:\n- Montant dû: ${formatNombre(data.montant_du)} FCFA\n- Montant payé: ${formatNombre(data.montant_paye)} FCFA\n- Montant restant: ${formatNombre(data.montant_restant)} FCFA\n- Date limite: ${data.date_limite ? new Date(data.date_limite).toLocaleDateString('fr-FR') : 'Non définie'}\n\nMerci de régulariser votre situation au plus vite.\n\nCordialement,\nService Financier ISGI`;
                document.getElementById('message_rappel').value = message;
                
                // Afficher le modal
                const modal = new bootstrap.Modal(document.getElementById('modalRappelDette'));
                modal.show();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Impossible de charger les données de la dette.');
            });
    }
    
    // Voir les détails d'une dette
    function voirDetails(detteId) {
        window.location.href = 'details_dette.php?id=' + detteId;
    }
    
    // Voir le plan de paiement
    function voirPlanPaiement(detteId) {
        document.getElementById('chargementPlan').style.display = 'block';
        document.getElementById('contenuPlan').style.display = 'none';
        
        // Charger le plan de paiement
        fetch('get_plan_paiement.php?dette_id=' + detteId)
            .then(response => response.json())
            .then(data => {
                document.getElementById('chargementPlan').style.display = 'none';
                document.getElementById('contenuPlan').style.display = 'block';
                
                let html = '';
                if (data.plan) {
                    html = `
                        <h6>Plan de paiement</h6>
                        <div class="mb-3">
                            <p><strong>Nombre de tranches:</strong> ${data.plan.nombre_tranches}</p>
                            <p><strong>Montant par tranche:</strong> ${formatNombre(data.plan.montant_tranche)} FCFA</p>
                            <p><strong>Fréquence:</strong> ${data.plan.frequence}</p>
                            <p><strong>Date de début:</strong> ${new Date(data.plan.date_debut).toLocaleDateString('fr-FR')}</p>
                        </div>
                        <h6>Échéances</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Montant</th>
                                        <th>Date échéance</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    data.echeances.forEach(echeance => {
                        const statusClass = echeance.statut === 'payee' ? 'badge bg-success' : 
                                          (echeance.statut === 'en_retard' ? 'badge bg-danger' : 'badge bg-warning');
                        html += `
                            <tr>
                                <td>${echeance.numero_tranche}</td>
                                <td>${formatNombre(echeance.montant)} FCFA</td>
                                <td>${new Date(echeance.date_echeance).toLocaleDateString('fr-FR')}</td>
                                <td><span class="${statusClass}">${echeance.statut}</span></td>
                            </tr>
                        `;
                    });
                    
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                } else {
                    html = '<div class="alert alert-info">Aucun plan de paiement pour cette dette.</div>';
                }
                
                document.getElementById('contenuPlan').innerHTML = html;
                
                // Afficher le modal
                const modal = new bootstrap.Modal(document.getElementById('modalPlanPaiement'));
                modal.show();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Impossible de charger le plan de paiement.');
            });
    }
    
    // Changer de vue (tableau/cartes)
    function voirVueTableau() {
        document.getElementById('vue-tableau').style.display = 'block';
        document.getElementById('vue-cartes').style.display = 'none';
    }
    
    function voirVueCartes() {
        document.getElementById('vue-tableau').style.display = 'none';
        document.getElementById('vue-cartes').style.display = 'block';
    }
    
    // Imprimer la liste
    function imprimerListe() {
        window.print();
    }
    
    // Exporter les dettes
    function exporterDettes() {
        // Récupérer les filtres actuels
        const params = new URLSearchParams(window.location.search);
        
        // Construir l'URL d'export
        let url = 'export_dettes.php?';
        params.forEach((value, key) => {
            url += key + '=' + encodeURIComponent(value) + '&';
        });
        
        // Ouvrir l'export dans un nouvel onglet
        window.open(url, '_blank');
    }
    
    // Rafraîchir les données
    function rafraichirDonnees() {
        window.location.reload();
    }
    
    // Validation du formulaire de nouvelle dette
    document.querySelector('#formNouvelleDette').addEventListener('submit', function(e) {
        const montantDu = parseFloat(document.getElementById('montant_du').value);
        const montantPaye = parseFloat(document.getElementById('montant_paye').value || 0);
        
        if (montantDu <= 0) {
            e.preventDefault();
            alert('Le montant dû doit être supérieur à 0');
            return false;
        }
        
        if (montantPaye < 0) {
            e.preventDefault();
            alert('Le montant payé ne peut pas être négatif');
            return false;
        }
        
        if (montantPaye > montantDu) {
            e.preventDefault();
            alert('Le montant payé ne peut pas dépasser le montant dû');
            return false;
        }
        
        return true;
    });
    
    // Validation du formulaire de modification
    document.querySelector('#formModificationDette').addEventListener('submit', function(e) {
        const montantDu = parseFloat(document.getElementById('modif_montant_du').value);
        const montantPaye = parseFloat(document.getElementById('modif_montant_paye').value || 0);
        
        if (montantDu <= 0) {
            e.preventDefault();
            alert('Le montant dû doit être supérieur à 0');
            return false;
        }
        
        if (montantPaye < 0) {
            e.preventDefault();
            alert('Le montant payé ne peut pas être négatif');
            return false;
        }
        
        if (montantPaye > montantDu) {
            e.preventDefault();
            alert('Le montant payé ne peut pas dépasser le montant dû');
            return false;
        }
        
        return true;
    });
    
    // Validation du formulaire de rappel
    document.querySelector('#formRappelDette').addEventListener('submit', function(e) {
        const message = document.getElementById('message_rappel').value;
        if (!message || message.trim() === '') {
            e.preventDefault();
            alert('Veuillez saisir un message pour le rappel.');
            return false;
        }
        return true;
    });
    </script>
</body>
</html>