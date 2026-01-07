<?php
// dashboard/dac/import_students.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $site_id = $_SESSION['site_id'] ?? null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], 'r');
            
            if ($handle !== false) {
                // Lire l'en-tête
                $headers = fgetcsv($handle, 1000, ',');
                
                $imported = 0;
                $errors = [];
                
                // Préparer la requête d'insertion
                $query = "INSERT INTO etudiants (site_id, matricule, nom, prenom, numero_cni, date_naissance, 
                                                lieu_naissance, sexe, nationalite, adresse, ville, pays, 
                                                profession, situation_matrimoniale, statut, date_inscription, classe_id) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW(), ?)";
                $stmt = $db->prepare($query);
                
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    if (count($data) < 12) {
                        $errors[] = "Ligne incomplète: " . implode(',', $data);
                        continue;
                    }
                    
                    try {
                        // Générer un matricule
                        $matricule = 'ISGI-' . date('Y') . '-' . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                        
                        // Vérifier si le CNI existe déjà
                        $check_query = "SELECT id FROM etudiants WHERE numero_cni = ?";
                        $check_stmt = $db->prepare($check_query);
                        $check_stmt->execute([$data[4]]);
                        
                        if ($check_stmt->fetch()) {
                            $errors[] = "CNI déjà existant: " . $data[4];
                            continue;
                        }
                        
                        // Insérer l'étudiant
                        $stmt->execute([
                            $site_id,
                            $matricule,
                            $data[0], // nom
                            $data[1], // prenom
                            $data[2], // cni
                            $data[3], // date_naissance
                            $data[4], // lieu_naissance
                            $data[5], // sexe
                            $data[6] ?? 'Congolaise', // nationalite
                            $data[7] ?? '', // adresse
                            $data[8] ?? '', // ville
                            $data[9] ?? 'Congo', // pays
                            $data[10] ?? '', // profession
                            $data[11] ?? '', // situation_matrimoniale
                            $_POST['default_classe'] ?? null
                        ]);
                        
                        $imported++;
                        
                    } catch (Exception $e) {
                        $errors[] = "Erreur ligne: " . implode(',', $data) . " - " . $e->getMessage();
                    }
                }
                
                fclose($handle);
                
                // Préparer le message de résultat
                $message = "$imported étudiants importés avec succès";
                if (!empty($errors)) {
                    $message .= ". " . count($errors) . " erreur(s)";
                    $message_type = "warning";
                    
                    // Sauvegarder les erreurs dans un fichier
                    $error_file = ROOT_PATH . '/logs/import_errors_' . date('Ymd_His') . '.txt';
                    file_put_contents($error_file, implode("\n", $errors));
                } else {
                    $message_type = "success";
                }
                
                header('Location: etudiants.php?message=' . urlencode($message) . '&message_type=' . $message_type);
                exit();
            }
        }
    }
    
    header('Location: etudiants.php?error=Erreur lors de l\'importation');
    exit();
    
} catch (Exception $e) {
    header('Location: etudiants.php?error=' . urlencode($e->getMessage()));
    exit();
}