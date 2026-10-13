<?php
session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$db = Database::getInstance()->getConnection();

try {
    if ($q === '') {
        echo json_encode(['success' => true, 'students' => []]);
        exit;
    }

    $search = "%{$q}%";

    $stmt = $db->prepare("
        SELECT
            e.id,
            e.matricule,
            e.nom,
            e.prenom,
            e.email,
            e.telephone,
            c.nom AS classe
        FROM etudiants e
        LEFT JOIN classes c ON c.id = e.classe_id
        WHERE e.statut IS NULL OR e.statut != 'supprime'
          AND (
              e.matricule LIKE :q
              OR e.nom LIKE :q
              OR e.prenom LIKE :q
              OR e.email LIKE :q
          )
        ORDER BY e.nom ASC, e.prenom ASC
        LIMIT 20
    ");

    $stmt->bindParam(':q', $search, PDO::PARAM_STR);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $ex) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de recherche',
        'error' => $ex->getMessage()
    ]);
}