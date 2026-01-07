<?php
// dashboard/surveillant/generer_qr.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

require_once ROOT_PATH . '/config/database.php';
$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$surveillant_id = $_SESSION['user_id'];

$pageTitle = "Générer QR Codes";

// Inclure la bibliothèque QR Code
error_reporting(E_ALL & ~E_DEPRECATED);
require_once ROOT_PATH . '/libs/phpqrcode/qrlib.php';
error_reporting(E_ALL); // Réactiver tous les erreurs après


// Fonction pour générer un QR code
function generateQRCode($data, $filename) {
    $path = ROOT_PATH . '/uploads/qrcodes/';
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    
    $filepath = $path . $filename;
    
    // Générer le QR code
    QRcode::png($data, $filepath, QR_ECLEVEL_H, 10, 2);
    
    // Retourner le chemin complet pour l'affichage web
    return 'http://localhost/isgi_system/uploads/qrcodes/' . $filename;
}

// Traitement du formulaire de génération
$generated_qr = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'generate_student':
                $student_id = $_POST['student_id'] ?? 0;
                $type = $_POST['qr_type'] ?? 'etudiant';
                
                // Récupérer les infos de l'étudiant
                $query = "SELECT * FROM etudiants WHERE id = :id AND site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':id' => $student_id, ':site_id' => $site_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception('Étudiant non trouvé');
                }
                
                // Générer les données du QR code
                $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                          "NOM:" . $student['nom'] . "|" .
                          "PRENOM:" . $student['prenom'] . "|" .
                          "SITE:" . $site_id . "|" .
                          "TYPE:etudiant|" .
                          "DATE:" . date('YmdHis');
                
                $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                // Mettre à jour la base de données
                $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE id = :id";
                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':qr_data' => $qr_data,
                    ':id' => $student_id
                ]);
                
                $generated_qr = [
                    'type' => 'Étudiant',
                    'name' => $student['nom'] . ' ' . $student['prenom'],
                    'matricule' => $student['matricule'],
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_' . $student['matricule'] . '.png'
                ];
                break;
                
            case 'generate_class':
                $class_id = $_POST['class_id'] ?? 0;
                
                // Récupérer les infos de la classe
                $query = "SELECT * FROM classes WHERE id = :id AND site_id = :site_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':id' => $class_id, ':site_id' => $site_id]);
                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$class) {
                    throw new Exception('Classe non trouvée');
                }
                
                // Récupérer les étudiants de la classe
                $query = "SELECT matricule, nom, prenom FROM etudiants WHERE classe_id = :class_id AND statut = 'actif'";
                $stmt = $db->prepare($query);
                $stmt->execute([':class_id' => $class_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Générer les QR codes pour chaque étudiant
                $qr_codes = [];
                foreach($students as $student) {
                    $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                              "NOM:" . $student['nom'] . "|" .
                              "PRENOM:" . $student['prenom'] . "|" .
                              "CLASSE:" . $class_id . "|" .
                              "SITE:" . $site_id . "|" .
                              "TYPE:etudiant|" .
                              "DATE:" . date('YmdHis');
                    
                    $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
                    $qr_path = generateQRCode($qr_data, $filename);
                    
                    $qr_codes[] = [
                        'student' => $student,
                        'qr_path' => $qr_path,
                        'qr_data' => $qr_data
                    ];
                    
                    // Mettre à jour la base
                    $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE matricule = :matricule";
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([
                        ':qr_data' => $qr_data,
                        ':matricule' => $student['matricule']
                    ]);
                }
                
                $generated_qr = [
                    'type' => 'Classe',
                    'name' => $class['nom'],
                    'qr_codes' => $qr_codes,
                    'count' => count($qr_codes)
                ];
                break;
                
            case 'generate_surveillant':
                // QR code pour le surveillant lui-même
                $qr_data = "SURVEILLANT:" . $surveillant_id . "|" .
                          "SITE:" . $site_id . "|" .
                          "TYPE:surveillant|" .
                          "DATE:" . date('YmdHis') . "|" .
                          "AUTH:" . md5($surveillant_id . $site_id . date('Ymd'));
                
                $filename = 'surveillant_' . $surveillant_id . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                $generated_qr = [
                    'type' => 'Surveillant',
                    'name' => 'QR Code Personnel',
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_Surveillant_' . date('Ymd') . '.png'
                ];
                break;
                
            case 'generate_custom':
                $custom_data = $_POST['custom_data'] ?? '';
                $custom_label = $_POST['custom_label'] ?? 'Personnalisé';
                
                if (empty($custom_data)) {
                    throw new Exception('Veuillez entrer des données pour le QR code');
                }
                
                $qr_data = "CUSTOM:" . base64_encode($custom_data) . "|" .
                          "TYPE:custom|" .
                          "DATE:" . date('YmdHis') . "|" .
                          "LABEL:" . $custom_label;
                
                $filename = 'custom_' . md5($custom_data) . '_' . time() . '.png';
                $qr_path = generateQRCode($qr_data, $filename);
                
                $generated_qr = [
                    'type' => 'Personnalisé',
                    'name' => $custom_label,
                    'qr_path' => $qr_path,
                    'qr_data' => $qr_data,
                    'download_name' => 'QR_' . $custom_label . '_' . date('Ymd') . '.png'
                ];
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Récupérer les étudiants et classes pour les formulaires
$query_students = "SELECT id, matricule, nom, prenom FROM etudiants WHERE site_id = :site_id AND statut = 'actif' ORDER BY nom, prenom";
$stmt_students = $db->prepare($query_students);
$stmt_students->execute([':site_id' => $site_id]);
$students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

$query_classes = "SELECT id, nom FROM classes WHERE site_id = :site_id ORDER BY nom";
$stmt_classes = $db->prepare($query_classes);
$stmt_classes->execute([':site_id' => $site_id]);
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);
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
    
    <!-- QR Code Styling -->
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
    }
    
    .qr-card {
        border-radius: 15px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        border: none;
    }
    
    .qr-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
    }
    
    .qr-preview {
        width: 200px;
        height: 200px;
        border: 2px dashed #dee2e6;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
        margin: 0 auto;
        overflow: hidden;
    }
    
    .qr-preview img {
        max-width: 100%;
        max-height: 100%;
        padding: 10px;
        background: white;
    }
    
    .nav-tabs .nav-link {
        color: var(--primary-color);
        border: none;
        padding: 10px 20px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-radius: 8px;
    }
    
    .qr-type-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin: 0 auto 10px;
    }
    
    .type-student { background-color: rgba(52, 152, 219, 0.1); color: var(--secondary-color); }
    .type-class { background-color: rgba(39, 174, 96, 0.1); color: var(--success-color); }
    .type-surveillant { background-color: rgba(231, 76, 60, 0.1); color: var(--accent-color); }
    .type-custom { background-color: rgba(243, 156, 18, 0.1); color: var(--warning-color); }
    
    .qr-actions .btn {
        border-radius: 20px;
        padding: 8px 20px;
        font-weight: 500;
    }
    
    .badge-qr {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 0.8em;
    }
    
    #qrDataText {
        font-family: 'Courier New', monospace;
        font-size: 12px;
        background: #f8f9fa;
        border-radius: 5px;
        padding: 10px;
        max-height: 100px;
        overflow-y: auto;
    }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-qrcode me-2"></i>
                Générer QR Codes
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i> Retour Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="scanner_qr.php">
                            <i class="fas fa-camera me-1"></i> Scanner QR
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-4">
        <!-- En-tête -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="fas fa-barcode text-primary me-2"></i>Générateur de QR Codes</h2>
                        <p class="text-muted">Générez des QR codes pour les étudiants, classes et plus</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-outline-primary" onclick="printAllQRCodes()">
                            <i class="fas fa-print me-1"></i> Imprimer Tous
                        </button>
                        <button class="btn btn-success" onclick="downloadBatch()">
                            <i class="fas fa-download me-1"></i> Télécharger ZIP
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Sidebar avec options -->
            <div class="col-lg-3">
                <div class="card qr-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Options de Génération</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <a href="#studentTab" class="list-group-item list-group-item-action active" 
                               data-bs-toggle="tab" role="tab">
                                <div class="d-flex align-items-center">
                                    <div class="qr-type-icon type-student">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h6 class="mb-1">Étudiant</h6>
                                        <small class="text-muted">QR code individuel</small>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="#classTab" class="list-group-item list-group-item-action" 
                               data-bs-toggle="tab" role="tab">
                                <div class="d-flex align-items-center">
                                    <div class="qr-type-icon type-class">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h6 class="mb-1">Classe</h6>
                                        <small class="text-muted">Tous les étudiants</small>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="#surveillantTab" class="list-group-item list-group-item-action" 
                               data-bs-toggle="tab" role="tab">
                                <div class="d-flex align-items-center">
                                    <div class="qr-type-icon type-surveillant">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h6 class="mb-1">Surveillant</h6>
                                        <small class="text-muted">QR code personnel</small>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="#customTab" class="list-group-item list-group-item-action" 
                               data-bs-toggle="tab" role="tab">
                                <div class="d-flex align-items-center">
                                    <div class="qr-type-icon type-custom">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h6 class="mb-1">Personnalisé</h6>
                                        <small class="text-muted">Données spécifiques</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                        
                        <hr>
                        
                        <div class="mt-3">
                            <h6><i class="fas fa-history me-2"></i>QR Codes Récents</h6>
                            <div id="recentQRCodes" class="mt-2">
                                <!-- Liste des QR codes récents via AJAX -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contenu principal avec onglets -->
            <div class="col-lg-9">
                <div class="tab-content" id="qrTabsContent">
                    <!-- Onglet Étudiant -->
                    <div class="tab-pane fade show active" id="studentTab" role="tabpanel">
                        <div class="card qr-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>QR Code Étudiant</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="studentForm">
                                    <input type="hidden" name="action" value="generate_student">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Sélectionner un étudiant</label>
                                                <select class="form-select" name="student_id" required 
                                                        onchange="loadStudentInfo(this.value)">
                                                    <option value="">Choisir un étudiant...</option>
                                                    <?php foreach($students as $student): ?>
                                                    <option value="<?php echo $student['id']; ?>">
                                                        <?php echo htmlspecialchars($student['matricule'] . ' - ' . $student['nom'] . ' ' . $student['prenom']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Type de QR Code</label>
                                                <select class="form-select" name="qr_type">
                                                    <option value="etudiant">QR Code Présence</option>
                                                    <option value="identite">QR Code Identité</option>
                                                    <option value="acces">QR Code Accès</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Options</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="include_photo" id="includePhoto">
                                                    <label class="form-check-label" for="includePhoto">
                                                        Inclure la photo dans le QR code
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="auto_print" id="autoPrint">
                                                    <label class="form-check-label" for="autoPrint">
                                                        Imprimer automatiquement
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="send_email" id="sendEmail">
                                                    <label class="form-check-label" for="sendEmail">
                                                        Envoyer par email à l'étudiant
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div id="studentInfo" class="alert alert-info" style="display: none;">
                                                <h6>Informations de l'étudiant</h6>
                                                <div id="studentDetails">
                                                    <!-- Rempli par JavaScript -->
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Format du QR Code</label>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <label class="form-label">Taille</label>
                                                        <select class="form-select" name="qr_size">
                                                            <option value="small">Petit (200x200)</option>
                                                            <option value="medium" selected>Moyen (300x300)</option>
                                                            <option value="large">Grand (400x400)</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">Couleur</label>
                                                        <input type="color" class="form-control form-control-color" 
                                                               name="qr_color" value="#000000" title="Choisir la couleur">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Format d'impression</label>
                                                <select class="form-select" name="print_format">
                                                    <option value="badge">Badge (85x55 mm)</option>
                                                    <option value="carte">Carte étudiante (CR80)</option>
                                                    <option value="sticker">Autocollant (50x50 mm)</option>
                                                    <option value="simple">Simple (A4)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-qrcode me-2"></i> Générer QR Code
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-lg ms-2" 
                                                onclick="generateStudentBatch()">
                                            <i class="fas fa-bolt me-2"></i> Générer en Masse
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Onglet Classe -->
                    <div class="tab-pane fade" id="classTab" role="tabpanel">
                        <div class="card qr-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>QR Codes par Classe</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="classForm">
                                    <input type="hidden" name="action" value="generate_class">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Sélectionner une classe</label>
                                                <select class="form-select" name="class_id" required 
                                                        onchange="loadClassInfo(this.value)">
                                                    <option value="">Choisir une classe...</option>
                                                    <?php foreach($classes as $class): ?>
                                                    <option value="<?php echo $class['id']; ?>">
                                                        <?php echo htmlspecialchars($class['nom']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Options de génération</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="include_all" id="includeAll" checked>
                                                    <label class="form-check-label" for="includeAll">
                                                        Tous les étudiants de la classe
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="only_missing" id="onlyMissing">
                                                    <label class="form-check-label" for="onlyMissing">
                                                        Seulement les étudiants sans QR code
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="generate_pdf" id="generatePDF">
                                                    <label class="form-check-label" for="generatePDF">
                                                        Générer un PDF avec tous les QR codes
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div id="classInfo" class="alert alert-info" style="display: none;">
                                                <h6>Informations de la classe</h6>
                                                <div id="classDetails">
                                                    <!-- Rempli par JavaScript -->
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Format de sortie</label>
                                                <select class="form-select" name="output_format">
                                                    <option value="individual">QR codes individuels</option>
                                                    <option value="sheet">Feuille A4 (9 par page)</option>
                                                    <option value="badges">Feuille de badges</option>
                                                    <option value="csv">Liste CSV avec liens</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Options d'impression</label>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <input type="number" class="form-control" name="copies" 
                                                               value="1" min="1" max="10" placeholder="Copies">
                                                    </div>
                                                    <div class="col-6">
                                                        <select class="form-select" name="paper_size">
                                                            <option value="A4">A4</option>
                                                            <option value="A5">A5</option>
                                                            <option value="Letter">Letter</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-qrcode me-2"></i> Générer pour la Classe
                                        </button>
                                        <button type="button" class="btn btn-outline-primary btn-lg ms-2" 
                                                onclick="generateAllClasses()">
                                            <i class="fas fa-layer-group me-2"></i> Toutes les Classes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Onglet Surveillant -->
                    <div class="tab-pane fade" id="surveillantTab" role="tabpanel">
                        <div class="card qr-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-user-shield me-2"></i>QR Code Surveillant</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="surveillantForm">
                                    <input type="hidden" name="action" value="generate_surveillant">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="alert alert-warning">
                                                <h6><i class="fas fa-info-circle me-2"></i>Information</h6>
                                                <p class="mb-0">Ce QR code vous permettra d'accéder à des fonctionnalités 
                                                spéciales et d'authentifier vos actions.</p>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Type d'accès</label>
                                                <select class="form-select" name="access_type">
                                                    <option value="full">Accès complet</option>
                                                    <option value="presence">Présence uniquement</option>
                                                    <option value="scan">Scan uniquement</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Validité</label>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <input type="date" class="form-control" name="valid_from" 
                                                               value="<?php echo date('Y-m-d'); ?>">
                                                        <small class="text-muted">Début</small>
                                                    </div>
                                                    <div class="col-6">
                                                        <input type="date" class="form-control" name="valid_to" 
                                                               value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                                                        <small class="text-muted">Fin</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Sécurité</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="require_pin" id="requirePin">
                                                    <label class="form-check-label" for="requirePin">
                                                        Requérir un PIN pour l'utilisation
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="expirable" id="expirable" checked>
                                                    <label class="form-check-label" for="expirable">
                                                        QR code expirable
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="revocable" id="revocable" checked>
                                                    <label class="form-check-label" for="revocable">
                                                        Révocable en cas de perte
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <?php if(isset($_SESSION['user_name'])): ?>
                                            <div class="alert alert-info">
                                                <h6>Vos informations</h6>
                                                <p class="mb-1">
                                                    <strong>Nom:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                                                </p>
                                                <p class="mb-0">
                                                    <strong>Site:</strong> 
                                                    <?php 
                                                    $query = "SELECT nom FROM sites WHERE id = :site_id";
                                                    $stmt = $db->prepare($query);
                                                    $stmt->execute([':site_id' => $site_id]);
                                                    $site = $stmt->fetch(PDO::FETCH_ASSOC);
                                                    echo htmlspecialchars($site['nom'] ?? 'N/A');
                                                    ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-warning btn-lg">
                                            <i class="fas fa-key me-2"></i> Générer QR Code Surveillant
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-lg ms-2" 
                                                onclick="generateStaffQR()">
                                            <i class="fas fa-users-cog me-2"></i> Pour tout le Personnel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Onglet Personnalisé -->
                    <div class="tab-pane fade" id="customTab" role="tabpanel">
                        <div class="card qr-card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>QR Code Personnalisé</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="customForm">
                                    <input type="hidden" name="action" value="generate_custom">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Libellé du QR code</label>
                                                <input type="text" class="form-control" name="custom_label" 
                                                       placeholder="Ex: Salle de réunion, Matériel..." required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Type de données</label>
                                                <select class="form-select" name="data_type" onchange="changeDataType(this.value)">
                                                    <option value="text">Texte libre</option>
                                                    <option value="url">URL/liens</option>
                                                    <option value="wifi">WiFi</option>
                                                    <option value="contact">Contact</option>
                                                    <option value="event">Événement</option>
                                                    <option value="location">Localisation</option>
                                                </select>
                                            </div>
                                            
                                            <div id="customFields">
                                                <!-- Champs dynamiques selon le type -->
                                                <div class="mb-3">
                                                    <label class="form-label">Données</label>
                                                    <textarea class="form-control" name="custom_data" rows="5" 
                                                              placeholder="Entrez les données à encoder..." required></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Options avancées</label>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <label class="form-label">Niveau de correction</label>
                                                        <select class="form-select" name="error_correction">
                                                            <option value="L">Faible (7%)</option>
                                                            <option value="M">Moyen (15%)</option>
                                                            <option value="Q" selected>Qualité (25%)</option>
                                                            <option value="H">Haute (30%)</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">Version QR</label>
                                                        <select class="form-select" name="qr_version">
                                                            <option value="1">1 (21x21)</option>
                                                            <option value="5">5 (37x37)</option>
                                                            <option value="10" selected>10 (57x57)</option>
                                                            <option value="20">20 (97x97)</option>
                                                            <option value="40">40 (177x177)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Style du QR code</label>
                                                <div class="row g-2">
                                                    <div class="col-4">
                                                        <input type="color" class="form-control form-control-color" 
                                                               name="fg_color" value="#000000" title="Couleur avant-plan">
                                                        <small class="text-muted">Avant-plan</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <input type="color" class="form-control form-control-color" 
                                                               name="bg_color" value="#ffffff" title="Couleur arrière-plan">
                                                        <small class="text-muted">Arrière-plan</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <select class="form-select" name="qr_style">
                                                            <option value="square">Carrés</option>
                                                            <option value="rounded">Arrondi</option>
                                                            <option value="dots">Points</option>
                                                            <option value="hex">Hexagones</option>
                                                        </select>
                                                        <small class="text-muted">Style</small>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Logo (optionnel)</label>
                                                <input type="file" class="form-control" name="logo_file" 
                                                       accept="image/png,image/jpeg" id="logoUpload">
                                                <small class="text-muted">PNG ou JPG, max 100KB</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-info btn-lg">
                                            <i class="fas fa-magic me-2"></i> Générer QR Code Personnalisé
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-lg ms-2" 
                                                onclick="previewQR()">
                                            <i class="fas fa-eye me-2"></i> Prévisualiser
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Résultat de génération -->
                <?php if($generated_qr): ?>
                <div class="card qr-card mt-4" id="resultCard">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            QR Code Généré avec Succès
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center">
                                <div class="qr-preview mb-3">
                                    <?php if($generated_qr['type'] === 'Classe'): ?>
                                        <div id="classQRCarousel" class="carousel slide" data-bs-ride="carousel">
                                            <div class="carousel-inner">
                                                <?php foreach($generated_qr['qr_codes'] as $index => $qr): ?>
                                                <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                    <img src="<?php echo $qr['qr_path']; ?>" 
                                                         alt="QR Code <?php echo $qr['student']['nom']; ?>">
                                                    <div class="carousel-caption d-none d-md-block">
                                                        <small><?php echo $qr['student']['nom']; ?></small>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button class="carousel-control-prev" type="button" data-bs-target="#classQRCarousel" data-bs-slide="prev">
                                                <span class="carousel-control-prev-icon"></span>
                                            </button>
                                            <button class="carousel-control-next" type="button" data-bs-target="#classQRCarousel" data-bs-slide="next">
                                                <span class="carousel-control-next-icon"></span>
                                            </button>
                                        </div>
                                        <p class="mt-2">
                                            <span class="badge bg-primary"><?php echo $generated_qr['count']; ?> QR codes</span>
                                        </p>
                                    <?php else: ?>
                                        <img src="<?php echo $generated_qr['qr_path']; ?>" 
                                             alt="QR Code <?php echo $generated_qr['name']; ?>">
                                    <?php endif; ?>
                                </div>
                                
                                <div class="qr-actions d-flex justify-content-center gap-2">
                                    <button class="btn btn-success" onclick="downloadQR('<?php echo $generated_qr['qr_path']; ?>', '<?php echo $generated_qr['download_name'] ?? 'qr_code.png'; ?>')">
                                        <i class="fas fa-download me-1"></i> Télécharger
                                    </button>
                                    <button class="btn btn-primary" onclick="printQR()">
                                        <i class="fas fa-print me-1"></i> Imprimer
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <h5><?php echo $generated_qr['name']; ?></h5>
                                <p class="text-muted">
                                    <i class="fas fa-tag me-1"></i>
                                    Type: <span class="badge-qr"><?php echo $generated_qr['type']; ?></span>
                                    <?php if($generated_qr['type'] === 'Étudiant'): ?>
                                        | Matricule: <strong><?php echo $generated_qr['matricule']; ?></strong>
                                    <?php endif; ?>
                                </p>
                                
                                <div class="mb-3">
                                    <label class="form-label">Données encodées:</label>
                                    <div id="qrDataText" class="mb-2">
                                        <?php echo htmlspecialchars($generated_qr['qr_data']); ?>
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="copyQRData()">
                                        <i class="fas fa-copy me-1"></i> Copier les données
                                    </button>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6><i class="fas fa-info-circle me-2"></i>Informations techniques</h6>
                                                <ul class="mb-0">
                                                    <li>Date: <?php echo date('d/m/Y H:i:s'); ?></li>
                                                    <li>Format: PNG</li>
                                                    <li>Taille: 300x300 pixels</li>
                                                    <li>Encodage: UTF-8</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6><i class="fas fa-share-alt me-2"></i>Partage rapide</h6>
                                                <div class="btn-group w-100">
                                                    <button class="btn btn-outline-primary" onclick="shareQR('whatsapp')">
                                                        <i class="fab fa-whatsapp"></i>
                                                    </button>
                                                    <button class="btn btn-outline-info" onclick="shareQR('email')">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                    <button class="btn btn-outline-dark" onclick="shareQR('sms')">
                                                        <i class="fas fa-sms"></i>
                                                    </button>
                                                    <button class="btn btn-outline-secondary" onclick="copyQRImage()">
                                                        <i class="fas fa-image"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if($generated_qr['type'] === 'Classe'): ?>
                                <div class="mt-3">
                                    <h6>Liste des QR codes générés:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Étudiant</th>
                                                    <th>Matricule</th>
                                                    <th>QR Code</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($generated_qr['qr_codes'] as $qr): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($qr['student']['nom'] . ' ' . $qr['student']['prenom']); ?></td>
                                                    <td><span class="badge bg-secondary"><?php echo $qr['student']['matricule']; ?></span></td>
                                                    <td><img src="<?php echo $qr['qr_path']; ?>" width="50" height="50"></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="downloadQR('<?php echo $qr['qr_path']; ?>', 'qr_<?php echo $qr['student']['matricule']; ?>.png')">
                                                            <i class="fas fa-download"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center">
                                        <button class="btn btn-primary" onclick="downloadClassZip()">
                                            <i class="fas fa-file-archive me-2"></i> Télécharger tous les QR codes (ZIP)
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de prévisualisation -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Prévisualisation QR Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="qrPreview" class="qr-preview mx-auto mb-3" style="width: 300px; height: 300px;">
                        <!-- Prévisualisation dynamique -->
                    </div>
                    <div id="previewInfo"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    
    <script>
    // Charger les QR codes récents
    async function loadRecentQRCodes() {
        try {
            const response = await fetch('ajax/get_recent_qrcodes.php');
            const qrcodes = await response.json();
            
            const container = document.getElementById('recentQRCodes');
            if (qrcodes.length > 0) {
                let html = '';
                qrcodes.forEach(qr => {
                    html += `
                        <div class="d-flex align-items-center mb-2 p-2 border rounded">
                            <img src="${qr.path}" width="40" height="40" class="me-2">
                            <div class="flex-grow-1">
                                <small class="d-block">${qr.name}</small>
                                <small class="text-muted">${qr.date}</small>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" onclick="downloadQR('${qr.path}', '${qr.filename}')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-muted text-center">Aucun QR code récent</p>';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }
    
    // Charger les infos de l'étudiant
    async function loadStudentInfo(studentId) {
        if (!studentId) return;
        
        try {
            const response = await fetch(`ajax/get_student_info.php?id=${studentId}`);
            const student = await response.json();
            
            const container = document.getElementById('studentInfo');
            const details = document.getElementById('studentDetails');
            
            if (student) {
                details.innerHTML = `
                    <p class="mb-1"><strong>Matricule:</strong> ${student.matricule}</p>
                    <p class="mb-1"><strong>Classe:</strong> ${student.classe || 'Non assigné'}</p>
                    <p class="mb-1"><strong>Téléphone:</strong> ${student.telephone || 'Non renseigné'}</p>
                    <p class="mb-0"><strong>Email:</strong> ${student.email || 'Non renseigné'}</p>
                `;
                container.style.display = 'block';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }
    
    // Charger les infos de la classe
    async function loadClassInfo(classId) {
        if (!classId) return;
        
        try {
            const response = await fetch(`ajax/get_class_info.php?id=${classId}`);
            const classe = await response.json();
            
            const container = document.getElementById('classInfo');
            const details = document.getElementById('classDetails');
            
            if (classe) {
                details.innerHTML = `
                    <p class="mb-1"><strong>Effectif:</strong> ${classe.effectif} étudiants</p>
                    <p class="mb-1"><strong>Niveau:</strong> ${classe.niveau || 'N/A'}</p>
                    <p class="mb-1"><strong>Filière:</strong> ${classe.filiere || 'N/A'}</p>
                    <p class="mb-0"><strong>Avec QR code:</strong> ${classe.with_qr || 0} étudiants</p>
                `;
                container.style.display = 'block';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }
    
    // Changer les champs selon le type de données
    function changeDataType(type) {
        const container = document.getElementById('customFields');
        let html = '';
        
        switch(type) {
            case 'url':
                html = `
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="url" class="form-control" name="custom_data" 
                               placeholder="https://example.com" required>
                    </div>
                `;
                break;
                
            case 'wifi':
                html = `
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">SSID (Nom réseau)</label>
                            <input type="text" class="form-control" name="wifi_ssid" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Mot de passe</label>
                            <input type="text" class="form-control" name="wifi_password">
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <label class="form-label">Type de sécurité</label>
                            <select class="form-select" name="wifi_type">
                                <option value="WPA">WPA/WPA2</option>
                                <option value="WEP">WEP</option>
                                <option value="nopass">Aucun</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Caché</label>
                            <select class="form-select" name="wifi_hidden">
                                <option value="false">Non</option>
                                <option value="true">Oui</option>
                            </select>
                        </div>
                    </div>
                `;
                break;
                
            case 'contact':
                html = `
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control" name="contact_name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" name="contact_phone" required>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="contact_email">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Entreprise</label>
                            <input type="text" class="form-control" name="contact_company">
                        </div>
                    </div>
                `;
                break;
                
            case 'event':
                html = `
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Nom de l'événement</label>
                            <input type="text" class="form-control" name="event_title" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Lieu</label>
                            <input type="text" class="form-control" name="event_location">
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <label class="form-label">Date début</label>
                            <input type="datetime-local" class="form-control" name="event_start" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date fin</label>
                            <input type="datetime-local" class="form-control" name="event_end">
                        </div>
                    </div>
                `;
                break;
                
            case 'location':
                html = `
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Latitude</label>
                            <input type="number" step="any" class="form-control" name="lat" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Longitude</label>
                            <input type="number" step="any" class="form-control" name="lng" required>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-12">
                            <label class="form-label">Libellé</label>
                            <input type="text" class="form-control" name="location_label" 
                                   placeholder="Ex: ISGI Brazzaville">
                        </div>
                    </div>
                `;
                break;
                
            default:
                html = `
                    <div class="mb-3">
                        <label class="form-label">Données</label>
                        <textarea class="form-control" name="custom_data" rows="5" 
                                  placeholder="Entrez les données à encoder..." required></textarea>
                    </div>
                `;
        }
        
        container.innerHTML = html;
    }
    
    // Télécharger un QR code
    function downloadQR(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // Télécharger tous les QR codes d'une classe en ZIP
    async function downloadClassZip() {
        Swal.fire({
            title: 'Préparation du ZIP',
            text: 'Création de l\'archive en cours...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        try {
            const response = await fetch('ajax/generate_class_zip.php');
            const blob = await response.blob();
            
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `qr_codes_classe_${new Date().toISOString().split('T')[0]}.zip`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            Swal.fire('Succès', 'Archive téléchargée avec succès', 'success');
        } catch (error) {
            Swal.fire('Erreur', 'Erreur lors de la création du ZIP', 'error');
            console.error('Erreur:', error);
        }
    }
    
    // Télécharger un batch de QR codes
    async function downloadBatch() {
        Swal.fire({
            title: 'Génération en masse',
            input: 'select',
            inputOptions: {
                'all_students': 'Tous les étudiants',
                'all_classes': 'Toutes les classes',
                'missing_qr': 'Étudiants sans QR code'
            },
            inputPlaceholder: 'Sélectionner une option',
            showCancelButton: true,
            confirmButtonText: 'Générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `ajax/generate_batch.php?type=${result.value}`;
            }
        });
    }
    
    // Imprimer un QR code
    function printQR() {
        const printWindow = window.open('', '_blank');
        const qrCard = document.getElementById('resultCard');
        
        printWindow.document.write(`
            <html>
            <head>
                <title>QR Code - ${document.title}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .print-header { text-align: center; margin-bottom: 30px; }
                    .qr-container { text-align: center; margin: 30px 0; }
                    .qr-container img { max-width: 300px; height: auto; }
                    .info-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    .info-table td { padding: 8px; border: 1px solid #ddd; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="print-header">
                    <h2>QR Code ISGI</h2>
                    <p>Généré le ${new Date().toLocaleDateString()} à ${new Date().toLocaleTimeString()}</p>
                </div>
                ${qrCard.innerHTML}
                <div class="footer">
                    <p>© ${new Date().getFullYear()} ISGI - Institut Supérieur de Gestion et d'Informatique</p>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
    
    // Copier les données du QR code
    function copyQRData() {
        const qrData = document.getElementById('qrDataText').textContent;
        navigator.clipboard.writeText(qrData).then(() => {
            Swal.fire('Succès', 'Données copiées dans le presse-papier', 'success');
        });
    }
    
    // Copier l'image du QR code
    async function copyQRImage() {
        try {
            const response = await fetch(document.querySelector('#resultCard img').src);
            const blob = await response.blob();
            
            const item = new ClipboardItem({ 'image/png': blob });
            await navigator.clipboard.write([item]);
            
            Swal.fire('Succès', 'Image copiée dans le presse-papier', 'success');
        } catch (error) {
            Swal.fire('Erreur', 'Impossible de copier l\'image', 'error');
        }
    }
    
    // Partager le QR code
    function shareQR(platform) {
        const qrData = document.getElementById('qrDataText').textContent;
        const qrImage = document.querySelector('#resultCard img').src;
        
        let url = '';
        switch(platform) {
            case 'whatsapp':
                url = `https://wa.me/?text=${encodeURIComponent('Voici mon QR code ISGI: ' + qrData)}`;
                window.open(url, '_blank');
                break;
            case 'email':
                url = `mailto:?subject=QR Code ISGI&body=${encodeURIComponent('QR Code généré: ' + qrData)}`;
                window.location.href = url;
                break;
            case 'sms':
                url = `sms:?body=${encodeURIComponent('QR Code ISGI: ' + qrData)}`;
                window.location.href = url;
                break;
        }
    }
    
    // Générer en masse pour les étudiants
    function generateStudentBatch() {
        Swal.fire({
            title: 'Génération en masse',
            html: `
                <div class="text-start">
                    <p>Sélectionnez les étudiants pour générer leurs QR codes:</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllStudents">
                        <label class="form-check-label" for="selectAllStudents">
                            Tous les étudiants actifs
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="onlyWithoutQR">
                        <label class="form-check-label" for="onlyWithoutQR">
                            Seulement ceux sans QR code
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="byClass" checked>
                        <label class="form-check-label" for="byClass">
                            Grouper par classe
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                // Redirection vers le script de génération en masse
                window.location.href = 'ajax/generate_student_batch.php';
            }
        });
    }
    
    // Générer pour toutes les classes
    function generateAllClasses() {
        Swal.fire({
            title: 'Confirmation',
            text: 'Générer des QR codes pour toutes les classes ? Cela peut prendre quelques minutes.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Oui, générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'ajax/generate_all_classes.php';
            }
        });
    }
    
    // Générer pour tout le personnel
    function generateStaffQR() {
        Swal.fire({
            title: 'QR Codes Personnel',
            text: 'Générer des QR codes pour tout le personnel administratif et enseignant ?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Générer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'ajax/generate_staff_qr.php';
            }
        });
    }
    
    // Prévisualiser un QR code
    function previewQR() {
        // Récupérer les données du formulaire
        const form = document.getElementById('customForm');
        const formData = new FormData(form);
        
        // Générer un QR code de prévisualisation
        const qr = qrcode(0, 'H');
        qr.addData('Prévisualisation - Données non encore générées');
        qr.make();
        
        const previewDiv = document.getElementById('qrPreview');
        previewDiv.innerHTML = qr.createImgTag(10);
        
        // Afficher le modal
        const modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
    }
    
    // Imprimer tous les QR codes
    function printAllQRCodes() {
        Swal.fire({
            title: 'Impression multiple',
            input: 'select',
            inputOptions: {
                'current_class': 'Classe actuelle',
                'all_students': 'Tous les étudiants',
                'missing_cards': 'Cartes manquantes'
            },
            inputPlaceholder: 'Sélectionner',
            showCancelButton: true,
            confirmButtonText: 'Imprimer',
            cancelButtonText: 'Annuler'
        }).then((result) => {
            if (result.isConfirmed) {
                window.open(`ajax/print_qrcodes.php?type=${result.value}`, '_blank');
            }
        });
    }
    
    // Initialisation
    document.addEventListener('DOMContentLoaded', () => {
        // Charger les QR codes récents
        loadRecentQRCodes();
        
        // Initialiser les tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Auto-sélection pour les formulaires
        const firstStudent = document.querySelector('select[name="student_id"] option:not([value=""])');
        if (firstStudent) {
            loadStudentInfo(firstStudent.value);
        }
        
        const firstClass = document.querySelector('select[name="class_id"] option:not([value=""])');
        if (firstClass) {
            loadClassInfo(firstClass.value);
        }
        
        // Sauvegarder automatiquement les formulaires
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Validation supplémentaire
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                this.classList.add('was-validated');
            });
        });
    });
    
    // Auto-refresh des QR codes récents
    setInterval(loadRecentQRCodes, 30000); // Toutes les 30 secondes
    </script>
</body>
</html>