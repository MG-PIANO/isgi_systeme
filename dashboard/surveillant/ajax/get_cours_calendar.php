<?php
// dashboard/surveillant/ajax/get_cours_calendar.php

define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));
require_once ROOT_PATH . '/config/database.php';

session_start();
$db = Database::getInstance()->getConnection();
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

if ($site_id === 0) {
    die(json_encode([]));
}

try {
    $query = "SELECT 
                edt.id,
                CONCAT(c.nom, ' - ', m.nom) as title,
                edt.jour_semaine,
                edt.heure_debut,
                edt.heure_fin,
                edt.salle,
                CONCAT('Salle: ', edt.salle, '\\nClasse: ', c.nom, '\\nMatière: ', m.nom) as description
              FROM emploi_du_temps edt
              LEFT JOIN classes c ON edt.classe_id = c.id
              LEFT JOIN matieres m ON edt.matiere_id = m.id
              WHERE edt.site_id = :site_id
                AND edt.annee_academique_id IN (
                  SELECT id FROM annees_academiques WHERE statut = 'active'
                )";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':site_id' => $site_id]);
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $events = [];
    
    foreach ($cours as $c) {
        // Déterminer la prochaine date pour ce jour de la semaine
        $days = [
            'Lundi' => 1,
            'Mardi' => 2,
            'Mercredi' => 3,
            'Jeudi' => 4,
            'Vendredi' => 5,
            'Samedi' => 6
        ];
        
        $dayOfWeek = $days[$c['jour_semaine']] ?? 1;
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        // Trouver le prochain jour correspondant
        $daysToAdd = ($dayOfWeek - $today->format('N') + 7) % 7;
        if ($daysToAdd === 0) {
            $daysToAdd = 7; // Prochaine semaine
        }
        
        $date = clone $today;
        $date->modify("+{$daysToAdd} days");
        
        $start = $date->format('Y-m-d') . 'T' . $c['heure_debut'];
        $end = $date->format('Y-m-d') . 'T' . $c['heure_fin'];
        
        $events[] = [
            'id' => $c['id'],
            'title' => $c['title'],
            'start' => $start,
            'end' => $end,
            'description' => $c['description'],
            'color' => '#3498db',
            'textColor' => '#ffffff'
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($events);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
}