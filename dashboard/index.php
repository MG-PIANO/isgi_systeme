<?php
// dashboard/index.php
require_once '../config/database.php';

session_start();

// Rediriger si non connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Récupérer l'utilisateur
$userId = $_SESSION['user_id'];
$roleId = $_SESSION['role_id'];

// Rediriger vers le dashboard approprié selon le rôle
switch ($roleId) {
    case 1: // Administrateur Principal
        header('Location: admin_principal/dashboard.php');
        break;
    case 2: // Administrateur Site
        header('Location: admin_site/dashboard.php');
        break;
    case 3: // Gestionnaire Principal
    case 4: // Gestionnaire Secondaire
        header('Location: gestionnaire/dashboard.php');
        break;
    case 5: // DAC
        header('Location: dac/dashboard.php');
        break;
    case 6: // Surveillant Général
        header('Location: surveillant/dashboard.php');
        break;
    case 7: // Professeur
        header('Location: professeur/dashboard.php');
        break;
    case 8: // Étudiant
        header('Location: etudiant/dashboard.php');
        break;
    case 9: // Tuteur
        header('Location: tuteur/dashboard.php');
        break;
    default:
        // Rôle inconnu, déconnecter
        session_destroy();
        header('Location: ../auth/login.php?error=role_inconnu');
        break;
}
exit();
?>