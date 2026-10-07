<?php
$c = file_get_contents('dashboard/surveillant/dashboard.php');
$o = substr_count($c, '<div');
$e = substr_count($c, '</div');
echo "divs opened: $o, closed: $e\n";
?>
