<?php
// verify_matricule.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
$config = [
    'db_host' => 'localhost',
    'db_name' => 'isgi_systeme',
    'db_user' => 'root',
    'db_pass' => 'admin1234'
];

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Récupérer le matricule
    $matricule = isset($_POST['matricule']) ? trim($_POST['matricule']) : '';
    
    if (empty($matricule)) {
        echo json_encode(['success' => false, 'message' => 'Matricule requis']);
        exit();
    }
    
    // Vérifier le matricule dans la base
    $stmt = $pdo->prepare("
        SELECT e.*, s.nom as site_nom
        FROM etudiants e
        LEFT JOIN sites s ON e.site_id = s.id
        WHERE e.matricule = ? AND e.statut = 'actif'
    ");
    $stmt->execute([$matricule]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Matricule non trouvé ou étudiant inactif']);
        exit();
    }
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Vérifier si un compte existe déjà
    if ($student['utilisateur_id'] !== null) {
        echo json_encode(['success' => false, 'message' => 'Un compte existe déjà pour cet étudiant']);
        exit();
    }
    
    echo json_encode([
        'success' => true,
        'student_info' => [
            'id' => $student['id'],
            'nom' => $student['nom'],
            'prenom' => $student['prenom'],
            'site_id' => $student['site_id'],
            'statut' => $student['statut']
        ],
        'site_nom' => $student['site_nom']
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>