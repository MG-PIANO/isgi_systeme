<?php
// dashboard/bibliotheque_action.php
define('ROOT_PATH', dirname(dirname(__FILE__)));
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

include_once ROOT_PATH . '/config/database.php';

$db = Database::getInstance()->getConnection();

$action = $_POST['action'] ?? $_GET['action'];

header('Content-Type: application/json');

switch($action) {
    case 'add_book':
        // Traitement d'ajout de livre
        $target_dir = ROOT_PATH . "/uploads/bibliotheque/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        // Upload PDF
        $pdf_name = uniqid() . '_' . basename($_FILES["fichier_pdf"]["name"]);
        $pdf_target = $target_dir . $pdf_name;
        
        if (move_uploaded_file($_FILES["fichier_pdf"]["tmp_name"], $pdf_target)) {
            // Upload image de couverture
            $cover_name = null;
            if (!empty($_FILES["couverture"]["name"])) {
                $cover_name = uniqid() . '_' . basename($_FILES["couverture"]["name"]);
                $cover_target = $target_dir . $cover_name;
                move_uploaded_file($_FILES["couverture"]["tmp_name"], $cover_target);
            }
            
            // Compter les pages du PDF
            $pages = 0;
            if (class_exists('Imagick')) {
                $imagick = new Imagick();
                $imagick->readImage($pdf_target);
                $pages = $imagick->getNumberImages();
            }
            
            // Dans bibliotheque_action.php, utilisez:
$stmt = $db->prepare("INSERT INTO livres 
    (titre, auteur, isbn, edition as editeur, annee_publication, categorie_id, 
     description, resume, fichier_pdf, couverture, taille_fichier, 
     site_id, nombre_pages, date_ajout, ajoute_par) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            $stmt->execute([
                $_POST['titre'],
                $_POST['auteur'],
                $_POST['isbn'],
                $_POST['editeur'],
                $_POST['annee_publication'],
                $_POST['categorie_id'] ?: null,
                $_POST['description'],
                $_POST['resume'],
                $pdf_name,
                $cover_name,
                filesize($pdf_target),
                $_SESSION['site_id'],
                $pages,
                $_SESSION['user_id']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Livre ajouté avec succès']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors du téléchargement du PDF']);
        }
        break;
        
    case 'save_document':
        // Sauvegarder un document de l'éditeur
        $title = $_POST['title'];
        $content = $_POST['content'];
        $pages = $_POST['pages'];
        $isAutoSave = $_POST['auto'] == '1';
        
        if ($_POST['document_id']) {
            // Mettre à jour un document existant
            $stmt = $db->prepare("UPDATE bibliotheque_documents 
                SET titre = ?, contenu_html = ?, nombre_pages = ?, 
                    date_modification = NOW(), derniere_sauvegarde = NOW()
                WHERE id = ?");
            $stmt->execute([$title, $content, $pages, $_POST['document_id']]);
            
            // Enregistrer la sauvegarde
            $stmt = $db->prepare("INSERT INTO bibliotheque_sauvegardes 
                (document_id, contenu_html, version, type_sauvegarde, commentaire) 
                VALUES (?, ?, 1, ?, ?)");
            $stmt->execute([
                $_POST['document_id'],
                $content,
                $isAutoSave ? 'auto' : 'manuel',
                $isAutoSave ? 'Sauvegarde automatique' : 'Sauvegarde manuelle'
            ]);
        } else {
            // Créer un nouveau document
            $stmt = $db->prepare("INSERT INTO bibliotheque_documents 
                (titre, auteur_id, contenu_html, site_id, statut, nombre_pages, date_creation) 
                VALUES (?, ?, ?, ?, 'brouillon', ?, NOW())");
            $stmt->execute([$title, $_SESSION['user_id'], $content, $_SESSION['site_id'], $pages]);
            
            $documentId = $db->lastInsertId();
            echo json_encode(['success' => true, 'document_id' => $documentId]);
        }
        break;
        
    case 'publish_document':
        // Publier un document
        if ($_POST['pages'] < 30) {
            echo json_encode(['success' => false, 'message' => 'Minimum 30 pages requis']);
            break;
        }
        
        $stmt = $db->prepare("UPDATE bibliotheque_documents 
            SET statut = 'publie', date_publication = NOW() 
            WHERE id = ?");
        $stmt->execute([$_POST['document_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Document publié avec succès']);
        break;
        
    case 'add_to_favorites':
        // Ajouter aux favoris
        $stmt = $db->prepare("INSERT IGNORE INTO bibliotheque_favoris (livre_id, utilisateur_id) VALUES (?, ?)");
        $stmt->execute([$_POST['livre_id'], $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
}