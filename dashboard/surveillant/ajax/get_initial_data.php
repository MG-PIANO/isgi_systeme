<?php
session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    $sql = "
        SELECT
            e.id,
            e.matricule,
            e.nom,
            e.prenom,
            e.email,
            e.telephone,
            e.photo,
            e.statut,
            c.nom AS classe
        FROM etudiants e
        LEFT JOIN classes c ON c.id = e.classe_id
        WHERE e.statut IS NULL OR e.statut != 'supprime'
        ORDER BY e.nom ASC, e.prenom ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $ex) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de chargement des étudiants',
        'error' => $ex->getMessage()
    ]);
}