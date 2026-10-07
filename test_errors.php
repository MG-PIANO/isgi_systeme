<?php
$output = file_get_contents('http://localhost/isgi_system/test_dashboard.php');
if (strpos($output, 'Erreur') !== false) {
    echo "Found 'Erreur' in output:\n";
    $lines = explode("\n", strip_tags($output));
    foreach($lines as $line) {
        if(stripos($line, 'Erreur') !== false) {
            echo trim($line) . "\n";
        }
    }
} else {
    echo "No errors found.\n";
}
?>
