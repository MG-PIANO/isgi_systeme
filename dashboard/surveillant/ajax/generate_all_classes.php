<?php
// dashboard/surveillant/ajax/generate_all_classes.php
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

// Récupérer toutes les classes actives
$query = "
    SELECT c.*, COUNT(e.id) as nb_etudiants
    FROM classes c
    LEFT JOIN etudiants e ON c.id = e.classe_id AND e.statut = 'actif'
    WHERE c.site_id = :site_id
    GROUP BY c.id
    HAVING nb_etudiants > 0
    ORDER BY c.nom
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
$total_generated = 0;

foreach ($classes as $classe) {
    // Récupérer les étudiants de cette classe
    $query_students = "
        SELECT e.* 
        FROM etudiants e
        WHERE e.classe_id = :classe_id 
          AND e.statut = 'actif'
          AND (e.qr_code_data IS NULL OR e.qr_code_data = '')
    ";
    
    $stmt_students = $db->prepare($query_students);
    $stmt_students->execute([':classe_id' => $classe['id']]);
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    
    $class_generated = 0;
    
    foreach ($students as $student) {
        try {
            // Générer le QR code
            $qr_data = "ETUDIANT:" . $student['matricule'] . "|" .
                      "NOM:" . $student['nom'] . "|" .
                      "PRENOM:" . $student['prenom'] . "|" .
                      "CLASSE:" . $classe['id'] . "|" .
                      "SITE:" . $site_id . "|" .
                      "TYPE:etudiant|" .
                      "DATE:" . date('YmdHis');
            
            $filename = 'etudiant_' . $student['matricule'] . '_' . time() . '.png';
            $filepath = dirname(dirname(dirname(__DIR__))) . '/uploads/qrcodes/' . $filename;
            
            QRcode::png($qr_data, $filepath, QR_ECLEVEL_H, 10, 2);
            
            // Mettre à jour la base
            $update_query = "UPDATE etudiants SET qr_code_data = :qr_data WHERE id = :id";
            $stmt_update = $db->prepare($update_query);
            $stmt_update->execute([
                ':qr_data' => $qr_data,
                ':id' => $student['id']
            ]);
            
            $class_generated++;
            $total_generated++;
            
        } catch (Exception $e) {
            // Ignorer les erreurs individuelles
        }
    }
    
    $results[] = [
        'classe' => $classe['nom'],
        'etudiants' => count($students),
        'generated' => $class_generated
    ];
}

// Créer un rapport HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport Génération QR Codes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0">
                    <i class="fas fa-check-circle me-2"></i>
                    Génération des QR Codes Terminée
                </h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h5>Résumé de la génération</h5>
                    <p class="mb-0">
                        QR codes générés: <strong><?php echo $total_generated; ?></strong><br>
                        Classes traitées: <strong><?php echo count($classes); ?></strong><br>
                        Date: <?php echo date('d/m/Y H:i:s'); ?>
                    </p>
                </div>
                
                <h5>Détail par classe:</h5>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Classe</th>
                                <th>Étudiants sans QR</th>
                                <th>QR codes générés</th>
                                <th>Taux</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($results as $result): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($result['classe']); ?></td>
                                <td><?php echo $result['etudiants']; ?></td>
                                <td><?php echo $result['generated']; ?></td>
                                <td>
                                    <?php 
                                    $rate = $result['etudiants'] > 0 ? 
                                        round(($result['generated'] / $result['etudiants']) * 100, 1) : 0;
                                    $color = $rate == 100 ? 'success' : ($rate > 50 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>">
                                        <?php echo $rate; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="text-center mt-4">
                    <a href="../generer_qr.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Retour au Générateur
                    </a>
                    <button class="btn btn-success ms-2" onclick="window.print()">
                        <i class="fas fa-print me-2"></i> Imprimer le Rapport
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$content = ob_get_clean();
echo $content;
?>