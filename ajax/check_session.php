<?php
// dashboard/ajax/check_session.php
session_start();

$response = [
    'valid' => false,
    'time_left' => 0
];

if (isset($_SESSION['last_activity'])) {
    $timeout = 24 * 3600; // 24 heures
    $timeLeft = $timeout - (time() - $_SESSION['last_activity']);
    
    if ($timeLeft > 0) {
        $response['valid'] = true;
        $response['time_left'] = $timeLeft;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>