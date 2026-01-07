<?php
// dashboard/dac/cartes_etudiant.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

// Fonctions de formatage
function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }
    
    try {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        return date($format, $timestamp);
    } catch (Exception $e) {
        return '';
    }
}

function getStatusBadge($statut) {
    switch (strtolower($statut)) {
        case 'actif':
        case 'valide':
            return '<span class="badge bg-success">Actif</span>';
        case 'inactif':
        case 'en_attente':
            return '<span class="badge bg-warning">En attente</span>';
        case 'annule':
        case 'rejete':
            return '<span class="badge bg-danger">Annulé</span>';
        case 'diplome':
            return '<span class="badge bg-primary">Diplômé</span>';
        case 'abandonne':
            return '<span class="badge bg-danger">Abandonné</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $site_id = $_SESSION['site_id'] ?? null;
    
    $pageTitle = "Gestion des Cartes Étudiant";
    
    $action = $_GET['action'] ?? 'list';
    $etudiant_id = $_GET['etudiant_id'] ?? null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['generate_card'])) {
            $etudiant_id = $_POST['etudiant_id'];
            
            // Récupérer les infos de l'étudiant
            $stmt = $db->prepare("SELECT * FROM etudiants WHERE id = ?");
            $stmt->execute([$etudiant_id]);
            $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($etudiant) {
                // Vérifier si la bibliothèque QR Code existe
                $qrcode_path = ROOT_PATH . '/vendor/phpqrcode/qrlib.php';
                
                if (file_exists($qrcode_path)) {
                    require_once $qrcode_path;
                    
                    $qr_data = "ETUDIANT:" . $etudiant['matricule'] . "|NOM:" . $etudiant['nom'] . "|PRENOM:" . $etudiant['prenom'] . "|SITE:" . $site_id;
                    $qr_filename = 'qrcode_' . $etudiant['matricule'] . '.png';
                    $qr_path = ROOT_PATH . '/uploads/qrcodes/' . $qr_filename;
                    
                    if (!file_exists(dirname($qr_path))) {
                        mkdir(dirname($qr_path), 0777, true);
                    }
                    
                    // Générer le QR code
                    QRcode::png($qr_data, $qr_path, QR_ECLEVEL_L, 10);
                    
                    // Mettre à jour l'étudiant avec le QR code
                    $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
                    $stmt->execute([$qr_data, $etudiant_id]);
                    
                    $message = "Carte générée avec succès pour " . $etudiant['prenom'] . " " . $etudiant['nom'];
                    $message_type = "success";
                } else {
                    // Utiliser un service en ligne si la bibliothèque n'existe pas
                    $qr_data = "ETUDIANT:" . $etudiant['matricule'] . "|NOM:" . $etudiant['nom'] . "|PRENOM:" . $etudiant['prenom'] . "|SITE:" . $site_id;
                    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qr_data);
                    
                    $stmt = $db->prepare("UPDATE etudiants SET qr_code_data = ? WHERE id = ?");
                    $stmt->execute([$qr_data, $etudiant_id]);
                    
                    $message = "Carte générée (sans QR code local) pour " . $etudiant['prenom'] . " " . $etudiant['nom'];
                    $message_type = "warning";
                }
            }
        }
    }
    
    // Récupérer la liste des étudiants
    $query = "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, f.nom as filiere_nom, n.libelle as niveau_libelle 
              FROM etudiants e
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN inscriptions i ON e.id = i.etudiant_id
              LEFT JOIN filieres f ON i.filiere_id = f.id
              LEFT JOIN niveaux n ON i.niveau = n.code
              WHERE e.site_id = ? AND e.statut = 'actif'
              ORDER BY e.nom, e.prenom";
    $stmt = $db->prepare($query);
    $stmt->execute([$site_id]);
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI DAC</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
    .card-preview {
        border: 2px solid #007bff;
        border-radius: 10px;
        padding: 20px;
        max-width: 400px;
        margin: 20px auto;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .card-preview::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 200%;
        background: rgba(255,255,255,0.1);
        transform: rotate(30deg);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(255,255,255,0.2);
    }
    
    .card-logo {
        font-size: 24px;
        font-weight: bold;
        color: white;
    }
    
    .student-photo {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        border: 3px solid white;
        margin: 0 auto 15px;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #333;
        overflow: hidden;
    }
    
    .student-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .qr-code {
        width: 120px;
        height: 120px;
        background: white;
        padding: 5px;
        margin: 10px auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .qr-code img {
        max-width: 100%;
        max-height: 100%;
    }
    
    .batch-options {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .card-actions {
        margin-top: 20px;
        display: flex;
        gap: 10px;
        justify-content: center;
    }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap"></i> ISGI - DAC
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="etudiants.php">
                            <i class="fas fa-user-graduate"></i> Étudiants
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="cartes_etudiant.php">
                            <i class="fas fa-id-card"></i> Cartes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presences.php">
                            <i class="fas fa-calendar-check"></i> Présences
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo ROOT_PATH; ?>/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Déconnexion
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-3">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-none d-md-block">
                <div class="list-group">
                    <a href="dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="etudiants.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-user-graduate me-2"></i>Étudiants
                    </a>
                    <a href="cartes_etudiant.php" class="list-group-item list-group-item-action active">
                        <i class="fas fa-id-card me-2"></i>Cartes étudiant
                    </a>
                    <a href="presences.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-calendar-check me-2"></i>Présences
                    </a>
                    <a href="calendrier_academique.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-calendar me-2"></i>Calendrier
                    </a>
                    <a href="notes.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-alt me-2"></i>Notes
                    </a>
                </div>
            </div>
            
            <!-- Contenu principal -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-id-card"></i> Gestion des Cartes Étudiant
                    </h1>
                </div>
                
                <?php if(isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if(isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Liste des Étudiants</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Matricule</th>
                                                <th>Nom & Prénom</th>
                                                <th>Filière</th>
                                                <th>Niveau</th>
                                                <th>Statut Carte</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($etudiants as $etudiant): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($etudiant['matricule']); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'Non assigné'); ?></td>
                                                <td><?php echo htmlspecialchars($etudiant['niveau_libelle'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if(!empty($etudiant['qr_code_data'])): ?>
                                                    <span class="badge bg-success">Générée</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-warning">À générer</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button onclick="previewCard(<?php echo htmlspecialchars(json_encode($etudiant)); ?>)" 
                                                            class="btn btn-sm btn-info" title="Prévisualiser">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                                        <button type="submit" name="generate_card" class="btn btn-sm btn-success" title="Générer">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                    </form>
                                                    <?php if(!empty($etudiant['qr_code_data'])): ?>
                                                    <a href="download_card.php?id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn btn-sm btn-primary" title="Télécharger">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Génération par Lot</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="batch_generate.php">
                                    <div class="mb-3">
                                        <label class="form-label">Sélectionner les étudiants</label>
                                        <select multiple class="form-select" name="etudiant_ids[]" size="5" style="height: 150px;">
                                            <?php foreach($etudiants as $etudiant): ?>
                                            <option value="<?php echo $etudiant['id']; ?>">
                                                <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Format d'impression</label>
                                        <select class="form-select" name="print_format">
                                            <option value="a4">A4 (6 cartes par page)</option>
                                            <option value="a4_8">A4 (8 cartes par page)</option>
                                            <option value="single">Carte individuelle</option>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" name="generate_batch" class="btn btn-primary w-100">
                                        <i class="fas fa-bolt"></i> Générer en Lot
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">Prévisualisation Carte</h5>
                            </div>
                            <div class="card-body text-center">
                                <div id="cardPreview" class="card-preview">
                                    <div class="card-header">
                                        <div class="card-logo">ISGI</div>
                                        <div class="card-year" style="color: white;">2025-2026</div>
                                    </div>
                                    
                                    <div class="student-photo">
                                        <i class="fas fa-user fa-3x"></i>
                                    </div>
                                    
                                    <h4 id="previewName" class="text-center mb-2" style="color: white;">Prénom NOM</h4>
                                    <p id="previewMatricule" class="text-center mb-1" style="color: rgba(255,255,255,0.8);">Matricule: XXXXXX</p>
                                    <p id="previewFiliere" class="text-center mb-3" style="color: rgba(255,255,255,0.8);">Filière - Niveau</p>
                                    
                                    <div class="qr-code">
                                        <img id="previewQr" src="" alt="QR Code" class="img-fluid">
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-6 text-start">
                                            <small style="color: rgba(255,255,255,0.8);">Date d'émission: <?php echo date('d/m/Y'); ?></small>
                                        </div>
                                        <div class="col-6 text-end">
                                            <small style="color: rgba(255,255,255,0.8);">Signature</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-actions">
                                    <button onclick="printCard()" class="btn btn-success">
                                        <i class="fas fa-print"></i> Imprimer
                                    </button>
                                    <button onclick="downloadCard()" class="btn btn-primary">
                                        <i class="fas fa-download"></i> Télécharger
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script>
    function previewCard(etudiant) {
        document.getElementById('previewName').textContent = etudiant.prenom + ' ' + etudiant.nom;
        document.getElementById('previewMatricule').textContent = 'Matricule: ' + etudiant.matricule;
        document.getElementById('previewFiliere').textContent = (etudiant.filiere_nom || 'Non assigné') + ' - ' + (etudiant.niveau_libelle || 'N/A');
        
        // Générer un QR code de prévisualisation
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=${encodeURIComponent(etudiant.matricule)}`;
        document.getElementById('previewQr').src = qrUrl;
    }
    
    function printCard() {
        const cardElement = document.getElementById('cardPreview');
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Impression Carte Étudiant</title>
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        margin: 0;
                        padding: 20px;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                    }
                    .card-preview { 
                        border: 2px solid #007bff;
                        border-radius: 10px;
                        padding: 20px;
                        max-width: 400px;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                    }
                    .student-photo {
                        width: 100px;
                        height: 100px;
                        border-radius: 50%;
                        border: 3px solid white;
                        margin: 0 auto 15px;
                        background: #f8f9fa;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #333;
                    }
                    .qr-code {
                        width: 120px;
                        height: 120px;
                        background: white;
                        padding: 5px;
                        margin: 10px auto;
                    }
                    .qr-code img {
                        max-width: 100%;
                        max-height: 100%;
                    }
                </style>
            </head>
            <body>
                ${cardElement.outerHTML}
                <script>
                    window.onload = function() { 
                        window.print(); 
                        setTimeout(function() { window.close(); }, 1000);
                    }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    function downloadCard() {
        // Générer un PDF de la carte
        const studentName = document.getElementById('previewName').textContent;
        const matricule = document.getElementById('previewMatricule').textContent.replace('Matricule: ', '');
        
        // Créer un formulaire pour télécharger le PDF
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'download_card.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'download_card';
        input.value = 'true';
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    // Prévisualisation par défaut
    document.addEventListener('DOMContentLoaded', function() {
        <?php if(!empty($etudiants)): ?>
        previewCard(<?php echo json_encode($etudiants[0]); ?>);
        <?php endif; ?>
    });
    
    // Sélection multiple avec Ctrl/Cmd
    document.querySelector('select[name="etudiant_ids[]"]').addEventListener('mousedown', function(e) {
        e.preventDefault();
        
        const option = e.target;
        if (option.tagName === 'OPTION') {
            option.selected = !option.selected;
        }
    });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>