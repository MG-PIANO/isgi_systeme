<?php
/**
 * ISGI - Vérification d'état de session photo pour synchronisation PC <-> Smartphone
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$session_id = $_GET['session_id'] ?? '';
if (empty($session_id)) {
    echo json_encode(['ready' => false]);
    exit;
}

$cleanSession = preg_replace('/[^a-zA-Z0-9_-]/', '', $session_id);
$sessionFile = __DIR__ . '/../uploads/photos/session_' . $cleanSession . '.json';

if (file_exists($sessionFile)) {
    $content = file_get_contents($sessionFile);
    $data = json_decode($content, true);
    // Supprimer le fichier de session après lecture pour éviter la répétition
    @unlink($sessionFile);
    echo json_encode($data);
} else {
    echo json_encode(['ready' => false]);
}
