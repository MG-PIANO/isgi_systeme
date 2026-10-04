<?php
// dashboard/dac/supprimer_note.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

require_once "../../includes/db.php";

// Récupérer l'ID de la note à supprimer
$note_id = $_GET['id'] ?? 0;
$note_id = intval($note_id);

if ($note_id > 0) {
    $stmt = $conn->prepare("DELETE FROM notes WHERE id=?");
    $stmt->bind_param("i", $note_id);

    if ($stmt->execute()) {
        // Suppression réussie
        header("Location: notes.php?action=list&success=Note+supprimée+avec+succès");
        exit;
    } else {
        header("Location: notes.php?action=list&error=Erreur+lors+de+la+suppression");
        exit;
    }
} else {
    header("Location: notes.php?action=list&error=ID+de+note+invalide");
    exit;
}