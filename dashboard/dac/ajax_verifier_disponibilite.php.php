<?php
session_start();
require_once '../../config/database.php';

$salle = $_GET['salle'] ?? '';
$date = $_GET['date'] ?? '';
$heure_debut = $_GET['heure_debut'] ?? '';
$heure_fin = $_GET['heure_fin'] ?? '';
$classe_id = $_GET['classe_id'] ?? 0;

try {
    $db = Database::getInstance()->getConnection();
    
    // Vérifier disponibilité salle
    $query = "SELECT COUNT(*) as count, m.nom as matiere_nom, c.nom as classe_nom
              FROM calendrier_examens ce
              JOIN matieres m ON ce.matiere_id = m.id
              JOIN classes c ON ce.classe_id = c.id
              WHERE ce.salle = :salle 
              AND ce.date_examen = :date_examen 
              AND ce.heure_debut < :heure_fin 
              AND ce.heure_fin > :heure_debut
              AND ce.statut != 'annule'";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        'salle' => $salle,
        'date_examen' => $date,
        'heure_debut' => $heure_debut,
        'heure_fin' => $heure_fin
    ]);
    $salle_occupee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Vérifier disponibilité classe
    $query = "SELECT COUNT(*) as count, m.nom as matiere_nom
              FROM calendrier_examens ce
              JOIN matieres m ON ce.matiere_id = m.id
              WHERE ce.classe_id = :classe_id 
              AND ce.date_examen = :date_examen 
              AND ce.heure_debut < :heure_fin 
              AND ce.heure_fin > :heure_debut
              AND ce.statut != 'annule'";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        'classe_id' => $classe_id,
        'date_examen' => $date,
        'heure_debut' => $heure_debut,
        'heure_fin' => $heure_fin
    ]);
    $classe_occupee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($salle_occupee['count'] > 0) {
        echo json_encode([
            'disponible' => false,
            'message' => `La salle ${salle} est déjà occupée par: ${salle_occupee['matiere_nom']} (${salle_occupee['classe_nom']})`
        ]);
    } elseif ($classe_occupee['count'] > 0) {
        echo json_encode([
            'disponible' => false,
            'message' => `La classe a déjà un examen: ${classe_occupee['matiere_nom']}`
        ]);
    } else {
        echo json_encode(['disponible' => true, 'message' => '']);
    }
    
} catch (Exception $e) {
    echo json_encode(['disponible' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
?>