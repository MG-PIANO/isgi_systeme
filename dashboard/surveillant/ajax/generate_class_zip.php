<?php
// dashboard/surveillant/ajax/generate_class_zip.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];

// Inclure la bibliothèque Zip
require_once '../../../libs/ZipStream.php';

try {
    // Récupérer la classe du dernier formulaire
    $class_id = $_SESSION['last_generated_class'] ?? 0;
    
    if (!$class_id) {
        throw new Exception('Aucune classe sélectionnée');
    }
    
    // Récupérer les étudiants de la classe
    $query = "
        SELECT e.*, c.nom as classe_nom 
        FROM etudiants e
        LEFT JOIN classes c ON e.classe_id = c.id
        WHERE e.classe_id = :class_id 
          AND e.site_id = :site_id
          AND e.statut = 'actif'
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':class_id' => $class_id,
        ':site_id' => $site_id
    ]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($students)) {
        throw new Exception('Aucun étudiant dans cette classe');
    }
    
    // Créer le dossier temporaire
    $temp_dir = sys_get_temp_dir() . '/qr_codes_' . time();
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    // Copier les QR codes dans le dossier temporaire
    foreach ($students as $student) {
        if (!empty($student['qr_code_data'])) {
            $filename = 'etudiant_' . $student['matricule'] . '.png';
            $source = dirname(dirname(dirname(__DIR__))) . '/uploads/qrcodes/' . $filename;
            $destination = $temp_dir . '/' . $filename;
            
            if (file_exists($source)) {
                copy($source, $destination);
            }
        }
    }
    
    // Créer un fichier README
    $readme = "QR Codes pour la classe: {$students[0]['classe_nom']}\n";
    $readme .= "Généré le: " . date('d/m/Y H:i:s') . "\n";
    $readme .= "Nombre de QR codes: " . count($students) . "\n\n";
    $readme .= "Liste des étudiants:\n";
    
    foreach ($students as $student) {
        $readme .= "- {$student['matricule']}: {$student['nom']} {$student['prenom']}\n";
    }
    
    file_put_contents($temp_dir . '/README.txt', $readme);
    
    // Créer le ZIP
    $zip_filename = 'qr_codes_classe_' . $students[0]['classe_nom'] . '_' . date('Ymd_His') . '.zip';
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Pragma: no-cache');
    
    $zip = new ZipArchive();
    $zip_path = $temp_dir . '.zip';
    
    if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
        // Ajouter les fichiers
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp_dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($temp_dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
        
        // Envoyer le fichier ZIP
        readfile($zip_path);
        
        // Nettoyer
        unlink($zip_path);
        array_map('unlink', glob("$temp_dir/*.*"));
        rmdir($temp_dir);
        
    } else {
        throw new Exception('Impossible de créer le fichier ZIP');
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>