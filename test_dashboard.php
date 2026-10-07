<?php
define('ROOT_PATH', __DIR__);

// Fake session to allow dashboard to load
session_start();
$_SESSION['user_id'] = 2; // Suppose ID 2 is a surveillant
$_SESSION['role_id'] = 6;
$_SESSION['site_id'] = 1;
$_SESSION['role_name'] = 'surveillant';

// Load the dashboard but capture output
ob_start();
try {
    require 'dashboard/surveillant/dashboard.php';
} catch (Throwable $e) {
    echo "Fatal Error captured: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
$output = ob_get_clean();

echo "Dashboard length: " . strlen($output) . " chars\n";
if (preg_match('/(?:Fatal error|Parse error|Warning|Exception|Notice):/i', $output, $matches, PREG_OFFSET_CAPTURE)) {
    echo "Found PHP error in output at pos " . $matches[0][1] . "\n";
    echo substr($output, max(0, $matches[0][1] - 50), 300) . "\n";
} else {
    echo "No visible PHP errors.\n";
}
?>
