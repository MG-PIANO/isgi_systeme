<?php
// dashboard/surveillant/ajax/get_class_info.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode(null);
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$class_id = $_GET['id'] ?? 0;

$query = "
    SELECT 
        c.*,
        f.nom as filiere,
        n.libelle as niveau,
        COUNT(e.id) as effectif,
        SUM(CASE WHEN e.qr_code_data IS NOT NULL THEN 1 ELSE 0 END) as with_qr
    FROM classes c
    LEFT JOIN filieres f ON c.filiere_id = f.id
    LEFT JOIN niveaux n ON c.niveau_id = n.id
    LEFT JOIN etudiants e ON c.id = e.classe_id AND e.statut = 'actif'
    WHERE c.id = :id 
      AND c.site_id = :site_id
    GROUP BY c.id, f.nom, n.libelle
    LIMIT 1
";

$stmt = $db->prepare($query);
$stmt->execute([
    ':id' => $class_id,
    ':site_id' => $site_id
]);

$class = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($class);
?>