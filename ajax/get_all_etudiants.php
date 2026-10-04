<?php
/**
 * ISGI - Récupération de tous les étudiants pour la génération de badges et le studio mobile
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$etudiants = [];

try {
    $pdo = new PDO("mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4", "root", "admin1234", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Check if etudiants table exists
    $stmt = $pdo->query("SELECT * FROM etudiants ORDER BY nom ASC, prenom ASC");
    if ($stmt) {
        $rows = $stmt->fetchAll();
        $uploadDir = __DIR__ . '/../uploads/photos/';

        foreach ($rows as $r) {
            $mat = $r['matricule'] ?? $r['numero_isgi'] ?? ('ISGI-' . ($r['id'] ?? ''));
            $cleanMat = preg_replace('/[^a-zA-Z0-9_-]/', '_', $mat);
            
            // Check if local photo exists in uploads/photos/
            $photoPath = 'uploads/photos/' . $cleanMat . '.jpg';
            $hasLocalPhoto = file_exists(__DIR__ . '/../' . $photoPath);

            $etudiants[] = [
                'id' => (string)($r['id'] ?? $mat),
                'nom' => strtoupper($r['nom'] ?? $r['last_name'] ?? ''),
                'prenom' => $r['prenom'] ?? $r['first_name'] ?? '',
                'matricule' => $mat,
                'filiere' => $r['filiere'] ?? $r['specialite'] ?? '',
                'niveau' => $r['niveau'] ?? $r['annee_etude'] ?? 'Licence 1',
                'lieu' => $r['lieu_naissance'] ?? $r['lieu'] ?? 'Brazzaville',
                'photo' => $hasLocalPhoto ? $photoPath : ($r['photo'] ?? $r['photo_url'] ?? null),
                'has_photo' => $hasLocalPhoto || !empty($r['photo']) || !empty($r['photo_url'])
            ];
        }
    }
} catch (Exception $e) {
    // MySQL error, will return empty array or fallback
}

echo json_encode([
    'success' => true,
    'total' => count($etudiants),
    'etudiants' => $etudiants
]);
