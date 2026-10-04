<?php
// dashboard/dac/liste_notes.php

session_start();
include "../../includes/header.php";
include "../../includes/db.php";

// Récupérer les notes avec toutes les informations
$query = "
    SELECT 
        n.id AS note_id,
        n.note,
        n.coefficient_note,
        n.date_evaluation,
        n.statut,
        e.matricule,
        e.nom AS etudiant_nom,
        e.prenom AS etudiant_prenom,
        m.code AS matiere_code,
        m.nom AS matiere_nom,
        te.nom AS type_examen,
        s.numero AS semestre_numero,
        aa.libelle AS annee_libelle,
        CONCAT(u.nom, ' ', u.prenom) AS evaluateur_nom
    FROM notes n
    JOIN etudiants e ON n.etudiant_id = e.id
    JOIN matieres m ON n.matiere_id = m.id
    JOIN types_examens te ON n.type_examen_id = te.id
    JOIN semestres s ON n.semestre_id = s.id
    JOIN annees_academiques aa ON n.annee_academique_id = aa.id
    JOIN utilisateurs u ON n.evaluateur_id = u.id
    ORDER BY n.date_evaluation DESC, e.nom, e.prenom
";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Liste des notes</title>
    <link rel="stylesheet" href="../../css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(270deg, #bad2f7ff, #c8ebf4ff);
            background-size: 200% 200%;
            animation: moveBG 10s ease infinite;
        }

        @keyframes moveBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        h3 {
            font-weight: 600;
            color: #0066cc;
            text-align: center;
            margin-bottom: 2rem;
            animation: fadeInDown 1s ease-in-out;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .table {
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .table thead {
            background: linear-gradient(90deg, #0066ff, #00ccff);
            color: #fff;
        }

        .table tbody tr {
            transition: background 0.3s ease;
        }

        .table tbody tr:hover {
            background: #e3f2fd;
        }

        .btn {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        /* Espacement des icônes Font Awesome */
        .btn i {
            margin-right: 6px;
            font-size: 1rem;
            vertical-align: middle;
        }

        /* Palette cohérente */
        .btn-success {
            background: #28a745;
            border: none;
        }
        
        .btn-success:hover {
            background: #218838;
        }

        .btn-info {
            background: #17a2b8;
            border: none;
        }
        
        .btn-info:hover {
            background: #117a8b;
        }

        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,102,255,0.4);
        }

        .btn-danger:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(220,53,69,0.4);
        }

        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Badges pour les notes */
        .badge-note {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .note-excellente { background-color: #d4edda; color: #155724; }
        .note-bonne { background-color: #c3e6cb; color: #155724; }
        .note-moyenne { background-color: #fff3cd; color: #856404; }
        .note-faible { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2><i class="fa-solid fa-list"></i> Liste des notes</h2>
    <a href="notes.php?action=ajouter" class="btn btn-success mb-3">
        <i class="fa-solid fa-plus"></i> Ajouter une note
    </a>
    <a href="notes.php?action=statistiques" class="btn btn-info mb-3">
        <i class="fa-solid fa-chart-bar"></i> Statistiques
    </a>
    <a href="dashboard.php" class="btn btn-secondary mb-3">
        <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
    </a>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th><i class="fa-solid fa-user"></i> Étudiant</th>
                    <th><i class="fa-solid fa-book"></i> Matière</th>
                    <th><i class="fa-solid fa-clipboard-check"></i> Type Examen</th>
                    <th><i class="fa-solid fa-star"></i> Note</th>
                    <th><i class="fa-solid fa-check"></i> Statut</th>
                    <th><i class="fa-solid fa-award"></i> Mention</th>
                    <th><i class="fa-solid fa-calendar"></i> Date</th>
                    <th><i class="fa-solid fa-gears"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                    $note = floatval($row['note']);
                    $statut = getStatutNote($note);
                    $mention = getMention($note);
                    
                    // Déterminer la classe CSS pour la note
                    $note_class = '';
                    if ($note >= 16) $note_class = 'note-excellente';
                    elseif ($note >= 14) $note_class = 'note-bonne';
                    elseif ($note >= 10) $note_class = 'note-moyenne';
                    else $note_class = 'note-faible';
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['etudiant_prenom'] . ' ' . $row['etudiant_nom']) ?></strong><br>
                            <small class="text-muted"><?= htmlspecialchars($row['matricule']) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['matiere_code']) ?><br>
                            <small class="text-muted"><?= htmlspecialchars($row['matiere_nom']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($row['type_examen']) ?></td>
                        <td>
                            <span class="badge-note <?= $note_class ?>">
                                <?= number_format($note, 2); ?>/20
                            </span>
                        </td>
                        <td>
                            <?php if($statut == 'Valide'): ?>
                                <span class="badge bg-success"><?= $statut ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= $statut ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($mention == 'Échoué'): ?>
                                <span class="badge bg-danger"><?= $mention ?></span>
                            <?php elseif($mention == 'Très Bien'): ?>
                                <span class="badge bg-success"><?= $mention ?></span>
                            <?php else: ?>
                                <span class="badge bg-info"><?= $mention ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= date('d/m/Y', strtotime($row['date_evaluation'])) ?><br>
                            <small class="text-muted">S<?= $row['semestre_numero'] ?> - <?= htmlspecialchars($row['annee_libelle']) ?></small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="modifier_note.php?id=<?= $row['note_id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </a>
                                <a href="supprimer_note.php?id=<?= $row['note_id'] ?>" 
                                   class="btn btn-danger btn-sm delete-btn"
                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette note ?')">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Fonctions utilitaires (définies ici car inclus dans le fichier) -->
    <?php
    function getMention($note) {
        if ($note >= 16) return "Très Bien";
        elseif ($note >= 14) return "Bien";
        elseif ($note >= 12) return "Assez Bien";
        elseif ($note >= 10) return "Passable";
        else return "Échoué";
    }
    
    function getStatutNote($note) {
        return ($note >= 10) ? "Valide" : "Non Valide";
    }
    ?>
</div>

<?php include "../../includes/footer.php" ?>