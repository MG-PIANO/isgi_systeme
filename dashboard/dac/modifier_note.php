<?php
// dashboard/dac/modifier_note.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}
include "../../includes/header.php";
require_once __DIR__ . "/../../includes/db.php";

$success = "";
$error = "";

// Récupérer la note
$note_id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM notes WHERE id=?");
$stmt->bind_param("i", $note_id);
$stmt->execute();
$result = $stmt->get_result();
$note = $result->fetch_assoc();

if (!$note) {
    die("Note introuvable !");
}

// Récupérer les listes
$etudiants = $conn->query("SELECT id, matricule, nom, prenom FROM etudiants WHERE statut = 'actif' ORDER BY nom");
$matieres = $conn->query("SELECT id, code, nom FROM matieres ORDER BY code");
$types_examens = $conn->query("SELECT id, nom FROM types_examens ORDER BY ordre");
$semestres = $conn->query("SELECT s.id, s.numero, aa.libelle FROM semestres s JOIN annees_academiques aa ON s.annee_academique_id = aa.id ORDER BY s.numero DESC");
$annees_academiques = $conn->query("SELECT id, libelle FROM annees_academiques ORDER BY date_debut DESC");

// Calcul statut + mention
function calculStatutMention($note_val) {
    if ($note_val >= 10) {
        $statut = "Valide";
        if ($note_val >= 16) $mention = "Très Bien";
        elseif ($note_val >= 14) $mention = "Bien";
        elseif ($note_val >= 12) $mention = "Assez Bien";
        else $mention = "Passable";
    } else {
        $statut = "Non Valide";
        $mention = "Échoué";
    }
    return [$statut, $mention];
}

list($statut, $mention) = calculStatutMention($note['note']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $etudiant_id = intval($_POST['etudiant_id']);
    $matiere_id = intval($_POST['matiere_id']);
    $type_examen_id = intval($_POST['type_examen_id']);
    $note_val = floatval($_POST['note']);
    $semestre_id = intval($_POST['semestre_id']);
    $annee_academique_id = intval($_POST['annee_academique_id']);

    list($statut, $mention) = calculStatutMention($note_val);

    $stmt_update = $conn->prepare(
        "UPDATE notes SET etudiant_id=?, matiere_id=?, type_examen_id=?, note=?, semestre_id=?, annee_academique_id=? WHERE id=?"
    );
    $stmt_update->bind_param("iiidiii", $etudiant_id, $matiere_id, $type_examen_id, $note_val, $semestre_id, $annee_academique_id, $note_id);

    if ($stmt_update->execute()) {
        $success = "Note modifiée avec succès.";
        $note['etudiant_id'] = $etudiant_id;
        $note['matiere_id'] = $matiere_id;
        $note['type_examen_id'] = $type_examen_id;
        $note['note'] = $note_val;
        $note['semestre_id'] = $semestre_id;
        $note['annee_academique_id'] = $annee_academique_id;
    } else {
        $error = "Erreur SQL : " . $stmt_update->error;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Modifier la note</title>
    <link rel="stylesheet" href="../../css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(270deg, #bad2f7ff, #c8ebf4ff);
            min-height: 100vh;
        }

        .container {
            max-width: 600px;
            background: #a8d6f2ff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .form-wrapper {
            min-height: calc(100vh - 120px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        h2 {
            font-weight: 700;
            color: #0066ff;
            margin-bottom: 20px;
        }
        
        .form-control, textarea {
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, textarea:focus {
            border-color: #0066ff;
            box-shadow: 0 0 8px rgba(0,102,255,0.3);
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .btn-primary {
            background: #0066ff;
            border: none;
        }
        
        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,102,255,0.4);
        }
        
        .btn-secondary {
            border-radius: 8px;
        }

        .btn-danger {
            background: #800303ff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .btn-danger:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(220,53,69,0.4);
        }
        
        .alert {
            border-radius: 8px;
            animation: fadeIn 0.6s ease;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>
<div class="form-wrapper">
    <div class="container">
        <h3><i class="fa-solid fa-pen-to-square"></i> Modifier la note</h3>
        <a href="notes.php?action=list" class="btn btn-secondary mb-3">
            <i class="fa-solid fa-arrow-left"></i> Retour à la liste des notes
        </a>

        <?php if($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label><i class="fa-solid fa-user"></i> Étudiant</label>
                <select name="etudiant_id" class="form-select" required>
                    <?php while($et = $etudiants->fetch_assoc()): ?>
                        <option value="<?= $et['id'] ?>" <?= ($et['id']==$note['etudiant_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($et['prenom'] . ' ' . $et['nom'] . ' - ' . $et['matricule']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label><i class="fa-solid fa-book"></i> Matière</label>
                <select name="matiere_id" class="form-select" required>
                    <?php while($m = $matieres->fetch_assoc()): ?>
                        <option value="<?= $m['id'] ?>" <?= ($m['id']==$note['matiere_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($m['code'] . ' - ' . $m['nom']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label><i class="fa-solid fa-clipboard-check"></i> Type d'examen</label>
                <select name="type_examen_id" class="form-select" required>
                    <?php while($t = $types_examens->fetch_assoc()): ?>
                        <option value="<?= $t['id'] ?>" <?= ($t['id']==$note['type_examen_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($t['nom']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label><i class="fa-solid fa-star"></i> Note</label>
                <input type="number" step="0.01" name="note" min="0" max="20" 
                       class="form-control" value="<?= htmlspecialchars($note['note']) ?>" required>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label><i class="fa-solid fa-calendar"></i> Semestre</label>
                    <select name="semestre_id" class="form-select" required>
                        <?php while($s = $semestres->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>" <?= ($s['id']==$note['semestre_id'])?'selected':'' ?>>
                                Semestre <?= $s['numero'] ?> (<?= htmlspecialchars($s['libelle']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-6 mb-3">
                    <label><i class="fa-solid fa-calendar-days"></i> Année académique</label>
                    <select name="annee_academique_id" class="form-select" required>
                        <?php while($a = $annees_academiques->fetch_assoc()): ?>
                            <option value="<?= $a['id'] ?>" <?= ($a['id']==$note['annee_academique_id'])?'selected':'' ?>>
                                <?= htmlspecialchars($a['libelle']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary w-50">
                    <i class="fa-solid fa-save"></i> Enregistrer
                </button>
                <button type="reset" class="btn btn-danger w-50">
                    <i class="fa-solid fa-trash"></i> Réinitialiser
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Animation subtile au focus des champs
document.querySelectorAll(".form-control, .form-select").forEach(input => {
    input.addEventListener("focus", () => {
        input.style.backgroundColor = "#f0f8ff";
    });
    input.addEventListener("blur", () => {
        input.style.backgroundColor = "";
    });
});

document.addEventListener("DOMContentLoaded", () => {
    const resetBtn = document.querySelector("button[type='reset']");
    const form = document.querySelector("form");

    resetBtn.addEventListener("click", () => {
        form.reset();
        resetBtn.innerHTML = "<i class='fa-solid fa-check'></i> Formulaire réinitialisé";
        resetBtn.style.backgroundColor = "#28a745";
        setTimeout(() => {
            resetBtn.innerHTML = "<i class='fa-solid fa-trash'></i> Réinitialiser";
            resetBtn.style.backgroundColor = "#dc3545";
        }, 1500);
    });
});

document.addEventListener("DOMContentLoaded", () => {
    const formElements = document.querySelectorAll(".card-body .mb-3, .btn");
    formElements.forEach((el, index) => {
        el.style.opacity = 0;
        el.style.transform = "translateY(20px)";
        setTimeout(() => {
            el.style.transition = "all 0.6s ease";
            el.style.opacity = 1;
            el.style.transform = "translateY(0)";
        }, index * 200);
    });
});
</script>

<?php include "../../includes/footer.php" ?>
</body>
</html>