<?php
// dashboard/admin_principal/cours_en_ligne_action.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier le rôle (admin principal uniquement)
if ($_SESSION['role_id'] != 1) { // ID 1 = admin principal
    header('Location: ' . ROOT_PATH . '/dashboard/admin_principal/cours_en_ligne.php');
    exit();
}

include_once ROOT_PATH . '/config/database.php';

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Pour les actions qui nécessitent une redirection HTML, pas de header JSON
if (!in_array($action, ['get_calendar_events', 'get_statistics', 'get_details', 'get_available_students'])) {
    // Ces actions seront redirigées normalement
} else {
    header('Content-Type: application/json');
}

switch($action) {
    case 'create':
        try {
            // Valider les données
            $required = ['titre', 'date_cours', 'duree_minutes', 'site_id'];
            $missing = [];
            foreach($required as $field) {
                if (empty($_POST[$field])) {
                    $missing[] = $field;
                }
            }
            
            if (!empty($missing)) {
                $_SESSION['error'] = "Champs obligatoires manquants: " . implode(', ', $missing);
                header('Location: cours_en_ligne.php?action=planifier');
                exit();
            }

            // Valider la durée
            $duree_minutes = intval($_POST['duree_minutes']);
            if ($duree_minutes <= 0 || $duree_minutes > 480) {
                $_SESSION['error'] = "La durée doit être comprise entre 1 et 480 minutes";
                header('Location: cours_en_ligne.php?action=planifier');
                exit();
            }

            // Valider la date
            $date_cours = $_POST['date_cours'];
            $date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $date_cours);
            if (!$date_obj) {
                $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $date_cours);
            }
            
            if (!$date_obj || $date_obj < new DateTime()) {
                $_SESSION['error'] = "Date invalide ou antérieure à la date actuelle";
                header('Location: cours_en_ligne.php?action=planifier');
                exit();
            }

            // Récupérer le site_id depuis le formulaire
            $site_id = intval($_POST['site_id']);
            
            // Vérifier que le site existe
            $stmt = $db->prepare("SELECT id FROM sites WHERE id = ? AND statut = 'actif'");
            $stmt->execute([$site_id]);
            if (!$stmt->fetch()) {
                $_SESSION['error'] = "Site invalide ou inactif";
                header('Location: cours_en_ligne.php?action=planifier');
                exit();
            }

            // Insertion du cours
            $stmt = $db->prepare("INSERT INTO cours_en_ligne 
                (titre, description, matiere_id, enseignant_id, site_id, 
                 type_cours, date_cours, duree_minutes, url_live, mot_de_passe,
                 enregistrement_auto, max_participants, created_by, date_creation, statut) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            $stmt->execute([
                trim($_POST['titre']),
                !empty($_POST['description']) ? trim($_POST['description']) : null,
                !empty($_POST['matiere_id']) ? intval($_POST['matiere_id']) : null,
                !empty($_POST['enseignant_id']) ? intval($_POST['enseignant_id']) : null,
                $site_id,
                !empty($_POST['type_cours']) ? $_POST['type_cours'] : 'cours',
                $date_obj->format('Y-m-d H:i:s'),
                $duree_minutes,
                !empty($_POST['url_live']) ? filter_var($_POST['url_live'], FILTER_SANITIZE_URL) : null,
                !empty($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : null,
                isset($_POST['enregistrement_auto']) ? 1 : 0,
                !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : 100,
                $user_id,
                'planifie'  // statut par défaut
            ]);
            
            $cours_id = $db->lastInsertId();
            
            // Gérer l'upload du fichier PDF
            if (!empty($_FILES["presentation_pdf"]["name"]) && $_FILES["presentation_pdf"]["error"] == 0) {
                $target_dir = ROOT_PATH . "/uploads/cours/";
                
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $allowed_types = ['application/pdf'];
                $max_size = 10 * 1024 * 1024;
                
                $file_type = $_FILES["presentation_pdf"]["type"];
                $file_size = $_FILES["presentation_pdf"]["size"];
                
                if (!in_array($file_type, $allowed_types)) {
                    $_SESSION['error'] = "Seuls les fichiers PDF sont autorisés";
                    header('Location: cours_en_ligne.php?action=planifier');
                    exit();
                }
                
                if ($file_size > $max_size) {
                    $_SESSION['error'] = "Le fichier est trop volumineux (max 10MB)";
                    header('Location: cours_en_ligne.php?action=planifier');
                    exit();
                }
                
                $pdf_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($_FILES["presentation_pdf"]["name"]));
                $pdf_target = $target_dir . $pdf_name;
                
                if (move_uploaded_file($_FILES["presentation_pdf"]["tmp_name"], $pdf_target)) {
                    $stmt = $db->prepare("UPDATE cours_en_ligne SET presentation_pdf = ? WHERE id = ?");
                    $stmt->execute([$pdf_name, $cours_id]);
                } else {
                    $_SESSION['error'] = "Erreur lors du téléchargement du fichier PDF";
                    header('Location: cours_en_ligne.php?action=planifier');
                    exit();
                }
            }
            
            // Redirection avec succès
            $_SESSION['success'] = "Cours planifié avec succès!";
            header('Location: cours_en_ligne.php?success=1');
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: cours_en_ligne.php?action=planifier');
            exit();
        }
        break;
        
    case 'update':
        try {
            if (empty($_POST['id'])) {
                $_SESSION['error'] = "ID du cours manquant";
                header('Location: cours_en_ligne.php');
                exit();
            }
            
            $id = intval($_POST['id']);
            
            // Vérifier que le cours existe
            $stmt = $db->prepare("SELECT id FROM cours_en_ligne WHERE id = ?");
            $stmt->execute([$id]);
            $cours = $stmt->fetch();
            
            if (!$cours) {
                $_SESSION['error'] = "Cours non trouvé";
                header('Location: cours_en_ligne.php');
                exit();
            }
            
            // Récupérer le site_id depuis le formulaire
            $site_id = intval($_POST['site_id'] ?? 0);
            if ($site_id <= 0) {
                $_SESSION['error'] = "Site invalide";
                header('Location: cours_en_ligne.php?action=edit&id=' . $id);
                exit();
            }
            
            $stmt = $db->prepare("UPDATE cours_en_ligne SET 
                titre = ?, description = ?, matiere_id = ?, enseignant_id = ?,
                site_id = ?, type_cours = ?, date_cours = ?, duree_minutes = ?, 
                url_live = ?, mot_de_passe = ?, enregistrement_auto = ?, 
                max_participants = ?, statut = ?, url_replay = ?, 
                modified_by = ?, date_modification = NOW()
                WHERE id = ?");
            
            $stmt->execute([
                trim($_POST['titre']),
                !empty($_POST['description']) ? trim($_POST['description']) : null,
                !empty($_POST['matiere_id']) ? intval($_POST['matiere_id']) : null,
                !empty($_POST['enseignant_id']) ? intval($_POST['enseignant_id']) : null,
                $site_id,
                !empty($_POST['type_cours']) ? $_POST['type_cours'] : 'cours',
                $_POST['date_cours'],
                intval($_POST['duree_minutes']),
                !empty($_POST['url_live']) ? filter_var($_POST['url_live'], FILTER_SANITIZE_URL) : null,
                !empty($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : null,
                isset($_POST['enregistrement_auto']) ? 1 : 0,
                !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : 100,
                $_POST['statut'] ?? 'planifie',
                !empty($_POST['url_replay']) ? filter_var($_POST['url_replay'], FILTER_SANITIZE_URL) : null,
                $user_id,
                $id
            ]);
            
            // Gérer l'upload du fichier PDF
            if (!empty($_FILES["presentation_pdf"]["name"]) && $_FILES["presentation_pdf"]["error"] == 0) {
                $target_dir = ROOT_PATH . "/uploads/cours/";
                
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $allowed_types = ['application/pdf'];
                $max_size = 10 * 1024 * 1024;
                
                $file_type = $_FILES["presentation_pdf"]["type"];
                $file_size = $_FILES["presentation_pdf"]["size"];
                
                if (!in_array($file_type, $allowed_types)) {
                    $_SESSION['error'] = "Seuls les fichiers PDF sont autorisés";
                    header('Location: cours_en_ligne.php?action=edit&id=' . $id);
                    exit();
                }
                
                if ($file_size > $max_size) {
                    $_SESSION['error'] = "Le fichier est trop volumineux (max 10MB)";
                    header('Location: cours_en_ligne.php?action=edit&id=' . $id);
                    exit();
                }
                
                // Supprimer l'ancien PDF si existe
                $stmt = $db->prepare("SELECT presentation_pdf FROM cours_en_ligne WHERE id = ?");
                $stmt->execute([$id]);
                $old_pdf = $stmt->fetchColumn();
                
                if ($old_pdf && file_exists($target_dir . $old_pdf)) {
                    unlink($target_dir . $old_pdf);
                }
                
                $pdf_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($_FILES["presentation_pdf"]["name"]));
                $pdf_target = $target_dir . $pdf_name;
                
                if (move_uploaded_file($_FILES["presentation_pdf"]["tmp_name"], $pdf_target)) {
                    $stmt = $db->prepare("UPDATE cours_en_ligne SET presentation_pdf = ? WHERE id = ?");
                    $stmt->execute([$pdf_name, $id]);
                } else {
                    $_SESSION['error'] = "Erreur lors du téléchargement du fichier PDF";
                    header('Location: cours_en_ligne.php?action=edit&id=' . $id);
                    exit();
                }
            }
            
            $_SESSION['success'] = "Cours modifié avec succès!";
            header('Location: cours_en_ligne.php?success=2');
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: cours_en_ligne.php?action=edit&id=' . $id);
            exit();
        }
        break;
        
    case 'delete':
        try {
            $id = intval($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                $_SESSION['error'] = "ID invalide";
                header('Location: cours_en_ligne.php');
                exit();
            }
            
            // Vérifier que le cours existe
            $stmt = $db->prepare("SELECT id, presentation_pdf FROM cours_en_ligne WHERE id = ?");
            $stmt->execute([$id]);
            $cours = $stmt->fetch();
            
            if (!$cours) {
                $_SESSION['error'] = "Cours non trouvé";
                header('Location: cours_en_ligne.php');
                exit();
            }
            
            // Supprimer le fichier PDF associé
            if (!empty($cours['presentation_pdf'])) {
                $target_dir = ROOT_PATH . "/uploads/cours/";
                if (file_exists($target_dir . $cours['presentation_pdf'])) {
                    unlink($target_dir . $cours['presentation_pdf']);
                }
            }
            
            // Supprimer les participants d'abord
            $stmt = $db->prepare("DELETE FROM cours_participants WHERE cours_id = ?");
            $stmt->execute([$id]);
            
            // Supprimer le cours
            $stmt = $db->prepare("DELETE FROM cours_en_ligne WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = "Cours supprimé avec succès!";
            header('Location: cours_en_ligne.php?success=3');
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: cours_en_ligne.php');
            exit();
        }
        break;
        
    // Les autres actions restent en JSON pour AJAX
    case 'update_status':
        try {
            header('Content-Type: application/json');
            
            $id = intval($_POST['cours_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            
            if ($id <= 0) {
                throw new Exception("ID invalide");
            }
            
            $allowed_status = ['planifie', 'en_cours', 'termine', 'annule'];
            if (!in_array($new_status, $allowed_status)) {
                throw new Exception("Statut invalide");
            }
            
            $stmt = $db->prepare("SELECT id FROM cours_en_ligne WHERE id = ?");
            $stmt->execute([$id]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Cours non trouvé");
            }
            
            $stmt = $db->prepare("UPDATE cours_en_ligne SET statut = ?, modified_by = ?, date_modification = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id, $id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'new_status' => $new_status
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'add_participants':
        try {
            header('Content-Type: application/json');
            
            $cours_id = intval($_POST['cours_id'] ?? 0);
            $etudiant_ids = $_POST['etudiant_ids'] ?? [];
            
            if ($cours_id <= 0) {
                throw new Exception("ID de cours invalide");
            }
            
            if (empty($etudiant_ids) || !is_array($etudiant_ids)) {
                throw new Exception("Aucun étudiant sélectionné");
            }
            
            $added = 0;
            $already_enrolled = 0;
            $errors = [];
            
            foreach($etudiant_ids as $etudiant_id) {
                $etudiant_id = intval($etudiant_id);
                if ($etudiant_id <= 0) continue;
                
                // Vérifier si déjà inscrit
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM cours_participants WHERE cours_id = ? AND etudiant_id = ?");
                $stmt->execute([$cours_id, $etudiant_id]);
                $exists = $stmt->fetch();
                
                if ($exists['total'] == 0) {
                    try {
                        $stmt = $db->prepare("INSERT INTO cours_participants (cours_id, etudiant_id, date_inscription, statut) VALUES (?, ?, NOW(), 'inscrit')");
                        $stmt->execute([$cours_id, $etudiant_id]);
                        $added++;
                    } catch (Exception $e) {
                        $errors[] = "Erreur pour étudiant #$etudiant_id: " . $e->getMessage();
                    }
                } else {
                    $already_enrolled++;
                }
            }
            
            echo json_encode([
                'success' => true,
                'added' => $added,
                'already_enrolled' => $already_enrolled,
                'errors' => $errors,
                'message' => "$added étudiant(s) ajouté(s), $already_enrolled déjà inscrit(s)"
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'get_calendar_events':
        try {
            header('Content-Type: application/json');
            
            $start = $_GET['start'] ?? date('Y-m-01');
            $end = $_GET['end'] ?? date('Y-m-t');
            
            $stmt = $db->prepare("SELECT 
                c.id, 
                c.titre as title,
                c.date_cours as start,
                DATE_ADD(c.date_cours, INTERVAL c.duree_minutes MINUTE) as end,
                c.statut,
                c.url_live,
                c.description,
                c.type_cours,
                c.enseignant_id,
                s.nom as site_nom,
                s.couleur as site_couleur
                FROM cours_en_ligne c
                LEFT JOIN sites s ON c.site_id = s.id
                WHERE c.date_cours >= ?
                AND c.date_cours <= ?
                ORDER BY c.date_cours");
            
            $stmt->execute([$start, $end]);
            $events = $stmt->fetchAll();
            
            $formatted_events = [];
            foreach($events as $event) {
                $color = $event['site_couleur'] ?: '#3498db';
                
                switch($event['statut']) {
                    case 'en_cours': $color = '#e74c3c'; break;
                    case 'planifie': $color = $event['site_couleur'] ?: '#f39c12'; break;
                    case 'termine': $color = '#27ae60'; break;
                    case 'annule': $color = '#95a5a6'; break;
                }
                
                $formatted_events[] = [
                    'id' => $event['id'],
                    'title' => $event['title'] . ' - ' . $event['site_nom'],
                    'start' => $event['start'],
                    'end' => $event['end'],
                    'color' => $color,
                    'textColor' => '#ffffff',
                    'extendedProps' => [
                        'statut' => $event['statut'],
                        'type' => $event['type_cours'],
                        'description' => $event['description'],
                        'site' => $event['site_nom']
                    ]
                ];
            }
            
            echo json_encode($formatted_events);
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        // Par défaut, rediriger vers la page principale
        header('Location: cours_en_ligne.php');
        exit();
}