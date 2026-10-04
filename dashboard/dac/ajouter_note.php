<?php
// dashboard/dac/ajouter_note.php

session_start();
include "../../includes/header.php";
require_once "../../includes/db.php";

$success = "";
$error = "";

// Vérifie que l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $etudiant_id = intval($_POST["etudiant_id"] ?? 0);
    $matiere_id = intval($_POST["matiere_id"] ?? 0);
    $type_examen_id = intval($_POST["type_examen_id"] ?? 0);
    $note = floatval($_POST["note"] ?? 0);
    $semestre_id = intval($_POST["semestre_id"] ?? 0);
    $annee_academique_id = intval($_POST["annee_academique_id"] ?? 0);
    
    if ($etudiant_id === 0 || $matiere_id === 0 || $type_examen_id === 0) {
        $error = "Sélectionnez un étudiant, une matière et un type d'examen valides.";
    } else {
        // Vérifier si la note existe déjà
        $stmt_check = $conn->prepare("SELECT id FROM notes WHERE etudiant_id = ? AND matiere_id = ? AND type_examen_id = ? AND semestre_id = ? AND annee_academique_id = ?");
        $stmt_check->bind_param("iiiii", $etudiant_id, $matiere_id, $type_examen_id, $semestre_id, $annee_academique_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $error = "Cet étudiant a déjà une note pour cette matière et ce type d'examen.";
        } else {
            $coefficient_note = 1.00; // Valeur par défaut
            $date_evaluation = date('Y-m-d');
            $evaluateur_id = $_SESSION['user_id'];
            $statut = 'valide';
            
            $stmt = $conn->prepare("INSERT INTO notes (etudiant_id, matiere_id, type_examen_id, note, coefficient_note, date_evaluation, evaluateur_id, semestre_id, annee_academique_id, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiddisiis", $etudiant_id, $matiere_id, $type_examen_id, $note, $coefficient_note, $date_evaluation, $evaluateur_id, $semestre_id, $annee_academique_id, $statut);

            if ($stmt->execute()) {
                $success = "Note ajoutée avec succès.";
            } else {
                $error = "Erreur SQL : " . $stmt->error;
            }
        }
    }
}

// Récupérer les données pour les listes déroulantes
$etudiants = $conn->query("SELECT e.id, e.matricule, e.nom, e.prenom FROM etudiants e WHERE e.statut = 'actif' ORDER BY e.nom");
$matieres = $conn->query("SELECT m.id, m.code, m.nom FROM matieres m ORDER BY m.code");
$types_examens = $conn->query("SELECT id, nom FROM types_examens ORDER BY ordre");
$semestres = $conn->query("SELECT s.id, s.numero, aa.libelle FROM semestres s JOIN annees_academiques aa ON s.annee_academique_id = aa.id ORDER BY s.numero DESC");
$annees_academiques = $conn->query("SELECT id, libelle FROM annees_academiques ORDER BY date_debut DESC");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter une note</title>
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
        
        .btn-secondary {
            border-radius: 8px;
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
        <a href="notes.php?action=list" class="btn btn-secondary mb-3">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="fa-solid fa-plus"></i> Ajouter une note</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label><i class="fa-solid fa-user"></i> Étudiant</label>
                        <select name="etudiant_id" class="form-select" required>
                            <option value="">-- Sélectionner un étudiant --</option>
                            <?php while($e = $etudiants->fetch_assoc()): ?>
                                <option value="<?= $e['id'] ?>">
                                    <?= htmlspecialchars($e['prenom'] . ' ' . $e['nom'] . ' - ' . $e['matricule']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label><i class="fa-solid fa-book"></i> Matière</label>
                        <select name="matiere_id" class="form-select" required>
                            <option value="">-- Sélectionner une matière --</option>
                            <?php while($m = $matieres->fetch_assoc()): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= htmlspecialchars($m['code'] . ' - ' . $m['nom']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label><i class="fa-solid fa-clipboard-check"></i> Type d'examen</label>
                        <select name="type_examen_id" class="form-select" required>
                            <option value="">-- Sélectionner un type --</option>
                            <?php while($t = $types_examens->fetch_assoc()): ?>
                                <option value="<?= $t['id'] ?>">
                                    <?= htmlspecialchars($t['nom']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label><i class="fa-solid fa-star"></i> Note</label>
                        <input type="number" step="0.01" min="0" max="20" name="note" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label><i class="fa-solid fa-calendar"></i> Semestre</label>
                            <select name="semestre_id" class="form-select" required>
                                <option value="">-- Sélectionner un semestre --</option>
                                <?php while($s = $semestres->fetch_assoc()): ?>
                                    <option value="<?= $s['id'] ?>">
                                        Semestre <?= $s['numero'] ?> (<?= htmlspecialchars($s['libelle']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label><i class="fa-solid fa-calendar-days"></i> Année académique</label>
                            <select name="annee_academique_id" class="form-select" required>
                                <option value="">-- Sélectionner une année --</option>
                                <?php while($a = $annees_academiques->fetch_assoc()): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= htmlspecialchars($a['libelle']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-50">
                            <i class="fa-solid fa-plus-circle"></i> Ajouter
                        </button>
                        <button type="reset" class="btn btn-danger w-50">
                            <i class="fa-solid fa-trash"></i> Réinitialiser
                        </button>
                    </div>
                </form>
            </div>
        </div>
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

// Confirmation visuelle après soumission
const form = document.querySelector("form");
form.addEventListener("submit", () => {
    const btn = form.querySelector("button[type='submit']");
    btn.textContent = "✔ Note ajoutée...";
    btn.style.backgroundColor = "#28a745";
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