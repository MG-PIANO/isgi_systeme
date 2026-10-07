<?php
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

echo "=== TABLES WITH 'calen' OR 'emploi' OR 'cours' OR 'annee' ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (preg_match('/(calen|emploi|cours|annee|plan|salle|seance|examen)/i', $t)) {
        echo "TABLE: $t\n";
        $cols = $db->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "   - {$c['Field']} ({$c['Type']})\n";
        }
    }
}
