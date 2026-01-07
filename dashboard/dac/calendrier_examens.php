<?php
// dashboard/dac/calendrier_examens.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle DAC (ID 5 dans la table roles)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
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
    $pageTitle = "DAC - Calendrier des Examens";
    
    // Récupérer l'ID du site de l'utilisateur
    $site_id = $_SESSION['site_id'] ?? null;
    
    // Fonctions utilitaires
    function formatMoney($amount) {
        if ($amount === null || $amount === '' || $amount == 0) return '0 FCFA';
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'planifie':
                return '<span class="badge bg-warning">Planifié</span>';
            case 'en_cours':
                return '<span class="badge bg-info">En cours</span>';
            case 'termine':
                return '<span class="badge bg-success">Terminé</span>';
            case 'annule':
                return '<span class="badge bg-danger">Annulé</span>';
            case 'reporte':
                return '<span class="badge bg-secondary">Reporté</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Variables pour les actions
    $action = $_GET['action'] ?? 'list';
    $id = $_GET['id'] ?? null;
    $filiere_id = $_GET['filiere_id'] ?? null;
    $classe_id = $_GET['classe_id'] ?? null;
    $matiere_id = $_GET['matiere_id'] ?? null;
    $type_examen_id = $_GET['type_examen_id'] ?? null;
    $semestre = $_GET['semestre'] ?? null;
    $mois = $_GET['mois'] ?? null;
    
    // Traitement des actions
    switch ($action) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                // Récupération des données du formulaire
                $data = [
                    'calendrier_academique_id' => $_POST['calendrier_academique_id'],
                    'matiere_id' => $_POST['matiere_id'],
                    'classe_id' => $_POST['classe_id'],
                    'type_examen_id' => $_POST['type_examen_id'],
                    'enseignant_id' => $_POST['enseignant_id'] ?: null,
                    'date_examen' => $_POST['date_examen'],
                    'heure_debut' => $_POST['heure_debut'],
                    'heure_fin' => $_POST['heure_fin'],
                    'duree_minutes' => $_POST['duree_minutes'],
                    'salle' => $_POST['salle'],
                    'nombre_places' => $_POST['nombre_places'],
                    'type_evaluation' => $_POST['type_evaluation'],
                    'coefficient' => $_POST['coefficient'],
                    'bareme' => $_POST['bareme'],
                    'consignes' => $_POST['consignes'],
                    'documents_autorises' => $_POST['documents_autorises'],
                    'materiel_requis' => $_POST['materiel_requis'],
                    'statut' => $_POST['statut'],
                    'cree_par' => $_SESSION['user_id']
                ];
                
                // Vérifier la disponibilité de la salle
                $query = "SELECT COUNT(*) as count FROM calendrier_examens 
                         WHERE salle = :salle AND date_examen = :date_examen 
                         AND ((heure_debut < :heure_fin AND heure_fin > :heure_debut)
                         OR id != :exclude_id)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'salle' => $data['salle'],
                    'date_examen' => $data['date_examen'],
                    'heure_debut' => $data['heure_debut'],
                    'heure_fin' => $data['heure_fin'],
                    'exclude_id' => 0
                ]);
                $salle_occupee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($salle_occupee['count'] > 0) {
                    $_SESSION['error_message'] = "La salle est déjà occupée à cette heure";
                    header('Location: calendrier_examens.php?action=create');
                    exit();
                }
                
                // Vérifier la disponibilité de la classe
                $query = "SELECT COUNT(*) as count FROM calendrier_examens 
                         WHERE classe_id = :classe_id AND date_examen = :date_examen 
                         AND ((heure_debut < :heure_fin AND heure_fin > :heure_debut)
                         OR id != :exclude_id)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'classe_id' => $data['classe_id'],
                    'date_examen' => $data['date_examen'],
                    'heure_debut' => $data['heure_debut'],
                    'heure_fin' => $data['heure_fin'],
                    'exclude_id' => 0
                ]);
                $classe_occupee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($classe_occupee['count'] > 0) {
                    $_SESSION['error_message'] = "La classe a déjà un examen à cette heure";
                    header('Location: calendrier_examens.php?action=create');
                    exit();
                }
                
                // Insertion dans la base de données
                $query = "INSERT INTO calendrier_examens 
                         (calendrier_academique_id, matiere_id, classe_id, type_examen_id, 
                          enseignant_id, date_examen, heure_debut, heure_fin, duree_minutes, 
                          salle, nombre_places, type_evaluation, coefficient, bareme, 
                          consignes, documents_autorises, materiel_requis, statut, cree_par)
                         VALUES (:calendrier_academique_id, :matiere_id, :classe_id, :type_examen_id, 
                                 :enseignant_id, :date_examen, :heure_debut, :heure_fin, :duree_minutes, 
                                 :salle, :nombre_places, :type_evaluation, :coefficient, :bareme, 
                                 :consignes, :documents_autorises, :materiel_requis, :statut, :cree_par)";
                
                $stmt = $db->prepare($query);
                $result = $stmt->execute($data);
                
                if ($result) {
                    $examen_id = $db->lastInsertId();
                    
                    // Ajouter les surveillants si spécifiés
                    if (isset($_POST['surveillants']) && is_array($_POST['surveillants'])) {
                        foreach ($_POST['surveillants'] as $surveillant_id) {
                            if (!empty($surveillant_id)) {
                                // Note: Dans la structure actuelle, surveillants est un champ TEXT
                                // On va mettre à jour le champ existant
                                $query = "UPDATE calendrier_examens 
                                         SET surveillants = CONCAT(COALESCE(surveillants, ''), :surveillant, ',')
                                         WHERE id = :id";
                                $stmt = $db->prepare($query);
                                $stmt->execute([
                                    'surveillant' => $surveillant_id,
                                    'id' => $examen_id
                                ]);
                            }
                        }
                    }
                    
                    $_SESSION['success_message'] = "Examen planifié avec succès";
                    header('Location: calendrier_examens.php?action=view&id=' . $examen_id);
                    exit();
                } else {
                    $_SESSION['error_message'] = "Erreur lors de la planification de l'examen";
                }
            }
            break;
            
        case 'edit':
            if ($id && $_SERVER['REQUEST_METHOD'] == 'POST') {
                // Mise à jour de l'examen
                $data = [
                    'id' => $id,
                    'matiere_id' => $_POST['matiere_id'],
                    'classe_id' => $_POST['classe_id'],
                    'type_examen_id' => $_POST['type_examen_id'],
                    'enseignant_id' => $_POST['enseignant_id'] ?: null,
                    'date_examen' => $_POST['date_examen'],
                    'heure_debut' => $_POST['heure_debut'],
                    'heure_fin' => $_POST['heure_fin'],
                    'duree_minutes' => $_POST['duree_minutes'],
                    'salle' => $_POST['salle'],
                    'nombre_places' => $_POST['nombre_places'],
                    'type_evaluation' => $_POST['type_evaluation'],
                    'coefficient' => $_POST['coefficient'],
                    'bareme' => $_POST['bareme'],
                    'consignes' => $_POST['consignes'],
                    'documents_autorises' => $_POST['documents_autorises'],
                    'materiel_requis' => $_POST['materiel_requis'],
                    'statut' => $_POST['statut'],
                    'modifie_par' => $_SESSION['user_id']
                ];
                
                // Vérifier la disponibilité de la salle (exclure l'examen actuel)
                $query = "SELECT COUNT(*) as count FROM calendrier_examens 
                         WHERE salle = :salle AND date_examen = :date_examen 
                         AND ((heure_debut < :heure_fin AND heure_fin > :heure_debut)
                         AND id != :exclude_id)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    'salle' => $data['salle'],
                    'date_examen' => $data['date_examen'],
                    'heure_debut' => $data['heure_debut'],
                    'heure_fin' => $data['heure_fin'],
                    'exclude_id' => $id
                ]);
                $salle_occupee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($salle_occupee['count'] > 0) {
                    $_SESSION['error_message'] = "La salle est déjà occupée à cette heure";
                    header('Location: calendrier_examens.php?action=edit&id=' . $id);
                    exit();
                }
                
                // Mise à jour
                $query = "UPDATE calendrier_examens 
                         SET matiere_id = :matiere_id, classe_id = :classe_id, 
                         type_examen_id = :type_examen_id, enseignant_id = :enseignant_id,
                         date_examen = :date_examen, heure_debut = :heure_debut, 
                         heure_fin = :heure_fin, duree_minutes = :duree_minutes,
                         salle = :salle, nombre_places = :nombre_places,
                         type_evaluation = :type_evaluation, coefficient = :coefficient,
                         bareme = :bareme, consignes = :consignes,
                         documents_autorises = :documents_autorises, 
                         materiel_requis = :materiel_requis, statut = :statut,
                         modifie_par = :modifie_par, date_modification = NOW()
                         WHERE id = :id";
                
                $stmt = $db->prepare($query);
                $result = $stmt->execute($data);
                
                if ($result) {
                    $_SESSION['success_message'] = "Examen mis à jour avec succès";
                    header('Location: calendrier_examens.php?action=view&id=' . $id);
                    exit();
                } else {
                    $_SESSION['error_message'] = "Erreur lors de la mise à jour";
                }
            }
            break;
            
        case 'cancel':
            if ($id) {
                $query = "UPDATE calendrier_examens 
                         SET statut = 'annule', motif_annulation = :motif,
                         modifie_par = :user_id, date_modification = NOW()
                         WHERE id = :id AND EXISTS (
                             SELECT 1 FROM classes c WHERE c.id = calendrier_examens.classe_id 
                             AND c.site_id = :site_id
                         )";
                $stmt = $db->prepare($query);
                $result = $stmt->execute([
                    'motif' => $_POST['motif'] ?? 'Annulation par le DAC',
                    'user_id' => $_SESSION['user_id'],
                    'id' => $id,
                    'site_id' => $site_id
                ]);
                
                if ($result) {
                    $_SESSION['success_message'] = "Examen annulé avec succès";
                } else {
                    $_SESSION['error_message'] = "Erreur lors de l'annulation";
                }
                header('Location: calendrier_examens.php');
                exit();
            }
            break;
            
        case 'publish':
            if ($id) {
                $query = "UPDATE calendrier_examens 
                         SET publie_etudiants = 1, date_modification = NOW()
                         WHERE id = :id AND EXISTS (
                             SELECT 1 FROM classes c WHERE c.id = calendrier_examens.classe_id 
                             AND c.site_id = :site_id
                         )";
                $stmt = $db->prepare($query);
                $result = $stmt->execute(['id' => $id, 'site_id' => $site_id]);
                
                if ($result) {
                    $_SESSION['success_message'] = "Calendrier publié aux étudiants";
                } else {
                    $_SESSION['error_message'] = "Erreur lors de la publication";
                }
                header('Location: calendrier_examens.php?action=view&id=' . $id);
                exit();
            }
            break;
            
        case 'duplicate':
            if ($id) {
                // Récupérer l'examen à dupliquer
                $query = "SELECT * FROM calendrier_examens WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->execute(['id' => $id]);
                $examen = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($examen) {
                    // Créer une copie avec une date différente
                    $examen['date_examen'] = date('Y-m-d', strtotime($examen['date_examen'] . ' +7 days'));
                    $examen['statut'] = 'planifie';
                    $examen['notes_saisies'] = 0;
                    $examen['notes_validees'] = 0;
                    $examen['date_publication_notes'] = null;
                    $examen['cree_par'] = $_SESSION['user_id'];
                    
                    unset($examen['id']);
                    unset($examen['date_creation']);
                    unset($examen['date_modification']);
                    
                    // Insérer la copie
                    $columns = implode(', ', array_keys($examen));
                    $values = ':' . implode(', :', array_keys($examen));
                    
                    $query = "INSERT INTO calendrier_examens ($columns) VALUES ($values)";
                    $stmt = $db->prepare($query);
                    $result = $stmt->execute($examen);
                    
                    if ($result) {
                        $new_id = $db->lastInsertId();
                        $_SESSION['success_message'] = "Examen dupliqué avec succès";
                        header('Location: calendrier_examens.php?action=edit&id=' . $new_id);
                        exit();
                    }
                }
            }
            break;
    }
    
    // Récupérer les données pour les listes déroulantes
    $filieres = [];
    $classes = [];
    $matieres = [];
    $enseignants = [];
    $types_examens = [];
    $calendriers_academiques = [];
    $salles_disponibles = ['Salle A', 'Salle B', 'Salle C', 'Salle D', 'Amphi 1', 'Amphi 2', 'Labo Info', 'Labo Langues'];
    
    if ($site_id) {
        // Récupérer les filières
        $query = "SELECT f.id, f.nom, o.nom as option_nom 
                 FROM filieres f 
                 JOIN options_formation o ON f.option_id = o.id
                 ORDER BY f.nom";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les classes
        $query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle,
                 aa.libelle as annee_libelle
                 FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN niveaux n ON c.niveau_id = n.id
                 JOIN annees_academiques aa ON c.annee_academique_id = aa.id
                 WHERE c.site_id = :site_id
                 ORDER BY f.nom, n.ordre";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les matières
        $query = "SELECT m.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
                 FROM matieres m 
                 JOIN filieres f ON m.filiere_id = f.id
                 JOIN niveaux n ON m.niveau_id = n.id
                 WHERE m.site_id = :site_id
                 ORDER BY m.code";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les enseignants
        $query = "SELECT e.*, CONCAT(u.nom, ' ', u.prenom) as nom_complet, u.email,
                 s.nom as site_nom
                 FROM enseignants e
                 JOIN utilisateurs u ON e.utilisateur_id = u.id
                 JOIN sites s ON e.site_id = s.id
                 WHERE e.site_id = :site_id AND e.statut = 'actif'
                 ORDER BY u.nom, u.prenom";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les types d'examens
        $query = "SELECT * FROM types_examens ORDER BY ordre";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $types_examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les calendriers académiques
        $query = "SELECT ca.*, s.nom as site_nom, aa.libelle as annee_libelle
                 FROM calendrier_academique ca
                 JOIN sites s ON ca.site_id = s.id
                 JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                 WHERE ca.site_id = :site_id AND ca.statut IN ('planifie', 'en_cours')
                 ORDER BY ca.date_debut_cours DESC";
        $stmt = $db->prepare($query);
        $stmt->execute(['site_id' => $site_id]);
        $calendriers_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Récupérer les examens selon l'action
    $examens = [];
    $examen_details = null;
    $statistiques = [];
    
    if ($site_id) {
        switch ($action) {
            case 'view':
                if ($id) {
                    // Récupérer les détails d'un examen spécifique
                    $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                             c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle,
                             te.nom as type_examen, te.pourcentage as type_pourcentage,
                             CONCAT(u.nom, ' ', u.prenom) as enseignant_nom, u.email as enseignant_email,
                             ca.semestre, ca.type_rentree, aa.libelle as annee_libelle,
                             CONCAT(uc.nom, ' ', uc.prenom) as createur_nom,
                             CONCAT(um.nom, ' ', um.prenom) as modificateur_nom,
                             CONCAT(uv.nom, ' ', uv.prenom) as validateur_nom
                             FROM calendrier_examens ce
                             JOIN matieres m ON ce.matiere_id = m.id
                             JOIN classes c ON ce.classe_id = c.id
                             JOIN filieres f ON c.filiere_id = f.id
                             JOIN niveaux n ON c.niveau_id = n.id
                             JOIN types_examens te ON ce.type_examen_id = te.id
                             LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                             LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                             LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                             LEFT JOIN annees_academiques aa ON ca.annee_academique_id = aa.id
                             LEFT JOIN utilisateurs uc ON ce.cree_par = uc.id
                             LEFT JOIN utilisateurs um ON ce.modifie_par = um.id
                             LEFT JOIN utilisateurs uv ON ce.valide_par = uv.id
                             WHERE ce.id = :id AND c.site_id = :site_id";
                    
                    $stmt = $db->prepare($query);
                    $stmt->execute(['id' => $id, 'site_id' => $site_id]);
                    $examen_details = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                break;
                
            case 'calendar':
                // Vue calendrier
                $query = "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom,
                         te.nom as type_examen, CONCAT(u.nom, ' ', u.prenom) as enseignant_nom
                         FROM calendrier_examens ce
                         JOIN matieres m ON ce.matiere_id = m.id
                         JOIN classes c ON ce.classe_id = c.id
                         JOIN types_examens te ON ce.type_examen_id = te.id
                         LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                         LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                         WHERE c.site_id = :site_id";
                
                $params = ['site_id' => $site_id];
                
                if ($mois) {
                    $query .= " AND MONTH(ce.date_examen) = :mois AND YEAR(ce.date_examen) = YEAR(CURDATE())";
                    $params['mois'] = $mois;
                }
                
                if ($semestre) {
                    $query .= " AND EXISTS (
                        SELECT 1 FROM calendrier_academique ca 
                        WHERE ca.id = ce.calendrier_academique_id AND ca.semestre = :semestre
                    )";
                    $params['semestre'] = $semestre;
                }
                
                $query .= " ORDER BY ce.date_examen, ce.heure_debut";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            default:
                // Liste des examens avec filtres
                $where = "c.site_id = :site_id";
                $params = ['site_id' => $site_id];
                
                if ($filiere_id) {
                    $where .= " AND f.id = :filiere_id";
                    $params['filiere_id'] = $filiere_id;
                }
                
                if ($classe_id) {
                    $where .= " AND ce.classe_id = :classe_id";
                    $params['classe_id'] = $classe_id;
                }
                
                if ($matiere_id) {
                    $where .= " AND ce.matiere_id = :matiere_id";
                    $params['matiere_id'] = $matiere_id;
                }
                
                if ($type_examen_id) {
                    $where .= " AND ce.type_examen_id = :type_examen_id";
                    $params['type_examen_id'] = $type_examen_id;
                }
                
                $query = "SELECT ce.*, m.nom as matiere_nom, m.code as matiere_code,
                         c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle,
                         te.nom as type_examen, CONCAT(u.nom, ' ', u.prenom) as enseignant_nom,
                         ca.semestre, ca.type_rentree
                         FROM calendrier_examens ce
                         JOIN matieres m ON ce.matiere_id = m.id
                         JOIN classes c ON ce.classe_id = c.id
                         JOIN filieres f ON c.filiere_id = f.id
                         JOIN niveaux n ON c.niveau_id = n.id
                         JOIN types_examens te ON ce.type_examen_id = te.id
                         LEFT JOIN enseignants e ON ce.enseignant_id = e.id
                         LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                         LEFT JOIN calendrier_academique ca ON ce.calendrier_academique_id = ca.id
                         WHERE $where
                         ORDER BY ce.date_examen DESC, ce.heure_debut DESC
                         LIMIT 100";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Statistiques
                $query = "SELECT 
                            COUNT(*) as total_examens,
                            SUM(CASE WHEN ce.statut = 'planifie' THEN 1 ELSE 0 END) as planifies,
                            SUM(CASE WHEN ce.statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                            SUM(CASE WHEN ce.statut = 'termine' THEN 1 ELSE 0 END) as termines,
                            SUM(CASE WHEN ce.statut = 'annule' THEN 1 ELSE 0 END) as annules,
                            SUM(CASE WHEN ce.publie_etudiants = 1 THEN 1 ELSE 0 END) as publies,
                            SUM(CASE WHEN ce.notes_validees = 1 THEN 1 ELSE 0 END) as notes_validees
                         FROM calendrier_examens ce
                         JOIN classes c ON ce.classe_id = c.id
                         WHERE c.site_id = :site_id";
                
                $stmt = $db->prepare($query);
                $stmt->execute(['site_id' => $site_id]);
                $statistiques = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
        }
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
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
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
        background: var(--info-color);
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
        background-color: var(--info-color);
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
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Tableaux */
    .table {
        color: var(--text-color);
    }
    
    .table thead th {
        background-color: var(--info-color);
        color: white;
        border: none;
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 12px 15px;
        color: var(--text-color);
    }
    
    /* Badges */
    .badge-stat {
        padding: 8px 15px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    /* Boutons d'action */
    .btn-action {
        padding: 5px 10px;
        margin: 2px;
        border-radius: 5px;
        font-size: 0.85rem;
    }
    
    /* Filtres */
    .filter-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    /* Calendrier FullCalendar */
    .fc {
        background: var(--card-bg);
        border-radius: 10px;
        padding: 15px;
    }
    
    .fc-toolbar-title {
        color: var(--text-color) !important;
    }
    
    .fc-col-header-cell {
        background-color: var(--info-color) !important;
        color: white !important;
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
    
    /* Carte examen */
    .exam-card {
        border-left: 4px solid var(--info-color);
        transition: transform 0.2s;
    }
    
    .exam-card:hover {
        transform: translateY(-2px);
    }
    
    .exam-card.dst { border-left-color: #ffc107; }
    .exam-card.recherche { border-left-color: #17a2b8; }
    .exam-card.session { border-left-color: #dc3545; }
    
    /* Alertes */
    .alert {
        border-radius: 10px;
        border: none;
    }
    
    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--info-color);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -23px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--info-color);
        border: 2px solid white;
    }
    
    /* Modal */
    .modal-content {
        background-color: var(--card-bg);
        color: var(--text-color);
    }
    
    /* Stat cards */
    .stat-card {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        color: white;
    }
    
    .stat-card.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .stat-card.planifie { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .stat-card.termine { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .stat-card.publie { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
    
    /* Date picker */
    .date-highlight {
        background-color: rgba(23, 162, 184, 0.1);
        border-radius: 5px;
        padding: 2px 8px;
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
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Calendrier Examens</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></p>
                <small>Planification Examens</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link <?php echo $action == 'list' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Liste Examens</span>
                    </a>
                    <a href="calendrier_examens.php?action=calendar" class="nav-link <?php echo $action == 'calendar' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar"></i>
                        <span>Vue Calendrier</span>
                    </a>
                    <a href="calendrier_examens.php?action=create" class="nav-link <?php echo $action == 'create' ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i>
                        <span>Planifier Examen</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Filtres Rapides</div>
                    <a href="calendrier_examens.php?statut=planifie" class="nav-link">
                        <i class="fas fa-clock"></i>
                        <span>Examens Planifiés</span>
                    </a>
                    <a href="calendrier_examens.php?statut=termine" class="nav-link">
                        <i class="fas fa-check-circle"></i>
                        <span>Examens Terminés</span>
                    </a>
                    <a href="calendrier_examens.php?publie_etudiants=0" class="nav-link">
                        <i class="fas fa-eye-slash"></i>
                        <span>Non Publiés</span>
                    </a>
                    <a href="calendrier_examens.php?date=<?php echo date('Y-m-d'); ?>" class="nav-link">
                        <i class="fas fa-calendar-day"></i>
                        <span>Aujourd'hui</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Actions</div>
                    <a href="export_data.php?type=calendrier_examens" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Exporter Calendrier</span>
                    </a>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar-week"></i>
                        <span>Calendrier Académique</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Saisie Notes</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="<?php echo ROOT_PATH; ?>/auth/logout.php" class="nav-link">
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
                            <i class="fas fa-calendar-alt me-2"></i>
                            Calendrier des Examens
                        </h2>
                        <p class="text-muted mb-0">Planification et gestion des examens</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-info" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                        <?php if($action == 'create' || $action == 'edit'): ?>
                        <a href="calendrier_examens.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Annuler
                        </a>
                        <?php else: ?>
                        <a href="calendrier_examens.php?action=create" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>Planifier Examen
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); endif; ?>
            
            <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); endif; ?>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Contenu selon l'action -->
            <?php switch($action): case 'create': case 'edit': ?>
                <!-- Formulaire de création/modification -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-<?php echo $action == 'create' ? 'plus' : 'edit'; ?> me-2"></i>
                                    <?php echo $action == 'create' ? 'Planifier un Nouvel Examen' : 'Modifier l\'Examen'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="calendrier_examens.php?action=<?php echo $action; ?><?php echo $id ? '&id=' . $id : ''; ?>" id="formExamen">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Calendrier Académique <span class="text-danger">*</span></label>
                                            <select name="calendrier_academique_id" class="form-select" required>
                                                <option value="">Sélectionner un calendrier</option>
                                                <?php foreach($calendriers_academiques as $cal): ?>
                                                <option value="<?php echo $cal['id']; ?>" 
                                                    <?php echo ($examen_details['calendrier_academique_id'] ?? '') == $cal['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cal['annee_libelle'] . ' - Semestre ' . $cal['semestre'] . ' (' . $cal['type_rentree'] . ')'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Type d'Examen <span class="text-danger">*</span></label>
                                            <select name="type_examen_id" class="form-select" required>
                                                <option value="">Sélectionner un type</option>
                                                <?php foreach($types_examens as $type): ?>
                                                <option value="<?php echo $type['id']; ?>" 
                                                    <?php echo ($examen_details['type_examen_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($type['nom'] . ' (' . $type['pourcentage'] . '%)'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Filière <span class="text-danger">*</span></label>
                                            <select name="filiere_id" id="filiere_id" class="form-select" required onchange="chargerClasses()">
                                                <option value="">Sélectionner une filière</option>
                                                <?php foreach($filieres as $filiere): ?>
                                                <option value="<?php echo $filiere['id']; ?>">
                                                    <?php echo htmlspecialchars($filiere['nom']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Classe <span class="text-danger">*</span></label>
                                            <select name="classe_id" id="classe_id" class="form-select" required onchange="chargerMatieres()">
                                                <option value="">Sélectionner une classe</option>
                                                <?php foreach($classes as $classe): ?>
                                                <option value="<?php echo $classe['id']; ?>" 
                                                    data-filiere="<?php echo $classe['filiere_id']; ?>"
                                                    <?php echo ($examen_details['classe_id'] ?? '') == $classe['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($classe['nom'] . ' - ' . $classe['filiere_nom'] . ' ' . $classe['niveau_libelle']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Matière <span class="text-danger">*</span></label>
                                            <select name="matiere_id" id="matiere_id" class="form-select" required>
                                                <option value="">Sélectionner une matière</option>
                                                <?php foreach($matieres as $matiere): ?>
                                                <option value="<?php echo $matiere['id']; ?>" 
                                                    data-filiere="<?php echo $matiere['filiere_id']; ?>"
                                                    <?php echo ($examen_details['matiere_id'] ?? '') == $matiere['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($matiere['code'] . ' - ' . $matiere['nom']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Enseignant Responsable</label>
                                            <select name="enseignant_id" class="form-select">
                                                <option value="">Non attribué</option>
                                                <?php foreach($enseignants as $enseignant): ?>
                                                <option value="<?php echo $enseignant['id']; ?>" 
                                                    <?php echo ($examen_details['enseignant_id'] ?? '') == $enseignant['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($enseignant['nom_complet'] . ' (' . $enseignant['specialite'] . ')'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Date de l'examen <span class="text-danger">*</span></label>
                                            <input type="date" name="date_examen" class="form-control" required
                                                   value="<?php echo $examen_details['date_examen'] ?? date('Y-m-d'); ?>"
                                                   min="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Heure de début <span class="text-danger">*</span></label>
                                            <input type="time" name="heure_debut" class="form-control" required
                                                   value="<?php echo $examen_details['heure_debut'] ?? '08:00'; ?>">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Heure de fin <span class="text-danger">*</span></label>
                                            <input type="time" name="heure_fin" class="form-control" required
                                                   value="<?php echo $examen_details['heure_fin'] ?? '10:00'; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Durée (minutes)</label>
                                            <input type="number" name="duree_minutes" class="form-control" 
                                                   value="<?php echo $examen_details['duree_minutes'] ?? 120; ?>" min="30" max="240">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Salle <span class="text-danger">*</span></label>
                                            <select name="salle" class="form-select" required>
                                                <option value="">Sélectionner une salle</option>
                                                <?php foreach($salles_disponibles as $salle): ?>
                                                <option value="<?php echo $salle; ?>"
                                                    <?php echo ($examen_details['salle'] ?? '') == $salle ? 'selected' : ''; ?>>
                                                    <?php echo $salle; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Nombre de places</label>
                                            <input type="number" name="nombre_places" class="form-control" 
                                                   value="<?php echo $examen_details['nombre_places'] ?? 30; ?>" min="1" max="100">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Type d'évaluation</label>
                                            <select name="type_evaluation" class="form-select">
                                                <option value="ecrit" <?php echo ($examen_details['type_evaluation'] ?? 'ecrit') == 'ecrit' ? 'selected' : ''; ?>>Écrit</option>
                                                <option value="oral" <?php echo ($examen_details['type_evaluation'] ?? '') == 'oral' ? 'selected' : ''; ?>>Oral</option>
                                                <option value="pratique" <?php echo ($examen_details['type_evaluation'] ?? '') == 'pratique' ? 'selected' : ''; ?>>Pratique</option>
                                                <option value="projet" <?php echo ($examen_details['type_evaluation'] ?? '') == 'projet' ? 'selected' : ''; ?>>Projet</option>
                                                <option value="tp" <?php echo ($examen_details['type_evaluation'] ?? '') == 'tp' ? 'selected' : ''; ?>>TP</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Coefficient</label>
                                            <input type="number" name="coefficient" class="form-control" step="0.1"
                                                   value="<?php echo $examen_details['coefficient'] ?? 1.0; ?>" min="0.1" max="5">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Barème (/20)</label>
                                            <input type="number" name="bareme" class="form-control" step="0.5"
                                                   value="<?php echo $examen_details['bareme'] ?? 20.0; ?>" min="5" max="20">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Consignes particulières</label>
                                        <textarea name="consignes" class="form-control" rows="3"><?php echo $examen_details['consignes'] ?? ''; ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Documents autorisés</label>
                                            <textarea name="documents_autorises" class="form-control" rows="2"><?php echo $examen_details['documents_autorises'] ?? ''; ?></textarea>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Matériel requis</label>
                                            <textarea name="materiel_requis" class="form-control" rows="2"><?php echo $examen_details['materiel_requis'] ?? ''; ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Statut</label>
                                        <select name="statut" class="form-select">
                                            <option value="planifie" <?php echo ($examen_details['statut'] ?? 'planifie') == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                            <option value="en_cours" <?php echo ($examen_details['statut'] ?? '') == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                            <option value="termine" <?php echo ($examen_details['statut'] ?? '') == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                        </select>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-secondary" onclick="verifierDisponibilite()">
                                            <i class="fas fa-search me-2"></i>Vérifier disponibilité
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>
                                            <?php echo $action == 'create' ? 'Planifier l\'examen' : 'Mettre à jour'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Aide et informations -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informations</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-lightbulb me-2"></i>Conseils de planification</h6>
                                    <ul class="mb-0">
                                        <li>Vérifiez toujours la disponibilité de la salle</li>
                                        <li>Évitez les conflits d'horaire pour les classes</li>
                                        <li>Planifiez les examens en fonction du calendrier académique</li>
                                        <li>Prévoyez du temps pour la correction</li>
                                        <li>Informez les enseignants à l'avance</li>
                                    </ul>
                                </div>
                                
                                <hr>
                                
                                <h6>Disponibilité aujourd'hui</h6>
                                <div class="list-group">
                                    <?php
                                    $today = date('Y-m-d');
                                    $query = "SELECT ce.salle, ce.heure_debut, ce.heure_fin, m.nom as matiere_nom
                                             FROM calendrier_examens ce
                                             JOIN matieres m ON ce.matiere_id = m.id
                                             JOIN classes c ON ce.classe_id = c.id
                                             WHERE c.site_id = :site_id AND ce.date_examen = :today
                                             ORDER BY ce.salle, ce.heure_debut";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute(['site_id' => $site_id, 'today' => $today]);
                                    $examens_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if(empty($examens_today)): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Aucun examen aujourd'hui
                                    </div>
                                    <?php else: 
                                    $salles_occupees = [];
                                    foreach($examens_today as $exam):
                                        $salles_occupees[$exam['salle']][] = $exam;
                                    endforeach;
                                    
                                    foreach($salles_disponibles as $salle):
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><strong><?php echo $salle; ?></strong></span>
                                            <?php if(isset($salles_occupees[$salle])): ?>
                                            <span class="badge bg-danger">Occupée</span>
                                            <?php else: ?>
                                            <span class="badge bg-success">Disponible</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if(isset($salles_occupees[$salle])): ?>
                                        <small class="text-muted">
                                            <?php foreach($salles_occupees[$salle] as $exam): ?>
                                            <div><?php echo $exam['heure_debut'] . '-' . $exam['heure_fin'] . ' : ' . $exam['matiere_nom']; ?></div>
                                            <?php endforeach; ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Calendrier du mois -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-calendar me-2"></i>Ce mois-ci</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $current_month = date('m');
                                $query = "SELECT COUNT(*) as count, DATE_FORMAT(date_examen, '%d/%m') as date_formatee
                                         FROM calendrier_examens ce
                                         JOIN classes c ON ce.classe_id = c.id
                                         WHERE c.site_id = :site_id AND MONTH(date_examen) = :month
                                         GROUP BY date_examen
                                         ORDER BY date_examen
                                         LIMIT 10";
                                $stmt = $db->prepare($query);
                                $stmt->execute(['site_id' => $site_id, 'month' => $current_month]);
                                $examens_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <div class="list-group">
                                    <?php foreach($examens_mois as $exam): ?>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span><?php echo $exam['date_formatee']; ?></span>
                                        <span class="badge bg-info"><?php echo $exam['count']; ?> examens</span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if(empty($examens_mois)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> Aucun examen ce mois-ci
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php break; case 'view': ?>
                <!-- Détails d'un examen -->
                <?php if($examen_details): ?>
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Détails de l'Examen</h5>
                                <div>
                                    <?php if($examen_details['publie_etudiants'] == 0): ?>
                                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#publishModal">
                                        <i class="fas fa-share-square me-2"></i>Publier
                                    </button>
                                    <?php else: ?>
                                    <span class="badge bg-success">Publié</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6>Informations Générales</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <th width="40%">Matière:</th>
                                                <td><?php echo htmlspecialchars($examen_details['matiere_nom'] . ' (' . $examen_details['matiere_code'] . ')'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Classe:</th>
                                                <td><?php echo htmlspecialchars($examen_details['classe_nom'] . ' - ' . $examen_details['filiere_nom'] . ' ' . $examen_details['niveau_libelle']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Type:</th>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo htmlspecialchars($examen_details['type_examen']); ?></span>
                                                    (<?php echo $examen_details['type_pourcentage']; ?>%)
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Enseignant:</th>
                                                <td><?php echo htmlspecialchars($examen_details['enseignant_nom'] ?? 'Non attribué'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Coefficient:</th>
                                                <td><?php echo $examen_details['coefficient']; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Date et Lieu</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <th width="40%">Date:</th>
                                                <td>
                                                    <span class="date-highlight">
                                                        <?php echo formatDateFr($examen_details['date_examen']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Heure:</th>
                                                <td>
                                                    <?php echo date('H:i', strtotime($examen_details['heure_debut'])); ?> - 
                                                    <?php echo date('H:i', strtotime($examen_details['heure_fin'])); ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Durée:</th>
                                                <td><?php echo $examen_details['duree_minutes']; ?> minutes</td>
                                            </tr>
                                            <tr>
                                                <th>Salle:</th>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($examen_details['salle']); ?></span>
                                                    (<?php echo $examen_details['nombre_places']; ?> places)
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Type évaluation:</th>
                                                <td><?php echo ucfirst($examen_details['type_evaluation']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Informations supplémentaires -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Consignes et Documents</h6>
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <?php if($examen_details['consignes']): ?>
                                                <p><strong>Consignes:</strong> <?php echo nl2br(htmlspecialchars($examen_details['consignes'])); ?></p>
                                                <?php endif; ?>
                                                
                                                <?php if($examen_details['documents_autorises']): ?>
                                                <p><strong>Documents autorisés:</strong> <?php echo nl2br(htmlspecialchars($examen_details['documents_autorises'])); ?></p>
                                                <?php endif; ?>
                                                
                                                <?php if($examen_details['materiel_requis']): ?>
                                                <p><strong>Matériel requis:</strong> <?php echo nl2br(htmlspecialchars($examen_details['materiel_requis'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>Statistiques et Suivi</h6>
                                        <div class="list-group">
                                            <div class="list-group-item d-flex justify-content-between">
                                                <span>Statut:</span>
                                                <?php echo getStatutBadge($examen_details['statut']); ?>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between">
                                                <span>Créé par:</span>
                                                <span><?php echo htmlspecialchars($examen_details['createur_nom'] ?? 'Système'); ?></span>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between">
                                                <span>Date création:</span>
                                                <span><?php echo formatDateFr($examen_details['date_creation']); ?></span>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between">
                                                <span>Notes saisies:</span>
                                                <span class="badge bg-<?php echo $examen_details['notes_saisies'] == 1 ? 'success' : 'warning'; ?>">
                                                    <?php echo $examen_details['notes_saisies'] == 1 ? 'Oui' : 'Non'; ?>
                                                </span>
                                            </div>
                                            <div class="list-group-item d-flex justify-content-between">
                                                <span>Notes validées:</span>
                                                <span class="badge bg-<?php echo $examen_details['notes_validees'] == 1 ? 'success' : 'warning'; ?>">
                                                    <?php echo $examen_details['notes_validees'] == 1 ? 'Oui' : 'Non'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0">Actions</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2 d-md-flex">
                                    <a href="saisie_notes.php?examen_id=<?php echo $examen_details['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-edit me-2"></i>Saisir les notes
                                    </a>
                                    <a href="calendrier_examens.php?action=edit&id=<?php echo $examen_details['id']; ?>" class="btn btn-warning">
                                        <i class="fas fa-pencil-alt me-2"></i>Modifier
                                    </a>
                                    <a href="calendrier_examens.php?action=duplicate&id=<?php echo $examen_details['id']; ?>" class="btn btn-info">
                                        <i class="fas fa-copy me-2"></i>Dupliquer
                                    </a>
                                    <?php if($examen_details['statut'] == 'planifie'): ?>
                                    <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                        <i class="fas fa-times-circle me-2"></i>Annuler
                                    </button>
                                    <?php endif; ?>
                                    <a href="export_data.php?type=examen_details&id=<?php echo $examen_details['id']; ?>" class="btn btn-success">
                                        <i class="fas fa-download me-2"></i>Exporter
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Timeline des événements -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-history me-2"></i>Historique</h6>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <h6>Examen planifié</h6>
                                        <p class="text-muted mb-1">
                                            <?php echo formatDateFr($examen_details['date_creation']); ?> 
                                            par <?php echo htmlspecialchars($examen_details['createur_nom'] ?? 'Système'); ?>
                                        </p>
                                    </div>
                                    
                                    <?php if($examen_details['date_modification'] && $examen_details['modificateur_nom']): ?>
                                    <div class="timeline-item">
                                        <h6>Modifié</h6>
                                        <p class="text-muted mb-1">
                                            <?php echo formatDateFr($examen_details['date_modification']); ?> 
                                            par <?php echo htmlspecialchars($examen_details['modificateur_nom']); ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if($examen_details['date_validation'] && $examen_details['validateur_nom']): ?>
                                    <div class="timeline-item">
                                        <h6>Validé</h6>
                                        <p class="text-muted mb-1">
                                            <?php echo formatDateFr($examen_details['date_validation']); ?> 
                                            par <?php echo htmlspecialchars($examen_details['validateur_nom']); ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if($examen_details['notes_validees'] == 1 && $examen_details['date_publication_notes']): ?>
                                    <div class="timeline-item">
                                        <h6>Notes publiées</h6>
                                        <p class="text-muted mb-1">
                                            <?php echo formatDateFr($examen_details['date_publication_notes']); ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Examens similaires -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-calendar-day me-2"></i>Même journée</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $query = "SELECT ce.*, m.nom as matiere_nom, c.nom as classe_nom
                                         FROM calendrier_examens ce
                                         JOIN matieres m ON ce.matiere_id = m.id
                                         JOIN classes c ON ce.classe_id = c.id
                                         WHERE c.site_id = :site_id AND ce.date_examen = :date_examen
                                         AND ce.id != :examen_id
                                         ORDER BY ce.heure_debut";
                                $stmt = $db->prepare($query);
                                $stmt->execute([
                                    'site_id' => $site_id,
                                    'date_examen' => $examen_details['date_examen'],
                                    'examen_id' => $examen_details['id']
                                ]);
                                $examens_meme_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <?php if(empty($examens_meme_jour)): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> Aucun autre examen cette journée
                                </div>
                                <?php else: ?>
                                <div class="list-group">
                                    <?php foreach($examens_meme_jour as $exam): ?>
                                    <a href="calendrier_examens.php?action=view&id=<?php echo $exam['id']; ?>" 
                                       class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo htmlspecialchars($exam['matiere_nom']); ?></span>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($exam['heure_debut'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($exam['classe_nom']); ?> - <?php echo $exam['salle']; ?></small>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal de publication -->
                <div class="modal fade" id="publishModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" action="calendrier_examens.php?action=publish&id=<?php echo $examen_details['id']; ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Publier l'examen aux étudiants</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        Cette action rendra l'examen visible pour tous les étudiants concernés.
                                    </div>
                                    
                                    <p>L'examen sera publié avec les informations suivantes :</p>
                                    <ul>
                                        <li>Date : <?php echo formatDateFr($examen_details['date_examen']); ?></li>
                                        <li>Heure : <?php echo date('H:i', strtotime($examen_details['heure_debut'])); ?></li>
                                        <li>Salle : <?php echo htmlspecialchars($examen_details['salle']); ?></li>
                                        <li>Matière : <?php echo htmlspecialchars($examen_details['matiere_nom']); ?></li>
                                    </ul>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Message d'accompagnement (optionnel)</label>
                                        <textarea name="message_publication" class="form-control" rows="3" placeholder="Message à joindre à la publication..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-primary">Confirmer la publication</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Modal d'annulation -->
                <div class="modal fade" id="cancelModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" action="calendrier_examens.php?action=cancel&id=<?php echo $examen_details['id']; ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Annuler l'examen</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Attention !</strong> Cette action est irréversible.
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Motif de l'annulation <span class="text-danger">*</span></label>
                                        <textarea name="motif" class="form-control" rows="4" required placeholder="Précisez le motif de l'annulation..."></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Date de rattrapage (optionnel)</label>
                                        <input type="date" name="date_rattrapage" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-danger">Confirmer l'annulation</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Examen non trouvé
                </div>
                <?php endif; ?>
                
            <?php break; case 'calendar': ?>
                <!-- Vue calendrier -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Calendrier des Examens</h5>
                        <div class="btn-group">
                            <button class="btn btn-outline-primary" onclick="window.location.href='?action=calendar&mois=<?php echo date('m', strtotime('-1 month')); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="btn btn-primary"><?php echo date('F Y'); ?></button>
                            <button class="btn btn-outline-primary" onclick="window.location.href='?action=calendar&mois=<?php echo date('m', strtotime('+1 month')); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="calendar"></div>
                    </div>
                </div>
                
                <!-- Liste des examens du mois -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0">Examens du mois</h6>
                    </div>
                    <div class="card-body">
                        <?php if(empty($examens)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun examen planifié ce mois-ci
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Heure</th>
                                        <th>Matière</th>
                                        <th>Classe</th>
                                        <th>Type</th>
                                        <th>Salle</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($examens as $examen): ?>
                                    <tr>
                                        <td><?php echo formatDateFr($examen['date_examen']); ?></td>
                                        <td><?php echo date('H:i', strtotime($examen['heure_debut'])); ?></td>
                                        <td><?php echo htmlspecialchars($examen['matiere_nom']); ?></td>
                                        <td><?php echo htmlspecialchars($examen['classe_nom']); ?></td>
                                        <td><span class="badge bg-warning"><?php echo htmlspecialchars($examen['type_examen']); ?></span></td>
                                        <td><?php echo htmlspecialchars($examen['salle'] ?? 'À définir'); ?></td>
                                        <td><?php echo getStatutBadge($examen['statut']); ?></td>
                                        <td>
                                            <a href="calendrier_examens.php?action=view&id=<?php echo $examen['id']; ?>" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Script pour FullCalendar -->
                <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.js"></script>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var calendarEl = document.getElementById('calendar');
                    var calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        locale: 'fr',
                        height: 'auto',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,timeGridDay'
                        },
                        events: [
                            <?php foreach($examens as $examen): ?>
                            {
                                title: '<?php echo addslashes($examen['matiere_nom'] . ' - ' . $examen['classe_nom']); ?>',
                                start: '<?php echo $examen['date_examen'] . 'T' . $examen['heure_debut']; ?>',
                                end: '<?php echo $examen['date_examen'] . 'T' . $examen['heure_fin']; ?>',
                                color: '<?php 
                                    switch($examen['type_examen']) {
                                        case 'DST': echo '#ffc107'; break;
                                        case 'Session': echo '#dc3545'; break;
                                        default: echo '#17a2b8';
                                    }
                                ?>',
                                url: 'calendrier_examens.php?action=view&id=<?php echo $examen['id']; ?>'
                            },
                            <?php endforeach; ?>
                        ],
                        eventClick: function(info) {
                            info.jsEvent.preventDefault();
                            if (info.event.url) {
                                window.location.href = info.event.url;
                            }
                        }
                    });
                    calendar.render();
                });
                </script>
                
            <?php break; default: ?>
                <!-- Liste des examens avec statistiques -->
                <div class="row mb-4">
                    <!-- Statistiques -->
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card total">
                            <div class="card-body">
                                <h6 class="card-title">Total Examens</h6>
                                <h2 class="mb-0"><?php echo $statistiques['total_examens'] ?? 0; ?></h2>
                                <p class="mb-0">Planifiés ce semestre</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card planifie">
                            <div class="card-body">
                                <h6 class="card-title">Planifiés</h6>
                                <h2 class="mb-0"><?php echo $statistiques['planifies'] ?? 0; ?></h2>
                                <p class="mb-0">À venir</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card termine">
                            <div class="card-body">
                                <h6 class="card-title">Terminés</h6>
                                <h2 class="mb-0"><?php echo $statistiques['termines'] ?? 0; ?></h2>
                                <p class="mb-0">Notes à saisir</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card publie">
                            <div class="card-body">
                                <h6 class="card-title">Publiés</h6>
                                <h2 class="mb-0"><?php echo $statistiques['publies'] ?? 0; ?></h2>
                                <p class="mb-0">Aux étudiants</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtres et actions -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Filière</label>
                                <select name="filiere_id" id="filterFiliere" class="form-select" onchange="filtrerExamens()">
                                    <option value="">Toutes les filières</option>
                                    <?php foreach($filieres as $filiere): ?>
                                    <option value="<?php echo $filiere['id']; ?>" <?php echo $filiere_id == $filiere['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($filiere['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Classe</label>
                                <select name="classe_id" id="filterClasse" class="form-select" onchange="filtrerExamens()">
                                    <option value="">Toutes les classes</option>
                                    <?php foreach($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>" <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($classe['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select name="type_examen_id" id="filterType" class="form-select" onchange="filtrerExamens()">
                                    <option value="">Tous types</option>
                                    <?php foreach($types_examens as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" <?php echo $type_examen_id == $type['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['nom']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Statut</label>
                                <select name="statut" id="filterStatut" class="form-select" onchange="filtrerExamens()">
                                    <option value="">Tous statuts</option>
                                    <option value="planifie" <?php echo ($_GET['statut'] ?? '') == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                    <option value="en_cours" <?php echo ($_GET['statut'] ?? '') == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                    <option value="termine" <?php echo ($_GET['statut'] ?? '') == 'termine' ? 'selected' : ''; ?>>Terminé</option>
                                    <option value="annule" <?php echo ($_GET['statut'] ?? '') == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" id="filterDate" class="form-control" onchange="filtrerExamens()" 
                                       value="<?php echo $_GET['date'] ?? ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button class="btn btn-outline-secondary" onclick="reinitialiserFiltres()">
                                            <i class="fas fa-times me-2"></i>Réinitialiser
                                        </button>
                                    </div>
                                    <div>
                                        <a href="calendrier_examens.php?action=calendar" class="btn btn-info">
                                            <i class="fas fa-calendar me-2"></i>Vue Calendrier
                                        </a>
                                        <a href="export_data.php?type=examens" class="btn btn-success">
                                            <i class="fas fa-download me-2"></i>Exporter
                                        </a>
                                        <a href="calendrier_examens.php?action=create" class="btn btn-primary">
                                            <i class="fas fa-plus-circle me-2"></i>Planifier Examen
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tableau des examens -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Liste des Examens</h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($examens)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Aucun examen trouvé
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Heure</th>
                                        <th>Matière</th>
                                        <th>Classe</th>
                                        <th>Type</th>
                                        <th>Enseignant</th>
                                        <th>Salle</th>
                                        <th>Statut</th>
                                        <th>Publication</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($examens as $examen): ?>
                                    <tr class="exam-card <?php echo strtolower($examen['type_examen']); ?>">
                                        <td>
                                            <strong><?php echo formatDateFr($examen['date_examen']); ?></strong><br>
                                            <small class="text-muted">
                                                <?php 
                                                $jours_restants = floor((strtotime($examen['date_examen']) - time()) / (60*60*24));
                                                if($jours_restants == 0) echo "Aujourd'hui";
                                                elseif($jours_restants == 1) echo "Demain";
                                                elseif($jours_restants > 1 && $jours_restants <= 7) echo "Dans $jours_restants jours";
                                                elseif($jours_restants < 0) echo "Passé";
                                                ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php echo date('H:i', strtotime($examen['heure_debut'])); ?><br>
                                            <small class="text-muted"><?php echo $examen['duree_minutes']; ?> min</small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($examen['matiere_code']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($examen['matiere_nom']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($examen['classe_nom']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($examen['filiere_nom'] . ' ' . $examen['niveau_libelle']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                            switch($examen['type_examen']) {
                                                case 'DST': echo 'warning'; break;
                                                case 'Session': echo 'danger'; break;
                                                default: echo 'info';
                                            }
                                            ?>">
                                                <?php echo htmlspecialchars($examen['type_examen']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($examen['enseignant_nom'] ?? 'Non attribué'); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($examen['salle'] ?? 'À définir'); ?></span><br>
                                            <small class="text-muted"><?php echo $examen['nombre_places'] ?? 30; ?> places</small>
                                        </td>
                                        <td><?php echo getStatutBadge($examen['statut']); ?></td>
                                        <td>
                                            <?php if($examen['publie_etudiants'] == 1): ?>
                                            <span class="badge bg-success">Publié</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning">Non publié</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="calendrier_examens.php?action=view&id=<?php echo $examen['id']; ?>" 
                                                   class="btn btn-action btn-info" title="Voir détails">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="calendrier_examens.php?action=edit&id=<?php echo $examen['id']; ?>" 
                                                   class="btn btn-action btn-warning" title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="saisie_notes.php?examen_id=<?php echo $examen['id']; ?>" 
                                                   class="btn btn-action btn-primary" title="Saisir notes">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if($examen['statut'] == 'planifie'): ?>
                                                <a href="calendrier_examens.php?action=cancel&id=<?php echo $examen['id']; ?>" 
                                                   class="btn btn-action btn-danger" title="Annuler"
                                                   onclick="return confirm('Annuler cet examen ?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <nav aria-label="Page navigation" class="mt-3">
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
                
            <?php endswitch; ?>
            
            <!-- Pied de page -->
            <footer class="mt-5 pt-3 border-top">
                <div class="row">
                    <div class="col-md-6">
                        <p class="text-muted">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Calendrier des Examens - DAC Panel
                            </small>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="text-muted">
                            <small>
                                <i class="fas fa-clock me-1"></i>
                                Session: <?php echo date('d/m/Y H:i'); ?>
                            </small>
                        </p>
                    </div>
                </div>
            </footer>
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
        
        // Gestion des alertes
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    });
    
    // Fonctions pour le formulaire
    function chargerClasses() {
        const filiereId = document.getElementById('filiere_id').value;
        const classeSelect = document.getElementById('classe_id');
        const matiereSelect = document.getElementById('matiere_id');
        
        // Filtrer les classes
        Array.from(classeSelect.options).forEach(option => {
            if (option.value === '') return;
            const filiere = option.getAttribute('data-filiere');
            if (filiereId === '' || filiere === filiereId) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
        
        // Réinitialiser la sélection
        classeSelect.value = '';
        matiereSelect.value = '';
    }
    
    function chargerMatieres() {
        const classeId = document.getElementById('classe_id').value;
        const matiereSelect = document.getElementById('matiere_id');
        
        if (!classeId) return;
        
        // Ici, normalement on ferait un appel AJAX pour charger les matières de la classe
        // Pour l'instant, on filtre simplement par filière
        
        const filiereId = document.getElementById('filiere_id').value;
        Array.from(matiereSelect.options).forEach(option => {
            if (option.value === '') return;
            const filiere = option.getAttribute('data-filiere');
            if (filiereId === '' || filiere === filiereId) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    function verifierDisponibilite() {
        const salle = document.querySelector('select[name="salle"]').value;
        const date = document.querySelector('input[name="date_examen"]').value;
        const heureDebut = document.querySelector('input[name="heure_debut"]').value;
        const heureFin = document.querySelector('input[name="heure_fin"]').value;
        const classeId = document.querySelector('select[name="classe_id"]').value;
        
        if (!salle || !date || !heureDebut || !heureFin || !classeId) {
            alert('Veuillez remplir tous les champs obligatoires');
            return;
        }
        
        // Simuler une vérification de disponibilité
        alert('Vérification de disponibilité...\n\nCette fonctionnalité nécessiterait une implémentation serveur complète.\n\nEn production, on vérifierait:\n1. Disponibilité de la salle\n2. Disponibilité de la classe\n3. Conflits avec d\'autres examens');
    }
    
    // Filtrage des examens
    function filtrerExamens() {
        const filiere = document.getElementById('filterFiliere').value;
        const classe = document.getElementById('filterClasse').value;
        const type = document.getElementById('filterType').value;
        const statut = document.getElementById('filterStatut').value;
        const date = document.getElementById('filterDate').value;
        
        let url = 'calendrier_examens.php?';
        const params = [];
        
        if (filiere) params.push('filiere_id=' + encodeURIComponent(filiere));
        if (classe) params.push('classe_id=' + encodeURIComponent(classe));
        if (type) params.push('type_examen_id=' + encodeURIComponent(type));
        if (statut) params.push('statut=' + encodeURIComponent(statut));
        if (date) params.push('date=' + encodeURIComponent(date));
        
        if (params.length > 0) {
            url += params.join('&');
        }
        
        window.location.href = url;
    }
    
    function reinitialiserFiltres() {
        window.location.href = 'calendrier_examens.php';
    }
    
    // Validation du formulaire
    document.getElementById('formExamen')?.addEventListener('submit', function(e) {
        const dateExamen = document.querySelector('input[name="date_examen"]').value;
        const heureDebut = document.querySelector('input[name="heure_debut"]').value;
        const heureFin = document.querySelector('input[name="heure_fin"]').value;
        
        if (new Date(dateExamen + 'T' + heureFin) <= new Date(dateExamen + 'T' + heureDebut)) {
            e.preventDefault();
            alert('L\'heure de fin doit être après l\'heure de début');
            return;
        }
        
        const maintenant = new Date();
        const dateExamenObj = new Date(dateExamen + 'T' + heureDebut);
        
        if (dateExamenObj < maintenant) {
            if (!confirm('Vous planifiez un examen dans le passé. Continuer ?')) {
                e.preventDefault();
            }
        }
    });
    
    // Raccourcis clavier
    document.addEventListener('keydown', function(e) {
        // Ctrl + N pour nouveau
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'calendrier_examens.php?action=create';
        }
        
        // Ctrl + F pour filtrer
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('filterFiliere')?.focus();
        }
        
        // Ctrl + C pour calendrier
        if (e.ctrlKey && e.key === 'c') {
            e.preventDefault();
            window.location.href = 'calendrier_examens.php?action=calendar';
        }
    });
    </script>
</body>
</html>