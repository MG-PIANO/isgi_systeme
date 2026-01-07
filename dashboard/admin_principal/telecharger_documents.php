<?php
// dashboard/admin_principal/telecharger_documents.php
session_start();

// Vérifier l'authentification
if (!isset($_SESSION['user_id'])) {
    die('Accès interdit');
}

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
require_once ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['demande_id'])) {
    $demande_id = $_POST['demande_id'];
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Récupérer les documents de la demande
        $query = "SELECT numero_demande, nom, prenom, photo_identite, acte_naissance, releve_notes, attestation_legalisee 
                  FROM demande_inscriptions WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$demande_id]);
        $demande = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$demande) {
            die("Demande non trouvée");
        }
        
        $documents = [
            'photo_identite' => $demande['photo_identite'],
            'acte_naissance' => $demande['acte_naissance'],
            'releve_notes' => $demande['releve_notes'],
            'attestation_legalisee' => $demande['attestation_legalisee']
        ];
        
        // Créer un fichier ZIP temporaire
        $zip = new ZipArchive();
        $zip_filename = tempnam(sys_get_temp_dir(), 'documents_');
        
        if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
            die("Impossible de créer le fichier ZIP");
        }
        
        $added_files = 0;
        foreach ($documents as $type => $file_path) {
            if (!empty($file_path) && file_exists(ROOT_PATH . '/' . $file_path)) {
                $file_name = $demande['numero_demande'] . '_' . $demande['nom'] . '_' . $demande['prenom'] . '_' . $type . '.' . pathinfo($file_path, PATHINFO_EXTENSION);
                $zip->addFile(ROOT_PATH . '/' . $file_path, $file_name);
                $added_files++;
            }
        }
        
        $zip->close();
        
        if ($added_files > 0) {
            // Envoyer le fichier ZIP
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="documents_' . $demande['numero_demande'] . '.zip"');
            header('Content-Length: ' . filesize($zip_filename));
            readfile($zip_filename);
            
            // Supprimer le fichier temporaire
            unlink($zip_filename);
            exit;
        } else {
            die("Aucun document à télécharger");
        }
        
    } catch (Exception $e) {
        die("Erreur: " . $e->getMessage());
    }
} else {
    die("Requête invalide");
}
?>