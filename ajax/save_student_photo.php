<?php
/**
 * ISGI - Enregistrement de photo d'étudiant (depuis Smartphone ou Web)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/photos/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Récupération des données POST ou JSON
$input = json_decode(file_get_contents('php://input'), true);
$matricule = $_POST['matricule'] ?? $input['matricule'] ?? '';
$session_id = $_POST['session_id'] ?? $input['session_id'] ?? '';
$imageBase64 = $_POST['image'] ?? $input['image'] ?? '';

if (empty($matricule)) {
    echo json_encode(['success' => false, 'error' => 'Matricule requis']);
    exit;
}

$cleanMatricule = preg_replace('/[^a-zA-Z0-9_-]/', '_', $matricule);
$targetFile = $uploadDir . $cleanMatricule . '.jpg';
$publicUrl = 'uploads/photos/' . $cleanMatricule . '.jpg';

$imageData = null;

// Cas 1 : Envoi en Base64
if (!empty($imageBase64)) {
    if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64, $type)) {
        $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
    }
    $imageData = base64_decode($imageBase64);
}
// Cas 2 : Envoi fichier multipart
elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $imageData = file_get_contents($_FILES['photo']['tmp_name']);
}

if (!$imageData) {
    echo json_encode(['success' => false, 'error' => 'Aucune image reçue']);
    exit;
}

// Sauvegarde du fichier image
if (file_put_contents($targetFile, $imageData) === false) {
    echo json_encode(['success' => false, 'error' => 'Impossible d\'écrire le fichier sur le disque']);
    exit;
}

// Notification de session pour synchronisation instantanée avec le PC de l'informaticien
if (!empty($session_id)) {
    $cleanSession = preg_replace('/[^a-zA-Z0-9_-]/', '', $session_id);
    $sessionFile = $uploadDir . 'session_' . $cleanSession . '.json';
    $sessionData = [
        'ready' => true,
        'matricule' => $matricule,
        'photo_url' => $publicUrl,
        'base64' => 'data:image/jpeg;base64,' . base64_encode($imageData),
        'timestamp' => time()
    ];
    file_put_contents($sessionFile, json_encode($sessionData));
}

// Mise à jour MySQL si disponible
try {
    $pdo = new PDO("mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4", "root", "admin1234", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
    ]);
    $stmt = $pdo->prepare("UPDATE etudiants SET photo = :photo WHERE matricule = :mat");
    $stmt->execute([':photo' => $publicUrl, ':mat' => $matricule]);
    
    // Mettre à jour aussi dans pointages_acces si la table existe
    $stmt2 = $pdo->prepare("UPDATE pointages_acces SET photo_url = :photo WHERE matricule = :mat");
    $stmt2->execute([':photo' => $publicUrl, ':mat' => $matricule]);
} catch (Exception $e) {
    // Ignorer si MySQL non connecté
}

echo json_encode([
    'success' => true,
    'matricule' => $matricule,
    'photo_url' => $publicUrl,
    'full_url' => 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/isgi_system/' . $publicUrl,
    'base64' => 'data:image/jpeg;base64,' . base64_encode($imageData)
]);
