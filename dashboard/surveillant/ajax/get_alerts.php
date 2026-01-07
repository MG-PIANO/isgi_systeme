<?php
// dashboard/surveillant/ajax/get_alerts.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$db = Database::getInstance()->getConnection();
$site_id = $_SESSION['site_id'];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$alerts = [];

// Alertes pour les absences prolongées (3 jours consécutifs)
$query = "
    SELECT e.id, e.matricule, e.nom, e.prenom, 
           COUNT(DISTINCT DATE(p.date_heure)) as jours_absents
    FROM etudiants e
    LEFT JOIN presences p ON e.id = p.etudiant_id 
        AND p.type_presence IN ('entree_ecole', 'entree_classe')
        AND p.date_heure >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        AND p.statut = 'absent'
    WHERE e.site_id = :site_id 
      AND e.statut = 'actif'
    GROUP BY e.id
    HAVING jours_absents >= 3
    LIMIT 10
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id]);
$absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($absences as $absence) {
    $alerts[] = [
        'type' => 'warning',
        'message' => "{$absence['nom']} {$absence['prenom']} ({$absence['matricule']}) - Absent depuis {$absence['jours_absents']} jours"
    ];
}

// Alertes pour les retards fréquents
$query = "
    SELECT e.id, e.matricule, e.nom, e.prenom, 
           COUNT(*) as nb_retards
    FROM etudiants e
    JOIN presences p ON e.id = p.etudiant_id 
        AND p.statut = 'retard'
        AND p.date_heure >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    WHERE e.site_id = :site_id
    GROUP BY e.id
    HAVING nb_retards >= 3
    LIMIT 10
";

$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $site_id]);
$retards = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($retards as $retard) {
    $alerts[] = [
        'type' => 'info',
        'message' => "{$retard['nom']} {$retard['prenom']} ({$retard['matricule']}) - {$retard['nb_retards']} retards cette semaine"
    ];
}

header('Content-Type: application/json');
echo json_encode($alerts);
?>