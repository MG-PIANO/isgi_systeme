<?php
// dashboard/surveillant/batch_result.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

$generated = $_GET['generated'] ?? 0;
$errors = $_GET['errors'] ?? 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultat Génération Batch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            Génération Batch Terminée
                        </h4>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <i class="fas fa-qrcode fa-5x text-success mb-3"></i>
                            <h3>Génération des QR Codes Réussie</h3>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="text-success">
                                            <i class="fas fa-check me-2"></i>
                                            QR Codes Générés
                                        </h5>
                                        <h2 class="display-4"><?php echo $generated; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="<?php echo $errors > 0 ? 'text-warning' : 'text-success'; ?>">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            Erreurs
                                        </h5>
                                        <h2 class="display-4"><?php echo $errors; ?></h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>Informations</h5>
                            <p class="mb-0">
                                Les QR codes ont été générés avec succès et sont prêts à être utilisés.<br>
                                Vous pouvez maintenant les imprimer ou les distribuer aux étudiants.
                            </p>
                        </div>
                        
                        <div class="mt-4">
                            <a href="generer_qr.php" class="btn btn-primary btn-lg me-3">
                                <i class="fas fa-arrow-left me-2"></i> Retour au Générateur
                            </a>
                            <a href="presences.php" class="btn btn-success btn-lg me-3">
                                <i class="fas fa-calendar-check me-2"></i> Voir les Présences
                            </a>
                            <a href="scanner_qr.php" class="btn btn-warning btn-lg">
                                <i class="fas fa-qrcode me-2"></i> Tester le Scanner
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>