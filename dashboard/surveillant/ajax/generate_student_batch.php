<?php
// dashboard/surveillant/ajax/generate_student_batch.php
require_once '../../../config/database.php';
require_once '../../../libs/phpqrcode/qrlib.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ../../auth/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

// Configuration
$output_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/qrcodes/batch/';
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0777, true);
}

// Récupérer tous les étudiants actifs sans QR code
$query = "
    SELECT e.*, c.nom as classe_nom 
    FROM etudiants e
    LEFT JOIN classes c ON e.classe_id = c.id
    WHERE e.site_id = :site_id 
      AND e.statut = 'actif'
      AND (e.qr_code_data IS NULL OR e.qr_code_data = '')
    ORDER BY e.classe_id, e.nom, e.prenom
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$generated = 0;
$errors = [];

foreach ($students as $student) {
    try {
        // Générer les données du QR code
        $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                  "NOM:" . $student['nom'] . "|" .
                  "PRENOM:" . $student['prenom'] . "|" .
                  "CLASSE:" . $student['classe_id'] . "|" .
                  "SITE:" . $site_id . "|" .
                  "TYPE:etudiant|" .
                  "DATE:" . date('YmdHis');
        
        $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
        $filepath = $output_dir . $filename;
        
        // Générer le QR code
        QRcode::png($qr_data, $filepath, QR_ECLEVEL_H, 10, 2);
        
        // Mettre à jour la base de données
        $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE id = :id";
        $stmt_update = $db->prepare($update_query);
        $stmt_update->execute([
            ':qr_data' => $qr_data,
            ':id' => $student['id']
        ]);
        
        // Journaliser
        $log_query = "
            INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, table_concernée, id_enregistrement, details)
            VALUES (:user_id, 'admin', 'batch_qr_generation', 'etudiants', :student_id, 
                    CONCAT('Batch QR: ', :matricule))
        ";
        $stmt_log = $db->prepare($log_query);
        $stmt_log->execute([
            ':user_id' => $surveillant_id,
            ':student_id' => $student['id'],
            ':matricule' => $student['matricule']
        ]);
        
        $generated++;
        
    } catch (Exception $e) {
        $errors[] = $student['matricule'] . ': ' . $e->getMessage();
    }
}

// Créer un rapport
$report = "Rapport de génération batch\n";
$report .= "Date: " . date('d/m/Y H:i:s') . "\n";
$report .= "Surveillant: " . $_SESSION['user_name'] . "\n";
$report .= "Site: " . $site_id . "\n";
$report .= "QR codes générés: " . $generated . "\n";
$report .= "Erreurs: " . count($errors) . "\n";

if (!empty($errors)) {
    $report .= "\nListe des erreurs:\n";
    foreach ($errors as $error) {
        $report .= "- " . $error . "\n";
    }
}

file_put_contents($output_dir . 'rapport_' . date('Ymd_His') . '.txt', $report);

// Rediriger vers la page des résultats
header('Location: ../batch_result.php?generated=' . $generated . '&errors=' . count($errors));
exit();
?>