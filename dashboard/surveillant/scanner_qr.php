<?php
// Démarrage de la session
session_start();

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'isgi_systeme');
define('DB_USER', 'root');
define('DB_PASS', 'admin1234');

// Variables de session (à adapter selon votre système d'authentification)
$site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : 1;
$surveillant_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 26;

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Traitement des requêtes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'check_student':
            $qr_data = $_POST['qr_data'] ?? '';
            $type_presence = $_POST['type_presence'] ?? 'entree_ecole';
            $statut = $_POST['statut'] ?? 'present';
            
            echo json_encode(checkAndRegisterStudent($qr_data, $type_presence, $statut, $site_id, $surveillant_id));
            exit;
    }
}

// Fonction pour vérifier et enregistrer l'étudiant
function checkAndRegisterStudent($qr_data, $type_presence, $statut, $site_id, $surveillant_id) {
    global $pdo;
    
    try {
        // Nettoyer et parser les données du QR code
        $qr_data = trim($qr_data);
        
        // Vérifier le format du QR code
        // Format attendu: ETUDIANT:ISGI-2025-00019|NOM:DIALLO|PRENOM:Aminata|SITE:1
        if (!preg_match('/ETUDIANT:/', $qr_data)) {
            return [
                'success' => false, 
                'message' => 'Format QR code invalide. Format attendu: ETUDIANT:XXXX|NOM:XXX|PRENOM:XXX|SITE:X',
                'qr_data' => $qr_data
            ];
        }
        
        // Extraire le matricule du QR code
        $pattern = '/ETUDIANT:([^|]+)/';
        preg_match($pattern, $qr_data, $matches);
        
        if (!isset($matches[1])) {
            return ['success' => false, 'message' => 'Matricule non trouvé dans le QR code'];
        }
        
        $matricule = trim($matches[1]);
        
        // Vérifier si l'étudiant existe dans la base avec CE matricule EXACT
        $query = "SELECT * FROM etudiants WHERE matricule = :matricule AND site_id = :site_id";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':matricule' => $matricule,
            ':site_id' => $site_id
        ]);
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            // Essayer de trouver avec qr_code_data si matricule ne correspond pas
            $query2 = "SELECT * FROM etudiants WHERE qr_code_data = :qr_data AND site_id = :site_id";
            $stmt2 = $pdo->prepare($query2);
            $stmt2->execute([
                ':qr_data' => $qr_data,
                ':site_id' => $site_id
            ]);
            
            $student = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                return [
                    'success' => false, 
                    'message' => "Étudiant non trouvé avec le matricule: $matricule",
                    'matricule' => $matricule,
                    'qr_data' => $qr_data
                ];
            }
        }
        
        // Vérifier le statut de l'étudiant
        if ($student['statut'] !== 'actif') {
            return [
                'success' => false,
                'message' => "Étudiant non actif. Statut: " . $student['statut'],
                'student' => [
                    'matricule' => $student['matricule'],
                    'nom' => $student['nom'],
                    'prenom' => $student['prenom'],
                    'statut' => $student['statut']
                ]
            ];
        }
        
        // Vérifier si une présence similaire a déjà été enregistrée récemment (dans les 5 dernières minutes)
        $query_check = "SELECT id FROM presences 
                       WHERE etudiant_id = :etudiant_id 
                       AND type_presence = :type_presence 
                       AND DATE_SUB(NOW(), INTERVAL 5 MINUTE) <= date_heure
                       LIMIT 1";
        $stmt_check = $pdo->prepare($query_check);
        $stmt_check->execute([
            ':etudiant_id' => $student['id'],
            ':type_presence' => $type_presence
        ]);
        
        if ($stmt_check->fetch()) {
            return [
                'success' => false,
                'message' => "Présence déjà enregistrée récemment pour ce type",
                'student' => $student,
                'type_presence' => $type_presence
            ];
        }
        
        // Enregistrer la présence dans la table presences
        $presence_id = registerPresence(
            $student['id'], 
            $site_id, 
            $type_presence, 
            $qr_data, // Utiliser les données EXACTES du QR code
            $surveillant_id, 
            $statut
        );
        
        // Récupérer les informations complètes pour l'affichage
        $student_info = getStudentCompleteInfo($student['id']);
        
        return [
            'success' => true,
            'message' => 'Présence enregistrée avec succès!',
            'presence_id' => $presence_id,
            'student' => $student_info,
            'type_presence' => $type_presence,
            'statut' => $statut,
            'timestamp' => date('Y-m-d H:i:s'),
            'matricule' => $student['matricule']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false, 
            'message' => 'Erreur système: ' . $e->getMessage(),
            'error' => $e->getTraceAsString()
        ];
    }
}

// Fonction pour enregistrer la présence
function registerPresence($etudiant_id, $site_id, $type_presence, $qr_code_scanne, $surveillant_id, $statut) {
    global $pdo;
    
    $query = "INSERT INTO presences 
              (etudiant_id, site_id, type_presence, qr_code_scanne, surveillant_id, statut, date_heure, date_creation) 
              VALUES (:etudiant_id, :site_id, :type_presence, :qr_code_scanne, :surveillant_id, :statut, NOW(), NOW())";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':etudiant_id' => $etudiant_id,
        ':site_id' => $site_id,
        ':type_presence' => $type_presence,
        ':qr_code_scanne' => $qr_code_scanne, // Insérer les données exactes du QR code
        ':surveillant_id' => $surveillant_id,
        ':statut' => $statut
    ]);
    
    return $pdo->lastInsertId();
}

// Fonction pour récupérer les informations complètes de l'étudiant
function getStudentCompleteInfo($student_id) {
    global $pdo;
    
    $query = "SELECT 
                e.id,
                e.matricule,
                e.nom,
                e.prenom,
                e.numero_cni,
                e.date_naissance,
                e.lieu_naissance,
                e.sexe,
                e.nationalite,
                e.adresse,
                e.ville,
                e.pays,
                e.filiere,
                e.niveau,
                e.cycle_formation,
                e.statut,
                DATE(e.date_inscription) as date_inscription,
                COUNT(p.id) as total_presences,
                (SELECT COUNT(*) FROM presences 
                 WHERE etudiant_id = e.id AND DATE(date_heure) = CURDATE()) as presences_aujourdhui,
                (SELECT type_presence FROM presences 
                 WHERE etudiant_id = e.id 
                 ORDER BY date_heure DESC LIMIT 1) as derniere_presence,
                (SELECT date_heure FROM presences 
                 WHERE etudiant_id = e.id 
                 ORDER BY date_heure DESC LIMIT 1) as derniere_heure
              FROM etudiants e 
              LEFT JOIN presences p ON e.id = p.etudiant_id
              WHERE e.id = :id
              GROUP BY e.id";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':id' => $student_id]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Récupérer les dernières présences pour l'historique
function getRecentPresences($limit = 10) {
    global $pdo;
    
    $query = "SELECT 
                p.id,
                p.type_presence,
                p.statut,
                p.date_heure,
                p.qr_code_scanne,
                e.matricule,
                e.nom,
                e.prenom,
                CASE 
                    WHEN p.type_presence LIKE 'entree%' THEN 'entree'
                    WHEN p.type_presence LIKE 'sortie%' THEN 'sortie'
                    ELSE p.type_presence
                END as type_categorie
              FROM presences p 
              JOIN etudiants e ON p.etudiant_id = e.id 
              WHERE DATE(p.date_heure) = CURDATE()
              ORDER BY p.date_heure DESC 
              LIMIT :limit";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupérer les statistiques du jour
function getTodayStats() {
    global $pdo;
    
    $query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'present' THEN 1 ELSE 0 END) as presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as absents,
                SUM(CASE WHEN statut = 'retard' THEN 1 ELSE 0 END) as retards,
                SUM(CASE WHEN statut = 'justifie' THEN 1 ELSE 0 END) as justifies,
                COUNT(DISTINCT etudiant_id) as etudiants_uniques
              FROM presences 
              WHERE DATE(date_heure) = CURDATE()";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Récupérer les dernières présences et statistiques
$recent_presences = getRecentPresences(10);
$today_stats = getTodayStats();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR Code - Présence ISGI</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --info-color: #7209b7;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
        }
        
        body {
            background-color: #f5f7ff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: none;
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-bottom: none;
        }
        
        .scanner-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        
        #qr-reader {
            width: 100%;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .result-container {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 5px solid var(--primary-color);
        }
        
        .btn-scan {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-scan:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
        }
        
        .btn-presence {
            flex: 1;
            margin: 0.25rem;
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-presence:hover {
            transform: translateY(-2px);
        }
        
        .btn-entree {
            background: linear-gradient(to right, #2ecc71, #27ae60);
        }
        
        .btn-sortie {
            background: linear-gradient(to right, #e74c3c, #c0392b);
        }
        
        .history-item {
            border-left: 4px solid var(--success-color);
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            background-color: rgba(76, 201, 240, 0.1);
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }
        
        .history-item:hover {
            transform: translateX(5px);
        }
        
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 10;
        }
        
        .scan-line {
            position: absolute;
            width: 100%;
            height: 3px;
            background-color: var(--success-color);
            box-shadow: 0 0 10px var(--success-color);
            top: 50%;
            animation: scan 2s infinite linear;
        }
        
        @keyframes scan {
            0% { top: 10%; }
            50% { top: 90%; }
            100% { top: 10%; }
        }
        
        .corner {
            position: absolute;
            width: 30px;
            height: 30px;
            border-color: var(--success-color);
            border-style: solid;
        }
        
        .corner-tl {
            top: 10px;
            left: 10px;
            border-width: 5px 0 0 5px;
            border-radius: 10px 0 0 0;
        }
        
        .corner-tr {
            top: 10px;
            right: 10px;
            border-width: 5px 5px 0 0;
            border-radius: 0 10px 0 0;
        }
        
        .corner-bl {
            bottom: 10px;
            left: 10px;
            border-width: 0 0 5px 5px;
            border-radius: 0 0 0 10px;
        }
        
        .corner-br {
            bottom: 10px;
            right: 10px;
            border-width: 0 5px 5px 0;
            border-radius: 0 0 10px 0;
        }
        
        .student-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--success-color);
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .presence-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .status-present {
            background-color: rgba(76, 175, 80, 0.2);
            color: #2e7d32;
            border: 1px solid #4CAF50;
        }
        
        .status-absent {
            background-color: rgba(244, 67, 54, 0.2);
            color: #c62828;
            border: 1px solid #f44336;
        }
        
        .status-retard {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ff8f00;
            border: 1px solid #ffc107;
        }
        
        .status-justifie {
            background-color: rgba(33, 150, 243, 0.2);
            color: #1565c0;
            border: 1px solid #2196f3;
        }
        
        .stat-card {
            border-radius: 10px;
            padding: 1rem;
            color: white;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-total { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        .stat-present { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .stat-absent { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .stat-retard { background: linear-gradient(135deg, #f39c12, #e67e22); }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .type-indicator {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background-color: rgba(0,0,0,0.1);
        }
        
        .type-entree { background-color: rgba(46, 204, 113, 0.2); color: #27ae60; }
        .type-sortie { background-color: rgba(231, 76, 60, 0.2); color: #c0392b; }
        
        .qr-data-display {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
            word-break: break-all;
            max-height: 100px;
            overflow-y: auto;
        }
        
        .badge-presence {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-12">
                <!-- En-tête avec statistiques -->
                <div class="card mb-4">
                    <div class="card-header text-center">
                        <h1 class="h3 mb-2"><i class="fas fa-qrcode me-2"></i> Système de Présence ISGI</h1>
                        <p class="mb-0">Scanner les QR codes étudiants pour enregistrer les présences</p>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 col-md-3 mb-3">
                                <div class="stat-card stat-total">
                                    <h3 class="mb-0"><?php echo $today_stats['total'] ?? 0; ?></h3>
                                    <small>Total Présences</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="stat-card stat-present">
                                    <h3 class="mb-0"><?php echo $today_stats['presents'] ?? 0; ?></h3>
                                    <small>Présents</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="stat-card stat-absent">
                                    <h3 class="mb-0"><?php echo $today_stats['absents'] ?? 0; ?></h3>
                                    <small>Absents</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="stat-card stat-retard">
                                    <h3 class="mb-0"><?php echo $today_stats['retards'] ?? 0; ?></h3>
                                    <small>Retards</small>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $today_stats['etudiants_uniques'] ?? 0; ?> étudiants uniques aujourd'hui
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Zone principale -->
                <div class="row">
                    <!-- Zone de scan -->
                    <div class="col-lg-7 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-camera me-2"></i> Scanner QR Code Étudiant</h4>
                            </div>
                            <div class="card-body">
                                <!-- Configuration de la présence -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Type de présence</label>
                                    <div class="d-flex flex-wrap mb-3">
                                        <button class="btn btn-presence btn-entree active" data-type="entree_ecole" id="btn-entree-ecole">
                                            <i class="fas fa-sign-in-alt me-2"></i> Entrée École
                                        </button>
                                        <button class="btn btn-presence btn-sortie" data-type="sortie_ecole" id="btn-sortie-ecole">
                                            <i class="fas fa-sign-out-alt me-2"></i> Sortie École
                                        </button>
                                        <button class="btn btn-presence btn-entree" data-type="entree_classe" id="btn-entree-classe">
                                            <i class="fas fa-door-open me-2"></i> Entrée Classe
                                        </button>
                                        <button class="btn btn-presence btn-sortie" data-type="sortie_classe" id="btn-sortie-classe">
                                            <i class="fas fa-door-closed me-2"></i> Sortie Classe
                                        </button>
                                    </div>
                                    
                                    <label class="form-label fw-bold">Statut</label>
                                    <div class="d-flex flex-wrap">
                                        <button class="btn btn-success me-2 mb-2 btn-statut active" data-statut="present" id="btn-present">
                                            <i class="fas fa-check-circle me-1"></i> Présent
                                        </button>
                                        <button class="btn btn-danger me-2 mb-2 btn-statut" data-statut="absent" id="btn-absent">
                                            <i class="fas fa-times-circle me-1"></i> Absent
                                        </button>
                                        <button class="btn btn-warning me-2 mb-2 btn-statut" data-statut="retard" id="btn-retard">
                                            <i class="fas fa-clock me-1"></i> Retard
                                        </button>
                                        <button class="btn btn-info btn-statut" data-statut="justifie" id="btn-justifie">
                                            <i class="fas fa-file-alt me-1"></i> Justifié
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Zone de scan -->
                                <div class="scanner-container mb-3">
                                    <div id="qr-reader"></div>
                                    <div class="scanner-overlay">
                                        <div class="corner corner-tl"></div>
                                        <div class="corner corner-tr"></div>
                                        <div class="corner corner-bl"></div>
                                        <div class="corner corner-br"></div>
                                        <div class="scan-line"></div>
                                    </div>
                                </div>
                                
                                <!-- Contrôles du scanner -->
                                <div class="text-center">
                                    <div class="btn-group">
                                        <button class="btn btn-scan" id="start-scanner">
                                            <i class="fas fa-play me-2"></i> Démarrer Scanner
                                        </button>
                                        <button class="btn btn-outline-secondary" id="stop-scanner" disabled>
                                            <i class="fas fa-stop me-2"></i> Arrêter
                                        </button>
                                    </div>
                                    <p class="text-muted mt-2 mb-0">Scanner le QR code d'un étudiant</p>
                                </div>
                                
                                <!-- Résultat du scan -->
                                <div id="scan-result" class="mt-4">
                                    <div class="text-center text-muted py-5">
                                        <i class="fas fa-qrcode fa-3x mb-3"></i>
                                        <p>Aucun QR code scanné pour le moment</p>
                                    </div>
                                </div>
                                
                                <!-- Loading -->
                                <div class="loading-spinner" id="loading">
                                    <div class="spinner"></div>
                                    <p>Vérification de l'étudiant en cours...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informations étudiant et historique -->
                    <div class="col-lg-5">
                        <!-- Informations étudiant -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-user-graduate me-2"></i> Informations Étudiant</h4>
                            </div>
                            <div class="card-body">
                                <div id="student-details">
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-user fa-3x mb-3"></i>
                                        <p>En attente de scan...</p>
                                        <small class="text-muted">Scannez un QR code étudiant pour afficher les informations</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Historique des présences du jour -->
                        <div class="card">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-history me-2"></i> Présences Aujourd'hui</h4>
                            </div>
                            <div class="card-body">
                                <div id="recent-history">
                                    <?php if (!empty($recent_presences)): ?>
                                        <?php foreach ($recent_presences as $presence): ?>
                                            <div class="history-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></strong>
                                                        <div class="small text-muted">
                                                            <?php echo htmlspecialchars($presence['matricule']); ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="type-indicator type-<?php echo $presence['type_categorie']; ?>">
                                                            <?php echo htmlspecialchars($presence['type_presence']); ?>
                                                        </span>
                                                        <div class="small">
                                                            <?php 
                                                            $date = new DateTime($presence['date_heure']);
                                                            echo $date->format('H:i');
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <?php 
                                                    $status_class = 'status-' . $presence['statut'];
                                                    ?>
                                                    <span class="presence-status <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($presence['statut'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="fas fa-calendar-day fa-2x mb-2"></i>
                                            <p>Aucune présence enregistrée aujourd'hui</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="presences.php" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-list me-1"></i> Voir toutes les présences
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <footer class="text-center mt-4">
                    <p class="mb-2">Système de Présence ISGI &copy; <?php echo date('Y'); ?></p>
                    <p class="small mb-0">
                        Site ID: <?php echo $site_id; ?> | 
                        Surveillant ID: <?php echo $surveillant_id; ?> | 
                        <?php echo date('d/m/Y H:i:s'); ?>
                    </p>
                </footer>
            </div>
        </div>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.4/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Variables globales
        let html5QrCode = null;
        let isScannerActive = false;
        let selectedType = 'entree_ecole';
        let selectedStatut = 'present';
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Configuration des boutons
            setupEventListeners();
            
            // Démarrer automatiquement le scanner
            setTimeout(() => {
                startScanner();
            }, 500);
        });
        
        // Configuration des écouteurs d'événements
        function setupEventListeners() {
            // Boutons de type de présence
            document.querySelectorAll('[data-type]').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('[data-type]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    selectedType = this.dataset.type;
                    updateStatusDisplay();
                });
            });
            
            // Boutons de statut
            document.querySelectorAll('.btn-statut').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.btn-statut').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    selectedStatut = this.dataset.statut;
                    updateStatusDisplay();
                });
            });
            
            // Contrôles du scanner
            document.getElementById('start-scanner').addEventListener('click', startScanner);
            document.getElementById('stop-scanner').addEventListener('click', stopScanner);
        }
        
        // Mettre à jour l'affichage du statut
        function updateStatusDisplay() {
            console.log(`Type: ${selectedType}, Statut: ${selectedStatut}`);
        }
        
        // Démarrer le scanner
        function startScanner() {
            if (isScannerActive) {
                Swal.fire('Info', 'Le scanner est déjà actif', 'info');
                return;
            }
            
            html5QrCode = new Html5Qrcode("qr-reader");
            
            const qrCodeSuccessCallback = (decodedText, decodedResult) => {
                // Arrêter temporairement le scanner
                stopScanner();
                
                // Traiter le QR code scanné
                processQRCode(decodedText);
                
                // Redémarrer après un délai
                setTimeout(() => {
                    startScanner();
                }, 3000);
            };
            
            const config = { 
                fps: 10, 
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                rememberLastUsedCamera: true
            };
            
            // Démarrer la caméra
            Html5Qrcode.getCameras().then(devices => {
                if (devices && devices.length) {
                    let cameraId = devices[0].id;
                    
                    // Préférer la caméra arrière
                    const backCamera = devices.find(device => 
                        device.label.toLowerCase().includes('back') || 
                        device.label.toLowerCase().includes('arrière') ||
                        device.label.toLowerCase().includes('rear')
                    );
                    
                    if (backCamera) {
                        cameraId = backCamera.id;
                    }
                    
                    html5QrCode.start(
                        cameraId,
                        config,
                        qrCodeSuccessCallback,
                        (errorMessage) => {
                            // Erreur silencieuse
                            console.log(`Scan error: ${errorMessage}`);
                        }
                    ).then(() => {
                        isScannerActive = true;
                        document.getElementById('start-scanner').disabled = true;
                        document.getElementById('stop-scanner').disabled = false;
                        
                        // Afficher un message
                        showToast('success', 'Scanner démarré', 'Le scanner est maintenant actif');
                    }).catch(err => {
                        console.error("Erreur:", err);
                        showError("Impossible de démarrer la caméra: " + err.message);
                    });
                } else {
                    showError("Aucune caméra détectée. Vérifiez les permissions.");
                }
            }).catch(err => {
                console.error("Erreur:", err);
                showError("Erreur d'accès à la caméra: " + err.message);
            });
        }
        
        // Arrêter le scanner
        function stopScanner() {
            if (!html5QrCode || !isScannerActive) return;
            
            html5QrCode.stop().then(() => {
                isScannerActive = false;
                document.getElementById('start-scanner').disabled = false;
                document.getElementById('stop-scanner').disabled = true;
            }).catch(err => {
                console.error("Erreur:", err);
            });
        }
        
        // Traiter le QR code scanné
        function processQRCode(qrData) {
            // Afficher le loading
            document.getElementById('loading').style.display = 'block';
            
            // Validation basique
            if (!qrData || qrData.trim() === '') {
                document.getElementById('loading').style.display = 'none';
                showError('QR code vide ou invalide');
                return;
            }
            
            // Envoyer les données au serveur
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'check_student',
                    'qr_data': qrData,
                    'type_presence': selectedType,
                    'statut': selectedStatut
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau');
                }
                return response.json();
            })
            .then(data => {
                // Cacher le loading
                document.getElementById('loading').style.display = 'none';
                
                // Traiter la réponse
                if (data.success) {
                    showSuccess(data);
                    updateStudentInfo(data.student);
                    updateScanResult(data);
                    addToRecentHistory(data);
                } else {
                    showError(data.message);
                    // Afficher quand même les données du QR
                    displayQRData(qrData, false);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                document.getElementById('loading').style.display = 'none';
                showError('Erreur de connexion au serveur: ' + error.message);
            });
        }
        
        // Afficher un succès
        function showSuccess(data) {
            const student = data.student;
            const typeNames = {
                'entree_ecole': 'Entrée École',
                'sortie_ecole': 'Sortie École',
                'entree_classe': 'Entrée Classe',
                'sortie_classe': 'Sortie Classe'
            };
            
            const statutNames = {
                'present': 'Présent',
                'absent': 'Absent',
                'retard': 'Retard',
                'justifie': 'Justifié'
            };
            
            Swal.fire({
                icon: 'success',
                title: '✓ Présence enregistrée!',
                html: `
                    <div class="text-start">
                        <div class="mb-3">
                            <h6>Étudiant:</h6>
                            <p class="mb-1"><strong>${student.nom} ${student.prenom}</strong></p>
                            <p class="mb-1 text-muted small">Matricule: ${student.matricule}</p>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <h6>Type:</h6>
                                <p>${typeNames[data.type_presence]}</p>
                            </div>
                            <div class="col-6">
                                <h6>Statut:</h6>
                                <span class="badge bg-${data.statut === 'present' ? 'success' : data.statut === 'absent' ? 'danger' : data.statut === 'retard' ? 'warning' : 'info'}">
                                    ${statutNames[data.statut]}
                                </span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <h6>Heure:</h6>
                            <p>${data.timestamp}</p>
                        </div>
                    </div>
                `,
                confirmButtonText: 'OK',
                timer: 4000,
                timerProgressBar: true,
                showCloseButton: true
            });
        }
        
        // Afficher une erreur
        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Erreur',
                text: message,
                confirmButtonText: 'OK',
                showCloseButton: true
            });
        }
        
        // Afficher un toast
        function showToast(icon, title, text) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            
            Toast.fire({
                icon: icon,
                title: title,
                text: text
            });
        }
        
        // Mettre à jour les informations de l'étudiant
        function updateStudentInfo(student) {
            const container = document.getElementById('student-details');
            
            const statutClass = 'status-' + selectedStatut;
            const statutText = selectedStatut.charAt(0).toUpperCase() + selectedStatut.slice(1);
            
            // Formater la date de naissance
            const dateNaissance = student.date_naissance ? 
                new Date(student.date_naissance).toLocaleDateString('fr-FR') : 'Non renseignée';
            
            // Calculer l'âge si date de naissance disponible
            let age = '';
            if (student.date_naissance) {
                const birthDate = new Date(student.date_naissance);
                const today = new Date();
                age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
            }
            
            container.innerHTML = `
                <div class="student-info">
                    <div class="text-center mb-3">
                        <div class="mb-2">
                            <i class="fas fa-user-graduate fa-3x text-primary"></i>
                        </div>
                        <h5 class="mt-2 mb-1">${student.nom} ${student.prenom}</h5>
                        <div class="mb-2">
                            <span class="badge bg-primary">${student.matricule}</span>
                            <span class="presence-status ${statutClass} ms-2">${statutText}</span>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">CNI:</span>
                        <span class="info-value">${student.numero_cni || 'Non renseigné'}</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Date naissance:</span>
                        <span class="info-value">${dateNaissance} ${age ? `(${age} ans)` : ''}</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Lieu naissance:</span>
                        <span class="info-value">${student.lieu_naissance || 'Non renseigné'}</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Sexe:</span>
                        <span class="info-value">${student.sexe === 'M' ? 'Masculin' : 'Féminin'}</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Nationalité:</span>
                        <span class="info-value">${student.nationalite || 'Non renseignée'}</span>
                    </div>
                    
                    ${student.filiere ? `
                    <div class="info-row">
                        <span class="info-label">Filière:</span>
                        <span class="info-value">${student.filiere}</span>
                    </div>
                    ` : ''}
                    
                    ${student.niveau ? `
                    <div class="info-row">
                        <span class="info-label">Niveau:</span>
                        <span class="info-value">${student.niveau}</span>
                    </div>
                    ` : ''}
                    
                    <div class="info-row">
                        <span class="info-label">Inscrit depuis:</span>
                        <span class="info-value">${student.date_inscription || 'Non renseigné'}</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Présences aujourd'hui:</span>
                        <span class="info-value">${student.presences_aujourdhui || 0}</span>
                    </div>
                    
                    ${student.derniere_presence ? `
                    <div class="info-row">
                        <span class="info-label">Dernière présence:</span>
                        <span class="info-value">${student.derniere_presence} à ${student.derniere_heure ? student.derniere_heure.split(' ')[1] : ''}</span>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Mettre à jour le résultat du scan
        function updateScanResult(data) {
            const container = document.getElementById('scan-result');
            const student = data.student;
            
            const typeNames = {
                'entree_ecole': 'Entrée École',
                'sortie_ecole': 'Sortie École',
                'entree_classe': 'Entrée Classe',
                'sortie_classe': 'Sortie Classe'
            };
            
            const typeIcon = selectedType.includes('entree') ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
            const statutClass = 'status-' + data.statut;
            const statutText = data.statut.charAt(0).toUpperCase() + data.statut.slice(1);
            
            container.innerHTML = `
                <div class="result-container">
                    <h5><i class="fas fa-check-circle text-success me-2"></i>Présence enregistrée</h5>
                    
                    <div class="row mt-3">
                        <div class="col-4 text-center">
                            <div class="bg-light rounded p-3">
                                <i class="fas ${typeIcon} fa-2x text-primary"></i>
                                <div class="mt-2 small">${typeNames[data.type_presence]}</div>
                            </div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="bg-light rounded p-3">
                                <i class="fas fa-user-graduate fa-2x text-success"></i>
                                <div class="mt-2 small text-truncate" title="${student.nom} ${student.prenom}">
                                    ${student.nom} ${student.prenom}
                                </div>
                            </div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="bg-light rounded p-3">
                                <span class="presence-status ${statutClass}">${statutText}</span>
                                <div class="mt-2 small">Statut</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td><strong>Matricule:</strong></td>
                                <td>${student.matricule}</td>
                            </tr>
                            <tr>
                                <td><strong>Heure:</strong></td>
                                <td>${new Date().toLocaleTimeString('fr-FR')}</td>
                            </tr>
                            <tr>
                                <td><strong>Date:</strong></td>
                                <td>${new Date().toLocaleDateString('fr-FR')}</td>
                            </tr>
                            <tr>
                                <td><strong>ID Présence:</strong></td>
                                <td>#${data.presence_id}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            `;
        }
        
        // Afficher les données du QR code
        function displayQRData(qrData, isSuccess = true) {
            const container = document.getElementById('scan-result');
            
            if (!isSuccess) {
                container.innerHTML = `
                    <div class="result-container">
                        <h5><i class="fas fa-exclamation-triangle text-warning me-2"></i>QR Code scanné</h5>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            L'étudiant n'a pas pu être vérifié, mais voici les données du QR code:
                        </div>
                        <div class="qr-data-display">
                            ${qrData}
                        </div>
                        <p class="mt-2 text-muted small">
                            <i class="fas fa-clock me-1"></i>
                            Scanné le ${new Date().toLocaleString('fr-FR')}
                        </p>
                    </div>
                `;
            }
        }
        
        // Ajouter à l'historique récent (côté client)
        function addToRecentHistory(data) {
            const container = document.getElementById('recent-history');
            const student = data.student;
            
            const typeCategorie = data.type_presence.includes('entree') ? 'entree' : 'sortie';
            const typeClass = `type-${typeCategorie}`;
            const statusClass = `status-${data.statut}`;
            const statusText = data.statut.charAt(0).toUpperCase() + data.statut.slice(1);
            const time = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            
            const historyItem = `
                <div class="history-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>${student.nom} ${student.prenom}</strong>
                            <div class="small text-muted">
                                ${student.matricule}
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="type-indicator ${typeClass}">
                                ${data.type_presence}
                            </span>
                            <div class="small">
                                ${time}
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="presence-status ${statusClass}">
                            ${statusText}
                        </span>
                    </div>
                </div>
            `;
            
            // Ajouter au début de l'historique
            const existingContent = container.innerHTML;
            if (existingContent.includes('Aucune présence')) {
                container.innerHTML = historyItem;
            } else {
                container.innerHTML = historyItem + existingContent;
            }
            
            // Limiter à 10 items
            const items = container.querySelectorAll('.history-item');
            if (items.length > 10) {
                items[items.length - 1].remove();
            }
        }
        
        // Rafraîchir la page toutes les 2 minutes pour les statistiques
        setTimeout(() => {
            location.reload();
        }, 120000); // 2 minutes
    </script>
</body>
</html>